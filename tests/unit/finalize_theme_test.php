<?php
declare(strict_types=1);

function finalize_project(array $fontFamilies, array $extraTheme = []): array
{
    $tmp = sys_get_temp_dir() . '/builder_fin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('theme/theme.json', array_merge([
        'version' => 3,
        'settings' => ['typography' => ['fontFamilies' => $fontFamilies]],
    ], $extraTheme));
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
    // No explicit weights anywhere → the 400/700 base, nothing more.
    assert_contains('family=Playfair+Display:wght@400;700', $php);
    assert_contains('family=Source+Serif+Pro:wght@400;700', $php);
    assert_contains('forno-vero-fonts', $php);
    // Fonts load in the editor too, not just the front end.
    assert_contains("add_action('enqueue_block_assets'", $php);
    // PHP must be syntactically valid.
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme requests the weights and italics the design actually uses', function () {
    // Weight 300 comes from theme.json (styles.elements), 500 + italic from the
    // generated markup (block attribute + inline style) — per issue #49.
    [$project, $tmp] = finalize_project(
        [['slug' => 'heading', 'fontFamily' => '"Cormorant Garamond", serif', 'name' => 'Heading']],
        ['styles' => ['elements' => ['h1' => ['typography' => ['fontWeight' => '300']]]]]
    );
    $project->writeText(
        'theme/parts/section-work.html',
        '<!-- wp:heading {"style":{"typography":{"fontWeight":"500","fontStyle":"italic"}}} -->'
        . '<h2 style="font-weight:500;font-style:italic">Selected work</h2><!-- /wp:heading -->'
    );

    (new FinalizeThemeStep())->run($project);

    $php = $project->readText('theme/functions.php');
    assert_contains(
        'family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700',
        $php
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('fontVariants always includes 400/700 and scans both sources', function () {
    // No usage anywhere → the base set, upright only.
    assert_eq([[400, 700], false], FinalizeThemeStep::fontVariants(['version' => 3], ''));

    // theme.json weights (numeric or string) + markup attributes and inline styles.
    [$weights, $italic] = FinalizeThemeStep::fontVariants(
        ['styles' => ['blocks' => ['core/button' => ['typography' => ['fontWeight' => 600]]]]],
        '<!-- wp:paragraph {"style":{"typography":{"fontWeight":"200"}}} -->'
        . '<p style="font-weight:900">x</p><!-- /wp:paragraph -->'
    );
    assert_eq([200, 400, 600, 700, 900], $weights);
    assert_eq(false, $italic);

    // Italic via theme.json fontStyle.
    [, $italic] = FinalizeThemeStep::fontVariants(
        ['styles' => ['elements' => ['cite' => ['typography' => ['fontStyle' => 'italic']]]]],
        ''
    );
    assert_eq(true, $italic);

    // Non-numeric weights are ignored (the base set still covers them).
    [$weights] = FinalizeThemeStep::fontVariants(
        ['styles' => ['typography' => ['fontWeight' => 'bold']]],
        ''
    );
    assert_eq([400, 700], $weights);
});

test('googleFontsUrl builds the css2 axis forms', function () {
    assert_eq(
        'https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;700&display=swap',
        FinalizeThemeStep::googleFontsUrl(['Oswald'], [300, 400, 700], false)
    );
    assert_eq(
        'https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,wght@0,400;0,700;1,400;1,700&display=swap',
        FinalizeThemeStep::googleFontsUrl(['Source Serif 4'], [400, 700], true)
    );
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
