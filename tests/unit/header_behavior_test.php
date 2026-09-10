<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\HeaderChrome;
use Automattic\SiteBuild\Steps\AssemblePagesStep;

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
        chrome: HeaderChrome::PERSISTENT,
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
        chrome: HeaderChrome::PERSISTENT,
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
        HeaderBehavior::behaviorFor($pages, HeaderBehavior::MODE_STACKED, chrome: HeaderChrome::PERSISTENT),
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
        chrome: HeaderChrome::PERSISTENT,
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
        chrome: HeaderChrome::PERSISTENT,
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
        chrome: HeaderChrome::PERSISTENT,
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
        chrome: HeaderChrome::PERSISTENT,
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
        chrome: HeaderChrome::PERSISTENT,
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
        chrome: HeaderChrome::PERSISTENT,
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
        chrome: HeaderChrome::PERSISTENT,
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
        chrome: HeaderChrome::PERSISTENT,
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
        chrome: HeaderChrome::PERSISTENT,
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

    // Overlay: plan-time resolve rests behind the kit scrim (a glass
    // treatment); the truly clear start is earned later from delivered
    // opening evidence, never assumed here.
    $overlay = HeaderBehavior::resolve(
        hb_pages('image', 'image'),
        HeaderBehavior::MODE_OVERLAY,
        $palette,
        chrome: HeaderChrome::PERSISTENT,
    );
    assert_eq(HeaderBehavior::OVERLAY_TO_SOLID, $overlay['behavior']);
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $overlay['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $overlay['scrolledTreatment']);
    $classes = HeaderBehavior::rootClasses($overlay);
    foreach ($classes as $class) {
        assert_true(
            !str_starts_with($class, 'header-top-') && $class !== 'header-scrolled-glass',
            'the scrim-veiled overlay start is kit-automatic and claims no treatment hook',
        );
    }

    // The earned clear start is the one overlay treatment hook: it names the
    // proven scrim-free resting state for the kit CSS.
    $clear = $overlay;
    $clear['topTreatment'] = HeaderBehavior::TREATMENT_TRANSPARENT;
    assert_true(
        in_array('header-top-transparent', HeaderBehavior::rootClasses($clear), true),
        'an earned clear overlay start opts in explicitly',
    );

    // Overlay fallback: white passes the scrim but not its own 'base'
    // openings, and every darker token fails the scrim, so the stacked path
    // takes over — and must still resolve treatments for the sticky result.
    $fallback = HeaderBehavior::resolve(hb_pages('base', 'base'), HeaderBehavior::MODE_OVERLAY, $palette, chrome: HeaderChrome::PERSISTENT);
    assert_eq(HeaderBehavior::STICKY_SOFT, $fallback['behavior']);
    assert_eq(HeaderBehavior::MODE_STACKED, $fallback['mode']);
    assert_eq(HeaderBehavior::TREATMENT_TRANSPARENT, $fallback['topTreatment'], 'token openings still prove the start');
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $fallback['scrolledTreatment']);

    // Static: a shallow single page keeps solid paint and claims no classes.
    $static = HeaderBehavior::resolve(
        [['slug' => 'home', 'sections' => [['slug' => 'hero', 'background' => 'base']]]],
        HeaderBehavior::MODE_STACKED,
        $palette,
        chrome: HeaderChrome::PERSISTENT,
    );
    assert_eq(HeaderBehavior::STATIC, $static['behavior']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $static['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $static['scrolledTreatment']);
    assert_eq([], HeaderBehavior::rootClasses($static));
});

test('the design direction decides persistent header chrome in both modes (BIGR-998)', function () {
    // The cohort that motivated BIGR-998 held 19 sticky-soft, 17
    // overlay-to-solid and 0 static headers, because the old rule read only
    // the site shape. The shape is still an input, but the direction now
    // chooses, and each mode keeps its own paint family.
    $deep = hb_pages('base', 'base');
    $deepImages = hb_pages('image', 'image');

    assert_eq(
        HeaderBehavior::STICKY_SOFT,
        HeaderBehavior::behaviorFor($deep, HeaderBehavior::MODE_STACKED, chrome: HeaderChrome::PERSISTENT),
    );
    assert_eq(
        HeaderBehavior::STATIC,
        HeaderBehavior::behaviorFor($deep, HeaderBehavior::MODE_STACKED, chrome: HeaderChrome::TRANSIENT),
    );
    assert_eq(
        HeaderBehavior::OVERLAY_TO_SOLID,
        HeaderBehavior::behaviorFor($deepImages, HeaderBehavior::MODE_OVERLAY, chrome: HeaderChrome::PERSISTENT),
    );
    assert_eq(
        HeaderBehavior::OVERLAY_TRANSIENT,
        HeaderBehavior::behaviorFor($deepImages, HeaderBehavior::MODE_OVERLAY, chrome: HeaderChrome::TRANSIENT),
    );

    // An absent or unusable commitment delivers the transient default rather
    // than the persistent chrome every site used to receive.
    assert_eq(HeaderBehavior::STATIC, HeaderBehavior::behaviorFor($deep, HeaderBehavior::MODE_STACKED));
    assert_eq(
        HeaderBehavior::STATIC,
        HeaderBehavior::behaviorFor($deep, HeaderBehavior::MODE_STACKED, chrome: 'always-on'),
    );
    assert_eq(
        HeaderBehavior::OVERLAY_TRANSIENT,
        HeaderBehavior::behaviorFor($deepImages, HeaderBehavior::MODE_OVERLAY, chrome: null),
    );
});

test('tall archetypes and shallow sites veto persistent chrome the direction asked for (BIGR-998)', function () {
    $deep = hb_pages('base', 'base');
    $deepImages = hb_pages('image', 'image');

    // Veto one: a tall composition is not useful chrome, in either mode.
    foreach (HeaderBehavior::TALL_ARCHETYPES as $tall) {
        assert_eq(
            HeaderBehavior::STATIC,
            HeaderBehavior::behaviorFor($deep, HeaderBehavior::MODE_STACKED, $tall, HeaderChrome::PERSISTENT),
            "{$tall} is too tall to stay on screen",
        );
        assert_eq(
            HeaderBehavior::OVERLAY_TRANSIENT,
            HeaderBehavior::behaviorFor($deepImages, HeaderBehavior::MODE_OVERLAY, $tall, HeaderChrome::PERSISTENT),
        );
    }

    // Veto two: one page of two bands has nothing for chrome to repay.
    $shallow = [['slug' => 'home', 'sections' => [
        ['slug' => 'hero', 'background' => 'image'],
        ['slug' => 'about', 'background' => 'base'],
    ]]];
    assert_true(!HeaderBehavior::depthSupportsChrome($shallow));
    assert_eq(
        HeaderBehavior::STATIC,
        HeaderBehavior::behaviorFor($shallow, HeaderBehavior::MODE_STACKED, null, HeaderChrome::PERSISTENT),
    );
    assert_eq(
        HeaderBehavior::OVERLAY_TRANSIENT,
        HeaderBehavior::behaviorFor($shallow, HeaderBehavior::MODE_OVERLAY, null, HeaderChrome::PERSISTENT),
    );

    // The floor lifts at four bands on one page, and at a second page.
    $fourBands = [['slug' => 'home', 'sections' => [
        ['slug' => 'hero'], ['slug' => 'about'], ['slug' => 'work'], ['slug' => 'contact'],
    ]]];
    assert_true(HeaderBehavior::depthSupportsChrome($fourBands));
    assert_true(HeaderBehavior::depthSupportsChrome($deep));
    assert_eq(
        HeaderBehavior::STICKY_SOFT,
        HeaderBehavior::behaviorFor($fourBands, HeaderBehavior::MODE_STACKED, null, HeaderChrome::PERSISTENT),
    );
});

test('a design choice never overrides the header contrast guarantee (BIGR-998)', function () {
    $deep = hb_pages('base', 'base');
    // Every token sits near mid-gray, so no pair reaches 4.5:1 and the two
    // painted states cannot both stay readable.
    $midTones = [
        'base' => '#7F7F7F',
        'contrast' => '#8A8A8A',
        'primary' => '#757575',
        'secondary' => '#808080',
        'accent' => '#8F8F8F',
    ];
    $stacked = HeaderBehavior::resolve(
        $deep,
        HeaderBehavior::MODE_STACKED,
        $midTones,
        chrome: HeaderChrome::PERSISTENT,
    );
    assert_eq(
        HeaderBehavior::STATIC,
        $stacked['behavior'],
        'an unsafe palette still downgrades sticky-soft, whatever the direction asked for',
    );

    // The overlay palette fallback keeps its shape: an overlay that cannot
    // prove one foreground re-resolves on the stacked path, and the chrome
    // commitment rides along to that second decision.
    $palette = [
        'base' => '#FFFFFF',
        'contrast' => '#171717',
        'primary' => '#274C77',
        'secondary' => '#E5E7EB',
        'accent' => '#C2410C',
    ];
    $persistentFallback = HeaderBehavior::resolve(
        $deep,
        HeaderBehavior::MODE_OVERLAY,
        $palette,
        chrome: HeaderChrome::PERSISTENT,
    );
    assert_eq(HeaderBehavior::STICKY_SOFT, $persistentFallback['behavior']);
    assert_eq(HeaderBehavior::MODE_STACKED, $persistentFallback['mode']);

    $transientFallback = HeaderBehavior::resolve(
        $deep,
        HeaderBehavior::MODE_OVERLAY,
        $palette,
        chrome: HeaderChrome::TRANSIENT,
    );
    assert_eq(HeaderBehavior::STATIC, $transientFallback['behavior']);
    assert_eq(HeaderBehavior::MODE_STACKED, $transientFallback['mode']);
});

test('overlay-transient carries the overlay contract and claims no fixed shell (BIGR-998)', function () {
    $palette = [
        'base' => '#FFFFFF',
        'contrast' => '#171717',
        'primary' => '#274C77',
        'secondary' => '#E5E7EB',
        'accent' => '#C2410C',
    ];
    $artifact = HeaderBehavior::resolve(
        hb_pages('image', 'image'),
        HeaderBehavior::MODE_OVERLAY,
        $palette,
        chrome: HeaderChrome::TRANSIENT,
    );
    assert_eq(HeaderBehavior::OVERLAY_TRANSIENT, $artifact['behavior']);
    assert_eq(HeaderBehavior::MODE_OVERLAY, $artifact['mode']);
    assert_eq(HeaderBehavior::TRANSPARENT, $artifact['topSurface']);
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $artifact['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_SOLID, $artifact['scrolledTreatment']);
    assert_true(
        in_array($artifact['scrolledSurface'], HeaderBehavior::SURFACES, true),
        'the scrolled pair stays proven for the no-JS and forced-solid shells',
    );

    // Both overlay behaviors paint through one class family, so the inner
    // markup is identical and only the outer shell class differs.
    $persistent = HeaderBehavior::resolve(
        hb_pages('image', 'image'),
        HeaderBehavior::MODE_OVERLAY,
        $palette,
        chrome: HeaderChrome::PERSISTENT,
    );
    assert_eq(HeaderBehavior::OVERLAY_TO_SOLID, $persistent['behavior']);
    assert_eq(HeaderBehavior::rootClasses($persistent), HeaderBehavior::rootClasses($artifact));
    assert_true(in_array('header-behavior-overlay', HeaderBehavior::rootClasses($artifact), true));

    // The earned clear resting state is available to both.
    $clear = $artifact;
    $clear['topTreatment'] = HeaderBehavior::TREATMENT_TRANSPARENT;
    assert_true(in_array('header-top-transparent', HeaderBehavior::rootClasses($clear), true));

    assert_contains('overlay-transient', HeaderBehavior::promptContract(HeaderBehavior::OVERLAY_TRANSIENT));
    assert_contains('scrolls away', HeaderBehavior::promptContract(HeaderBehavior::OVERLAY_TRANSIENT));
    assert_eq(
        'site-header-shell site-header-shell--overlay-transient',
        AssemblePagesStep::pageHeaderClassName(HeaderBehavior::OVERLAY_TRANSIENT),
    );
    assert_eq(
        'site-header-shell site-header-shell--force-solid',
        AssemblePagesStep::indexHeaderClassName(HeaderBehavior::OVERLAY_TRANSIENT),
        'the blog fallback has no image-led opening to rest on',
    );
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
    assert_eq($overlayGlassTop, HeaderBehavior::validateArtifact($overlayGlassTop), 'the scrim veil is a glass top');
    $overlaySolidTop = $overlay;
    $overlaySolidTop['topTreatment'] = 'solid';
    assert_throws(
        static fn () => HeaderBehavior::validateArtifact($overlaySolidTop),
        'overlay tops are veiled or earned-clear, never opaque',
    );
    $overlayGlassScrolled = $overlay;
    $overlayGlassScrolled['scrolledTreatment'] = 'glass';
    assert_throws(
        static fn () => HeaderBehavior::validateArtifact($overlayGlassScrolled),
        'overlay requires a solid scrolled treatment',
    );
});

test('the clear overlay resting state is earned from the cover dim, worst case included (BIGR-778)', function () {
    $white = [255, 255, 255];
    $ink = [23, 26, 29];

    // A 60% dark dim bounds a pure-white pixel to a readable composite; 50%
    // does not. The minimal grant is therefore exactly 60.
    assert_true(HeaderBehavior::clearOverlayTopIsSafe($white, $ink, 60.0, $ink, false));
    assert_true(!HeaderBehavior::clearOverlayTopIsSafe($white, $ink, 50.0, $ink, false));
    assert_eq(60, HeaderBehavior::minimalClearOverlayDim($white, $ink, $ink, false));

    // Core renders dimRatio through a 10-point class. The proof must judge
    // that delivered opacity rather than a slightly more favorable authored
    // fraction, and values outside Core's class range cannot earn a grant.
    assert_eq(50, HeaderBehavior::renderedCoverDim(54.0));
    assert_eq(60, HeaderBehavior::renderedCoverDim(56.0));
    assert_true(!HeaderBehavior::clearOverlayTopIsSafe($white, [0, 0, 0], 54.0, $ink, false));
    assert_true(HeaderBehavior::clearOverlayTopIsSafe($white, [0, 0, 0], 56.0, $ink, false));
    assert_eq(null, HeaderBehavior::renderedCoverDim(-1.0));
    assert_eq(null, HeaderBehavior::renderedCoverDim(101.0));

    // #222 needs more than the 60% class. Authored 62% previously passed the
    // raw math but serializes to 60%; the canonical safe repair is 70%.
    $softInk = [34, 34, 34];
    assert_true(!HeaderBehavior::clearOverlayTopIsSafe($white, $softInk, 62.0, $softInk, false));
    assert_eq(70, HeaderBehavior::minimalClearOverlayDim($white, $softInk, $softInk, false));

    // Smooth transitions additionally prove the whole path into the
    // scrolled solid; a same-family dark landing keeps the grant.
    assert_true(HeaderBehavior::clearOverlayTopIsSafe($white, $ink, 60.0, $ink, true));

    // A mid-gray protection can never bound a white pixel readably at any
    // dim the cap allows: no grant, the scrim veil stays.
    assert_eq(null, HeaderBehavior::minimalClearOverlayDim($white, [102, 102, 102], $ink, false));

    // A full-opacity "dim" is the solid-opening case: the clear state
    // reveals the protection surface itself.
    assert_true(HeaderBehavior::clearOverlayTopIsSafe($white, $ink, 100.0, $ink, false));
    assert_true(!HeaderBehavior::clearOverlayTopIsSafe([23, 26, 29], $ink, 100.0, $ink, false));
});
