<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Serializer;

/**
 * Nested support state the pinned runtime cannot consume — layout values and
 * style values alike — is corrected or dropped rather than failing the build.
 *
 * The generator gets layout wrong the same way it gets attribute names wrong:
 * by borrowing something real from next door. `justifyContent` accepts
 * left/center/right/space-between and `verticalAlignment` accepts stretch, so a
 * section reading `"justifyContent":"stretch"` is a valid value on the wrong
 * property — the same mistake as `verticalAlignment` on core/group, one level
 * down. One such word used to discard an entire build at the last step.
 */

/** @return array{0:string,1:list<string>} the first delimiter line and its repair codes */
function layout_guard_transform(string $attrs): array
{
    $result = (new Serializer())->transform(
        "<!-- wp:group {$attrs} --><div class=\"wp-block-group\"></div><!-- /wp:group -->"
    );
    return [
        trim(explode("\n", $result->html)[0]),
        array_map(static fn ($r): string => $r->code, $result->repairs),
    ];
}

test('an invalid layout value is dropped, leaving a layout that still renders', function () {
    // The exact markup that killed a 13-minute build.
    [$html, $codes] = layout_guard_transform('{"layout":{"type":"flex","justifyContent":"stretch"}}');

    assert_contains('"layout":{"type":"flex"}', $html, 'the flex container survives without the bad refinement');
    assert_true(!str_contains($html, 'stretch'), 'the borrowed value is gone');
    assert_eq(
        ['invalid-layout-dropped:{"block":"core/group","key":"justifyContent","value":"stretch"}'],
        $codes,
    );
});

test('a layout value that only varies in shape is corrected, not dropped', function () {
    foreach (['space between', 'spaceBetween', 'Space-Between'] as $authored) {
        [$html, $codes] = layout_guard_transform(
            '{"layout":{"type":"flex","justifyContent":"' . $authored . '"}}'
        );
        assert_contains('"justifyContent":"space-between"', $html, $authored);
        assert_eq(
            ['layout-value-corrected:{"block":"core/group","key":"justifyContent","from":"'
                . $authored . '","to":"space-between"}'],
            $codes,
            $authored,
        );
    }
});

test('an unknown layout key is dropped', function () {
    [$html, $codes] = layout_guard_transform('{"layout":{"type":"flex","alignContent":"middle"}}');

    assert_contains('"layout":{"type":"flex"}', $html);
    assert_eq(
        ['invalid-layout-dropped:{"block":"core/group","key":"alignContent","value":"middle"}'],
        $codes,
    );
});

test('valid layout state passes through untouched', function () {
    [$html, $codes] = layout_guard_transform(
        '{"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap","verticalAlignment":"stretch"}}'
    );

    // `stretch` is legitimate here — the same word that is invalid on
    // justifyContent — so nothing is touched.
    assert_contains('"verticalAlignment":"stretch"', $html);
    assert_contains('"justifyContent":"space-between"', $html);
    assert_eq([], $codes, 'valid layout produces no repairs');
});

test('a layout emptied by dropping is removed entirely', function () {
    [$html, $codes] = layout_guard_transform('{"layout":{"justifyContent":"stretch"}}');

    assert_true(!str_contains($html, 'layout'), 'no bare empty layout object is left behind');
    assert_eq(1, count($codes));
});

test('a layout that is not an object at all is removed', function () {
    foreach (['"flex"', '42', 'null', '["flex"]'] as $shape) {
        [$html, $codes] = layout_guard_transform('{"layout":' . $shape . '}');
        assert_true(!str_contains($html, 'layout'), $shape);
        assert_eq(
            ['invalid-layout-dropped:{"block":"core/group","key":"layout","value":"non-object"}'],
            $codes,
            $shape,
        );
    }
});

test('contentSize and wideSize keep any non-empty string but lose unusable ones', function () {
    [$html, $codes] = layout_guard_transform('{"layout":{"type":"constrained","contentSize":"860px"}}');
    assert_contains('"contentSize":"860px"', $html);
    assert_eq([], $codes, 'a free-string layout key is not enum-checked');

    [$html, $codes] = layout_guard_transform('{"layout":{"type":"constrained","contentSize":""}}');
    assert_true(!str_contains($html, 'contentSize'), 'an empty free-string key is dropped');
    assert_eq(1, count($codes));
});

/** @return array{0:string,1:list<string>} the first delimiter line and its repair codes */
function nested_style_transform(string $attrs): array
{
    $result = (new Serializer())->transform(
        "<!-- wp:group {$attrs} --><div class=\"wp-block-group\"></div><!-- /wp:group -->"
    );
    return [
        trim(explode("\n", $result->html)[0]),
        array_map(static fn ($r): string => $r->code, $result->repairs),
    ];
}

test('an object where a style scalar belongs is dropped', function () {
    // The second gate the same build hit: the model wrapped a preset reference
    // in {"ref": …} at a path whose reviewed rule accepts only a scalar.
    $result = (new Serializer())->transform(
        '<!-- wp:button {"style":{"elements":{"link":{"color":{"text":{"ref":"var:preset|color|base"}}}}}} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Go</a></div>'
        . '<!-- /wp:button -->'
    );
    $codes = array_map(static fn ($r): string => $r->code, $result->repairs);

    assert_true(!str_contains($result->html, '"ref"'), 'the unusable wrapper is gone');
    assert_contains('wp-block-button__link', $result->html, 'the button still renders');
    assert_true(
        in_array(
            'unusable-style-dropped:{"block":"core/button","key":"elements.link.color.text","value":"object"}',
            $codes,
            true,
        ),
        'the drop names the full style path',
    );
});

test('a style value outside its enumerated set is corrected by shape or dropped', function () {
    // textDecoration accepts none|underline.
    [$html, $codes] = nested_style_transform(
        '{"style":{"elements":{"link":{"typography":{"textDecoration":"UNDERLINE"}}}}}'
    );
    assert_contains('"textDecoration":"underline"', $html, 'a shape variant is corrected');
    assert_eq(1, count($codes));
    assert_contains('style-value-corrected:', $codes[0]);

    [$html, $codes] = nested_style_transform(
        '{"style":{"elements":{"link":{"typography":{"textDecoration":"squiggly"}}}}}'
    );
    assert_true(!str_contains($html, 'squiggly'), 'an unrecognizable value is dropped');
    assert_contains('unusable-style-dropped:', $codes[0]);
});

test('valid style state passes through untouched', function () {
    [$html, $codes] = nested_style_transform(
        '{"style":{"spacing":{"padding":{"top":"var:preset|spacing|md"}},'
        . '"elements":{"link":{"color":{"text":"var:preset|color|accent"}}}}}'
    );
    assert_contains('var:preset|color|accent', $html);
    assert_contains('var:preset|spacing|md', $html);
    assert_eq([], $codes, 'valid nested state produces no repairs');
});

test('style.background stays fail-closed', function () {
    // The reviewed exception: StyleEngine consumes background, so a value this
    // pass cannot judge would render a visibly broken band rather than a
    // missing refinement. It must still fail rather than degrade.
    // Fail-closed now means "emit nothing we guessed at" rather than "kill the
    // run": the block keeps its authored bytes verbatim and reports why, so no
    // wrong background is ever written.
    assert_block_kept_as_authored(
        '<!-- wp:group {"style":{"background":{"backgroundSize":{"nested":"object"}}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->',
        'style.background',
    );
});

test('a style emptied by dropping is removed entirely', function () {
    [$html, $codes] = nested_style_transform(
        '{"style":{"elements":{"link":{"typography":{"textDecoration":"squiggly"}}}}}'
    );
    assert_true(!str_contains($html, '"style"'), 'no bare empty style object is left behind');
    assert_eq(1, count($codes));
});

test('a border-style the HTML declares but the delimiter omits is recovered, not dropped', function () {
    // A CSS border only renders with a border-style, so generated markup writes
    // color and width into the delimiter while emitting all three declarations
    // into the HTML. Sourcing the style back makes the re-render byte-identical
    // to what was authored — the border survives exactly as designed.
    $markup = '<!-- wp:paragraph {"style":{"border":{"left":{"color":"var:preset|color|accent","width":"2px"}}}} -->' . "\n"
        . '<p style="border-left-color:var(--wp--preset--color--accent);border-left-style:solid;border-left-width:2px">Note</p>' . "\n"
        . '<!-- /wp:paragraph -->';

    $result = (new Serializer())->transform($markup);
    $codes = array_map(static fn ($r): string => $r->code, $result->repairs);

    assert_contains('"style":"solid"', $result->html, 'the missing longhand is sourced into the delimiter');
    assert_contains('border-left-style:solid', $result->html, 'the authored border still renders');
    assert_true(
        in_array(
            'border-style-recovered:{"block":"core/paragraph","key":"style.border.left.style"}',
            $codes,
            true,
        ),
        'the recovery is reported',
    );
});

test('a border side the delimiter never described is not invented from a stray declaration', function () {
    // Recovery fills a gap in something authored; it does not conjure a border
    // side out of a declaration with no attribute behind it. The block guard
    // therefore still objects — and that is the layering working as intended:
    // this pass refuses to guess, and PhpBlockFixer keeps the one file as
    // authored so the run continues (see php_block_fixer_test.php).
    $markup = '<!-- wp:paragraph {"style":{"border":{"left":{"width":"2px"}}}} -->' . "\n"
        . '<p style="border-top-style:dashed">Note</p>' . "\n"
        . '<!-- /wp:paragraph -->';

    assert_block_kept_as_authored($markup, 'border-top-style');
});

test('a border is only read from the block own wrapper', function () {
    // Generated markup is structurally sloppy, so a stray element can precede
    // the wrapper. Taking "the first element" would lift a border off something
    // the block save() then discards, writing a border nobody asked for.
    $markup = '<!-- wp:group {"style":{"border":{"left":{"color":"#f00","width":"4px"}}}} -->' . "\n"
        . '<p style="border-left-style:dotted">stray</p>' . "\n"
        . '<div class="wp-block-group" style="border-left-color:#f00;border-left-width:4px"></div>' . "\n"
        . '<!-- /wp:group -->';

    $result = (new Serializer())->transform($markup);
    assert_true(!str_contains($result->html, 'dotted'), 'no border is lifted off a stray element');
});

test('a dropped number is reported as the author wrote it', function () {
    // columnCount is numeric and valid on a grid; a string there is not, and
    // the report must echo what the author actually wrote.
    [, $codes] = layout_guard_transform('{"layout":{"type":"grid","columnCount":"three"}}');
    assert_contains('"value":"three"', $codes[0]);

    [, $codes] = layout_guard_transform('{"layout":{"type":"flex","flexWrap":3}}');
    assert_contains('"value":"3"', $codes[0], 'not the decoded float 3.0');
});

test('a grid layout survives intact', function () {
    // WordPress has supported grid on core/group since 6.3. Excluding it from
    // the reviewed domain silently flattened a three-column section into a
    // vertical stack: `type` was dropped as unrecognised, `columnCount` as an
    // unknown key, and the emptied layout object removed wholesale.
    [$html, $codes] = layout_guard_transform(
        '{"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"12rem"}}'
    );
    assert_contains('"type":"grid"', $html);
    assert_contains('"columnCount":3', $html);
    assert_contains('"minimumColumnWidth":"12rem"', $html);
    assert_eq([], $codes, 'a valid grid produces no repairs');
});
