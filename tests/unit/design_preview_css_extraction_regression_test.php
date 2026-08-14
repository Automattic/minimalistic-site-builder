<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignPreviewStep;
use Automattic\SiteBuild\Tests\FakeLlm;

test('design-preview extracts byte-faithful CSS from the actual head style', function () {
    $tmp = sys_get_temp_dir() . '/builder_design_preview_css_source_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery']);
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

    $css = "\r\n\t:root { --content-size: 800px; --wide-size: 1280px; }\n"
        . "body { margin: 0; }\r\n"
        . "body::before { content: \"<style>RAW-TEXT-BAIT\"; }\n ";
    $preview = '<!doctype html><html lang="en">'
        . '<head data-style-bait="<style>ATTRIBUTE-BAIT</style>">'
        . '<title>Hearth &amp; Crumb</title>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>' . $css . '</style></head><body>'
        . '<header><nav aria-label="Primary"><a href="/menu">Menu</a></nav></header>'
        . '<main><section id="hero"><h1>Fresh bread</h1>'
        . '<img alt="AI_IMAGE: A baker sliding a sourdough loaf into a stone oven, viewed from counter height | homepage hero beside the primary headline | photorealistic | landscape">'
        . '</section></main></body></html>';

    $llm = new FakeLlm();
    $llm->queueText($preview);
    (new DesignPreviewStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq($preview, $project->readText('design/preview.html'));
    assert_eq($css, $project->readText('design/site.css'));

    exec('rm -rf ' . escapeshellarg($tmp));
});
