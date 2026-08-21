<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContinuationRecovery;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TruncatedGenerationException;

/**
 * Test-only closure probe. Production callers supply the document-specific
 * structural check appropriate to their generated format.
 */
function continuation_test_html_is_closed(string $text): bool
{
    return substr_count($text, '<section') === substr_count($text, '</section>')
        && str_ends_with($text, '</section>');
}

test('ContinuationRecovery stitches a mid-tag truncation to a closed document', function () {
    $llm = new FakeLlm();
    $llm->queueText('<section cla', 'max_tokens');
    $llm->queueText('ss="x"><p>Done</p></section>', 'stop');

    $text = ContinuationRecovery::completeToClose(
        $llm,
        'Generate one section.',
        ['max_tokens' => 64],
        'continuation_test_html_is_closed',
    );

    assert_eq('<section class="x"><p>Done</p></section>', $text);
    assert_eq(2, $llm->completeCalls, 'one initial request plus one continuation');
    assert_contains(
        'Continue EXACTLY where the previous output stopped; do not repeat or restate',
        $llm->calls[1]['prompt'],
    );
    assert_contains('<section cla', $llm->calls[1]['prompt'], 'continuation receives prior output');
    assert_eq(['max_tokens' => 64], $llm->calls[1]['opts'], 'continuation preserves options');
});

test('ContinuationRecovery clean path issues exactly one LLM request', function () {
    $llm = new FakeLlm();
    $llm->queueText('<section class="x">Done</section>', 'stop');

    $text = ContinuationRecovery::completeToClose(
        $llm,
        'Generate one section.',
        [],
        'continuation_test_html_is_closed',
    );

    assert_eq('<section class="x">Done</section>', $text);
    assert_eq(1, $llm->completeCalls);
});

test('ContinuationRecovery returns a closed document even when provider reports truncation', function () {
    $llm = new FakeLlm();
    $llm->queueText('<section class="x">Done</section>', 'max_tokens');

    $text = ContinuationRecovery::completeToClose(
        $llm,
        'Generate one section.',
        [],
        'continuation_test_html_is_closed',
    );

    assert_eq('<section class="x">Done</section>', $text);
    assert_eq(1, $llm->completeCalls, 'closed output needs no continuation');
});

test('ContinuationRecovery cap exhaustion throws with the stitched partial text', function () {
    $llm = new FakeLlm();
    $llm->queueText('<section cla', 'max_tokens');
    $llm->queueText('ss="x"', 'length');
    $llm->queueText(' data-cut="yes"', 'model_context_window_exceeded');

    try {
        ContinuationRecovery::completeToClose(
            $llm,
            'Generate one section.',
            [],
            'continuation_test_html_is_closed',
        );
        throw new RuntimeException('expected TruncatedGenerationException');
    } catch (TruncatedGenerationException $e) {
        assert_eq('<section class="x" data-cut="yes"', $e->getPartialText());
    }

    assert_eq(3, $llm->completeCalls, 'default maxRounds caps total requests at three');
});
