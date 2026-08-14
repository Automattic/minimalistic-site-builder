<?php
declare(strict_types=1);

use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignPreviewStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array<string,mixed> */
function slice5_preview_reference(): array
{
    return [
        'url' => 'https://reference.example',
        'page_type' => 'landing',
        'owner_type' => 'other',
        'style' => 'Bold, high-contrast, playful',
        'colors' => [['hex' => '#ff90e8', 'name' => 'Candy pink', 'role' => 'accent']],
        'sections' => [[
            'category' => 'feature',
            'description' => 'Full-bleed color field with oversized headline',
        ]],
    ];
}

/** @return array{0:Project,1:FakeLlm,2:string} */
function slice5_preview_fixture(bool $withReference): array
{
    $tmp = sys_get_temp_dir() . '/builder_slice5_dp_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', [
        'prompt' => 'A neighborhood bakery with seasonal bread and classes',
    ]);
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb',
        'description' => 'Neighborhood bread and pastry studio',
    ]);
    $project->writeJson('designDirection.json', [
        'direction' => [
            'title' => 'Flour Archive',
            'description' => 'Warm editorial layouts with documentary bakery imagery.',
        ],
    ]);
    $project->writeJson('inspiration.json', $withReference
        ? [
            'urls' => ['https://reference.example'],
            'references' => [slice5_preview_reference()],
        ]
        : ['urls' => [], 'references' => []]);
    return [$project, new FakeLlm(), $tmp];
}

function slice5_preview_document(string $marker = 'DESIGN-PREVIEW'): string
{
    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>:root { --content-size: 800px; --wide-size: 1280px; }'
        . 'body { margin: 0; font-family: system-ui, sans-serif; }'
        . 'main { max-width: var(--wide-size); margin-inline: auto; }</style>'
        . '</head><body>'
        . '<header><nav aria-label="Primary"><a href="/menu">Menu</a></nav></header>'
        . '<main><section id="hero"><h1>' . $marker . '</h1>'
        . '<img alt="AI_IMAGE: A baker sliding a sourdough loaf into a stone oven, viewed from counter height | homepage hero beside the primary headline | photorealistic | landscape">'
        . '</section></main>'
        . '</body></html>';
}

function slice5_preview_run(Project $project, FakeLlm $llm): void
{
    (new DesignPreviewStep($llm, new PromptRenderer(Package::promptsDir())))->run($project);
}

test('design-preview declares inspiration.json as a read', function () {
    $step = new DesignPreviewStep(
        new FakeLlm(),
        new PromptRenderer(Package::promptsDir()),
    );
    assert_true(in_array('inspiration.json', $step->declaration()->reads, true));
});

test('disabled design-preview neither declares nor consumes inspiration', function () {
    [$project, $llm, $tmp] = slice5_preview_fixture(true);
    try {
        $llm->queueText(slice5_preview_document());
        $step = new DesignPreviewStep(
            $llm,
            new PromptRenderer(Package::promptsDir()),
            useInspiration: false,
        );

        assert_eq(false, in_array('inspiration.json', $step->declaration()->reads, true));
        $step->run($project);

        assert_eq(false, str_contains($llm->calls[0]['prompt'], 'BEGIN UNTRUSTED REFERENCE DATA'));
        assert_eq(false, str_contains($llm->calls[0]['prompt'], 'Bold, high-contrast, playful'));
    } finally {
        remove_tree($tmp);
    }
});

test('reference rhythm follows site spec in the preview prompt', function () {
    [$project, $llm, $tmp] = slice5_preview_fixture(true);
    try {
        $llm->queueText(slice5_preview_document());
        slice5_preview_run($project, $llm);

        $prompt = $llm->calls[0]['prompt'];
        $specOffset = strpos($prompt, 'Hearth & Crumb');
        $referenceOffset = strpos($prompt, 'BEGIN UNTRUSTED REFERENCE DATA');
        assert_true($specOffset !== false && $referenceOffset !== false && $specOffset < $referenceOffset);
        assert_contains('Full-bleed color field with oversized headline', $prompt);
        assert_contains('Use their section rhythm', $prompt);
    } finally {
        remove_tree($tmp);
    }
});

test('preview repair reuses the rendered reference-grounded prompt', function () {
    [$project, $llm, $tmp] = slice5_preview_fixture(true);
    try {
        $llm->queueText('<p>not a document</p>');
        $llm->queueText(slice5_preview_document('REPAIRED'));
        slice5_preview_run($project, $llm);

        assert_eq(2, count($llm->calls));
        assert_contains('BEGIN UNTRUSTED REFERENCE DATA', $llm->calls[1]['prompt']);
        assert_contains('Full-bleed color field with oversized headline', $llm->calls[1]['prompt']);
    } finally {
        remove_tree($tmp);
    }
});

test('no-reference preview prompt remains byte-identical to the pre-slice prompt', function () {
    [$project, $llm, $tmp] = slice5_preview_fixture(false);
    try {
        $llm->queueText(slice5_preview_document());
        slice5_preview_run($project, $llm);

        assert_eq(
            '4ca277c7e2d1ac6594f6aee99f6d61496f114278dcea4a69e19f0d58a936d5f5',
            hash('sha256', $llm->calls[0]['prompt']),
            'empty inspiration must add zero prompt bytes',
        );
    } finally {
        remove_tree($tmp);
    }
});
