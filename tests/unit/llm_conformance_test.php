<?php
declare(strict_types=1);

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

/** Shared request validation, mirroring AnthropicClient::bodyFor's contract checks. */
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
        $provided = $opts['cached_prefixes'];
        if (!is_array($provided) || !array_is_list($provided)) {
            throw new \RuntimeException('cached_prefixes must be a list of strings');
        }
        $layers = [];
        foreach ($provided as $index => $prefix) {
            if (!is_string($prefix)) {
                throw new \RuntimeException("cached_prefixes[{$index}] must be a string");
            }
            if (trim($prefix) !== '') {
                $layers[] = $prefix;
            }
        }
        if (count($layers) > 3) {
            throw new \RuntimeException('requests support at most three cached_prefixes');
        }
        return $layers;
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
        if (str_contains($seen, LlmConformance::PROBE_TOKEN)) {
            return LlmConformance::PROBE_TOKEN;
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
    $findings = LlmConformance::run(new ConformantFakeLlm());

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
    assert_true(!$byCheck['oversized_prefix_list_rejected']->passed, 'a fourth layer must be rejected');
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
            return str_contains($seen, LlmConformance::PROBE_TOKEN) ? LlmConformance::PROBE_TOKEN : 'MISSING';
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
