<?php
declare(strict_types=1);

use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Units\OpeningHeadlineScale;

// Shapes taken from the tbilisi23 cohort site (BIGR-1015): `/menu/` authored
// "fontSize":"display" on its opening h1 and `/about/` authored no size at
// all, and both rendered at the 96px display maximum against a front hero
// pinned to 37px.

function ohs_theme(string $h1Size = 'var:preset|font-size|display'): array
{
    return [
        'settings' => ['typography' => ['fontSizes' => [
            ['slug' => 'section-title', 'size' => 'clamp(3.062rem, 3vw, 3.834rem)'],
            ['slug' => 'display', 'size' => 'clamp(3.797rem, 7vw, 6rem)'],
        ]]],
        'styles' => ['elements' => ['h1' => ['typography' => ['fontSize' => $h1Size]]]],
    ];
}

/** One page-opening section holding a single level-1 heading. */
function ohs_opening(string $attrs, string $classes = 'wp-block-heading'): string
{
    return '<!-- wp:group {"align":"full","anchor":"hero","layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group alignfull" id="hero">'
        . '<!-- wp:heading ' . $attrs . ' -->' . "\n"
        . '<h1 class="' . $classes . '">Traditional Georgian and Caucasian cuisine</h1>' . "\n"
        . '<!-- /wp:heading --></div>' . "\n"
        . '<!-- /wp:group -->';
}

/** Return the final HTML from the block fixer. */
function ohs_fixed_markup(string $markup): string
{
    return with_temp_dir('opening_scale_', function (string $dir) use ($markup): string {
        mkdir($dir . '/parts');
        $path = $dir . '/parts/opening.html';
        file_put_contents($path, $markup);
        $fixer = new PhpBlockFixer();
        assert_eq(0, $fixer->fixReport($dir)->failedCount(), 'the block fixer must complete');
        $out = (string) file_get_contents($path);
        $again = $fixer->fixReport($dir);
        assert_eq(0, $again->failedCount(), 'the second pass must complete');
        assert_eq(0, $again->changedCount(), 'the final HTML must reach a fixed point');
        assert_eq($out, file_get_contents($path));
        return $out;
    });
}

test('the block fixer cannot restore the display class from comment attributes', function () {
    foreach ([
        'has-display-font-size',
        "reveal-blur\thas-display-font-size\nhas-display-font-size-custom",
    ] as $classes) {
        $markup = ohs_opening(
            (string) json_encode(['level' => 1, 'fontSize' => 'display', 'className' => $classes]),
            'wp-block-heading ' . $classes,
        );
        $repairs = [];
        $out = OpeningHeadlineScale::enforce($markup, 'page-about--hero', ohs_theme(), $repairs);
        $final = ohs_fixed_markup($out);
        assert_true(
            preg_match('/(?<![\\w-])has-display-font-size(?![\\w-])/', $final) !== 1,
            'the final HTML must remove the display token',
        );
        assert_contains('has-section-title-font-size', $final);
        assert_eq(1, count($repairs));
        assert_eq('display', $repairs[0]['authored']);
        assert_eq('section-title', $repairs[0]['delivered']);
        if (str_contains($classes, 'reveal-blur')) {
            assert_contains('reveal-blur', $final);
            assert_contains('has-display-font-size-custom', $final);
        } else {
            assert_true(!str_contains($out, '"className"'), 'remove the empty className attribute');
        }
        $again = [];
        assert_eq($final, OpeningHeadlineScale::enforce($final, 'page-about--hero', ohs_theme(), $again));
        assert_eq([], $again);
    }
});

test('an inner opening h1 authored at the masthead preset is lowered to the section step', function () {
    $repairs = [];
    $markup = ohs_opening(
        '{"level":1,"className":"reveal-blur","fontSize":"display"}',
        'wp-block-heading reveal-blur has-display-font-size',
    );
    $out = OpeningHeadlineScale::enforce($markup, 'page-menu--hero', ohs_theme(), $repairs);

    assert_contains('"fontSize":"section-title"', $out, 'the heading takes the step below the masthead');
    assert_true(!str_contains($out, '"fontSize":"display"'), 'the masthead preset is gone from the attributes');
    assert_true(
        !str_contains($out, 'has-display-font-size'),
        'the stale preset class would beat the new attribute with !important',
    );
    assert_contains('class="wp-block-heading reveal-blur"', $out, 'unrelated classes survive');
    assert_eq(1, count($repairs), 'one repair row');
    assert_eq('display', $repairs[0]['authored']);
    assert_eq('section-title', $repairs[0]['delivered']);
    assert_eq('page-menu--hero', $repairs[0]['part']);
});

test('an inner opening h1 with no preset is lowered when the theme sizes h1 at the masthead', function () {
    // The `/about/` case: nothing in the markup shows the masthead scale —
    // the h1 inherits it from styles.elements.h1.
    $repairs = [];
    $markup = ohs_opening('{"level":1,"textColor":"contrast","fontFamily":"heading"}');
    $out = OpeningHeadlineScale::enforce($markup, 'page-about--hero', ohs_theme(), $repairs);

    assert_contains('"fontSize":"section-title"', $out, 'the inherited masthead step is overridden');
    assert_contains('"textColor":"contrast"', $out, 'sibling attributes survive');
    assert_eq(1, count($repairs));
    assert_contains('no preset', $repairs[0]['authored']);
});

test('a bare h1 is left alone when the theme does not size h1 at the masthead', function () {
    // The bound reads the theme rather than assuming it: a theme whose h1
    // element resolves below `display` has nothing to lower.
    $markup = ohs_opening('{"level":1,"textColor":"contrast"}');
    $repairs = [];
    assert_eq(
        $markup,
        OpeningHeadlineScale::enforce($markup, 'p', ohs_theme('var:preset|font-size|section-title'), $repairs),
        'byte-identical',
    );
    assert_eq([], $repairs);

    // No theme at all is the same answer, for the same reason.
    assert_eq($markup, OpeningHeadlineScale::enforce($markup, 'p', null), 'byte-identical without a theme');
});

test('the theme is read through both spellings of a preset reference', function () {
    $repairs = [];
    $markup = ohs_opening('{"level":1}');
    $out = OpeningHeadlineScale::enforce(
        $markup,
        'p',
        ohs_theme('var(--wp--preset--font-size--display)'),
        $repairs,
    );
    assert_contains('"fontSize":"section-title"', $out);
    assert_eq(1, count($repairs));
});

test('a heading the model already sized below the masthead is untouched', function () {
    foreach (['section-title', 'heading', 'lead'] as $slug) {
        $markup = ohs_opening('{"level":1,"fontSize":"' . $slug . '"}', 'wp-block-heading has-' . $slug . '-font-size');
        $repairs = [];
        assert_eq($markup, OpeningHeadlineScale::enforce($markup, 'p', ohs_theme(), $repairs), "byte-identical for {$slug}");
        assert_eq([], $repairs, "no repair row for {$slug}");
    }
});

test('an explicitly pinned size is a decision of its own and survives', function () {
    // HeroHeadlineFit writes exactly this shape. Re-deriving a preset from it
    // would undo a measured bound.
    $markup = ohs_opening(
        '{"level":1,"style":{"typography":{"fontSize":"min(var(--wp--preset--font-size--display), 72px)"}}}',
    );
    $repairs = [];
    assert_eq($markup, OpeningHeadlineScale::enforce($markup, 'p', ohs_theme(), $repairs), 'byte-identical');
    assert_eq([], $repairs);
});

test('headings below level 1 keep the scale the section gave them', function () {
    // The ban is on the masthead step for the page's own headline, not on the
    // display preset everywhere: a section H2 is not this pass's business.
    $markup = '<!-- wp:heading {"level":2,"fontSize":"display"} -->' . "\n"
        . '<h2 class="wp-block-heading has-display-font-size">Signature dishes</h2>' . "\n"
        . '<!-- /wp:heading -->';
    $repairs = [];
    assert_eq($markup, OpeningHeadlineScale::enforce($markup, 'p', ohs_theme(), $repairs), 'byte-identical');
    assert_eq([], $repairs);
});

test('the bound reaches a fixed point and leaves broken markup alone', function () {
    $markup = ohs_opening(
        '{"level":1,"fontSize":"display"}',
        'wp-block-heading has-display-font-size',
    );
    $repairs = [];
    $once = OpeningHeadlineScale::enforce($markup, 'p', ohs_theme(), $repairs);
    $again = [];
    assert_eq($once, OpeningHeadlineScale::enforce($once, 'p', ohs_theme(), $again), 'idempotent');
    assert_eq([], $again, 'a second pass has nothing to repair');

    // A truncated response is delivered as-is; this pass never throws on it.
    $broken = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":1,"fontSize":"display"} --><h1>Cut short';
    $dropped = [];
    assert_eq($broken, OpeningHeadlineScale::enforce($broken, 'p', ohs_theme(), $dropped), 'byte-identical');
    assert_eq([], $dropped);
});
