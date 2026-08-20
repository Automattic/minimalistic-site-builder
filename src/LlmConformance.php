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
     * How little a host must bill before the layers cannot have been sent is
     * BilledInput's call, not this suite's — SectionsStep's runtime guard asks
     * the same question of the same numbers, and the two answers must not
     * drift. A host that trips the warning in a build should have failed here
     * first.
     */

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
     * Both cached_prefixes probes read ONE request — the layered batch one.
     * They ask different questions (was it billed? did the model see it?) of
     * the same call, which is what production actually makes, and which costs
     * a call less than asking them separately.
     *
     * @return list<LlmConformanceFinding>
     */
    public static function live(Llm $llm): array
    {
        $probe = self::layeredProbe($llm);

        return [
            self::checkPrefixesCountedInUsage($probe),
            self::checkPrefixesReachTheModel($probe),
            self::checkBlankPrefixesTolerated($llm),
            self::checkCacheWarmProbeTolerated($llm),
            self::checkBatchKeysRoundTrip($llm),
        ];
    }

    /**
     * True when every non-skipped finding passed.
     *
     * A skip is not a pass — it is "this run could not tell". Callers gating a
     * build on this must ask inconclusive() too, or a host that skipped every
     * check goes green having proven nothing.
     *
     * @param list<LlmConformanceFinding> $findings
     */
    public static function passed(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (!$finding->passed) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when the run established nothing at all: no findings, or every one
     * of them skipped.
     *
     * Worth a separate question because the two ways a suite can be useless
     * look identical from passed(). An adapter behind a bad key, or one that
     * implements neither UsageReporting nor a recognisable rejection, can skip
     * its way to a green report — which is the exact false confidence this
     * suite exists to remove.
     *
     * @param list<LlmConformanceFinding> $findings
     */
    public static function inconclusive(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (!$finding->skipped) {
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
     * Send the layered probe once and keep everything both prefix checks need.
     *
     * @return array{reply:?string,error:?string,billed:?int,expected:int}
     */
    private static function layeredProbe(Llm $llm): array
    {
        $request  = self::layeredProbeRequest();
        $expected = BilledInput::estimateTokens($request['cached_prefixes']);

        $before = self::usageSnapshot($llm);
        $reply = null;
        $error = null;
        try {
            $result = $llm->completeBatch(['probe' => $request]);
            $reply  = (string) ($result->texts['probe'] ?? '');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $after = self::usageSnapshot($llm);

        return [
            'reply'    => $reply,
            'error'    => $error,
            'billed'   => ($before !== null && $after !== null) ? BilledInput::delta($before, $after) : null,
            'expected' => $expected,
        ];
    }

    /** Cumulative usage totals when the host reports them, else null. */
    private static function usageSnapshot(Llm $llm): ?array
    {
        if (!$llm instanceof UsageReporting) {
            return null;
        }
        try {
            return $llm->usageTotals();
        } catch (\Throwable) {
            return null;
        }
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
     * Whether a throwable is the host rejecting the request itself, rather
     * than a transport that happened to fail.
     *
     * The structural tier is advertised as zero-spend and safe on every commit,
     * so it runs exactly where a key is most likely to be absent or wrong. Bad
     * credentials, a DNS failure and a timeout all throw, and counting those as
     * "rejected" reports a green structural run for an adapter that validated
     * nothing — false confidence, which is worse than no check.
     *
     * So only POSITIVE proof of a local refusal is accepted, and only these
     * two things are positive proof:
     *
     *   - LlmRequestRejected, which the contract reserves for a request refused
     *     before transport;
     *   - a message naming the field, which both reference clients produce.
     *
     * An unmoved request counter is deliberately NOT proof, and this is the
     * whole point. Both reference clients increment that counter only after a
     * call succeeds (AnthropicClient::complete, OpenAiCompatibleClient::complete
     * both accrue below their catch), so an HTTP 401 leaves it exactly where a
     * local rejection would. Reading it as proof is what let an adapter with no
     * validation at all print "Rejected all 3 malformed cached_prefixes shapes
     * before transport."
     *
     * A counter that DID move is still conclusive in the other direction: the
     * host sent something, so it did not refuse.
     *
     * Anything else is unclassifiable, and the caller reports the check as
     * skipped rather than passed.
     */
    private static function rejectedBeforeTransport(\Throwable $e, ?int $requestsBefore, ?int $requestsAfter): ?bool
    {
        if ($e instanceof LlmRequestRejected) {
            return true;
        }
        if (stripos($e->getMessage(), 'cached_prefixes') !== false) {
            return true;
        }
        if ($requestsBefore !== null && $requestsAfter !== null && $requestsAfter !== $requestsBefore) {
            return false;
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
                'Could not tell rejection from transport failure. The host threw, but not '
                . 'LlmRequestRejected, and the message does not name cached_prefixes — a bad key '
                . 'looks the same from here, so this run proves nothing either way. Throw '
                . 'LlmRequestRejected for a refused request and this check becomes decisive: '
                . implode('; ', $unclassified),
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
                    'Could not tell rejection from transport failure. The host threw, but not '
                    . 'LlmRequestRejected, and the message does not name cached_prefixes: '
                    . $e->getMessage(),
                    LlmConformanceFinding::TIER_STRUCTURAL,
                );
            }
            return LlmConformanceFinding::fail(
                $check,
                'Host sent a four-layer cached_prefixes list to its transport and failed there ('
                . $e->getMessage() . '). The contract caps a request at three non-blank layers and '
                . 'makes enforcing that the implementation\'s job, so the fourth layer must be '
                . 'refused before transport, not passed along.',
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
     * THE check. Layers of known size must show up in billed input usage.
     *
     * This is the measurement that exposed the original production defect, and
     * unlike the echo probe it does not depend on the model following an
     * instruction — a host cannot pass it while discarding the layers.
     *
     * It reads the layered batch request, not a single complete(). Sections are
     * authored through completeBatch, and a host may build that body separately;
     * measuring complete() would let the path that actually runs lie freely.
     * SectionsStep::warmSectionCache() probes the same seam for the same reason.
     *
     * @param array{reply:?string,error:?string,billed:?int,expected:int} $probe
     */
    private static function checkPrefixesCountedInUsage(array $probe): LlmConformanceFinding
    {
        $check = 'cached_prefixes_counted_in_usage';
        $tier = LlmConformanceFinding::TIER_LIVE;
        if ($probe['error'] !== null) {
            return LlmConformanceFinding::fail($check, 'Probe call failed: ' . $probe['error'], $tier);
        }
        if ($probe['billed'] === null) {
            return LlmConformanceFinding::skip(
                $check,
                'Host does not implement UsageReporting, so input-token accounting cannot be observed. '
                . 'The echo probe still covers this case, less strictly.',
                $tier,
            );
        }
        $billed = $probe['billed'];
        $expected = $probe['expected'];
        if (BilledInput::looksDiscarded($expected, $billed)) {
            return LlmConformanceFinding::fail(
                $check,
                "Sent ~{$expected} tokens of cached_prefixes through completeBatch but the host billed "
                . "only {$billed} input tokens. The layers are not reaching the model. Every SectionUnit "
                . 'request ships the site spec, theme JSON, design direction and page outline this way, '
                . 'so sections would be generated with none of that context. If the layers ARE being '
                . 'sent, the other possibility is usage accounting: input_tokens must include cached '
                . 'reads and creations (see UsageReporting), or a conformant host reports a fraction of '
                . 'what it actually billed.',
                $tier,
            );
        }
        return LlmConformanceFinding::pass(
            $check,
            "Cached layers accounted for in billed input ({$billed} tokens for ~{$expected} sent).",
            $tier,
        );
    }

    /**
     * A nonce hidden in the prefix must come back, proving the model saw it.
     *
     * @param array{reply:?string,error:?string,billed:?int,expected:int} $probe
     */
    private static function checkPrefixesReachTheModel(array $probe): LlmConformanceFinding
    {
        $check = 'cached_prefixes_reach_the_model';
        $tier = LlmConformanceFinding::TIER_LIVE;
        if ($probe['error'] !== null || $probe['reply'] === null) {
            return LlmConformanceFinding::fail($check, 'Probe call failed: ' . (string) $probe['error'], $tier);
        }
        $reply = $probe['reply'];
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
        // Review asked whether a passing usage probe should make this advisory,
        // and the answer splits on WHICH layers came back. Nothing at all is
        // what a model that ignored the instruction looks like, and the usage
        // probe measured this very request, so it has already proved the layers
        // arrived — failing there would punish a conformant host for a model's
        // mood. SOME of them is different: that is positive evidence of
        // selective forwarding, which no amount of billed input excuses, and it
        // stays fatal. (Billing alone cannot separate the two: a host that
        // forwards two of three layers still clears the ratio.)
        $usageProved = $probe['billed'] !== null
            && !BilledInput::looksDiscarded($probe['expected'], $probe['billed']);
        if (count($missing) === 3 && $usageProved) {
            return LlmConformanceFinding::skip(
                $check,
                'The model repeated none of the three nonces, but usage accounting for this same '
                . "request bills {$probe['billed']} input tokens against ~{$probe['expected']} sent, so "
                . 'the layers did reach it. Treating this as advisory: the echo depends on the model '
                . 'following an instruction, and the stronger probe has already answered the question.',
                $tier,
            );
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
     * The cache-warm probe SectionsStep sends before every section batch must
     * survive: one batch member, max_tokens 1, tolerate_empty true.
     *
     * That probe is what warms the section cache AND what raises the runtime
     * warning when a host discards the layers — the guard this suite is the CI
     * half of. A host that refuses the shape (or regenerates instead of
     * accepting an output-limited empty answer) makes warmSectionCache() throw,
     * and it catches, prints "continuing uncached" and returns no warning. The
     * protection would switch itself off silently, which is the failure mode
     * this whole issue is about, so the contract clause it rests on gets its
     * own check.
     */
    private static function checkCacheWarmProbeTolerated(Llm $llm): LlmConformanceFinding
    {
        $check = 'cache_warm_probe_tolerated';
        $tier = LlmConformanceFinding::TIER_LIVE;
        try {
            $llm->completeBatch([
                'section-cache-warm' => [
                    'prompt'          => 'Warm the cached section context.',
                    'cached_prefixes' => [self::probePrefix()],
                    'max_tokens'      => 1,
                    'tolerate_empty'  => true,
                ],
            ]);
        } catch (\Throwable $e) {
            return LlmConformanceFinding::fail(
                $check,
                'The cache-warm probe raised: ' . $e->getMessage() . '. SectionsStep sends exactly this '
                . 'shape (one batch member, max_tokens 1, tolerate_empty true) before every section '
                . 'batch. A host that refuses it loses first-window cache hits AND the runtime warning '
                . 'that catches a host discarding cached_prefixes — the probe fails, the guard stays '
                . 'quiet, and nothing tells anyone.',
                $tier,
            );
        }
        return LlmConformanceFinding::pass(
            $check,
            'Cache-warm probe (max_tokens 1, tolerate_empty) accepted through completeBatch.',
            $tier,
        );
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
