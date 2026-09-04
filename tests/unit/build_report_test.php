<?php
declare(strict_types=1);

use Automattic\SiteBuild\BuildReport;

test('build-report formats a row with right-aligned, thousands-separated tokens', function () {
    $row = BuildReport::formatRow('site-spec', 2.34, 3000, 210);
    assert_contains('site-spec', $row);
    assert_contains('2.3s', $row);
    assert_contains('3,000', $row);
    assert_contains('210', $row);
    assert_contains('3,210', $row);
    assert_contains('in-tok', BuildReport::formatHeader());
    assert_contains('out-tok', BuildReport::formatHeader());
});

test('build-report row names the model configured for recorded LLM spend', function () {
    assert_contains('claude-opus-4-8', BuildReport::formatRow('site-spec', 2.34, 3000, 210, 'claude-opus-4-8'));
    // A deterministic step ran on no model at all — an em dash, never a blank
    // column, so the report never reads as "we forgot to record this one".
    assert_contains('—', BuildReport::formatRow('scaffold-theme', 0.0, 0, 0));
});

test('build-report sums per-step tokens into the totals', function () {
    $r = new BuildReport('A cozy bakery', 'cozy-bakery', '/tmp/cozy-bakery', '2026-06-30T00:00:00+00:00');
    $r->recordStep('scaffold-theme', 0.0, 0, 0);
    $r->recordStep('site-spec', 2.3, 3000, 200);
    $r->recordStep('sections', 21.4, 33000, 12200);

    assert_eq(33000, $r->totalInputTokens());
    assert_eq(12200, $r->totalOutputTokens());
    assert_eq(45200, $r->totalTokens());
    assert_eq(23.7, round($r->totalSecs(), 1));
    assert_contains('45,200', $r->totalLine());
    assert_contains('33,000', $r->totalLine());
    assert_contains('12,200', $r->totalLine());
});

test('build-report attributes cumulative usage across pipeline and extra steps', function () {
    $r = new BuildReport('p', 'slug', '/tmp/slug', '2026-06-30T00:00:00+00:00');

    $site = $r->recordStep('site-spec', 1.0, 100, 50, 'small-model');
    $images = $r->recordStep('generate-images', 2.0, 125, 60, 'repair-model');
    $cover = $r->recordStep('cover-contrast', 0.1, 125, 60, 'incorrect-config');

    assert_eq(100, $site['in']);
    assert_eq(50, $site['out']);
    assert_eq('small-model', $site['model']);
    assert_eq(25, $images['in'], 'conditional image-prompt repair input is attributed');
    assert_eq(10, $images['out'], 'conditional image-prompt repair output is attributed');
    assert_eq('repair-model', $images['model']);
    assert_eq(null, $cover['model'], 'a configured model is suppressed when the step made no LLM call');
});

test('build-report aggregate totals remain authoritative when usage is not attributed to a row', function () {
    $r = new BuildReport('p', 'slug', '/tmp/slug', '2026-06-30T00:00:00+00:00');
    $r->recordStep('site-spec', 1.0, 100, 50, 'small-model');
    $r->setLlmTotals(2, 125, 60);
    $stats = $r->stats('large-model', ['site-spec' => 'small-model']);

    assert_eq(125, $r->totalInputTokens());
    assert_eq(60, $r->totalOutputTokens());
    assert_eq(185, $r->totalTokens());
    assert_eq(125, $stats['input_tokens']);
    assert_eq(60, $stats['output_tokens']);
    assert_eq(185, $stats['total_tokens']);
    assert_eq(150, $stats['steps'][0]['total_tokens'], 'the row remains the attributed breakdown');
});

test('build-report rejects a decreasing cumulative usage cursor', function () {
    $r = new BuildReport('p', 'slug', '/tmp/slug', '2026-06-30T00:00:00+00:00');
    $r->recordStep('site-spec', 1.0, 100, 50, 'small-model');

    assert_throws(fn () => $r->recordStep('sections', 1.0, 99, 50, 'large-model'));
    assert_throws(fn () => $r->setLlmTotals(1, 100, 49));
});

test('build-report omits the images line until an image step is recorded', function () {
    $r = new BuildReport('p', 'slug', '/tmp/slug', '2026-06-30T00:00:00+00:00');
    $r->recordStep('site-spec', 1.0, 100, 50);
    assert_eq(null, $r->imagesLine());

    $r->setImages(6, 1, 7);
    assert_eq('Images: 6 generated, 1 failed (7 total)', $r->imagesLine());
});

test('build-report renders a full document with header, table, totals and images', function () {
    $r = new BuildReport('A cozy bakery', 'cozy-bakery', '/tmp/cozy-bakery', '2026-06-30T00:00:00+00:00');
    $r->recordStep('scaffold-theme', 0.0, 0, 0);
    $r->recordStep('site-spec', 2.3, 3000, 200);
    $r->setImages(2, 0, 2);
    $r->setLlmTotals(4, 3000, 200);
    $r->setWallSeconds(3.7);
    $r->setBuiltAt('2026-06-30T00:00:10+00:00');

    $out = $r->render();
    assert_contains('BUILD REPORT — cozy-bakery', $out);
    assert_contains('Prompt       : A cozy bakery', $out);
    assert_contains('Built at     : 2026-06-30T00:00:10+00:00', $out);
    assert_contains('Output       : /tmp/cozy-bakery', $out);
    assert_contains('in-tok', $out);
    assert_contains('out-tok', $out);
    assert_contains('scaffold-theme', $out);
    assert_contains('TOTAL', $out);
    assert_contains('Wall time    : 3.7s', $out);
    assert_contains('LLM requests : 4', $out);
    assert_contains('Images: 2 generated, 0 failed (2 total)', $out);
});

test('build-report lines the starting line up with the row that completes it', function () {
    $start = BuildReport::formatStartRow('site-spec', 'Draft the site spec');
    $row = BuildReport::formatRow('site-spec', 2.34, 3000, 210);

    assert_contains('site-spec', $start);
    assert_contains('Draft the site spec…', $start);
    // The "→ " marker eats exactly the two columns the id column gives back,
    // so both lines start their second field in the same place. Nothing else
    // enforces that, and the two are read together in the live output.
    assert_eq(mb_strpos($row, '2.3s') - 4, mb_strpos($start, 'Draft'));
});

test('build-report resolves the model label of a concurrent step group', function () {
    $models = [
        'design-direction-seeds' => 'small-model',
        'design-direction-judge' => 'judge-model',
        'design-direction' => 'large-model',
        'theme-json' => 'small-model',
        'page-plan' => 'small-model',
        'sections' => 'large-model',
    ];

    assert_eq('large-model', BuildReport::modelLabel('sections', $models));
    // A group id is its members joined by '+' and is never a map key itself;
    // members sharing a tier collapse to one label rather than repeating it.
    assert_eq('small-model', BuildReport::modelLabel('theme-json+page-plan', $models));
    assert_eq('small-model, large-model', BuildReport::modelLabel('theme-json+sections', $models));
    assert_eq(
        'small-model, judge-model, large-model',
        BuildReport::modelLabel('design-direction', $models),
        'one row names each configured model contributing calls to the step: seeds, judge, expansion',
    );
    assert_eq('small-model', BuildReport::modelLabel('generate-images', [
        'image-prompt-repair' => 'small-model',
    ]));
    assert_eq(null, BuildReport::modelLabel('scaffold-theme', $models), 'a deterministic step ran on no model');
});

test('build-report carries each step model through into the rendered table', function () {
    $r = new BuildReport('A cozy bakery', 'cozy-bakery', '/tmp/cozy-bakery', '2026-06-30T00:00:00+00:00');
    $r->recordStep('scaffold-theme', 0.0, 0, 0, 'configured-but-unused');
    $r->recordStep('site-spec', 2.3, 3000, 200, 'claude-haiku-4-8');

    $out = $r->render();
    assert_contains('claude-haiku-4-8', $out);
    assert_contains('—', $out, 'the deterministic step keeps an em dash in the model column');
});

test('build-report serializes the run into build-stats.json shape', function () {
    $r = new BuildReport('A cozy bakery', 'cozy-bakery', '/tmp/cozy-bakery', '2026-06-30T00:00:00+00:00');
    $r->recordStep('scaffold-theme', 0.0, 0, 0);
    $r->recordStep('site-spec', 2.34, 3000, 200, 'claude-haiku-4-8');
    $r->setLlmTotals(4, 3000, 200);
    // Wall time is measured, not summed: it also covers the work between steps.
    $r->setWallSeconds(9.87);
    $r->setBuiltAt('2026-06-30T00:00:10+00:00');

    $stats = $r->stats('claude-opus-4-8', ['site-spec' => 'claude-haiku-4-8']);

    assert_eq('A cozy bakery', $stats['prompt']);
    assert_eq(9.9, $stats['wall_seconds']);
    assert_eq(4, $stats['requests']);
    assert_eq(3000, $stats['input_tokens']);
    assert_eq(200, $stats['output_tokens']);
    assert_eq(3200, $stats['total_tokens']);
    assert_eq('claude-opus-4-8', $stats['model'], 'the default model the run started from');
    assert_eq(['site-spec' => 'claude-haiku-4-8'], $stats['step_models']);
    assert_eq('2026-06-30T00:00:10+00:00', $stats['built_at'], 'built_at is the completion timestamp');
    assert_eq(
        [
            ['id' => 'scaffold-theme', 'seconds' => 0.0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'model' => null],
            ['id' => 'site-spec', 'seconds' => 2.3, 'input_tokens' => 3000, 'output_tokens' => 200, 'total_tokens' => 3200, 'model' => 'claude-haiku-4-8'],
        ],
        $stats['steps'],
    );
});

test('build-report surfaces warnings.json so a warned build never looks clean', function () {
    $r = new BuildReport('p', 'slug', '/tmp/slug', '2026-06-30T00:00:00+00:00');
    $r->recordStep('site-spec', 1.0, 100, 50);
    assert_eq(null, $r->warningsLine(), 'no line when the build delivered clean');

    $r->setWarnings([
        'fix-blocks' => ['a dropped declaration', 'a failed file'],
        'sections'   => ['a dropped section'],
        'empty-step' => [],
    ]);
    assert_eq(
        'Warnings: 3 defect(s) delivered through — see warnings.json (fix-blocks: 2, sections: 1)',
        $r->warningsLine(),
    );
    assert_contains('Warnings: 3 defect(s) delivered through', $r->render());
});
