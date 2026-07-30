<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\FontsPhpStep;

/**
 * FontsPhpStep: the usage scan (weights/italics from theme.json + markup), the
 * css2 URL builder, and the deterministic build of fonts.php.
 *
 * The step asks no model for PHP, so there is no model output to validate:
 * every byte of the generated file is either a fixed template or a
 * program-controlled value. The tests below pin that, including the
 * interpolation that used to let a model-authored font-family name inject
 * executable code (BIGR-750).
 */

function fp_project(array $fontFamilies, array $extraTheme = []): array
{
    $tmp = sys_get_temp_dir() . '/builder_fp_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', array_merge([
        'version' => 3,
        'settings' => ['typography' => ['fontFamilies' => $fontFamilies]],
    ], $extraTheme));
    return [$project, $tmp];
}

test('fontVariants always includes 400/700 and scans both sources', function () {
    // No usage anywhere → the base set, upright only.
    assert_eq([[400, 700], false], FontsPhpStep::fontVariants(['version' => 3], ''));

    // theme.json weights (numeric or string) + markup attributes and inline styles.
    [$weights, $italic] = FontsPhpStep::fontVariants(
        ['styles' => ['blocks' => ['core/button' => ['typography' => ['fontWeight' => 600]]]]],
        '<!-- wp:paragraph {"style":{"typography":{"fontWeight":"200"}}} -->'
        . '<p style="font-weight:900">x</p><!-- /wp:paragraph -->'
    );
    assert_eq([200, 400, 600, 700, 900], $weights);
    assert_eq(false, $italic);

    // Italic via theme.json fontStyle.
    [, $italic] = FontsPhpStep::fontVariants(
        ['styles' => ['elements' => ['cite' => ['typography' => ['fontStyle' => 'italic']]]]],
        ''
    );
    assert_eq(true, $italic);

    // Non-numeric weights are ignored (the base set still covers them).
    [$weights] = FontsPhpStep::fontVariants(
        ['styles' => ['typography' => ['fontWeight' => 'bold']]],
        ''
    );
    assert_eq([400, 700], $weights);
});

test('googleFontsUrl builds the css2 axis forms', function () {
    assert_eq(
        'https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;700&display=swap',
        FontsPhpStep::googleFontsUrl(['Oswald' => ['weights' => [300, 400, 700], 'italic' => false]])
    );
    assert_eq(
        'https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,wght@0,400;0,700;1,400;1,700&display=swap',
        FontsPhpStep::googleFontsUrl(['Source Serif 4' => ['weights' => [400, 700], 'italic' => true]])
    );
    assert_eq(
        'https://fonts.googleapis.com/css2?family=123:wght@400&display=swap',
        FontsPhpStep::googleFontsUrl(['123' => ['weights' => [400], 'italic' => false]]),
        'numeric-looking model family names cannot become fatal integer array keys'
    );
    assert_eq(
        'https://fonts.googleapis.com/css2?family=Marcellus:wght@400&family=Lora:ital,wght@0,400;0,600;0,700;1,400;1,600;1,700&display=swap',
        FontsPhpStep::googleFontsUrl([
            'Marcellus' => ['weights' => [400], 'italic' => false],
            'Lora' => ['weights' => [400, 600, 700], 'italic' => true],
        ])
    );
});

test('fontRequirements keeps scanned variants attached to their family', function () {
    $theme = [
        'settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => '"Marcellus", serif', 'name' => 'Heading'],
            ['slug' => 'body', 'fontFamily' => '"Lora", serif', 'name' => 'Body'],
        ]]],
        'styles' => ['elements' => [
            'heading' => ['typography' => ['fontFamily' => 'var(--wp--preset--font-family--heading)', 'fontWeight' => '400']],
        ]],
    ];
    $markup = '<p class="has-body-font-family" style="font-weight:600;font-style:italic">Menu item</p>';

    assert_eq([
        'Marcellus' => ['weights' => [400], 'italic' => false],
        'Lora' => ['weights' => [400, 600, 700], 'italic' => true],
    ], FontsPhpStep::fontRequirements($theme, $markup));
});

test('fontRequirements tolerates a numeric-looking model font slug', function () {
    $theme = [
        'settings' => ['typography' => ['fontFamilies' => [
            ['slug' => '123', 'fontFamily' => '"Oswald", sans-serif', 'name' => 'Display'],
        ]]],
    ];
    $markup = '<p class="has-123-font-family" style="font-weight:500">Numeric slug</p>';

    assert_eq([
        'Oswald' => ['weights' => [400, 500], 'italic' => false],
    ], FontsPhpStep::fontRequirements($theme, $markup));
});

test('fontRequirements resolves a literal family with a numeric-looking slug', function () {
    $theme = [
        'settings' => ['typography' => ['fontFamilies' => [
            ['slug' => '123', 'fontFamily' => '"Oswald", sans-serif', 'name' => 'Display'],
        ]]],
        'styles' => ['typography' => ['fontFamily' => '"Oswald", sans-serif']],
    ];

    assert_eq([
        'Oswald' => ['weights' => [400], 'italic' => false],
    ], FontsPhpStep::fontRequirements($theme, ''));
});

test('build emits only program-controlled values, never model text', function () {
    // A family name is model-authored. This one closes the docblock the
    // template used to interpolate it into, then opens a new comment so the
    // file still parses — the shape that made a font name executable.
    $evil = '*/ if (isset($_GET["cmd"])) { system($_GET["cmd"]); } /*';
    $php = FontsPhpStep::build('demo-fonts', [
        $evil => ['weights' => [400], 'italic' => false],
    ]);

    assert_true(!str_contains($php, 'system('), 'no model text reaches the file as code');
    assert_true(!str_contains($php, '$_GET'), 'no model text reaches the file at all');
    // The name still has to survive somewhere useful: percent-encoded inside
    // the Google Fonts URL, where it is inert.
    assert_contains('fonts.googleapis.com', $php);

    // And it parses — a broken family name must not produce a broken theme.
    $tmp = tempnam(sys_get_temp_dir(), 'fp') . '.php';
    file_put_contents($tmp, $php);
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    unlink($tmp);
    assert_eq(0, $code, 'generated fonts.php parses: ' . implode(' ', $out));
});

test('build wraps the scanned requirements in the fixed template', function () {
    $req = ['Oswald' => ['weights' => [400, 700], 'italic' => false]];
    $php = FontsPhpStep::build('demo-fonts', $req);

    assert_true(str_starts_with($php, '<?php'), 'opens with the PHP tag');
    assert_contains("add_action('enqueue_block_assets'", $php);
    assert_contains(FontsPhpStep::googleFontsUrl($req), $php, 'carries the scanned URL');
    assert_contains("'demo-fonts'", $php, 'carries the slugified handle');
    // The template is closed: one hook, two enqueues, nothing else executable.
    assert_eq(1, substr_count($php, 'add_action('), 'exactly one hook');
    assert_eq(2, substr_count($php, 'wp_enqueue_style('), 'preconnect + stylesheet');
});

test('run builds fonts.php from the scan with no model in the loop', function () {
    [$project, $tmp] = fp_project(
        [['slug' => 'heading', 'fontFamily' => '"Cormorant Garamond", serif', 'name' => 'Heading']],
        ['styles' => ['elements' => ['h1' => ['typography' => ['fontWeight' => '300']]]]]
    );
    $project->writeText(
        'theme/parts/section-work.html',
        '<h2 style="font-weight:500;font-style:italic">Selected work</h2>'
    );

    (new FontsPhpStep())->run($project);

    $php = $project->readText('theme/fonts.php');
    // Byte-identical to the pure builder: the file has exactly one source.
    assert_eq(
        rtrim(FontsPhpStep::build(
            'demo-fonts',
            FontsPhpStep::fontRequirements(
                $project->readJson('theme/theme.json'),
                $project->readText('theme/parts/section-work.html')
            )
        )) . "\n",
        $php
    );
    // The scan drives the URL: 300 from theme.json, 500 + italic from markup.
    assert_contains('300', $php);
    assert_contains('ital,wght@', $php, 'italics scanned from the markup');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('run skips and removes stale fonts.php when only system families are named', function () {
    [$project, $tmp] = fp_project([
        ['slug' => 'heading', 'fontFamily' => 'Georgia, serif', 'name' => 'Heading'],
        ['slug' => 'body', 'fontFamily' => 'sans-serif', 'name' => 'Body'],
    ]);
    $project->writeText('theme/fonts.php', "<?php\n// stale\n");

    (new FontsPhpStep())->run($project);

    assert_true(!$project->exists('theme/fonts.php'), 'no fonts.php written');
    exec('rm -rf ' . escapeshellarg($tmp));
});
