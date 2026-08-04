<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\InnerPagesDesignStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * @param list<array<string,mixed>> $pages
 * @return array{0:Project,1:string}
 */
function page_artifact_map_fixture(array $pages): array
{
    $tmp = sys_get_temp_dir() . '/builder_page_artifact_map_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', [
        'name' => 'Artifact Map Studio',
        'language' => 'English',
        'pages' => $pages,
    ]);
    $project->writeText('design/site.css', ':root{--ink:#18222d}');
    $project->writeText(
        'design/preview.html',
        '<!doctype html><html><head><style>:root{--ink:#18222d}</style></head>'
            . '<body><header>Header</header><main><section id="hero">Hero</section></main>'
            . '</body></html>',
    );
    return [$project, $tmp];
}

function page_artifact_map_step(FakeLlm $llm): InnerPagesDesignStep
{
    return new InnerPagesDesignStep($llm, new PromptRenderer(repo_path('prompts')));
}

function page_artifact_map_home_body(string $marker): string
{
    return '<main><section><h2>' . $marker . '</h2></section></main>'
        . '<footer><p>Footer</p></footer>';
}

function page_artifact_map_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

test('page artifact map binds semantic slugs to deterministic physical slugs and replaces stale rows', function () {
    [$project, $tmp] = page_artifact_map_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
        [
            'slug' => 'preview',
            'title' => 'Preview',
            'purpose' => 'Reserved preview page',
            'children' => [[
                'slug' => 'site',
                'title' => 'Site',
                'purpose' => 'Reserved site page',
            ]],
        ],
        ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Reserved home page'],
        ['slug' => 'about', 'title' => 'About', 'purpose' => 'Ordinary page'],
    ]);
    $llm = new FakeLlm();
    foreach ([
        page_artifact_map_home_body('FIRST-HOME-BODY'),
        '<main><h1>Preview</h1></main>',
        '<main><h1>Site</h1></main>',
        '<main><h1>Home</h1></main>',
        '<main><h1>About</h1></main>',
    ] as $response) {
        $llm->queueText($response);
    }

    page_artifact_map_step($llm)->run($project);

    $expected = [
        'landing' => 'home',
        'preview' => 'preview-2',
        'site' => 'site-2',
        'home' => 'home-2',
        'about' => 'about',
    ];
    assert_eq($expected, $project->readJson('design/page-artifact-map.json'));
    foreach ($project->readJson('design/page-artifact-map.json') as $physicalSlug) {
        assert_true(!str_contains($physicalSlug, '/'), 'map values omit paths');
        assert_true(!str_contains($physicalSlug, '.'), 'map values omit extensions');
    }

    $project->writeJson('siteSpec.json', [
        'name' => 'Artifact Map Studio',
        'language' => 'English',
        'pages' => [
            ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
            ['slug' => 'about', 'title' => 'About', 'purpose' => 'Ordinary page'],
        ],
    ]);
    $rerunLlm = new FakeLlm();
    $rerunLlm->queueText(page_artifact_map_home_body('SECOND-HOME-BODY'));
    $rerunLlm->queueText('<main><h1>About rerun</h1></main>');
    page_artifact_map_step($rerunLlm)->run($project);

    assert_eq(
        ['landing' => 'home', 'about' => 'about'],
        $project->readJson('design/page-artifact-map.json'),
        'rerun replaces map instead of retaining stale semantic slugs',
    );
    page_artifact_map_cleanup($tmp);
});

test('failed page units remove stale physical HTML before writing markers', function () {
    [$project, $tmp] = page_artifact_map_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
        ['slug' => 'preview', 'title' => 'Preview', 'purpose' => 'Reserved preview page'],
    ]);
    $project->writeText('design/home-body.html', 'STALE-HOME-BODY');
    $project->writeText('design/preview-2.html', 'STALE-RESERVED-INNER-PAGE');

    $llm = new FakeLlm();
    $llm->queueText('<main><section>Home missing footer</section></main>');
    $llm->queueText('<section>Preview missing main</section>');
    $llm->queueText('<main><section>Home repair still missing footer</section></main>');
    $llm->queueText('<div>Preview repair still missing main</div>');

    page_artifact_map_step($llm)->run($project);

    assert_true(!$project->exists('design/home-body.html'), 'failed home-body clears stale HTML');
    assert_true($project->exists('design/home-body.failed'));
    assert_true(!$project->exists('design/preview-2.html'), 'failed inner page clears stale physical HTML');
    assert_true($project->exists('design/preview-2.failed'));
    page_artifact_map_cleanup($tmp);
});
