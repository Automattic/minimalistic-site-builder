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
 * Four live checks spend five small completions before retries. Pure apart
 * from the calls it makes into the supplied Llm — it never touches a Project
 * or the filesystem.
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
    public static function probePrefix(?int $layer = null): string
    {
        $token = $layer === null ? self::PROBE_TOKEN : self::PROBE_TOKEN . '-L' . $layer;
        return str_repeat(self::FILLER_LINE, self::FILLER_REPEATS)
            . "\n\nPROBE TOKEN: " . $token . "\n\n";
    }

    /**
     * One request carrying three distinguishable layers, sent through
     * completeBatch.
     *
     * Both halves matter. SectionUnit sends TWO layers, so a host that
     * forwards only cached_prefixes[0] keeps the theme and drops the page
     * outline, and a single-layer probe reports that host conformant. And
     * sections are dispatched through completeBatch, so a host whose batch
     * path is a separate body builder can honour the field in complete() and
     * discard it in the call that actually runs.
     *
     * @return array{prompt:string,cached_prefixes:list<string>,max_tokens:int}
     */
    private static function layeredProbeRequest(): array
    {
        return [
            'prompt' => 'Above this line are three PROBE TOKEN lines. Reply with those three tokens '
                . 'in the order they appear, separated by single spaces, and nothing else. '
                . 'If you cannot find them, reply with the word MISSING.',
            'cached_prefixes' => [self::probePrefix(1), self::probePrefix(2), self::probePrefix(3)],
            'max_tokens'      => 128,
        ];
    }

    // ---------------------------------------------------------------- checks

    /**
     * The contract says cached_prefixes must be a list of strings. A host that
     * accepts anything else will mangle or silently discard real layers.
     */
    /**
     * Whether a throwable is the host rejecting the request itself, rather
     * than a transport that happened to fail.
     *
     * The structural tier is advertised as zero-spend and safe on every
     * commit, so it runs where a key may be absent or wrong. Bad credentials,
     * a DNS failure and a timeout all throw, and counting those as "rejected"
     * reports a green structural run for an adapter that validated nothing —
     * the false confidence is worse than no check.
     *
     * Two independent signals, either is enough:
     *
     *   - the host never sent anything (its own request counter did not move),
     *     which only a local rejection can produce;
     *   - the message names the field, which both reference clients do.
     *
     * A host that reports no usage and throws something opaque is
     * unclassifiable, and the caller reports the check as skipped rather than
     * passed.
     */
    private static function rejectedBeforeTransport(\Throwable $e, ?int $requestsBefore, ?int $requestsAfter): ?bool
    {
        if ($requestsBefore !== null && $requestsAfter !== null) {
            return $requestsAfter === $requestsBefore;
        }
        if (stripos($e->getMessage(), 'cached_prefixes') !== false) {
            return true;
        }
        return null;
    }

    /** Cumulative request count when the host exposes one, else null. */
    private static function requestCount(Llm $llm): ?int
    {
        if (!$llm instanceof UsageReporting) {
            return null;
        }
        try {
            return (int) ($llm->usageTotals()['requests'] ?? 0);
        } catch (\Throwable) {
            return null;
        }
    }

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
        $unclassified = [];
        foreach ($invalid as $label => $value) {
            $before = self::requestCount($llm);
            try {
                $llm->complete('Conformance probe.', ['cached_prefixes' => $value]);
                $accepted[] = $label;
            } catch (\Throwable $e) {
                $local = self::rejectedBeforeTransport($e, $before, self::requestCount($llm));
                if ($local === true) {
                    $rejected++;
                } elseif ($local === false) {
                    $accepted[] = $label . ' (sent it, then failed downstream)';
                } else {
                    $unclassified[] = $label . ': ' . $e->getMessage();
                }
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
        if ($unclassified !== []) {
            return LlmConformanceFinding::skip(
                $check,
                'Could not tell rejection from transport failure. The host threw, but it reports no '
                . 'request count and the error does not name cached_prefixes, so this run proves '
                . 'nothing either way: ' . implode('; ', $unclassified),
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
        $before = self::requestCount($llm);
        try {
            $llm->complete('Conformance probe.', [
                'cached_prefixes' => ['one', 'two', 'three', 'four'],
            ]);
        } catch (\Throwable $e) {
            $local = self::rejectedBeforeTransport($e, $before, self::requestCount($llm));
            if ($local === true) {
                return LlmConformanceFinding::pass(
                    $check,
                    'Rejected a four-layer cached_prefixes list, per the three-layer cap.',
                    LlmConformanceFinding::TIER_STRUCTURAL,
                );
            }
            if ($local === null) {
                return LlmConformanceFinding::skip(
                    $check,
                    'Could not tell rejection from transport failure. The host threw, but it reports no '
                    . 'request count and the error does not name cached_prefixes: ' . $e->getMessage(),
                    LlmConformanceFinding::TIER_STRUCTURAL,
                );
            }
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
            $result = $llm->completeBatch(['probe' => self::layeredProbeRequest()]);
            $reply = (string) ($result->texts['probe'] ?? '');
        } catch (\Throwable $e) {
            return LlmConformanceFinding::fail($check, 'Probe call failed: ' . $e->getMessage(), $tier);
        }
        $missing = [];
        $at = [];
        foreach ([1, 2, 3] as $layer) {
            $pos = strpos($reply, self::PROBE_TOKEN . '-L' . $layer);
            if ($pos === false) {
                $missing[] = 'layer ' . $layer;
                continue;
            }
            $at[$layer] = $pos;
        }
        if ($missing !== []) {
            return LlmConformanceFinding::fail(
                $check,
                'The model could not repeat ' . implode(' and ', $missing) . ' of a three-layer '
                . 'cached_prefixes (replied '
                . json_encode(mb_substr(trim($reply), 0, 80), JSON_UNESCAPED_SLASHES) . '). '
                . 'A host that forwards only the first layer keeps the theme and drops the page '
                . 'outline, which is half of the defect this suite exists for.',
                $tier,
            );
        }
        if ($at[1] > $at[2] || $at[2] > $at[3]) {
            return LlmConformanceFinding::fail(
                $check,
                'All three cached_prefixes layers reached the model but came back out of order. '
                . 'The contract prepends them in order; callers compose the layers so that later '
                . 'ones may refer to earlier ones.',
                $tier,
            );
        }
        return LlmConformanceFinding::pass(
            $check,
            'Model echoed all three cached_prefixes nonces, in order, through completeBatch.',
            $tier,
        );
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
        // Matching keys are not enough. A pool that mixes up handle and index
        // returns the right key set with the values swapped, and every caller
        // then writes each section into the other one's file. Each prompt asks
        // for its own key as the answer, so the pairing is checkable.
        $swapped = [];
        foreach ($requests as $key => $_request) {
            if (stripos((string) ($result->texts[$key] ?? ''), (string) $key) === false) {
                $swapped[] = $key;
            }
        }
        if ($swapped !== []) {
            return LlmConformanceFinding::fail(
                $check,
                'Batch keys survived but their values did not follow: ' . implode(', ', $swapped)
                . ' came back without the word the prompt asked that key to answer with. A caller '
                . 'pairing by key would file each response under the wrong job.',
                $tier,
            );
        }
        return LlmConformanceFinding::pass(
            $check,
            'Batch results keyed identically to the requests, each carrying its own key\'s answer.',
            $tier,
        );
    }
}
