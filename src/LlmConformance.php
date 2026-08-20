<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Executable conformance suite for the Llm contract.
 *
 * Every host hands the pipeline its own Llm (see
 * docs/site-build-portable-pipeline.md). The steps above that seam assume the
 * whole documented contract holds — most consequentially that `cached_prefixes`
 * are prepended to the prompt, because SectionUnit ships the site spec, theme
 * JSON, design direction and page outline in those layers and sends only the
 * per-section brief as `prompt`. A host that silently drops the field produces
 * a complete, plausible-looking site whose sections never saw the theme.
 *
 * That is not hypothetical: it is exactly what a production blocks-first run
 * did, and it was only caught by noticing that section generations reported
 * ~2,400 input tokens against a 29,870-byte prompt template. Nothing failed,
 * nothing warned. This suite exists so the next host fails loudly in CI
 * instead.
 *
 * The checks are BEHAVIOURAL on purpose. The Llm interface deliberately hides
 * the transport, so a portable suite cannot inspect an outgoing request body —
 * it can only observe what a host's implementation actually does. Two signals
 * carry the load:
 *
 *   - an echo probe: a nonce hidden in a cached prefix, which the model can
 *     only repeat back if the prefix reached it;
 *   - a usage probe: a prefix of known size, whose tokens must show up in the
 *     host's reported input usage.
 *
 * The usage probe is the stronger of the two, because it holds even when the
 * model declines to cooperate with the echo instruction, and it is the same
 * measurement that exposed the original defect.
 *
 * Structural checks spend nothing (they must be rejected before transport).
 * Live checks each spend one small completion. Pure apart from the calls it
 * makes into the supplied Llm — it never touches a Project or the filesystem.
 */
final class LlmConformance
{
    /** Nonce embedded in a cached prefix and echoed back by the live probe. */
    public const PROBE_TOKEN = 'CONFORMANCE-PROBE-8F3A21D7';

    /**
     * Filler sized so the probe prefix comfortably clears both the usage floor
     * below and Anthropic's minimum cacheable prefix (1,024 tokens on
     * Sonnet-tier). Deterministic so repeated runs are byte-identical and can
     * actually hit a warm cache.
     */
    private const FILLER_LINE = 'This paragraph is inert conformance filler and carries no instructions whatsoever. ';
    private const FILLER_REPEATS = 120;

    /**
     * Minimum input tokens a conforming host must report for the usage probe.
     * The prefix alone is ~2,400 tokens; a host that drops it lands near 60.
     * The floor sits far below the true figure so provider-specific tokenizer
     * differences and system-preamble variation can never make this flaky —
     * it only has to separate "prefix present" from "prefix discarded".
     */
    private const USAGE_FLOOR_TOKENS = 800;

    /**
     * Run every check the supplied host can support.
     *
     * @param bool $includeLive false runs only the zero-spend structural checks
     * @return list<LlmConformanceFinding>
     */
    public static function run(Llm $llm, bool $includeLive = true): array
    {
        $findings = self::structural($llm);
        if ($includeLive) {
            array_push($findings, ...self::live($llm));
        }
        return $findings;
    }

    /**
     * Checks that must be decided before any transport call, so they cost
     * nothing and are safe to run in ordinary CI.
     *
     * @return list<LlmConformanceFinding>
     */
    public static function structural(Llm $llm): array
    {
        return [
            self::checkMalformedPrefixesRejected($llm),
            self::checkOversizedPrefixListRejected($llm),
        ];
    }

    /**
     * Checks that need model calls: three singles plus one two-member batch.
     *
     * @return list<LlmConformanceFinding>
     */
    public static function live(Llm $llm): array
    {
        return [
            self::checkPrefixesCountedInUsage($llm),
            self::checkPrefixesReachTheModel($llm),
            self::checkBlankPrefixesTolerated($llm),
            self::checkBatchKeysRoundTrip($llm),
        ];
    }

    /** True when every non-skipped finding passed. */
    public static function passed(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (!$finding->passed) {
                return false;
            }
        }
        return true;
    }

    /** The probe prefix: inert filler wrapped around the echo nonce. */
    public static function probePrefix(): string
    {
        return str_repeat(self::FILLER_LINE, self::FILLER_REPEATS)
            . "\n\nPROBE TOKEN: " . self::PROBE_TOKEN . "\n\n";
    }

    // ---------------------------------------------------------------- checks

    /**
     * The contract says cached_prefixes must be a list of strings. A host that
     * accepts anything else will mangle or silently discard real layers.
     */
    private static function checkMalformedPrefixesRejected(Llm $llm): LlmConformanceFinding
    {
        $check = 'malformed_prefixes_rejected';
        $rejected = 0;
        $accepted = [];
        // A non-list array, a null, and a list holding a non-string.
        $invalid = [
            'string-keyed array' => ['a' => 'x'],
            'null'               => null,
            'non-string member'  => ['valid', 42],
        ];
        foreach ($invalid as $label => $value) {
            try {
                $llm->complete('Conformance probe.', ['cached_prefixes' => $value]);
                $accepted[] = $label;
            } catch (\Throwable) {
                $rejected++;
            }
        }
        if ($accepted !== []) {
            return LlmConformanceFinding::fail(
                $check,
                'Host accepted malformed cached_prefixes (' . implode(', ', $accepted)
                . '). The contract requires a list of strings; rejecting bad input early is what stops '
                . 'a mistyped layer from being silently dropped.',
                LlmConformanceFinding::TIER_STRUCTURAL,
            );
        }
        return LlmConformanceFinding::pass(
            $check,
            "Rejected all {$rejected} malformed cached_prefixes shapes before transport.",
            LlmConformanceFinding::TIER_STRUCTURAL,
        );
    }

    /**
     * The contract caps a request at three non-blank layers. A host that
     * quietly accepts more is likely truncating rather than erroring.
     */
    private static function checkOversizedPrefixListRejected(Llm $llm): LlmConformanceFinding
    {
        $check = 'oversized_prefix_list_rejected';
        try {
            $llm->complete('Conformance probe.', [
                'cached_prefixes' => ['one', 'two', 'three', 'four'],
            ]);
        } catch (\Throwable) {
            return LlmConformanceFinding::pass(
                $check,
                'Rejected a four-layer cached_prefixes list, per the three-layer cap.',
                LlmConformanceFinding::TIER_STRUCTURAL,
            );
        }
        return LlmConformanceFinding::fail(
            $check,
            'Host accepted four cached_prefixes layers. The contract allows at most three non-blank '
            . 'layers; accepting more usually means the extras are being dropped rather than sent.',
            LlmConformanceFinding::TIER_STRUCTURAL,
        );
    }

    /**
     * THE check. A prefix of known size must show up in reported input usage.
     *
     * This is the measurement that exposed the original production defect, and
     * unlike the echo probe it does not depend on the model following an
     * instruction — a host cannot pass it while discarding the layers.
     */
    private static function checkPrefixesCountedInUsage(Llm $llm): LlmConformanceFinding
    {
        $check = 'cached_prefixes_counted_in_usage';
        $tier = LlmConformanceFinding::TIER_LIVE;
        if (!$llm instanceof UsageReporting) {
            return LlmConformanceFinding::skip(
                $check,
                'Host does not implement UsageReporting, so input-token accounting cannot be observed. '
                . 'The echo probe still covers this case, less strictly.',
                $tier,
            );
        }
        $before = (int) ($llm->usageTotals()['input_tokens'] ?? 0);
        try {
            $llm->complete('Reply with the single word: ok.', [
                'cached_prefixes' => [self::probePrefix()],
                'max_tokens'      => 16,
            ]);
        } catch (\Throwable $e) {
            return LlmConformanceFinding::fail($check, 'Probe call failed: ' . $e->getMessage(), $tier);
        }
        $delta = (int) ($llm->usageTotals()['input_tokens'] ?? 0) - $before;
        if ($delta < self::USAGE_FLOOR_TOKENS) {
            return LlmConformanceFinding::fail(
                $check,
                "Sent a ~2,400-token cached prefix but the host reported only {$delta} input tokens "
                . '(floor ' . self::USAGE_FLOOR_TOKENS . '). The prefix is not reaching the model. '
                . 'Every SectionUnit request ships the site spec, theme JSON, design direction and page '
                . 'outline this way, so sections would be generated with none of that context.',
                $tier,
            );
        }
        return LlmConformanceFinding::pass(
            $check,
            "Cached prefix accounted for in input usage ({$delta} tokens reported).",
            $tier,
        );
    }

    /** A nonce hidden in the prefix must come back, proving the model saw it. */
    private static function checkPrefixesReachTheModel(Llm $llm): LlmConformanceFinding
    {
        $check = 'cached_prefixes_reach_the_model';
        $tier = LlmConformanceFinding::TIER_LIVE;
        try {
            $reply = $llm->complete(
                'Above this line is a PROBE TOKEN. Reply with that token exactly and nothing else. '
                . 'If you cannot find a PROBE TOKEN, reply with the word MISSING.',
                ['cached_prefixes' => [self::probePrefix()], 'max_tokens' => 64],
            );
        } catch (\Throwable $e) {
            return LlmConformanceFinding::fail($check, 'Probe call failed: ' . $e->getMessage(), $tier);
        }
        if (!str_contains($reply, self::PROBE_TOKEN)) {
            return LlmConformanceFinding::fail(
                $check,
                'The model could not repeat a token that was placed in a cached prefix (replied '
                . json_encode(mb_substr(trim($reply), 0, 80), JSON_UNESCAPED_SLASHES) . '). '
                . 'The prefix did not reach it.',
                $tier,
            );
        }
        return LlmConformanceFinding::pass($check, 'Model echoed the nonce carried in the cached prefix.', $tier);
    }

    /**
     * Blank layers are documented as ignored, byte-identical to an uncached
     * request. A host that instead errors, or that sends empty cache blocks,
     * breaks callers that build layers conditionally.
     */
    private static function checkBlankPrefixesTolerated(Llm $llm): LlmConformanceFinding
    {
        $check = 'blank_prefixes_tolerated';
        $tier = LlmConformanceFinding::TIER_LIVE;
        try {
            $llm->complete('Reply with the single word: ok.', [
                'cached_prefixes' => ['', " \n\t "],
                'max_tokens'      => 16,
            ]);
        } catch (\Throwable $e) {
            return LlmConformanceFinding::fail(
                $check,
                'An all-blank cached_prefixes list raised: ' . $e->getMessage()
                . '. The contract says blank layers are ignored and the request behaves as uncached.',
                $tier,
            );
        }
        return LlmConformanceFinding::pass($check, 'All-blank cached_prefixes handled as an uncached request.', $tier);
    }

    /**
     * completeBatch must return a TextBatchResult keyed exactly as its input.
     * SectionsStep pairs every response back to its job by key, so a host that
     * re-keys silently writes sections into the wrong files.
     */
    private static function checkBatchKeysRoundTrip(Llm $llm): LlmConformanceFinding
    {
        $check = 'batch_keys_round_trip';
        $tier = LlmConformanceFinding::TIER_LIVE;
        $requests = [
            'alpha' => ['prompt' => 'Reply with the single word: alpha.', 'max_tokens' => 16],
            'beta'  => ['prompt' => 'Reply with the single word: beta.', 'max_tokens' => 16],
        ];
        try {
            $result = $llm->completeBatch($requests);
        } catch (\Throwable $e) {
            return LlmConformanceFinding::fail($check, 'Batch call failed: ' . $e->getMessage(), $tier);
        }
        if (!$result instanceof TextBatchResult) {
            return LlmConformanceFinding::fail(
                $check,
                'completeBatch returned ' . get_debug_type($result) . ', not a TextBatchResult. '
                . 'The degradation notes it carries are how truncated members get recorded.',
                $tier,
            );
        }
        $got = array_keys($result->texts);
        $want = array_keys($requests);
        sort($got);
        sort($want);
        if ($got !== $want) {
            return LlmConformanceFinding::fail(
                $check,
                'Batch results were keyed [' . implode(', ', $got) . '] for requests ['
                . implode(', ', $want) . ']. Callers pair responses back to jobs by key.',
                $tier,
            );
        }
        return LlmConformanceFinding::pass($check, 'Batch results keyed identically to the requests.', $tier);
    }
}
