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

/**
 * A hero copy group holding one display h1. `$copyAttrs` replaces the copy
 * group's own layout attributes; `$open`/`$close` wrap it in section chrome.
 */
function hhf_hero(
    string $headline,
    string $copyAttrs = '"layout":{"type":"constrained","contentSize":"640px"}',
    string $open = '',
    string $close = '',
): string {
    return $open
        . '<!-- wp:group {"className":"hero-composition__copy",' . $copyAttrs . '} -->'
        . "\n" . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1,"fontSize":"display","style":{"color":{"text":"#E8E4FF"},"typography":{"lineHeight":"1.02"}}} -->' . "\n"
        . '<h1 class="wp-block-heading has-text-color has-display-font-size" style="color:#E8E4FF;line-height:1.02">'
        . $headline . '</h1>' . "\n"
        . '<!-- /wp:heading --></div>' . "\n"
        . '<!-- /wp:group -->' . $close;
}

/** A constrained section wrapper, the outer bound of any nested measure. */
function hhf_section(string $contentSize): array
{
    return [
        '<!-- wp:group {"layout":{"type":"constrained","contentSize":"' . $contentSize . '"}} -->'
            . '<div class="wp-block-group">',
        '</div><!-- /wp:group -->',
    ];
}

test('a headline word wider than the copy measure pins the heading below the display maximum', function () {
    $r = HeroHeadlineFit::apply(hhf_hero('Two Nights of Electronic Immersion'), hhf_theme());
    // ELECTRONIC: 10 × 0.70em + 9 × 0.04em = 7.36em; 640px × 0.96 ÷ 7.36 → 83px.
    // Comment JSON escapes each -- so the pinned value can live inside an HTML
    // comment without forming a premature closer.
    assert_contains(
        '"fontSize":"min(var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dfont-size\u002d\u002ddisplay), 83px)"',
        $r['markup'],
    );
    assert_contains("word-fit: 'Electronic' (10 chars, uppercase", implode("\n", $r['notes']));
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

test('a percentage column narrows the measure it inherits', function () {
    // The audited miss: an editorial-split hero puts the copy in one column of
    // a wide constrained section, so the section width is not the measure.
    [$open, $close] = hhf_section('1200px');
    $bare = hhf_hero('Two Nights of Electronic Immersion', '"layout":{"type":"constrained"}', $open, $close);
    assert_eq($bare, HeroHeadlineFit::apply($bare, hhf_theme())['markup'], 'the word fits 1200px');

    $inColumn = hhf_hero(
        'Two Nights of Electronic Immersion',
        '"layout":{"type":"constrained"}',
        $open . '<!-- wp:columns --><div class="wp-block-columns">'
            . '<!-- wp:column {"width":"48%"} --><div class="wp-block-column">',
        '</div><!-- /wp:column --></div><!-- /wp:columns -->' . $close,
    );
    // 1200px × 0.48 × 0.96 ÷ 7.36 → 75px.
    assert_contains('), 75px)', HeroHeadlineFit::apply($inColumn, hhf_theme())['markup']);
});

test('the copy half of a media-text is measured, not the whole block', function () {
    [$open, $close] = hhf_section('1280px');
    $markup = hhf_hero(
        'Two Nights of Electronic Immersion',
        '"layout":{"type":"constrained"}',
        $open . '<!-- wp:media-text {"mediaWidth":60} --><div class="wp-block-media-text">'
            . '<div class="wp-block-media-text__content">',
        '</div></div><!-- /wp:media-text -->' . $close,
    );
    // 1280px × 0.40 × 0.96 ÷ 7.36 → 66px.
    assert_contains('), 66px)', HeroHeadlineFit::apply($markup, hhf_theme())['markup']);
});

test('a copy group flexSize is the measure the composition asked for', function () {
    // pulso5's shape: a constrained copy group with no contentSize of its own,
    // carrying the intended 620px column in style.layout.flexSize, inside a
    // cover whose own constrained contentSize is the full 1320px section.
    [$open, $close] = hhf_section('1320px');
    $markup = hhf_hero(
        'Two Nights of Electronic Immersion',
        '"style":{"layout":{"selfStretch":"fixed","flexSize":"620px"}},"layout":{"type":"constrained"}',
        $open,
        $close,
    );
    // 620px × 0.96 ÷ 7.36 → 80px, not the 1320px section.
    assert_contains('), 80px)', HeroHeadlineFit::apply($markup, hhf_theme())['markup']);
});

test('the fit uses the theme content size when no ancestor names one', function () {
    $markup = hhf_hero('Two Nights of Electronic Immersion', '"layout":{"type":"constrained"}');
    $wide = HeroHeadlineFit::apply($markup, hhf_theme(['settings' => ['layout' => ['contentSize' => '1200px']]]));
    assert_eq($markup, $wide['markup']);

    $narrow = HeroHeadlineFit::apply($markup, hhf_theme(['settings' => ['layout' => ['contentSize' => '640px']]]));
    assert_contains('), 83px)', $narrow['markup']);
});

test('an impossible word opts into hyphenation instead of a pinned size', function () {
    // 26 uppercase chars in a 320px measure cap under 32px: no size worth
    // pinning, so the heading takes the hyphenation hook and keeps its preset.
    $markup = hhf_hero('Rechtsschutzversicherungen', '"layout":{"type":"constrained","contentSize":"320px"}');
    $r = HeroHeadlineFit::apply($markup, hhf_theme());
    assert_contains('"className":"headline-hyphenate"', $r['markup']);
    assert_contains('"fontSize":"display"', $r['markup'], 'the preset survives');
    assert_true(!str_contains($r['markup'], 'min(var('), 'no size is pinned');
    assert_contains('opted into hyphenation', implode("\n", $r['notes']));

    // Idempotent, and the hook reaches the rendered class attribute.
    $second = HeroHeadlineFit::apply($r['markup'], hhf_theme());
    assert_eq($r['markup'], $second['markup']);
    assert_eq([], $second['notes']);
    assert_contains('headline-hyphenate', (new Serializer())->transform($r['markup'])->html);
});

test('hyphenation is opted into per heading, never left on by default', function () {
    // The tbilisi5 defect: "A long table in Old Town" at display scale in a
    // 58% column. Every word fits, so the heading must stay untouched — no
    // pin and no hyphenation hook, or `table` splits as "ta-/ble".
    [$open, $close] = hhf_section('840px');
    $markup = hhf_hero(
        'A long table in Old Town',
        '"layout":{"type":"constrained"}',
        $open . '<!-- wp:columns --><div class="wp-block-columns">'
            . '<!-- wp:column {"width":"58%"} --><div class="wp-block-column">',
        '</div><!-- /wp:column --></div><!-- /wp:columns -->' . $close,
    );
    $r = HeroHeadlineFit::apply($markup, hhf_theme());
    assert_eq($markup, $r['markup']);
    assert_eq([], $r['notes']);
});

test('mixed case measures narrower than uppercase, and tight tracking never widens', function () {
    $noTransform = hhf_theme();
    unset($noTransform['styles']['elements']['h1']['typography']['textTransform']);
    $noTransform['styles']['elements']['h1']['typography']['letterSpacing'] = '-0.02em';
    // Electronic: 10 × 0.58em with the tightening ignored → 5.8em; 104 × 5.8 =
    // 603px against 640px × 0.96 = 614px — it fits, so nothing is touched.
    $markup = hhf_hero('Two Nights of Electronic Immersion');
    assert_eq($markup, HeroHeadlineFit::apply($markup, $noTransform)['markup']);
});

test('a line break is a word boundary, not a silent word joiner', function () {
    $r = HeroHeadlineFit::apply(hhf_hero('Two Nights<br>of Electronic Immersion'), hhf_theme());
    assert_contains("word-fit: 'Electronic'", implode("\n", $r['notes']));
});

test('without a resolvable display preset or measure the markup is untouched', function () {
    $markup = hhf_hero('Two Nights of Electronic Immersion');
    $noDisplay = hhf_theme();
    $noDisplay['settings']['typography']['fontSizes'] = [['slug' => 'body', 'size' => '1.125rem']];
    assert_eq($markup, HeroHeadlineFit::apply($markup, $noDisplay)['markup']);

    $vwSizes = hhf_theme();
    $vwSizes['settings']['typography']['fontSizes'][1]['size'] = '8vw';
    assert_eq($markup, HeroHeadlineFit::apply($markup, $vwSizes)['markup']);

    $noMeasure = hhf_hero('Two Nights of Electronic Immersion', '"layout":{"type":"constrained"}');
    $unitless = hhf_theme(['settings' => ['layout' => ['contentSize' => '60vw']]]);
    assert_eq($noMeasure, HeroHeadlineFit::apply($noMeasure, $unitless)['markup']);
});

test('the pinned serializer emits the fitted size and drops the !important preset class', function () {
    $fitted = HeroHeadlineFit::apply(hhf_hero('Two Nights of Electronic Immersion'), hhf_theme())['markup'];
    $out = (new Serializer())->transform($fitted)->html;
    // Core renders .has-display-font-size with !important, which would beat
    // the pinned inline size — the preset attr and class must both be gone.
    assert_true(!str_contains($out, 'has-display-font-size'), 'preset class removed');
    assert_true(!str_contains($out, '"fontSize":"display"'), 'preset attr removed');
    assert_contains('font-size:min(var(--wp--preset--font-size--display), 83px)', $out, 'inline size is emitted');
    assert_contains(
        '"fontSize":"min(var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dfont-size\u002d\u002ddisplay), 83px)"',
        $out,
        'attr stays canonical',
    );
});
