<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategy;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;
use Automattic\SiteBuild\BlockSerializer\Serializer;

test('SaveStrategyRegistry handles dynamic, inner, conditional, raw and missing explicitly', function () {
    $registered = [
        'core/post-content' => ['attributes' => [], 'supports' => []],
        'core/navigation-link' => ['attributes' => [], 'supports' => []],
        'core/navigation' => ['attributes' => [], 'supports' => []],
        'core/html' => ['attributes' => [], 'supports' => []],
    ];
    $strategies = [
        'core/post-content' => SaveStrategy::DYNAMIC_NULL,
        'core/navigation-link' => SaveStrategy::INNER_BLOCKS,
        'core/navigation' => SaveStrategy::CONDITIONAL,
        'core/html' => SaveStrategy::RAW_CONTENT,
    ];
    $saves = new SaveStrategyRegistry(new BlockRegistry($registered, $strategies));
    assert_eq('', $saves->save('core/post-content', [], '<p>ignored</p>'));
    assert_eq('<i>inner</i>', $saves->save('core/navigation-link', [], '<i>inner</i>'));
    assert_eq('<i>inner</i>', $saves->save('core/navigation', [], '<i>inner</i>'));
    assert_eq('', $saves->save('core/navigation', ['ref' => 12], '<i>inner</i>'));
    assert_eq('<b>raw</b>', $saves->save('core/html', ['content' => '<b>raw</b>'], ''));
    assert_eq('<u>unknown</u>', $saves->save('vendor/unknown', [], '', '<u>unknown</u>'));
});

test('Core static renderer produces a nested group support slice', function () {
    $registered = [
        'core/group' => ['attributes' => [], 'supports' => [
            'className' => true,
            'spacing' => ['padding' => true],
            'color' => ['background' => true, 'text' => true],
        ]],
    ];
    $saves = new SaveStrategyRegistry(new BlockRegistry(
        $registered,
        ['core/group' => SaveStrategy::STATIC_RENDERER],
    ));
    assert_eq(
        '<section class="wp-block-group featured has-base-background-color has-background" style="padding-top:2rem"><p>inner</p></section>',
        $saves->save('core/group', [
            'tagName' => 'section', 'className' => 'featured', 'backgroundColor' => 'base',
            'style' => ['spacing' => ['padding' => ['top' => '2rem']]],
        ], '<p>inner</p>')
    );
});

test('heading seeds its generated class before support styles', function () {
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_eq(
        '<h1 class="wp-block-heading has-heading-font-family has-display-font-size" '
            . 'style="margin-bottom:var(--wp--preset--spacing--md)">Title</h1>',
        $saves->save('core/heading', [
            'content' => 'Title',
            'level' => 1,
            'fontFamily' => 'heading',
            'fontSize' => 'display',
            'style' => ['spacing' => ['margin' => ['bottom' => 'var:preset|spacing|md']]],
        ], ''),
    );
});

test('cover numeric zero dim omits the image-gradient marker', function () {
    $html = (new SaveStrategyRegistry(new BlockRegistry()))->save('core/cover', [
        'url' => '/cover.jpg',
        'dimRatio' => 0.0,
        'customGradient' => 'linear-gradient(red,blue)',
    ], '<p>Inner</p>');
    assert_true(!str_contains($html, 'wp-block-cover__gradient-background'));
});

test('paragraph deprecation recreation preserves literal NBSP bytes', function () {
    $nbsp = "\u{00A0}";
    $input = '<!-- wp:paragraph {"textColor":"secondary","align":"center",'
        . '"className":"has-secondary-color has-text-color has-body-font-family",'
        . '"fontFamily":"body","fontSize":"caption",'
        . '"style":{"typography":{"textAlign":"center"}}} -->' . "\n"
        . '<p class="has-text-align-center has-secondary-color has-text-color '
        . 'has-body-font-family has-caption-font-size">'
        . "Studio: A{$nbsp}·{$nbsp}B</p>\n"
        . '<!-- /wp:paragraph -->';

    assert_eq($input, (new Serializer())->transform($input)->html);
});
