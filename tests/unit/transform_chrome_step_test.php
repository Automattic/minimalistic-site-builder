<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\TransformChromeStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/**
 * transform-chrome: the chrome half of transform-site, extracted so the
 * --html-islands graph can build a real block header and footer while page
 * bodies ship as raw HTML islands.
 *
 * These tests are the ACCEPTANCE CONTRACT for the slice. Written before the
 * implementation exists and FROZEN: the builder makes them pass and does not
 * edit them.
 *
 * WHAT THIS STEP OWNS, and why each piece is not optional:
 *
 *   theme/parts/header.html   header-hero reads it and is the sole producer of
 *                             headerBehavior.json, which assemble-pages and
 *                             finalize-theme both consume. The adaptive-header
 *                             kit keys off .site-header-shell--sticky-soft and
 *                             --overlay-to-solid, applied by this transform.
 *                             AboveFoldPartFacts::headerFacts() parses this
 *                             part as BLOCK markup — it must stay blocks.
 *   theme/parts/footer.html   templates/page.html references it; a missing
 *                             part renders as an empty template-part.
 *   carried CSS               page-styles merges both carried-CSS files when
 *                             they exist; the chrome blocks depend on the CSS
 *                             the transform carried for them.
 *
 * WHAT IT MUST NOT OWN: page bodies, pages.json, aboveFold.json,
 * transform-report.json. Those either belong to island-pages or disappear with
 * the html-first graph.
 *
 * The page-level landmark rules come from DesignDocument (the shared helper
 * built in the previous slice) rather than being re-derived here. The tests
 * below exercise those rules THROUGH this step deliberately: a <footer> used
 * as a blockquote attribution is idiomatic authored HTML and has twice shipped
 * a defect in this repo by being mistaken for the site footer.
 */

/** @return array{0:Project,1:FakeLlm,2:string} */
function tc_fixture(string $home, array $inner = []): array
{
    $tmp = sys_get_temp_dir() . '/builder_transform_chrome_' . getmypid() . '_' . uniqid('', true);
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'Demo site', 'graph' => 'html-islands']);
    $pages = [['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome']];
    foreach (array_keys($inner) as $slug) {
        $pages[] = ['slug' => $slug, 'title' => ucfirst((string) $slug), 'purpose' => 'Explain'];
    }
    $project->writeJson('siteSpec.json', [
        'name' => 'Northstar Studio',
        'language' => 'English',
        'pages' => $pages,
    ]);
    $map = ['home' => 'home'];
    foreach (array_keys($inner) as $slug) {
        $map[$slug] = (string) $slug;
    }
    $project->writeJson('design/page-artifact-map.json', $map);
    $project->writeJson('designDirection.json', test_design_direction('cinematic-safe-zone', [
        'description' => 'Crisp editorial system',
    ]));
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => []]);
    $project->writeJson('images.json', []);
    $project->writeText('design/site.css', ".shared{color:#123}\n");
    $project->writeText('design/home.html', $home);
    foreach ($inner as $slug => $html) {
        $project->writeText("design/{$slug}.html", $html);
    }
    return [$project, new FakeLlm(), $tmp];
}

function tc_run(Project $project, FakeLlm $llm): void
{
    (new TransformChromeStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
}

function tc_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

function tc_warnings(Project $project): string
{
    return implode("\n", $project->readJson('warnings.json')['transform-chrome'] ?? []);
}

function tc_page(string $chrome, string $main = '<main><section id="hero"><h1>Hi</h1></section></main>'): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>t</title>'
        . '<style>body{margin:0}</style></head><body>' . $chrome . $main . '</body></html>';
}

// ---------------------------------------------------------------------------
// 1. the happy path
// ---------------------------------------------------------------------------

test('transform-chrome: transforms the authored header and footer into block parts', function () {
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '<header><p>NORTHSTAR-HEADER</p></header>',
        '<main><section id="hero"><h1>Hi</h1></section></main><footer><p>NORTHSTAR-FOOTER</p></footer>',
    ));

    tc_run($project, $llm);

    assert_true($project->exists('theme/parts/header.html'), 'the header part is mandatory');
    assert_true($project->exists('theme/parts/footer.html'), 'the footer part is mandatory');
    assert_contains('NORTHSTAR-HEADER', $project->readText('theme/parts/header.html'), '');
    assert_contains('NORTHSTAR-FOOTER', $project->readText('theme/parts/footer.html'), '');
    assert_eq(0, $llm->completeBatchCalls, 'authored chrome that transforms needs no model call');
    tc_cleanup($tmp);
});

test('transform-chrome: the delivered parts are block markup, not raw HTML', function () {
    // AboveFoldPartFacts::headerFacts() and ContrastFixStep both parse
    // theme/parts/header.html with BlockMarkup. Shipping an island here would
    // make every header fact silently empty.
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '<header><p>Header copy</p></header>',
        '<main><section id="hero"><h1>Hi</h1></section></main><footer><p>Footer copy</p></footer>',
    ));

    tc_run($project, $llm);

    foreach (['header', 'footer'] as $area) {
        $markup = $project->readText("theme/parts/{$area}.html");
        assert_contains('<!-- wp:', $markup, "{$area} must be block markup");
        assert_true(!str_contains($markup, '<!-- wp:html'), "{$area} must not be a raw-HTML island");
    }
    tc_cleanup($tmp);
});

test('transform-chrome: writes both carried-CSS artifacts for the style merge', function () {
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '<header><p>Header copy</p></header>',
        '<main><section id="hero"><h1>Hi</h1></section></main><footer><p>Footer copy</p></footer>',
    ));

    tc_run($project, $llm);

    assert_true($project->exists(TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR), 'page-styles merges this when present');
    assert_true($project->exists(TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR), '');
    tc_cleanup($tmp);
});

// ---------------------------------------------------------------------------
// 2. scope — what this step must NOT do
// ---------------------------------------------------------------------------

test('transform-chrome: writes no page parts and no page manifest', function () {
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '<header><p>Header copy</p></header>',
        '<main><section id="hero"><h1>Hi</h1></section><section id="cta"><h2>Go</h2></section></main>'
        . '<footer><p>Footer copy</p></footer>',
    ));

    tc_run($project, $llm);

    $parts = array_map('basename', glob($project->themePath('parts/*.html')) ?: []);
    sort($parts);
    assert_eq(['footer.html', 'header.html'], $parts, 'chrome only — page bodies belong to island-pages');
    assert_true(!$project->exists('pages.json'), 'the page manifest belongs to island-pages');
    assert_true(!$project->exists('aboveFold.json'), 'the above-fold contract is derived in its own slice');
    assert_true(!$project->exists(TransformArtifacts::REPORT), 'transform-report.json dies with the html-first graph');
    tc_cleanup($tmp);
});

test('transform-chrome: does not read inner-page design artifacts', function () {
    // The chrome is authored once, on the front page. Reading inner pages
    // would make a broken inner artifact able to damage the site shell.
    [$project, $llm, $tmp] = tc_fixture(
        tc_page(
            '<header><p>REAL-HEADER</p></header>',
            '<main><section id="hero"><h1>Hi</h1></section></main><footer><p>REAL-FOOTER</p></footer>',
        ),
        ['about' => '<main><section id="a"><h2>A</h2></section></main><footer><p>WRONG-FOOTER</p></footer>'],
    );

    tc_run($project, $llm);

    assert_contains('REAL-FOOTER', $project->readText('theme/parts/footer.html'), '');
    assert_true(
        !str_contains($project->readText('theme/parts/footer.html'), 'WRONG-FOOTER'),
        'an inner page must never supply the site footer',
    );
    $reads = (new TransformChromeStep($llm, new PromptRenderer(repo_path('prompts'))))->declaration()->reads;
    assert_true(in_array('design/home.html', $reads, true), 'the declaration must name the front artifact it depends on');
    tc_cleanup($tmp);
});

// ---------------------------------------------------------------------------
// 3. page-level landmark rules, exercised through the step
// ---------------------------------------------------------------------------

test('transform-chrome: a <footer> inside <main> is not the site footer', function () {
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '<header><p>Header copy</p></header>',
        '<main><section id="hero"><h1>Hi</h1><footer><p>SECTION-BYLINE</p></footer></section></main>',
    ));
    $llm->queueText(
        '<!-- wp:group {"tagName":"footer"} --><footer class="wp-block-group">'
        . '<!-- wp:paragraph --><p>GENERATED-FOOTER</p><!-- /wp:paragraph -->'
        . '</footer><!-- /wp:group -->',
    );

    tc_run($project, $llm);

    $footer = $project->readText('theme/parts/footer.html');
    assert_true(!str_contains($footer, 'SECTION-BYLINE'), 'a byline inside main is page content, not site chrome');
    assert_contains('GENERATED-FOOTER', $footer, 'the absent landmark is generated instead');
    assert_contains('missing_shell_landmark', tc_warnings($project), '');
    tc_cleanup($tmp);
});

test('transform-chrome: a <footer> used as a blockquote attribution is not the site footer', function () {
    // The canonical MDN pattern. It has shipped two defects in this repo by
    // being counted as a page-level footer.
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '<header><p>Header copy</p></header>',
        '<main><section id="hero">'
        . '<blockquote><p>Quoted</p><footer>— ATTRIBUTION-ONLY</footer></blockquote>'
        . '</section></main>'
        . '<footer><p>REAL-SITE-FOOTER</p></footer>',
    ));

    tc_run($project, $llm);

    $footer = $project->readText('theme/parts/footer.html');
    assert_contains('REAL-SITE-FOOTER', $footer, 'the page-level footer wins');
    assert_true(!str_contains($footer, 'ATTRIBUTION-ONLY'), '');
    assert_eq(0, $llm->completeBatchCalls, 'the real footer was found, so nothing needed generating');
    tc_cleanup($tmp);
});

test('transform-chrome: a <header> nested in a wrapper div is not the site header', function () {
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '<div class="shell"><header><p>WRAPPED-HEADER</p></header></div>',
        '<main><section id="hero"><h1>Hi</h1></section></main><footer><p>Footer copy</p></footer>',
    ));
    $llm->queueText(
        '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
        . '<!-- wp:paragraph --><p>GENERATED-HEADER</p><!-- /wp:paragraph -->'
        . '</header><!-- /wp:group -->',
    );

    tc_run($project, $llm);

    assert_contains('GENERATED-HEADER', $project->readText('theme/parts/header.html'), '');
    assert_contains('missing_shell_landmark', tc_warnings($project), '');
    tc_cleanup($tmp);
});

// ---------------------------------------------------------------------------
// 4. the degrade ladder
// ---------------------------------------------------------------------------

test('transform-chrome: a missing landmark is generated through the blocks unit', function () {
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '<header><p>Header copy</p></header>',
        '<main><section id="hero"><h1>Hi</h1></section></main>', // no footer authored
    ));
    $llm->queueText(
        '<!-- wp:group {"tagName":"footer"} --><footer class="wp-block-group">'
        . '<!-- wp:paragraph --><p>STUB-FOOTER-MARKER</p><!-- /wp:paragraph -->'
        . '</footer><!-- /wp:group -->',
    );

    tc_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls, 'one batch covers every missing area');
    assert_contains('Build the site FOOTER template part', $llm->calls[0]['prompt'], 'the footer unit prompt is used');
    assert_contains('STUB-FOOTER-MARKER', $project->readText('theme/parts/footer.html'), '');
    $warnings = tc_warnings($project);
    assert_contains('missing_shell_landmark', $warnings, '');
    assert_contains('design/home.html', $warnings, 'the warning names the source it read');
    assert_contains('footer', $warnings, '');
    tc_cleanup($tmp);
});

test('transform-chrome: both landmarks missing are generated in ONE batch', function () {
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '',
        '<main><section id="hero"><h1>Hi</h1></section></main>',
    ));
    $llm->queueText('<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
        . '<!-- wp:paragraph --><p>H</p><!-- /wp:paragraph --></header><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group {"tagName":"footer"} --><footer class="wp-block-group">'
        . '<!-- wp:paragraph --><p>F</p><!-- /wp:paragraph --></footer><!-- /wp:group -->');

    tc_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls, 'two missing areas still cost one round trip');
    assert_true($project->exists('theme/parts/header.html'), '');
    assert_true($project->exists('theme/parts/footer.html'), '');
    tc_cleanup($tmp);
});

test('transform-chrome: an LLM failure still delivers a deterministic minimal shell', function () {
    // AGENTS.md degrade ladder: the templates reference these parts, so
    // "no part" is not an option. A minimal shell is.
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '',
        '<main><section id="hero"><h1>Hi</h1></section></main>',
    ));
    $llm->failPromptSubstrings[] = 'template part';

    tc_run($project, $llm);

    foreach (['header', 'footer'] as $area) {
        $markup = $project->readText("theme/parts/{$area}.html");
        assert_contains('<!-- wp:', $markup, "{$area} still delivers usable block markup");
    }
    assert_contains('shell_generation_failed', tc_warnings($project), 'the degrade is visible, not silent');
    tc_cleanup($tmp);
});

test('transform-chrome: unusable model output falls back to the minimal shell', function () {
    [$project, $llm, $tmp] = tc_fixture(tc_page(
        '',
        '<main><section id="hero"><h1>Hi</h1></section></main>',
    ));
    $llm->queueText('I cannot help with that request.');
    $llm->queueText('Nor with this one.');

    tc_run($project, $llm);

    foreach (['header', 'footer'] as $area) {
        assert_contains('<!-- wp:', $project->readText("theme/parts/{$area}.html"), '');
    }
    assert_contains('deterministic minimal shell delivered', tc_warnings($project), '');
    tc_cleanup($tmp);
});

test('transform-chrome: a structurally broken front artifact still delivers chrome', function () {
    // The front page is fatal in island-pages because the site needs a home
    // page. Chrome is different: a generated shell is a usable site.
    [$project, $llm, $tmp] = tc_fixture("<header><p>Broken<main><section id=\"a\"><h2>A</h2>\n");
    $llm->queueText('<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
        . '<!-- wp:paragraph --><p>H</p><!-- /wp:paragraph --></header><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group {"tagName":"footer"} --><footer class="wp-block-group">'
        . '<!-- wp:paragraph --><p>F</p><!-- /wp:paragraph --></footer><!-- /wp:group -->');

    tc_run($project, $llm);

    assert_true($project->exists('theme/parts/header.html'), 'chrome degrades rather than aborting the build');
    assert_true($project->exists('theme/parts/footer.html'), '');
    assert_true(tc_warnings($project) !== '', 'the degrade is reported');
    tc_cleanup($tmp);
});

// ---------------------------------------------------------------------------
// 5. declaration
// ---------------------------------------------------------------------------

test('transform-chrome: declares the artifacts it reads and writes', function () {
    $step = new TransformChromeStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $declaration = $step->declaration();
    assert_eq('transform-chrome', $declaration->id, '');
    assert_eq('transform-chrome', $step->id(), 'id() and the declaration must agree');
    foreach (['theme/parts/header.html', 'theme/parts/footer.html', 'warnings.json'] as $artifact) {
        assert_true(in_array($artifact, $declaration->writes, true), "declaration must publish {$artifact}");
    }
    foreach (['pages.json', 'aboveFold.json', TransformArtifacts::REPORT] as $artifact) {
        assert_true(!in_array($artifact, $declaration->writes, true), "{$artifact} is out of this step's scope");
    }
});
