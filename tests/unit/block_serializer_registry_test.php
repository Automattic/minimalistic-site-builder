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

test('missing-block fallback preserves a registered unsupported child as the smallest failing unit', function () {
    // Previously the domain guard failed the whole document here. Block-level
    // isolation instead delivers the missing parent's subtree verbatim and
    // reports the preservation, so sibling blocks elsewhere keep their
    // generated classes.
    $input = '<!-- wp:vendor/missing -->'
        . '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->'
        . '<!-- /wp:vendor/missing -->';

    $result = (new Serializer())->transform($input);
    assert_contains('<div class="wp-block-query"></div>', $result->html);
    assert_contains('<!-- /wp:vendor/missing -->', $result->html);
    assert_eq(1, count($result->repairs));
    assert_contains('preserved vendor/missing', $result->repairs[0]->code);
    assert_contains("Registered block 'core/query' is outside the supported PHP domain", $result->repairs[0]->code);
    assert_eq('0', $result->repairs[0]->blockPath);
});

test('registered blocks preserve comment keys outside the current schema verbatim', function () {
    $legacy = '<!-- wp:paragraph {"customTextColor":"#ff0000"} -->'
        . '<p style="color:#ff0000">Legacy</p><!-- /wp:paragraph -->';

    $result = (new Serializer())->transform($legacy);
    assert_contains('"customTextColor":"#ff0000"', $result->html, 'the unreviewed attribute is not re-saved, only preserved');
    assert_contains('<p style="color:#ff0000">Legacy</p>', $result->html);
    assert_eq(1, count($result->repairs));
    assert_contains("preserved core/paragraph (Unsupported comment attribute 'customTextColor'", $result->repairs[0]->code);
    assert_eq('0', $result->repairs[0]->blockPath);
});

test('registered blocks preserve recognizable unreviewed current-key deprecations verbatim', function () {
    // A current-key inline color on a valid <p> root is now the reviewed
    // selector-less carryover (paragraph-inline-color-carryover golden). An
    // invalid paragraph whose root is not a <p> stays outside that reviewed
    // domain — the nested-paragraph merge cannot reconcile it — so its
    // authored bytes are preserved with a report row instead of re-saved.
    $paragraph = '<!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.12em"}}} -->'
        . '<div style="letter-spacing:0.12em;color:var(--wp--preset--color--accent)">'
        . 'Legacy</div><!-- /wp:paragraph -->';
    $result = (new Serializer())->transform($paragraph);
    assert_contains('color:var(--wp--preset--color--accent)', $result->html);
    assert_eq(1, count($result->repairs));
    assert_contains('preserved core/paragraph (Unsupported deprecated core/paragraph style signature', $result->repairs[0]->code);
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

test('reviewed generated support deprecations follow the pinned branch selection', function () {
    $group = '<!-- wp:group {"align":"full","backgroundColor":"contrast","textColor":"base",'
        . '"style":{"elements":{"link":{"color":{"text":"var:preset|color|base"},'
        . '":hover":{"color":{"text":"var:preset|color|accent"}}}}}} -->'
        . '<div class="wp-block-group alignfull has-base-color has-contrast-background-color '
        . 'has-text-color has-background"></div><!-- /wp:group -->';
    $groupResult = (new Serializer())->transform($group)->html;
    assert_contains('"className":"has-base-color has-text-color"', $groupResult);
    assert_contains('has-link-color', $groupResult);

    $separator = '<!-- wp:separator {"backgroundColor":"accent","className":"is-style-wide",'
        . '"style":{"color":{"background":"var:preset|color|accent"}}} -->'
        . '<hr class="wp-block-separator has-text-color has-accent-color has-css-opacity '
        . 'has-accent-background-color has-background is-style-wide" '
        . 'style="background-color:var(--wp--preset--color--accent)"/><!-- /wp:separator -->';
    $separatorResult = (new Serializer())->transform($separator)->html;
    assert_contains('"opacity":"css"', $separatorResult);
    assert_contains('has-css-opacity', $separatorResult);
    assert_true(!str_contains($separatorResult, 'has-alpha-channel-opacity'));

    $alphaSeparator = '<!-- wp:separator {"backgroundColor":"primary",'
        . '"style":{"spacing":{"margin":{"top":"var:preset|spacing|sm"}}}} -->'
        . '<hr class="wp-block-separator has-text-color has-primary-color '
        . 'has-alpha-channel-opacity has-primary-background-color has-background"/>'
        . '<!-- /wp:separator -->';
    $alphaResult = (new Serializer())->transform($alphaSeparator)->html;
    assert_contains('"opacity":"css"', $alphaResult);
    assert_contains('"className":"has-text-color has-primary-color has-alpha-channel-opacity has-primary-background-color has-background"', $alphaResult);

    $width = '<!-- wp:button {"backgroundColor":"accent","textColor":"base","width":100,'
        . '"fontSize":"body"} --><div class="wp-block-button has-custom-width '
        . 'wp-block-button__width-100 has-custom-font-size has-body-font-size">'
        . '<a class="wp-block-button__link has-base-color has-accent-background-color has-text-color '
        . 'has-background wp-element-button" href="#tickets">Tickets</a></div><!-- /wp:button -->';
    $widthResult = (new Serializer())->transform($width)->html;
    assert_contains('"fontSize":"body","style":{"dimensions":{"width":"100%"}}', $widthResult);
    assert_true(!str_contains($widthResult, '"className"'));
    assert_contains('has-body-font-size has-custom-font-size wp-element-button', $widthResult);

    $olderWidth = '<!-- wp:button {"textColor":"secondary","width":100,"fontSize":"body",'
        . '"style":{"color":{"background":"transparent"}}} -->'
        . '<div class="wp-block-button has-custom-width wp-block-button__width-100 '
        . 'has-custom-font-size has-body-font-size"><a class="wp-block-button__link '
        . 'has-secondary-color has-text-color wp-element-button" href="#groups">Groups</a></div>'
        . '<!-- /wp:button -->';
    $olderWidthResult = (new Serializer())->transform($olderWidth)->html;
    assert_contains('"className":"has-custom-width wp-block-button__width-100 has-custom-font-size has-body-font-size"', $olderWidthResult);
    assert_true(!str_contains($olderWidthResult, '"dimensions"'));

    $paragraph = '<!-- wp:paragraph {"textColor":"accent","fontFamily":"heading",'
        . '"fontSize":"caption","style":{"border":{"radius":"999px","width":"1px",'
        . '"color":"#2DE2E6"}}} --><p class="has-accent-color has-text-color '
        . 'has-heading-font-family has-caption-font-size" style="border-color:#2DE2E6;'
        . 'border-width:1px;border-radius:999px">Ages 7–10</p><!-- /wp:paragraph -->';
    $paragraphResult = (new Serializer())->transform($paragraph)->html;
    assert_contains('"className":"has-accent-color has-text-color has-heading-font-family"', $paragraphResult);
    assert_contains('has-border-color has-caption-font-size', $paragraphResult);

    $lineHeight = '<!-- wp:paragraph {"textColor":"base","fontFamily":"body",'
        . '"fontSize":"body","style":{"typography":{"lineHeight":"1.6"}}} -->'
        . '<p class="has-base-color has-text-color has-body-font-family has-body-font-size">Open</p>'
        . '<!-- /wp:paragraph -->';
    $lineHeightResult = (new Serializer())->transform($lineHeight)->html;
    assert_contains('"className":"has-base-color has-text-color has-body-font-family has-body-font-size"', $lineHeightResult);
    assert_contains('style="line-height:1.6"', $lineHeightResult);

    $image = '<!-- wp:image {"sizeSlug":"large","style":{"border":{"radius":"24px"}}} -->'
        . '<figure class="wp-block-image size-large has-custom-border"><img src="photo.jpg" '
        . 'alt="Photo"/></figure><!-- /wp:image -->';
    $imageResult = (new Serializer())->transform($image)->html;
    assert_contains('"className":"has-custom-border"', $imageResult);
});

test('reviewed button href alias is sourced from saved HTML then dropped', function () {
    $button = '<!-- wp:button {"href":"#contact"} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" '
        . 'href="#contact">Start an inquiry</a></div><!-- /wp:button -->';

    $result = (new Serializer())->transform($button)->html;
    assert_true(!str_contains($result, '"href"'));
    assert_true(!str_contains($result, '"url"'));
    assert_contains('href="#contact"', $result);
});

test('reviewed legacy button textAlign follows the pinned drop', function () {
    $button = '<!-- wp:button {"textAlign":"center","backgroundColor":"accent",'
        . '"textColor":"base","style":{"border":{"radius":"2px"}}} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link has-base-color '
        . 'has-accent-background-color has-text-color has-background has-text-align-center '
        . 'wp-element-button" href="#contact" style="border-radius:2px">Contact</a></div>'
        . '<!-- /wp:button -->';

    $result = (new Serializer())->transform($button)->html;
    assert_true(!str_contains($result, '"textAlign"'));
    assert_true(!str_contains($result, 'has-text-align-center'));
    assert_contains('href="#contact"', $result);
    // An unreviewed variant stays outside the pinned drop: the block is
    // preserved verbatim with a report row instead of failing the document.
    $unreviewed = (new Serializer())->transform(str_replace(
        '"textAlign":"center"',
        '"textAlign":"justify"',
        $button,
    ));
    assert_contains('"textAlign":"justify"', $unreviewed->html);
    assert_eq(1, count($unreviewed->repairs));
    assert_contains('preserved core/button', $unreviewed->repairs[0]->code);
});

test('legacy heading textAlign safely folds beside authored typography', function () {
    $input = '<!-- wp:heading {"textAlign":"center","level":2,'
        . '"style":{"typography":{"lineHeight":"1.1"}}} -->'
        . '<h2 class="wp-block-heading has-text-align-center" style="line-height:1.1">'
        . 'Centered title</h2><!-- /wp:heading -->';

    $serializer = new Serializer();
    $result = $serializer->transform($input)->html;

    assert_eq(
        '<!-- wp:heading {"style":{"typography":{"lineHeight":"1.1",'
            . '"textAlign":"center"}}} -->' . "\n"
            . '<h2 class="wp-block-heading has-text-align-center" style="line-height:1.1">'
            . 'Centered title</h2>' . "\n"
            . '<!-- /wp:heading -->',
        $result,
    );
    assert_eq($result, $serializer->transform($result)->html);
});

test('registered align migration wins over a conflicting legacy textAlign', function () {
    $input = '<!-- wp:heading {"align":"right","textAlign":"center","level":2} -->'
        . '<h2 class="wp-block-heading has-text-align-right">Right title</h2>'
        . '<!-- /wp:heading -->';

    $serializer = new Serializer();
    $result = $serializer->transform($input)->html;

    assert_eq(
        '<!-- wp:heading {"align":"right","style":{"typography":{'
            . '"textAlign":"right"}}} -->' . "\n"
            . '<h2 class="wp-block-heading has-text-align-right">Right title</h2>' . "\n"
            . '<!-- /wp:heading -->',
        $result,
    );
    assert_true(!str_contains($result, 'has-text-align-center'));
    assert_eq($result, $serializer->transform($result)->html);
});

test('authored alignment class wins without a conflicting folded class', function () {
    $input = '<!-- wp:heading {"textAlign":"center","level":2,'
        . '"className":"hero-title has-text-align-right"} -->'
        . '<h2 class="wp-block-heading hero-title has-text-align-right">Right title</h2>'
        . '<!-- /wp:heading -->';

    $serializer = new Serializer();
    $result = $serializer->transform($input)->html;

    assert_eq(
        '<!-- wp:heading {"className":"hero-title has-text-align-right"} -->' . "\n"
            . '<h2 class="wp-block-heading hero-title has-text-align-right">Right title</h2>' . "\n"
            . '<!-- /wp:heading -->',
        $result,
    );
    assert_true(!str_contains($result, 'has-text-align-center'));
    assert_eq($result, $serializer->transform($result)->html);
});

test('legacy textAlign does not overwrite malformed authored containers', function () {
    // Fail closed is per block: the malformed container is delivered verbatim
    // with its reason on the preservation row, rather than being canonicalized
    // over or taking every sibling in the file down with it.
    $cases = [
        [
            '<!-- wp:heading {"textAlign":"center","style":"keep-style"} -->'
                . '<h2 class="wp-block-heading has-text-align-center">Title</h2>'
                . '<!-- /wp:heading -->',
            'authored style "keep-style" is not an object',
            'preserved core/heading',
            '"style":"keep-style"',
        ],
        [
            '<!-- wp:paragraph {"textAlign":"center",'
                . '"style":{"typography":"keep-typography"}} -->'
                . '<p class="has-text-align-center">Copy</p><!-- /wp:paragraph -->',
            'authored style.typography "keep-typography" is not an object',
            'preserved core/paragraph',
            '"style":{"typography":"keep-typography"}',
        ],
    ];

    foreach ($cases as [$input, $message, $preserved, $container]) {
        $result = (new Serializer())->transform($input);
        assert_contains($container, $result->html, 'the authored container is never overwritten');
        assert_eq(1, count($result->repairs), 'exactly one preserved block');
        assert_contains($preserved, $result->repairs[0]->code);
        assert_contains('Cannot canonicalize legacy textAlign', $result->repairs[0]->code);
        assert_contains($message, $result->repairs[0]->code);
    }
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

test('reviewed selector-less paragraph carries contrast-repair inline color past align migration', function () {
    $paragraph = '<!-- wp:paragraph {"align":"center","fontFamily":"body",'
        . '"fontSize":"caption","style":{"typography":{"fontStyle":"italic",'
        . '"fontWeight":"400"}},"textColor":"contrast"} -->' . "\n"
        . '<p class="has-text-align-center has-body-font-family has-caption-font-size" '
        . 'style="color:#7C7B54;font-style:italic;font-weight:400">Our philosophy</p>' . "\n"
        . '<!-- /wp:paragraph -->';

    $serializer = new Serializer();
    $result = $serializer->transform($paragraph)->html;
    assert_eq(
        '<!-- wp:paragraph {"textColor":"contrast","align":"center",'
            . '"fontFamily":"body","fontSize":"caption","style":{"typography":{'
            . '"fontStyle":"italic","fontWeight":"400"}}} -->' . "\n"
            . '<p class="has-contrast-color has-text-color has-body-font-family '
            . 'has-caption-font-size has-text-align-center" '
            . 'style="font-style:italic;font-weight:400;color:#7C7B54">Our philosophy</p>' . "\n"
            . '<!-- /wp:paragraph -->',
        $result,
    );
    assert_eq($result, $serializer->transform($result)->html);
});

test('reviewed inert paragraph fontStyleNormal state is retained without rendering CSS', function () {
    $paragraph = '<!-- wp:paragraph {"fontSize":"caption","textColor":"secondary",'
        . '"fontFamily":"body","style":{"typography":{"fontStyle":"italic",'
        . '"fontStyleNormal":false}}} -->' . "\n"
        . '<p class="has-secondary-color has-text-color has-body-font-family '
        . 'has-caption-font-size" style="font-style:italic">Closed</p>' . "\n"
        . '<!-- /wp:paragraph -->';

    $serializer = new Serializer();
    $result = $serializer->transform($paragraph)->html;
    assert_eq(
        '<!-- wp:paragraph {"textColor":"secondary","fontFamily":"body",'
            . '"fontSize":"caption","style":{"typography":{"fontStyle":"italic",'
            . '"fontStyleNormal":false}}} -->' . "\n"
            . '<p class="has-secondary-color has-text-color has-body-font-family '
            . 'has-caption-font-size" style="font-style:italic">Closed</p>' . "\n"
            . '<!-- /wp:paragraph -->',
        $result,
    );
    assert_eq($result, $serializer->transform($result)->html);
    assert_true(!str_contains($result, 'font-style-normal'));
});

test('reviewed inert paragraph caption element state preserves authored italic style', function () {
    $paragraph = '<!-- wp:paragraph {"fontSize":"caption","style":{"elements":{'
        . '"caption":{"typography":{"fontStyle":"italic"}}}},"textColor":"base"} -->' . "\n"
        . '<p class="has-caption-font-size" style="font-style:italic;color:#6E6552">'
        . 'Plaza de Mayo at nightfall, Buenos Aires — 2008.</p>' . "\n"
        . '<!-- /wp:paragraph -->';

    $serializer = new Serializer();
    $result = $serializer->transform($paragraph)->html;
    assert_eq(
        '<!-- wp:paragraph {"textColor":"base","fontSize":"caption","style":{"elements":{'
            . '"caption":{"typography":{"fontStyle":"italic"}}}}} -->' . "\n"
            . '<p class="has-base-color has-text-color has-caption-font-size" '
            . 'style="font-style:italic;color:#6E6552">'
            . 'Plaza de Mayo at nightfall, Buenos Aires — 2008.</p>' . "\n"
            . '<!-- /wp:paragraph -->',
        $result,
    );
    assert_eq($result, $serializer->transform($result)->html);
});

test('paragraph element-link deprecation still recovers the pinned link class', function () {
    $paragraph = '<!-- wp:paragraph {"fontSize":"caption","style":{"elements":{'
        . '"link":{"color":{"text":"var:preset|color|base"}}}}} -->' . "\n"
        . '<p class="has-caption-font-size"><a href="#">Link</a></p>' . "\n"
        . '<!-- /wp:paragraph -->';

    $serializer = new Serializer();
    $result = $serializer->transform($paragraph)->html;
    assert_eq(
        '<!-- wp:paragraph {"fontSize":"caption","style":{"elements":{'
            . '"link":{"color":{"text":"var:preset|color|base"}}}}} -->' . "\n"
            . '<p class="has-link-color has-caption-font-size"><a href="#">Link</a></p>' . "\n"
            . '<!-- /wp:paragraph -->',
        $result,
    );
    assert_eq($result, $serializer->transform($result)->html);
});

test('reviewed inert navigation style fontSize remains comment state', function () {
    $navigation = '<!-- wp:navigation {"textColor":"primary","overlayMenu":"never",'
        . '"layout":{"type":"flex","justifyContent":"center","flexWrap":"wrap"},'
        . '"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.08em"},'
        . '"spacing":{"blockGap":"var:preset|spacing|md"},"fontSize":"caption"}} -->'
        . '<!-- wp:navigation-link {"label":"Statement","url":"#artist-statement",'
        . '"kind":"custom"} /--><!-- /wp:navigation -->';

    $serializer = new Serializer();
    $result = $serializer->transform($navigation)->html;
    assert_eq(
        '<!-- wp:navigation {"textColor":"primary","overlayMenu":"never",'
            . '"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.08em"},'
            . '"spacing":{"blockGap":"var:preset|spacing|md"},"fontSize":"caption"},'
            . '"layout":{"type":"flex","justifyContent":"center","flexWrap":"wrap"}} -->' . "\n"
            . '<!-- wp:navigation-link {"label":"Statement","url":"#artist-statement",'
            . '"kind":"custom"} /-->' . "\n"
            . '<!-- /wp:navigation -->',
        $result,
    );
    assert_eq($result, $serializer->transform($result)->html);
    assert_true(!str_contains($result, '"fontSize":"caption","layout"'));
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

test('reviewed site-tagline deprecations migrate legacy align and font family', function () {
    $align = (new Serializer())->transform(
        '<!-- wp:site-tagline {"textAlign":"center"} /-->'
    )->html;
    assert_eq(
        '<!-- wp:site-tagline {"style":{"typography":{"textAlign":"center"}}} /-->',
        $align,
    );

    $font = (new Serializer())->transform(
        '<!-- wp:site-tagline '
        . '{"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} /-->'
    )->html;
    assert_eq(
        '<!-- wp:site-tagline {"fontFamily":"heading",'
        . '"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} /-->',
        $font,
    );
});

test('reviewed site identity deprecation candidates compose before raw style overlay', function () {
    foreach (['site-title', 'site-tagline'] as $block) {
        $result = (new Serializer())->transform(
            '<!-- wp:' . $block . ' {"textAlign":"center",'
            . '"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} /-->'
        )->html;
        assert_eq(
            '<!-- wp:' . $block . ' {"fontFamily":"heading",'
            . '"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} /-->',
            $result,
        );
    }
});
