<?php
declare(strict_types=1);

use Automattic\SiteBuild\TextBatchRecovery;

test('TextBatchRecovery returns an all-complete batch without a retry', function () {
    $requests = [
        'header'         => ['prompt' => 'Generate the header'],
        'page-home--hero' => ['prompt' => 'Generate the hero'],
    ];
    $rounds = [];

    $send = function (array $subset) use (&$rounds): array {
        $rounds[] = array_keys($subset);
        return [
            'header'          => ['text' => '<!-- wp:group --><!-- /wp:group -->', 'stop_reason' => 'end_turn'],
            'page-home--hero' => ['text' => '<!-- wp:cover --><!-- /wp:cover -->', 'stop_reason' => 'end_turn'],
        ];
    };

    $out = TextBatchRecovery::run($requests, $send);

    assert_eq([['header', 'page-home--hero']], $rounds, 'an all-complete batch is sent exactly once');
    assert_eq(['header', 'page-home--hero'], array_keys($out), 'input order is preserved');
    assert_eq('<!-- wp:cover --><!-- /wp:cover -->', $out['page-home--hero']);
});

test('TextBatchRecovery regenerates only the truncated sibling with a doubled budget', function () {
    $requests = [
        'header'          => ['prompt' => 'Generate the header'],
        'page-home--hero' => ['prompt' => 'Generate the hero', 'max_tokens' => 4000],
    ];
    $rounds = [];
    $regenerated = [];

    $send = function (array $subset) use (&$rounds, &$regenerated): array {
        $rounds[] = array_keys($subset);
        if (count($rounds) === 1) {
            return [
                'header'          => ['text' => '<!-- wp:group --><!-- /wp:group -->', 'stop_reason' => 'end_turn'],
                'page-home--hero' => ['text' => '<!-- wp:cover {"url":"', 'stop_reason' => 'max_tokens'],
            ];
        }
        $regenerated = $subset;
        return ['page-home--hero' => ['text' => '<!-- wp:cover --><!-- /wp:cover -->', 'stop_reason' => 'end_turn']];
    };

    $out = TextBatchRecovery::run($requests, $send);

    assert_eq([['header', 'page-home--hero'], ['page-home--hero']], $rounds, 'the complete sibling is retained');
    assert_eq('<!-- wp:cover --><!-- /wp:cover -->', $out['page-home--hero']);
    assert_eq(8000, $regenerated['page-home--hero']['max_tokens'], 'the retry doubles the explicit output budget');
    assert_contains('CUT OFF BY THE OUTPUT LENGTH LIMIT', $regenerated['page-home--hero']['prompt']);
    assert_contains('-regenerate', (string) $regenerated['page-home--hero']['log_label']);
    assert_true(
        !str_contains($regenerated['page-home--hero']['prompt'], '<!-- wp:cover {"url":"'),
        'the truncated text is not re-embedded (it cannot be repaired, only regenerated)',
    );
});

test('TextBatchRecovery defaults a truncated retry to twice the 16k client default', function () {
    $requests = ['part' => ['prompt' => 'Generate the part']];
    $budgets = [];

    $send = function (array $subset) use (&$budgets): array {
        $budgets[] = $subset['part']['max_tokens'] ?? null;
        return count($budgets) === 1
            ? ['part' => ['text' => '<!-- wp:group {"cut', 'stop_reason' => 'max_tokens']]
            : ['part' => ['text' => '<!-- wp:group --><!-- /wp:group -->', 'stop_reason' => 'end_turn']];
    };

    TextBatchRecovery::run($requests, $send);
    assert_eq([null, 32000], $budgets);
});

test('TextBatchRecovery keeps the partial text when regeneration is still truncated', function () {
    $requests = ['part' => ['prompt' => 'Generate the part']];
    $rounds = 0;

    $send = function (array $subset) use (&$rounds): array {
        $rounds++;
        return ['part' => ['text' => "<!-- wp:group {\"round\":{$rounds}", 'stop_reason' => 'max_tokens']];
    };

    $out = TextBatchRecovery::run($requests, $send);

    assert_eq(2, $rounds, 'the configured one regeneration is honored');
    assert_eq('<!-- wp:group {"round":2', $out['part'], 'the last best-effort text is returned, not an exception');
});

test('TextBatchRecovery regenerates a refusal without touching the budget', function () {
    $requests = ['part' => ['prompt' => 'Generate the part', 'max_tokens' => 4000]];
    $regenerated = [];

    $send = function (array $subset) use (&$regenerated): array {
        if (str_contains((string) ($subset['part']['log_label'] ?? ''), 'regenerate')) {
            $regenerated = $subset;
            return ['part' => ['text' => '<!-- wp:group --><!-- /wp:group -->', 'stop_reason' => 'end_turn']];
        }
        return ['part' => ['text' => 'I cannot produce this section.', 'stop_reason' => 'refusal']];
    };

    $out = TextBatchRecovery::run($requests, $send);

    assert_eq('<!-- wp:group --><!-- /wp:group -->', $out['part']);
    assert_eq(4000, $regenerated['part']['max_tokens'], 'a refusal retry keeps the original budget');
    assert_contains('NO USABLE CONTENT', $regenerated['part']['prompt']);
    assert_true(
        !str_contains($regenerated['part']['prompt'], 'CUT OFF'),
        'a refusal is not framed as a truncation',
    );
});

test('TextBatchRecovery fails loud when the sender omits an expected key', function () {
    $requests = [
        'header' => ['prompt' => 'Header'],
        'footer' => ['prompt' => 'Footer'],
    ];
    $send = fn (array $subset): array => ['header' => ['text' => 'x', 'stop_reason' => 'end_turn']];

    assert_throws(fn () => TextBatchRecovery::run($requests, $send));
});

test('TextBatchRecovery preserves order and integer keys', function () {
    $requests = [
        7      => ['prompt' => 'Seven'],
        'part' => ['prompt' => 'Part'],
        2      => ['prompt' => 'Two'],
    ];

    $send = function (array $subset): array {
        $out = [];
        foreach ($subset as $key => $_request) {
            $out[$key] = ['text' => "text-{$key}", 'stop_reason' => 'end_turn'];
        }
        return $out;
    };

    $out = TextBatchRecovery::run($requests, $send);

    assert_eq([7, 'part', 2], array_keys($out));
    assert_eq('text-7', $out[7]);
    assert_eq('text-part', $out['part']);
    assert_eq('text-2', $out[2]);
});
