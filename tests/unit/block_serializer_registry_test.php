<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategy;
use Automattic\SiteBuild\BlockSerializer\Serializer;

test('BlockRegistry keeps registered, supported, and observed sets distinct', function () {
    $registry = new BlockRegistry(
        registered: [
            'core/paragraph' => ['attributes' => ['content' => ['source' => 'rich-text']]],
            'core/query' => ['attributes' => []],
        ],
        supported: ['core/paragraph' => SaveStrategy::STATIC_RENDERER],
        observed: ['core/paragraph', 'vendor/missing'],
    );

    assert_eq(['core/paragraph', 'core/query'], $registry->registeredNames());
    assert_eq(['core/paragraph'], $registry->supportedNames());
    assert_eq(['core/paragraph', 'vendor/missing'], $registry->observedNames());
    assert_eq(SaveStrategy::MISSING_BLOCK, $registry->strategy('vendor/missing'));
});

test('BlockRegistry fails closed for a registered unsupported block', function () {
    $registry = new BlockRegistry(
        registered: ['core/query' => ['attributes' => []]],
        supported: [],
    );
    assert_throws(static fn () => $registry->strategy('core/query'));
});

test('BlockRegistry rejects supported blocks absent from the snapshot', function () {
    assert_throws(static fn () => new BlockRegistry(
        registered: [],
        supported: ['core/paragraph' => SaveStrategy::STATIC_RENDERER],
    ));
});

test('missing-block fallback cannot tunnel a registered unsupported child past the domain guard', function () {
    $input = '<!-- wp:vendor/missing -->'
        . '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->'
        . '<!-- /wp:vendor/missing -->';

    assert_throws(static fn () => (new Serializer())->transform($input));
});

test('registered blocks reject comment keys outside the current schema', function () {
    $legacy = '<!-- wp:paragraph {"customTextColor":"#ff0000"} -->'
        . '<p style="color:#ff0000">Legacy</p><!-- /wp:paragraph -->';

    assert_throws(static fn () => (new Serializer())->transform($legacy));
});

test('registered blocks reject recognizable unreviewed current-key deprecations', function () {
    $paragraph = '<!-- wp:paragraph {"textColor":"base","align":"wide",'
        . '"fontFamily":"body","fontSize":"caption",'
        . '"style":{"typography":{"letterSpacing":"0.12em","textTransform":"uppercase"}}} -->'
        . '<p class="alignwide has-base-color has-text-color has-body-font-family has-caption-font-size" '
        . 'style="letter-spacing:0.12em;text-transform:uppercase;color:var(--wp--preset--color--accent)">'
        . 'Legacy</p><!-- /wp:paragraph -->';
    assert_throws(static fn () => (new Serializer())->transform($paragraph));
});

test('reviewed navigation font-family deprecation supplies pinned defaults', function () {
    $navigation = '<!-- wp:navigation '
        . '{"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} -->'
        . '<!-- wp:page-list /--><!-- /wp:navigation -->';

    $result = (new Serializer())->transform($navigation)->html;
    assert_contains('"overlayMenu":"never"', $result);
    assert_contains('"fontFamily":"heading"', $result);
    assert_contains('"layout":{"type":"flex","orientation":"horizontal"}', $result);
});

test('reviewed site-title deprecations migrate legacy align and font family', function () {
    $align = (new Serializer())->transform(
        '<!-- wp:site-title {"textAlign":"center"} /-->'
    )->html;
    assert_eq(
        '<!-- wp:site-title {"style":{"typography":{"textAlign":"center"}}} /-->',
        $align,
    );

    $font = (new Serializer())->transform(
        '<!-- wp:site-title '
        . '{"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} /-->'
    )->html;
    assert_eq(
        '<!-- wp:site-title {"fontFamily":"heading",'
        . '"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} /-->',
        $font,
    );
});
