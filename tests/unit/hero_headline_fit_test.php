<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\HeroHeadlineFit;

// Shapes distilled from the 2026-08-07 pulso cohort site: display
// clamp(3rem, 7.6vw, 6.5rem) resolves to 104px at 1366px, uppercase h1 with
// 0.04em tracking, and a 640px constrained copy group — "ELECTRONIC" cannot
// fit and snapped mid-word ("ELECTRONI / C").

function hhf_theme(array $overrides = []): array
{
    return array_replace_recursive([
        'settings' => [
            'layout' => ['contentSize' => '840px'],
            'typography' => ['fontSizes' => [
                ['slug' => 'body', 'size' => '1.125rem'],
                ['slug' => 'display', 'size' => 'clamp(3rem, 7.6vw, 6.5rem)'],
            ]],
        ],
        'styles' => ['elements' => ['h1' => ['typography' => [
            'textTransform' => 'uppercase',
            'letterSpacing' => '0.04em',
        ]]]],
    ], $overrides);
}

function hhf_hero(string $headline, string $contentSize = '640px'): string
{
    return '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained","contentSize":"'
        . $contentSize . '"}} -->' . "\n"
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1,"fontSize":"display","style":{"color":{"text":"#E8E4FF"},"typography":{"lineHeight":"1.02"}}} -->' . "\n"
        . '<h1 class="wp-block-heading has-text-color has-display-font-size" style="color:#E8E4FF;line-height:1.02">'
        . $headline . '</h1>' . "\n"
        . '<!-- /wp:heading --></div>' . "\n"
        . '<!-- /wp:group -->';
}

test('a headline word wider than the copy measure pins the heading below the display maximum', function () {
    $r = HeroHeadlineFit::apply(hhf_hero('Two Nights of Electronic Immersion'), hhf_theme());
    // ELECTRONIC ≈ 6.42em + 9×0.04em tracking = 6.78em; 640px×0.96 ÷ 6.78 → 90px.
    // Comment JSON escapes each -- as -- so the value can live
    // inside an HTML comment without forming a premature closer.
    assert_contains('"fontSize":"min(var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dfont-size\u002d\u002ddisplay), 90px)"', $r['markup']);
    assert_contains("word-fit: 'Electronic'", implode("\n", $r['notes']));
    assert_contains('lineHeight":"1.02', $r['markup'], 'sibling typography attrs survive');
});

test('a headline whose words all fit is left byte-identical', function () {
    $markup = hhf_hero('Bread Made Slowly');
    $r = HeroHeadlineFit::apply($markup, hhf_theme());
    assert_eq($markup, $r['markup']);
    assert_eq([], $r['notes']);
});

test('the fit is idempotent and never fights an authored explicit size', function () {
    $theme = hhf_theme();
    $first = HeroHeadlineFit::apply(hhf_hero('Two Nights of Electronic Immersion'), $theme);
    $second = HeroHeadlineFit::apply($first['markup'], $theme);
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['notes']);

    $authored = str_replace(
        '"typography":{"lineHeight":"1.02"}',
        '"typography":{"lineHeight":"1.02","fontSize":"5rem"}',
        hhf_hero('Two Nights of Electronic Immersion'),
    );
    $r = HeroHeadlineFit::apply($authored, $theme);
    assert_eq($authored, $r['markup']);
});

test('the fit uses the theme content size when no constrained ancestor names one', function () {
    // Same word in a wide 1200px measure: 104px × 6.78em = 705px < 1152px — fits.
    $markup = str_replace(',"contentSize":"640px"', '', hhf_hero('Two Nights of Electronic Immersion'));
    $wide = HeroHeadlineFit::apply($markup, hhf_theme(['settings' => ['layout' => ['contentSize' => '1200px']]]));
    assert_eq($markup, $wide['markup']);

    $narrow = HeroHeadlineFit::apply($markup, hhf_theme(['settings' => ['layout' => ['contentSize' => '640px']]]));
    assert_contains('90px)', $narrow['markup']);
});

test('without a resolvable display preset or measure the markup is untouched', function () {
    $markup = hhf_hero('Two Nights of Electronic Immersion');
    $noDisplay = hhf_theme();
    $noDisplay['settings']['typography']['fontSizes'] = [['slug' => 'body', 'size' => '1.125rem']];
    assert_eq($markup, HeroHeadlineFit::apply($markup, $noDisplay)['markup']);

    $vwSizes = hhf_theme();
    $vwSizes['settings']['typography']['fontSizes'][1]['size'] = '8vw';
    assert_eq($markup, HeroHeadlineFit::apply($markup, $vwSizes)['markup']);

    $noMeasure = str_replace(',"contentSize":"640px"', '', hhf_hero('Two Nights of Electronic Immersion'));
    $unitless = hhf_theme(['settings' => ['layout' => ['contentSize' => '60rem']]]);
    assert_eq($noMeasure, HeroHeadlineFit::apply($noMeasure, $unitless)['markup']);
});

test('lowercase headlines measure narrower than uppercase ones', function () {
    $noTransform = hhf_theme();
    unset($noTransform['styles']['elements']['h1']['typography']['textTransform']);
    // Mixed-case "Electronic" ≈ 4.8em: fits 614px at 104px? 104×4.8=499 < 614 — yes.
    $r = HeroHeadlineFit::apply(hhf_hero('Two Nights of Electronic Immersion'), $noTransform);
    assert_eq([], $r['notes']);
});

test('the pinned serializer emits the fitted size and drops the !important preset class', function () {
    $fitted = HeroHeadlineFit::apply(hhf_hero('Two Nights of Electronic Immersion'), hhf_theme())['markup'];
    $out = (new Serializer())->transform($fitted)->html;
    // Core renders .has-display-font-size with !important, which would beat
    // the pinned inline size — the preset attr and class must both be gone.
    assert_true(!str_contains($out, 'has-display-font-size'), 'preset class removed');
    assert_true(!str_contains($out, '"fontSize":"display"'), 'preset attr removed');
    assert_contains('font-size:min(var(--wp--preset--font-size--display), 90px)', $out, 'inline size is emitted');
    assert_contains('"fontSize":"min(var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dfont-size\u002d\u002ddisplay), 90px)"', $out, 'attr stays canonical');
});
