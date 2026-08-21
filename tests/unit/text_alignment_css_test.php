<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\RootTextAlignmentConflictDetector;
use Automattic\SiteBuild\BlockSerializer\TextAlignmentCss;

test('inline text alignment ignores declarations inside nested rule-shaped bytes', function () {
    $rootForStyle = static function (string $style) {
        $children = HtmlFragment::parse('<h2 style="' . $style . '">Title</h2>')
            ->root()
            ->children();
        return $children[0];
    };

    $effective = TextAlignmentCss::effectiveInline($rootForStyle(
        'text-align:center;.nested{text-align:right!important}',
    ));
    assert_eq('center', $effective['value'] ?? null);
    assert_true(!($effective['important'] ?? true));
    assert_eq(
        null,
        TextAlignmentCss::effectiveInline($rootForStyle('.nested{text-align:right}')),
        'a nested rule has no declaration in the element inline cascade',
    );
});

test('root alignment preflight uses the direct declaration rather than a nested rule', function () {
    $markup = '<!-- wp:heading {"style":{"typography":{"textAlign":"right"}}} -->'
        . '<h2 style="text-align:center;.nested{text-align:right}">Title</h2>'
        . '<!-- /wp:heading -->';

    $conflicts = (new RootTextAlignmentConflictDetector())->detect($markup);
    assert_eq(1, count($conflicts));
    assert_contains('inline text-align:center', $conflicts[0]);
});

test('inline text alignment keeps comments as token-separating trivia', function () {
    $rootForStyle = static function (string $style) {
        $children = HtmlFragment::parse('<h2 style="' . $style . '">Title</h2>')
            ->root()
            ->children();
        return $children[0];
    };

    $valid = TextAlignmentCss::effectiveInline($rootForStyle(
        '/**/text-align/**/:/**/right/**/!/**/important',
    ));
    assert_eq('right', $valid['value'] ?? null);
    assert_true($valid['safe'] ?? false);
    assert_true($valid['important'] ?? false);

    foreach ([
        'text-/**/align:right',
        'text-align:ri/**/ght',
        'text-align:\\72/**/ight',
        'text-align:var/**/(--align)',
        'text-align:right!im/**/portant',
    ] as $style) {
        $opaque = TextAlignmentCss::effectiveInline($rootForStyle($style));
        assert_true($opaque !== null, "{$style} remains visible to the preflight");
        assert_true(!$opaque['safe'], "{$style} cannot concatenate across a comment");
    }

    $functionTrivia = TextAlignmentCss::effectiveInline($rootForStyle(
        'text-align:var(/**/--align/**/)',
    ));
    assert_eq('var(--align)', $functionTrivia['value'] ?? null);
});

test('root alignment preflight fails closed for comments that split property or value tokens', function () {
    $detector = new RootTextAlignmentConflictDetector();
    $opening = '<!-- wp:heading {"style":{"typography":{"textAlign":"right"}}} -->';
    $closing = 'Title</h2><!-- /wp:heading -->';

    assert_eq([], $detector->detect(
        $opening . '<h2 style="/**/text-align/**/:/**/right/**/">' . $closing,
    ), 'comments at token boundaries are ordinary CSS trivia');

    foreach (['text-/**/align:right', 'text-align:ri/**/ght'] as $style) {
        $conflicts = $detector->detect(
            $opening . '<h2 style="' . $style . '">' . $closing,
        );
        assert_eq(1, count($conflicts), "{$style} cannot silently become text-align:right");
        assert_contains('root text-alignment signals conflict', $conflicts[0]);
    }

    $priorityCascade = '<!-- wp:heading {"style":{"typography":{'
        . '"textAlign":"right"}}} --><h2 class="has-text-align-right" '
        . 'style="text-align:right!im/**/portant;text-align:center">Title</h2>'
        . '<!-- /wp:heading -->';
    assert_eq(
        1,
        count($detector->detect($priorityCascade)),
        'a split priority identifier cannot turn an invalid declaration into the winning !important value',
    );

    $paragraphFunctionTrivia = '<!-- wp:paragraph {"style":{"typography":{'
        . '"textAlign":"var(--align)"}}} --><p '
        . 'style="text-align:var(/**/--align/**/)">Copy</p><!-- /wp:paragraph -->';
    assert_eq(
        [],
        $detector->detect($paragraphFunctionTrivia),
        'comments beside function punctuation are removable token-safe trivia',
    );
});

test('paragraph style projection compares the authored CSS cascade with the exact fixer map', function () {
    foreach ([
        'text-align:right!important;text-align:center',
        'TEXT-ALIGN:right!important;TEXT-ALIGN:center',
        'text\\2d align:right!important;text\\2d align:center',
        ' text-align :right!important;text-align:center',
        'all:unset!important;all:initial',
        'all:unset;text-align:right;all:initial',
        'text-align:right;all:unset;text-align:center',
        'text-align:right!important;color:red;text-align:right',
        'text-align:right;text-align:banana',
        'text-align:center;text-align:ri/**/ght',
        'direction:rtl!important;text-align:start;direction:ltr',
        'writing-mode:vertical-rl!important;text-align:start;writing-mode:horizontal-tb',
        '--a:right!important;text-align:var(--a);--a:left',
        '--a:right!important;--b:var(--a);text-align:var(--b);--a:left',
        '--a:start;text-align:var(--a);direction:rtl!important;direction:ltr',
        'text-align:env(--alignment);direction:rtl!important;direction:ltr',
    ] as $style) {
        $projection = TextAlignmentCss::paragraphProjection($style);
        assert_true(!$projection['preserves'], "{$style} cannot be projected losslessly");
    }

    foreach ([
        'text-align:right;text-align:center',
        'text-align:right;color:red;text-align:right',
        'text-align:right;text-align:var(--missing)',
        'text-align:ri/**/ght;text-align:center',
        'text-/**/align:right;text-align:center',
        'text-align:right!im/**/portant;text-align:center',
        'text-align:right;TEXT-ALIGN:banana',
        'direction:rtl!important;text-align:center;direction:ltr',
        '--a:right!important;text-align:center;--a:left',
    ] as $style) {
        $projection = TextAlignmentCss::paragraphProjection($style);
        assert_true($projection['preserves'], "{$style} keeps the effective alignment");
    }
});
