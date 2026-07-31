<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;
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
    assert_eq([], $result['degradations']);
});

test('section rhythm degrades a coverless image section to solid-band spacing', function () {
    // A plan-declared image band whose model rendered no direct wp:cover
    // (e.g. a plain wp:image inside wp:columns) must not fail the build:
    // the root gets the same density edges an opaque background would.
    $markup = sr_section(
        ['align' => 'full', 'layout' => ['type' => 'constrained']],
        '<!-- wp:paragraph --><p>No cover</p><!-- /wp:paragraph -->'
    );
    $result = SectionRhythm::rewrite([[
        'slug' => 'lugar-ubicacion', 'markup' => $markup, 'density' => 'standard', 'background' => 'image',
    ]]);

    $attrs = sr_root_attrs($result['markups'][0]);
    assert_eq('var:preset|spacing|xl', $attrs['style']['spacing']['padding']['top']);
    assert_eq('var:preset|spacing|xl', $attrs['style']['spacing']['padding']['bottom']);
    assert_eq('0', $attrs['style']['spacing']['margin']['top']);
    assert_eq('0', $attrs['style']['spacing']['margin']['bottom']);
    assert_contains('site-build-section-rhythm-degraded-image', $attrs['className']);
    assert_eq('missing-direct-cover', $result['degradations'][0]['code']);
    assert_contains('solid-band rhythm', $result['degradations'][0]['message']);
    assert_eq([$result['degradations'][0]['message']], $result['notes']);
});

test('section rhythm degrades multi-cover and uneditable-cover image sections', function () {
    $cover = '<!-- wp:cover {"dimRatio":50,"style":{"spacing":{"padding":{"top":"12rem","bottom":"12rem"}}}} -->'
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container"><p>Band</p></div></div><!-- /wp:cover -->';
    $fatalCover = '<!-- wp:cover {"style":"broken"} -->'
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container"><p>Band</p></div></div><!-- /wp:cover -->';
    $twoCovers = sr_section(['layout' => ['type' => 'constrained']], $cover . $fatalCover);
    $result = SectionRhythm::rewrite([[
        'slug' => 'double', 'markup' => $twoCovers, 'density' => 'compact', 'background' => 'image',
    ]]);
    $attrs = sr_root_attrs($result['markups'][0]);
    assert_eq('var:preset|spacing|lg', $attrs['style']['spacing']['padding']['top']);
    assert_eq('var:preset|spacing|lg', $attrs['style']['spacing']['padding']['bottom']);
    assert_eq(
        '12rem',
        sr_first_attrs($result['markups'][0], 'cover')['style']['spacing']['padding']['top'],
        'ambiguous covers are left untouched — density moves to the root'
    );
    assert_contains('degraded to solid-band', implode("\n", $result['notes']));
    assert_eq('multiple-direct-covers', $result['degradations'][0]['code']);
    assert_contains('direct cover 2', $result['degradations'][0]['reason']);
    assert_contains('serializer-fatal non-object and was removed', $result['degradations'][0]['reason']);
    assert_true(
        !str_contains($result['markups'][0], '"style":"broken"'),
        'every parseable direct cover is sanitized before multi-cover fallback',
    );
    $serialized = (new Serializer())->transform($result['markups'][0])->html;
    $again = SectionRhythm::rewrite([[
        'slug' => 'double', 'markup' => $serialized, 'density' => 'compact', 'background' => 'image',
    ]]);
    assert_eq($serialized, $again['markups'][0], 'multi-cover fallback survives block serialization');
    assert_eq([], $again['notes']);

    $badCover = sr_section(
        ['layout' => ['type' => 'constrained']],
        '<!-- wp:cover {"dimRatio": nope} --><div class="wp-block-cover"><p>Band</p></div><!-- /wp:cover -->'
    );
    $result = SectionRhythm::rewrite([[
        'slug' => 'bad-cover', 'markup' => $badCover, 'density' => 'compact', 'background' => 'image',
    ]]);
    assert_eq(
        'var:preset|spacing|lg',
        sr_root_attrs($result['markups'][0])['style']['spacing']['padding']['top'],
        'an uneditable cover opener degrades instead of aborting the build'
    );
    assert_contains('degraded to solid-band', implode("\n", $result['notes']));
    assert_eq('invalid-cover-attributes', $result['degradations'][0]['code']);
});

test('section rhythm degraded image output is idempotent so the validator gate stays clean', function () {
    $entry = static fn (string $markup): array => [
        'slug' => 'lugar-ubicacion', 'markup' => $markup, 'density' => 'standard', 'background' => 'image',
    ];
    $coverless = sr_section(
        ['layout' => ['type' => 'constrained']],
        '<!-- wp:paragraph --><p>No cover</p><!-- /wp:paragraph -->'
    );
    // ThemeValidator::spacingWarnings re-runs rewrite() over the assembled
    // page with the same plan entry ('image' is never rewritten in the plan),
    // and any note becomes a drift warning — so a second pass over the
    // degraded output must change nothing and report nothing.
    $first = SectionRhythm::rewrite([$entry($coverless)]);
    $second = SectionRhythm::rewrite([$entry($first['markups'][0])]);
    assert_eq($first['markups'], $second['markups']);
    assert_eq([], $second['notes']);
    assert_eq('persisted-fallback', $second['degradations'][0]['code']);
    assert_eq(false, $second['degradations'][0]['newlyDetected']);
});

test('section rhythm safely degrades covers with unusable nested spacing state', function () {
    $cases = [
        'non-object-style' => [
            '{"style":"broken"}',
            '',
            '"style":"broken"',
            null,
            'was removed',
        ],
        'non-object-spacing' => [
            '{"style":{"spacing":"broken"}}',
            '',
            '"spacing":"broken"',
            null,
            'was removed',
        ],
        'non-object-padding' => [
            '{"style":{"spacing":{"padding":["x"]}}}',
            '',
            '"padding":["x"]',
            null,
            'was removed',
        ],
        'unparseable-padding' => [
            '{"style":{"spacing":{"padding":"var(--ambiguous-padding)"}}}',
            '',
            null,
            '"padding":"var(--ambiguous-padding)"',
            'was preserved',
        ],
        'non-object-margin' => [
            '{"style":{"spacing":{"margin":42}}}',
            '',
            null,
            '"margin":42',
            'was preserved',
        ],
        'unsafe-wrapper-spacing' => [
            '{"dimRatio":50}',
            ' style="padding:var(--ambiguous-padding)"',
            null,
            'style="padding:var(--ambiguous-padding)"',
            'unparseable inline padding shorthand',
        ],
    ];

    foreach ($cases as $slug => [$coverJson, $wrapperAttrs, $removed, $preserved, $reasonNeedle]) {
        $cover = "<!-- wp:cover {$coverJson} -->"
            . "<div class=\"wp-block-cover\"{$wrapperAttrs}>"
            . '<div class="wp-block-cover__inner-container"><p>Band</p></div></div><!-- /wp:cover -->';
        $markup = sr_section(['layout' => ['type' => 'constrained']], $cover);

        $result = SectionRhythm::rewrite([[
            'slug' => $slug, 'markup' => $markup, 'density' => 'compact', 'background' => 'image',
        ]]);

        $root = sr_root_attrs($result['markups'][0]);
        assert_eq('var:preset|spacing|lg', $root['style']['spacing']['padding']['top']);
        assert_contains('site-build-section-rhythm-degraded-image', $root['className']);
        assert_eq('unusable-cover-attributes', $result['degradations'][0]['code']);
        assert_contains($reasonNeedle, $result['degradations'][0]['reason']);
        if ($removed !== null) {
            assert_true(!str_contains($result['markups'][0], $removed), "{$slug}: fatal state was removed");
        }
        if ($preserved !== null) {
            assert_contains($preserved, $result['markups'][0], "{$slug}: serializer-safe state was preserved");
        }

        $serialized = (new Serializer())->transform($result['markups'][0])->html;
        $again = SectionRhythm::rewrite([[
            'slug' => $slug, 'markup' => $serialized, 'density' => 'compact', 'background' => 'image',
        ]]);
        assert_eq($serialized, $again['markups'][0], "{$slug}: fallback survives block serialization");
        assert_eq([], $again['notes'], "{$slug}: validator sees no false spacing drift");
    }

    $numericSide = sr_section(
        ['layout' => ['type' => 'constrained']],
        '<!-- wp:cover {"style":{"spacing":{"padding":{"right":0}}}} -->'
            . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container"><p>Band</p></div></div>'
            . '<!-- /wp:cover -->',
    );
    $numericResult = SectionRhythm::rewrite([[
        'slug' => 'numeric-side',
        'markup' => $numericSide,
        'density' => 'compact',
        'background' => 'image',
    ]]);
    assert_eq([], $numericResult['degradations'], 'serializer-safe numeric side values do not trigger fallback');
    assert_eq(0, sr_first_attrs($numericResult['markups'][0], 'cover')['style']['spacing']['padding']['right']);
    (new Serializer())->transform($numericResult['markups'][0]);
});

test('a degraded image section keeps image seam semantics for its neighbours', function () {
    $coverless = sr_section(
        ['layout' => ['type' => 'constrained']],
        '<!-- wp:paragraph --><p>No cover</p><!-- /wp:paragraph -->'
    );
    $result = SectionRhythm::rewrite([
        ['slug' => 'before', 'markup' => sr_section(), 'density' => 'compact', 'background' => 'base'],
        ['slug' => 'band', 'markup' => $coverless, 'density' => 'standard', 'background' => 'image'],
        ['slug' => 'after', 'markup' => sr_section(), 'density' => 'compact', 'background' => 'base'],
    ]);

    $before = sr_root_attrs($result['markups'][0])['style']['spacing'];
    $band = sr_root_attrs($result['markups'][1])['style']['spacing'];
    assert_eq('var:preset|spacing|lg', $before['padding']['bottom'], 'image never shares a seam, degraded or not');
    assert_eq('var:preset|spacing|xl', $band['padding']['top']);
    assert_eq('var:preset|spacing|xl', $band['padding']['bottom'], 'the degraded band keeps its own bottom edge');
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
        sr_section(
            ['layout' => ['type' => 'constrained']],
            '<!-- wp:cover [] --><div class="wp-block-cover"></div><!-- /wp:cover -->',
        ),
        sr_section() . sr_section(),
        sr_section() . '<p>Content outside the group</p>',
    ];

    foreach ($badMarkups as $markup) {
        foreach (['base', 'image'] as $background) {
            assert_throws(static fn () => SectionRhythm::rewrite([[
                'slug' => 'broken',
                'markup' => $markup,
                'density' => 'compact',
                'background' => $background,
            ]]), "a malformed root remains fatal for {$background} sections");
        }
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

test('section rhythm mirrors owned spacing into the wrapper style attribute', function () {
    // Valid Gutenberg markup mirrors the attrs into inline CSS. If the pass
    // rewrote only the attrs, the block fixer would later report the stale
    // inline values as dropped vertical-rhythm CSS and fail the build.
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"12rem","bottom":"12rem"},"margin":{"top":"0"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="margin-top:0;padding-top:12rem;padding-bottom:12rem;min-height: 40vh">'
        . '<!-- wp:heading --><h2 class="wp-block-heading" style="margin-top:6rem">Hi</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';

    $result = SectionRhythm::rewrite([[
        'slug' => 'story', 'markup' => $markup, 'density' => 'standard', 'background' => 'base',
    ]]);

    $html = $result['markups'][0];
    assert_contains(
        'style="margin-top:0;padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl);min-height: 40vh"',
        $html,
        'owned declarations rewritten in place; untouched CSS byte-identical'
    );
    assert_true(!str_contains($html, '12rem'), 'no orphaned model spacing anywhere');
    assert_contains('style="margin-top:6rem"', $html, 'inner block styles are not the wrapper\'s');
});

test('section rhythm preserves horizontal shorthand components in block attributes', function () {
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"6rem","bottom":"6rem"},"margin":{"top":"0","bottom":"0"}}}} -->'
        . '<div class="wp-block-group" data-label="A > B" style="padding:6rem 2rem;margin:0 auto;margin-block:3rem;color:red">Content</div><!-- /wp:group -->';

    $result = SectionRhythm::rewrite([[
        'markup' => $markup, 'density' => 'compact', 'background' => 'base',
    ]]);

    // The shorthand spellings are gone only after their horizontal components
    // have moved into the durable attrs that Gutenberg re-serializes.
    assert_contains('style="color:red"', $result['markups'][0]);
    assert_contains('data-label="A > B"', $result['markups'][0], 'a quoted > does not truncate the wrapper tag');
    $attrs = sr_root_attrs($result['markups'][0]);
    assert_eq('2rem', $attrs['style']['spacing']['padding']['right']);
    assert_eq('2rem', $attrs['style']['spacing']['padding']['left']);
    assert_eq('auto', $attrs['style']['spacing']['margin']['right']);
    assert_eq('auto', $attrs['style']['spacing']['margin']['left']);

    $again = SectionRhythm::rewrite([[
        'markup' => $result['markups'][0], 'density' => 'compact', 'background' => 'base',
    ]]);
    assert_eq($result['markups'][0], $again['markups'][0], 'promotion is idempotent before fix-blocks');
    assert_eq([], $again['notes']);
});

test('section rhythm expands box values and honors horizontal CSS cascade priority', function () {
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"6rem","bottom":"6rem"}}}} -->'
        . '<div class="wp-block-group" style="padding:3rem 2rem;'
        . 'padding-right:3rem ! important;padding-right:4rem;'
        . 'margin:1rem 2rem 3rem 4rem;color:red">Content</div><!-- /wp:group -->';

    $result = SectionRhythm::rewrite([[
        'markup' => $markup, 'density' => 'standard', 'background' => 'base',
    ]]);
    $spacing = sr_root_attrs($result['markups'][0])['style']['spacing'];
    assert_eq('3rem !important', $spacing['padding']['right'], 'important longhand beats later normal value');
    assert_eq('2rem', $spacing['padding']['left']);
    assert_eq('2rem', $spacing['margin']['right'], 'four-value shorthand right maps from position two');
    assert_eq('4rem', $spacing['margin']['left'], 'four-value shorthand left maps from position four');
    assert_contains('style="color:red"', $result['markups'][0], 'superseded horizontal longhands are removed');
});

test('section rhythm expands valid attribute shorthands and rejects ambiguous inline sides', function () {
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":"6rem 2rem","margin":"0 auto"}}} -->'
        . '<div class="wp-block-group" style="padding:6rem 2rem;margin:0 auto">Content</div><!-- /wp:group -->';
    $result = SectionRhythm::rewrite([[
        'markup' => $markup, 'density' => 'compact', 'background' => 'base',
    ]]);
    $spacing = sr_root_attrs($result['markups'][0])['style']['spacing'];
    assert_eq('2rem', $spacing['padding']['right']);
    assert_eq('auto', $spacing['margin']['left']);

    $preset = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"6rem","right":"var:preset|spacing|md","bottom":"6rem"}}}} -->'
        . '<div class="wp-block-group" style="padding:6rem var(--wp--preset--spacing--md)">Content</div><!-- /wp:group -->';
    $presetResult = SectionRhythm::rewrite([[
        'markup' => $preset, 'density' => 'compact', 'background' => 'base',
    ]]);
    $presetPadding = sr_root_attrs($presetResult['markups'][0])['style']['spacing']['padding'];
    assert_eq('var:preset|spacing|md', $presetPadding['right'], 'rendered and attribute preset forms compare equally');
    assert_eq('var:preset|spacing|md', $presetPadding['left'], 'promoted preset values use attribute syntax');

    $conflict = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"6rem","right":"3rem","bottom":"6rem","left":"2rem"}}}} -->'
        . '<div class="wp-block-group" style="padding:6rem 2rem">Content</div><!-- /wp:group -->';
    assert_throws(static fn () => SectionRhythm::rewrite([[
        'markup' => $conflict, 'density' => 'compact', 'background' => 'base',
    ]]), 'conflicting inline and attribute padding-right');

    $malformed = '<!-- wp:group --><div class="wp-block-group" style="padding:calc(2rem + 1vw">Content</div><!-- /wp:group -->';
    assert_throws(static fn () => SectionRhythm::rewrite([[
        'markup' => $malformed, 'density' => 'compact', 'background' => 'base',
    ]]), 'unparseable inline padding shorthand');

    $invalidLonghand = '<!-- wp:group --><div class="wp-block-group" style="padding:6rem 2rem;padding-right:bogus">Content</div><!-- /wp:group -->';
    assert_throws(static fn () => SectionRhythm::rewrite([[
        'markup' => $invalidLonghand, 'density' => 'compact', 'background' => 'base',
    ]]), 'invalid later longhand must not replace the effective shorthand side');

    $malformedVarLonghand = '<!-- wp:group --><div class="wp-block-group" style="padding:6rem 2rem;padding-right:var(--space">Content</div><!-- /wp:group -->';
    assert_throws(static fn () => SectionRhythm::rewrite([[
        'markup' => $malformedVarLonghand, 'density' => 'compact', 'background' => 'base',
    ]]), 'a malformed custom-property longhand must not replace the effective shorthand side');

    $ambiguousVar = '<!-- wp:group --><div class="wp-block-group" style="--pad:6rem 2rem;padding:var(--pad)">Content</div><!-- /wp:group -->';
    assert_throws(static fn () => SectionRhythm::rewrite([[
        'markup' => $ambiguousVar, 'density' => 'compact', 'background' => 'base',
    ]]), 'a custom property may expand to more than one shorthand component');

    $unverifiedFunction = '<!-- wp:group --><div class="wp-block-group" style="padding:calc(2rem + 1vw)">Content</div><!-- /wp:group -->';
    assert_throws(static fn () => SectionRhythm::rewrite([[
        'markup' => $unverifiedFunction, 'density' => 'compact', 'background' => 'base',
    ]]), 'freeform functions fail closed without a full CSS value parser');
});

test('section rhythm promotes standalone physical side declarations', function () {
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"6rem","bottom":"6rem"}}}} -->'
        . '<div class="wp-block-group" style="padding-left:var(--custom-space);padding-top:6rem">Content</div><!-- /wp:group -->';
    $result = SectionRhythm::rewrite([[
        'markup' => $markup, 'density' => 'standard', 'background' => 'base',
    ]]);

    $padding = sr_root_attrs($result['markups'][0])['style']['spacing']['padding'];
    assert_eq('var(--custom-space)', $padding['left']);
    assert_contains('padding-left:var(--custom-space)', $result['markups'][0], 'canonical longhands stay idempotent');
});

test('section rhythm keeps overlap utilities off owned wrappers but preserves nested use', function () {
    $nested = '<!-- wp:group {"className":"overlap-up"} -->'
        . '<div class="wp-block-group overlap-up">Nested overlap</div><!-- /wp:group -->';
    $markup = '<!-- wp:group {"className":"overlap-up overlap-upper hover-lift","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group overlap-up overlap-upper hover-lift">' . $nested . '</div><!-- /wp:group -->';

    $result = SectionRhythm::rewrite([[
        'markup' => $markup, 'density' => 'compact', 'background' => 'base',
    ]]);
    $attrs = sr_root_attrs($result['markups'][0]);
    assert_eq('overlap-upper hover-lift', $attrs['className'], 'only the exact forbidden root token is removed');
    assert_contains(
        '<div class="wp-block-group overlap-upper hover-lift">',
        $result['markups'][0],
        'the saved root wrapper class is stripped too'
    );
    assert_contains($nested, $result['markups'][0], 'an inner group remains allowed to overlap');

    $again = SectionRhythm::rewrite([[
        'markup' => $result['markups'][0], 'density' => 'compact', 'background' => 'base',
    ]]);
    assert_eq($result['markups'][0], $again['markups'][0]);
    assert_eq([], $again['notes']);
});

test('section rhythm patches the image cover wrapper style, not just its attributes', function () {
    $cover = '<!-- wp:cover {"align":"full","dimRatio":50,"className":"overlap-up","style":{"spacing":{"padding":{"top":"12rem","bottom":"12rem"}}}} -->'
        . '<div class="wp-block-cover alignfull overlap-up" style="padding:12rem 2rem;margin:0 auto">'
        . '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
        . '<div class="wp-block-cover__inner-container"><p>Image band</p></div></div><!-- /wp:cover -->';
    $markup = sr_section(['align' => 'full', 'layout' => ['type' => 'constrained']], $cover);

    $result = SectionRhythm::rewrite([[
        'slug' => 'hero', 'markup' => $markup, 'density' => 'standard', 'background' => 'image',
    ]]);

    $coverAttrs = sr_first_attrs($result['markups'][0], 'cover');
    assert_eq('2rem', $coverAttrs['style']['spacing']['padding']['right']);
    assert_eq('2rem', $coverAttrs['style']['spacing']['padding']['left']);
    assert_eq('auto', $coverAttrs['style']['spacing']['margin']['right']);
    assert_eq('auto', $coverAttrs['style']['spacing']['margin']['left']);
    assert_true(!array_key_exists('className', $coverAttrs), 'the rhythm-owned cover cannot overlap upward');
    assert_true(!str_contains($result['markups'][0], 'overlap-up'));
    assert_true(!str_contains($result['markups'][0], '12rem'));
});

test('every code-owned hero recipe has a section-rhythm-compatible root skeleton', function () {
    foreach (HeroComposition::RECIPES as $recipe) {
        $projection = HeroComposition::planProjection(HeroBlueprint::defaultFor($recipe));
        $background = $projection['default_background'];
        $inner = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Title</h1><!-- /wp:heading -->';
        if ($background === 'image') {
            $inner = '<!-- wp:cover {"align":"full","dimRatio":50} -->'
                . '<div class="wp-block-cover alignfull"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
                . '<div class="wp-block-cover__inner-container">' . $inner . '</div></div><!-- /wp:cover -->';
        }
        $markup = sr_section([
            'className' => 'hero-composition--' . $recipe,
            'layout' => ['type' => 'constrained'],
        ], $inner);

        $result = SectionRhythm::rewrite([[
            'slug' => 'hero',
            'markup' => $markup,
            'density' => 'standard',
            'background' => $background,
        ]]);

        assert_eq([], $result['degradations'], "{$recipe} skeleton retains its intended rhythm mode");
        assert_eq(
            'hero-composition--' . $recipe,
            sr_root_attrs($result['markups'][0])['className'] ?? null,
        );
        if ($background === 'image') {
            assert_eq('var:preset|spacing|xl', sr_first_attrs($result['markups'][0], 'cover')['style']['spacing']['padding']['top']);
        } else {
            assert_eq('var:preset|spacing|xl', sr_root_attrs($result['markups'][0])['style']['spacing']['padding']['top']);
        }
    }
});
