<?php
declare(strict_types=1);

test('extractUsage sums input, cache, and output tokens', function () {
    $resp = ['usage' => [
        'input_tokens' => 100,
        'cache_read_input_tokens' => 20,
        'cache_creation_input_tokens' => 5,
        'output_tokens' => 50,
    ]];
    $u = AnthropicClient::extractUsage($resp);
    assert_eq(125, $u['input']);
    assert_eq(50, $u['output']);
});

test('extractUsage tolerates missing usage', function () {
    $u = AnthropicClient::extractUsage([]);
    assert_eq(0, $u['input']);
    assert_eq(0, $u['output']);
});

test('usageTotals starts at zero', function () {
    $c = new AnthropicClient('k', 'claude-opus-4-8');
    $t = $c->usageTotals();
    assert_eq(0, $t['requests']);
    assert_eq(0, $t['total_tokens']);
});
