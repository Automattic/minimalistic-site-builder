<?php
declare(strict_types=1);

use Automattic\SiteBuild\TreeGraph\TokenMath;

test('TokenMath contrastRatio and toneOf match the WCAG anchors', function () {
    assert_eq(21.0, round(TokenMath::contrastRatio('#000000', '#ffffff'), 2));
    assert_eq(1.0, round(TokenMath::contrastRatio('#ffffff', '#ffffff'), 2));
    assert_eq('light', TokenMath::toneOf('#ffffff'));
    assert_eq('dark', TokenMath::toneOf('#000000'));
    // 3-digit hexes expand before measurement.
    assert_eq(
        round(TokenMath::contrastRatio('#ffffff', '#777777'), 4),
        round(TokenMath::contrastRatio('#fff', '#777'), 4),
    );
});

test('TokenMath mixHex mixes channels linearly and uppercases', function () {
    assert_eq('#808080', TokenMath::mixHex('#000000', '#ffffff', 0.5));
    assert_eq('#000000', TokenMath::mixHex('#000000', '#ffffff', 0.0));
    assert_eq('#FFFFFF', TokenMath::mixHex('#000000', '#ffffff', 1.0));
    // The placeholder tone: a light band nudged 12% toward black.
    assert_eq('#E0E0E0', TokenMath::mixHex('#ffffff', '#000000', 0.12));
});

test('TokenMath annotatePalette carries hex plus measured tone per slug', function () {
    $annotated = TokenMath::annotatePalette([
        ['slug' => 'base', 'name' => 'Base', 'color' => '#111111'],
        ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#fafafa'],
    ]);
    assert_eq([
        ['slug' => 'base', 'color' => '#111111', 'tone' => 'dark'],
        ['slug' => 'contrast', 'color' => '#fafafa', 'tone' => 'light'],
    ], $annotated);
});

test('TokenMath resolveInkMenus buckets slugs by measured contrast', function () {
    $palette = [
        ['slug' => 'base', 'color' => '#ffffff'],
        ['slug' => 'contrast', 'color' => '#000000'],
        ['slug' => 'mid', 'color' => '#8a8a8a'],  // ~3.45:1 on white — display only
        ['slug' => 'faint', 'color' => '#dddddd'], // ~1.35:1 on white — in no menu
    ];
    $menus = TokenMath::resolveInkMenus('base', $palette);
    assert_eq(['contrast'], $menus['safe_inks']);
    assert_eq(['mid'], $menus['display_only_inks']);

    // Unknown background: both menus empty rather than guessed.
    assert_eq(
        ['safe_inks' => [], 'display_only_inks' => []],
        TokenMath::resolveInkMenus('nope', $palette),
    );
});

test('TokenMath resolveBandColors maps brief roles onto applied slugs by hex', function () {
    $brief = [
        ['name' => 'Cream', 'color' => '#FFF8EE', 'role' => 'background'],
        ['name' => 'Ink', 'color' => '#101014', 'role' => 'text'],
        ['name' => 'Brass', 'color' => '#B8862F', 'role' => 'accent'],
    ];
    $applied = [
        ['slug' => 'base', 'color' => '#FFF8EE'],
        ['slug' => 'contrast', 'color' => '#101014'],
        ['slug' => 'brass', 'color' => '#B8862F'],
    ];
    // The accent band resolves through the brief's accent hex to its applied slug.
    $accent = TokenMath::resolveBandColors('accent', $brief, $applied);
    assert_eq('brass', $accent['background']);
    // Text is chosen by measured contrast against the band's actual colour:
    // dark ink wins on brass, whatever the slug names suggest.
    assert_eq('contrast', $accent['text']);

    $base = TokenMath::resolveBandColors('base', $brief, $applied);
    assert_eq('base', $base['background']);
    assert_eq('contrast', $base['text']);

    $contrast = TokenMath::resolveBandColors('contrast', $brief, $applied);
    assert_eq('contrast', $contrast['background']);
    assert_eq('base', $contrast['text']);
});

test('TokenMath deriveThemeSpacing reads origin-keyed arrays, theme origin first', function () {
    $themeTokens = [
        'spacing' => [
            'spacingSizes' => [
                'default' => [['slug' => '20', 'size' => '0.5rem']],
                'theme'   => [['slug' => '30', 'size' => '1rem'], ['slug' => '40', 'size' => 2]],
            ],
        ],
        'layout' => ['contentSize' => '645px', 'wideSize' => '1340px'],
    ];
    assert_eq(
        ['scale_unit' => 'px', 'steps' => [['slug' => '30', 'size' => '1rem'], ['slug' => '40', 'size' => '2']]],
        TokenMath::deriveThemeSpacing($themeTokens),
    );
    assert_eq(
        ['contentSize' => '645px', 'wideSize' => '1340px'],
        TokenMath::deriveThemeLayout($themeTokens),
    );
    // A plain list passes through as-is.
    assert_eq(
        ['scale_unit' => 'px', 'steps' => [['slug' => 'x', 'size' => '1px']]],
        TokenMath::deriveThemeSpacing(['spacing' => ['spacingSizes' => [['slug' => 'x', 'size' => '1px']]]]),
    );
});

test('TokenMath canonicalJson sorts object keys at every depth, lists keep order', function () {
    assert_eq(
        '{"a":[2,1],"b":{"c":1,"d":2}}',
        TokenMath::canonicalJson(['b' => ['d' => 2, 'c' => 1], 'a' => [2, 1]]),
    );
});

test('TokenMath tokenChecks enforces R9 pass-through and the base/contrast floor', function () {
    if (!class_exists(Automattic\SiteBuild\TreeGraph\Schema::class)) {
        skip_test('TreeGraph\\Schema not present yet (sibling task)');
    }
    $themeSpacing = ['scale_unit' => 'px', 'steps' => [['slug' => '30', 'size' => '1rem']]];
    $themeLayout = ['contentSize' => '645px', 'wideSize' => '1340px'];
    $brief = [['name' => 'Ink', 'color' => '#101014', 'role' => 'text']];
    $tokens = [
        'palette' => [
            ['slug' => 'base', 'name' => 'Base', 'color' => '#FFFFFF'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#101014'],
        ],
        'spacing'    => $themeSpacing,
        'typography' => ['families' => [['slug' => 'sans', 'name' => 'Sans', 'fontFamily' => 'sans-serif']], 'sizes' => [['slug' => 'display', 'size' => '4rem']]],
        'layout'     => $themeLayout,
    ];
    assert_eq([], TokenMath::tokenChecks($tokens, $themeSpacing, $themeLayout, $brief));

    // Redesigned spacing is an R9 violation.
    $drifted = $tokens;
    $drifted['spacing'] = ['scale_unit' => 'px', 'steps' => [['slug' => '30', 'size' => '2rem']]];
    $issues = TokenMath::tokenChecks($drifted, $themeSpacing, $themeLayout, $brief);
    assert_true(count($issues) === 1 && str_contains($issues[0]['message'], 'R9 violation: spacing'));

    // A near-black "contrast" on a near-black base fails the measured pair.
    $dark = $tokens;
    $dark['palette'] = [
        ['slug' => 'base', 'name' => 'Base', 'color' => '#101014'],
        ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#16161A'],
    ];
    $dark['typography'] = $tokens['typography'];
    $issues = TokenMath::tokenChecks($dark, $themeSpacing, $themeLayout, []);
    assert_true(count($issues) === 1 && str_contains($issues[0]['message'], 'body text needs at least 4.5:1'));

    // A brief colour absent from the palette is named.
    $missing = TokenMath::tokenChecks($tokens, $themeSpacing, $themeLayout, [
        ['name' => 'Brass', 'color' => '#B8862F', 'role' => 'accent'],
    ]);
    assert_true(count($missing) === 1 && str_contains($missing[0]['message'], 'brief color #B8862F (Brass)'));

    // Losing the theme's own base/contrast slugs is a failure.
    $renamed = $tokens;
    $renamed['palette'] = [
        ['slug' => 'paper', 'name' => 'Paper', 'color' => '#FFFFFF'],
        ['slug' => 'ink', 'name' => 'Ink', 'color' => '#101014'],
    ];
    $issues = TokenMath::tokenChecks($renamed, $themeSpacing, $themeLayout, []);
    assert_eq(2, count($issues));
    assert_contains('palette must keep the theme slug "base"', $issues[0]['message']);
});
