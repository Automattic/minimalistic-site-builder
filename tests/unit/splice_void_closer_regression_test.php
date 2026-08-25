<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignPreviewStep;
use Automattic\SiteBuild\Steps\SpliceHomeDesignStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array{0:Project,1:string} */
function splice_void_project(string $prefix): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    return [(new ProjectStore($tmp))->create('demo'), $tmp];
}

function splice_void_valid_fold(string $imageClose = ''): string
{
    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>:root { --content-size: 800px; --wide-size: 1280px; }'
        . 'body { margin: 0; font-family: system-ui, sans-serif; }'
        . 'header { display: flex; flex-direction: row; flex-wrap: nowrap; align-items: center; justify-content: space-between; }'
        . 'main { max-width: var(--wide-size); margin-inline: auto; }</style>'
        . '</head><body>'
        . '<header><a class="site-identity" href="/">VOID-CLOSER-HEADER</a>'
        . '<nav aria-label="Primary"><a href="/menu/">Menu</a></nav></header>'
        . '<main><section id="hero"><h1>VOID-CLOSER-HERO</h1>'
        . '<img alt="AI_IMAGE: A baker sliding a sourdough loaf into a stone oven, viewed from counter height | homepage hero beside the primary headline | photorealistic | landscape">'
        . $imageClose
        . '</section></main>'
        . '</body></html>';
}

function splice_void_body(): string
{
    return '<main><section id="story">VOID-CLOSER-BODY</section></main>'
        . '<footer>VOID-CLOSER-FOOTER</footer>';
}

test('splice accepts a DesignPreview-valid authored closing tag for an HTML void image', function () {
    [$project, $tmp] = splice_void_project('builder_splice_void_closer_');
    $project->writeJson('meta.json', [
        'prompt' => 'A neighborhood bakery with seasonal bread and classes',
    ]);
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb',
        'description' => 'Neighborhood bread and pastry studio',
        'pages' => [
            ['slug' => 'home', 'title' => 'Home'],
            ['slug' => 'menu', 'title' => 'Menu'],
        ],
    ]);
    $project->writeJson('designDirection.json', [
        'direction' => [
            'title' => 'Flour Archive',
            'description' => 'Warm editorial layouts with documentary bakery imagery.',
        ],
    ]);
    $fold = splice_void_valid_fold('</img>');
    $llm = new FakeLlm();
    $llm->queueText($fold);

    (new DesignPreviewStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq($fold, $project->readText('design/preview.html'));
    assert_contains('</img>', $project->readText('design/preview.html'));
    assert_true(!$project->exists('warnings.json'), 'DesignPreview accepts authored void closer');
    $project->writeText('design/home-body.html', splice_void_body());
    (new SpliceHomeDesignStep())->run($project);

    $home = $project->readText('design/home.html');
    foreach ([
        'VOID-CLOSER-HEADER',
        'VOID-CLOSER-HERO',
        'VOID-CLOSER-BODY',
        'VOID-CLOSER-FOOTER',
    ] as $marker) {
        assert_contains($marker, $home);
    }
    assert_contains('</img>', $home, 'splice preserves authored hero bytes');
    assert_true(!$project->exists('warnings.json'), 'valid splice adds no malformed-fold warning');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('splice still degrades a truly mismatched non-void closing tag', function () {
    [$project, $tmp] = splice_void_project('builder_splice_nonvoid_closer_');
    $project->writeText(
        'design/preview.html',
        str_replace(
            '</section></main>',
            '<div>TRULY-MALFORMED-NONVOID</span></section></main>',
            splice_void_valid_fold(),
        ),
    );
    $project->writeText('design/home-body.html', splice_void_body());

    (new SpliceHomeDesignStep())->run($project);

    assert_eq(splice_void_body(), $project->readText('design/home.html'));
    $warnings = implode("\n", $project->readJson('warnings.json')['splice-home-design'] ?? []);
    assert_contains('fold missing header, section#hero, or a closed document envelope', $warnings);
    assert_contains('disposition degraded', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});
