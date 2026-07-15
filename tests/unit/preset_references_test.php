<?php
declare(strict_types=1);

use Automattic\SiteBuild\PresetReferences;
use Automattic\SiteBuild\ProjectStore;

/** @return array{0:Automattic\SiteBuild\Project,1:string} */
function preset_references_project(): array
{
    $tmp = sys_get_temp_dir() . '/builder_presets_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', [
        'version' => 3,
        'settings' => [
            'spacing' => ['spacingSizes' => [
                ['slug' => 'xl', 'name' => 'Large', 'size' => '5rem'],
            ]],
            'color' => [
                'palette' => [
                    ['slug' => 'primary', 'name' => 'Primary', 'color' => '#123456'],
                ],
                'gradients' => [
                    ['slug' => 'wash', 'name' => 'Wash', 'gradient' => 'linear-gradient(#fff,#000)'],
                ],
                'duotone' => [
                    ['slug' => 'mono', 'name' => 'Mono', 'colors' => ['#fff', '#000']],
                ],
            ],
            'typography' => [
                'fontSizes' => [
                    ['slug' => 'display', 'name' => 'Display', 'size' => '4rem'],
                ],
                'fontFamilies' => [
                    ['slug' => 'body', 'name' => 'Body', 'fontFamily' => 'sans-serif'],
                ],
            ],
            'shadow' => ['presets' => [
                ['slug' => 'soft', 'name' => 'Soft', 'shadow' => '0 1px 4px #0003'],
            ]],
            'dimensions' => ['aspectRatios' => [
                ['slug' => 'portrait', 'name' => 'Portrait', 'ratio' => '3/4'],
            ]],
        ],
    ]);
    return [$project, $tmp];
}

test('preset references accepts declared block and CSS forms for every known type', function () {
    [$project, $tmp] = preset_references_project();
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|xl"}},'
        . '"color":{"text":"var:preset|color|primary"},'
        . '"typography":{"fontSize":"var:preset|font-size|display"},'
        . '"filter":{"duotone":"var:preset|duotone|mono"}}} -->'
        . '<div style="font-family:var(--wp--preset--font-family--body);'
        . 'box-shadow:var(--wp--preset--shadow--soft)">Hero</div><!-- /wp:group -->'
    );
    $project->writeText(
        'theme/templates/front-page.html',
        '<style>.card{background:var(--wp--preset--gradient--wash);'
        . 'aspect-ratio:var(--wp--preset--aspect-ratio--portrait)}</style>'
        . '<!-- wp:template-part {"slug":"section-hero"} /-->'
    );

    assert_eq([], PresetReferences::problems($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('preset references reports malformed block and CSS separators', function () {
    [$project, $tmp] = preset_references_project();
    $project->writeText(
        'theme/parts/section-bad.html',
        '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing--xl"}}}} -->'
        . '<div style="padding-top:var(--wp--preset--spacing-xl)">Bad</div>'
        . '<style>.bad{color:var(--wp--preset--color-primary)}</style>'
        . '<!-- /wp:group -->'
    );

    $problems = PresetReferences::problems($project);
    assert_eq(3, count($problems));
    $joined = implode("\n", $problems);
    assert_contains('var:preset|spacing--xl', $joined);
    assert_contains('--wp--preset--spacing-xl', $joined);
    assert_contains('--wp--preset--color-primary', $joined);
    assert_contains('malformed preset reference', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('preset references reports syntactically valid slugs absent from theme.json', function () {
    [$project, $tmp] = preset_references_project();
    $project->writeText(
        'theme/parts/section-unknown.html',
        '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|missing"}}}} -->'
        . '<div style="padding:var(--wp--preset--spacing--missing);'
        . 'color:var(--wp--preset--color--ghost)">Unknown</div><!-- /wp:group -->'
    );

    $problems = PresetReferences::problems($project);
    // The block and CSS spelling of the same spacing reference collapse to
    // one logical problem for this file.
    assert_eq(2, count($problems));
    $joined = implode("\n", $problems);
    assert_contains('preset spacing slug "missing" is not declared', $joined);
    assert_contains('preset color slug "ghost" is not declared', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('preset references ignores unrelated CSS variables and prose outside scanned scopes', function () {
    [$project, $tmp] = preset_references_project();
    $project->writeText(
        'theme/parts/section-custom.html',
        '<!-- wp:paragraph --><p style="gap:var(--card-gap);color:var(--brand-color)">'
        . 'Example text: --wp--preset--spacing-broken</p><!-- /wp:paragraph -->'
    );

    assert_eq([], PresetReferences::problems($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('preset references validates generated style.css and theme.json strings', function () {
    [$project, $tmp] = preset_references_project();
    $project->writeText(
        'theme/style.css',
        '.card{color:var(--wp--preset--color--ghost);box-shadow:var(--wp--preset--shadow--natural)}'
    );
    $theme = $project->readJson('theme/theme.json');
    $theme['styles']['spacing']['padding']['top'] = 'var:preset|spacing|missing';
    $project->writeJson('theme/theme.json', $theme);

    $joined = implode("\n", PresetReferences::problems($project));
    assert_contains('style.css: preset color slug "ghost"', $joined);
    assert_contains('theme.json: preset spacing slug "missing"', $joined);
    assert_true(!str_contains($joined, 'shadow slug "natural"'), 'core shadow presets are valid without redeclaration');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('preset references validates plain preset-bearing block fields', function () {
    [$project, $tmp] = preset_references_project();
    $project->writeText(
        'theme/parts/section-fields.html',
        '<!-- wp:group {"backgroundColor":"ghost","fontFamily":"body","overlayColor":"missing-overlay"} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph {"fontSize":"missing","textColor":"primary"} -->'
        . '<p>Text</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
    );

    $joined = implode("\n", PresetReferences::problems($project));
    assert_contains('preset color slug "ghost" from backgroundColor', $joined);
    assert_contains('preset color slug "missing-overlay" from overlayColor', $joined);
    assert_contains('preset font-size slug "missing" from fontSize', $joined);
    assert_true(!str_contains($joined, 'font-family slug "body"'), 'declared direct field passes');
    assert_true(!str_contains($joined, 'color slug "primary"'), 'declared direct field passes');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('preset references honors disabled core shadow presets', function () {
    [$project, $tmp] = preset_references_project();
    $theme = $project->readJson('theme/theme.json');
    $theme['settings']['shadow']['defaultPresets'] = false;
    $project->writeJson('theme/theme.json', $theme);
    $project->writeText('theme/style.css', '.card{box-shadow:var(--wp--preset--shadow--natural)}');

    $joined = implode("\n", PresetReferences::problems($project));
    assert_contains('preset shadow slug "natural" is not declared', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('preset references catches a missing separator before the preset type', function () {
    [$project, $tmp] = preset_references_project();
    $project->writeText(
        'theme/style.css',
        '.bad{padding:var(--wp--preset-spacing--xl)}'
    );

    $joined = implode("\n", PresetReferences::problems($project));
    assert_contains('--wp--preset-spacing--xl', $joined);
    assert_contains('malformed preset reference', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('preset references fails clearly when theme.json cannot supply declarations', function () {
    [$project, $tmp] = preset_references_project();
    $project->writeText('theme/theme.json', '{bad json');
    $problems = PresetReferences::problems($project);
    assert_eq(1, count($problems));
    assert_contains('invalid JSON', $problems[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});
