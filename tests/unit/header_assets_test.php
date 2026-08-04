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
    assert_contains('box-shadow: var(--header-scrolled-shadow)', $state);
    assert_true(
        preg_match('/\b(?:height|padding|margin|position|top|inset|border-width)\s*:/', $state) !== 1,
        'scrolled state must not mutate geometry'
    );
    assert_contains('transition-property: background-color, box-shadow', $css);
    assert_true(!str_contains($css, 'transition: all'), 'trusted state kit never transitions all properties');
});

test('header CSS maps the closed palette vocabulary and covers accessibility states', function () {
    $css = (string) file_get_contents(repo_path('assets/header/header.css'));

    foreach (['base', 'contrast', 'primary', 'secondary', 'accent'] as $slug) {
        assert_contains(".header-start-{$slug}", $css);
        assert_contains(".header-scrolled-{$slug}", $css);
        assert_contains(".header-foreground-{$slug}", $css);
    }
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
    $glass = header_asset_css_block($css, '@supports ((-webkit-backdrop-filter: blur(1px))');
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
    assert_contains('.wp-block-navigation__responsive-container.is-menu-open', $print);
    assert_contains('position: static !important', $print);
    assert_contains('background: #ffffff !important', $print);
    assert_contains('.wp-block-navigation__responsive-container-open', $print);
    assert_contains('.wp-block-navigation__responsive-container-close', $print);
    assert_contains('display: none !important', $print);
    assert_contains('.header-behavior-overlay-to-solid::before', $print);
    assert_contains('content: none !important', $print);
    assert_contains('backdrop-filter: none !important', $print);

    $instant = header_asset_css_block($css, '.site-header-shell .header-transition-instant');
    assert_true(!str_contains($instant, '*'), 'instant state does not disable descendant hover transitions');
});

test('header driver owns its scope, coalesces passive events, and fails open', function () {
    $js = (string) file_get_contents(repo_path('assets/header/header.js'));

    assert_contains("var ENHANCEMENT_CLASS = 'header-state-js'", $js);
    assert_contains("var SCROLLED_CLASS = 'header-is-scrolled'", $js);
    assert_contains('var SCROLL_THRESHOLD = 24', $js);
    assert_contains('window.requestAnimationFrame', $js);
    assert_contains("window.addEventListener('scroll', scheduleScrollState, { passive: true })", $js);
    assert_contains("window.addEventListener('pageshow', refreshRestoredState, { passive: true })", $js);
    assert_contains("root.style.setProperty('--site-header-height'", $js);
    assert_contains("root.style.setProperty('--site-admin-bar-offset'", $js);
    assert_contains("document.body.style.setProperty('--site-admin-bar-offset'", $js);
    assert_contains("document.body.style.removeProperty('--site-admin-bar-offset'", $js);
    assert_contains('resizeObserver.observe(header)', $js);
    assert_contains('root.classList.remove(ENHANCEMENT_CLASS)', $js);
    assert_contains('clearMeasuredHeight()', $js);
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
