<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\HeaderBehavior;

test('smooth sticky surface selection never crosses an unreadable midpoint', function () {
    $pages = [
        ['slug' => 'home', 'sections' => [['slug' => 'hero']]],
        ['slug' => 'about', 'sections' => [['slug' => 'intro']]],
    ];
    $palette = [
        'base' => '#FFFFFF',
        'contrast' => '#000000',
        // This gray passes against both endpoints but becomes the exact
        // foreground color midway through a white-to-black interpolation.
        'primary' => '#767676',
    ];

    $smooth = HeaderBehavior::resolve(
        $pages,
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'primary',
    );
    assert_eq(HeaderBehavior::STICKY_SOFT, $smooth['behavior']);
    assert_eq('base', $smooth['topSurface']);
    assert_eq('base', $smooth['scrolledSurface'], 'shadow-only change is the safe smooth fallback');

    $foreground = ContrastMath::hexToRgb($palette['primary']);
    $white = ContrastMath::hexToRgb($palette['base']);
    $black = ContrastMath::hexToRgb($palette['contrast']);
    assert_true($foreground !== null && $white !== null && $black !== null);
    assert_true(ContrastMath::ratio($foreground, $white) >= ContrastMath::NORMAL_TEXT);
    assert_true(ContrastMath::ratio($foreground, $black) >= ContrastMath::NORMAL_TEXT);
    assert_true(
        !HeaderBehavior::transitionIsSafe($foreground, $white, $black),
        'endpoint contrast alone cannot prove an animated path',
    );

    $instant = HeaderBehavior::resolve(
        $pages,
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_INSTANT,
        'base',
        'primary',
    );
    assert_eq('contrast', $instant['scrolledSurface'], 'instant changes have no interpolated midpoint');
});

test('sticky-soft downgrades to static when no palette pair reaches readable contrast', function () {
    // Two pages: enough depth that the resolver would otherwise grant
    // sticky-soft chrome.
    $pages = [
        ['slug' => 'home', 'sections' => [['slug' => 'hero']]],
        ['slug' => 'about', 'sections' => [['slug' => 'intro']]],
    ];
    assert_eq(
        HeaderBehavior::STICKY_SOFT,
        HeaderBehavior::behaviorFor($pages, HeaderBehavior::MODE_STACKED),
        'this site shape requests sticky-soft before palette safety runs',
    );

    // Every token sits near mid-gray: no foreground/surface pair can reach
    // 4.5:1, so persistent chrome cannot keep one readable foreground.
    $midTones = [
        'base' => '#7F7F7F',
        'contrast' => '#8A8A8A',
        'primary' => '#757575',
        'secondary' => '#808080',
        'accent' => '#8F8F8F',
    ];
    foreach ($midTones as $a) {
        foreach ($midTones as $b) {
            $aRgb = ContrastMath::hexToRgb($a);
            $bRgb = ContrastMath::hexToRgb($b);
            assert_true($aRgb !== null && $bRgb !== null);
            assert_true(ContrastMath::ratio($aRgb, $bRgb) < ContrastMath::NORMAL_TEXT);
        }
    }

    $artifact = HeaderBehavior::resolve(
        $pages,
        HeaderBehavior::MODE_STACKED,
        $midTones,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
    );
    assert_eq(HeaderBehavior::STATIC, $artifact['behavior'], 'unsafe palette downgrades sticky-soft to static');
    assert_eq(HeaderBehavior::MODE_STACKED, $artifact['mode']);
    assert_eq($artifact['topSurface'], $artifact['scrolledSurface'], 'static keeps one surface');
    assert_eq([], HeaderBehavior::rootClasses($artifact), 'static chrome claims no behavior classes');
});

test('smooth transition safety preserves readable mixed-channel hue changes', function () {
    $pages = [
        ['slug' => 'home', 'sections' => [['slug' => 'hero']]],
        ['slug' => 'about', 'sections' => [['slug' => 'intro']]],
    ];
    $palette = [
        'base' => '#FFFF00',
        'contrast' => '#000000',
        'primary' => '#00FFFF',
    ];

    $artifact = HeaderBehavior::resolve(
        $pages,
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
    );
    assert_eq('primary', $artifact['scrolledSurface'], 'safe hue transition is retained');

    $foreground = ContrastMath::hexToRgb($palette['contrast']);
    $yellow = ContrastMath::hexToRgb($palette['base']);
    $cyan = ContrastMath::hexToRgb($palette['primary']);
    assert_true($foreground !== null && $yellow !== null && $cyan !== null);
    assert_true(HeaderBehavior::transitionIsSafe($foreground, $yellow, $cyan));
});

/**
 * Two sticky-depth pages whose openings carry the given planned backgrounds;
 * null omits the background key entirely (an unplanned opening).
 */
function hb_pages(?string $first = null, ?string $second = null): array
{
    $section = static fn (string $slug, ?string $background): array => $background === null
        ? ['slug' => $slug]
        : ['slug' => $slug, 'background' => $background];
    return [
        ['slug' => 'home', 'sections' => [$section('hero', $first)]],
        ['slug' => 'about', 'sections' => [$section('intro', $second)]],
    ];
}

test('a token-backed multi-page site earns a provably safe transparent sticky start', function () {
    // Foreground #111111 clears the white page background, both planned
    // openings, and every smooth interpolation segment, so the airiest
    // ladder rung must be granted rather than the always-available glass.
    $palette = [
        'base' => '#FFFFFF',
        'contrast' => '#111111',
        'secondary' => '#F2F2F2',
        'primary' => '#2A4B6E',
        'accent' => '#B34700',
    ];
    $artifact = HeaderBehavior::resolve(
        hb_pages('base', 'secondary'),
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
    );
    assert_eq(HeaderBehavior::STICKY_SOFT, $artifact['behavior']);
    assert_eq(HeaderBehavior::TREATMENT_TRANSPARENT, $artifact['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $artifact['scrolledTreatment']);
    assert_true(
        in_array('header-top-transparent', HeaderBehavior::rootClasses($artifact), true),
        'sticky root classes carry the transparent-start hook',
    );
    assert_true(in_array('header-scrolled-glass', HeaderBehavior::rootClasses($artifact), true));

    // An image opening cannot be verified without a scrim, so the same
    // palette downgrades exactly one rung: transparent becomes glass, whose
    // worst case covers arbitrary content.
    $image = HeaderBehavior::resolve(
        hb_pages('base', 'image'),
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
    );
    assert_eq(HeaderBehavior::STICKY_SOFT, $image['behavior']);
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $image['topTreatment'], 'image opening denies only the transparent rung');
    assert_true(
        !in_array('header-top-transparent', HeaderBehavior::rootClasses($image), true),
        'no transparent hook without a proof',
    );
});

test('the pageBackground parameter joins the transparent-start contrast contract', function () {
    // Every opening is white, so 'base' alone would prove a transparent
    // start — but the page body behind the header is the dark secondary
    // token, which the near-black foreground cannot clear (~1.7:1).
    $palette = [
        'base' => '#FFFFFF',
        'contrast' => '#111111',
        'secondary' => '#3B3B3B',
    ];
    $fg = ContrastMath::hexToRgb($palette['contrast']);
    $pageBg = ContrastMath::hexToRgb($palette['secondary']);
    assert_true($fg !== null && $pageBg !== null);
    assert_true(ContrastMath::ratio($fg, $pageBg) < ContrastMath::NORMAL_TEXT);

    $default = HeaderBehavior::resolve(
        hb_pages('base', 'base'),
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
    );
    assert_eq(HeaderBehavior::TREATMENT_TRANSPARENT, $default['topTreatment'], 'base convention proves the start');

    $darkBody = HeaderBehavior::resolve(
        hb_pages('base', 'base'),
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
        'secondary',
    );
    assert_eq(HeaderBehavior::STICKY_SOFT, $darkBody['behavior']);
    assert_eq(
        HeaderBehavior::TREATMENT_GLASS,
        $darkBody['topTreatment'],
        'the passed page-background token must deny the transparent start',
    );
});

test('a mid-gray tint fails frosting at 0.80 alpha and falls back to solid paint', function () {
    // #767676 is the canonical 4.54:1 gray against white: the opaque pair is
    // safe, but its 0.80-alpha composites (#5E5E5E over black, #919191 over
    // white) leave white text below 4.5:1, so neither state may frost.
    $palette = [
        'base' => '#767676',
        'contrast' => '#FFFFFF',
    ];
    $fg = ContrastMath::hexToRgb($palette['contrast']);
    $tint = ContrastMath::hexToRgb($palette['base']);
    assert_true($fg !== null && $tint !== null);
    assert_true(ContrastMath::ratio($fg, $tint) >= ContrastMath::NORMAL_TEXT, 'the opaque pair itself is safe');
    assert_true(!HeaderBehavior::glassStateIsSafe($fg, $tint));

    $artifact = HeaderBehavior::resolve(
        hb_pages(),
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
    );
    assert_eq(HeaderBehavior::STICKY_SOFT, $artifact['behavior'], 'solid sticky chrome survives the denial');
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $artifact['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $artifact['scrolledTreatment']);
    foreach (HeaderBehavior::rootClasses($artifact) as $class) {
        assert_true(
            !str_starts_with($class, 'header-top-') && $class !== 'header-scrolled-glass',
            "unproven treatment class '{$class}' must not be emitted",
        );
    }
});

test('glass grants for the top and scrolled states are decided independently', function () {
    // Scrolled-only glass: the mid-gray top tint fails frosting while the
    // near-black scrolled tint passes it under the same white foreground.
    $scrolledOnly = HeaderBehavior::resolve(
        hb_pages(),
        HeaderBehavior::MODE_STACKED,
        ['base' => '#767676', 'contrast' => '#FFFFFF', 'secondary' => '#222222'],
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
    );
    assert_eq('base', $scrolledOnly['topSurface']);
    assert_eq('secondary', $scrolledOnly['scrolledSurface']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $scrolledOnly['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $scrolledOnly['scrolledTreatment']);
    $classes = HeaderBehavior::rootClasses($scrolledOnly);
    assert_true(in_array('header-scrolled-glass', $classes, true));
    assert_true(!in_array('header-top-glass', $classes, true));

    // Top-only glass: swapping which token is mid-gray flips the grant.
    $topOnly = HeaderBehavior::resolve(
        hb_pages(),
        HeaderBehavior::MODE_STACKED,
        ['base' => '#222222', 'contrast' => '#FFFFFF', 'secondary' => '#767676'],
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
    );
    assert_eq('base', $topOnly['topSurface']);
    assert_eq('secondary', $topOnly['scrolledSurface']);
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $topOnly['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $topOnly['scrolledTreatment']);
    $classes = HeaderBehavior::rootClasses($topOnly);
    assert_true(in_array('header-top-glass', $classes, true));
    assert_true(!in_array('header-scrolled-glass', $classes, true));
});

test('glassStateIsSafe matches the hand-computed 0.80-alpha composite segment', function () {
    // At GLASS_ALPHA the admitted segment per channel is exactly
    // round(0.8*tint) (over black) to round(0.8*tint + 51) (over white).
    assert_eq(0.80, HeaderBehavior::GLASS_ALPHA);

    // White foreground over a near-black tint (#111111): composites are
    // rgb(14,14,14) and rgb(65,65,65); white clears the light end at ~10:1.
    $white = [255, 255, 255];
    $darkTint = [17, 17, 17];
    assert_true(HeaderBehavior::glassStateIsSafe($white, $darkTint));
    assert_true(HeaderBehavior::transitionIsSafe($white, [14, 14, 14], [65, 65, 65]));

    // A mid-gray foreground whose own luminance sits inside the segment its
    // tint admits (composites rgb(94,94,94) and rgb(145,145,145) bracket
    // #767676) necessarily passes through 1:1 contrast — never safe.
    $midGray = [118, 118, 118];
    assert_true(!HeaderBehavior::glassStateIsSafe($midGray, $midGray));
    assert_true(!HeaderBehavior::transitionIsSafe($midGray, [94, 94, 94], [145, 145, 145]));

    // Dark foreground over the white tint: the worst composite is
    // rgb(204,204,204), the pale end rgb(255,255,255) — both readable.
    $ink = [17, 17, 17];
    assert_true(HeaderBehavior::glassStateIsSafe($ink, [255, 255, 255]));
    assert_true(HeaderBehavior::transitionIsSafe($ink, [204, 204, 204], [255, 255, 255]));
});

test('treatments ride the artifact across overlay, fallback, and static paths', function () {
    $palette = [
        'base' => '#FFFFFF',
        'contrast' => '#171717',
        'primary' => '#274C77',
        'secondary' => '#E5E7EB',
        'accent' => '#C2410C',
    ];

    // Overlay: scrim-veiled transparent start, opaque solid landing.
    $overlay = HeaderBehavior::resolve(
        [['slug' => 'home', 'sections' => [['slug' => 'hero', 'background' => 'image']]]],
        HeaderBehavior::MODE_OVERLAY,
        $palette,
    );
    assert_eq(HeaderBehavior::OVERLAY_TO_SOLID, $overlay['behavior']);
    assert_eq(HeaderBehavior::TREATMENT_TRANSPARENT, $overlay['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $overlay['scrolledTreatment']);
    $classes = HeaderBehavior::rootClasses($overlay);
    foreach ($classes as $class) {
        assert_true(
            !str_starts_with($class, 'header-top-') && $class !== 'header-scrolled-glass',
            'treatment hooks are sticky-only; the overlay kit owns its own states',
        );
    }

    // Overlay fallback: white passes the scrim but not its own 'base'
    // openings, and every darker token fails the scrim, so the stacked path
    // takes over — and must still resolve treatments for the sticky result.
    $fallback = HeaderBehavior::resolve(hb_pages('base', 'base'), HeaderBehavior::MODE_OVERLAY, $palette);
    assert_eq(HeaderBehavior::STICKY_SOFT, $fallback['behavior']);
    assert_eq(HeaderBehavior::MODE_STACKED, $fallback['mode']);
    assert_eq(HeaderBehavior::TREATMENT_TRANSPARENT, $fallback['topTreatment'], 'token openings still prove the start');
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $fallback['scrolledTreatment']);

    // Static: a shallow single page keeps solid paint and claims no classes.
    $static = HeaderBehavior::resolve(
        [['slug' => 'home', 'sections' => [['slug' => 'hero', 'background' => 'base']]]],
        HeaderBehavior::MODE_STACKED,
        $palette,
    );
    assert_eq(HeaderBehavior::STATIC, $static['behavior']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $static['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $static['scrolledTreatment']);
    assert_eq([], HeaderBehavior::rootClasses($static));
});

test('validateArtifact closes the treatment vocabulary per behavior', function () {
    $sticky = [
        'behavior' => 'sticky-soft',
        'mode' => 'stacked',
        'transition' => 'smooth',
        'topSurface' => 'base',
        'scrolledSurface' => 'secondary',
        'foreground' => 'contrast',
        'topTreatment' => 'glass',
        'scrolledTreatment' => 'glass',
    ];
    assert_eq($sticky, HeaderBehavior::validateArtifact($sticky));

    $unknownTop = $sticky;
    $unknownTop['topTreatment'] = 'frosted';
    assert_throws(static fn () => HeaderBehavior::validateArtifact($unknownTop));
    $unknownScrolled = $sticky;
    $unknownScrolled['scrolledTreatment'] = 'transparent';
    assert_throws(
        static fn () => HeaderBehavior::validateArtifact($unknownScrolled),
        'the scrolled state may never be transparent',
    );

    $static = [
        'behavior' => 'static',
        'mode' => 'stacked',
        'transition' => 'instant',
        'topSurface' => 'base',
        'scrolledSurface' => 'base',
        'foreground' => 'contrast',
        'topTreatment' => 'glass',
        'scrolledTreatment' => 'solid',
    ];
    assert_throws(static fn () => HeaderBehavior::validateArtifact($static), 'static must stay solid/solid');
    $staticGlassScrolled = $static;
    $staticGlassScrolled['topTreatment'] = 'solid';
    $staticGlassScrolled['scrolledTreatment'] = 'glass';
    assert_throws(static fn () => HeaderBehavior::validateArtifact($staticGlassScrolled));

    $overlay = [
        'behavior' => 'overlay-to-solid',
        'mode' => 'overlay',
        'transition' => 'smooth',
        'topSurface' => 'transparent',
        'scrolledSurface' => 'contrast',
        'foreground' => 'base',
        'topTreatment' => 'transparent',
        'scrolledTreatment' => 'solid',
    ];
    assert_eq($overlay, HeaderBehavior::validateArtifact($overlay));
    $overlayGlassTop = $overlay;
    $overlayGlassTop['topTreatment'] = 'glass';
    assert_throws(
        static fn () => HeaderBehavior::validateArtifact($overlayGlassTop),
        'overlay requires a transparent top treatment',
    );
    $overlayGlassScrolled = $overlay;
    $overlayGlassScrolled['scrolledTreatment'] = 'glass';
    assert_throws(
        static fn () => HeaderBehavior::validateArtifact($overlayGlassScrolled),
        'overlay requires a solid scrolled treatment',
    );
});
