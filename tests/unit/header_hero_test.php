<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroCopyBudget;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\HeaderHeroStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;

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
function hh_above_fold($project, array $pages, string $recipe = 'foreground-split', ?array $theme = null, ?string $forced = null): array
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

test('an over-wide nav row keeps the inline desktop nav instead of overlayMenu:always', function () {
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

    assert_true(
        HeaderHeroStep::estimatedRowWidth(BlockMarkup::parse($markup), 'Pulso Sur Centro Cultural')
            > HeaderHeroStep::ROW_BUDGET_PX,
        'fixture must still be over the single-row budget so this pins the old always-collapse',
    );
    assert_true(
        !str_contains($result['markup'], '"overlayMenu":"always"'),
        'an over-wide row must not become a hamburger at desktop widths',
    );
    assert_eq($markup, $result['markup']);
    assert_contains('over-wide header row', implode(' ', $result['warnings']));

    // A short three-item nav fits the row and is untouched.
    $short = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu"} /--><!-- wp:navigation-link {"label":"Visit"} /-->'
        . '<!-- /wp:navigation -->'
    );
    $fits = HeaderHeroStep::fixHeader($short, AboveFoldContract::MODE_STACKED, 'Demo');
    assert_eq($short, $fits['markup']);
    assert_true(!str_contains(implode(' ', $fits['warnings']), 'over-wide header row'));
});

test('a page-list nav is measured by the site page titles', function () {
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->'
    );
    $doc = BlockMarkup::parse($markup);
    $longTitles = ['Our Seasonal Tasting Menu', 'Private Dining and Events', 'Reservations and Contact', 'The Story of the House'];
    assert_true(
        HeaderHeroStep::estimatedRowWidth($doc, 'Demo', $longTitles) > HeaderHeroStep::ROW_BUDGET_PX,
        'long page titles must charge the row so the estimate still has a job',
    );
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', $longTitles);
    assert_true(
        !str_contains($result['markup'], '"overlayMenu":"always"'),
        'measuring a wide page-list must not collapse desktop to a hamburger',
    );
    assert_eq($markup, $result['markup']);
    assert_contains('over-wide header row', implode(' ', $result['warnings']));

    $fits = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', ['Menu', 'Visit']);
    assert_eq($markup, $fits['markup']);
    assert_true(
        HeaderHeroStep::estimatedRowWidth($doc, 'Demo', ['Menu', 'Visit']) <= HeaderHeroStep::ROW_BUDGET_PX,
    );
    assert_true(!str_contains(implode(' ', $fits['warnings']), 'over-wide header row'));
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

test('estimatedRowWidth measures rich navigation labels by visible text', function () {
    $label = '<span class="name" data-blocks-engine-richtext-marker="blocks-engine-richtext-f5cea1491d8b-4">Super Coaching</span>';
    $attrs = json_encode(['label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $doc = BlockMarkup::parse('<!-- wp:navigation-link ' . $attrs . ' /-->');

    // GUTTERS_PX (64) + 14 visible chars * NAV_CHAR_PX (11) + NAV_GAP_PX (28).
    assert_eq(64 + 14 * 11 + 28, HeaderHeroStep::estimatedRowWidth($doc, ''));
});

test('estimatedRowWidth decodes entities in navigation labels before counting', function () {
    $label = 'Tools &amp; Advice';
    $attrs = json_encode(['label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $doc = BlockMarkup::parse('<!-- wp:navigation-link ' . $attrs . ' /-->');

    assert_eq(18, mb_strlen($label));
    assert_eq(14, mb_strlen(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    assert_eq(64 + 14 * 11 + 28, HeaderHeroStep::estimatedRowWidth($doc, ''));
});

test('estimatedRowWidth uses the widest visible line of a rich navigation label', function () {
    $label = '<span class="name" data-blocks-engine-richtext-marker="blocks-engine-richtext-brand-name">Super Coaching</span>' . "\n      "
        . '<span class="place" data-blocks-engine-richtext-marker="blocks-engine-richtext-brand-place">Plymouth, New Hampshire</span>';
    $attrs = json_encode(['label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $doc = BlockMarkup::parse('<!-- wp:navigation-link ' . $attrs . ' /-->');

    // The two stacked lines occupy the width of the longer 23-character line.
    assert_eq(64 + 23 * 11 + 28, HeaderHeroStep::estimatedRowWidth($doc, ''));
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
        ]], 'foreground-split');
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

test('the step strips a Home page-list from the footer part', function () {
    with_project('builder_hh_footer_home_', function ($project) {
        $pages = [
            [
                'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
                'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
            ],
            [
                'slug' => 'menu', 'title' => 'Menu', 'path' => '/menu/', 'front' => false,
                'sections' => [['slug' => 'menu-hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
            ],
            [
                'slug' => 'visit', 'title' => 'Visit', 'path' => '/visit/', 'front' => false,
                'sections' => [['slug' => 'visit-hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
            ],
        ];
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages);
        $project->writeText(
            'theme/parts/header.html',
            hh_header('{"backgroundColor":"base","layout":{"type":"constrained"}}') . "\n",
        );
        $project->writeText(
            'theme/parts/footer.html',
            '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
            . '<div class="wp-block-group">'
            . '<!-- wp:site-title /-->'
            . '<!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->'
            . '</div>' . "\n"
            . '<!-- /wp:group -->' . "\n",
        );
        $project->writeText('theme/parts/page-home--hero.html', hh_cover('50') . "\n");

        (new HeaderHeroStep())->run($project);

        $footer = $project->readText('theme/parts/footer.html');
        assert_contains('<!-- wp:site-title /-->', $footer);
        assert_true(!str_contains($footer, 'wp:page-list'));
        assert_true(!str_contains($footer, '"label":"Home"'));
        assert_contains('"label":"Menu"', $footer);
        assert_contains('"label":"Visit"', $footer);
    });
});

test('HTML-first header-hero strips Home from transformed chrome on both header and footer', function () {
    with_project('builder_hh_html_first_home_', function ($project) {
        $pages = [
            [
                'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
                'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
            ],
            [
                'slug' => 'about', 'title' => 'About', 'path' => '/about/', 'front' => false,
                'sections' => [['slug' => 'about-hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
            ],
        ];
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages);
        $project->writeText(
            'theme/parts/header.html',
            hh_header(
                '{"backgroundColor":"base","layout":{"type":"constrained"}}',
                '<!-- wp:site-title /-->'
                . '<a class="brand" href="/">Demo</a>'
                . '<!-- wp:navigation -->'
                . '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->'
                . '<!-- wp:navigation-link {"label":"About","url":"/about/","kind":"custom"} /-->'
                . '<!-- /wp:navigation -->',
            ) . "\n",
        );
        $project->writeText(
            'theme/parts/footer.html',
            '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
            . '<div class="wp-block-group">'
            . '<!-- wp:site-title /-->'
            . '<nav><a href="/">Home</a><a href="/about/">About</a></nav>'
            . '</div>' . "\n"
            . '<!-- /wp:group -->' . "\n",
        );
        $project->writeText('theme/parts/page-home--hero.html', hh_cover('50') . "\n");

        (new HeaderHeroStep(htmlFirst: true))->run($project);

        $header = $project->readText('theme/parts/header.html');
        $footer = $project->readText('theme/parts/footer.html');
        assert_contains('<a class="brand" href="/">Demo</a>', $header);
        assert_contains('"label":"About"', $header);
        assert_true(!str_contains($header, '"label":"Home"'));
        assert_contains('<!-- wp:site-title /-->', $footer);
        assert_contains('<a href="/about/">About</a>', $footer);
        assert_true(!str_contains($footer, '>Home</a>'));
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
        ]], 'foreground-split');
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
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-media-first">'
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
        ]], 'foreground-split');
        $project->writeText(
            'theme/parts/header.html',
            hh_header('{"backgroundColor":"base","textColor":"contrast","style":{"position":{"type":"sticky","top":"0px"}},"layout":{"type":"constrained"}}') . "\n",
        );
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-media-first">'
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
        ], 'foreground-split');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-media-first">'
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
        ], 'foreground-split');
        $project->writeText(
            'theme/parts/header.html',
            hh_header('{"backgroundColor":"base","textColor":"contrast","style":{"position":{"type":"sticky","top":"0px"}},"layout":{"type":"constrained"}}') . "\n",
        );
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-media-first">'
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
        ], 'foreground-split');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-media-first">'
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
            ], 'foreground-split');
            $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-media-first">'
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

test('dedupeAgainstHero strips a tagline block whose rendered text paraphrases a hero line (BIGR-773)', function () {
    // The audited portfolio build: the dynamic tagline renders the spec topic,
    // and the hero eyebrow independently paraphrases the same fact. Neither
    // line is byte-identical, so the strict linesEcho path can never fire.
    $hero = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p>Documentary photography from Argentina</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":1} --><h1>Twenty years at the edge of the crowd</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /-->'
        . '<!-- wp:site-tagline {"fontSize":"caption"} /-->'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p>Open Tuesday to Sunday</p><!-- /wp:paragraph -->'
    );
    $tagline = 'documentary photography of Argentine social and political events';
    $result = HeaderHeroStep::dedupeAgainstHero($header, $hero, null, $tagline);
    assert_true(!str_contains($result['markup'], 'wp:site-tagline'), 'paraphrased tagline block removed');
    assert_contains('wp:site-title', $result['markup'], 'identity chrome untouched');
    assert_contains('Open Tuesday to Sunday', $result['markup'], 'unrelated chrome line survives');
    assert_eq(1, count($result['notes']));
    assert_contains('paraphrases', $result['notes'][0]);
    assert_eq(1, count($result['warnings']));
    foreach (["file='theme/parts/header.html'", "block='wp:site-tagline[1]'", 'authored=', 'delivered=removed', 'disposition='] as $context) {
        assert_contains($context, $result['warnings'][0]);
    }

    // Idempotent.
    $again = HeaderHeroStep::dedupeAgainstHero($result['markup'], $hero, null, $tagline);
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['notes']);
});

test('dedupeAgainstHero keeps a stated tagline that says something the hero does not', function () {
    $hero = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p>Open Tue–Fri · 24 Market Street</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":1} --><h1>Bread Made Slowly, Shared Daily</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:site-tagline {"fontSize":"caption"} /-->'
    );
    // A brand-register tagline sharing no meaning-bearing words with the hero.
    $result = HeaderHeroStep::dedupeAgainstHero($header, $hero, null, 'Naturally leavened since 1998');
    assert_eq($header, $result['markup']);
    assert_eq([], $result['notes']);
    assert_eq([], $result['warnings']);
});

test('dedupeAgainstHero strips a tagline block that would render blank', function () {
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:site-tagline {"textColor":"secondary","fontSize":"caption"} /-->'
    );
    // The spec states no tagline; the dynamic block would paint a dead line.
    $result = HeaderHeroStep::dedupeAgainstHero($header, '', null, '');
    assert_true(!str_contains($result['markup'], 'wp:site-tagline'), 'blank-rendering tagline block removed');
    assert_contains('wp:site-title', $result['markup'], 'identity chrome untouched');
    assert_eq(1, count($result['notes']));
    assert_contains('blank line', $result['notes'][0]);
    assert_eq(1, count($result['warnings']));
    assert_contains('delivered=removed', $result['warnings'][0]);

    $again = HeaderHeroStep::dedupeAgainstHero($result['markup'], '', null, '');
    assert_eq($result['markup'], $again['markup']);
});

test('dedupeAgainstHero removes and reports a painted wrapper emptied with a duplicate tagline', function () {
    $hero = '<!-- wp:paragraph --><p>Documentary photography from Argentina</p><!-- /wp:paragraph -->';
    $tagline = '<!-- wp:site-tagline {"fontSize":"caption"} /-->';
    $painted = '<!-- wp:group {"backgroundColor":"accent","style":{"spacing":{"padding":{"top":"20px"}}}} -->'
        . '<div class="wp-block-group has-accent-background-color has-background" style="padding-top:20px">'
        . $tagline . '</div><!-- /wp:group -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /-->' . $painted,
    );
    $taglineText = 'documentary photography of Argentine social and political events';

    $first = HeaderHeroStep::dedupeAgainstHero($header, $hero, null, $taglineText);

    assert_contains('wp:site-title', $first['markup']);
    assert_true(!str_contains($first['markup'], 'wp:site-tagline'));
    assert_true(!str_contains($first['markup'], 'backgroundColor":"accent'));
    assert_eq(1, count($first['notes']));
    assert_eq(2, count($first['warnings']));
    assert_contains("block='wp:site-tagline[1]'", $first['warnings'][0]);
    foreach ([
        "block='wp:group[2]'",
        'backgroundColor',
        'padding-top:20px',
        'delivered=removed',
        'dead header UI',
    ] as $context) {
        assert_contains($context, $first['warnings'][1]);
    }

    $second = HeaderHeroStep::dedupeAgainstHero($first['markup'], $hero, null, $taglineText);
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['notes']);
    assert_eq([], $second['warnings']);
});

test('dedupeAgainstHero never widens a tagline removal through the required header root', function () {
    $header = hh_header(
        '{"className":"header-archetype--standard-row","backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-tagline /-->',
    );

    $first = HeaderHeroStep::dedupeAgainstHero($header, '', null, '');

    assert_true($first['markup'] !== '');
    assert_contains('header-archetype--standard-row', $first['markup']);
    assert_contains('backgroundColor', $first['markup']);
    assert_true(!str_contains($first['markup'], 'wp:site-tagline'));
    assert_eq(1, count($first['warnings']));
    assert_contains("block='wp:site-tagline[1]'", $first['warnings'][0]);
    assert_true(!str_contains($first['warnings'][0], "block='wp:group[1]'"));

    $second = HeaderHeroStep::dedupeAgainstHero($first['markup'], '', null, '');
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['warnings']);
});

test('dedupeAgainstHero retains a duplicate tagline boundary that owns title and navigation', function () {
    // Invalid generated nesting must not turn a local tagline cleanup into the
    // loss of the masthead's identity and navigation. The whole unsafe unit is
    // delivered byte-for-byte and queued for later repair.
    $taglineUnit = '<!-- wp:site-tagline {"fontSize":"caption"} -->'
        . '<div class="wp-block-site-tagline">'
        . '<!-- wp:site-title /--><!-- wp:navigation /-->'
        . '</div><!-- /wp:site-tagline -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        $taglineUnit,
    );

    $result = HeaderHeroStep::dedupeAgainstHero($header, '', null, '');

    assert_eq($header, $result['markup'], 'the complete generated header bytes are retained');
    assert_contains($taglineUnit, $result['markup']);
    assert_contains('wp:site-title', $result['markup'], 'identity survives');
    assert_contains('wp:navigation', $result['markup'], 'navigation survives');
    assert_eq(1, count($result['notes']));
    assert_contains('retained', $result['notes'][0]);
    assert_eq(1, count($result['warnings']));
    foreach ([
        "file='theme/parts/header.html'",
        "block='wp:site-tagline[1]'",
        'authored=',
        'delivered="original block bytes"',
        'retained transactionally without partial edits',
        'residual duplicate is queued for repair',
    ] as $context) {
        assert_contains($context, $result['warnings'][0]);
    }

    $again = HeaderHeroStep::dedupeAgainstHero($result['markup'], '', null, '');
    assert_eq($result, $again, 'the retained-unit result and warning reach a fixed point');
});

test('dedupeAgainstHero retains an echoed text leaf with visible raw media payload', function () {
    $hero = '<!-- wp:paragraph --><p>SAN TELMO · BUENOS AIRES</p><!-- /wp:paragraph -->';
    $rawMedia = '<img src="keep.jpg" alt="">';
    $echo = '<!-- wp:paragraph --><p>San Telmo, Buenos Aires</p>'
        . $rawMedia . '<!-- /wp:paragraph -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        $echo,
    );

    $first = HeaderHeroStep::dedupeAgainstHero($header, $hero, null);

    assert_eq($header, $first['markup'], 'raw media freezes the complete malformed text leaf');
    assert_contains($rawMedia, $first['markup']);
    assert_eq(1, count($first['warnings']));
    foreach ([
        "block='wp:paragraph[1]'",
        'authored=',
        'delivered="original block bytes"',
        'visible raw/non-block payload',
        'retained transactionally without partial edits',
    ] as $context) {
        assert_contains($context, $first['warnings'][0]);
    }

    $second = HeaderHeroStep::dedupeAgainstHero($first['markup'], $hero, null);
    assert_eq($first, $second, 'the raw-survivor bytes and warning reach a fixed point');
});

test('dedupeAgainstHero retains an outer duplicate that contains an unsafe raw-media duplicate', function () {
    $line = 'San Telmo after dark';
    $hero = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":1} --><h1>' . $line . '</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $inner = '<!-- wp:paragraph --><p>' . $line
        . '<img src="keep.jpg" alt="Keep"></p><!-- /wp:paragraph -->';
    $outer = '<!-- wp:paragraph --><p>' . $inner . '</p><!-- /wp:paragraph -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        $outer,
    );

    $first = HeaderHeroStep::dedupeAgainstHero($header, $hero, null);

    assert_eq($header, $first['markup'], 'the nested candidate component stays byte-for-byte intact');
    assert_contains('keep.jpg', $first['markup']);
    assert_eq(2, count($first['warnings']));
    assert_contains('delivered="original block bytes"', implode("\n", $first['warnings']));

    $second = HeaderHeroStep::dedupeAgainstHero($first['markup'], $hero, null);
    assert_eq($first, $second, 'ancestor and raw-survivor warnings reach one fixed point');
});

test('dedupeAgainstHero locates a retained duplicate after an earlier same-type removal', function () {
    $safe = '<!-- wp:site-tagline /-->';
    $retained = '<!-- wp:site-tagline --><div class="bad-tagline">'
        . '<!-- wp:site-title /--></div><!-- /wp:site-tagline -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        $safe . $retained,
    );

    $first = HeaderHeroStep::dedupeAgainstHero($header, '', null, '');

    assert_true(!str_contains($first['markup'], $safe), 'the isolated blank tagline is removed');
    assert_contains($retained, $first['markup'], 'the nested identity boundary survives');
    assert_eq(2, count($first['warnings']));
    assert_contains('delivered=removed', $first['warnings'][0]);
    assert_contains(
        "block='wp:site-tagline[1]'",
        $first['warnings'][1],
        'retained warning points at its delivered ordinal, not its stale input ordinal',
    );

    $second = HeaderHeroStep::dedupeAgainstHero($first['markup'], '', null, '');
    assert_eq($first['markup'], $second['markup']);
    assert_eq([$first['warnings'][1]], $second['warnings'], 'the residual warning is fixed-point stable');
});

test('dedupeAgainstHero removes only a duplicate button when its buttons wrapper has a non-button child', function () {
    $button = '<!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" href="/visit/">Reserve a Table</a></div>'
        . '<!-- /wp:button -->';
    $survivor = '<!-- wp:paragraph {"fontSize":"caption"} -->'
        . '<p>Walk-ins welcome after six.</p><!-- /wp:paragraph -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:buttons --><div class="wp-block-buttons">'
            . $button . $survivor
            . '</div><!-- /wp:buttons -->',
    );
    $action = ['label' => 'Reserve a Table', 'intent' => 'Invite a booking', 'destination' => '/visit/'];

    $dedupe = HeaderHeroStep::dedupeAgainstHero($header, '', $action);
    $cleanup = HeroCopyBudget::removeEmptyButtonsWrappers($dedupe['markup'], 'header');
    $result = ['markup' => $cleanup['markup']];

    assert_true(!str_contains($result['markup'], 'Reserve a Table'), 'only the duplicate button is removed');
    assert_contains($survivor, $result['markup'], 'the non-button sibling survives byte-for-byte');
    assert_true(!str_contains($result['markup'], 'wp:buttons'), 'the zero-button wrapper is unwrapped');
    assert_eq(1, count($dedupe['notes']));
    assert_eq(1, count($dedupe['warnings']));
    assert_contains('delivered=removed', $dedupe['warnings'][0]);
    assert_eq(1, count($cleanup['warnings']));
    assert_contains('delivered=unwrapped', $cleanup['warnings'][0]);

    $doc = BlockMarkup::parse($result['markup']);
    assert_eq([], $doc->unclosedIndices(), 'the surviving structure remains balanced');
    assert_true(!$doc->hasMismatchedDelimiters(), 'the surviving structure has no crossed delimiters');
    assert_eq(
        0,
        count(array_filter($doc->indices(), static fn (int $i): bool => $doc->name($i) === 'button')),
    );
    assert_eq(
        1,
        count(array_filter($doc->indices(), static fn (int $i): bool => $doc->name($i) === 'paragraph')),
    );

    $againDedupe = HeaderHeroStep::dedupeAgainstHero($result['markup'], '', $action);
    $againCleanup = HeroCopyBudget::removeEmptyButtonsWrappers($againDedupe['markup'], 'header');
    assert_eq($result['markup'], $againCleanup['markup'], 'the composed removal reaches a fixed point');
    assert_eq([], $againDedupe['notes']);
    assert_eq([], $againDedupe['warnings']);
    assert_eq([], $againCleanup['warnings']);
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
        blueprint: HeroBlueprint::defaultFor('foreground-split'),
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
            'slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'asymmetric-split',
            'background' => 'contrast', 'primary_action' => $action,
        ]],
    ]];
    $delivery = AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor('foreground-split'),
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
    $hero = '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-media-first">'
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

test('a surviving overlay earns the clear resting state and raises a just-short cover dim (BIGR-778)', function () {
    with_project('builder_hh_clear_top_', function ($project) {
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [[
                'slug' => 'hero',
                'role' => 'hero',
                'layout_archetype' => 'full-bleed-cover',
                'background' => 'image',
            ]],
        ], [
            'slug' => 'menu', 'title' => 'Menu', 'front' => false,
            'sections' => [[
                'slug' => 'opening',
                'role' => 'opening',
                'layout_archetype' => 'full-bleed-cover',
                'background' => 'image',
            ]],
        ]];
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages, 'cinematic-safe-zone');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--cinematic-safe-zone hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--cinematic-safe-zone hero-mobile--stack-media-first">'
                . '<!-- wp:cover {"url":"theme:./assets/hero.jpg","align":"full","dimRatio":54,"overlayColor":"contrast"} -->'
                . '<div class="wp-block-cover alignfull"><span aria-hidden="true" '
                . 'class="wp-block-cover__background has-contrast-background-color has-background-dim"></span>'
                . '<div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->'
                . '</div><!-- /wp:group -->',
        );
        $project->writeText(
            'theme/parts/page-menu--opening.html',
            '<!-- wp:group {"anchor":"opening","layout":{"type":"constrained"}} -->'
                . '<div id="opening" class="wp-block-group">'
                . '<!-- wp:cover {"url":"theme:./assets/menu.jpg","align":"full","overlayColor":"contrast"} -->'
                . '<div class="wp-block-cover alignfull"><span aria-hidden="true" '
                . 'class="wp-block-cover__background has-contrast-background-color has-background-dim-100 has-background-dim"></span>'
                . '<div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->'
                . '</div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $artifact = $project->readJson(HeaderBehavior::FILE);
        assert_eq(HeaderBehavior::OVERLAY_TO_SOLID, $artifact['behavior']);
        assert_eq(HeaderBehavior::TREATMENT_TRANSPARENT, $artifact['topTreatment'], 'the proven overlay rests clear');
        assert_contains('header-top-transparent', $project->readText('theme/parts/header.html'));

        // Core renders authored dim 54 through its 50% class, which cannot
        // bound a white pixel for the white foreground; 60 is the smallest
        // supported passing value, recorded as a repair note, not a loss.
        $hero = BlockMarkup::parse($project->readText('theme/parts/page-home--hero.html'));
        $cover = null;
        foreach ($hero->indices() as $i) {
            if ($hero->name($i) === 'cover') {
                $cover = $i;
            }
        }
        assert_true($cover !== null);
        assert_eq(60, ($hero->attrs($cover) ?? [])['dimRatio']);
        assert_contains('has-background-dim-60 has-background-dim', $hero->ownHtml($cover));
        $implicitFullDim = $project->readText('theme/parts/page-menu--opening.html');
        assert_true(!str_contains($implicitFullDim, '"dimRatio"'), 'Core default 100 stays implicit');
        assert_contains('has-background-dim-100 has-background-dim', $implicitFullDim);
        assert_contains('cover dimRatio 54 raised to 60', $project->readText('logs/header-hero.txt'));
        assert_true(!$project->exists('warnings.json'), 'the earned clear state is warning-free');
    });
});

test('a redundant has-background-dim-50 still earns the clear resting state (BIGR-886)', function () {
    // Core's save() omits the numbered class at its 50 default, but the model
    // spells it out routinely and the two render at the same opacity:.5. The
    // grant must read past that cosmetic spelling; treating it as
    // untrustworthy paint left 12 of 22 cohort overlays behind the scrim bar.
    with_project('builder_hh_redundant_dim_', function ($project) {
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [[
                'slug' => 'hero',
                'role' => 'hero',
                'layout_archetype' => 'full-bleed-cover',
                'background' => 'image',
            ]],
        ]];
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages, 'cinematic-safe-zone');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--cinematic-safe-zone hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--cinematic-safe-zone hero-mobile--stack-media-first">'
                . '<!-- wp:cover {"url":"theme:./assets/hero.jpg","align":"full","dimRatio":50,"overlayColor":"contrast"} -->'
                . '<div class="wp-block-cover alignfull"><span aria-hidden="true" '
                . 'class="wp-block-cover__background has-contrast-background-color '
                . 'has-background-dim-50 has-background-dim"></span>'
                . '<div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->'
                . '</div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $artifact = $project->readJson(HeaderBehavior::FILE);
        assert_eq(HeaderBehavior::OVERLAY_TO_SOLID, $artifact['behavior']);
        assert_eq(HeaderBehavior::TREATMENT_TRANSPARENT, $artifact['topTreatment'], 'the proven overlay rests clear');
        assert_contains('header-top-transparent', $project->readText('theme/parts/header.html'));

        // The raise leaves exactly one numbered opacity class on the span:
        // stacking the redundant 50 beside the new value would hand Core two
        // competing opacity rules for the paint the grant just proved.
        $hero = $project->readText('theme/parts/page-home--hero.html');
        assert_contains('"dimRatio":60', $hero);
        assert_contains('has-background-dim-60 has-background-dim', $hero);
        assert_true(!str_contains($hero, 'has-background-dim-50'), 'the redundant 50 class must be gone');
        assert_eq(1, substr_count($hero, 'has-background-dim-'));
        assert_true(!$project->exists('warnings.json'), 'the earned clear state is warning-free');
    });
});

test('competing cover paint keeps a surviving overlay behind its scrim veil (BIGR-778)', function () {
    with_project('builder_hh_competing_paint_', function ($project) {
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [[
                'slug' => 'hero',
                'role' => 'hero',
                'layout_archetype' => 'full-bleed-cover',
                'background' => 'image',
            ]],
        ]];
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages, 'cinematic-safe-zone');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--cinematic-safe-zone hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--cinematic-safe-zone hero-mobile--stack-media-first">'
                . '<!-- wp:cover {"url":"theme:./assets/hero.jpg","align":"full","dimRatio":60,"overlayColor":"contrast","customGradient":"linear-gradient(#fff,#fff)"} -->'
                . '<div class="wp-block-cover alignfull"></div><!-- /wp:cover -->'
                . '</div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $artifact = $project->readJson(HeaderBehavior::FILE);
        assert_eq(HeaderBehavior::OVERLAY_TO_SOLID, $artifact['behavior']);
        assert_eq(HeaderBehavior::TREATMENT_GLASS, $artifact['topTreatment']);
        assert_true(!str_contains($project->readText('theme/parts/header.html'), 'header-top-transparent'));
        assert_contains('customGradient', $project->readText('theme/parts/page-home--hero.html'));
    });
});

test('an unprovable clear resting state keeps the scrim veil and the delivered dim (BIGR-778)', function () {
    with_project('builder_hh_veiled_top_', function ($project) {
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [[
                'slug' => 'hero',
                'role' => 'hero',
                'layout_archetype' => 'full-bleed-cover',
                'background' => 'image',
            ]],
        ]];
        // A mid-gray protection token can never bound a white image pixel at
        // any grantable dim, so the kit scrim must stay.
        $theme = hh_theme_json();
        foreach ($theme['settings']['color']['palette'] as &$entry) {
            if ($entry['slug'] === 'contrast') {
                $entry['color'] = '#666666';
            }
        }
        unset($entry);
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', $theme);
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages, 'cinematic-safe-zone', $theme);
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
        assert_eq(HeaderBehavior::TREATMENT_GLASS, $artifact['topTreatment'], 'no proof, no clear state');
        assert_true(!str_contains($project->readText('theme/parts/header.html'), 'header-top-transparent'));
        assert_contains('"dimRatio":50', $project->readText('theme/parts/page-home--hero.html'));
    });
});

/** Seed one contract-null hero fixture for HTML-first/legacy action reconciliation tests. */
function hh_null_action_fixture($project, string $heroMarkup): void
{
    $pages = [[
        'slug' => 'home',
        'title' => 'Home',
        'path' => '/',
        'front' => true,
        'sections' => [[
            'slug' => 'hero',
            'role' => 'hero',
            'layout_archetype' => 'asymmetric-split',
            'background' => 'base',
            'primary_action' => null,
        ]],
    ]];
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', hh_theme_json());
    $project->writeJson('pages.json', ['pages' => $pages]);
    hh_above_fold($project, $pages);
    $project->writeText(
        'theme/parts/header.html',
        hh_header('{"className":"header-archetype--standard-row","backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}}'),
    );
    $project->writeText('theme/parts/page-home--hero.html', $heroMarkup);
}

/** Wrap authored hero children in the contract-required composition root. */
function hh_authored_hero(string $children): string
{
    return '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-media-first">'
        . $children
        . '</div><!-- /wp:group -->';
}

test('HTML-first HeaderHeroStep retains anchor-derived hero buttons when the contract action is null', function () {
    with_project('builder_hh_html_first_actions_', function ($project) {
        $heading = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Build what matters</h1><!-- /wp:heading -->';
        $primary = '<!-- wp:button {"backgroundColor":"accent"} --><div class="wp-block-button"><a class="wp-block-button__link has-accent-background-color has-background wp-element-button" href="#hero">Book a consultation</a></div><!-- /wp:button -->';
        $secondary = '<!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#hero">The coaching approach</a></div><!-- /wp:button -->';
        $buttons = '<!-- wp:buttons --><div class="wp-block-buttons">' . $primary . $secondary . '</div><!-- /wp:buttons -->';
        hh_null_action_fixture($project, hh_authored_hero($heading . $buttons));

        (new HeaderHeroStep(htmlFirst: true))->run($project);

        $delivered = $project->readText('theme/parts/page-home--hero.html');
        assert_contains($primary, $delivered);
        assert_contains($secondary, $delivered);
        assert_contains($buttons, $delivered, 'authored action row survives byte-for-byte');
        $document = BlockMarkup::parse($delivered);
        assert_eq(2, count(array_filter(
            $document->indices(),
            static fn (int $index): bool => $document->name($index) === 'button',
        )));
    });
});

test('HeaderHeroStep leaves no zero-button wp:buttons wrapper after HTML-first or legacy pruning', function () {
    foreach ([true, false] as $htmlFirst) {
        with_project('builder_hh_empty_actions_', function ($project) use ($htmlFirst) {
            $heading = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading -->';
            $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/missing/">Missing section</a></div><!-- /wp:button -->';
            $wrapper = '<!-- wp:buttons --><div class="wp-block-buttons">'
                . ($htmlFirst ? '' : $button)
                . '</div><!-- /wp:buttons -->';
            hh_null_action_fixture($project, hh_authored_hero($heading . $wrapper));

            (new HeaderHeroStep(htmlFirst: $htmlFirst))->run($project);

            $delivered = $project->readText('theme/parts/page-home--hero.html');
            assert_true(
                preg_match('/<div class="wp-block-buttons[^"]*">\s*<\/div>/', $delivered) !== 1,
                ($htmlFirst ? 'HTML-first' : 'legacy') . ' path must not leave an empty saved-HTML shell',
            );
            $document = BlockMarkup::parse($delivered);
            foreach ($document->indices() as $index) {
                if ($document->name($index) !== 'buttons') {
                    continue;
                }
                assert_true(
                    array_filter(
                        $document->children($index),
                        static fn (int $child): bool => $document->name($child) === 'button',
                    ) !== [],
                    ($htmlFirst ? 'HTML-first' : 'legacy') . ' path must not retain a zero-button wrapper',
                );
            }
        });
    }
});

test('legacy HeaderHeroStep still removes hero buttons for a null BIGR-800 action', function () {
    with_project('builder_hh_bigr_800_guard_', function ($project) {
        $sibling = '<!-- wp:paragraph --><p>Authorized sibling stays byte-for-byte.</p><!-- /wp:paragraph -->';
        $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/missing/">Missing section</a></div><!-- /wp:button -->';
        $wrapper = '<!-- wp:buttons --><div class="wp-block-buttons">' . $button . '</div><!-- /wp:buttons -->';
        hh_null_action_fixture(
            $project,
            hh_authored_hero('<!-- wp:heading {"level":1} --><h1>Hero</h1><!-- /wp:heading -->' . $sibling . $wrapper),
        );

        (new HeaderHeroStep())->run($project);

        $delivered = $project->readText('theme/parts/page-home--hero.html');
        assert_contains($sibling, $delivered);
        assert_true(!str_contains($delivered, $button));
        assert_true(!str_contains($delivered, 'wp:buttons'));
        $warnings = implode("\n", $project->readJson('warnings.json')['header-hero'] ?? []);
        assert_contains('no primary action was assigned', $warnings);
        assert_contains('delivered=removed', $warnings);
    });
});

test('HeaderHeroStep unwraps a header action row emptied by duplicate CTA removal', function () {
    with_project('builder_hh_header_action_cleanup_', function ($project) {
        $action = [
            'label' => 'Reserve a Table',
            'intent' => 'Invite a booking',
            'destination' => '#hero',
        ];
        $pages = [[
            'slug' => 'home',
            'title' => 'Home',
            'path' => '/',
            'front' => true,
            'sections' => [[
                'slug' => 'hero',
                'role' => 'hero',
                'layout_archetype' => 'asymmetric-split',
                'background' => 'base',
                'primary_action' => $action,
            ]],
        ]];
        $headerButton = '<!-- wp:button --><div class="wp-block-button">'
            . '<a class="wp-block-button__link wp-element-button" href="#hero">Reserve a Table</a>'
            . '</div><!-- /wp:button -->';
        $paragraph = '<!-- wp:paragraph {"fontSize":"caption"} -->'
            . '<p class="has-caption-font-size">Walk-ins welcome after six.</p><!-- /wp:paragraph -->';
        $nested = '<!-- wp:group {"className":"booking-note","layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group booking-note">' . $paragraph . '</div><!-- /wp:group -->';
        $raw = '<span class="raw-booking-note">Kitchen closes at ten.</span>';
        $headerActions = '<!-- wp:buttons {"className":"header-actions"} -->'
            . '<div class="wp-block-buttons header-actions">' . $headerButton . $nested . $raw . '</div>'
            . '<!-- /wp:buttons -->';
        $heroButton = '<!-- wp:button --><div class="wp-block-button">'
            . '<a class="wp-block-button__link wp-element-button" href="#hero">Reserve a Table</a>'
            . '</div><!-- /wp:button -->';
        $heroActions = '<!-- wp:buttons --><div class="wp-block-buttons">'
            . $heroButton . '</div><!-- /wp:buttons -->';

        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages);
        $project->writeText(
            'theme/parts/header.html',
            hh_header(
                '{"className":"header-archetype--standard-row","backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}}',
                $headerActions,
            ),
        );
        $hero = hh_authored_hero(
            '<!-- wp:heading {"level":1} --><h1>Join us tonight</h1><!-- /wp:heading -->' . $heroActions,
        );
        $project->writeText('theme/parts/page-home--hero.html', $hero);

        (new HeaderHeroStep(htmlFirst: true))->run($project);

        $deliveredHeader = $project->readText('theme/parts/header.html');
        assert_true(!str_contains($deliveredHeader, $headerButton));
        assert_true(!str_contains($deliveredHeader, 'wp:buttons'));
        assert_true(!str_contains($deliveredHeader, 'wp-block-buttons'));
        assert_contains($nested, $deliveredHeader);
        assert_contains($paragraph, $deliveredHeader);
        assert_contains($raw, $deliveredHeader);
        assert_eq(1, substr_count($deliveredHeader, $nested));
        assert_eq(1, substr_count($deliveredHeader, $raw));
        assert_contains($heroButton, $project->readText('theme/parts/page-home--hero.html'));

        $headerWarnings = array_values(array_filter(
            $project->readJson('warnings.json')['header-hero'] ?? [],
            static fn (string $warning): bool => str_contains($warning, "file='theme/parts/header.html'"),
        ));
        assert_eq(2, count($headerWarnings), 'button removal and wrapper unwrap warn once each');
        assert_eq(1, count(array_filter(
            $headerWarnings,
            static fn (string $warning): bool => str_contains($warning, "block='wp:button[1]'"),
        )));
        assert_eq(1, count(array_filter(
            $headerWarnings,
            static fn (string $warning): bool => str_contains($warning, "block='wp:buttons[1]'")
                && str_contains($warning, 'delivered=unwrapped'),
        )));

        $againDedupe = HeaderHeroStep::dedupeAgainstHero($deliveredHeader, $hero, $action);
        $againCleanup = HeroCopyBudget::removeEmptyButtonsWrappers($againDedupe['markup'], 'header');
        assert_eq($deliveredHeader, $againCleanup['markup']);
        assert_eq([], $againDedupe['notes']);
        assert_eq([], $againDedupe['warnings']);
        assert_eq([], $againCleanup['warnings']);
    });
});

test('the HTML-first hero re-assertion respects a root the design already measures', function () {
    // header-hero re-asserts the part layout contract after its own repairs,
    // and it runs AFTER normalize-layout. Without the design's class list it
    // would stamp a hero root that owns its width — the same policy footer and
    // header roots already get, applied inconsistently to the hero.
    $run = function (string $rootClass) {
        return with_project('builder_hh_wide_' . $rootClass . '_', function ($project) use ($rootClass) {
            $pages = [[
                'slug' => 'home', 'title' => 'Home', 'front' => true,
                'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
            ]];
            $project->writeJson('siteSpec.json', ['name' => 'Demo']);
            $project->writeJson('theme/theme.json', hh_theme_json());
            $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
            $project->writeJson('pages.json', ['pages' => $pages]);
            hh_above_fold($project, $pages);
            $project->writeText(
                'design/site.css',
                ':root{--wide-size:1280px}.hero-inner{max-width:var(--wide-size);margin:0 auto}'
            );
            $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
            $project->writeText(
                'theme/parts/page-home--hero.html',
                '<!-- wp:group {"className":"' . $rootClass . '"} -->'
                . '<div class="wp-block-group ' . $rootClass . '">'
                . '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group -->' . "\n"
            );

            putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
            (new HeaderHeroStep(htmlFirst: true))->run($project);

            return $project->readText('theme/parts/page-home--hero.html');
        });
    };

    assert_true(
        !str_contains($run('hero-inner'), '"layout":{"type":"constrained"}'),
        'a hero root the design gives the wide measure keeps its own width',
    );
    // Non-vacuity: a root the stylesheet does not measure is still constrained.
    assert_contains('"layout":{"type":"constrained"}', $run('plain-hero'));
});

test('header-hero declares the design stylesheet it reads on the HTML-first graph', function () {
    assert_true(
        in_array('design/site.css', (new HeaderHeroStep(htmlFirst: true))->declaration()->reads, true),
        'HTML-first header-hero declares the design stylesheet it reads',
    );
    assert_true(
        !in_array('design/site.css', (new HeaderHeroStep())->declaration()->reads, true),
        'the legacy graph, which writes no design/site.css, does not declare it',
    );
});

test('header-hero does not restore a planned Add to cart label after storefront degrade', function () {
    $tmp = sys_get_temp_dir() . '/builder_hh_cart_restore_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', hh_theme_json());
    $action = ['label' => 'Add to cart', 'intent' => 'Buy the product.', 'destination' => '/cart'];
    $pages = [
        [
            'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
            'sections' => [[
                'slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'asymmetric-split',
                'background' => 'base', 'primary_action' => $action,
            ]],
        ],
        // A live /cart route is what lets reconcile restore the planned label
        // instead of treating the button as a dead control.
        [
            'slug' => 'cart', 'title' => 'Cart', 'path' => '/cart', 'front' => false,
            'sections' => [['slug' => 'body', 'role' => 'section', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ],
    ];
    $project->writeJson('pages.json', ['pages' => $pages]);
    hh_above_fold($project, $pages);
    $project->writeText(
        'theme/parts/header.html',
        hh_header('{"className":"header-archetype--standard-row","backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}}'),
    );
    $button = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link wp-element-button" href="/cart">Enquire</a>'
        . '</div><!-- /wp:button -->';
    $project->writeText(
        'theme/parts/page-home--hero.html',
        hh_authored_hero(
            '<!-- wp:heading {"level":1} --><h1>Shop the catalog</h1><!-- /wp:heading -->'
            . '<!-- wp:buttons --><div class="wp-block-buttons">' . $button . '</div><!-- /wp:buttons -->',
        ),
    );

    putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
    (new HeaderHeroStep())->run($project);

    $delivered = $project->readText('theme/parts/page-home--hero.html');
    assert_contains('Enquire', $delivered);
    assert_true(!str_contains($delivered, 'Add to cart'), 'reconcile must not put the purchase label back');

    exec('rm -rf ' . escapeshellarg($tmp));
});

/**
 * A split-nav header: two navigations flanking the wordmark, each holding its
 * own hand-authored links (the one archetype that authors links by hand).
 */
function hh_split_nav_header(string $leftAttrs = '', string $rightAttrs = ''): string
{
    return hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:navigation ' . ($leftAttrs !== '' ? $leftAttrs . ' ' : '') . '-->'
        . '<!-- wp:navigation-link {"label":"About","url":"/about/"} /-->'
        . '<!-- wp:navigation-link {"label":"Services","url":"/services/"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation ' . ($rightAttrs !== '' ? $rightAttrs . ' ' : '') . '-->'
        // Deliberately no Home item: BIGR-863 strips those before this pass
        // runs, and a fixture that leans on one would measure that strip
        // rather than the consolidation. hh_split_nav_with_home() covers the
        // interaction on purpose.
        . '<!-- wp:navigation-link {"label":"Journal","url":"/journal/"} /-->'
        . '<!-- wp:navigation-link {"label":"Contact","url":"/contact/"} /-->'
        . '<!-- /wp:navigation -->',
    );
}

/**
 * Every wp:navigation in the markup as [overlayMenu, className], in document
 * order — the emitted values, not merely their presence.
 *
 * @return list<array{string,string}>
 */
function hh_nav_states(string $markup): array
{
    $doc = BlockMarkup::parse($markup);
    $out = [];
    foreach ($doc->indices() as $i) {
        if ($doc->name($i) !== 'navigation') {
            continue;
        }
        $attrs = $doc->attrs($i) ?? [];
        $out[] = [(string) ($attrs['overlayMenu'] ?? ''), (string) ($attrs['className'] ?? '')];
    }
    return $out;
}

/**
 * Every navigation-link label in document order, paired with its className,
 * grouped by the nav it sits in.
 *
 * @return list<list<array{string,string}>>
 */
function hh_nav_items(string $markup): array
{
    $doc = BlockMarkup::parse($markup);
    $out = [];
    foreach ($doc->indices() as $i) {
        if ($doc->name($i) !== 'navigation') {
            continue;
        }
        $items = [];
        foreach ($doc->children($i) as $child) {
            $attrs = $doc->attrs($child) ?? [];
            $items[] = [
                (string) ($attrs['label'] ?? $doc->name($child)),
                (string) ($attrs['className'] ?? ''),
            ];
        }
        $out[] = $items;
    }
    return $out;
}

test('a header nav authored overlayMenu:never is raised to mobile', function () {
    // The reported failure: core's is_responsive() treats "never" as opt-out
    // and returns the bare inner list, so the header renders NO hamburger at
    // any width and its nowrap row runs off a 375px screen.
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation {"overlayMenu":"never","fontSize":"caption"} -->'
        . '<!-- wp:navigation-link {"label":"Menu"} /--><!-- wp:navigation-link {"label":"Visit"} /-->'
        . '<!-- /wp:navigation -->',
    );
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');

    assert_eq([['mobile', '']], hh_nav_states($result['markup']));
    assert_true(
        !str_contains($result['markup'], '"overlayMenu":"never"'),
        'no header nav may keep the value that disables the hamburger',
    );
    assert_contains('overlayMenu:never raised to mobile', implode(' ', $result['notes']));
});

test('a header nav authored overlayMenu:always is lowered to mobile', function () {
    // always paints a hamburger at every width, including a 1440px desktop.
    // mobile is the core default: inline nav on desktop, hamburger below the
    // breakpoint. BIGR-856.
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation {"overlayMenu":"always","fontSize":"caption"} -->'
        . '<!-- wp:navigation-link {"label":"Menu"} /--><!-- wp:navigation-link {"label":"Visit"} /-->'
        . '<!-- /wp:navigation -->',
    );
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');

    assert_eq([['mobile', '']], hh_nav_states($result['markup']));
    assert_true(
        !str_contains($result['markup'], '"overlayMenu":"always"'),
        'no header menu nav may keep the value that is a hamburger on desktop',
    );
    assert_contains('overlayMenu:always lowered to mobile', implode(' ', $result['notes']));
});

test('a lone header nav that already collapses is left exactly as authored', function () {
    foreach (['', '{"overlayMenu":"mobile"}'] as $attrs) {
        $markup = hh_header(
            '{"backgroundColor":"base","layout":{"type":"constrained"}}',
            '<!-- wp:site-title /--><!-- wp:navigation ' . ($attrs !== '' ? $attrs . ' ' : '') . '-->'
            . '<!-- wp:navigation-link {"label":"Menu"} /--><!-- wp:navigation-link {"label":"Visit"} /-->'
            . '<!-- /wp:navigation -->',
        );
        $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');
        assert_eq($markup, $result['markup'], "a one-nav header needs no consolidation ({$attrs})");
        assert_eq([], $result['notes']);
    }
});

test('a two-nav header collapses to one menu carrying every link', function () {
    // Two navs each get their own hamburger below the breakpoint — two
    // identical toggles flanking the wordmark, each opening half the site.
    $result = HeaderHeroStep::fixHeader(
        hh_split_nav_header(),
        AboveFoldContract::MODE_STACKED,
        'Demo',
    );

    // The LAST nav is the menu, so the toggle lands at the end of the row and
    // a space-between header leaves the wordmark at the start. The first is
    // hidden below the breakpoint and must NOT emit a toggle of its own —
    // the one place "never" is the right value.
    assert_eq(
        [['never', HeaderHeroStep::NAV_WIDE_ONLY_CLASS], ['', '']],
        hh_nav_states($result['markup']),
    );

    // Every link reaches the collapsed menu exactly once, still in document
    // order, and the copies are the ones the theme hides above the breakpoint.
    assert_eq(
        [
            [
                ['About', ''],
                ['Services', ''],
            ],
            [
                ['About', HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS],
                ['Services', HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS],
                ['Journal', ''],
                ['Contact', ''],
            ],
        ],
        hh_nav_items($result['markup']),
    );
    assert_contains('header collapses to one menu', implode(' ', $result['notes']));
});

/** @return array<int,array<string,mixed>> the three-page fixture BIGR-863 uses. */
function hh_pages_with_home(): array
{
    return [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'About', 'slug' => 'about', 'path' => '/about/', 'front' => false],
        ['title' => 'Contact', 'slug' => 'contact', 'path' => '/contact/', 'front' => false],
    ];
}

/** fixHeader with the pages argument, which needs every optional before it. */
function hh_fix_with_pages(string $markup, array $pages): array
{
    return HeaderHeroStep::fixHeader(
        $markup,
        AboveFoldContract::MODE_STACKED,
        'Demo',
        [],
        false,
        '',
        '',
        '',
        null,
        [],
        $pages,
    );
}

test('the Home strip runs before the copies are taken', function () {
    // Ordering, not decoration. BIGR-863 REPLACES a page-list with inner-page
    // links; consolidation COPIES nav items. Copy first and the copy is of a
    // page-list that no longer exists by the time the strip is done — measured,
    // the collapsed menu ends up with ZERO copies and the leading nav's links
    // are reachable at desktop only. Strip first and both survive.
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Contact","url":"/contact/"} /-->'
        . '<!-- /wp:navigation -->',
    );
    $result = hh_fix_with_pages($markup, hh_pages_with_home());

    assert_eq(
        [
            [['About', ''], ['Contact', '']],
            [
                ['About', HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS],
                ['Contact', HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS],
                ['Contact', ''],
            ],
        ],
        hh_nav_items($result['markup']),
    );
});

test('no Home item survives into the collapsed menu', function () {
    // The other half of the same ordering: a plain Home link must not reach
    // the menu as an overlay-only copy, where it would be the only place it
    // still rendered.
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->'
        . '<!-- wp:navigation-link {"label":"About","url":"/about/"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Contact","url":"/contact/"} /-->'
        . '<!-- /wp:navigation -->',
    );
    $result = hh_fix_with_pages($markup, hh_pages_with_home());

    assert_eq(
        [
            [['About', '']],
            [['About', HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS], ['Contact', '']],
        ],
        hh_nav_items($result['markup']),
    );
    assert_true(
        !str_contains($result['markup'], '"label":"Home"'),
        'no Home item may survive anywhere, copies included',
    );
});

test('consolidating a two-nav header is a fixed point', function () {
    // Re-running must not copy the copies: the pass runs on every build, and
    // fix-blocks re-serializes this markup afterwards.
    $once = HeaderHeroStep::fixHeader(hh_split_nav_header(), AboveFoldContract::MODE_STACKED, 'Demo');
    $twice = HeaderHeroStep::fixHeader($once['markup'], AboveFoldContract::MODE_STACKED, 'Demo');
    $thrice = HeaderHeroStep::fixHeader($twice['markup'], AboveFoldContract::MODE_STACKED, 'Demo');

    assert_eq($once['markup'], $twice['markup']);
    assert_eq($twice['markup'], $thrice['markup']);
    assert_eq([], $twice['notes']);
});

test('a never-valued split nav is both raised and consolidated', function () {
    // The delivered rustic-orchard shape: both halves authored "never".
    $result = HeaderHeroStep::fixHeader(
        hh_split_nav_header('{"overlayMenu":"never"}', '{"overlayMenu":"never"}'),
        AboveFoldContract::MODE_STACKED,
        'Super Coaching',
    );
    assert_eq(
        [['never', HeaderHeroStep::NAV_WIDE_ONLY_CLASS], ['mobile', '']],
        hh_nav_states($result['markup']),
    );
});

test('an always-valued split nav keeps one mobile hamburger, not two desktop ones', function () {
    // Two overlayMenu:always halves would be two hamburgers on a desktop
    // viewport. The trailing nav is the one menu (mobile); the leading half
    // is wide-only and never, so desktop still shows both inline rows.
    $result = HeaderHeroStep::fixHeader(
        hh_split_nav_header('{"overlayMenu":"always"}', '{"overlayMenu":"always"}'),
        AboveFoldContract::MODE_STACKED,
        'Demo',
    );
    assert_eq(
        [['never', HeaderHeroStep::NAV_WIDE_ONLY_CLASS], ['mobile', '']],
        hh_nav_states($result['markup']),
    );
    assert_true(!str_contains($result['markup'], '"overlayMenu":"always"'));
});

test('overlay-only copies do not count against the single-row width budget', function () {
    // The copies are hidden at the width this estimate is about. Charging for
    // them would push a comfortable row over the budget and collapse a desktop
    // header that never needed collapsing.
    $before = HeaderHeroStep::estimatedRowWidth(BlockMarkup::parse(hh_split_nav_header()), 'Demo');
    $after = HeaderHeroStep::fixHeader(hh_split_nav_header(), AboveFoldContract::MODE_STACKED, 'Demo');

    assert_eq($before, HeaderHeroStep::estimatedRowWidth(BlockMarkup::parse($after['markup']), 'Demo'));
    assert_true(
        !str_contains($after['markup'], '"overlayMenu":"always"'),
        'consolidation must not trip the over-wide collapse',
    );
});

test('a second nav page-list is not copied over the menu own page-list', function () {
    // Two page-lists in one menu render every site page twice.
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:navigation --><!-- wp:page-list /-->'
        . '<!-- wp:navigation-link {"label":"Contact"} /--><!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->',
    );
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', ['Menu', 'Visit']);

    // The hidden nav's link is copied; its page-list is not, because the menu
    // already carries one.
    assert_eq(
        [
            [['page-list', ''], ['Contact', '']],
            [['Contact', HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS], ['page-list', '']],
        ],
        hh_nav_items($result['markup']),
    );
});

test('the theme stylesheet hides exactly one of each consolidated pair', function () {
    // The repair marks the markup; only these rules make the marks mean
    // anything. Renaming a constant without the stylesheet fails here.
    $tmp = sys_get_temp_dir() . '/hh-css-' . uniqid();
    $project = (new ProjectStore($tmp))->create('Demo');
    (new ScaffoldThemeStep())->run($project);
    $css = $project->readText('theme/style.css');

    assert_contains('@media (max-width: 719.98px)', $css);
    assert_contains('.wp-site-blocks .' . HeaderHeroStep::NAV_WIDE_ONLY_CLASS, $css);
    assert_contains('@media (min-width: 720px)', $css);
    assert_contains('.wp-site-blocks .' . HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS, $css);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the active menu row inverts the panel pair instead of picking a color', function () {
    // The panel's surface is chosen per build — base for a static header, the
    // resolved scrolled surface for sticky-soft/overlay-to-solid — so the
    // active row cannot name a color of its own. Two ways that has gone wrong:
    // a fixed `accent`, invisible on every accent-surfaced panel (1.0:1); and
    // a low-alpha tint of the ink, which drags the background TOWARD the text
    // and drops contrast as it strengthens (14% measured 3.8:1, under AA).
    // Only swapping the panel's own two colors keeps the proven ratio.
    $tmp = sys_get_temp_dir() . '/hh-hover-' . uniqid();
    $project = (new ProjectStore($tmp))->create('Demo');
    (new ScaffoldThemeStep())->run($project);
    $css = $project->readText('theme/style.css');

    // Every rule block whose selector reaches into an open menu panel AND
    // carries :hover/:focus.
    preg_match_all('/([^{}]*is-menu-open[^{}]*)\{([^}]*)\}/', $css, $blocks, PREG_SET_ORDER);
    $checked = 0;
    foreach ($blocks as [, $selector, $body]) {
        if (!str_contains($selector, ':hover') && !str_contains($selector, ':focus')) {
            continue;
        }
        $checked++;
        $where = ' (selector: ' . trim(preg_replace('/\s+/', ' ', $selector) ?? '') . ')';

        // Neither half of the pair may be pinned to a palette slug.
        preg_match_all('/(?<![-\w])(background-color|color)\s*:\s*([^;]+);/', $body, $decls, PREG_SET_ORDER);
        $seen = [];
        foreach ($decls as [, $prop, $value]) {
            $seen[$prop] = trim($value);
            assert_true(
                !str_contains($value, '--wp--preset--color--'),
                "menu active {$prop} must not be a fixed palette slug, got: " . trim($value) . $where,
            );
            // A translucent fill silently lowers contrast against whatever it
            // sits on; the active row must be opaque.
            assert_true(
                !str_contains($value, 'color-mix') && !str_contains($value, 'rgba('),
                "menu active {$prop} must be opaque, got: " . trim($value) . $where,
            );
        }

        // And it must actually be the inversion: panel ink behind panel surface.
        assert_contains('--menu-panel-ink', $seen['background-color'] ?? '', 'active row background' . $where);
        assert_contains('--menu-panel-surface', $seen['color'] ?? '', 'active row label' . $where);
    }
    assert_true($checked > 0, 'the menu panel should have at least one hover/focus rule to police');

    // The row the bar paints must carry inline padding, or the fill is a band
    // cropped to the glyphs. Asserted on that rule's own body — `padding-inline`
    // occurs elsewhere in this stylesheet, so a whole-file search proves nothing.
    $itemRule = null;
    foreach ($blocks as [, $selector, $body]) {
        if (str_contains($selector, 'navigation-item__content:not(.wp-element-button)')
            && !str_contains($selector, ':hover')
        ) {
            $itemRule = $body;
        }
    }
    assert_true($itemRule !== null, 'the menu item rule should exist');
    assert_contains('padding-inline:', (string) $itemRule, 'menu row needs inline padding for the bar');
    assert_contains('margin-inline:', (string) $itemRule, 'the bar must bleed without shifting the label');

    // Both panels must publish the pair, or the inversion falls back to a
    // currentColor bar with an inherited label — the invisible case again.
    assert_contains('--menu-panel-ink: var(--wp--preset--color--contrast)', $css);
    assert_contains('--menu-panel-surface: var(--wp--preset--color--base)', $css);
    $kit = $project->readText('theme/assets/header/header.css');
    assert_contains('--menu-panel-ink: var(--header-foreground)', $kit);
    assert_contains('--menu-panel-surface: var(--header-scrolled-solid)', $kit);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a copied submenu carries its own links across whole', function () {
    // overlayOnlyClone rewrites ONLY the opening delimiter and carries the
    // rest of the node's source verbatim, so a nested subtree has to survive
    // intact. The docblock claims that; nothing else proved it.
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:navigation -->'
        . '<!-- wp:navigation-submenu {"label":"Work","url":"/work/"} -->'
        . '<!-- wp:navigation-link {"label":"Studio","url":"/studio/"} /-->'
        . '<!-- wp:navigation-link {"label":"Field","url":"/field/"} /-->'
        . '<!-- /wp:navigation-submenu -->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Contact"} /--><!-- /wp:navigation -->',
    );
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');

    $doc = BlockMarkup::parse($result['markup']);
    $navs = [];
    foreach ($doc->indices() as $i) {
        if ($doc->name($i) === 'navigation') {
            $navs[] = $i;
        }
    }
    $menu = $navs[count($navs) - 1];
    $copy = $doc->children($menu)[0];

    assert_eq('navigation-submenu', $doc->name($copy));
    assert_eq(
        HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS,
        (string) (($doc->attrs($copy) ?? [])['className'] ?? ''),
    );
    // Hiding the submenu hides these with it, so they stay unmarked.
    assert_eq(
        [['Studio', ''], ['Field', '']],
        array_map(
            static fn (int $c): array => [
                (string) (($doc->attrs($c) ?? [])['label'] ?? ''),
                (string) (($doc->attrs($c) ?? [])['className'] ?? ''),
            ],
            $doc->children($copy),
        ),
    );
});

test('a three-nav header collapses to the one trailing menu', function () {
    // array_slice($navs, 0, -1) is written for N leading navs; only the
    // two-nav case was covered.
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:navigation --><!-- wp:navigation-link {"label":"About"} /--><!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Work"} /--><!-- /wp:navigation -->'
        . '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Contact"} /--><!-- /wp:navigation -->',
    );
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');

    assert_eq(
        [
            ['never', HeaderHeroStep::NAV_WIDE_ONLY_CLASS],
            ['never', HeaderHeroStep::NAV_WIDE_ONLY_CLASS],
            ['', ''],
        ],
        hh_nav_states($result['markup']),
    );
    assert_eq(
        [
            [['About', '']],
            [['Work', '']],
            [
                ['About', HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS],
                ['Work', HeaderHeroStep::NAV_OVERLAY_ONLY_CLASS],
                ['Contact', ''],
            ],
        ],
        hh_nav_items($result['markup']),
    );
});

test('the header prompt forbids overlayMenu always and never', function () {
    $prompt = (string) file_get_contents(repo_path('prompts/header.md'));
    assert_contains('NEVER write `"overlayMenu":"always"`', $prompt);
    assert_contains('NEVER write `"overlayMenu":"never"`', $prompt);
    assert_contains('inline links on desktop', $prompt);
    assert_contains('exactly one hamburger on mobile', $prompt);
});

test('the build stamps the blueprint media aspect on the hero root (BIGR-925)', function () {
    // BIGR-912 moved the portrait plate's aspect-ratio onto a hero-media--
    // class, but only the model wrote it, so a model that skipped it silently
    // lost the contained vertical frame. The build stamps it now, the same way
    // it stamps hero-mobile--, so the CSS hook cannot go missing.
    with_project('builder_hh_aspect_', function ($project) {
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
            'sections' => [[
                'slug' => 'hero', 'role' => 'hero',
                'layout_archetype' => 'asymmetric-split', 'background' => 'base',
            ]],
        ]];
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => $pages]);

        $blueprint = HeroBlueprint::defaultFor('foreground-split');
        $blueprint['media_aspect'] = 'portrait';
        $delivery = AboveFoldContract::resolve(
            $pages,
            $blueprint,
            'full-bleed',
            hh_theme_json(),
            ['stable_id' => 'hh-aspect', 'writing_direction' => 'ltr', 'page_count' => 1],
            ['archetype' => 'minimal-columns', 'surface' => 'base'],
        );
        assert_eq('portrait', $delivery['media_aspect'], 'the contract carries the committed aspect');
        $project->writeJson('aboveFold.json', $delivery);

        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}'));
        // The authored hero deliberately omits the class the CSS keys on.
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-copy-first","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-copy-first">'
                . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading -->'
                . '</div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $hero = $project->readText('theme/parts/page-home--hero.html');
        // Once in the rendered class attribute, and once in the block comment's
        // JSON, where a literal `--` is illegal inside an HTML comment and is
        // therefore escaped.
        assert_eq(1, substr_count($hero, 'class="wp-block-group hero-composition--foreground-split '
            . 'hero-mobile--stack-copy-first hero-media--portrait"'));
        assert_eq(1, substr_count($hero, 'hero-media--portrait'));
        assert_contains('hero-composition--foreground-split', $hero);
        assert_contains('hero-mobile--stack-copy-first', $hero);
    });
});

test('a stale media-aspect marker on an authored hero is replaced, not doubled (BIGR-925)', function () {
    with_project('builder_hh_aspect2_', function ($project) {
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
            'sections' => [[
                'slug' => 'hero', 'role' => 'hero',
                'layout_archetype' => 'asymmetric-split', 'background' => 'base',
            ]],
        ]];
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'calm']);
        $project->writeJson('pages.json', ['pages' => $pages]);

        $blueprint = HeroBlueprint::defaultFor('foreground-split');
        $blueprint['media_aspect'] = 'square';
        $project->writeJson('aboveFold.json', AboveFoldContract::resolve(
            $pages,
            $blueprint,
            'full-bleed',
            hh_theme_json(),
            ['stable_id' => 'hh-aspect2', 'writing_direction' => 'ltr', 'page_count' => 1],
            ['archetype' => 'minimal-columns', 'surface' => 'base'],
        ));

        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}'));
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-copy-first hero-media--portrait","layout":{"type":"constrained"}} -->'
                . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-copy-first hero-media--portrait">'
                . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading -->'
                . '</div><!-- /wp:group -->',
        );

        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $hero = $project->readText('theme/parts/page-home--hero.html');
        assert_contains('hero-media--square', $hero);
        // Both spellings must be gone: the rendered class attribute, and the
        // block comment's JSON, which escapes a `--` that is illegal inside an
        // HTML comment.
        assert_true(
            !str_contains($hero, 'hero-media--portrait')
                && !str_contains($hero, 'hero-media\u002d\u002dportrait'),
            'the model\'s contradicting aspect marker is replaced by the committed one',
        );
    });
});

test('ensureSiteLogoMark inserts a tagged site-logo before the site title', function () {
    $in = hh_header('{"layout":{"type":"flex"}}', '<!-- wp:site-title /-->');
    $out = HeaderHeroStep::ensureSiteLogoMark($in);
    assert_contains('<!-- wp:site-logo {"width":48,"shouldSyncIcon":true,"className":"site-logo-mark"} /-->', $out);
    $logoAt = strpos($out, 'wp:site-logo');
    $titleAt = strpos($out, 'wp:site-title');
    assert_true($logoAt !== false && $titleAt !== false && $logoAt < $titleAt);
    assert_eq($out, HeaderHeroStep::ensureSiteLogoMark($out), 'idempotent when a logo already exists');
});

test('ensureSiteLogoMark inserts inside the root group when there is no site-title', function () {
    $in = '<!-- wp:group {"layout":{"type":"flex"}} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph --><p>Hearth</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $out = HeaderHeroStep::ensureSiteLogoMark($in);
    assert_contains('site-logo-mark', $out);
    assert_contains('Hearth', $out);
    $groupAt = strpos($out, 'wp:group');
    $wrapperAt = strpos($out, '<div class="wp-block-group">');
    $logoAt = strpos($out, 'wp:site-logo');
    $closeAt = strpos($out, '/wp:group');
    assert_true($groupAt !== false && $wrapperAt !== false && $logoAt !== false && $closeAt !== false);
    assert_true(
        $groupAt < $wrapperAt && $wrapperAt < $logoAt && $logoAt < $closeAt,
        'logo sits inside the root group wrapper, not between the comment and its saved HTML',
    );
});

test('ensureSiteLogoMark does not retag an authored branded-lockup logo', function () {
    $in = hh_header(
        '{"layout":{"type":"flex"}}',
        '<!-- wp:site-logo {"width":48,"shouldSyncIcon":true} /-->' . "\n" . '<!-- wp:site-title /-->'
    );
    $out = HeaderHeroStep::ensureSiteLogoMark($in);
    assert_eq($in, $out);
    assert_true(!str_contains($out, 'site-logo-mark'));
});

test('header-hero injects the mark on a business site and not on a personal site', function () {
    with_project('builder_hh_logo_', function ($project) {
        $project->writeJson('siteSpec.json', [
            'name' => 'Hearth & Crumb', 'site_type' => 'business storefront',
            'area' => 'bakery', 'persona_name' => '',
        ]);
        $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'none']);
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]];
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages, 'foreground-split');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText('theme/parts/page-home--hero.html', hh_cover('80') . "\n");

        putenv(\Automattic\SiteBuild\AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $header = $project->readText('theme/parts/header.html');
        assert_contains('site-logo-mark', $header);
        assert_contains('wp:site-title', $header);
    });

    with_project('builder_hh_nologo_', function ($project) {
        $project->writeJson('siteSpec.json', [
            'name' => 'Ada', 'persona_name' => 'Ada Lovelace',
            'site_type' => 'portfolio', 'area' => 'studio',
        ]);
        $project->writeJson('meta.json', ['prompt' => 'My paintings']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'none']);
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]];
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages, 'foreground-split');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText('theme/parts/page-home--hero.html', hh_cover('80') . "\n");

        putenv(\Automattic\SiteBuild\AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        assert_true(!str_contains($project->readText('theme/parts/header.html'), 'wp:site-logo'));
    });
});
