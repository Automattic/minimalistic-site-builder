<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeaderBehavior;

/** Return the complete CSS block beginning at a selector/at-rule needle. */
function header_asset_css_block(string $css, string $needle): string
{
    $start = strpos($css, $needle);
    if ($start === false) {
        throw new RuntimeException("CSS block not found: {$needle}");
    }
    $open = strpos($css, '{', $start + strlen($needle));
    if ($open === false) {
        throw new RuntimeException("CSS block has no opening brace: {$needle}");
    }
    $depth = 1;
    for ($i = $open + 1, $length = strlen($css); $i < $length; $i++) {
        if ($css[$i] === '{') {
            $depth++;
        } elseif ($css[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($css, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException("CSS block is unbalanced: {$needle}");
}

/** Return the complete CSS block beginning at the LAST occurrence of the needle. */
function header_asset_last_css_block(string $css, string $needle): string
{
    $start = strrpos($css, $needle);
    if ($start === false) {
        throw new RuntimeException("CSS block not found: {$needle}");
    }
    return header_asset_css_block(substr($css, $start), $needle);
}

test('header CSS keeps positioning progressive and state changes paint-only', function () {
    $css = (string) file_get_contents(repo_path('assets/header/header.css'));

    $sticky = header_asset_css_block($css, '.site-header-shell--sticky-soft');
    assert_contains('position: sticky', $sticky);
    assert_contains('top: var(--site-admin-bar-offset, 0px)', $sticky);

    $overlay = header_asset_css_block($css, '.site-header-shell--overlay-to-solid');
    assert_contains('position: absolute', $overlay, 'no-JS overlay scrolls away instead of becoming persistent');
    $enhanced = header_asset_css_block(
        $css,
        'html.header-state-js .site-header-shell--overlay-to-solid'
    );
    assert_contains('position: fixed', $enhanced, 'only the driver-owned scope fixes an overlay');
    assert_true(
        substr_count($css, 'position: fixed') === 1,
        'no selector outside the driver scope fixes the header'
    );

    $state = header_asset_css_block($css, 'html.header-is-scrolled');
    assert_contains('background-color: var(--header-scrolled-surface)', $state);
    assert_contains('background-image: none !important', $state, 'the texture snaps off before crossing later sections');
    assert_contains('box-shadow: var(--header-scrolled-shadow)', $state);
    assert_true(
        preg_match('/\b(?:height|padding|margin|position|top|inset|border-width)\s*:/', $state) !== 1,
        'scrolled state must not mutate geometry'
    );
    assert_contains('transition-property: background-color, box-shadow', $css);
    assert_true(!str_contains($css, 'transition: all'), 'trusted state kit never transitions all properties');

    $behavior = header_asset_css_block(
        $css,
        '.site-header-shell :is(.header-behavior-sticky-soft, .header-behavior-overlay-to-solid)',
    );
    assert_true(
        !str_contains($behavior, 'background-image: none'),
        'the behavior base no longer suppresses the reviewed top-of-page texture',
    );
    $unreviewedPaint = header_asset_css_block($css, ':not(.has-stage-texture-backdrop)');
    assert_contains('background-image: none !important', $unreviewedPaint, 'arbitrary generated header paint stays suppressed');
    $forced = header_asset_css_block($css, '.site-header-shell--force-solid');
    assert_contains('background-image: none !important', $forced, 'forced-solid pages never carry the front-stage texture');

    $clearOverlay = header_asset_css_block(
        $css,
        '.header-behavior-overlay-to-solid.header-top-transparent',
    );
    assert_contains('--header-start-surface: transparent', $clearOverlay);
    assert_contains(
        'transition-property: box-shadow',
        $clearOverlay,
        'a fixed clear header snaps protective paint while retaining the smooth shadow cue',
    );
});

test('header CSS maps the closed palette vocabulary and covers accessibility states', function () {
    $css = (string) file_get_contents(repo_path('assets/header/header.css'));

    // The token vocabulary feeds the always-opaque -solid tier; the painted
    // surface pair derives from it and only driver-scoped treatment rules
    // may ever make the painted tier non-opaque.
    foreach (['base', 'contrast', 'primary', 'secondary', 'accent'] as $slug) {
        assert_contains(".header-start-{$slug} { --header-start-solid:", $css);
        assert_contains(".header-scrolled-{$slug} { --header-scrolled-solid:", $css);
        assert_contains(".header-foreground-{$slug}", $css);
    }
    assert_contains('--header-start-surface: var(--header-start-solid)', $css);
    assert_contains('--header-scrolled-surface: var(--header-scrolled-solid)', $css);
    assert_contains('.header-start-transparent', $css);
    assert_true(!str_contains($css, '.header-scrolled-transparent'), 'solid state can never be transparent');
    assert_contains('.site-header-shell--force-solid', $css);
    assert_contains('.wp-block-navigation__responsive-container.is-menu-open', $css);
    assert_contains('.wp-block-navigation__submenu-container', $css);
    assert_contains('background-color: var(--header-scrolled-surface) !important', $css);
    assert_contains('.header-transition-instant', $css);
    assert_contains('@media (prefers-reduced-motion: reduce)', $css);
    assert_contains('scroll-padding-top:', $css);
    assert_true(!str_contains($css, 'scroll-margin-top:'), 'anchor clearance is applied exactly once');
    assert_contains('--header-overlay-scrim: rgb(0 0 0 / 0.60)', $css);
    assert_contains('.header-start-transparent { --header-start-surface: var(--header-overlay-scrim); }', $css);
    assert_eq(0.60, HeaderBehavior::OVERLAY_SCRIM_ALPHA);
    assert_eq([102, 102, 102], HeaderBehavior::OVERLAY_WORST_CASE_RGB);
    // The overlay's progressive glass block is the LAST backdrop-filter
    // @supports: the sticky treatment block earlier also gates on color-mix.
    $glass = header_asset_last_css_block($css, '@supports ((-webkit-backdrop-filter: blur(1px))');
    assert_contains('.header-behavior-overlay-to-solid::before', $glass);
    assert_contains('-webkit-backdrop-filter: blur(14px) saturate(115%)', $glass);
    assert_contains('backdrop-filter: blur(14px) saturate(115%)', $glass);
    assert_contains('pointer-events: none', $glass);
    assert_contains('html.header-is-scrolled', $glass);
    assert_contains('.site-header-shell--force-solid', $glass);
    assert_contains('backdrop-filter: none', $glass);
    assert_true(
        !str_contains($glass, 'transition-property: backdrop-filter'),
        'the potentially expensive glass filter switches states without animation',
    );
    assert_contains('.site-header-shell--overlay-to-solid + .wp-block-post-content', $css);
    assert_contains('margin-block-start: 0', $css);

    // Classic WordPress exposes body.admin-bar but no dependable custom
    // property, so the kit owns both canonical responsive offsets.
    assert_contains('body.admin-bar', $css);
    assert_contains('--site-admin-bar-offset: 32px', $css);
    assert_contains('@media (max-width: 782px)', $css);
    assert_contains('--site-admin-bar-offset: 46px', $css);
    assert_contains('@media (max-width: 600px)', $css);
    assert_contains('body.admin-bar .site-header-shell--sticky-soft', $css);
    assert_contains('top: 0', $css);

    $print = header_asset_css_block($css, '@media print');
    assert_contains('position: static !important', $print);
    assert_contains('background: #ffffff !important', $print);
    assert_contains('color: #000000 !important', $print);
    assert_contains('transition: none !important', $print);

    // Scope the menu-open assertions to their own sub-block: matched against
    // the whole print block they would only re-find the shell declarations
    // above and could never fail.
    $printMenu = header_asset_css_block($print, '.wp-block-navigation__responsive-container.is-menu-open');
    assert_contains('position: static !important', $printMenu);
    assert_contains('background: #ffffff !important', $printMenu);
    assert_contains('color: #000000 !important', $printMenu);

    $printToggles = header_asset_css_block($print, '.wp-block-navigation__responsive-container-open');
    assert_contains('.wp-block-navigation__responsive-container-close', $printToggles);
    assert_contains('display: none !important', $printToggles);

    // Print disables the glass pseudo-element for BOTH persistent behaviors:
    // the needle itself fails closed if sticky-soft loses its coverage.
    $printGlass = header_asset_css_block(
        $print,
        ':is(.header-behavior-overlay-to-solid, .header-behavior-sticky-soft)::before',
    );
    assert_contains('content: none !important', $printGlass);
    assert_contains('backdrop-filter: none !important', $printGlass);

    $instant = header_asset_css_block($css, '.site-header-shell .header-transition-instant');
    assert_true(!str_contains($instant, '*'), 'instant state does not disable descendant hover transitions');
});

test('sticky treatment rules are driver-scoped, alpha-synced, and fail closed to opaque paint', function () {
    $css = (string) file_get_contents(repo_path('assets/header/header.css'));

    // Every selector that styles a treatment class must sit beneath the
    // driver-owned html.header-state-js scope: without JavaScript (or after
    // a failed driver removed the scope) the header keeps its opaque tokens.
    // Comments are stripped so prose mentioning a class cannot mask a rule.
    $rules = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
    preg_match_all('/[^{};]+\{/', $rules, $matches);
    foreach (['header-top-transparent', 'header-top-glass', 'header-scrolled-glass'] as $token) {
        $selectors = array_values(array_filter(
            $matches[0],
            static fn (string $selector): bool => str_contains($selector, $token),
        ));
        assert_true($selectors !== [], "the kit implements at least one {$token} rule");
        foreach ($selectors as $selector) {
            assert_contains(
                'html.header-state-js',
                $selector,
                "every {$token} rule must be driver-scoped, found: " . trim($selector),
            );
        }
    }

    // The color-mix tint percentage is part of the contrast contract: the
    // resolver proved the foreground against composites at exactly
    // HeaderBehavior::GLASS_ALPHA, so the painted tint must match it.
    $tint = sprintf('%d%%', (int) round(HeaderBehavior::GLASS_ALPHA * 100));
    assert_contains("color-mix(in srgb, var(--header-start-solid) {$tint}, transparent)", $css);
    assert_contains("color-mix(in srgb, var(--header-scrolled-solid) {$tint}, transparent)", $css);

    // The sticky glass rules are additionally gated on support for BOTH
    // backdrop-filter and color-mix; either missing keeps opaque tokens.
    $glassSupports = header_asset_css_block($css, 'and (background-color: color-mix(');
    assert_contains("color-mix(in srgb, red {$tint}, transparent)", $css, 'the @supports probe uses the same tint');
    assert_contains('.header-top-glass', $glassSupports);
    assert_contains('.header-scrolled-glass', $glassSupports);
    $prelude = substr($css, 0, (int) strpos($css, 'and (background-color: color-mix('));
    $prelude = substr($prelude, (int) strrpos($prelude, '@supports'));
    assert_contains('backdrop-filter: blur(1px)', $prelude, 'glass needs backdrop-filter AND color-mix support');

    // The responsive-nav modal and desktop submenu panels are full opaque
    // surfaces behind text: they take the always-solid tier directly and may
    // never inherit a treatment-aware (possibly translucent) surface.
    $modal = header_asset_css_block($css, '.wp-block-navigation__responsive-container.is-menu-open');
    assert_contains('background-color: var(--header-scrolled-solid) !important', $modal);
    assert_true(
        !str_contains($modal, '--header-scrolled-surface'),
        'the menu modal must never paint the treatment-aware surface',
    );
    $submenu = header_asset_css_block($css, '.wp-block-navigation__submenu-container');
    assert_contains('background-color: var(--header-scrolled-solid) !important', $submenu);
    assert_true(
        !str_contains($submenu, '--header-scrolled-surface'),
        'submenu panels must never paint the treatment-aware surface',
    );

    // Users who ask for less transparency get the verified opaque pair back
    // and the blur pseudo-element is fully disabled.
    $reduced = header_asset_css_block($css, '@media (prefers-reduced-transparency: reduce)');
    assert_contains('html.header-state-js', $reduced);
    assert_contains('--header-start-surface: var(--header-start-solid)', $reduced);
    assert_contains('--header-scrolled-surface: var(--header-scrolled-solid)', $reduced);
    assert_contains('content: none', $reduced);
    assert_contains('backdrop-filter: none', $reduced);
});

test('header driver keeps its public contract names and passive threshold shape', function () {
    $js = (string) file_get_contents(repo_path('assets/header/header.js'));

    // Behavior (thresholds with hysteresis, event coalescing, admin-bar
    // caching, double-init guard, fail-open) is exercised end-to-end by the
    // runtime harness below. These checks only pin the names shared with the
    // CSS kit plus loose structure, so a rename or reformat inside the driver
    // cannot break them.
    assert_contains("'header-state-js'", $js);
    assert_contains("'header-is-scrolled'", $js);
    assert_contains("'--site-header-height'", $js);
    assert_contains("'--site-admin-bar-offset'", $js);
    assert_contains("'wpadminbar'", $js);
    assert_true(
        preg_match('/\b(?:var|let|const)\s+\w*THRESHOLD\w*\s*=\s*\d+\b/', $js) === 1,
        'driver declares a numeric scroll threshold constant'
    );
    assert_true(
        preg_match("/addEventListener\\(\\s*'scroll'\\s*,[^)]*passive\\s*:\\s*true/s", $js) === 1,
        'driver registers a passive scroll listener'
    );
    assert_contains('requestAnimationFrame', $js);
});

test('header driver runtime covers restored scroll, measurement, admin bar, and fail-open', function () {
    $node = [];
    $nodeExit = 1;
    exec('command -v node 2>/dev/null', $node, $nodeExit);
    if ($nodeExit !== 0 || ($node[0] ?? '') === '') {
        skip_test('Node is unavailable; static header asset contract tests still ran');
    }

    $command = escapeshellarg($node[0]) . ' '
        . escapeshellarg(repo_path('tests/runtime/header_state_driver_harness.js')) . ' '
        . escapeshellarg(repo_path('assets/header/header.js')) . ' 2>&1';
    $output = [];
    $exit = 0;
    exec($command, $output, $exit);

    assert_eq(0, $exit, implode("\n", $output));
    assert_contains('header state driver runtime harness passed', implode("\n", $output));
});
