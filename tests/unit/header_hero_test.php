<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\HeaderHeroStep;

/**
 * Unit tests for HeaderHeroStep: the deterministic backstop for the
 * header/hero composition contract (BIGR-735) and the resolved header
 * behavior artifact (BIGR-762).
 */

/**
 * Resolve and persist a delivery-phase contract for a step fixture, so the
 * step exercises the same two-phase flow the pipeline runs.
 *
 * @param array<int,array<string,mixed>> $pages
 * @param array<string,mixed>|null $theme themeContext for token verification
 */
function hh_above_fold($project, array $pages, string $recipe = 'focal-subject-stage', ?array $theme = null, ?string $forced = null): array
{
    $delivery = AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor($recipe),
        'full-bleed',
        $theme ?? hh_theme_json(),
        ['stable_id' => 'hh-step-fixture', 'writing_direction' => 'ltr', 'page_count' => count($pages)],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        $forced,
    );
    $project->writeJson('aboveFold.json', $delivery);
    return $delivery;
}


/** A minimal stacked-style header part with the given top-level attrs JSON. */
function hh_header(string $topAttrs, string $inner = '<!-- wp:site-title /-->'): string
{
    return '<!-- wp:group ' . $topAttrs . ' -->' . "\n"
        . '<div class="wp-block-group">' . $inner . '</div>' . "\n"
        . '<!-- /wp:group -->';
}

/** A hero part holding one cover with the given minHeight in vh. */
function hh_cover(string $height): string
{
    return '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group">'
        . '<!-- wp:cover {"url":"x.jpg","dimRatio":50,"minHeight":' . $height . ',"minHeightUnit":"vh","align":"full"} -->'
        . '<div class="wp-block-cover alignfull" style="min-height:' . $height . 'vh"></div>'
        . '<!-- /wp:cover --></div>' . "\n"
        . '<!-- /wp:group -->';
}

/** Required semantic palette, as both slug map and theme.json entries. */
function hh_palette(): array
{
    return [
        'base' => '#FFFFFF',
        'contrast' => '#171717',
        'primary' => '#274C77',
        'secondary' => '#E5E7EB',
        'accent' => '#C2410C',
    ];
}

function hh_theme_json(): array
{
    $palette = [];
    foreach (hh_palette() as $slug => $color) {
        $palette[] = ['slug' => $slug, 'name' => ucfirst($slug), 'color' => $color];
    }
    return ['version' => 3, 'settings' => ['color' => ['palette' => $palette]]];
}


test('overlay-to-solid removes legacy inner positioning and wires closed state classes', function () {
    $markup = hh_header('{"backgroundColor":"base","style":{"position":{"type":"sticky","top":"0px"},"spacing":{"padding":{"top":"var:preset|spacing|sm"}}},"layout":{"type":"constrained"}}');
    $behavior = [
        'behavior' => HeaderBehavior::OVERLAY_TO_SOLID,
        'mode' => HeaderBehavior::MODE_OVERLAY,
        'transition' => HeaderBehavior::TRANSITION_SMOOTH,
        'topSurface' => HeaderBehavior::TRANSPARENT,
        'scrolledSurface' => 'contrast',
        'foreground' => 'base',
        'topTreatment' => HeaderBehavior::TREATMENT_TRANSPARENT,
        'scrolledTreatment' => HeaderBehavior::TREATMENT_SOLID,
    ];
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_OVERLAY, 'Demo', [], false, behavior: $behavior);

    assert_contains('header-behavior-overlay-to-solid', $result['markup']);
    assert_contains('header-start-transparent', $result['markup']);
    assert_contains('header-scrolled-contrast', $result['markup']);
    assert_contains('header-foreground-base', $result['markup']);
    assert_true(!str_contains($result['markup'], 'header-overlay'), 'legacy absolute-positioning hook removed');
    assert_true(!str_contains($result['markup'], 'backgroundColor'), 'opaque background removed');
    assert_true(!str_contains($result['markup'], 'sticky'), 'sticky removed — the overlay floats');
    assert_contains('"textColor":"base"', $result['markup']);
    assert_contains('"spacing"', $result['markup'], 'unrelated style survives');

    // Idempotent: a compliant overlay header is untouched.
    $again = HeaderHeroStep::fixHeader($result['markup'], AboveFoldContract::MODE_OVERLAY, 'Demo', [], false, behavior: $behavior);
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['notes']);
});

test('nested header Groups neutralize Atlas-style global vertical padding', function () {
    $theme = ['styles' => ['blocks' => ['core/group' => ['spacing' => ['padding' => [
        'top' => 'var:preset|spacing|xl',
        'bottom' => 'var:preset|spacing|xl',
    ]]]]]];
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|md"}},"layout":{"type":"flex"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}},"layout":{"type":"flex"}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->'
        . '<!-- wp:navigation /--></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderHeroStep::fixHeader(
        $markup,
        AboveFoldContract::MODE_STACKED,
        'Atlas Field',
        [],
        false, behavior: null, theme: $theme);
    $doc = BlockMarkup::parse($result['markup']);
    $groups = array_values(array_filter($doc->indices(), static fn (int $i): bool => $doc->name($i) === 'group'));
    assert_eq(3, count($groups));
    $rootPadding = ($doc->attrs($groups[0]) ?? [])['style']['spacing']['padding'];
    assert_eq('var:preset|spacing|sm', $rootPadding['top'], 'root breathing room survives');
    assert_eq('var:preset|spacing|sm', $rootPadding['bottom']);
    foreach (array_slice($groups, 1) as $group) {
        $attrs = $doc->attrs($group) ?? [];
        assert_eq('0', $attrs['style']['spacing']['padding']['top']);
        assert_eq('0', $attrs['style']['spacing']['padding']['bottom']);
        assert_true(isset($attrs['style']['spacing']['blockGap']), 'existing descendant gap survives');
    }
    assert_contains('2 descendant header groups', implode(' ', $result['notes']));

    $again = HeaderHeroStep::fixHeader(
        $result['markup'],
        AboveFoldContract::MODE_STACKED,
        'Atlas Field',
        [],
        false, behavior: null, theme: $theme);
    assert_eq($result['markup'], $again['markup'], 'repair reaches a fixed point');
    assert_eq([], $again['notes']);
});

test('nested Group repair preserves explicit strip and partial-side padding', function () {
    $theme = ['styles' => ['blocks' => ['core/group' => ['spacing' => ['padding' => [
        'top' => 'var:preset|spacing|xl',
        'bottom' => 'var:preset|spacing|xl',
    ]]]]]];
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm"}}}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm"}},"border":{"bottom":{"width":"1px"}}}} -->'
        . '<div class="wp-block-group"><!-- wp:site-tagline /--></div><!-- /wp:group -->'
        . '<!-- wp:group {"style":{"spacing":{"padding":{"bottom":"var:preset|spacing|sm"}}},"layout":{"type":"flex"}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /--><!-- wp:navigation /--></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', [], false, behavior: null, theme: $theme);
    $doc = BlockMarkup::parse($result['markup']);
    $groups = array_values(array_filter($doc->indices(), static fn (int $i): bool => $doc->name($i) === 'group'));
    $strip = $doc->attrs($groups[1]) ?? [];
    assert_eq('var:preset|spacing|sm', $strip['style']['spacing']['padding']['top']);
    assert_eq('var:preset|spacing|sm', $strip['style']['spacing']['padding']['bottom']);
    assert_eq('1px', $strip['style']['border']['bottom']['width'], 'strip decoration survives');
    $row = $doc->attrs($groups[2]) ?? [];
    assert_eq('0', $row['style']['spacing']['padding']['top'], 'missing inherited side neutralized');
    assert_eq('var:preset|spacing|sm', $row['style']['spacing']['padding']['bottom'], 'explicit side survives');
});

test('nested Group repair skips malformed containers and continues with healthy siblings', function () {
    $theme = ['styles' => ['blocks' => ['core/group' => ['spacing' => ['padding' => [
        'top' => 'var:preset|spacing|xl',
        'bottom' => 'var:preset|spacing|xl',
    ]]]]]];
    $markup = '<!-- wp:group {} --><div class="wp-block-group">'
        . '<!-- wp:group {"style":"bad"} --><div class="wp-block-group"></div><!-- /wp:group -->'
        . '<!-- wp:group {"style":{"spacing":"bad"}} --><div class="wp-block-group"></div><!-- /wp:group -->'
        . '<!-- wp:group {"style":{"spacing":{"padding":["bad"]}}} --><div class="wp-block-group"></div><!-- /wp:group -->'
        . '<!-- wp:group {"layout":{"type":"flex"}} --><div class="wp-block-group">'
        . '<!-- wp:site-title /--></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', [], false, behavior: null, theme: $theme);
    $doc = BlockMarkup::parse($result['markup']);
    $groups = array_values(array_filter($doc->indices(), static fn (int $i): bool => $doc->name($i) === 'group'));
    assert_eq('bad', ($doc->attrs($groups[1]) ?? [])['style']);
    assert_eq('bad', ($doc->attrs($groups[2]) ?? [])['style']['spacing']);
    assert_eq(['bad'], ($doc->attrs($groups[3]) ?? [])['style']['spacing']['padding']);
    $healthyPadding = ($doc->attrs($groups[4]) ?? [])['style']['spacing']['padding'];
    assert_eq('0', $healthyPadding['top']);
    assert_eq('0', $healthyPadding['bottom']);
    assert_contains('1 descendant header group', implode(' ', $result['notes']));
});

test('stacked mode removes a stray header-overlay class from attrs and saved HTML', function () {
    $markup = '<!-- wp:group {"className":"header-overlay","layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group header-overlay"><!-- wp:site-title /--></div>' . "\n"
        . '<!-- /wp:group -->';
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');

    assert_true(!str_contains($result['markup'], 'header-overlay'), 'class gone from attrs AND html');
    assert_eq(1, count($result['notes']));
});

test('position cleanup moves root persistence but preserves descendant relative layout', function () {
    $behavior = [
        'behavior' => HeaderBehavior::STICKY_SOFT,
        'mode' => HeaderBehavior::MODE_STACKED,
        'transition' => HeaderBehavior::TRANSITION_SMOOTH,
        'topSurface' => 'base',
        'scrolledSurface' => 'secondary',
        'foreground' => 'contrast',
        'topTreatment' => HeaderBehavior::TREATMENT_SOLID,
        'scrolledTreatment' => HeaderBehavior::TREATMENT_SOLID,
    ];
    $markup = '<!-- wp:group {"backgroundColor":"base","textColor":"contrast","style":{"position":{"type":"sticky","top":"0px"}}} -->'
        . '<div class="wp-block-group" style="position:sticky;padding-top:1rem">'
        . '<!-- wp:group {"style":{"position":{"type":"relative"}}} -->'
        . '<div class="wp-block-group" style="position:relative"><!-- wp:site-title /--></div>'
        . '<!-- /wp:group --></div><!-- /wp:group -->';

    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', [], false, behavior: $behavior);
    assert_true(!str_contains($result['markup'], 'position:sticky'), 'root inline persistence moved to the shell');
    assert_true(!str_contains($result['markup'], '"type":"sticky"'), 'root block position removed');
    assert_contains('position:relative', $result['markup'], 'descendant inline layout survives');
    assert_contains('"position":{"type":"relative"}', $result['markup'], 'descendant block layout survives');
    assert_contains('padding-top:1rem', $result['markup'], 'neighboring root declaration survives');
});

test('inline position cleanup never rewrites visible text content', function () {
    // A paragraph whose copy literally mentions style="position:fixed" is
    // text, not authored positioning; only the real descendant tag's
    // persistent inline declaration may be stripped.
    $copy = 'Use style="position:fixed" to pin banners.';
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group {"layout":{"type":"flex"}} -->'
        . '<div class="wp-block-group" style="position:sticky;top:0">'
        . '<!-- wp:paragraph --><p>' . $copy . '</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');
    assert_contains($copy, $result['markup'], 'visible copy survives verbatim');
    assert_true(
        !str_contains($result['markup'], 'style="position:sticky'),
        'real descendant persistent inline declaration is stripped',
    );
    assert_contains('style="top:0"', $result['markup'], 'neighboring declaration survives');
});

test('a leading non-block comment does not shield the real root from position cleanup', function () {
    // LLM output sometimes opens with a plain HTML comment. The root region
    // must still be the first actual block, whose inline absolute
    // positioning belongs to the outer shell.
    $markup = "<!-- generated note -->\n"
        . '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="position:absolute;top:0">'
        . '<!-- wp:site-title /--></div>'
        . '<!-- /wp:group -->';

    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');
    assert_contains('<!-- generated note -->', $result['markup'], 'leading comment survives');
    assert_true(
        !str_contains($result['markup'], 'position:absolute'),
        'root inline absolute positioning is stripped despite the leading comment',
    );
    assert_contains('style="top:0"', $result['markup'], 'neighboring root declaration survives');
    assert_contains(
        'removed inline position declaration',
        implode(' ', $result['notes']),
    );
});

test('a display-scale site title is lowered to heading, syncing the saved HTML', function () {
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title {"fontSize":"section-title"} /-->'
    );
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');
    assert_contains('"fontSize":"heading"', $result['markup']);
    assert_contains("lowered to 'heading'", implode(' ', $result['notes']));

    // The one sanctioned exception: a forced oversized-wordmark build.
    $forced = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', [], true);
    assert_contains('"fontSize":"section-title"', $forced['markup']);
    assert_eq([], $forced['notes']);
});

test('an over-wide nav row collapses to overlayMenu:always instead of wrapping', function () {
    $nav = '<!-- wp:navigation {"fontSize":"caption"} -->'
        . '<!-- wp:navigation-link {"label":"Programación"} /-->'
        . '<!-- wp:navigation-link {"label":"Instalaciones"} /-->'
        . '<!-- wp:navigation-link {"label":"Talleres"} /-->'
        . '<!-- wp:navigation-link {"label":"Ubicación"} /-->'
        . '<!-- wp:navigation-link {"label":"Accesibilidad"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link">Entradas</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->';
    $markup = hh_header('{"backgroundColor":"base","layout":{"type":"constrained"}}', '<!-- wp:site-title /-->' . $nav);
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Pulso Sur Centro Cultural');

    assert_contains('"overlayMenu":"always"', $result['markup']);
    assert_contains('overlayMenu:always', implode(' ', $result['notes']));

    // A short three-item nav fits the row and is untouched.
    $short = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu"} /--><!-- wp:navigation-link {"label":"Visit"} /-->'
        . '<!-- /wp:navigation -->'
    );
    $fits = HeaderHeroStep::fixHeader($short, AboveFoldContract::MODE_STACKED, 'Demo');
    assert_eq($short, $fits['markup']);
});

test('a page-list nav is measured by the site page titles', function () {
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->'
    );
    $longTitles = ['Our Seasonal Tasting Menu', 'Private Dining and Events', 'Reservations and Contact', 'The Story of the House'];
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', $longTitles);
    assert_contains('"overlayMenu":"always"', $result['markup']);

    $fits = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', ['Menu', 'Visit']);
    assert_eq($markup, $fits['markup']);
});

test('estimatedRowWidth charges a button its width plus the cluster gap', function () {
    $nav = '<!-- wp:navigation-link {"label":"Home"} /-->';
    $button = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link">Go</a></div><!-- /wp:button -->';

    $without = HeaderHeroStep::estimatedRowWidth(BlockMarkup::parse(hh_header('{"layout":{"type":"constrained"}}', $nav)), 'Demo');
    $with    = HeaderHeroStep::estimatedRowWidth(BlockMarkup::parse(hh_header('{"layout":{"type":"constrained"}}', $nav . $button)), 'Demo');

    // BUTTON_PAD_PX (56) + 2 label chars * BUTTON_CHAR_PX (9) + CLUSTER_GAP_PX (32).
    // The gap term is the regression this pins: the button branch set a
    // variable the gap check never read, so every header with a button was
    // underestimated by the cluster gap.
    assert_eq(56 + 2 * 9 + 32, $with - $without);
});

test('capCovers lowers a viewport-scale cover to 80vh and leaves the rest alone', function () {
    $result = HeaderHeroStep::capCovers(hh_cover('92'));
    assert_contains('"minHeight":80', $result['markup']);
    assert_eq(1, count($result['notes']));

    // 80vh already fits beside a stacked header; px heights are not viewport math.
    $ok = hh_cover('80');
    assert_eq($ok, HeaderHeroStep::capCovers($ok)['markup']);
    $px = str_replace('"minHeightUnit":"vh"', '"minHeightUnit":"px"', hh_cover('600'));
    assert_eq($px, HeaderHeroStep::capCovers($px)['markup']);
});

test('header behavior selection uses site depth and excludes forced tall chrome', function () {
    $short = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'hero'],
            ['slug' => 'about'],
            ['slug' => 'contact'],
        ],
    ]];
    assert_eq(HeaderBehavior::STATIC, HeaderBehavior::behaviorFor($short, HeaderBehavior::MODE_STACKED));

    $long = $short;
    $long[0]['sections'][] = ['slug' => 'faq'];
    assert_eq(HeaderBehavior::STICKY_SOFT, HeaderBehavior::behaviorFor($long, HeaderBehavior::MODE_STACKED));
    assert_eq(
        HeaderBehavior::STATIC,
        HeaderBehavior::behaviorFor($long, HeaderBehavior::MODE_STACKED, 'centered-masthead'),
    );

    $multi = [$short[0], ['slug' => 'about', 'sections' => [['slug' => 'intro']]]];
    assert_eq(HeaderBehavior::STICKY_SOFT, HeaderBehavior::behaviorFor($multi, HeaderBehavior::MODE_STACKED));
    assert_eq(
        HeaderBehavior::OVERLAY_TO_SOLID,
        HeaderBehavior::behaviorFor($short, HeaderBehavior::MODE_OVERLAY),
    );
});

test('resolver keeps one readable foreground across a subtle sticky surface change', function () {
    $pages = [
        ['slug' => 'home', 'sections' => [['slug' => 'hero']]],
        ['slug' => 'about', 'sections' => [['slug' => 'intro']]],
    ];
    $artifact = HeaderBehavior::resolve(
        $pages,
        HeaderBehavior::MODE_STACKED,
        hh_palette(),
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
    );
    assert_eq(HeaderBehavior::STICKY_SOFT, $artifact['behavior']);
    assert_eq('base', $artifact['topSurface']);
    assert_eq('secondary', $artifact['scrolledSurface'], 'nearest distinct safe surface is the soft neutral');
    assert_eq('contrast', $artifact['foreground']);
    foreach ([$artifact['topSurface'], $artifact['scrolledSurface']] as $surface) {
        $fg = ContrastMath::hexToRgb(hh_palette()[$artifact['foreground']]);
        $bg = ContrastMath::hexToRgb(hh_palette()[$surface]);
        assert_true($fg !== null && $bg !== null && ContrastMath::ratio($fg, $bg) >= 4.5);
    }

    // The openings carry no token-backed background, so a transparent start
    // is unverifiable — but both tints survive frosting at GLASS_ALPHA: the
    // near-black foreground still clears each tint's worst-case composite
    // (white at 0.80 over black is #CCCCCC, an ~11:1 pair), so the resolver
    // legitimately grants the glass/glass ladder rung.
    $fg = ContrastMath::hexToRgb(hh_palette()['contrast']);
    foreach (['base', 'secondary'] as $tintSlug) {
        $tint = ContrastMath::hexToRgb(hh_palette()[$tintSlug]);
        assert_true($fg !== null && $tint !== null && HeaderBehavior::glassStateIsSafe($fg, $tint));
    }
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $artifact['topTreatment']);
    assert_eq(HeaderBehavior::TREATMENT_GLASS, $artifact['scrolledTreatment']);
    assert_eq(
        [
            'header-behavior-sticky-soft',
            'header-start-base',
            'header-scrolled-secondary',
            'header-foreground-contrast',
            'header-top-glass',
            'header-scrolled-glass',
        ],
        HeaderBehavior::rootClasses($artifact),
    );
});

test('overlay resolver proves the scrim and every planned solid opening before enabling persistence', function () {
    $pages = [
        ['slug' => 'home', 'sections' => [[
            'slug' => 'hero', 'background' => 'image',
        ]]],
        ['slug' => 'about', 'sections' => [[
            'slug' => 'intro', 'background' => 'contrast',
        ]]],
    ];
    $normal = HeaderBehavior::resolve($pages, HeaderBehavior::MODE_OVERLAY, hh_palette());
    assert_eq(HeaderBehavior::OVERLAY_TO_SOLID, $normal['behavior']);
    $foreground = ContrastMath::hexToRgb(hh_palette()[$normal['foreground']]);
    assert_true($foreground !== null);
    assert_true(
        ContrastMath::ratio($foreground, HeaderBehavior::OVERLAY_WORST_CASE_RGB) >= ContrastMath::NORMAL_TEXT,
        'foreground passes the worst possible pixel after the trusted 60% scrim',
    );
    assert_true(
        ContrastMath::ratio($foreground, ContrastMath::hexToRgb(hh_palette()['contrast']))
            >= ContrastMath::NORMAL_TEXT,
        'same foreground passes the planned contrast opening',
    );

    // No single foreground passes both the worst-case scrim pixel and the
    // planned light 'contrast' opening, so the overlay guarantee fails —
    // but the stacked path still owns a contrast-safe opaque pair and the
    // two-page depth that earns sticky-soft. The fallback resolves through
    // that path instead of forcing static.
    $inverted = [
        'base' => '#101E2B',
        'contrast' => '#F2EDE3',
        'primary' => '#182938',
        'secondary' => '#D9D4CA',
        'accent' => '#273B4B',
    ];
    $fallback = HeaderBehavior::resolve($pages, HeaderBehavior::MODE_OVERLAY, $inverted);
    assert_eq(HeaderBehavior::STICKY_SOFT, $fallback['behavior']);
    assert_eq(HeaderBehavior::MODE_STACKED, $fallback['mode']);
    assert_eq('base', $fallback['topSurface']);
    assert_eq('contrast', $fallback['foreground']);
    foreach ([$fallback['topSurface'], $fallback['scrolledSurface']] as $surface) {
        $fg = ContrastMath::hexToRgb($inverted[$fallback['foreground']]);
        $bg = ContrastMath::hexToRgb($inverted[$surface]);
        assert_true($fg !== null && $bg !== null && ContrastMath::ratio($fg, $bg) >= ContrastMath::NORMAL_TEXT);
    }
});

test('overlay palette fallback keeps sticky-soft when the stacked path is safe', function () {
    // Both openings sit on 'base' (white): white passes the worst-case scrim
    // pixel but not its own opening, and every darker token fails the scrim,
    // so overlay-to-solid is impossible with this palette.
    $pages = [
        ['slug' => 'home', 'sections' => [['slug' => 'hero', 'background' => 'base']]],
        ['slug' => 'about', 'sections' => [['slug' => 'intro', 'background' => 'base']]],
    ];
    $artifact = HeaderBehavior::resolve($pages, HeaderBehavior::MODE_OVERLAY, hh_palette());
    assert_eq(HeaderBehavior::STICKY_SOFT, $artifact['behavior'], 'stacked path grants its safe sticky-soft');
    assert_eq(HeaderBehavior::MODE_STACKED, $artifact['mode']);
    assert_eq('base', $artifact['topSurface']);
    assert_eq('secondary', $artifact['scrolledSurface']);
    assert_eq('contrast', $artifact['foreground']);

    // When the stacked path's own palette safety check also fails, the
    // fallback still ends at static even though the depth is sticky-eligible.
    $midTones = [
        'base' => '#7F7F7F',
        'contrast' => '#8A8A8A',
        'primary' => '#757575',
        'secondary' => '#808080',
        'accent' => '#8F8F8F',
    ];
    $unsafe = HeaderBehavior::resolve($pages, HeaderBehavior::MODE_OVERLAY, $midTones);
    assert_eq(HeaderBehavior::STATIC, $unsafe['behavior'], 'both paths failing still ends at static');
    assert_eq(HeaderBehavior::MODE_STACKED, $unsafe['mode']);
    assert_eq($unsafe['topSurface'], $unsafe['scrolledSurface']);
});

test('overlay resolver degrades an all-dark palette instead of throwing on a missing foreground', function () {
    $pages = [['slug' => 'home', 'sections' => [[
        'slug' => 'hero', 'background' => 'image',
    ]]]];
    $artifact = HeaderBehavior::resolve($pages, HeaderBehavior::MODE_OVERLAY, [
        'base' => '#111111',
        'contrast' => '#222222',
        'primary' => '#333333',
        'secondary' => '#444444',
        'accent' => '#555555',
    ]);

    assert_eq(HeaderBehavior::STATIC, $artifact['behavior']);
    assert_eq(HeaderBehavior::MODE_STACKED, $artifact['mode']);
});

test('closed behavior artifact rejects extra fields and impossible tuples', function () {
    $valid = [
        'behavior' => 'static',
        'mode' => 'stacked',
        'transition' => 'instant',
        'topSurface' => 'base',
        'scrolledSurface' => 'base',
        'foreground' => 'contrast',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
    ];
    assert_eq($valid, HeaderBehavior::validateArtifact($valid));
    assert_throws(static fn () => HeaderBehavior::validateArtifact($valid + ['extra' => 'nope']));
    $legacy = $valid;
    unset($legacy['topTreatment'], $legacy['scrolledTreatment']);
    assert_throws(
        static fn () => HeaderBehavior::validateArtifact($legacy),
        'the closed contract is exactly eight fields; the pre-treatment shape is rejected',
    );
    $invalid = $valid;
    $invalid['topSurface'] = 'transparent';
    assert_throws(static fn () => HeaderBehavior::validateArtifact($invalid));
    assert_eq('instant', HeaderBehavior::transitionFor('minimal'));
    assert_eq('instant', HeaderBehavior::transitionFor('none'));
    assert_eq('smooth', HeaderBehavior::transitionFor('calm'));
});

test('the step repairs parts, writes the behavior artifact, and keeps successful fixes out of warnings', function () {
    with_project('builder_hh_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]]]);
        hh_above_fold($project, [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]], 'focal-subject-stage');
        $project->writeText('theme/parts/header.html', hh_header('{"className":"header-overlay","layout":{"type":"constrained"}}') . "\n");
        $project->writeText('theme/parts/page-home--hero.html', hh_cover('92') . "\n");

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        assert_true(!str_contains($project->readText('theme/parts/header.html'), 'header-overlay'), 'stacked mode strips the stray overlay hook');
        assert_contains('"minHeight":80', $project->readText('theme/parts/page-home--hero.html'));
        assert_eq(HeaderBehavior::STATIC, $project->readJson(HeaderBehavior::FILE)['behavior']);
        assert_true(!$project->exists('warnings.json'), 'complete deterministic repair is not queued for AI repair');
        assert_true($project->exists('logs/header-hero.txt'));
    });
});

test('the step protects a resumed legacy theme with global Group padding', function () {
    with_project('builder_hh_group_padding_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Atlas Field']);
        $theme = hh_theme_json();
        $theme['styles']['blocks']['core/group']['spacing']['padding'] = [
            'top' => 'var:preset|spacing|xl',
            'bottom' => 'var:preset|spacing|xl',
        ];
        $project->writeJson('theme/theme.json', $theme);
        $project->writeJson('designDirection.json', ['canvas' => 'contained', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]]]);
        hh_above_fold($project, [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]], 'focal-subject-stage');
        $project->writeText(
            'theme/parts/header.html',
            '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm"}}},"layout":{"type":"constrained"}} -->'
                . '<div class="wp-block-group"><!-- wp:group {"layout":{"type":"flex"}} -->'
                . '<div class="wp-block-group"><!-- wp:group {"layout":{"type":"flex"}} -->'
                . '<div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->'
                . '<!-- wp:navigation /--></div><!-- /wp:group --></div><!-- /wp:group -->',
        );
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage hero-mobile--stack-media-first">'
                . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading --></div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $header = BlockMarkup::parse($project->readText('theme/parts/header.html'));
        $groups = array_values(array_filter(
            $header->indices(),
            static fn (int $i): bool => $header->name($i) === 'group',
        ));
        foreach (array_slice($groups, 1) as $group) {
            $padding = ($header->attrs($group) ?? [])['style']['spacing']['padding'];
            assert_eq('0', $padding['top']);
            assert_eq('0', $padding['bottom']);
        }
        assert_contains('neutralized inherited core/group', $project->readText('logs/header-hero.txt'));
        assert_true(!$project->exists('warnings.json'), 'lossless legacy repair is not queued');
    });
});

test('removing authored sticky behavior from a resolved static header warns actionably', function () {
    with_project('builder_hh_loss_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'none']);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]]]);
        hh_above_fold($project, [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]], 'focal-subject-stage');
        $project->writeText(
            'theme/parts/header.html',
            hh_header('{"backgroundColor":"base","textColor":"contrast","style":{"position":{"type":"sticky","top":"0px"}},"layout":{"type":"constrained"}}') . "\n",
        );
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage hero-mobile--stack-media-first">'
                . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading --></div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $warnings = $project->readText('warnings.json');
        assert_contains("file='theme/parts/header.html'", $warnings);
        assert_contains("block='wp:group[0]'", $warnings);
        assert_contains('authored=', $warnings);
        assert_contains('delivered=removed', $warnings);
        assert_contains('disposition=', $warnings);
        assert_true(!str_contains($project->readText('theme/parts/header.html'), '"position"'));
        assert_eq('instant', $project->readJson(HeaderBehavior::FILE)['transition']);
    });
});

test('the step warns actionably when an unreadable palette downgrades sticky-soft to static', function () {
    with_project('builder_hh_downgrade_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        // Every palette token sits near mid-gray: no foreground/surface pair
        // reaches 4.5:1, so the requested sticky-soft cannot stay readable.
        $palette = [];
        foreach ([
            'base' => '#7F7F7F',
            'contrast' => '#8A8A8A',
            'primary' => '#757575',
            'secondary' => '#808080',
            'accent' => '#8F8F8F',
        ] as $slug => $color) {
            $palette[] = ['slug' => $slug, 'name' => ucfirst($slug), 'color' => $color];
        }
        $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['color' => ['palette' => $palette]]]);
        $project->writeJson('designDirection.json', ['canvas' => 'contained', 'motion' => 'calm']);
        $section = ['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base'];
        $project->writeJson('pages.json', ['pages' => [
            ['slug' => 'home', 'title' => 'Home', 'front' => true, 'sections' => [$section]],
            ['slug' => 'about', 'title' => 'About', 'front' => false, 'sections' => [$section]],
        ]]);
        hh_above_fold($project, [
            ['slug' => 'home', 'title' => 'Home', 'front' => true, 'sections' => [$section]],
            ['slug' => 'about', 'title' => 'About', 'front' => false, 'sections' => [$section]],
        ], 'focal-subject-stage');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage hero-mobile--stack-media-first">'
                . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading --></div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $artifact = $project->readJson(HeaderBehavior::FILE);
        assert_eq(HeaderBehavior::STATIC, $artifact['behavior'], 'unsafe palette downgrades sticky-soft');
        assert_eq(HeaderBehavior::MODE_STACKED, $artifact['mode']);
        assert_true($project->exists('warnings.json'), 'a behavior downgrade is actionable and must be queued');
        $warnings = implode("\n", $project->readJson('warnings.json')['header-hero'] ?? []);
        assert_contains("file='" . HeaderBehavior::FILE . "'", $warnings);
        assert_contains("block='behavior'", $warnings);
        assert_contains("authored='sticky-soft' in mode 'stacked'", $warnings);
        assert_contains("delivered='static' in mode 'stacked'", $warnings);
        assert_contains('disposition=behavior downgraded', $warnings);
        assert_contains('no palette-token foreground/surface pair', $warnings);
    });
});

test('moving authored root sticky behavior to a sticky outer shell is a warning-free canonical repair', function () {
    with_project('builder_hh_move_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $section = ['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base'];
        $project->writeJson('pages.json', ['pages' => [
            ['slug' => 'home', 'title' => 'Home', 'front' => true, 'sections' => [$section]],
            ['slug' => 'about', 'title' => 'About', 'front' => false, 'sections' => [$section]],
        ]]);
        hh_above_fold($project, [
            ['slug' => 'home', 'title' => 'Home', 'front' => true, 'sections' => [$section]],
            ['slug' => 'about', 'title' => 'About', 'front' => false, 'sections' => [$section]],
        ], 'focal-subject-stage');
        $project->writeText(
            'theme/parts/header.html',
            hh_header('{"backgroundColor":"base","textColor":"contrast","style":{"position":{"type":"sticky","top":"0px"}},"layout":{"type":"constrained"}}') . "\n",
        );
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage hero-mobile--stack-media-first">'
                . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading --></div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        assert_eq(HeaderBehavior::STICKY_SOFT, $project->readJson(HeaderBehavior::FILE)['behavior']);
        assert_true(!$project->exists('warnings.json'), 'equivalent outer sticky behavior preserves the authored intent');
        assert_true(!str_contains($project->readText('theme/parts/header.html'), '"position"'));
    });
});

test('the step downgrades a planned overlay when generated opening markup loses its image band', function () {
    with_project('builder_hh_opening_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [[
                'slug' => 'hero',
                'role' => 'hero',
                'layout_archetype' => 'full-bleed-cover',
                'background' => 'image',
            ]],
        ]]]);
        hh_above_fold($project, [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [[
                'slug' => 'hero',
                'role' => 'hero',
                'layout_archetype' => 'full-bleed-cover',
                'background' => 'image',
            ]],
        ]], 'cinematic-safe-zone');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"backgroundColor":"base"} --><div class="wp-block-group has-base-background-color has-background">'
                . '<!-- wp:heading --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading -->'
                . '</div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $artifact = $project->readJson(HeaderBehavior::FILE);
        assert_eq(HeaderBehavior::STATIC, $artifact['behavior']);
        assert_eq(HeaderBehavior::MODE_STACKED, $artifact['mode']);
        $warnings = implode("\n", $project->readJson('warnings.json')['header-hero'] ?? []);
        assert_contains("file='theme/parts/page-home--hero.html'", $warnings);
        assert_contains('authored="overlay-to-solid for page \'home\'"', $warnings);
        assert_contains("delivered='static' in mode 'stacked'", $warnings);
        assert_contains('disposition=overlay downgraded', $warnings);

    });
});

test('the step keeps overlay when generated opening markup begins with a real cover image', function () {
    with_project('builder_hh_overlay_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [[
                'slug' => 'hero',
                'role' => 'hero',
                'layout_archetype' => 'full-bleed-cover',
                'background' => 'image',
            ]],
        ]]]);
        hh_above_fold($project, [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [[
                'slug' => 'hero',
                'role' => 'hero',
                'layout_archetype' => 'full-bleed-cover',
                'background' => 'image',
            ]],
        ]], 'cinematic-safe-zone');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--cinematic-safe-zone hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--cinematic-safe-zone hero-mobile--stack-media-first">'
                . '<!-- wp:cover {"url":"theme:./assets/hero.jpg","align":"full","dimRatio":50,"overlayColor":"contrast"} -->'
                . '<div class="wp-block-cover alignfull"></div><!-- /wp:cover -->'
                . '</div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $artifact = $project->readJson(HeaderBehavior::FILE);
        assert_eq(HeaderBehavior::OVERLAY_TO_SOLID, $artifact['behavior']);
        assert_eq(HeaderBehavior::MODE_OVERLAY, $artifact['mode']);
        assert_contains('header-behavior-overlay-to-solid', $project->readText('theme/parts/header.html'));
        assert_true(!$project->exists('warnings.json'), 'verified overlay is a warning-free deterministic treatment');

    });
});

test('the step rejects opening markup whose image does not actually meet the viewport-wide top edge', function () {
    $cover = '<!-- wp:cover {"url":"theme:./assets/hero.jpg","align":"full","dimRatio":40} -->'
        . '<div class="wp-block-cover alignfull"></div><!-- /wp:cover -->';
    $cases = [
        'non-full cover' => str_replace(',"align":"full"', '', $cover),
        'opaque wrapper' => '<!-- wp:group {"backgroundColor":"base","layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group has-base-background-color has-background">'
            . $cover . '</div><!-- /wp:group -->',
        'top-padded wrapper' => '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|xl"}}},'
            . '"layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--xl)">'
            . $cover . '</div><!-- /wp:group -->',
        'visible raw lead-in' => '<p>Unexpected lead-in</p>' . $cover,
        'visible wrapper lead-in' => '<!-- wp:group {"layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group"><p>Unexpected lead-in</p>'
            . $cover . '</div><!-- /wp:group -->',
    ];

    putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
    foreach ($cases as $label => $openingMarkup) {
        with_project('builder_hh_opening_edge_', function ($project) use ($label, $openingMarkup) {
            $project->writeJson('siteSpec.json', ['name' => 'Demo']);
            $project->writeJson('theme/theme.json', hh_theme_json());
            $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
            $project->writeJson('pages.json', ['pages' => [[
                'slug' => 'home', 'title' => 'Home', 'front' => true,
                'sections' => [[
                    'slug' => 'hero',
                    'role' => 'hero',
                    'layout_archetype' => 'full-bleed-cover',
                    'background' => 'image',
                ]],
            ]]]);
        hh_above_fold($project, [[
                'slug' => 'home', 'title' => 'Home', 'front' => true,
                'sections' => [[
                    'slug' => 'hero',
                    'role' => 'hero',
                    'layout_archetype' => 'full-bleed-cover',
                    'background' => 'image',
                ]],
            ]], 'cinematic-safe-zone');
            $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
            $project->writeText('theme/parts/page-home--hero.html', $openingMarkup);

            (new HeaderHeroStep())->run($project);

            $artifact = $project->readJson(HeaderBehavior::FILE);
            assert_eq(HeaderBehavior::STATIC, $artifact['behavior'], $label);
            assert_eq(HeaderBehavior::MODE_STACKED, $artifact['mode'], $label);
            $warnings = implode("\n", $project->readJson('warnings.json')['header-hero'] ?? []);
            assert_contains('disposition=overlay downgraded', $warnings, $label);

        });
    }
});

test('the step writes earned sticky treatments into both the header part and the artifact', function () {
    with_project('builder_hh_treatments_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'contained', 'motion' => 'calm']);
        // Every opening is a token-backed 'base' band and theme.json paints no
        // custom page background, so the near-black foreground is provable
        // against everything a transparent start reveals; the scrolled state
        // frosts the soft neutral it lands on.
        $section = ['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base'];
        $project->writeJson('pages.json', ['pages' => [
            ['slug' => 'home', 'title' => 'Home', 'front' => true, 'sections' => [$section]],
            ['slug' => 'about', 'title' => 'About', 'front' => false, 'sections' => [$section]],
        ]]);
        hh_above_fold($project, [
            ['slug' => 'home', 'title' => 'Home', 'front' => true, 'sections' => [$section]],
            ['slug' => 'about', 'title' => 'About', 'front' => false, 'sections' => [$section]],
        ], 'focal-subject-stage');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage hero-mobile--stack-media-first">'
                . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading --></div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $artifact = $project->readJson(HeaderBehavior::FILE);
        assert_eq(HeaderBehavior::STICKY_SOFT, $artifact['behavior']);
        assert_eq(HeaderBehavior::TREATMENT_TRANSPARENT, $artifact['topTreatment']);
        assert_eq(HeaderBehavior::TREATMENT_GLASS, $artifact['scrolledTreatment']);
        $header = $project->readText('theme/parts/header.html');
        assert_contains('header-top-transparent', $header, 'earned top treatment reaches the written part');
        assert_contains('header-scrolled-glass', $header, 'earned scrolled treatment reaches the written part');
    });
});

test('theme.json page background feeds the transparent-start contrast contract in both preset spellings', function () {
    // The palette is built so a transparent start is provable against the
    // default 'base' page background but not against 'secondary': the dark
    // foreground clears white at ~19:1 yet reaches only ~1.7:1 on the dark
    // secondary token a transparent header would reveal at rest.
    $palette = [];
    foreach (['base' => '#FFFFFF', 'contrast' => '#111111', 'secondary' => '#3B3B3B'] as $slug => $color) {
        $palette[] = ['slug' => $slug, 'name' => ucfirst($slug), 'color' => $color];
    }
    $section = ['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base'];
    $run = static function (?string $background) use ($palette, $section): array {
        return with_project('builder_hh_pagebg_', function ($project) use ($palette, $section, $background): array {
            $project->writeJson('siteSpec.json', ['name' => 'Demo']);
            $theme = ['version' => 3, 'settings' => ['color' => ['palette' => $palette]]];
            if ($background !== null) {
                $theme['styles']['color']['background'] = $background;
            }
            $project->writeJson('theme/theme.json', $theme);
            $project->writeJson('designDirection.json', ['canvas' => 'contained', 'motion' => 'calm']);
            $project->writeJson('pages.json', ['pages' => [
                ['slug' => 'home', 'title' => 'Home', 'front' => true, 'sections' => [$section]],
                ['slug' => 'about', 'title' => 'About', 'front' => false, 'sections' => [$section]],
            ]]);
        hh_above_fold($project, [
                ['slug' => 'home', 'title' => 'Home', 'front' => true, 'sections' => [$section]],
                ['slug' => 'about', 'title' => 'About', 'front' => false, 'sections' => [$section]],
            ], 'focal-subject-stage');
            $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage hero-mobile--stack-media-first">'
                . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading --></div><!-- /wp:group -->',
        );
            putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
            (new HeaderHeroStep())->run($project);
            return $project->readJson(HeaderBehavior::FILE);
        });
    };

    $control = $run(null);
    assert_eq(HeaderBehavior::STICKY_SOFT, $control['behavior']);
    assert_eq(
        HeaderBehavior::TREATMENT_TRANSPARENT,
        $control['topTreatment'],
        'without a custom page background the base convention proves the transparent start',
    );

    foreach ([
        'var(--wp--preset--color--secondary)',
        'var:preset|color|secondary',
    ] as $spelling) {
        $artifact = $run($spelling);
        assert_eq(HeaderBehavior::STICKY_SOFT, $artifact['behavior'], $spelling);
        assert_eq(
            HeaderBehavior::TREATMENT_GLASS,
            $artifact['topTreatment'],
            "{$spelling}: the revealed dark page background must deny the transparent start",
        );
    }
});
test('dedupeAgainstHero strips echoed caption lines and the duplicate-label CTA', function () {
    $hero = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p>SAN TELMO · BUENOS AIRES</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":1} --><h1>Vegetarian Argentine Cuisine</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>A long standfirst that runs well past twelve words so it can never'
        . ' participate in echo matching against header chrome at all.</p><!-- /wp:paragraph -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" href="/visit/">Reserve a Table</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:paragraph {"fontSize":"caption"} --><p>San Telmo, Buenos Aires</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p>Open Tuesday to Sunday</p><!-- /wp:paragraph -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" href="/visit/">Reserve a Table</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->'
    );
    $action = ['label' => 'Reserve a Table', 'intent' => 'Invite a booking', 'destination' => '/visit/'];
    $result = HeaderHeroStep::dedupeAgainstHero($header, $hero, $action);

    assert_true(!str_contains($result['markup'], 'San Telmo'), 'echoed location line removed');
    assert_contains('Open Tuesday to Sunday', $result['markup'], 'non-echoed chrome line survives');
    assert_true(!str_contains($result['markup'], 'Reserve a Table'), 'duplicate CTA removed');
    assert_true(!str_contains($result['markup'], 'wp:buttons'), 'empty buttons wrapper removed with its only button');
    assert_contains('wp:site-title', $result['markup'], 'identity chrome untouched');
    assert_eq(2, count($result['notes']));

    // Idempotent, and a header with nothing duplicated is untouched.
    $again = HeaderHeroStep::dedupeAgainstHero($result['markup'], $hero, $action);
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['notes']);
});

test('dedupeAgainstHero leaves distinct chrome, partial overlap, and other-label buttons alone', function () {
    $hero = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Bienvenidos — San Telmo, Buenos Aires</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    // Shares only the place name with the hero eyebrow — different information.
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:paragraph {"fontSize":"caption"} --><p>Vegetarian kitchen · Calle Defensa, San Telmo</p><!-- /wp:paragraph -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" href="/menu/">See the Menu</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->'
    );
    $action = ['label' => 'Reserve a Table', 'intent' => 'Invite a booking', 'destination' => '/visit/'];
    $result = HeaderHeroStep::dedupeAgainstHero($header, $hero, $action);
    assert_eq($header, $result['markup']);
    assert_eq([], $result['notes']);

    // No contract action: buttons are never candidates.
    $none = HeaderHeroStep::dedupeAgainstHero($header, $hero, null);
    assert_eq($header, $none['markup']);
});

test('the step repairs header and hero parts without promoting successful repairs to warnings', function () {
    $tmp = sys_get_temp_dir() . '/builder_hh_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $pages = [[
        'slug' => 'home', 'title' => 'Home', 'front' => true,
        'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base', 'primary_action' => null]],
    ]];
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', AboveFoldContract::resolve(
        pages: $pages,
        blueprint: HeroBlueprint::defaultFor('focal-subject-stage'),
        canvas: 'full-bleed',
        themeContext: ['version' => 3],
        siteContext: ['stable_id' => 'demo', 'writing_direction' => 'ltr', 'page_count' => 1],
        footerContext: ['archetype' => 'typographic-billboard', 'surface' => 'base'],
    ));
    $project->writeText('theme/parts/header.html', hh_header('{"className":"header-overlay","layout":{"type":"constrained"}}') . "\n");
    $project->writeText('theme/parts/page-home--hero.html', hh_cover('92') . "\n");

    putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
    (new HeaderHeroStep())->run($project);

    assert_true(!str_contains($project->readText('theme/parts/header.html'), 'header-overlay'), 'stacked mode strips the stray overlay hook');
    assert_contains('"minHeight":80', $project->readText('theme/parts/page-home--hero.html'));
    assert_true(!$project->exists('warnings.json'), 'successful contract repairs stay in the step report only');
    assert_true($project->exists('logs/header-hero.txt'));
    $report = $project->readText('logs/header-hero.txt');
    assert_contains("removed the legacy 'header-overlay' class", $report);
    assert_contains('cover minHeight 92vh lowered to 80vh', $report);
    assert_eq('final', $project->readJson('aboveFold.json')['phase'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the step removes an exact hero action when its delivered target is dead', function () {
    $tmp = sys_get_temp_dir() . '/builder_hh_dead_action_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $action = ['label' => 'Missing work', 'intent' => 'Reach missing work.', 'destination' => '/missing/'];
    $pages = [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
        'sections' => [[
            'slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'mixed-width-editorial',
            'background' => 'contrast', 'primary_action' => $action,
        ]],
    ]];
    $delivery = AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor('focal-subject-stage'),
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'dead-action', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'standard-row',
    );
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', $delivery);
    $project->writeText('theme/parts/header.html', hh_header('{"className":"header-archetype--standard-row","backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}}'));
    $sibling = '<!-- wp:paragraph --><p>Sibling proposition remains.</p><!-- /wp:paragraph -->';
    $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/missing/">Missing work</a></div><!-- /wp:button -->';
    $hero = '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage hero-mobile--stack-media-first">'
        . $sibling . '<!-- wp:buttons --><div class="wp-block-buttons">' . $button . '</div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->';
    $project->writeText('theme/parts/page-home--hero.html', $hero);

    (new HeaderHeroStep())->run($project);

    $deliveredHero = $project->readText('theme/parts/page-home--hero.html');
    assert_contains($sibling, $deliveredHero);
    assert_true(!str_contains($deliveredHero, $button));
    assert_eq(null, $project->readJson('pages.json')['pages'][0]['sections'][0]['primary_action']);
    $final = $project->readJson('aboveFold.json');
    assert_eq('final', $final['phase']);
    assert_eq(null, $final['primary_action']);
    assert_eq('primary-action-target-lost', $final['degradations'][0]['code']);
    $warnings = implode("\n", $project->readJson('warnings.json')['header-hero'] ?? []);
    assert_contains('code="primary-action-target-lost"', $warnings);
    assert_contains('/missing/', $warnings);
    assert_contains('delivered=removed', $warnings);
    assert_contains('dead control', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('late overlay protection loss produces matching stacked bytes and the 80vh cover cap', function () {
    $tmp = sys_get_temp_dir() . '/builder_hh_overlay_loss_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $pages = [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
        'sections' => [[
            'slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'full-bleed-cover',
            'background' => 'image', 'primary_action' => null,
        ]],
    ]];
    $delivery = AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor('cinematic-safe-zone'),
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'overlay-loss', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'minimal-overlay',
    );
    assert_eq('overlay', $delivery['header']['mode']);
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', $delivery);
    $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}'));
    $hero = str_replace(
        '<!-- wp:group {"layout":{"type":"constrained"}} -->',
        '<!-- wp:group {"className":"hero-composition--cinematic-safe-zone hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->',
        hh_cover('92'),
    );
    $hero = str_replace(
        'class="wp-block-group"',
        'class="wp-block-group hero-composition--cinematic-safe-zone hero-mobile--stack-media-first"',
        $hero,
    );
    $project->writeText('theme/parts/page-home--hero.html', $hero);

    (new HeaderHeroStep())->run($project);

    $final = $project->readJson('aboveFold.json');
    assert_eq('stacked', $final['header']['mode']);
    assert_eq('standard-row', $final['header']['archetype']);
    assert_eq('overlay-support-lost', $final['degradations'][0]['code']);
    $header = $project->readText('theme/parts/header.html');
    assert_true(!str_contains($header, 'header-overlay'));
    assert_contains('header-archetype--standard-row', $header);
    assert_contains('"backgroundColor":"base"', $header);
    assert_contains('has-base-background-color', $header);
    assert_contains('has-background', $header);
    assert_contains('has-contrast-color', $header);
    assert_contains('has-text-color', $header);
    assert_contains('"minHeight":80', $project->readText('theme/parts/page-home--hero.html'));
    $warnings = implode("\n", $project->readJson('warnings.json')['header-hero'] ?? []);
    assert_contains('code="overlay-support-lost"', $warnings);
    assert_contains('top-edge protection', $warnings);
    assert_contains('delivered="stacked"', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});
