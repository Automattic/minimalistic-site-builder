<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\AssignImageSourcesStep;
use Automattic\SiteBuild\Steps\SpliceHomeDesignStep;
use Automattic\SiteBuild\Steps\TransformSiteStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/** @return array{0:Project,1:FakeLlm,2:string} */
function pam_transform_fixture(array $pages, array $map): array
{
    $tmp = sys_get_temp_dir() . '/builder_page_artifact_map_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'Mapped artifact regression']);
    $project->writeJson('siteSpec.json', [
        'name' => 'Mapped Studio',
        'language' => 'English',
        'pages' => $pages,
    ]);
    $project->writeJson('designDirection.json', test_design_direction('cinematic-safe-zone', [
        'description' => 'Mapped editorial system',
    ]));
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => []]);
    $project->writeText('design/site.css', ".mapped{color:#123}\n");
    $project->writeJson('design/page-artifact-map.json', $map);
    $project->writeText(
        'design/home.html',
        '<!doctype html><html><body><header><p>MAPPED-HEADER</p></header><main>'
            . '<section id="home-content"><h1>MAPPED-HOME</h1></section></main>'
            . '<footer><p>MAPPED-FOOTER</p></footer></body></html>',
    );
    return [$project, new FakeLlm(), $tmp];
}

function pam_transform_run(Project $project, FakeLlm $llm): void
{
    (new TransformSiteStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
}

test('transform-site uses page-artifact-map as sole semantic-to-physical source map', function () {
    [$project, $llm, $tmp] = pam_transform_fixture(
        [
            ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Welcome'],
            ['slug' => 'preview', 'title' => 'Preview', 'purpose' => 'Reserved semantic slug'],
            ['slug' => 'site', 'title' => 'Site', 'purpose' => 'Another reserved semantic slug'],
        ],
        ['landing' => 'home', 'preview' => 'preview-2', 'site' => 'site-2'],
    );
    $project->writeText(
        'design/preview-2.html',
        '<main><section id="preview-content"><h1>MAPPED-PREVIEW-SUCCESS</h1></section></main>',
    );
    $project->writeText(
        'design/site-2.html',
        '<main><section id="site-content"><h1>MAPPED-SITE-SUCCESS</h1></section></main>',
    );
    $project->writeText('design/preview.html', '<dialog>SEED-PREVIEW-MUST-NOT-COMPILE</dialog>');
    $project->writeText('design/home-body.html', '<dialog>HOME-BODY-MUST-NOT-COMPILE</dialog>');
    $project->writeText('design/orphan.html', '<dialog>UNMAPPED-HTML-MUST-NOT-COMPILE</dialog>');

    pam_transform_run($project, $llm);

    assert_eq(0, count($llm->calls), 'excluded and unmapped HTML creates no repair request');
    assert_eq(['landing', 'preview', 'site'], array_column(
        $project->readJson('pages.json')['pages'],
        'slug',
    ));
    assert_contains(
        'MAPPED-PREVIEW-SUCCESS',
        $project->readText('theme/parts/page-preview--preview-content.html'),
    );
    assert_contains(
        'MAPPED-SITE-SUCCESS',
        $project->readText('theme/parts/page-site--site-content.html'),
    );
    assert_eq([], $project->readJson(TransformArtifacts::REPORT)['fallback_codes']);
    $outputs = implode("\n", [
        $project->readText('theme/parts/page-preview--preview-content.html'),
        $project->readText('theme/parts/page-site--site-content.html'),
        $project->readText(TransformArtifacts::REPORT),
    ]);
    foreach (['SEED-PREVIEW', 'HOME-BODY', 'UNMAPPED-HTML'] as $excluded) {
        assert_true(!str_contains($outputs, $excluded), "{$excluded} excluded from transform");
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('transform-site resolves mapped failed markers while keeping semantic page slugs', function () {
    [$project, $llm, $tmp] = pam_transform_fixture(
        [
            ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Welcome'],
            ['slug' => 'preview', 'title' => 'Preview', 'purpose' => 'Reserved semantic slug'],
        ],
        ['landing' => 'home', 'preview' => 'preview-2'],
    );
    $project->writeText(
        'design/preview-2.html',
        '<main><section id="stale"><h1>STALE-MAPPED-PAGE</h1></section></main>',
    );
    $project->writeText('design/preview-2.failed', "Mapped page failed after repair.\n");

    pam_transform_run($project, $llm);

    assert_eq(['landing'], array_column($project->readJson('pages.json')['pages'], 'slug'));
    assert_true(!$project->exists('theme/parts/page-preview--stale.html'));
    $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
    assert_contains('source design/preview-2.failed', $warnings);
    assert_contains('selector page[slug=preview]', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('transform-site treats missing or non-basename map entries as corrupt required artifacts', function () {
    $cases = [
        'missing key' => ['home' => 'home'],
        'path value' => ['home' => 'home', 'about' => 'design/about'],
        'extension value' => ['home' => 'home', 'about' => 'about.html'],
        'nested value' => ['home' => 'home', 'about' => ['artifact' => 'about']],
    ];
    foreach ($cases as $name => $map) {
        [$project, $llm, $tmp] = pam_transform_fixture(
            [
                ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome'],
                ['slug' => 'about', 'title' => 'About', 'purpose' => 'Explain'],
            ],
            $map,
        );
        $project->writeText('design/about.html', '<main><section id="about">About</section></main>');
        $caught = null;
        try {
            pam_transform_run($project, $llm);
        } catch (Throwable $error) {
            $caught = $error;
        }
        assert_true($caught instanceof RuntimeException, "{$name} throws corrupt-artifact failure");
        assert_contains('design/page-artifact-map.json', $caught->getMessage(), $name);
        assert_contains('corrupt required artifact', $caught->getMessage(), $name);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('assign-image-sources leaves preview and intermediate home-body bytes untouched', function () {
    $tmp = sys_get_temp_dir() . '/builder_assign_intermediate_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $preview = '<img alt="PREVIEW-IMAGE-MUST-STAY-SOURCELESS">';
    $homeBody = '<main><img alt="HOME-BODY-IMAGE-MUST-STAY-SOURCELESS"></main><footer>Footer</footer>';
    $project->writeText('design/preview.html', $preview);
    $project->writeText('design/home-body.html', $homeBody);
    $project->writeText('design/home.html', '<img alt="COMPOSED-HOME-IMAGE">');

    (new AssignImageSourcesStep())->run($project);

    assert_eq($preview, $project->readText('design/preview.html'));
    assert_eq($homeBody, $project->readText('design/home-body.html'));
    assert_contains('theme:./assets/', $project->readText('design/home.html'));
    $log = $project->readText('logs/assign-image-sources.log');
    assert_true(!str_contains($log, 'design/preview.html'));
    assert_true(!str_contains($log, 'design/home-body.html'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('splice-home-design ignores stale failed marker when valid home-body exists', function () {
    $tmp = sys_get_temp_dir() . '/builder_splice_stale_marker_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText(
        'design/preview.html',
        '<!doctype html><html><head><style>:root{--ink:#111}</style></head><body>'
            . '<header><nav>STALE-MARKER-HEADER</nav></header><main>'
            . '<section id="hero"><h1>STALE-MARKER-HERO</h1></section></main></body></html>',
    );
    $project->writeText(
        'design/home-body.html',
        '<main><section id="story">VALID-BODY-BEATS-STALE-MARKER</section></main>'
            . '<footer>VALID-BODY-FOOTER</footer>',
    );
    $project->writeText('design/home-body.failed', "Stale diagnostic marker.\n");

    (new SpliceHomeDesignStep())->run($project);

    $home = $project->readText('design/home.html');
    assert_contains('VALID-BODY-BEATS-STALE-MARKER', $home);
    assert_contains('VALID-BODY-FOOTER', $home);
    assert_true(!$project->exists('warnings.json'), 'stale undeclared marker is never probed');
    exec('rm -rf ' . escapeshellarg($tmp));
});
