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
    // A current-key inline color on a valid <p> root is now the reviewed
    // selector-less carryover (paragraph-inline-color-carryover golden). An
    // invalid paragraph whose root is not a <p> stays outside that reviewed
    // domain — the nested-paragraph merge cannot reconcile it — so its
    // authored inline color must still fail closed.
    $paragraph = '<!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.12em"}}} -->'
        . '<div style="letter-spacing:0.12em;color:var(--wp--preset--color--accent)">'
        . 'Legacy</div><!-- /wp:paragraph -->';
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

test('reviewed legacy button width migrates to current dimensions support', function () {
    $button = '<!-- wp:button {"backgroundColor":"accent","textColor":"base","width":100} -->'
        . '<div class="wp-block-button has-custom-width wp-block-button__width-100">'
        . '<a class="wp-block-button__link has-base-color has-accent-background-color has-text-color '
        . 'has-background wp-element-button" href="#map">Map</a></div><!-- /wp:button -->';

    $result = (new Serializer())->transform($button)->html;
    assert_contains('<!-- wp:button {"backgroundColor":"accent","textColor":"base",'
        . '"style":{"dimensions":{"width":"100%"}}} -->', $result);
    assert_contains('<div class="wp-block-button">', $result);
    assert_true(!str_contains($result, 'has-custom-width'));
    assert_true(!str_contains($result, 'wp-block-button__width-100'));
});

test('reviewed legacy image shadow follows the pinned lossy migration', function () {
    $image = '<!-- wp:image {"sizeSlug":"large","className":"reveal-scale",'
        . '"style":{"border":{"color":"var:preset|color|secondary","width":"1px"}},'
        . '"shadow":"var:preset|shadow|plate"} -->'
        . '<figure class="wp-block-image size-large has-border-color reveal-scale" '
        . 'style="border-color:var(--wp--preset--color--secondary);border-width:1px;'
        . 'box-shadow:var(--wp--preset--shadow--plate)"><img src="photo.jpg" alt="Photo"/></figure>'
        . '<!-- /wp:image -->';

    $result = (new Serializer())->transform($image)->html;
    assert_true(!str_contains($result, '"shadow"'));
    assert_true(!str_contains($result, 'box-shadow'));
    assert_contains('"border":{"color":"var:preset|color|secondary","width":"1px"}', $result);
});

test('reviewed selector-less paragraph carries authored typography past align migration', function () {
    $paragraph = '<!-- wp:paragraph {"align":"center","textColor":"secondary",'
        . '"fontFamily":"heading","fontSize":"caption","className":"reveal-fade"} -->'
        . '<p class="has-text-align-center has-secondary-color has-text-color has-heading-font-family '
        . 'has-caption-font-size reveal-fade" style="letter-spacing:0.2em;text-transform:uppercase">'
        . 'Our Table</p><!-- /wp:paragraph -->';

    $result = (new Serializer())->transform($paragraph)->html;
    assert_contains('"align":"center"', $result);
    assert_contains('style="letter-spacing:0.2em;text-transform:uppercase"', $result);
});

test('reviewed selector-less paragraph matches the pinned inert let-spacing carryover', function () {
    $paragraph = '<!-- wp:paragraph {"align":"center","textColor":"accent","fontSize":"caption",'
        . '"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.18em",'
        . '"fontWeight":"600"}}} -->'
        . '<p class="has-text-align-center has-accent-color has-text-color has-caption-font-size" '
        . 'style="font-weight:600;let-spacing:0.18em;text-transform:uppercase;letter-spacing:0.18em">'
        . 'Visit Us</p><!-- /wp:paragraph -->';

    $result = (new Serializer())->transform($paragraph)->html;
    assert_contains('let-spacing:0.18em', $result);
    assert_contains('letter-spacing:0.18em', $result);
});

test('reviewed paragraph container layout follows the pinned drop', function () {
    $paragraph = '<!-- wp:paragraph {"align":"center","textColor":"base",'
        . '"style":{"spacing":{"margin":{"top":"var:preset|spacing|md"}}},'
        . '"layout":{"type":"constrained","contentSize":"600px"}} -->'
        . '<p class="has-text-align-center has-base-color has-text-color" '
        . 'style="margin-top:var(--wp--preset--spacing--md)">Details</p><!-- /wp:paragraph -->';

    $result = (new Serializer())->transform($paragraph)->html;
    assert_true(!str_contains($result, '"layout"'));
    assert_contains('"margin":{"top":"var:preset|spacing|md"}', $result);
    assert_contains('margin-top:var(--wp--preset--spacing--md)', $result);
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
