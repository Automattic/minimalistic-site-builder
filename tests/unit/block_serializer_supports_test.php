<?php
declare(strict_types=1);

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
            'flexWrap' => 'wrap',
        ],
    ], '0');

    assert_throws(static fn () => $guard->assertSupported(
        'core/group',
        ['style' => ['position' => ['type' => 'sticky']]],
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
});
