<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;

/** Seed the closed static header contract for tests not concerned with it. */
function finalize_static_header(\Automattic\SiteBuild\Project $project): void
{
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
}

test('finalize-theme writes the deterministic functions.php loader', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeText('theme/style.css', "/*\nTheme Name: Forno Vero\n*/\n");
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $php = $project->readText('theme/functions.php');
    // Block themes don't load style.css automatically; the enqueue is what
    // makes the utility CSS (equal-cards, layout utilities) actually apply.
    assert_contains("wp_enqueue_style('forno-vero-style', get_stylesheet_uri()", $php);
    assert_contains("add_editor_style('style.css')", $php);
    // The generated fonts module is loaded guardedly, so a fontless theme stays valid.
    assert_contains("require_once __DIR__ . '/fonts.php'", $php);
    assert_contains('is_readable', $php);
    assert_contains("register_block_pattern_category('forno-vero-sections'", $php);
    assert_contains("register_block_pattern_category('forno-vero-components'", $php);
    assert_contains("'label' => 'Forno Vero sections'", $php);
    assert_contains("'label' => 'Forno Vero components'", $php);
    // No model output belongs here — no font URLs, ever.
    assert_true(!str_contains($php, 'googleapis'), 'fonts stay in fonts.php');
    // PHP must be syntactically valid.
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme keeps a hostile pattern category label inert', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_category_' . uniqid();
    $project = (new ProjectStore($tmp))->create('hostile');
    $project->writeText('theme/style.css', "/*\nTheme Name: O'Brien); phpinfo(); //\n*/\n");
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $php = $project->readText('theme/functions.php');
    assert_contains("'label' => 'O\\'Brien); phpinfo(); // sections'", $php);
    assert_contains("'label' => 'O\\'Brien); phpinfo(); // components'", $php);
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme enqueues the motion kit and prunes to the committed profile', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    (new ScaffoldThemeStep())->run($project);
    $project->writeJson('designDirection.json', ['description' => 'x', 'motion' => 'dramatic']);
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $php = $project->readText('theme/functions.php');
    assert_contains("wp_enqueue_style('forno-vero-motion', get_theme_file_uri('assets/motion/motion.css')", $php);
    assert_contains("assets/motion/profiles/dramatic.css", $php);
    // style.css loads after the complete static kit/profile dependency chain.
    assert_contains("wp_enqueue_style('forno-vero-style', get_stylesheet_uri(), array('forno-vero-motion-profile')", $php);
    // The script rides in <head> (last arg false) so motion-js exists pre-paint.
    assert_contains("wp_enqueue_script('forno-vero-motion', get_theme_file_uri('assets/motion/motion.js'), array(), \$ver, false)", $php);

    // Unused profiles are pruned; the committed one ships.
    assert_true($project->exists('theme/assets/motion/profiles/dramatic.css'));
    foreach (['calm', 'energetic', 'minimal'] as $unused) {
        assert_true(!$project->exists("theme/assets/motion/profiles/{$unused}.css"), "{$unused} pruned");
    }

    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme with motion none ships no kit and no motion enqueues', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    (new ScaffoldThemeStep())->run($project);
    $project->writeJson('designDirection.json', ['description' => 'x', 'motion' => 'none']);
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $php = $project->readText('theme/functions.php');
    assert_true(!str_contains($php, 'motion'), 'no motion wiring in functions.php');
    assert_true(!is_dir($project->themePath('assets/motion')), 'kit removed from the theme');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme without a design direction behaves as motion none', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    (new ScaffoldThemeStep())->run($project);
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    assert_true(!str_contains($project->readText('theme/functions.php'), 'motion'));
    assert_true(!is_dir($project->themePath('assets/motion')));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme enqueues adaptive headers independently of motion', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    (new ScaffoldThemeStep())->run($project);
    $project->writeJson('designDirection.json', ['description' => 'x', 'motion' => 'none']);
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'sticky-soft',
        'mode' => 'stacked',
        'transition' => 'smooth',
        'topSurface' => 'base',
        'scrolledSurface' => 'primary',
        'foreground' => 'contrast',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
    ]);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $php = $project->readText('theme/functions.php');
    assert_contains(
        "wp_enqueue_style('forno-vero-header', get_theme_file_uri('assets/header/header.css'), array('forno-vero-style')",
        $php
    );
    assert_contains(
        "wp_enqueue_script('forno-vero-header', get_theme_file_uri('assets/header/header.js'), array(), \$ver, false)",
        $php,
        'header state driver is loaded in head'
    );
    assert_true(!str_contains($php, "get_theme_file_uri('assets/motion/"), 'motion kit remains independent and absent');
    assert_true($project->exists('theme/assets/header/header.css'));
    assert_true($project->exists('theme/assets/header/header.js'));
    assert_true(!is_dir($project->themePath('assets/motion')), 'motion kit was still pruned');

    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme prunes the header kit for a static behavior', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    (new ScaffoldThemeStep())->run($project);
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    assert_true(!is_dir($project->themePath('assets/header')), 'static behavior ships no dead header assets');
    assert_true(!str_contains($project->readText('theme/functions.php'), 'assets/header/'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme downgrades malformed, non-object, or incomplete header behavior with a warning', function () {
    // '"static"' is valid JSON that is not the closed object contract: it must
    // take the same fail-open static path as malformed JSON, never a TypeError.
    foreach (['{"behavior":', '"static"', '{"behavior":"sticky-soft"}'] as $artifact) {
        $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
        $project = (new ProjectStore($tmp))->create('demo');
        (new ScaffoldThemeStep())->run($project);
        $project->writeText('headerBehavior.json', $artifact);

        quietly(fn () => (new FinalizeThemeStep())->run($project));

        assert_true(!is_dir($project->themePath('assets/header')), 'invalid behavior was delivered as static');
        assert_true(!str_contains($project->readText('theme/functions.php'), 'assets/header/'));
        $warning = implode(' ', $project->readJson('warnings.json')['finalize-theme'] ?? []);
        assert_contains("file='headerBehavior.json'", $warning);
        assert_contains("block='behavior'", $warning);
        assert_contains('authored=', $warning);
        assert_contains("delivered='static'", $warning);
        assert_contains('downgraded', $warning);

        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('finalize-theme warns when header behavior artifact is missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    (new ScaffoldThemeStep())->run($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    assert_true(!is_dir($project->themePath('assets/header')));
    $warning = implode(' ', $project->readJson('warnings.json')['finalize-theme'] ?? []);
    assert_contains("file='headerBehavior.json'", $warning);
    assert_contains('authored=<missing>', $warning);
    assert_contains("delivered='static'", $warning);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme solidifies an overlay-prepared header when the behavior artifact is corrupt', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    (new ScaffoldThemeStep())->run($project);
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
        ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
    ]]]]);
    $classes = 'header-behavior-overlay-to-solid header-start-transparent '
        . 'header-scrolled-contrast header-foreground-base';
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"className":"' . $classes . '","textColor":"base","layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group ' . $classes . ' has-base-color has-text-color">'
        . '<!-- wp:site-title /--></div>' . "\n"
        . '<!-- /wp:group -->' . "\n"
    );
    $project->writeText('headerBehavior.json', '"static"');

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    // The kit is pruned for static — the markup must not still expect it:
    // light text on a transparent in-flow root would be an invisible header.
    assert_true(!is_dir($project->themePath('assets/header')), 'kit pruned for the degraded static behavior');
    $header = $project->readText('theme/parts/header.html');
    assert_true(!str_contains($header, 'header-start-transparent'), 'transparent-start class removed');
    assert_contains('"backgroundColor":"base"', $header);
    assert_contains('"textColor":"contrast"', $header);
    assert_contains('has-base-background-color', $header);
    assert_contains('has-contrast-color', $header);
    assert_true(!str_contains($header, 'has-base-color'), 'the light overlay foreground class is gone');

    $warning = implode(' ', $project->readJson('warnings.json')['finalize-theme'] ?? []);
    assert_contains("file='theme/parts/header.html'", $warning);
    assert_contains("block='overlay top state'", $warning);
    assert_contains("delivered=opaque 'base' surface with 'contrast' foreground", $warning);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme treats a missing trusted asset as fatal for adaptive behavior', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    (new ScaffoldThemeStep())->run($project);
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'overlay-to-solid',
        'mode' => 'overlay',
        'transition' => 'smooth',
        'topSurface' => 'transparent',
        'scrolledSurface' => 'contrast',
        'foreground' => 'base',
        'topTreatment' => 'transparent',
        'scrolledTreatment' => 'solid',
    ]);
    unlink($project->themePath('assets/header/header.js'));

    $error = assert_throws(fn () => quietly(fn () => (new FinalizeThemeStep())->run($project)));
    assert_contains('Missing or unreadable trusted header asset', $error->getMessage());
    assert_true(!$project->exists('theme/functions.php'), 'fatal contract failure does not write partial wiring');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme ships and enqueues the shape kit for a rounded commitment', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'shape' => 'soft']);
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $css = $project->readText('theme/assets/shape/shape.css');
    assert_contains('border-radius: 0.5rem', $css);
    assert_contains('.wp-block-cover:not(.alignfull)', $css);
    $php = $project->readText('theme/functions.php');
    assert_contains(
        "wp_enqueue_style('forno-vero-shape', get_theme_file_uri('assets/shape/shape.css'), array('forno-vero-style')",
        $php,
    );
    assert_contains("add_editor_style(array('style.css', 'assets/shape/shape.css'))", $php);
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme omits a stale overlay kit from the loader when it cannot be pruned', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'shape' => 'sharp']);
    $project->writeText('theme/assets/shape/shape.css', '.wp-block-cover { border-radius: 1.25rem; }');
    $project->writeText('theme/functions.php', "wp_enqueue_style('forno-vero-shape', 'stale');\n");
    finalize_static_header($project);

    $dir = $project->themePath('assets/shape');
    try {
        if (!chmod($dir, 0555) || is_writable($dir)) {
            skip_test('cannot make the kit directory read-only on this platform');
        }
        quietly(fn () => (new FinalizeThemeStep())->run($project));
        assert_true(is_file($dir . '/shape.css'), 'leftover bytes remain on disk');
        $php = $project->readText('theme/functions.php');
        assert_true(!str_contains($php, 'forno-vero-shape'), 'the rewritten loader does not enqueue the leftover');
        $warning = implode(' ', $project->readJson('warnings.json')['finalize-theme'] ?? []);
        assert_contains('leftover bytes could not be deleted', $warning);
    } finally {
        @chmod($dir, 0755);
        remove_tree($tmp);
    }
});

test('finalize-theme ships no shape kit for sharp and prunes a stale one', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'shape' => 'sharp']);
    // A kit left by an earlier rounded finalize run must not survive sharp.
    $project->writeText('theme/assets/shape/shape.css', '.wp-block-cover { border-radius: 1.25rem; }');
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    assert_true(!$project->exists('theme/assets/shape/shape.css'), 'stale kit pruned');
    $php = $project->readText('theme/functions.php');
    assert_true(!str_contains($php, 'forno-vero-shape'), 'no shape enqueue for sharp');
    assert_contains("add_editor_style('style.css')", $php);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme ships the committed depth kit, including deliberate flat', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    finalize_static_header($project);

    $project->writeJson('designDirection.json', ['description' => 'x', 'depth' => 'hard-offset']);
    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $css = $project->readText('theme/assets/depth/depth.css');
    assert_contains("Committed 'hard-offset' depth", $css);
    assert_contains('0.55rem 0.55rem 0', $css);
    assert_contains('var(--wp--preset--shadow--depth', $css);
    $php = $project->readText('theme/functions.php');
    assert_contains(
        "wp_enqueue_style('forno-vero-depth', get_theme_file_uri('assets/depth/depth.css'), array('forno-vero-style')",
        $php,
    );
    assert_contains("add_editor_style(array('style.css', 'assets/depth/depth.css'))", $php);

    $project->writeJson('designDirection.json', ['description' => 'x', 'depth' => 'flat']);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    $flat = $project->readText('theme/assets/depth/depth.css');
    assert_contains("Committed 'flat' depth", $flat);
    assert_contains('var(--wp--preset--shadow--depth, none)', $flat);
    assert_contains('assets/depth/depth.css', $project->readText('theme/functions.php'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme does not invent depth for a pre-field direction and prunes stale bytes', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x']);
    $project->writeText('theme/assets/depth/depth.css', 'stale');
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    assert_true(!$project->exists('theme/assets/depth/depth.css'));
    assert_true(!str_contains($project->readText('theme/functions.php'), 'assets/depth/'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme ships and enqueues the surface overlay', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'surface' => 'paper']);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#EFE8DA'],
    ]]]]);
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $css = $project->readText('theme/assets/surface/surface.css');
    assert_contains('position: fixed', $css);
    assert_contains('mix-blend-mode: soft-light', $css);
    $php = $project->readText('theme/functions.php');
    assert_contains('assets/surface/surface.css', $php);

    $project->writeJson('designDirection.json', ['description' => 'x', 'surface' => 'none']);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_true(!$project->exists('theme/assets/surface/surface.css'), 'stale overlay pruned');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme warns when generated CSS already claimed body::before', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'surface' => 'paper']);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#0B1B33'],
        ['slug' => 'contrast', 'color' => '#7EC8E3'],
    ]]]]);
    $project->writeText('theme/style.css', 'body:where(.page)::before { content: "deco"; }');
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $css = $project->readText('theme/assets/surface/surface.css');
    assert_contains('rgba(11,27,51,', $css);
    assert_contains('rgba(126,200,227,', $css);
    $warning = implode(' ', $project->readJson('warnings.json')['finalize-theme'] ?? []);
    assert_contains('html body::before', $warning);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme ships the device kit and prunes it for none', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'device' => 'stamp']);
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    assert_contains('.device--stamp', $project->readText('theme/assets/device/device.css'));
    $php = $project->readText('theme/functions.php');
    assert_contains(
        "wp_enqueue_style('forno-vero-device', get_theme_file_uri('assets/device/device.css'), "
            . "array('forno-vero-style'), \$ver);",
        $php,
    );
    assert_contains("add_editor_style(array('style.css', 'assets/device/device.css'));", $php);

    $project->writeJson('designDirection.json', ['description' => 'x', 'device' => 'none']);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_true(!$project->exists('theme/assets/device/device.css'), 'stale device kit pruned');
    $php = $project->readText('theme/functions.php');
    assert_true(!str_contains($php, 'forno-vero-device'), 'stale device enqueue pruned');
    assert_true(!str_contains($php, 'assets/device/device.css'), 'stale editor device style pruned');
    assert_contains("add_editor_style('style.css');", $php);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme ships surface and device kits independently', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', [
        'description' => 'x',
        'surface' => 'paper',
        'device' => 'stamp',
        'shape' => 'soft',
    ]);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#EFE8DA'],
    ]]]]);
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $php = $project->readText('theme/functions.php');
    assert_true($project->exists('theme/assets/surface/surface.css'));
    assert_true($project->exists('theme/assets/device/device.css'));
    assert_true($project->exists('theme/assets/shape/shape.css'));
    assert_contains('forno-vero-surface', $php);
    assert_contains('forno-vero-device', $php);
    assert_contains('forno-vero-shape', $php);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme keeps a corrupt required theme artifact fatal', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'surface' => 'paper']);
    $project->writeText('theme/theme.json', '{');
    $classes = 'header-behavior-overlay-to-solid header-start-transparent '
        . 'header-scrolled-contrast header-foreground-base';
    $header = '<!-- wp:group {"className":"' . $classes . '","textColor":"base"} -->'
        . '<div class="wp-block-group ' . $classes . ' has-base-color has-text-color">'
        . '<!-- wp:site-title /--></div><!-- /wp:group -->';
    $project->writeText('theme/parts/header.html', $header);
    $project->writeText('headerBehavior.json', '"static"');

    $error = assert_throws(fn () => quietly(fn () => (new FinalizeThemeStep())->run($project)));
    assert_contains('File is not valid JSON', $error->getMessage());
    assert_true(!$project->exists('theme/functions.php'), 'fatal artifact failure writes no partial wiring');
    assert_eq($header, $project->readText('theme/parts/header.html'), 'fatal preflight leaves header bytes unchanged');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme omits a stale surface overlay from the loader when it cannot be removed', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'surface' => 'none']);
    $project->writeText('theme/assets/surface/surface.css', 'stale');
    $project->writeText('theme/functions.php', "wp_enqueue_style('forno-vero-surface', 'stale');\n");
    finalize_static_header($project);
    $surfaceDir = $project->themePath('assets/surface');
    try {
        if (!chmod($surfaceDir, 0555) || is_writable($surfaceDir)) {
            skip_test('cannot make the kit directory read-only on this platform');
        }
        quietly(fn () => (new FinalizeThemeStep())->run($project));
        assert_true($project->exists('theme/assets/surface/surface.css'), 'failed prune keeps stale file visible');
        $php = $project->readText('theme/functions.php');
        assert_true(!str_contains($php, 'forno-vero-surface'), 'rewritten loader does not enqueue leftover overlay');
        $warning = implode(' ', $project->readJson('warnings.json')['finalize-theme'] ?? []);
        assert_contains('leftover bytes could not be deleted', $warning);
    } finally {
        chmod($surfaceDir, 0775);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('finalize-theme tunes the overlay to a dark page base', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'surface' => 'concrete']);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#16181A'],
    ]]]]);
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $css = $project->readText('theme/assets/surface/surface.css');
    assert_contains('mix-blend-mode: soft-light', $css);
    assert_contains('opacity: 0.48', $css, 'a dark base carries the heavier grain');
    assert_true(!str_contains($css, 'feTurbulence'));
    assert_contains('z-index: 1', $css);
    assert_contains('@supports (mix-blend-mode: soft-light)', $css);

    exec('rm -rf ' . escapeshellarg($tmp));
});
