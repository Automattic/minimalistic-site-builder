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

test('TextBatchRecovery doubles the calling client configurable default for a truncated retry', function () {
    $requests = ['part' => ['prompt' => 'Generate the part']];
    $budgets = [];

    $send = function (array $subset) use (&$budgets): array {
        $budgets[] = $subset['part']['max_tokens'] ?? null;
        return count($budgets) === 1
            ? ['part' => ['text' => '<!-- wp:group {"cut', 'stop_reason' => 'max_tokens']]
            : ['part' => ['text' => '<!-- wp:group --><!-- /wp:group -->', 'stop_reason' => 'end_turn']];
    };

    TextBatchRecovery::run($requests, $send, defaultMaxTokens: 4096);
    assert_eq([null, 8192], $budgets);
});

test('TextBatchRecovery keeps a salvageable initial response over a worse abnormal retry', function () {
    $requests = ['part' => ['prompt' => 'Generate the part']];
    $rounds = 0;
    $initial = "<!-- wp:group --><div class=\"wp-block-group\"></div><!-- /wp:group -->\n<!-- wp:paragraph";
    $longerButUnsalvageable = '<!-- wp:group --><div>' . str_repeat('cut ', 1000);

    $send = function (array $subset) use (&$rounds, $initial, $longerButUnsalvageable): array {
        $rounds++;
        return $rounds === 1
            ? ['part' => ['text' => $initial, 'stop_reason' => 'max_tokens']]
            : ['part' => ['text' => $longerButUnsalvageable, 'stop_reason' => 'max_tokens']];
    };

    $out = TextBatchRecovery::run($requests, $send);

    assert_eq(2, $rounds, 'the configured one regeneration is honored');
    assert_true(strlen($longerButUnsalvageable) > strlen($initial), 'the bad retry is deliberately longer');
    assert_eq($initial, $out['part'], 'the earlier salvageable markup is not overwritten by the worse retry');
});

test('TextBatchRecovery upgrades an empty truncation to a non-empty abnormal retry', function () {
    $requests = ['part' => ['prompt' => 'Generate the part']];
    $rounds = 0;
    $retry = '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->';

    $send = function (array $subset) use (&$rounds, $retry): array {
        $rounds++;
        return ['part' => [
            'text' => $rounds === 1 ? '' : $retry,
            'stop_reason' => 'max_tokens',
        ]];
    };

    $out = TextBatchRecovery::run($requests, $send);

    assert_eq(2, $rounds);
    assert_eq($retry, $out['part'], 'a real partial response improves on an empty first attempt');
});

test('TextBatchRecovery keeps a prior abnormal candidate when regeneration throws', function () {
    $requests = ['part' => ['prompt' => 'Generate the part']];
    $rounds = 0;
    $initial = '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group --><!-- wp:para';

    $send = function (array $subset) use (&$rounds, $initial): array {
        $rounds++;
        if ($rounds === 1) {
            return ['part' => ['text' => $initial, 'stop_reason' => 'max_tokens']];
        }
        throw new RuntimeException('retry budget exceeds model limit');
    };

    $out = TextBatchRecovery::run($requests, $send);

    assert_eq(2, $rounds);
    assert_eq($initial, $out['part'], 'a retry transport exception does not discard the paid-for candidate');
});

test('TextBatchRecovery isolates retry failures so a successful sibling is retained and accounted', function () {
    $requests = [
        'broken' => ['prompt' => 'Generate broken'],
        'healthy' => ['prompt' => 'Generate healthy'],
    ];
    $calls = [];
    $accounted = [];
    $brokenInitial = '<!-- wp:group --><div></div><!-- /wp:group --><!-- wp:para';

    $send = function (array $subset) use (&$calls, &$accounted, $brokenInitial): array {
        $keys = array_keys($subset);
        $calls[] = $keys;
        if (count($keys) === 2) {
            return [
                'broken' => ['text' => $brokenInitial, 'stop_reason' => 'max_tokens'],
                'healthy' => ['text' => '<!-- wp:group {"cut', 'stop_reason' => 'max_tokens'],
            ];
        }
        if ($keys === ['broken']) {
            throw new RuntimeException('retry rejected by model');
        }

        $accounted[] = 'healthy';
        return ['healthy' => [
            'text' => '<!-- wp:group --><div>complete</div><!-- /wp:group -->',
            'stop_reason' => 'end_turn',
        ]];
    };

    $out = TextBatchRecovery::run($requests, $send);

    assert_eq(
        [['broken', 'healthy'], ['broken'], ['healthy']],
        $calls,
        'the initial request remains batched while regenerations get independent accounting boundaries',
    );
    assert_eq(['healthy'], $accounted, 'the successful sibling retry completes its transport accounting path');
    assert_eq($brokenInitial, $out['broken'], 'the failed retry falls back to its original candidate');
    assert_contains('complete', $out['healthy'], 'the successful sibling retry is returned');
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
