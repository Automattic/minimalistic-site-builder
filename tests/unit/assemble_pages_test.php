<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\PageStylesStep;

/**
 * Unit tests for AssemblePagesStep: inlines every page's (already fixed)
 * section parts into the content plugin's page files, writes the plugin
 * manifest and the deterministic theme templates, registers the chrome
 * template parts, and removes the transient page-* parts from the theme.
 */

function assemble_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_asm_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'static',
        'mode' => 'stacked',
        'transition' => 'instant',
        'topSurface' => 'base',
        'scrolledSurface' => 'base',
        'foreground' => 'contrast',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
    ]);
    $project->writeJson('pages.json', ['pages' => [
        [
            'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
            'parent' => null, 'menu_order' => 0, 'purpose' => 'Welcome',
            'sections' => [
                ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
                ['slug' => 'about', 'title' => 'About', 'type' => 'about'],
            ],
        ],
        [
            'slug' => 'menu', 'title' => 'Menu', 'path' => '/menu/', 'front' => false,
            'parent' => null, 'menu_order' => 10, 'purpose' => 'What we bake',
            'sections' => [
                ['slug' => 'breads', 'title' => 'Breads', 'type' => 'features'],
            ],
        ],
    ]]);
    $project->writeText('theme/parts/header.html', '<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->' . "\n");
    $project->writeText('theme/parts/footer.html', '<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->' . "\n");
    $project->writeText('theme/parts/page-home--hero.html', '<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->' . "\n");
    $project->writeText('theme/parts/page-home--about.html', '<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->' . "\n");
    $project->writeText('theme/parts/page-menu--breads.html', '<!-- wp:heading --><h2>Breads</h2><!-- /wp:heading -->' . "\n");
    return [$project, $tmp];
}

test('assemble-pages inlines fixed parts into plugin pages in plan order', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssemblePagesStep())->run($project);

    $home = $project->readText('plugin/pages/home.html');
    assert_contains('<h2>Hero</h2>', $home);
    assert_contains('<h2>About</h2>', $home);
    assert_true(strpos($home, '<h2>Hero</h2>') < strpos($home, '<h2>About</h2>'), 'sections in plan order');
    assert_true(!str_contains($home, 'wp:template-part'), 'content is inline markup, not part references');

    assert_contains('<h2>Breads</h2>', $project->readText('plugin/pages/menu.html'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages writes the plugin manifest from the plan', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssemblePagesStep())->run($project);

    $manifest = $project->readJson('plugin/pages.json');
    assert_eq(2, count($manifest['pages']));
    assert_eq(
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0, 'parent' => null],
        $manifest['pages'][0]
    );
    assert_eq(
        ['slug' => 'menu', 'title' => 'Menu', 'front' => false, 'menu_order' => 10, 'parent' => null],
        $manifest['pages'][1]
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages removes the transient page parts and keeps the chrome', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssemblePagesStep())->run($project);

    assert_true(!$project->exists('theme/parts/page-home--hero.html'), 'transient part removed');
    assert_true(!$project->exists('theme/parts/page-home--about.html'), 'transient part removed');
    assert_true(!$project->exists('theme/parts/page-menu--breads.html'), 'transient part removed');
    assert_true($project->exists('theme/parts/header.html'), 'header kept');
    assert_true($project->exists('theme/parts/footer.html'), 'footer kept');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages writes the page and index templates and registers the chrome parts', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssemblePagesStep())->run($project);

    $page = $project->readText('theme/templates/page.html');
    assert_contains('wp:template-part {"slug":"header","tagName":"header"}', $page);
    // Bare post-content: sections carry their own layout, so the template must
    // not constrain them (that would break full-bleed bands).
    assert_contains('<!-- wp:post-content /-->', $page);
    assert_contains('wp:template-part {"slug":"footer","tagName":"footer"}', $page);

    $index = $project->readText('theme/templates/index.html');
    assert_contains('wp:post-content', $index);

    assert_true(!$project->exists('theme/templates/front-page.html'), 'no front-page template — the seeded homepage owns the front');

    $theme = $project->readJson('theme/theme.json');
    assert_eq([
        ['name' => 'header', 'title' => 'Header', 'area' => 'header'],
        ['name' => 'footer', 'title' => 'Footer', 'area' => 'footer'],
    ], $theme['templateParts']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages wires sticky-soft behavior onto the page and index header wrappers', function () {
    [$project, $tmp] = assemble_fixture();
    $behavior = $project->readJson('headerBehavior.json');
    $behavior['behavior'] = 'sticky-soft';
    $project->writeJson('headerBehavior.json', $behavior);

    (new AssemblePagesStep())->run($project);

    $className = 'site-header-shell site-header-shell--sticky-soft';
    assert_contains(
        'wp:template-part {"slug":"header","tagName":"header","className":"' . $className . '"}',
        $project->readText('theme/templates/page.html')
    );
    assert_contains(
        'wp:template-part {"slug":"header","tagName":"header","className":"' . $className . '"}',
        $project->readText('theme/templates/index.html')
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages keeps overlay on pages but forces the blog index to an opaque sticky header', function () {
    [$project, $tmp] = assemble_fixture();
    $behavior = $project->readJson('headerBehavior.json');
    $behavior['behavior'] = 'overlay-to-solid';
    $behavior['mode'] = 'overlay';
    $behavior['topSurface'] = 'transparent';
    $behavior['topTreatment'] = 'transparent';
    $project->writeJson('headerBehavior.json', $behavior);

    (new AssemblePagesStep())->run($project);

    assert_contains(
        '"className":"site-header-shell site-header-shell--overlay-to-solid"',
        $project->readText('theme/templates/page.html')
    );
    $index = $project->readText('theme/templates/index.html');
    assert_contains(
        '"className":"site-header-shell site-header-shell--sticky-soft site-header-shell--force-solid"',
        $index
    );
    assert_true(!str_contains($index, 'site-header-shell--overlay-to-solid'), 'index never starts transparent');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages preserves the original template-part markup for a static header', function () {
    assert_eq(
        '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n"
            . '<!-- wp:post-content /-->' . "\n"
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' . "\n",
        AssemblePagesStep::pageTemplate('static'),
        'static page template remains byte-for-byte unchanged'
    );
    assert_true(
        !str_contains(AssemblePagesStep::pageTemplate('static'), 'site-header-shell'),
        'static page template has no behavior classes'
    );
    assert_true(
        !str_contains(AssemblePagesStep::index('static'), 'site-header-shell'),
        'static index template has no behavior classes'
    );
});

test('assemble-pages degrades a missing header behavior to static and records actionable context', function () {
    [$project, $tmp] = assemble_fixture();
    unlink($project->path('headerBehavior.json'));

    (new AssemblePagesStep())->run($project);

    assert_true(
        !str_contains($project->readText('theme/templates/page.html'), 'site-header-shell'),
        'missing behavior delivered as static'
    );
    $joined = implode(' ', $project->readJson('warnings.json')['assemble-pages'] ?? []);
    assert_contains("file='headerBehavior.json'", $joined);
    assert_contains("block='behavior'", $joined);
    assert_contains('authored=<missing>', $joined);
    assert_contains("delivered='static'", $joined);
    assert_contains('disposition=', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages degrades malformed header behavior JSON to static and warns instead of aborting', function () {
    [$project, $tmp] = assemble_fixture();
    $project->writeText('headerBehavior.json', '{"behavior":');

    (new AssemblePagesStep())->run($project);

    assert_true(
        !str_contains($project->readText('theme/templates/page.html'), 'site-header-shell'),
        'malformed behavior delivered as static'
    );
    $joined = implode(' ', $project->readJson('warnings.json')['assemble-pages'] ?? []);
    assert_contains("file='headerBehavior.json'", $joined);
    assert_contains("block='behavior'", $joined);
    assert_contains('authored=<invalid JSON:', $joined);
    assert_contains("delivered='static'", $joined);
    assert_contains('disposition=malformed generated header behavior', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages validates the complete behavior tuple before adding adaptive wrapper classes', function () {
    [$project, $tmp] = assemble_fixture();
    $project->writeJson('headerBehavior.json', ['behavior' => 'overlay-to-solid']);

    (new AssemblePagesStep())->run($project);

    assert_true(
        !str_contains($project->readText('theme/templates/page.html'), 'site-header-shell'),
        'an incomplete overlay artifact degrades to the original static wrapper',
    );
    assert_true(!str_contains($project->readText('theme/templates/index.html'), 'site-header-shell'));
    $joined = implode(' ', $project->readJson('warnings.json')['assemble-pages'] ?? []);
    assert_contains("file='headerBehavior.json'", $joined);
    assert_contains('authored={"behavior":"overlay-to-solid"}', $joined);
    assert_contains("delivered='static'", $joined);
    assert_contains('must contain exactly', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages degrades a non-object header behavior artifact instead of aborting', function () {
    [$project, $tmp] = assemble_fixture();
    // Valid JSON, but not the closed object contract — a bare string must take
    // the same fail-open static path as malformed JSON, never a TypeError.
    $project->writeText('headerBehavior.json', '"static"');

    (new AssemblePagesStep())->run($project);

    assert_true(
        !str_contains($project->readText('theme/templates/page.html'), 'site-header-shell'),
        'non-object behavior delivered as static'
    );
    $joined = implode(' ', $project->readJson('warnings.json')['assemble-pages'] ?? []);
    assert_contains("file='headerBehavior.json'", $joined);
    assert_contains('authored="static"', $joined);
    assert_contains("delivered='static'", $joined);
    assert_contains('must be a JSON object', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

/**
 * Rewrite the fixture's header part to the overlay-prepared shape
 * HeaderHeroStep + fix-blocks leave behind: transparent root, light
 * palette foreground, behavior classes in attrs AND saved HTML.
 */
function assemble_overlay_prepared_header(\Automattic\SiteBuild\Project $project): void
{
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
        ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
    ]]]]);
    $classes = 'header-behavior-overlay-to-solid header-start-transparent '
        . 'header-scrolled-contrast header-foreground-base header-top-transparent site-brand-lockup';
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"className":"' . $classes . '","textColor":"base","layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group ' . $classes . ' has-base-color has-text-color">'
        . '<!-- wp:site-title /--></div>' . "\n"
        . '<!-- /wp:group -->' . "\n"
    );
}

test('assemble-pages solidifies an overlay-prepared header when the behavior artifact is corrupt', function () {
    [$project, $tmp] = assemble_fixture();
    assemble_overlay_prepared_header($project);
    $project->writeText('headerBehavior.json', '{"behavior":'); // corrupt

    (new AssemblePagesStep())->run($project);

    // Without the rewrite this ships light text with no background in normal
    // flow — an invisible header. The degrade path must deliver an opaque,
    // contrast-safe surface in attrs AND saved HTML.
    $header = $project->readText('theme/parts/header.html');
    assert_true(!str_contains($header, 'header-start-transparent'), 'transparent-start class removed');
    assert_true(!str_contains($header, 'header-behavior-overlay-to-solid'), 'behavior class removed');
    assert_true(!str_contains($header, 'header-top-transparent'), 'earned transparent treatment class removed');
    assert_eq(2, substr_count($header, 'site-brand-lockup'), 'unrelated classes survive in attrs and saved HTML');
    assert_contains('"backgroundColor":"base"', $header);
    assert_contains('"textColor":"contrast"', $header);
    assert_contains('has-base-background-color', $header);
    assert_contains('has-background', $header);
    assert_contains('has-contrast-color', $header);
    assert_true(!str_contains($header, 'has-base-color'), 'the light overlay foreground class is gone');

    $joined = implode(' ', $project->readJson('warnings.json')['assemble-pages'] ?? []);
    assert_contains("file='theme/parts/header.html'", $joined);
    assert_contains("block='overlay top state'", $joined);
    assert_contains("delivered=opaque 'base' surface with 'contrast' foreground", $joined);
    assert_contains('disposition=overlay-prepared header rewritten', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages leaves a non-overlay header part alone on the degrade path', function () {
    [$project, $tmp] = assemble_fixture();
    $before = $project->readText('theme/parts/header.html');
    unlink($project->path('headerBehavior.json'));

    (new AssemblePagesStep())->run($project);

    assert_eq($before, $project->readText('theme/parts/header.html'), 'ordinary header untouched');
    $joined = implode(' ', $project->readJson('warnings.json')['assemble-pages'] ?? []);
    assert_true(!str_contains($joined, "block='overlay top state'"), 'no rewrite row for an ordinary header');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages writes the plugin image manifest for content-referenced assets', function () {
    [$project, $tmp] = assemble_fixture();
    // The hero part references two assets; one has a collected spec (subject
    // becomes the media title), the other doesn't (title falls back to the
    // filename). Chrome-only assets must NOT land in the manifest.
    $project->writeText(
        'theme/parts/page-home--hero.html',
        '<!-- wp:cover {"url":"theme:./assets/hero-loaves.jpg"} --><div>'
        . '<img src="theme:./assets/crumb-detail.jpg" alt="AI_IMAGE: crumb | hero | photo | landscape">'
        . '</div><!-- /wp:cover -->' . "\n"
    );
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group --><img src="theme:./assets/wordmark.png" alt="AI_IMAGE: wordmark | header | flat | square"><!-- /wp:group -->' . "\n"
    );
    $project->writeJson('images.json', [
        ['filename' => 'hero-loaves.jpg', 'src' => 'theme:./assets/hero-loaves.jpg', 'subject' => 'Golden sourdough loaves on a rack'],
        ['filename' => 'wordmark.png', 'src' => 'theme:./assets/wordmark.png', 'subject' => 'Bakery wordmark'],
    ]);

    (new AssemblePagesStep())->run($project);

    $manifest = $project->readJson('plugin/images.json');
    assert_eq([
        ['filename' => 'hero-loaves.jpg', 'title' => 'Golden sourdough loaves on a rack'],
        ['filename' => 'crumb-detail.jpg', 'title' => 'Crumb Detail'],
    ], $manifest['images']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages unions a site-logo role into the plugin image manifest', function () {
    [$project, $tmp] = assemble_fixture();
    $project->writeText(
        'theme/parts/page-home--hero.html',
        '<!-- wp:cover {"url":"theme:./assets/hero-loaves.jpg"} --><div></div><!-- /wp:cover -->' . "\n"
    );
    $project->writeJson('images.json', [
        ['filename' => 'hero-loaves.jpg', 'src' => 'theme:./assets/hero-loaves.jpg', 'subject' => 'Golden sourdough loaves on a rack'],
        [
            'filename' => 'site-logo.png',
            'src' => 'theme:./assets/site-logo.png',
            'subject' => 'simple geometric brand mark for bakery, no letters',
            'role' => 'site-logo',
        ],
        ['filename' => 'wordmark.png', 'src' => 'theme:./assets/wordmark.png', 'subject' => 'Bakery wordmark'],
    ]);

    (new AssemblePagesStep())->run($project);

    assert_eq([
        ['filename' => 'hero-loaves.jpg', 'title' => 'Golden sourdough loaves on a rack'],
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
    ], $project->readJson('plugin/images.json')['images']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages writes an empty image manifest when pages reference no assets', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssemblePagesStep())->run($project);

    assert_eq(['images' => []], $project->readJson('plugin/images.json'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages skips a missing section part and warns instead of failing', function () {
    [$project, $tmp] = assemble_fixture();
    unlink($project->themePath('parts/page-home--about.html'));

    (new AssemblePagesStep())->run($project);

    // The page ships without the lost section; every sibling survives.
    $home = $project->readText('plugin/pages/home.html');
    assert_contains('<h2>Hero</h2>', $home);
    assert_true(!str_contains($home, '<h2>About</h2>'), 'missing section skipped');
    assert_contains('<h2>Breads</h2>', $project->readText('plugin/pages/menu.html'));

    $warnings = $project->readJson('warnings.json')['assemble-pages'] ?? [];
    assert_contains('missing generated part parts/page-home--about.html', implode(' ', $warnings));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages skips an interior page whose every section part is missing', function () {
    [$project, $tmp] = assemble_fixture();
    unlink($project->themePath('parts/page-menu--breads.html'));

    (new AssemblePagesStep())->run($project);

    // The interior page is dropped whole: no page file, no manifest entry.
    assert_true(!$project->exists('plugin/pages/menu.html'), 'empty interior page skipped');
    $manifest = $project->readJson('plugin/pages.json');
    assert_eq(['home'], array_column($manifest['pages'], 'slug'));

    $joined = implode(' ', $project->readJson('warnings.json')['assemble-pages'] ?? []);
    assert_contains("page 'menu': no section markup survived; page skipped", $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages keeps the front page when every one of its section parts is missing', function () {
    [$project, $tmp] = assemble_fixture();
    unlink($project->themePath('parts/page-home--hero.html'));
    unlink($project->themePath('parts/page-home--about.html'));

    (new AssemblePagesStep())->run($project);

    // The front page ships empty — templates and the seeder rely on its
    // existence — while the interior page is untouched.
    assert_eq('', trim($project->readText('plugin/pages/home.html')), 'front page shipped empty');
    $manifest = $project->readJson('plugin/pages.json');
    assert_eq(['home', 'menu'], array_column($manifest['pages'], 'slug'));

    $joined = implode(' ', $project->readJson('warnings.json')['assemble-pages'] ?? []);
    assert_contains("front page 'home': no section markup survived; empty front page delivered", $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages warns and continues when the chrome parts are missing', function () {
    [$project, $tmp] = assemble_fixture();
    unlink($project->themePath('parts/header.html'));

    (new AssemblePagesStep())->run($project);

    // The build still ships — the template references an absent part, which
    // WordPress renders as empty chrome — with a durable warning.
    assert_contains('<h2>Hero</h2>', $project->readText('plugin/pages/home.html'));
    $joined = implode(' ', $project->readJson('warnings.json')['assemble-pages'] ?? []);
    assert_contains('missing parts/header.html', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages throws when pages.json is empty', function () {
    [$project, $tmp] = assemble_fixture();
    $project->writeJson('pages.json', ['pages' => []]);

    assert_throws(function () use ($project) {
        (new AssemblePagesStep())->run($project);
    }, 'no pages');
    exec('rm -rf ' . escapeshellarg($tmp));
});

/**
 * Two nested <header> elements — the template part's own wrapper and the
 * authored landmark the transformed part roots — both match the design's
 * `header{…}` rule, which the theme stylesheet carries verbatim, so its box
 * model is applied twice.
 */
test('assemble-pages neutralizes a chrome shell that wraps a part rooting the same landmark', function () {
    [$project, $tmp] = assemble_fixture();
    // The transformer keeps the authored landmark whenever the design's own
    // header rule maps onto block attributes, so the part roots it here. The
    // fixture's footer part roots none, which keeps this test honest in both
    // directions.
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"tagName":"header","style":{"spacing":{"padding":{"top":"40px"}}}} -->'
        . '<header class="wp-block-group" style="padding-top:40px"><!-- wp:site-title /--></header>'
        . '<!-- /wp:group -->' . "\n",
    );

    (new AssemblePagesStep())->run($project);

    foreach (['page', 'index'] as $template) {
        $markup = $project->readText("theme/templates/{$template}.html");
        assert_contains('"slug":"header","tagName":"header","className":"chrome-nested-landmark"', $markup);
        assert_contains(PageStylesStep::NESTED_LANDMARK_CLASS, $markup);
        // Pin the footer reference before asserting it carries no marker:
        // absence alone would also pass if the reference vanished entirely.
        assert_contains('"slug":"footer","tagName":"footer"', $markup);
        assert_true(
            !str_contains($markup, '"slug":"footer","tagName":"footer","className"'),
            "{$template}.html must not neutralize a footer shell whose part roots no landmark: {$markup}",
        );
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});
