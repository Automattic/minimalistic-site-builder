<?php
declare(strict_types=1);

use Automattic\SiteBuild\CachedPrefixes;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmConformance;
use Automattic\SiteBuild\LlmConformanceFinding;
use Automattic\SiteBuild\TextBatchResult;
use Automattic\SiteBuild\UsageReporting;

/**
 * Unit tests for the host conformance suite.
 *
 * The suite's whole job is to fail when a host's Llm quietly ignores part of
 * the contract, so the tests that matter most here are the negative ones: a
 * deliberately broken adapter must be caught, and caught by the specific check
 * that names the field it dropped. A suite that only goes green on a good
 * implementation would prove nothing.
 */

/** Shared request validation, through the same helper both reference clients use. */
abstract class ConformanceFakeBase implements Llm, UsageReporting
{
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public int $requests = 0;

    /** @return list<string> non-blank layers, after contract validation */
    protected function prefixes(array $opts): array
    {
        if (!array_key_exists('cached_prefixes', $opts)) {
            return [];
        }
        return CachedPrefixes::normalize($opts['cached_prefixes'], 'requests');
    }

    /** What this host actually puts in front of the model. Subclasses differ here. */
    abstract protected function seenByModel(string $prompt, array $opts): string;

    public function complete(string $prompt, array $opts = []): string
    {
        $seen = $this->seenByModel($prompt, $opts);
        $this->requests++;
        // A crude but monotonic stand-in for tokenization: the suite only needs
        // "prefix present" to be separable from "prefix discarded".
        $this->inputTokens += (int) ceil(strlen($seen) / 4);
        $this->outputTokens += 4;
        return $this->reply($seen);
    }

    protected function reply(string $seen): string
    {
        // Echo every probe token the host actually put in front of us, in the
        // order it appears. The layered probe carries one per cached layer, so
        // a host that forwards only the first is visible in the reply.
        if (preg_match_all('/' . preg_quote(LlmConformance::PROBE_TOKEN, '/') . '(?:-L\d+)?/', $seen, $m) === 0
            || $m[0] === []) {
            // fall through
        } else {
            return implode(' ', $m[0]);
        }
        // Stand in for a model that follows a plain instruction. The batch
        // check pairs each response to its key by asking for the key as the
        // answer, so a fake that ignored the instruction would read as a
        // transport that swapped the values.
        if (preg_match('/Reply with the single word: ([A-Za-z0-9_-]+)\./', $seen, $m) === 1) {
            return $m[1];
        }
        return 'MISSING';
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        $this->complete($prompt, $opts);
        return [];
    }

    public function completeJsonBatch(array $requests): array
    {
        $out = [];
        foreach ($requests as $key => $request) {
            $this->complete((string) $request['prompt'], $request);
            $out[$key] = [];
        }
        return $out;
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        $texts = [];
        foreach ($requests as $key => $request) {
            $texts[$key] = $this->complete((string) $request['prompt'], $request);
        }
        return new TextBatchResult($texts);
    }

    public function usageTotals(): array
    {
        return [
            'requests'      => $this->requests,
            'input_tokens'  => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens'  => $this->inputTokens + $this->outputTokens,
        ];
    }
}

/** Keys survive, values follow the other key: the handle/index mix-up. */
final class SwapsBatchValuesFakeLlm extends ConformanceFakeBase
{
    protected function seenByModel(string $prompt, array $opts): string
    {
        return implode('', $this->prefixes($opts)) . $prompt;
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        $texts = [];
        foreach ($requests as $key => $request) {
            $texts[$key] = $this->complete((string) $request['prompt'], $request);
        }
        $keys = array_keys($texts);
        return new TextBatchResult(array_combine($keys, array_reverse(array_values($texts))));
    }
}

/** A host that honours the whole contract. */
final class ConformantFakeLlm extends ConformanceFakeBase
{
    protected function seenByModel(string $prompt, array $opts): string
    {
        return implode('', $this->prefixes($opts)) . $prompt;
    }
}

/** The production defect: validates the field, then throws the layers away. */
final class PrefixDroppingFakeLlm extends ConformanceFakeBase
{
    protected function seenByModel(string $prompt, array $opts): string
    {
        $this->prefixes($opts); // still validates, so structural checks pass
        return $prompt;
    }
}

/**
 * The adapter the structural tier used to hand a green report to: no
 * cached_prefixes validation at all, a bad key, and — like BOTH reference
 * clients — a request counter that only moves after a call SUCCEEDS.
 *
 * That last detail is the whole trap. An unmoved counter was read as proof of
 * a local rejection, and an HTTP 401 leaves it exactly where a rejection does.
 */
final class NoValidationBadKeyFakeLlm implements Llm, UsageReporting
{
    public int $requests = 0;

    public function complete(string $prompt, array $opts = []): string
    {
        throw new \RuntimeException('HTTP 401 from api.example.com: {"error":{"message":"invalid x-api-key"}}');
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        return [];
    }

    public function completeJsonBatch(array $requests): array
    {
        return array_map(static fn () => [], $requests);
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        return new TextBatchResult([]);
    }

    public function usageTotals(): array
    {
        return ['requests' => $this->requests, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
    }
}

/**
 * Fully conformant, but reports usage the way the raw Anthropic Messages API
 * does: cached tokens land in cache_creation_input_tokens and are EXCLUDED
 * from input_tokens.
 */
final class RawUsageConventionFakeLlm extends ConformanceFakeBase
{
    public int $cacheCreationTokens = 0;

    protected function seenByModel(string $prompt, array $opts): string
    {
        return implode('', $this->prefixes($opts)) . $prompt;
    }

    public function complete(string $prompt, array $opts = []): string
    {
        $layers = implode('', $this->prefixes($opts));
        $this->requests++;
        $this->inputTokens += (int) ceil(strlen($prompt) / 4);
        $this->cacheCreationTokens += (int) ceil(strlen($layers) / 4);
        $this->outputTokens += 4;
        return $this->reply($layers . $prompt);
    }

    public function usageTotals(): array
    {
        return parent::usageTotals() + [
            'cache_read_input_tokens'     => 0,
            'cache_creation_input_tokens' => $this->cacheCreationTokens,
        ];
    }
}

/** Honours cached_prefixes in complete(), drops them in completeBatch(). */
final class BatchDropsPrefixesFakeLlm extends ConformanceFakeBase
{
    private bool $inBatch = false;

    protected function seenByModel(string $prompt, array $opts): string
    {
        return ($this->inBatch ? '' : implode('', $this->prefixes($opts))) . $prompt;
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        $this->inBatch = true;
        try {
            return parent::completeBatch($requests);
        } finally {
            $this->inBatch = false;
        }
    }
}

/** A host with no input validation at all. */
final class PermissiveFakeLlm extends ConformanceFakeBase
{
    protected function seenByModel(string $prompt, array $opts): string
    {
        $layers = $opts['cached_prefixes'] ?? [];
        $joined = '';
        foreach ((array) $layers as $layer) {
            $joined .= is_string($layer) ? $layer : '';
        }
        return $joined . $prompt;
    }
}

/** @return array<string,LlmConformanceFinding> findings keyed by check id */
function conformance_by_check(array $findings): array
{
    $out = [];
    foreach ($findings as $finding) {
        $out[$finding->check] = $finding;
    }
    return $out;
}

test('a conformant host passes every check', function () {
    $llm = new ConformantFakeLlm();
    $findings = LlmConformance::run($llm);

    assert_true(LlmConformance::passed($findings), 'conformant host should pass');
    $byCheck = conformance_by_check($findings);
    assert_true(
        $byCheck['cached_prefixes_counted_in_usage']->passed,
        'usage probe should see the prefix tokens',
    );
    assert_true(
        $byCheck['cached_prefixes_reach_the_model']->passed,
        'echo probe should recover the nonce',
    );
    assert_eq(5, $llm->requests, 'full run should spend five completions before retries');
});

test('a host that drops cached_prefixes fails, and the failure names the field', function () {
    $findings = LlmConformance::run(new PrefixDroppingFakeLlm());

    assert_true(!LlmConformance::passed($findings), 'dropping host must not pass');
    $byCheck = conformance_by_check($findings);

    // This is the regression that shipped to production: a host that validates
    // the field and then discards it looks completely healthy from outside.
    assert_true(
        !$byCheck['cached_prefixes_counted_in_usage']->passed,
        'usage probe must catch a discarded prefix',
    );
    assert_contains('input tokens', $byCheck['cached_prefixes_counted_in_usage']->detail);
    assert_true(
        !$byCheck['cached_prefixes_reach_the_model']->passed,
        'echo probe must catch a discarded prefix',
    );

    // The structural checks still pass, which is the point: only the
    // behavioural probes can see this class of defect.
    assert_true($byCheck['malformed_prefixes_rejected']->passed);
    assert_true($byCheck['oversized_prefix_list_rejected']->passed);
});

test('a host with no cached_prefixes validation fails the structural checks', function () {
    $findings = LlmConformance::structural(new PermissiveFakeLlm());
    $byCheck = conformance_by_check($findings);

    assert_true(!$byCheck['malformed_prefixes_rejected']->passed, 'malformed shapes must be rejected');
    assert_contains('list of strings', $byCheck['malformed_prefixes_rejected']->detail);
    assert_contains('null', $byCheck['malformed_prefixes_rejected']->detail);
    assert_true(!$byCheck['oversized_prefix_list_rejected']->passed, 'a fourth layer must be rejected');
});

test('a bad key is never mistaken for validation the host does not have', function () {
    // Regression: this adapter validates nothing, and every structural check
    // used to report PASS — "Rejected all 3 malformed cached_prefixes shapes
    // before transport" — because its request counter had not moved. Both
    // reference clients count that way, so this is the realistic shape.
    $findings = conformance_by_check(LlmConformance::structural(new NoValidationBadKeyFakeLlm()));

    foreach (['malformed_prefixes_rejected', 'oversized_prefix_list_rejected'] as $check) {
        assert_true($findings[$check]->skipped, "{$check} must report skipped, not passed");
        // And the skip has to tell the host how to make the check decisive.
        assert_contains('LlmRequestRejected', $findings[$check]->detail);
    }
    // Nothing was proven, so the run as a whole must not read as a pass.
    assert_true(LlmConformance::inconclusive(array_values($findings)), 'an all-skipped run is inconclusive');
});

test('LlmRequestRejected is accepted as proof even from a usage-blind host', function () {
    // A host with no UsageReporting at all: the type alone has to carry it.
    $llm = new class implements Llm {
        public function complete(string $prompt, array $opts = []): string
        {
            // array_key_exists, not ??: `cached_prefixes => null` must reach the
            // validator rather than coalescing into a silent empty list.
            if (array_key_exists('cached_prefixes', $opts)) {
                CachedPrefixes::normalize($opts['cached_prefixes'], 'requests');
            }
            return 'ok';
        }

        public function completeJson(string $prompt, array $opts = []): array
        {
            return [];
        }

        public function completeJsonBatch(array $requests): array
        {
            return array_map(static fn () => [], $requests);
        }

        public function completeBatch(array $requests): TextBatchResult
        {
            return new TextBatchResult(array_map(static fn () => 'ok', $requests));
        }
    };

    $findings = conformance_by_check(LlmConformance::structural($llm));

    assert_true($findings['malformed_prefixes_rejected']->passed);
    assert_true(!$findings['malformed_prefixes_rejected']->skipped, 'the exception type is decisive on its own');
    assert_true($findings['oversized_prefix_list_rejected']->passed);
});

test('structural checks spend nothing on a conforming host', function () {
    $llm = new ConformantFakeLlm();
    LlmConformance::structural($llm);

    assert_eq(0, $llm->requests, 'contract violations are rejected before transport');
});

test('the probe prefix clears the usage floor and carries the nonce', function () {
    $prefix = LlmConformance::probePrefix();

    assert_contains(LlmConformance::PROBE_TOKEN, $prefix);
    // ~4 chars/token: the prefix must sit far enough above the 800-token floor
    // that tokenizer differences between providers can never make this flaky.
    assert_true(
        strlen($prefix) / 4 > 1600,
        'probe prefix should be ~2x the usage floor, got ' . (int) (strlen($prefix) / 4) . ' tokens',
    );
});

test('a host that re-keys batch results is caught', function () {
    $llm = new class extends ConformanceFakeBase {
        protected function seenByModel(string $prompt, array $opts): string
        {
            return implode('', $this->prefixes($opts)) . $prompt;
        }

        public function completeBatch(array $requests): TextBatchResult
        {
            $texts = [];
            foreach (array_values($requests) as $i => $request) {
                $texts[(string) $i] = $this->complete((string) $request['prompt'], $request);
            }
            return new TextBatchResult($texts);
        }
    };

    $byCheck = conformance_by_check(LlmConformance::live($llm));

    assert_true(!$byCheck['batch_keys_round_trip']->passed, 'positional re-keying must be caught');
    assert_contains('keyed', $byCheck['batch_keys_round_trip']->detail);
});

test('usage-blind hosts skip the usage probe instead of failing it', function () {
    $llm = new class implements Llm {
        public function complete(string $prompt, array $opts = []): string
        {
            $seen = implode('', (array) ($opts['cached_prefixes'] ?? [])) . $prompt;
            preg_match_all('/' . preg_quote(LlmConformance::PROBE_TOKEN, '/') . '(?:-L\d+)?/', $seen, $m);
            return $m[0] === [] ? 'MISSING' : implode(' ', $m[0]);
        }

        public function completeJson(string $prompt, array $opts = []): array
        {
            return [];
        }

        public function completeJsonBatch(array $requests): array
        {
            return array_map(static fn () => [], $requests);
        }

        public function completeBatch(array $requests): TextBatchResult
        {
            $texts = [];
            foreach ($requests as $key => $request) {
                $texts[$key] = $this->complete((string) $request['prompt'], $request);
            }
            return new TextBatchResult($texts);
        }
    };

    $byCheck = conformance_by_check(LlmConformance::live($llm));

    assert_true($byCheck['cached_prefixes_counted_in_usage']->skipped, 'no UsageReporting means skip');
    assert_true($byCheck['cached_prefixes_counted_in_usage']->passed, 'a skip must not fail the run');
    // The echo probe still covers the host, less strictly.
    assert_true($byCheck['cached_prefixes_reach_the_model']->passed);
});

test('a host that swaps batch values keeps the keys and is still caught', function () {
    $llm = new SwapsBatchValuesFakeLlm();
    $findings = LlmConformance::live($llm);
    $batch = null;
    foreach ($findings as $f) {
        if ($f->check === 'batch_keys_round_trip') {
            $batch = $f;
        }
    }
    assert_true($batch !== null, 'batch check ran');
    assert_true(!$batch->passed, 'a value swap must fail even though the keys match');
    assert_contains('values did not follow', $batch->detail);
});

test('a conformant host is not accused for reporting the raw-API usage convention', function () {
    // The provider's own field name is the likeliest thing for a host to pass
    // through, and under that convention a cached prefix is billed almost
    // entirely OUTSIDE input_tokens. Reading the raw field alone failed this
    // host with "the layers are not reaching the model" — the opposite of the
    // truth, on the load-bearing check.
    $byCheck = conformance_by_check(LlmConformance::live(new RawUsageConventionFakeLlm()));

    assert_true(
        $byCheck['cached_prefixes_counted_in_usage']->passed,
        'billed input must count cache creations: ' . $byCheck['cached_prefixes_counted_in_usage']->detail,
    );
    assert_true(!$byCheck['cached_prefixes_counted_in_usage']->skipped, 'and it must be measured, not skipped');
});

test('a host that honours complete() but drops the layers in completeBatch is caught by usage', function () {
    // Sections are authored through completeBatch. While the usage probe ran on
    // complete(), this host passed it — only the echo probe caught it, and the
    // echo is the weaker signal.
    $byCheck = conformance_by_check(LlmConformance::live(new BatchDropsPrefixesFakeLlm()));

    assert_true(
        !$byCheck['cached_prefixes_counted_in_usage']->passed,
        'the usage probe must travel the path production uses',
    );
    assert_contains('completeBatch', $byCheck['cached_prefixes_counted_in_usage']->detail);
});

test('the echo is advisory when the model declines but usage proves delivery', function () {
    $llm = new class extends ConformanceFakeBase {
        protected function seenByModel(string $prompt, array $opts): string
        {
            return implode('', $this->prefixes($opts)) . $prompt;
        }

        /** A model that ignores the echo instruction entirely. */
        protected function reply(string $seen): string
        {
            return str_contains($seen, LlmConformance::PROBE_TOKEN) ? 'MISSING' : parent::reply($seen);
        }
    };

    $byCheck = conformance_by_check(LlmConformance::live($llm));

    assert_true($byCheck['cached_prefixes_counted_in_usage']->passed, 'usage answered the question');
    assert_true($byCheck['cached_prefixes_reach_the_model']->skipped, 'so the echo must not fail a good host');
    assert_true(LlmConformance::passed(LlmConformance::live($llm)), 'and the run stays green');
});

test('a partial echo stays fatal even when the host bills enough', function () {
    // Forwards two of three layers: enough billed input to clear the ratio, but
    // the missing nonce is positive evidence of selective forwarding. This is
    // the case the advisory downgrade must not swallow.
    $llm = new class extends ConformanceFakeBase {
        protected function seenByModel(string $prompt, array $opts): string
        {
            $layers = $this->prefixes($opts);
            if (count($layers) === 3) {
                array_pop($layers);
            }
            return implode('', $layers) . $prompt;
        }
    };

    $byCheck = conformance_by_check(LlmConformance::live($llm));

    assert_true($byCheck['cached_prefixes_counted_in_usage']->passed, 'two of three layers clears the ratio');
    assert_true(!$byCheck['cached_prefixes_reach_the_model']->passed, 'but a dropped layer must still fail');
    assert_contains('layer 3', $byCheck['cached_prefixes_reach_the_model']->detail);
});

test('a host that refuses the cache-warm probe is caught', function () {
    // SectionsStep swallows this failure by design (a warm-up must never abort
    // a build), which is exactly why the suite has to be the one that shouts.
    $llm = new class extends ConformanceFakeBase {
        protected function seenByModel(string $prompt, array $opts): string
        {
            return implode('', $this->prefixes($opts)) . $prompt;
        }

        public function completeBatch(array $requests): TextBatchResult
        {
            foreach ($requests as $request) {
                if (($request['tolerate_empty'] ?? false) === true) {
                    throw new \RuntimeException('max_tokens must be at least 16');
                }
            }
            return parent::completeBatch($requests);
        }
    };

    $byCheck = conformance_by_check(LlmConformance::live($llm));

    assert_true(!$byCheck['cache_warm_probe_tolerated']->passed, 'the warm-probe shape must be checked');
    assert_contains('runtime warning', $byCheck['cache_warm_probe_tolerated']->detail);
});

test('a run that proved nothing exits non-zero', function () {
    // The gate's own verdict. An adapter behind a bad key skips its way through
    // the structural tier, and "0 checks passed, 2 skipped" used to exit 0 —
    // green on no evidence, which is the failure this suite exists to remove.
    $skipped = LlmConformance::structural(new NoValidationBadKeyFakeLlm());
    $report = LlmConformance::report($skipped, true);

    assert_eq(1, $report['exit'], 'an all-skipped run must not gate a build green');
    assert_contains('proved nothing', $report['text']);
    assert_contains('SKIP', $report['text']);

    // A real pass still exits 0, and still shows what was measured.
    $good = LlmConformance::run(new ConformantFakeLlm());
    $ok = LlmConformance::report($good);
    assert_eq(0, $ok['exit']);
    assert_contains('All 7 checks passed', $ok['text']);
    assert_contains('tokens for ~', $ok['text'], 'a pass reports its measurement');

    // A failure names the check and exits 1.
    $bad = LlmConformance::report(LlmConformance::run(new PrefixDroppingFakeLlm()));
    assert_eq(1, $bad['exit']);
    assert_contains('checks FAILED', $bad['text']);
});
