<?php
declare(strict_types=1);

test('finalize-theme writes the deterministic functions.php loader', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');

    (new FinalizeThemeStep())->run($project);

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
