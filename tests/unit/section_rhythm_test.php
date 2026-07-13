<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\SectionRhythm;

/** @param array<mixed> $attrs */
function sr_section(array $attrs = [], string $inner = ''): string
{
    return BlockMarkup::serializeComment('group', $attrs, false)
        . '<div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
}

/** @return array<mixed> */
function sr_root_attrs(string $markup): array
{
    $doc = BlockMarkup::parse($markup);
    foreach ($doc->indices() as $i) {
        if ($doc->parent($i) === null) {
            return $doc->attrs($i) ?? [];
        }
    }
    throw new RuntimeException('fixture has no root block');
}

/** @return array<mixed> */
function sr_first_attrs(string $markup, string $name): array
{
    $doc = BlockMarkup::parse($markup);
    foreach ($doc->indices() as $i) {
        if ($doc->name($i) === $name) {
            return $doc->attrs($i) ?? [];
        }
    }
    throw new RuntimeException("fixture has no {$name} block");
}

function sr_image_section(): string
{
    $cover = '<!-- wp:cover {"align":"full","dimRatio":50,"metadata":{},"allowedBlocks":[],"style":{"spacing":{"padding":{"top":"12rem","bottom":"12rem"}}}} -->'
        . '<div class="wp-block-cover alignfull"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
        . '<div class="wp-block-cover__inner-container"><p>Image band</p></div></div><!-- /wp:cover -->';
    return sr_section(['align' => 'full', 'layout' => ['type' => 'constrained']], $cover);
}

test('section rhythm owns vertical root spacing while preserving every other attribute', function () {
    $nested = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm"}}}} -->'
        . '<div class="wp-block-group">Nested</div><!-- /wp:group -->';
    $first = sr_section([
        'align' => 'full',
        'backgroundColor' => 'base',
        'layout' => ['type' => 'constrained'],
        'style' => [
            'spacing' => [
                'padding' => [
                    'top' => '12rem',
                    'right' => 'var:preset|spacing|md',
                    'bottom' => '1px',
                    'left' => '2rem',
                ],
                'margin' => ['top' => '-4rem', 'right' => '3px', 'bottom' => 'var:preset|spacing|sm'],
                'blockGap' => 'var:preset|spacing|md',
            ],
            'border' => ['radius' => '4px'],
        ],
        'metadata' => ['name' => 'Keep me'],
    ], $nested);
    $second = sr_section(['layout' => ['type' => 'constrained']]);

    $result = SectionRhythm::rewrite([
        ['slug' => 'intro', 'markup' => $first, 'density' => 'compact', 'background' => 'base'],
        ['slug' => 'work', 'markup' => $second, 'density' => 'standard', 'background' => 'contrast'],
    ]);

    assert_eq(2, count($result['markups']));
    assert_eq(2, count($result['notes']));
    $attrs = sr_root_attrs($result['markups'][0]);
    assert_eq('var:preset|spacing|lg', $attrs['style']['spacing']['padding']['top']);
    assert_eq('var:preset|spacing|lg', $attrs['style']['spacing']['padding']['bottom']);
    assert_eq('var:preset|spacing|md', $attrs['style']['spacing']['padding']['right']);
    assert_eq('2rem', $attrs['style']['spacing']['padding']['left']);
    assert_eq('0', $attrs['style']['spacing']['margin']['top']);
    assert_eq('3px', $attrs['style']['spacing']['margin']['right']);
    assert_eq('0', $attrs['style']['spacing']['margin']['bottom'], 'the builder owns both outer margins');
    assert_eq('var:preset|spacing|md', $attrs['style']['spacing']['blockGap']);
    assert_eq(['radius' => '4px'], $attrs['style']['border']);
    assert_eq(['name' => 'Keep me'], $attrs['metadata']);
    assert_eq('full', $attrs['align']);
    assert_eq(['type' => 'constrained'], $attrs['layout']);
    assert_contains($nested, $result['markups'][0], 'nested markup and its spacing must be byte-identical');

    $attrs = sr_root_attrs($result['markups'][1]);
    assert_eq('var:preset|spacing|xl', $attrs['style']['spacing']['padding']['top']);
    assert_eq('var:preset|spacing|xl', $attrs['style']['spacing']['padding']['bottom']);
    assert_eq('0', $attrs['style']['spacing']['margin']['top']);
});

test('section rhythm puts image density inside the direct cover, not outside the band', function () {
    $result = SectionRhythm::rewrite([[
        'slug' => 'hero',
        'markup' => sr_image_section(),
        'density' => 'standard',
        'background' => 'image',
    ]]);

    $root = sr_root_attrs($result['markups'][0]);
    assert_eq('0', $root['style']['spacing']['padding']['top']);
    assert_eq('0', $root['style']['spacing']['padding']['bottom']);
    assert_eq('0', $root['style']['spacing']['margin']['bottom']);

    $cover = sr_first_attrs($result['markups'][0], 'cover');
    assert_eq('var:preset|spacing|xl', $cover['style']['spacing']['padding']['top']);
    assert_eq('var:preset|spacing|xl', $cover['style']['spacing']['padding']['bottom']);
    assert_eq('0', $cover['style']['spacing']['margin']['top']);
    assert_eq('0', $cover['style']['spacing']['margin']['bottom']);
    assert_contains('"metadata":{}', $result['markups'][0]);
    assert_contains('"allowedBlocks":[]', $result['markups'][0]);
    assert_contains('image-cover padding', implode("\n", $result['notes']));
});

test('section rhythm collapses same-background seams onto the following top edge', function () {
    $result = SectionRhythm::rewrite([
        ['slug' => 'one', 'markup' => sr_section(), 'density' => 'compact', 'background' => 'base'],
        ['slug' => 'two', 'markup' => sr_section(), 'density' => 'spacious', 'background' => 'base'],
        ['slug' => 'three', 'markup' => sr_section(), 'density' => 'standard', 'background' => 'contrast'],
    ]);

    $one = sr_root_attrs($result['markups'][0])['style']['spacing'];
    $two = sr_root_attrs($result['markups'][1])['style']['spacing'];
    $three = sr_root_attrs($result['markups'][2])['style']['spacing'];

    assert_eq('var:preset|spacing|lg', $one['padding']['top']);
    assert_eq('0', $one['padding']['bottom'], 'the prior section gives up its bottom edge');
    assert_eq('var:preset|spacing|xxl', $two['padding']['top'], 'the following section owns the shared seam');
    assert_eq('var:preset|spacing|xxl', $two['padding']['bottom'], 'a background change retains the current interior edge');
    assert_eq('var:preset|spacing|xl', $three['padding']['top'], 'a background change retains the next interior edge');
    assert_eq('var:preset|spacing|xl', $three['padding']['bottom'], 'the last section retains its exterior edge');
    assert_contains('shared base seam is owned by section \'two\'', implode("\n", $result['notes']));
});

test('section rhythm only collapses surfaces guaranteed to be visually identical', function () {
    $tinted = SectionRhythm::rewrite([
        ['markup' => sr_section(), 'density' => 'compact', 'background' => 'tinted'],
        ['markup' => sr_section(), 'density' => 'compact', 'background' => 'tinted'],
    ]);
    assert_eq(
        'var:preset|spacing|lg',
        sr_root_attrs($tinted['markups'][0])['style']['spacing']['padding']['bottom'],
        'independently generated tinted gradients may differ'
    );

    $images = SectionRhythm::rewrite([
        ['markup' => sr_image_section(), 'density' => 'compact', 'background' => 'image'],
        ['markup' => sr_image_section(), 'density' => 'compact', 'background' => 'image'],
    ]);
    assert_eq(
        'var:preset|spacing|lg',
        sr_first_attrs($images['markups'][0], 'cover')['style']['spacing']['padding']['bottom'],
        'different image assets are never one continuous surface'
    );
});

test('section rhythm reconciles a same-surface footer and can inspect chrome surfaces', function () {
    $footer = sr_section([
        'backgroundColor' => 'base',
        'style' => ['spacing' => ['padding' => ['top' => 'var:preset|spacing|lg']]],
    ]);
    assert_eq('base', SectionRhythm::surfaceFromMarkup($footer));
    assert_eq('base', SectionRhythm::followingSurfaceFromMarkup($footer));
    assert_eq('contrast', SectionRhythm::surfaceFromMarkup(sr_section(['backgroundColor' => 'contrast'])));
    assert_eq(null, SectionRhythm::surfaceFromMarkup(sr_section(['gradient' => 'wash'])));
    assert_eq(null, SectionRhythm::surfaceFromMarkup(sr_section([
        'style' => ['color' => ['gradient' => 'var:preset|gradient|wash']],
    ])));

    $same = SectionRhythm::rewrite([
        ['slug' => 'contact', 'markup' => sr_section(), 'density' => 'standard', 'background' => 'base'],
    ], SectionRhythm::surfaceFromMarkup($footer));
    assert_eq('0', sr_root_attrs($same['markups'][0])['style']['spacing']['padding']['bottom']);
    assert_contains('owned by the footer', implode("\n", $same['notes']));

    $footerWithoutEdge = sr_section(['backgroundColor' => 'base']);
    assert_eq(null, SectionRhythm::followingSurfaceFromMarkup($footerWithoutEdge));
    $kept = SectionRhythm::rewrite([
        ['markup' => sr_section(), 'density' => 'standard', 'background' => 'base'],
    ], SectionRhythm::followingSurfaceFromMarkup($footerWithoutEdge));
    assert_eq(
        'var:preset|spacing|xl',
        sr_root_attrs($kept['markups'][0])['style']['spacing']['padding']['bottom'],
        'a footer with no top edge cannot own the seam'
    );

    $different = SectionRhythm::rewrite([
        ['markup' => sr_section(), 'density' => 'standard', 'background' => 'contrast'],
    ], 'base');
    assert_eq(
        'var:preset|spacing|xl',
        sr_root_attrs($different['markups'][0])['style']['spacing']['padding']['bottom']
    );
});

test('section rhythm is idempotent and reports only actual mutations', function () {
    $entries = [
        ['slug' => 'one', 'markup' => sr_section(), 'density' => 'compact', 'background' => 'base'],
        ['slug' => 'two', 'markup' => sr_section(), 'density' => 'standard', 'background' => 'base'],
    ];
    $first = SectionRhythm::rewrite($entries);
    $second = SectionRhythm::rewrite([
        ['slug' => 'one', 'markup' => $first['markups'][0], 'density' => 'compact', 'background' => 'base'],
        ['slug' => 'two', 'markup' => $first['markups'][1], 'density' => 'standard', 'background' => 'base'],
    ]);

    assert_eq($first['markups'], $second['markups']);
    assert_eq([], $second['notes']);
});

test('section rhythm preserves JSON object and array shapes in untouched root attributes', function () {
    $markup = '<!-- wp:group {"align":"full","metadata":{},"items":[],"numeric":{"0":"zero"},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignfull">Content</div><!-- /wp:group -->';
    $result = SectionRhythm::rewrite([[
        'markup' => $markup,
        'density' => 'standard',
        'background' => 'base',
    ]]);

    assert_contains('"metadata":{}', $result['markups'][0]);
    assert_contains('"items":[]', $result['markups'][0]);
    assert_contains('"numeric":{"0":"zero"}', $result['markups'][0]);
    assert_contains('"layout":{"type":"constrained"}', $result['markups'][0]);
});

test('section rhythm requires a valid density and background for every entry', function () {
    foreach ([
        [['markup' => sr_section(), 'background' => 'base']],
        [['markup' => sr_section(), 'density' => 'huge', 'background' => 'base']],
        [['markup' => sr_section(), 'density' => 'compact']],
        [['markup' => sr_section(), 'density' => 'compact', 'background' => '   ']],
        [['markup' => sr_section(), 'density' => 'compact', 'background' => 'unknown']],
    ] as $entries) {
        assert_throws(static fn () => SectionRhythm::rewrite($entries));
    }
});

test('section rhythm rejects non-group and malformed section roots', function () {
    $badMarkups = [
        '<!-- wp:paragraph --><p>Not a group</p><!-- /wp:paragraph -->',
        '<!-- wp:group {"style": nope} --><div class="wp-block-group"></div><!-- /wp:group -->',
        '<!-- wp:group --><div class="wp-block-group">Never closed</div>',
        sr_section() . sr_section(),
        sr_section() . '<p>Content outside the group</p>',
        sr_section([], '<!-- wp:paragraph --><p>No cover</p><!-- /wp:paragraph -->'),
    ];

    foreach ($badMarkups as $markup) {
        $background = str_contains($markup, 'No cover') ? 'image' : 'base';
        assert_throws(static fn () => SectionRhythm::rewrite([[
            'slug' => 'broken',
            'markup' => $markup,
            'density' => 'compact',
            'background' => $background,
        ]]));
    }
});

test('section rhythm accepts a structurally valid attr-less root group', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">Content</div><!-- /wp:group -->';
    $result = SectionRhythm::rewrite([[
        'markup' => $markup,
        'density' => 'spacious',
        'background' => 'base',
    ]]);
    $attrs = sr_root_attrs($result['markups'][0]);
    assert_eq('var:preset|spacing|xxl', $attrs['style']['spacing']['padding']['top']);
    assert_eq('var:preset|spacing|xxl', $attrs['style']['spacing']['padding']['bottom']);
    assert_eq('0', $attrs['style']['spacing']['margin']['top']);
});
