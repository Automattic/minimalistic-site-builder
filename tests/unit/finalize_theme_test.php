<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;

test('finalize-theme writes the deterministic functions.php loader', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');

    ob_start();
    (new FinalizeThemeStep())->run($project);
    ob_end_clean();

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

    ob_start();
    (new FinalizeThemeStep())->run($project);
    ob_end_clean();

    $php = $project->readText('theme/functions.php');
    assert_contains("wp_enqueue_style('forno-vero-motion', get_theme_file_uri('assets/motion/motion.css')", $php);
    assert_contains("assets/motion/profiles/dramatic.css", $php);
    // style.css depends on the profile so its :root --motion-* tuning wins.
    assert_contains("wp_enqueue_style('forno-vero-style', get_stylesheet_uri(), array('forno-vero-motion-profile')", $php);
    // The script rides in <head> (last arg false) so html.js exists pre-paint.
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

    ob_start();
    (new FinalizeThemeStep())->run($project);
    ob_end_clean();

    $php = $project->readText('theme/functions.php');
    assert_true(!str_contains($php, 'motion'), 'no motion wiring in functions.php');
    assert_true(!is_dir($project->themePath('assets/motion')), 'kit removed from the theme');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme without a design direction behaves as motion none', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    (new ScaffoldThemeStep())->run($project);

    ob_start();
    (new FinalizeThemeStep())->run($project);
    ob_end_clean();

    assert_true(!str_contains($project->readText('theme/functions.php'), 'motion'));
    assert_true(!is_dir($project->themePath('assets/motion')));

    exec('rm -rf ' . escapeshellarg($tmp));
});
