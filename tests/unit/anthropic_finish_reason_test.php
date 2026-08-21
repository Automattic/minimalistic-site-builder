<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\FinishReasonAwareLlm;
use Automattic\SiteBuild\TextBatchRecovery;

/**
 * Build the production client around a network-free single-request transport.
 *
 * The optional constructor argument is deliberately last so every existing
 * AnthropicClient construction remains valid without modification.
 */
function anthropic_finish_reason_client(Closure $transport): AnthropicClient
{
    assert_true(
        is_a(AnthropicClient::class, FinishReasonAwareLlm::class, true),
        'AnthropicClient must implement FinishReasonAwareLlm',
    );

    $constructor = new ReflectionMethod(AnthropicClient::class, '__construct');
    assert_true(
        $constructor->getNumberOfParameters() >= 4,
        'AnthropicClient needs an optional constructor-end single transport seam for network-free complete() tests',
    );

    return new AnthropicClient('test-key', 'claude-opus-4-8', 16000, $transport);
}

test('Anthropic complete exposes its finish reason and resets it around a failed attempt', function () {
    $client = null;
    $calls = 0;
    $finishReasonSeenByFailedAttempt = 'not-called';
    $partial = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>mid';

    $transport = function (array $body) use (
        &$client,
        &$calls,
        &$finishReasonSeenByFailedAttempt,
        $partial,
    ): array {
        $calls++;
        assert_eq('Keep exact partial text', $body['messages'][0]['content']);

        if ($calls === 1) {
            return [
                'text' => $partial,
                'input' => 11,
                'output' => 7,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 0,
                'time' => 0.01,
                'stop_reason' => 'max_tokens',
            ];
        }

        $finishReasonSeenByFailedAttempt = $client->lastFinishReason();
        throw new RuntimeException('synthetic transport failure');
    };

    $client = anthropic_finish_reason_client($transport);

    assert_eq(null, $client->lastFinishReason(), 'finish reason starts unset');
    assert_eq($partial, $client->complete('Keep exact partial text'), 'complete preserves response text');
    assert_eq('max_tokens', $client->lastFinishReason(), 'complete surfaces provider stop_reason');
    assert_eq(
        true,
        TextBatchRecovery::isTruncation($client->lastFinishReason()),
        'single complete finish reason uses the shared abnormal-termination classifier',
    );

    try {
        $client->complete('Keep exact partial text');
        throw new RuntimeException('expected synthetic transport failure');
    } catch (RuntimeException $e) {
        assert_eq('synthetic transport failure', $e->getMessage());
    }

    assert_eq(null, $finishReasonSeenByFailedAttempt, 'finish reason resets before failed transport');
    assert_eq(null, $client->lastFinishReason(), 'finish reason stays unset after failed attempt');
    assert_eq(2, $calls);
});
