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
    ]);
}

test('finalize-theme writes the deterministic functions.php loader', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
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
    // No model output belongs here — no font URLs, ever.
    assert_true(!str_contains($php, 'googleapis'), 'fonts stay in fonts.php');
    // PHP must be syntactically valid.
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

test('finalize-theme downgrades malformed or incomplete header behavior with a warning', function () {
    foreach (['{"behavior":', '{"behavior":"sticky-soft"}'] as $artifact) {
        $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
        $project = (new ProjectStore($tmp))->create('demo');
        (new ScaffoldThemeStep())->run($project);
        $project->writeText('headerBehavior.json', $artifact);

        quietly(fn () => (new FinalizeThemeStep())->run($project));

        assert_true(!is_dir($project->themePath('assets/header')), 'invalid behavior was delivered as static');
        assert_true(!str_contains($project->readText('theme/functions.php'), 'assets/header/'));
        $warning = implode(' ', $project->readJson('warnings.json')['finalize-theme'] ?? []);
        assert_contains('headerBehavior.json', $warning);
        assert_contains('authored value', $warning);
        assert_contains('delivered value', $warning);
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
    assert_contains('authored value missing', $warning);
    assert_contains('delivered value {"behavior":"static"}', $warning);

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
    ]);
    unlink($project->themePath('assets/header/header.js'));

    $error = assert_throws(fn () => quietly(fn () => (new FinalizeThemeStep())->run($project)));
    assert_contains('Missing or unreadable trusted header asset', $error->getMessage());
    assert_true(!$project->exists('theme/functions.php'), 'fatal contract failure does not write partial wiring');

    exec('rm -rf ' . escapeshellarg($tmp));
});
