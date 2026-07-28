<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\FontsPhpStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * FontsPhpStep: the usage scan (weights/italics from theme.json + markup), the
 * css2 URL builder, the fonts.php validator (scan floor, Google-only
 * URLs), and the run behavior (model output kept when valid, deterministic
 * fallback otherwise, skip for system-only families).
 */

function fp_valid_php(string $url): string
{
    return "<?php\nadd_action('enqueue_block_assets', function () {\n"
        . "    wp_enqueue_style('preconnect-gfonts', 'https://fonts.gstatic.com', array(), null);\n"
        . "    wp_enqueue_style('demo-fonts', '{$url}', array(), null);\n"
        . "});\n";
}

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

test('validate accepts a well-formed fonts.php that covers the scan', function () {
    // Exactly the scan.
    $req = ['Oswald' => ['weights' => [300, 400, 700], 'italic' => false]];
    $php = fp_valid_php(FontsPhpStep::googleFontsUrl($req));
    assert_eq([], FontsPhpStep::validate($php, $req));

    // The model may request MORE than the scan (here: 200 + italics on top).
    $php = fp_valid_php('https://fonts.googleapis.com/css2?family=Oswald:ital,wght@0,200;0,400;0,700;1,400&display=swap');
    assert_eq([], FontsPhpStep::validate($php, ['Oswald' => ['weights' => [400, 700], 'italic' => false]]));
});

test('validate rejects require/include — a runtime fatal php -l cannot see', function () {
    $req = ['Oswald' => ['weights' => [400, 700], 'italic' => false]];

    // The real failure: a self-inclusion "guard" whose precedence makes it
    // `require_once ''`, fataling on every load once the theme is active.
    $withGuard = str_replace(
        '<?php',
        "<?php\nrequire_once __DIR__ . '/fonts.php' === __FILE__ ? '' : '';",
        fp_valid_php(FontsPhpStep::googleFontsUrl($req))
    );
    assert_true(
        in_array('must not require or include anything', FontsPhpStep::validate($withGuard, $req), true),
        'self-inclusion guard rejected'
    );

    foreach (["require 'x.php';", "include_once __DIR__ . '/x.php';"] as $statement) {
        $php = str_replace('<?php', "<?php\n{$statement}", fp_valid_php(FontsPhpStep::googleFontsUrl($req)));
        assert_true([] !== FontsPhpStep::validate($php, $req), "rejected: {$statement}");
    }

    // Words that merely contain them stay valid.
    $benign = str_replace('<?php', "<?php\n// requirements: the scanned floor.", fp_valid_php(FontsPhpStep::googleFontsUrl($req)));
    assert_eq([], FontsPhpStep::validate($benign, $req));
});

test('validate enforces the scan as a floor', function () {
    // Scanned weight 300 missing from the URL — the exact issue #49 bug.
    $php = fp_valid_php('https://fonts.googleapis.com/css2?family=Oswald:wght@400;700&display=swap');
    assert_true([] !== FontsPhpStep::validate($php, ['Oswald' => ['weights' => [300, 400, 700], 'italic' => false]]), 'missing weight');
    // Scanned italics missing.
    assert_true([] !== FontsPhpStep::validate($php, ['Oswald' => ['weights' => [400, 700], 'italic' => true]]), 'missing italics');
    // A family missing entirely.
    assert_true([] !== FontsPhpStep::validate($php, [
        'Oswald' => ['weights' => [400, 700], 'italic' => false],
        'Source Serif 4' => ['weights' => [400, 700], 'italic' => false],
    ]), 'missing family');
});

test('validate checks weights and italics per family, not globally', function () {
    $php = fp_valid_php(
        'https://fonts.googleapis.com/css2?family=Heading:wght@400&family=Body:ital,wght@0,400;0,600;0,700;1,400;1,600;1,700&display=swap'
    );

    assert_true([] !== FontsPhpStep::validate($php, [
        'Heading' => ['weights' => [400, 600], 'italic' => false],
        'Body' => ['weights' => [400, 600, 700], 'italic' => true],
    ]), 'Heading cannot borrow Body weight 600');
    assert_eq([], FontsPhpStep::validate($php, [
        'Heading' => ['weights' => [400], 'italic' => false],
        'Body' => ['weights' => [400, 600, 700], 'italic' => true],
    ]));
});

test('validate rejects off-contract font loading', function () {
    $req = ['Oswald' => ['weights' => [400, 700], 'italic' => false]];
    $url = FontsPhpStep::googleFontsUrl($req);
    foreach ([
        'wrong hook' => str_replace('enqueue_block_assets', 'wp_enqueue_scripts', fp_valid_php($url)),
        'foreign URL' => str_replace('https://fonts.gstatic.com', 'https://evil.example.com/x.css', fp_valid_php($url)),
        'not php' => "add_action('enqueue_block_assets', fn () => null);",
        'syntax error' => fp_valid_php($url) . "\nfunction broken( {\n",
    ] as $label => $php) {
        assert_true([] !== FontsPhpStep::validate($php, $req), $label);
    }
});

test('validate does not police unrelated PHP side effects', function () {
    $req = ['Oswald' => ['weights' => [400, 700], 'italic' => false]];
    $php = fp_valid_php(FontsPhpStep::googleFontsUrl($req)) . "\ndelete_option('siteurl');\n";
    assert_eq([], FontsPhpStep::validate($php, $req));
});

test('run writes the model fonts.php when valid and passes the configured model', function () {
    [$project, $tmp] = fp_project(
        [['slug' => 'heading', 'fontFamily' => '"Cormorant Garamond", serif', 'name' => 'Heading']],
        ['styles' => ['elements' => ['h1' => ['typography' => ['fontWeight' => '300']]]]]
    );
    $project->writeText(
        'theme/parts/section-work.html',
        '<h2 style="font-weight:500;font-style:italic">Selected work</h2>'
    );
    // Valid model output covering the scan {300, 400, 500 + italics}; the model
    // may still request extra weights such as 700.
    $url = FontsPhpStep::googleFontsUrl([
        'Cormorant Garamond' => ['weights' => [300, 400, 500, 700], 'italic' => true],
    ]);
    $llm = new FakeLlm();
    $llm->queueText(fp_valid_php($url));

    (new FontsPhpStep($llm, new PromptRenderer(repo_path('prompts')), 'claude-haiku-4-5'))->run($project);

    $php = $project->readText('theme/fonts.php');
    assert_contains('family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700', $php);
    // The prompt carried the scan and the design direction slot.
    assert_contains('Cormorant Garamond: weights 300, 400, 500; italics yes', $llm->calls[0]['prompt']);
    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('run falls back to the deterministic fonts.php when the model output fails', function () {
    [$project, $tmp] = fp_project(
        [['slug' => 'heading', 'fontFamily' => 'Oswald, sans-serif', 'name' => 'Heading']],
        ['styles' => ['elements' => ['h1' => ['typography' => ['fontWeight' => '300']]]]]
    );
    // Model forgets the scanned 300 — rejected, replaced by the scan-built file.
    $llm = new FakeLlm();
    $llm->queueText(fp_valid_php('https://fonts.googleapis.com/css2?family=Oswald:wght@400;700&display=swap'));

    (new FontsPhpStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $php = $project->readText('theme/fonts.php');
    assert_contains('family=Oswald:wght@300;400', $php, 'fallback requests the scanned weights');
    assert_contains("add_action('enqueue_block_assets'", $php);
    assert_contains('family Oswald missing scanned weight: 300', $project->readText('logs/fonts-php.log'));

    // The fallback delivery is recorded durably, not just in the step log.
    $joined = implode(' ', $project->readJson('warnings.json')['fonts-php'] ?? []);
    assert_contains('deterministic scan-built fallback delivered', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('run skips and removes stale fonts.php when only system families are named', function () {
    [$project, $tmp] = fp_project([
        ['slug' => 'heading', 'fontFamily' => 'Georgia, serif', 'name' => 'Heading'],
        ['slug' => 'body', 'fontFamily' => 'sans-serif', 'name' => 'Body'],
    ]);
    $project->writeText('theme/fonts.php', "<?php\n// stale\n");
    $llm = new FakeLlm(); // nothing queued: any call would throw

    (new FontsPhpStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq([], $llm->calls, 'no LLM call made');
    assert_true(!$project->exists('theme/fonts.php'), 'no fonts.php written');
    exec('rm -rf ' . escapeshellarg($tmp));
});
