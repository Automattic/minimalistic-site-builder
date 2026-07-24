<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Json\JsonDecoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonNative;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Supports\StyleEngine;
use Automattic\SiteBuild\BlockSerializer\Supports\SupportDomainGuard;
use Automattic\SiteBuild\BlockSerializer\Supports\SupportEngine;

test('StyleEngine preserves pinned property order and preset conversion', function () {
    $rules = (new StyleEngine())->declarations([
        'typography' => ['fontWeight' => '700', 'letterSpacing' => '-0.02em'],
        'spacing' => ['padding' => ['top' => 'var:preset|spacing|XL', 'left' => '2rem']],
        'border' => ['radius' => '12px', 'top' => ['width' => '1px']],
        'shadow' => '0 1px 2px #000',
        'color' => ['background' => '#fff'],
    ]);
    assert_eq([
        'borderRadius' => '12px',
        'borderTopWidth' => '1px',
        'backgroundColor' => '#fff',
        'paddingTop' => 'var(--wp--preset--spacing--xl)',
        'paddingLeft' => '2rem',
        'fontWeight' => '700',
        'letterSpacing' => '-0.02em',
        'boxShadow' => '0 1px 2px #000',
    ], $rules);
});

test('StyleEngine preserves the pinned legacy preset delimiter normalization', function () {
    assert_eq(
        [
            'borderBottomColor' => 'var(--wp--preset--color-primary)',
            'paddingTop' => 'var(--wp--preset--spacing-xl)',
        ],
        (new StyleEngine())->declarations([
            'border' => ['bottom' => ['color' => 'var:preset|color--primary']],
            'spacing' => ['padding' => ['top' => 'var:preset|spacing--xl']],
        ]),
    );
});

test('SupportEngine applies classes and inline styles in effective order', function () {
    $props = (new SupportEngine())->apply(
        'core/group',
        [
            'align' => 'full',
            'anchor' => 'hero',
            'ariaLabel' => 'Hero',
            'className' => 'custom',
            'backgroundColor' => 'base',
            'textColor' => 'contrast',
            'fontFamily' => 'heading',
            'style' => [
                'spacing' => ['padding' => ['top' => '2rem']],
                'typography' => ['textAlign' => 'center'],
            ],
        ],
        [
            'align' => ['wide', 'full'], 'anchor' => true, 'ariaLabel' => true,
            'color' => ['background' => true, 'text' => true],
            'spacing' => ['padding' => true],
            'typography' => ['textAlign' => true, '__experimentalFontFamily' => true],
        ],
    )->all();
    assert_eq('wp-block-group has-text-align-center alignfull custom has-contrast-color has-base-background-color has-text-color has-background has-heading-font-family', $props['className']);
    assert_eq('hero', $props['id']);
    assert_eq('Hero', $props['aria-label']);
    assert_eq(['paddingTop' => '2rem'], $props['style']);
});

test('SupportEngine honors className false without inventing a block class', function () {
    $props = (new SupportEngine())->apply(
        'core/paragraph',
        ['className' => 'authored'],
        ['className' => false, 'customClassName' => true],
    )->all();
    assert_eq('authored', $props['className']);
});

test('SupportDomainGuard rejects style families outside the reviewed PHP pipeline', function () {
    $guard = new SupportDomainGuard();
    $guard->assertSupported('core/group', [
        'style' => [
            'background' => [
                'backgroundImage' => ['url' => 'https://example.invalid/background.jpg'],
                'gradient' => 'linear-gradient(#fff,#000)',
                'backgroundPosition' => 'center',
                'backgroundRepeat' => 'no-repeat',
                'backgroundSize' => 'cover',
                'backgroundAttachment' => 'fixed',
            ],
            'border' => [
                'color' => '#112233',
                'style' => 'solid',
                'width' => '1px',
                'radius' => ['topLeft' => '4px'],
                'top' => ['color' => '#334455', 'style' => 'dashed', 'width' => '2px'],
            ],
            'color' => ['text' => '#112233', 'background' => '#fff', 'gradient' => 'linear-gradient(#fff,#000)'],
            'dimensions' => ['height' => '10px', 'minHeight' => '5px', 'width' => '20px', 'aspectRatio' => '2/1'],
            'elements' => [
                'link' => [
                    'color' => ['text' => '#112233'],
                    ':hover' => ['color' => ['text' => '#334455']],
                ],
            ],
            'layout' => ['selfStretch' => 'fill', 'flexSize' => '2'],
            'outline' => ['color' => '#000', 'style' => 'solid', 'offset' => '2px', 'width' => '1px'],
            'shadow' => '0 1px 2px #000',
            'spacing' => [
                'margin' => '1rem',
                'padding' => ['top' => '1rem', 'right' => '2rem'],
                'blockGap' => ['top' => '1rem', 'left' => '2rem'],
            ],
            'typography' => [
                'fontFamily' => 'serif',
                'fontSize' => '1rem',
                'fontStyle' => 'italic',
                'fontWeight' => '700',
                'letterSpacing' => '0.1em',
                'lineHeight' => '1.4',
                'textAlign' => 'center',
                'textColumns' => 2,
                'textDecoration' => 'underline',
                'textIndent' => '1em',
                'textShadow' => '0 1px #000',
                'textTransform' => 'uppercase',
                'writingMode' => 'vertical-rl',
            ],
        ],
        'layout' => [
            'type' => 'flex',
            'orientation' => 'vertical',
            'justifyContent' => 'space-between',
            'verticalAlignment' => 'center',
            'alignItems' => 'center',
            'flexWrap' => 'wrap',
            'contentSize' => '850px',
            'wideSize' => '1320px',
        ],
    ], '0');

    $guard->assertSupported('core/group', [
        'layout' => ['type' => 'default'],
    ], '0');

    $guard->assertSupported(
        'core/group',
        ['style' => ['position' => ['type' => 'sticky', 'top' => '0px']]],
        '0',
    );
    $guard->assertSupported(
        'core/group',
        ['style' => ['radius' => '12px']],
        '0',
    );
    $guard->assertSupported(
        'core/separator',
        ['style' => ['border' => ['top' => '0', 'left' => '0', 'right' => '0']]],
        '0',
    );
    $guard->assertSupported(
        'core/group',
        ['style' => ['background' => ['backgroundImage' => ['ref' => 'none']]]],
        '0',
    );
    $guard->assertSupported(
        'core/group',
        ['style' => ['background' => ['backgroundImage' => ['ref' => 'var:preset|gradient|plum-teal-tile']]]],
        '0',
    );
    $guard->assertSupported('core/paragraph', ['style' => ['fontFamily' => 'heading']], '0');
    $guard->assertSupported('core/group', ['style' => ['display' => 'flex']], '0');
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['style' => ['display' => 'block']],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/paragraph',
        ['style' => ['fontFamily' => 'heading family']],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['style' => ['background' => ['backgroundImage' => ['ref' => 'featured']]]],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/separator',
        ['style' => ['border' => ['top' => '1px']]],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['style' => ['radius' => '8px']],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['style' => ['radius' => ['topLeft' => '12px']]],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['style' => ['position' => ['type' => 'fixed', 'top' => '0px']]],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['style' => 'not-an-object'],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['style' => ['spacing' => ['unsupported' => '1rem']]],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['layout' => ['type' => 'grid']],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['layout' => ['alignItems' => 'baseline']],
        '0',
    ));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['layout' => ['wideSize' => '']],
        '0',
    ));
});

test('SupportDomainGuard admits pinned comment-only group layout and link typography', function () {
    $attrs = [
        'style' => [
            'layout' => ['type' => 'constrained'],
            'elements' => [
                'link' => [
                    'typography' => ['textDecoration' => 'none'],
                    ':hover' => ['typography' => ['textDecoration' => 'underline']],
                ],
            ],
        ],
        'layout' => ['type' => 'constrained'],
    ];

    (new SupportDomainGuard())->assertSupported('core/group', $attrs, '0');
    assert_throws(static fn () => (new SupportDomainGuard())->assertSupported(
        'core/group',
        ['style' => ['layout' => ['type' => 'flex']]],
        '0',
    ));
    assert_throws(static fn () => (new SupportDomainGuard())->assertSupported(
        'core/group',
        ['style' => ['elements' => ['link' => ['typography' => ['textDecoration' => 'overline']]]]],
        '0',
    ));
});

/** @return JsonObject */
function supports_test_attributes(string $json): JsonObject
{
    $decoded = (new JsonDecoder($json))->decode();
    if (!$decoded instanceof JsonObject) {
        throw new RuntimeException('supports test attributes fixture must be a JSON object');
    }
    return $decoded;
}

test('SupportDomainGuard prunes invented style keys and keeps reviewed state', function () {
    $guard = new SupportDomainGuard();

    $attributes = supports_test_attributes('{"style":{"spacing":'
        . '{"mediaPadding":{"top":"0","right":"0"},"margin":{"top":"1rem"}},'
        . '"typography":{"fontStretch":"75%","fontWeight":"600"}}}');
    assert_eq(
        ['spacing.mediaPadding', 'typography.fontStretch'],
        $guard->pruneInventedStylePaths('core/media-text', $attributes),
    );
    assert_eq(
        ['style' => [
            'spacing' => ['margin' => ['top' => '1rem']],
            'typography' => ['fontWeight' => '600'],
        ]],
        JsonNative::objectToArray($attributes),
    );
    $guard->assertSupported('core/media-text', JsonNative::objectToArray($attributes), '0');

    // A style object left with no keys is removed entirely.
    $emptied = supports_test_attributes('{"style":{"spacing":{"mediaPadding":{"top":"0"}}},"align":"wide"}');
    assert_eq(['spacing.mediaPadding'], $guard->pruneInventedStylePaths('core/media-text', $emptied));
    assert_eq(['align' => 'wide'], JsonNative::objectToArray($emptied));
});

test('SupportDomainGuard never prunes pinned-but-unimplemented style families', function () {
    $guard = new SupportDomainGuard();

    // Carried families: unknown keys are kept verbatim in the delimiter AND
    // pass validation — no save-path consumer reads them, so the pinned
    // save hooks emit identical bytes with or without them.
    foreach ([
        '{"style":{"css":"color:red"}}',
        '{"style":{"filter":{"blur":"2px"}}}',
        '{"style":{"variation":"section-1"}}',
        '{"style":{"color":{"duotone":"var:preset|duotone|dark"}}}',
        '{"style":{"layout":{"columnSpan":2}}}',
        '{"style":{"layout":{"contentSize":"720px"}}}',
        '{"style":{"position":{"bottom":"0"}}}',
    ] as $json) {
        $attributes = supports_test_attributes($json);
        $before = JsonNative::objectToArray($attributes);
        assert_eq([], $guard->pruneInventedStylePaths('core/group', $attributes));
        assert_eq($before, JsonNative::objectToArray($attributes), 'pinned families keep authored bytes');
        $guard->assertSupported('core/group', $before, '0');
    }

    // style.background stays fail-closed: StyleEngine consumes it wholesale,
    // so an unreviewed shape could change the emitted bytes.
    $background = supports_test_attributes('{"style":{"background":{"backgroundImage":{"id":42}}}}');
    $before = JsonNative::objectToArray($background);
    assert_eq([], $guard->pruneInventedStylePaths('core/group', $background));
    assert_eq($before, JsonNative::objectToArray($background), 'pinned families keep authored bytes');
    assert_throws(static fn () => $guard->assertSupported('core/group', $before, '0'));
});

test('SupportDomainGuard carries a misplaced style.layout.contentSize instead of failing', function () {
    $guard = new SupportDomainGuard();
    // Verbatim signature from generated site tbilisi60: the model wrote the
    // constrained width under style.layout instead of the top-level layout
    // attribute. core/group save() reads only the top-level attribute, so
    // the misplaced key is dead delimiter state — carried, never pruned.
    $attributes = supports_test_attributes(
        '{"style":{"layout":{"contentSize":"720px"}},"layout":{"type":"constrained"}}'
    );
    $before = JsonNative::objectToArray($attributes);
    assert_eq([], $guard->pruneInventedStylePaths('core/group', $attributes));
    assert_eq($before, JsonNative::objectToArray($attributes), 'authored bytes are kept');
    $guard->assertSupported('core/group', $before, '0');
});

test('SupportDomainGuard pruning leaves value mismatches and reviewed signatures alone', function () {
    $guard = new SupportDomainGuard();

    // Value-level mismatches on known paths are not pruned; they fail closed.
    $badValue = supports_test_attributes('{"style":{"radius":"8px","display":"block"}}');
    assert_eq([], $guard->pruneInventedStylePaths('core/group', $badValue));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        JsonNative::objectToArray($badValue),
        '0',
    ));

    // An authored object where the reviewed rule expects a scalar stays.
    $badShape = supports_test_attributes('{"style":{"shadow":{"blur":"2px"}}}');
    assert_eq([], $guard->pruneInventedStylePaths('core/group', $badShape));
    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        JsonNative::objectToArray($badShape),
        '0',
    ));

    // The reviewed inert AI signatures remain retained delimiter state.
    foreach ([
        ['core/navigation', '{"style":{"fontSize":"caption"}}'],
        ['core/paragraph', '{"style":{"typography":{"fontStyle":"italic","fontStyleNormal":false}}}'],
        ['core/paragraph', '{"style":{"elements":{"caption":{"typography":{"fontStyle":"italic"}}}}}'],
    ] as [$name, $json]) {
        $attributes = supports_test_attributes($json);
        $before = JsonNative::objectToArray($attributes);
        assert_eq([], $guard->pruneInventedStylePaths($name, $attributes));
        assert_eq($before, JsonNative::objectToArray($attributes));
    }

    // Outside its reviewed block, the same spelling is invented and pruned.
    $inventedElsewhere = supports_test_attributes('{"style":{"fontSize":"caption"}}');
    assert_eq(['fontSize'], $guard->pruneInventedStylePaths('core/group', $inventedElsewhere));
    assert_eq([], JsonNative::objectToArray($inventedElsewhere)['style'] ?? []);
});

test('SupportDomainGuard admits only the exact reviewed inert AI style signatures', function () {
    $guard = new SupportDomainGuard();
    $guard->assertSupported(
        'core/navigation',
        ['style' => ['fontSize' => 'caption']],
        '0',
    );
    $guard->assertSupported(
        'core/paragraph',
        ['style' => ['typography' => ['fontStyle' => 'italic', 'fontStyleNormal' => false]]],
        '0',
    );
    $guard->assertSupported(
        'core/paragraph',
        ['style' => ['elements' => [
            'caption' => ['typography' => ['fontStyle' => 'italic']],
        ]]],
        '0',
    );

    foreach ([
        ['core/navigation', ['style' => ['fontSize' => 'body']]],
        ['core/navigation', ['style' => ['fontSize' => '0.875rem']]],
        ['core/group', ['style' => ['fontSize' => 'caption']]],
        ['core/paragraph', ['style' => ['typography' => ['fontStyleNormal' => false]]]],
        ['core/paragraph', ['style' => ['typography' => ['fontStyle' => 'normal', 'fontStyleNormal' => false]]]],
        ['core/paragraph', ['style' => ['typography' => ['fontStyle' => 'italic', 'fontStyleNormal' => true]]]],
        ['core/paragraph', ['style' => ['typography' => ['fontStyle' => 'italic', 'fontStyleNormal' => 0]]]],
        ['core/paragraph', ['style' => ['typography' => ['fontStyle' => 'italic', 'fontStyleNormal' => null]]]],
        ['core/paragraph', ['style' => ['typography' => ['fontStyle' => 'italic', 'fontStyleNormal' => 'false']]]],
        ['core/group', ['style' => ['typography' => ['fontStyle' => 'italic', 'fontStyleNormal' => false]]]],
    ] as [$name, $attributes]) {
        assert_throws(static fn () => $guard->assertSupported($name, $attributes, '0'));
    }

    // Near-miss caption variants used to fail closed; since unreviewed
    // elements paths became delimiter-carried state they pass instead.
    foreach ([
        ['core/paragraph', ['style' => ['elements' => [
            'caption' => ['typography' => ['fontStyle' => 'normal']],
        ]]]],
        ['core/paragraph', ['style' => ['elements' => [
            'caption' => ['typography' => ['fontStyle' => 'italic'], 'extra' => false],
        ]]]],
        ['core/group', ['style' => ['elements' => [
            'caption' => ['typography' => ['fontStyle' => 'italic']],
        ]]]],
    ] as [$name, $attributes]) {
        $guard->assertSupported($name, $attributes, '0');
    }
});

test('SupportDomainGuard carries unreviewed elements state per block instead of failing', function () {
    $guard = new SupportDomainGuard();
    // The two signatures observed in generated sites (a button :hover
    // background, elements.heading on a heading), plus a novel invention:
    // all pass validation with their authored bytes untouched, because
    // unreviewed elements paths are delimiter-carried state at the pinned
    // save hooks, not saved-markup behaviour.
    foreach ([
        ['core/button', '{"textColor":"accent","style":{"color":{"background":"transparent"},'
            . '"elements":{"link":{":hover":{"color":{"background":"var:preset|color|accent",'
            . '"text":"var:preset|color|base"}}}}}}'],
        ['core/heading', '{"style":{"elements":{"heading":{"color":{"text":"var:preset|color|base"}}}}}'],
        ['core/group', '{"style":{"elements":{"cite":{"typography":{"fontStyle":"italic"}}}}}'],
    ] as [$name, $json]) {
        $attributes = supports_test_attributes($json);
        $before = JsonNative::objectToArray($attributes);
        assert_eq([], $guard->pruneInventedStylePaths($name, $attributes), "{$name} elements state is never pruned");
        assert_eq($before, JsonNative::objectToArray($attributes), "{$name} keeps authored bytes");
        $guard->assertSupported($name, $before, '0');
    }
});

test('SupportDomainGuard still validates the reviewed elements link recipe strictly', function () {
    $guard = new SupportDomainGuard();
    // A reviewed path with a malformed value fails closed even though its
    // unreviewed siblings are carried.
    assert_throws(static fn () => $guard->assertSupported('core/group', [
        'style' => ['elements' => ['link' => ['color' => ['text' => ['not' => 'a-color']]]]],
    ], '0'));
    // The reviewed hover text color next to a carried hover background keeps
    // validating: a bad text value still fails.
    assert_throws(static fn () => $guard->assertSupported('core/group', [
        'style' => ['elements' => ['link' => [':hover' => ['color' => [
            'background' => 'var:preset|color|accent',
            'text' => ['not' => 'a-color'],
        ]]]]],
    ], '0'));
});
