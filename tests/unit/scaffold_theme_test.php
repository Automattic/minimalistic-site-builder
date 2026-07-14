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
    // JS-set html.js scope, and everything respects reduced motion AND
    // stays out of print media (unvisited reveals would print blank).
    $css = $project->readText('theme/assets/motion/motion.css');
    assert_contains('@media screen and (prefers-reduced-motion: no-preference)', $css);
    assert_contains('html.js .reveal', $css);
    assert_true(!preg_match('/^\s*\.reveal[^{]*\{[^}]*opacity:\s*0/m', $css), 'no unscoped hiding');
    $js = $project->readText('theme/assets/motion/motion.js');
    assert_contains("classList.add('js')", $js);
    assert_contains('prefers-reduced-motion: reduce', $js);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme has stable id and label', function () {
    $s = new ScaffoldThemeStep();
    assert_eq('scaffold-theme', $s->id());
    assert_true($s->label() !== '');
});
