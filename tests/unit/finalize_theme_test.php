<?php
declare(strict_types=1);

function finalize_project(array $fontFamilies): array
{
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('theme/theme.json', [
        'version' => 3,
        'settings' => ['typography' => ['fontFamilies' => $fontFamilies]],
    ]);
    return [$project, $tmp];
}

test('finalize-theme enqueues google fonts for real families', function () {
    [$project, $tmp] = finalize_project([
        ['slug' => 'heading', 'fontFamily' => 'Playfair Display, serif', 'name' => 'Heading'],
        ['slug' => 'body', 'fontFamily' => '"Source Serif Pro", serif', 'name' => 'Body'],
    ]);

    (new FinalizeThemeStep())->run($project);

    $php = $project->readText('theme/functions.php');
    // Block themes don't load style.css automatically; the enqueue is what
    // makes the utility CSS (equal-cards, layout utilities) actually apply.
    assert_contains("wp_enqueue_style('forno-vero-style', get_stylesheet_uri()", $php);
    assert_contains("add_editor_style('style.css')", $php);
    assert_contains('fonts.googleapis.com/css2', $php);
    assert_contains('family=Playfair+Display:wght@400;600;700', $php);
    assert_contains('family=Source+Serif+Pro:wght@400;600;700', $php);
    assert_contains('forno-vero-fonts', $php);
    // PHP must be syntactically valid.
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme skips generic/system families', function () {
    [$project, $tmp] = finalize_project([
        ['slug' => 'heading', 'fontFamily' => 'Georgia, serif', 'name' => 'Heading'],
        ['slug' => 'body', 'fontFamily' => 'sans-serif', 'name' => 'Body'],
    ]);

    (new FinalizeThemeStep())->run($project);
    $php = $project->readText('theme/functions.php');
    assert_true(!str_contains($php, 'googleapis'), 'no google fonts for system families');
    assert_contains('get_stylesheet_uri()', $php, 'style.css enqueued even without webfonts');
    // Still valid PHP.
    $rc = 0;
    exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $o, $rc);
    assert_eq(0, $rc);

    exec('rm -rf ' . escapeshellarg($tmp));
});
