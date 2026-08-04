<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;

test('scaffold-theme writes style.css and readme with placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);

    assert_true($project->exists('theme/style.css'), 'style.css written');
    assert_true($project->exists('theme/readme.txt'), 'readme.txt written');

    $css = $project->readText('theme/style.css');
    assert_contains('Theme Name: {{THEME_NAME}}', $css);
    assert_contains('Text Domain: {{THEME_SLUG}}', $css);
    assert_contains('Description: {{DESCRIPTION}}', $css);

    // The card-cropping class hooks the section recipes reference (they keep
    // card sizing out of inline CSS, which fix-blocks would strip).
    assert_contains('.card-media img', $css);
    assert_contains('.card-media-tall img { height: 320px; }', $css);
    assert_contains('.card-media-thumb img { height: 110px; }', $css);

    // Core's font-relative pullquote spacing must not compound unpredictably
    // with the deterministic section rhythm.
    assert_contains('.wp-site-blocks .wp-block-pullquote {', $css);
    assert_contains('margin-block: 0;', $css);
    assert_contains('padding-block: var(--wp--preset--spacing--lg);', $css);

    // The raised hamburger breakpoint (BIGR-735): core swaps to the inline nav
    // at 600px, but a tracked title/nav row that fits at 768px can still wrap
    // or overflow in the 600-719px band — the shipped override keeps the nav
    // collapsed there. Both halves of core's swap must be countered.
    assert_contains('@media (min-width: 600px) and (max-width: 719.98px)', $css);
    assert_contains('.wp-site-blocks .wp-block-navigation__responsive-container-open:not(.always-shown)', $css);
    assert_contains('.wp-site-blocks .wp-block-navigation__responsive-container:not(.hidden-by-default):not(.is-menu-open)', $css);

    $readme = $project->readText('theme/readme.txt');
    assert_contains('=== {{THEME_NAME}} ===', $readme);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme copies the static motion kit verbatim into the theme', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);

    foreach (['motion.css', 'motion.js'] as $file) {
        assert_eq(
            file_get_contents(\Automattic\SiteBuild\Package::motionDir() . '/' . $file),
            $project->readText('theme/assets/motion/' . $file),
            "{$file} copied byte-for-byte"
        );
    }
    foreach (['calm', 'energetic', 'dramatic', 'minimal'] as $profile) {
        assert_true($project->exists("theme/assets/motion/profiles/{$profile}.css"), "{$profile} profile copied");
    }

    // The kit's accessibility contract: reveals hide only under the
    // JS-set, motion-owned scope, and everything respects reduced motion AND
    // stays out of print media (unvisited reveals would print blank).
    $css = $project->readText('theme/assets/motion/motion.css');
    assert_contains('@media screen and (prefers-reduced-motion: no-preference)', $css);
    assert_contains('html.motion-js:not(.motion-ready) .reveal', $css);
    assert_true(!preg_match('/^\s*\.reveal[^{]*\{[^}]*opacity:\s*0/m', $css), 'no unscoped hiding');
    $js = $project->readText('theme/assets/motion/motion.js');
    assert_contains("classList.add('motion-js')", $js);
    assert_contains("classList.add('motion-target')", $js);
    assert_contains('prefers-reduced-motion: reduce', $js);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme copies the trusted adaptive-header kit verbatim', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);

    foreach (['header.css', 'header.js'] as $file) {
        assert_eq(
            file_get_contents(\Automattic\SiteBuild\Package::headerDir() . '/' . $file),
            $project->readText('theme/assets/header/' . $file),
            "{$file} copied byte-for-byte"
        );
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme has stable id and label', function () {
    $s = new ScaffoldThemeStep();
    assert_eq('scaffold-theme', $s->id());
    assert_true($s->label() !== '');
});
