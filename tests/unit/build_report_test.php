<?php
declare(strict_types=1);

test('build-report formats a row with right-aligned, thousands-separated tokens', function () {
    $row = BuildReport::formatRow('site-spec', 2.34, 3000, 210);
    assert_contains('site-spec', $row);
    assert_contains('2.3s', $row);
    assert_contains('3,210 tok', $row);
});

test('build-report sums per-step tokens into the totals', function () {
    $r = new BuildReport('A cozy bakery', 'cozy-bakery', '/tmp/cozy-bakery', '2026-06-30T00:00:00+00:00');
    $r->addStep('scaffold-theme', 0.0, 0, 0);
    $r->addStep('site-spec', 2.3, 3000, 200);
    $r->addStep('sections', 21.4, 30000, 12000);

    assert_eq(33000, $r->totalInputTokens());
    assert_eq(12200, $r->totalOutputTokens());
    assert_eq(45200, $r->totalTokens());
    assert_eq(23.7, round($r->totalSecs(), 1));
    assert_contains('45,200 tok', $r->totalLine());
    assert_contains('33,000 in + 12,200 out', $r->totalLine());
});

test('build-report omits the images line until an image step is recorded', function () {
    $r = new BuildReport('p', 'slug', '/tmp/slug', '2026-06-30T00:00:00+00:00');
    $r->addStep('site-spec', 1.0, 100, 50);
    assert_eq(null, $r->imagesLine());

    $r->setImages(6, 1, 7);
    assert_eq('Images: 6 generated, 1 failed (7 total)', $r->imagesLine());
});

test('build-report renders a full document with header, table, totals and images', function () {
    $r = new BuildReport('A cozy bakery', 'cozy-bakery', '/tmp/cozy-bakery', '2026-06-30T00:00:00+00:00');
    $r->addStep('scaffold-theme', 0.0, 0, 0);
    $r->addStep('site-spec', 2.3, 3000, 200);
    $r->setImages(2, 0, 2);
    $r->setRequestCount(4);

    $out = $r->render();
    assert_contains('BUILD REPORT — cozy-bakery', $out);
    assert_contains('Prompt       : A cozy bakery', $out);
    assert_contains('Output       : /tmp/cozy-bakery', $out);
    assert_contains('scaffold-theme', $out);
    assert_contains('TOTAL', $out);
    assert_contains('LLM requests : 4', $out);
    assert_contains('Images: 2 generated, 0 failed (2 total)', $out);
});

test('build-report records llm model and temperature config', function () {
    $r = new BuildReport('A cozy bakery', 'cozy-bakery', '/tmp/cozy-bakery', '2026-06-30T00:00:00+00:00');
    $r->setRequestCount(1);
    $r->setLlmConfig(
        ['theme-json' => 'claude-opus-4-8'],
        ['theme-json' => 1.0],
    );

    $out = $r->render();
    assert_contains('LLM config', $out);
    assert_contains('theme-json', $out);
    assert_contains('model=claude-opus-4-8 temperature=1.0', $out);
});
