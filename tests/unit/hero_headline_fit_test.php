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

// ── Masthead promotion (BIGR-883) ───────────────────────────────────────────
// lumen3/atlas3/pulso3 all shipped their hero h1 at `section-title`, the same
// preset every section h2 below it uses, so the first screen read as a caption
// over a photograph. `display` exists for the masthead and nothing else.

/** A theme whose display and section-title presets are both resolvable. */
function hhf_scale_theme(array $overrides = []): array
{
    return array_replace_recursive([
        'settings' => [
            'layout' => ['contentSize' => '840px'],
            'typography' => ['fontSizes' => [
                ['slug' => 'body', 'size' => '1.125rem'],
                ['slug' => 'section-title', 'size' => 'clamp(2.25rem, 3vw, 3rem)'],
                ['slug' => 'display', 'size' => 'clamp(3rem, 6.4vw, 5.75rem)'],
            ]],
        ],
    ], $overrides);
}

/** A hero copy group holding one h1 at the given preset. */
function hhf_scale_hero(string $headline, string $preset = 'section-title'): string
{
    $attr = $preset === '' ? '' : ',"fontSize":"' . $preset . '"';
    $class = $preset === '' ? '' : ' has-' . $preset . '-font-size';
    return '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained","contentSize":"720px"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1' . $attr . '} -->'
        . '<h1 class="wp-block-heading' . $class . '">' . $headline . '</h1>'
        . '<!-- /wp:heading --></div>'
        . '<!-- /wp:group -->';
}

test('a hero h1 below the masthead preset is promoted to display', function () {
    $r = HeroHeadlineFit::apply(
        hhf_scale_hero('Glass Given a Second Life as Light'),
        hhf_scale_theme(),
        [1, 2],
    );

    assert_true(!str_contains($r['markup'], '"fontSize":"section-title"'), 'the section preset is gone');
    // Block comments escape `--`, so the CSS form is only readable once the
    // serializer has regenerated the saved HTML — which is what fix-blocks
    // does right after this step.
    $out = (new Serializer())->transform($r['markup'])->html;
    assert_contains('font-size:min(var(--wp--preset--font-size--display)', $out, 'display carries the size');
    // The preset CLASS must go with the preset attr: core renders
    // `.has-section-title-font-size` with !important, which would beat the
    // pinned inline size and silently keep the old scale.
    assert_true(!str_contains($out, 'has-section-title-font-size'), 'the stale preset class is gone too');
    assert_contains('promoted to the display preset', implode("\n", $r['notes']));
});

test('promotion respects the blueprint desktop line target', function () {
    $headline = 'Glass Given a Second Life as Light';

    // A 2-line target in a 720px measure cannot take the full display max.
    $tight = HeroHeadlineFit::apply(hhf_scale_hero($headline), hhf_scale_theme(), [1, 2]);
    $tightHtml = (new Serializer())->transform($tight['markup'])->html;
    assert_true(
        preg_match('/min\(var\(--wp--preset--font-size--display\), (\d+)px\)/', $tightHtml, $m) === 1,
        'a bounded promotion pins a cap'
    );
    $cap = (int) $m[1];
    assert_true($cap > 48, "the cap {$cap}px must beat the section-title maximum of 48px");
    assert_true($cap < 92, "the cap {$cap}px must sit under the display maximum of 92px");

    // A roomier target lets the headline run larger.
    $loose = HeroHeadlineFit::apply(hhf_scale_hero($headline), hhf_scale_theme(), [1, 4]);
    $looseHtml = (new Serializer())->transform($loose['markup'])->html;
    preg_match('/min\(var\(--wp--preset--font-size--display\), (\d+)px\)/', $looseHtml, $m2);
    assert_true(
        $loose['markup'] === $tight['markup'] || (int) ($m2[1] ?? 999) > $cap,
        'a looser line target never produces a SMALLER headline'
    );

    // With no target at all the plain preset ships; only the word fit bounds it.
    $none = HeroHeadlineFit::apply(hhf_scale_hero('Light'), hhf_scale_theme(), null);
    assert_contains('"fontSize":"display"', $none['markup'], 'no target means the plain preset');
});

test('promotion leaves a heading that already clears the masthead bar', function () {
    // Already at display: nothing to raise.
    $atDisplay = hhf_scale_hero('Two Nights of Electronic Dreams', 'display');
    assert_eq(
        $atDisplay,
        HeroHeadlineFit::apply($atDisplay, hhf_scale_theme(), [1, 3])['markup'],
        'byte-identical'
    );

    // An explicit authored size is an author's decision, not a model default.
    $pinned = '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained","contentSize":"720px"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"5rem"}}} -->'
        . '<h1 class="wp-block-heading">Held At Five Rem</h1>'
        . '<!-- /wp:heading --></div><!-- /wp:group -->';
    assert_eq($pinned, HeroHeadlineFit::apply($pinned, hhf_scale_theme(), [1, 3])['markup'], 'byte-identical');
});

test('promotion never makes the headline smaller than the model chose', function () {
    // A very long headline in a 1-line target would compute a cap far below
    // the section-title preset it already has. Promoting there would be a
    // demotion wearing the display preset's name, so the pass declines.
    $long = hhf_scale_hero(
        'An Extraordinarily Long Masthead Sentence That Cannot Possibly Fit On One Single Line'
    );
    assert_eq($long, HeroHeadlineFit::apply($long, hhf_scale_theme(), [1, 1])['markup'], 'byte-identical');
});

test('a below-display masthead still gets the impossible-word hyphenation escape', function () {
    // Review regression: promotion used to return before the ordinary word-fit
    // loop, but that loop only visits display headings. The original
    // section-title heading therefore kept overflowing with no escape at all.
    $markup = str_replace(
        '"contentSize":"720px"',
        '"contentSize":"320px"',
        hhf_scale_hero('Rechtsschutzversicherungen'),
    );
    $first = HeroHeadlineFit::apply($markup, hhf_scale_theme(), [1, 2]);

    assert_contains('"fontSize":"section-title"', $first['markup'], 'the safer existing scale survives');
    assert_contains('"className":"headline-hyphenate"', $first['markup'], 'hyphenation is opted in');
    assert_contains('opted into hyphenation', implode("\n", $first['notes']));

    $second = HeroHeadlineFit::apply($first['markup'], hhf_scale_theme(), [1, 2]);
    assert_eq($first['markup'], $second['markup'], 'fixed point');
    assert_eq([], $second['notes'], 'and silent on the second pass');
});

test('promotion only ever touches the page masthead h1', function () {
    // A section heading in the hero keeps its own scale.
    $withH2 = '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained","contentSize":"720px"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":2,"fontSize":"section-title"} -->'
        . '<h2 class="wp-block-heading has-section-title-font-size">A Section Heading</h2>'
        . '<!-- /wp:heading --></div><!-- /wp:group -->';
    assert_eq($withH2, HeroHeadlineFit::apply($withH2, hhf_scale_theme(), [1, 2])['markup'], 'byte-identical');
});

test('promotion reaches a fixed point', function () {
    $theme = hhf_scale_theme();
    $once = HeroHeadlineFit::apply(hhf_scale_hero('Glass Given a Second Life as Light'), $theme, [1, 2]);
    $twice = HeroHeadlineFit::apply($once['markup'], $theme, [1, 2]);
    assert_eq($once['markup'], $twice['markup'], 'idempotent');
    assert_eq([], $twice['notes'], 'and silent on the second pass');
});

test('promotion is bounded by the WORD fit, not only the line target', function () {
    // Blocker found in review: promotion writes an explicit size, and the
    // word-fit loop deliberately skips any heading that has one — so a
    // promoted heading was never word-checked at all. "Transcontinental" in a
    // 720px measure fits no size above 74px, but the line target alone pinned
    // 83px. Hero headings are `overflow-wrap: normal`, and
    // .hero-composition--layered-poster clips overflow, so that ships a
    // masthead with its first word cut off at the column edge.
    $r = HeroHeadlineFit::apply(
        hhf_scale_hero('Transcontinental Ambition Rebuilt'),
        hhf_scale_theme(),
        [1, 2],
    );
    $out = (new Serializer())->transform($r['markup'])->html;
    assert_true(
        preg_match('/min\(var\(--wp--preset--font-size--display\), (\d+)px\)/', $out, $m) === 1,
        'the heading is pinned'
    );
    $cap = (int) $m[1];

    // The same headline entered at `display` goes through the word fit alone.
    $wordOnly = HeroHeadlineFit::apply(
        hhf_scale_hero('Transcontinental Ambition Rebuilt', 'display'),
        hhf_scale_theme(),
        [1, 2],
    );
    preg_match(
        '/min\(var\(--wp--preset--font-size--display\), (\d+)px\)/',
        (new Serializer())->transform($wordOnly['markup'])->html,
        $w,
    );
    assert_eq(
        (int) $w[1],
        $cap,
        'a promoted heading is bounded exactly as tightly as a display one'
    );
    assert_contains("so 'Transcontinental' fits the measure", implode("\n", $r['notes']));
});

test('promotion strips a stale preset class that lives only in the saved HTML', function () {
    // Found in review. `fix-blocks` runs AFTER header-hero and rescues a class
    // that has no matching attr, so keying the removal off the attr left
    // `.has-section-title-font-size` — which core renders with !important —
    // beating the inline pin. The headline then still renders at the section
    // scale and BIGR-883 is not fixed at all.
    $classOnly = '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained","contentSize":"720px"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} -->'
        . '<h1 class="wp-block-heading has-section-title-font-size">Glass Given a Second Life</h1>'
        . '<!-- /wp:heading --></div><!-- /wp:group -->';

    $out = (new Serializer())->transform(
        HeroHeadlineFit::apply($classOnly, hhf_scale_theme(), [1, 2])['markup']
    )->html;
    assert_true(!str_contains($out, 'has-section-title-font-size'), 'the stale class is gone');
    assert_true(
        substr_count($out, '-font-size') <= 1,
        'and no two conflicting preset classes survive together'
    );
});

test('promotion declines when the headline cannot be measured or is empty', function () {
    $theme = hhf_scale_theme();

    // No resolvable measure anywhere in the chain: promoting would ship an
    // unbounded display headline that neither bound has checked.
    $noMeasure = '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1,"fontSize":"section-title"} -->'
        . '<h1 class="wp-block-heading has-section-title-font-size">Glass Given a Second Life</h1>'
        . '<!-- /wp:heading --></div><!-- /wp:group -->';
    $unitless = hhf_scale_theme(['settings' => ['layout' => ['contentSize' => '60vw']]]);
    assert_eq($noMeasure, HeroHeadlineFit::apply($noMeasure, $unitless, [1, 2])['markup'], 'byte-identical');

    // An empty headline has no scale worth promoting, and must not emit a note.
    $empty = hhf_scale_hero('   ');
    $r = HeroHeadlineFit::apply($empty, $theme, [1, 2]);
    assert_eq($empty, $r['markup'], 'byte-identical');
    assert_eq([], $r['notes'], 'and silent');
});

test('a malformed line target still bounds the headline', function () {
    // Found in review: reading only [1] let `[2]` and `{}` fall through to an
    // unbounded display preset.
    foreach ([[2], ['max' => 2]] as $target) {
        $out = (new Serializer())->transform(
            HeroHeadlineFit::apply(
                hhf_scale_hero('Glass Given a Second Life as Light'),
                hhf_scale_theme(),
                array_values($target),
            )['markup']
        )->html;
        assert_true(
            preg_match('/min\(var\(--wp--preset--font-size--display\), \d+px\)/', $out) === 1,
            'the only number supplied is still used as the bound'
        );
    }
});

test('an extreme generated display maximum cannot become an unbounded search', function () {
    $theme = hhf_scale_theme();
    $theme['settings']['typography']['fontSizes'][2]['size'] = '1000000000px';

    $out = (new Serializer())->transform(
        HeroHeadlineFit::apply(
            hhf_scale_hero('Glass Given a Second Life as Light'),
            $theme,
            [1, 2],
        )['markup']
    )->html;
    assert_true(
        preg_match('/min\(var\(--wp--preset--font-size--display\), (\d+)px\)/', $out, $m) === 1,
        'the hostile maximum is reduced to a finite cap',
    );
    assert_true((int) $m[1] < 1000, 'the cap comes from the measure, not the hostile preset maximum');
});

test('a scalar style attr degrades instead of taking the build down', function () {
    // Reading through a scalar is safe (`??` yields null); WRITING through it is
    // a TypeError, and nothing between HeaderHeroStep and the CLI catches it.
    // The promotion pass added the second write site, on the path this feature
    // exists to trigger. FooterMarkup already repairs the same malformed shape.
    foreach (['"style":"color:red"', '"style":{"typography":"big"}'] as $malformed) {
        $markup = '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained","contentSize":"720px"}} -->'
            . '<div class="wp-block-group hero-composition__copy">'
            . '<!-- wp:heading {"level":1,"fontSize":"section-title",' . $malformed . '} -->' . "\n"
            . '<h1 class="wp-block-heading has-section-title-font-size">Glass Given a Second Life as Light</h1>'
            . '<!-- /wp:heading --></div><!-- /wp:group -->';

        $r = HeroHeadlineFit::apply($markup, hhf_scale_theme(), [1, 2]);
        assert_contains('promoted to the display preset', implode("\n", $r['notes']));
        assert_contains(
            'font-size:min(var(--wp--preset--font-size--display)',
            (new Serializer())->transform($r['markup'])->html,
            'and the pin still lands'
        );
    }
});
