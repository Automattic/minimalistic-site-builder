<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\HeroBlueprint;

/**
 * A design stylesheet whose <header> authors the given colours. Mirrors the
 * shape real designs use: a :root custom-property block plus a bare `header`
 * rule referring to it through var().
 */
function design_header_css(?string $background, ?string $text = null, string $selector = 'header'): string
{
    $vars = [];
    $decls = [];
    if ($background !== null) {
        $vars[] = "--band:{$background};";
        $decls[] = 'background:var(--band);';
    }
    if ($text !== null) {
        $vars[] = "--ink:{$text};";
        $decls[] = 'color:var(--ink);';
    }
    return ":root{" . implode('', $vars) . "}\n"
        . "body{background:var(--band,#fff);}\n"
        . $selector . "{" . implode('', $decls) . "position:relative;}\n";
}

/** Stacked-mode pages: a solid opening keeps the contract out of overlay mode. */
function design_header_pages(): array
{
    return [[
        'slug' => 'home',
        'title' => 'Home',
        'path' => '/',
        'front' => true,
        'sections' => [
            [
                'slug' => 'hero',
                'title' => 'Welcome',
                'layout_archetype' => 'stacked-band',
                'background' => 'base',
                'primary_action' => null,
            ],
            [
                'slug' => 'proof',
                'title' => 'Proof',
                'layout_archetype' => 'offset-grid',
                'background' => 'base',
                'primary_action' => null,
            ],
        ],
    ]];
}

/** @param array<string,string> $palette slug => hex */
function design_header_theme(array $palette): array
{
    return ['settings' => ['color' => ['palette' => array_map(
        static fn (string $slug, string $color): array => ['slug' => $slug, 'color' => $color],
        array_keys($palette),
        array_values($palette),
    )]]];
}

/** @param array<string,string> $palette slug => hex */
function design_header_contract(?string $css, array $palette): array
{
    return AboveFoldContract::resolve(
        pages: design_header_pages(),
        blueprint: HeroBlueprint::defaultFor('editorial-split'),
        canvas: 'full-bleed',
        themeContext: design_header_theme($palette),
        siteContext: [
            'stable_id' => 'design-header-fixture',
            'writing_direction' => 'ltr',
            'page_count' => 1,
        ],
        footerContext: ['archetype' => 'minimal-columns', 'surface' => 'base'],
        designCss: $css,
    );
}

/** azure-garden's real palette: primary is byte-identical to the authored navy. */
const DESIGN_HEADER_AZURE_PALETTE = [
    'base' => '#FBFAF7',
    'contrast' => '#12151A',
    'primary' => '#0B1B33',
    'secondary' => '#3C4A5A',
    'accent' => '#8A6425',
];

/** sunny-ember's real palette: `base` is DARK and `contrast` is LIGHT. */
const DESIGN_HEADER_INVERTED_PALETTE = [
    'base' => '#1B0B2E',
    'contrast' => '#F7F2FF',
    'primary' => '#A855F7',
    'secondary' => '#F26522',
    'accent' => '#FFD400',
];

// H1 -- RED FIRST. The defect: azure-garden authors an opaque navy header that
// is byte-identical to the palette's `primary`, and the stacked contract
// hard-codes `base` regardless.
test('a stacked header adopts the palette slug matching the design-authored header background', function () {
    $contract = design_header_contract(
        design_header_css('#0B1B33', '#FBFAF7'),
        DESIGN_HEADER_AZURE_PALETTE,
    );

    assert_eq(AboveFoldContract::MODE_STACKED, $contract['header']['mode']);
    assert_eq('primary', $contract['header']['protection_token']);
    assert_eq('base', $contract['header']['foreground_token']);
});

test('the authored header background is read through a var() chain, not only as a literal', function () {
    $css = ":root{--navy:#0B1B33;--surface:var(--navy);}\n"
        . "header{background:var(--surface);color:#FBFAF7;}\n";
    $contract = design_header_contract($css, DESIGN_HEADER_AZURE_PALETTE);

    assert_eq('primary', $contract['header']['protection_token']);
    assert_eq('base', $contract['header']['foreground_token']);
});

test('a compound header selector authors the surface just as a bare element selector does', function () {
    $contract = design_header_contract(
        design_header_css('#0B1B33', '#FBFAF7', 'header.site-header'),
        DESIGN_HEADER_AZURE_PALETTE,
    );

    assert_eq('primary', $contract['header']['protection_token']);
});

// H2 -- NON-VACUITY. Pin the negative by asserting the PRESENCE of `base`, so
// the gate cannot pass by deleting the construct it measures.
test('a design authoring no header background still yields the reviewed base/contrast stacked pair', function () {
    $noRule = design_header_contract(
        ":root{--navy:#0B1B33;}\nmain{background:var(--navy);}\n",
        DESIGN_HEADER_AZURE_PALETTE,
    );
    assert_eq('base', $noRule['header']['protection_token']);
    assert_eq('contrast', $noRule['header']['foreground_token']);

    // A `header` rule that authors no colour at all (amber-ember's shape).
    $colourless = design_header_contract(
        ":root{--navy:#0B1B33;}\nheader{position:absolute;top:0;z-index:5;}\n",
        DESIGN_HEADER_AZURE_PALETTE,
    );
    assert_eq('base', $colourless['header']['protection_token']);
    assert_eq('contrast', $colourless['header']['foreground_token']);

    // No design stylesheet at all -- the legacy, non-HTML-first path.
    $absent = design_header_contract(null, DESIGN_HEADER_AZURE_PALETTE);
    assert_eq('base', $absent['header']['protection_token']);
    assert_eq('contrast', $absent['header']['foreground_token']);
});

test('a design whose authored header background already matches base still yields base', function () {
    // zesty-canyon / silver-summit / tbilisi4's shape: `header{background:var(--base)}`.
    $contract = design_header_contract(
        design_header_css('#FBFAF7'),
        DESIGN_HEADER_AZURE_PALETTE,
    );
    assert_eq('base', $contract['header']['protection_token']);
    assert_eq('contrast', $contract['header']['foreground_token']);
});

// H4 -- MAPPING IS BY VALUE, NOT BY NAME. sunny-ember's palette inverts the
// usual luminance roles: `base` is near-black and `contrast` is near-white.
// An implementation that assumes `base` is the light token fails both halves.
test('the header surface maps by colour value even when base is the dark token', function () {
    // The authored LIGHT header must select `contrast`, because in this
    // palette `contrast` -- not `base` -- is the light colour.
    $light = design_header_contract(
        design_header_css('#F7F2FF', '#1B0B2E'),
        DESIGN_HEADER_INVERTED_PALETTE,
    );
    assert_eq('contrast', $light['header']['protection_token']);
    assert_eq('base', $light['header']['foreground_token']);

    // And the authored DARK header must select `base`.
    $dark = design_header_contract(
        design_header_css('#1B0B2E', '#F7F2FF'),
        DESIGN_HEADER_INVERTED_PALETTE,
    );
    assert_eq('base', $dark['header']['protection_token']);
    assert_eq('contrast', $dark['header']['foreground_token']);
});

test('an authored header background maps to a mid-palette slug by value, never by slug order', function () {
    $contract = design_header_contract(
        design_header_css('#A855F7'),
        DESIGN_HEADER_INVERTED_PALETTE,
    );
    assert_eq('primary', $contract['header']['protection_token']);

    $accent = design_header_contract(
        design_header_css('#FFD400'),
        DESIGN_HEADER_INVERTED_PALETTE,
    );
    assert_eq('accent', $accent['header']['protection_token']);
});

// The match is a recognition test, not a snap-to-nearest. calm-lantern authors
// #2E0B5A, which is CIELAB dE 34.6 from its nearest palette slug -- a plainly
// different colour. Snapping would repaint a header the designer never asked
// for, so the contract keeps the reviewed default instead.
test('an authored header background far from every palette slug keeps the reviewed default', function () {
    $contract = design_header_contract(
        design_header_css('#2E0B5A', '#FFFFFF'),
        [
            'base' => '#FFFFFF',
            'contrast' => '#151217',
            'primary' => '#5B18A6',
            'secondary' => '#B84403',
            'accent' => '#FFC91E',
        ],
    );

    assert_eq('base', $contract['header']['protection_token']);
    // The authored `#FFFFFF` text IS an exact palette colour, so it still maps.
    assert_eq('base', $contract['header']['foreground_token']);
});

test('a near-identical authored background inside the match window still maps to its slug', function () {
    // One unit per channel off #0B1B33 -- encoding noise, not a new colour.
    $contract = design_header_contract(
        design_header_css('#0C1C34'),
        DESIGN_HEADER_AZURE_PALETTE,
    );
    assert_eq('primary', $contract['header']['protection_token']);
});

// H3 -- SAFETY VETO INTACT. A design may author a pair that is unreadable.
// The contract records what the design asked for; HeaderBehavior still refuses
// to deliver it and falls back through opaquePairWithSafety.
test('an authored header pair below 4.5:1 is vetoed by the existing behavior fallback', function () {
    // primary #0B1B33 against contrast #12151A is ~1.05:1 -- unreadable.
    $palette = DESIGN_HEADER_AZURE_PALETTE;
    $ratio = ContrastMath::ratio(
        ContrastMath::hexToRgb($palette['contrast']),
        ContrastMath::hexToRgb($palette['primary']),
    );
    assert_true($ratio < ContrastMath::NORMAL_TEXT, "authored pair must be unsafe, got {$ratio}:1");

    $artifact = HeaderBehavior::resolve(
        design_header_pages(),
        HeaderBehavior::MODE_STACKED,
        $palette,
        'standard-row',
        HeaderBehavior::TRANSITION_SMOOTH,
        'primary',
        'contrast',
        'base',
    );

    $delivered = ContrastMath::ratio(
        ContrastMath::hexToRgb($palette[$artifact['foreground']]),
        ContrastMath::hexToRgb($palette[$artifact['topSurface']]),
    );
    assert_true(
        $delivered >= ContrastMath::NORMAL_TEXT,
        "delivered pair must clear 4.5:1, got {$delivered}:1"
        . " ({$artifact['foreground']} on {$artifact['topSurface']})",
    );
    // The veto keeps the authored SURFACE and repairs the foreground.
    assert_eq('primary', $artifact['topSurface']);
    assert_eq('base', $artifact['foreground']);
});

test('a derived stacked surface reaches the header kit through its header-start class', function () {
    $artifact = HeaderBehavior::resolve(
        design_header_pages(),
        HeaderBehavior::MODE_STACKED,
        DESIGN_HEADER_AZURE_PALETTE,
        'standard-row',
        HeaderBehavior::TRANSITION_SMOOTH,
        'primary',
        'base',
        'base',
    );
    $classes = HeaderBehavior::rootClasses($artifact);
    if ($artifact['behavior'] === HeaderBehavior::STATIC) {
        assert_eq([], $classes);
        return;
    }
    assert_true(
        in_array('header-start-primary', $classes, true),
        'kit surface class must name the derived slug, got: ' . implode(' ', $classes),
    );
    assert_true(
        !in_array('header-top-transparent', $classes, true),
        'a stacked header must not resolve to a transparent top: ' . implode(' ', $classes),
    );
});

// Every slug the mapping can emit must be one the header kit has a class for,
// or the derived surface silently never reaches the rendered header.
test('the derived surface vocabulary is exactly the header kit vocabulary', function () {
    $css = file_get_contents(dirname(__DIR__, 2) . '/assets/header/header.css');
    foreach (HeaderBehavior::SURFACES as $slug) {
        foreach (['header-start-', 'header-scrolled-', 'header-foreground-'] as $prefix) {
            assert_true(
                str_contains((string) $css, ".{$prefix}{$slug} "),
                "header kit has no .{$prefix}{$slug} rule",
            );
        }
    }
});
