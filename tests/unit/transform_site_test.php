<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\TransformSiteStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/** @return array{0:Project,1:FakeLlm,2:string} */
function transform_site_fixture(string $home, array $inner = [], int $repairBudget = 12): array
{
    $tmp = sys_get_temp_dir() . '/builder_transform_site_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'Demo site', 'repair_budget' => $repairBudget]);
    $pages = [['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome']];
    if (array_key_exists('about', $inner)) {
        $about = ['slug' => 'about', 'title' => 'About', 'purpose' => 'Explain'];
        if (array_key_exists('team', $inner)) {
            $about['children'] = [['slug' => 'team', 'title' => 'Team', 'purpose' => 'Introduce']];
        }
        $pages[] = $about;
    }
    $project->writeJson('siteSpec.json', [
        'name' => 'Northstar Studio',
        'language' => 'English',
        'pages' => $pages,
    ]);
    $project->writeJson('designDirection.json', ['description' => 'Crisp editorial system']);
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => []]);
    $project->writeJson('images.json', []);
    $project->writeText('design/site.css', ".shared{color:#123}\n");
    $project->writeText('design/home.html', $home);
    foreach ($inner as $slug => $html) {
        $project->writeText("design/{$slug}.html", $html);
    }
    return [$project, new FakeLlm(), $tmp];
}

function transform_site_run(Project $project, FakeLlm $llm): TransformSiteStep
{
    $step = new TransformSiteStep($llm, new PromptRenderer(repo_path('prompts')));
    $step->run($project);
    return $step;
}

function transform_site_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

/** @return array<string,string> */
function transform_site_outputs(Project $project): array
{
    $out = [];
    foreach (glob($project->themePath('parts/*.html')) ?: [] as $path) {
        $rel = 'theme/parts/' . basename($path);
        $out[$rel] = $project->readText($rel);
    }
    foreach (['design/transformer-carried.css', 'design/transform-report.json', 'pages.json'] as $rel) {
        if ($project->exists($rel)) {
            $out[$rel] = $project->readText($rel);
        }
    }
    ksort($out);
    return $out;
}

test('transform-site writes exact legacy part names and AssemblePagesStep accepts pages.json unchanged', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body>'
        . '<header><p>Shared header</p></header>'
        . '<section id="hero"><h1 style="width:321px">Hero</h1></section>'
        . '<section id="feature"><p>Feature</p></section>'
        . '<footer><p>Shared footer</p></footer>'
        . '</body></html>',
        [
            'about' => '<main><section id="about-intro"><h1>About</h1></section></main>',
            'team' => '<main><section id="team-intro"><h1>Team</h1></section></main>',
        ],
    );

    $step = transform_site_run($project, $llm);

    assert_eq('transform-site', $step->id());
    assert_eq(0, $llm->completeBatchCalls);
    foreach ([
        'theme/parts/header.html',
        'theme/parts/footer.html',
        'theme/parts/page-home--hero.html',
        'theme/parts/page-home--feature.html',
        'theme/parts/page-about--about-intro.html',
        'theme/parts/page-team--team-intro.html',
    ] as $rel) {
        assert_true($project->exists($rel), $rel);
    }
    $plan = $project->readJson('pages.json');
    assert_eq(['home', 'about', 'team'], array_column($plan['pages'], 'slug'));
    assert_eq([true, false, false], array_column($plan['pages'], 'front'));
    assert_eq([0, 10, 20], array_column($plan['pages'], 'menu_order'));
    assert_eq([null, null, 'about'], array_column($plan['pages'], 'parent'));
    assert_contains('.be-inline-geometry-', $project->readText(TransformArtifacts::CARRIED_CSS));

    $before = $project->readText('pages.json');
    (new AssemblePagesStep())->run($project);
    assert_eq($before, $project->readText('pages.json'), 'reader does not rewrite transform plan');
    assert_eq(['home', 'about', 'team'], array_column($project->readJson('plugin/pages.json')['pages'], 'slug'));
    transform_site_cleanup($tmp);
});

test('transform-site batches missing shell through legacy unit and keeps marker local', function () {
    $benign = 'BENIGN __TRANSFORM_SENTINEL__ COPY';
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header>'
        . '<main><section id="copy"><p>' . $benign . '</p></section></main></body></html>',
    );
    $llm->queueText(
        '<!-- wp:group {"tagName":"footer"} --><footer class="wp-block-group">'
        . '<!-- wp:paragraph --><p>STUB-FOOTER-MARKER</p><!-- /wp:paragraph -->'
        . '</footer><!-- /wp:group -->',
    );

    transform_site_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls);
    assert_contains('Build the site FOOTER template part', $llm->calls[0]['prompt']);
    assert_contains('STUB-FOOTER-MARKER', $project->readText('theme/parts/footer.html'));
    assert_contains($benign, $project->readText('theme/parts/page-home--copy.html'));
    $all = implode("\n", transform_site_outputs($project));
    assert_true(!str_contains($all, '__TRANSFORM_INTERNAL_'), 'no internal sentinel leaks');
    $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
    assert_contains('missing_shell_landmark', $warnings);
    assert_contains('design/home.html', $warnings);
    assert_contains('footer', $warnings);
    transform_site_cleanup($tmp);
});

test('transform-site repairs each unsupported diagnostic once in one batch', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="bad"><dialog data-probe="authored">Unsupported copy</dialog><p>Sibling</p></section>'
        . '</main><footer><p>Footer</p></footer></body></html>',
    );
    $llm->queueText('<div data-probe="repaired"><p>Supported copy</p></div>');

    $step = new TransformSiteStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
        'repair-model',
        0.37,
    );
    $step->run($project);

    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(1, count($llm->calls));
    assert_eq('repair-model', $llm->calls[0]['opts']['model'] ?? null);
    assert_eq(0.37, $llm->calls[0]['opts']['temperature'] ?? null);
    assert_contains('<dialog data-probe="authored">Unsupported copy</dialog>', $llm->calls[0]['prompt']);
    assert_contains('SUPPORTED SLICE', $llm->calls[0]['prompt']);
    $part = $project->readText('theme/parts/page-home--bad.html');
    assert_contains('Supported copy', $part);
    assert_contains('Sibling', $part);
    $report = $project->readJson(TransformArtifacts::REPORT);
    assert_true(in_array('html_unsupported_element', $report['fallback_codes'], true));
    assert_eq('repaired', $report['repair_outcomes'][0]['disposition']);
    assert_eq([], $report['dropped_fragments']);
    transform_site_cleanup($tmp);
});

test('transform-site matches identical unsupported HTML to its exact sibling fragment', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="first"><dialog>SAME</dialog><p>FIRST-SIBLING</p></section>'
        . '<section id="second"><dialog>SAME</dialog><p>SECOND-SIBLING</p></section>'
        . '</main><footer><p>Footer</p></footer></body></html>',
    );
    $llm->queueText('<div><p>FIRST-REPAIRED</p></div>');
    $llm->queueText('<div><p>SECOND-REPAIRED</p></div>');

    transform_site_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(2, count($llm->calls));
    $first = $project->readText('theme/parts/page-home--first.html');
    $second = $project->readText('theme/parts/page-home--second.html');
    assert_contains('FIRST-REPAIRED', $first);
    assert_contains('FIRST-SIBLING', $first);
    assert_true(!str_contains($first, 'SECOND-REPAIRED'));
    assert_contains('SECOND-REPAIRED', $second);
    assert_contains('SECOND-SIBLING', $second);
    assert_true(!str_contains($second, 'FIRST-REPAIRED'));
    assert_eq([], $project->readJson(TransformArtifacts::REPORT)['dropped_fragments']);
    transform_site_cleanup($tmp);
});

test('transform-site repair budget drops only exhausted fragment and reports actionable context', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="first"><dialog>First bad</dialog></section>'
        . '<section id="safe"><p>BYTE-STABLE-SAFE-SIBLING</p></section>'
        . '<section id="second"><dialog>Second bad</dialog></section>'
        . '</main><footer><p>Footer</p></footer></body></html>',
        [],
        1,
    );
    $llm->queueText('<div><p>First repaired</p></div>');

    transform_site_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls);
    assert_true($project->exists('theme/parts/page-home--first.html'));
    assert_true($project->exists('theme/parts/page-home--safe.html'));
    assert_true(!$project->exists('theme/parts/page-home--second.html'));
    assert_contains('BYTE-STABLE-SAFE-SIBLING', $project->readText('theme/parts/page-home--safe.html'));
    $report = $project->readJson(TransformArtifacts::REPORT);
    assert_eq(1, count($report['dropped_fragments']));
    $drop = $report['dropped_fragments'][0];
    assert_eq('design/home.html', $drop['source']);
    assert_contains('Second bad', $drop['authored_value']);
    assert_eq('removed', $drop['delivered_value']);
    assert_eq('dropped', $drop['disposition']);
    $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
    assert_contains('repair_budget', $warnings);
    assert_contains('authored_value', $warnings);
    assert_contains('delivered_value removed', $warnings);
    assert_contains('disposition dropped', $warnings);
    transform_site_cleanup($tmp);
});

test('transform-site drops an empty interior page explicitly while keeping the front page', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header>'
        . '<main><section id="home-content"><h1>Home</h1></section></main>'
        . '<footer><p>Footer</p></footer></body></html>',
        ['about' => '<main></main>'],
    );

    transform_site_run($project, $llm);

    assert_eq(['home'], array_column($project->readJson('pages.json')['pages'], 'slug'));
    $report = $project->readJson(TransformArtifacts::REPORT);
    assert_true(in_array('page_content_dropped', $report['fallback_codes'], true));
    assert_eq('design/about.html', $report['dropped_fragments'][0]['source']);
    assert_eq('removed', $report['dropped_fragments'][0]['delivered_value']);
    $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
    assert_contains('page_content_dropped', $warnings);
    assert_contains('disposition dropped', $warnings);
    transform_site_cleanup($tmp);
});

test('transform-site is byte-identical and same-tag sibling reorder cannot mis-target carrier CSS', function () {
    $prefix = '<!doctype html><html><body><header><p>Header</p></header><main><section id="order">';
    $suffix = '</section></main><footer><p>Footer</p></footer></body></html>';
    $home = $prefix
        . '<p style="width:111px">Alpha</p><p style="width:222px">Beta</p>'
        . $suffix;
    $reordered = $prefix
        . '<p style="width:222px">Beta</p><p style="width:111px">Alpha</p>'
        . $suffix;
    [$first, $firstLlm, $firstTmp] = transform_site_fixture($home);
    [$second, $secondLlm, $secondTmp] = transform_site_fixture($home);
    [$swapped, $swappedLlm, $swappedTmp] = transform_site_fixture($reordered);

    transform_site_run($first, $firstLlm);
    transform_site_run($second, $secondLlm);
    transform_site_run($swapped, $swappedLlm);

    assert_eq(transform_site_outputs($first), transform_site_outputs($second));
    foreach ([$first, $swapped] as $project) {
        $css = $project->readText(TransformArtifacts::CARRIED_CSS);
        $part = $project->readText('theme/parts/page-home--order.html');
        assert_true(!str_contains($css, 'nth-of-type'));
        foreach (['Alpha' => '111px', 'Beta' => '222px'] as $text => $width) {
            assert_true(
                preg_match(
                    '/class="[^"]*\\b(be-inline-geometry-[a-z0-9-]+)\\b[^"]*"[^>]*>' . $text . '<\\/p>/',
                    $part,
                    $match,
                ) === 1,
                "carrier class remains attached to {$text}",
            );
            assert_contains('.' . $match[1] . '{width:' . $width, $css);
        }
    }
    transform_site_cleanup($firstTmp);
    transform_site_cleanup($secondTmp);
    transform_site_cleanup($swappedTmp);
});

test('transform-site explicitly preserves or reports styled core html and preserves sibling', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="raw"><table style="width:333px"><tr><td rowspan="2">A</td></tr><tr></tr></table></section>'
        . '<section id="safe"><p>SAFE-CORE-PARAGRAPH</p></section>'
        . '</main><footer><p>Footer</p></footer></body></html>',
    );

    transform_site_run($project, $llm);

    assert_contains('SAFE-CORE-PARAGRAPH', $project->readText('theme/parts/page-home--safe.html'));
    $report = $project->readJson(TransformArtifacts::REPORT);
    if ($project->exists('theme/parts/page-home--raw.html')) {
        $raw = $project->readText('theme/parts/page-home--raw.html');
        assert_contains('wp:html', $raw);
        assert_contains('width:333px', $raw);
    } else {
        assert_eq(1, count($report['dropped_fragments']));
        assert_eq('dropped', $report['dropped_fragments'][0]['disposition']);
        $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
        assert_contains('delivered_value removed', $warnings);
    }
    transform_site_cleanup($tmp);
});
