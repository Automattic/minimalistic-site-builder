<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\ThemeJsonStep;

/** A delivered solid construction, as theme-json writes it. @return array<mixed> */
function solid_cta_theme(): array
{
    [$theme] = ThemeJsonStep::repairCtaStyle(
        ['version' => 3, 'styles' => ['elements' => ['button' => ['typography' => ['fontWeight' => '700']]]]],
        'solid',
    );
    return $theme;
}

test('repairCtaStyle keeps the label ink contrast-fix wrote, in either preset spelling, as a fixed point', function () {
    // contrast-fix runs after theme-json and writes its readable label in the
    // CSS-variable spelling. PepeneBun build two (2026-09-04): the repair only
    // recognized the pipe spelling, so it treated the dark ink on the orange
    // accent as foreign, flipped it back to the paler token, and every leaf it
    // touched on the way became a warning row.
    $theme = solid_cta_theme();
    $theme['styles']['elements']['button']['color']['text'] = 'var(--wp--preset--color--contrast)';
    [$again, $repairs] = ThemeJsonStep::repairCtaStyle($theme, 'solid');
    assert_eq([], $repairs, 'a deterministic label in the CSS-variable spelling is the same result, not drift');
    assert_eq('var(--wp--preset--color--contrast)', $again['styles']['elements']['button']['color']['text']);

    $theme['styles']['elements']['button']['color']['text'] = 'var:preset|color|contrast';
    [, $repairs] = ThemeJsonStep::repairCtaStyle($theme, 'solid');
    assert_eq([], $repairs);
});

test('repairCtaStyle reports only the leaves it actually changed', function () {
    // One foreign label used to produce twenty-six rows: the stripper and the
    // merger narrate every leaf they pass through on the way back to the same
    // value. A row is a change the reader can act on, or it is noise.
    $theme = solid_cta_theme();
    $theme['styles']['elements']['button']['color']['text'] = 'var(--wp--preset--color--accent)';
    [$repaired, $repairs] = ThemeJsonStep::repairCtaStyle($theme, 'solid');
    assert_eq('var:preset|color|base', $repaired['styles']['elements']['button']['color']['text']);
    assert_eq(1, count($repairs), 'one changed leaf, one row');
    assert_contains('styles.elements.button.color.text', $repairs[0]);
    assert_contains('var(--wp--preset--color--accent)', $repairs[0]);

    // A genuinely competing declaration still reports, once, with its change.
    $theme = solid_cta_theme();
    $theme['styles']['elements']['button']['border']['width'] = '1px';
    [, $repairs] = ThemeJsonStep::repairCtaStyle($theme, 'solid');
    assert_eq(1, count($repairs));
    assert_contains('styles.elements.button.border.width', $repairs[0]);
    assert_contains('"1px"', $repairs[0]);
});
