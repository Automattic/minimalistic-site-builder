<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\AlignmentClassLoss;
use Automattic\SiteBuild\BlockSerializer\AlignmentClassLossDetector;

/**
 * @param list<AlignmentClassLoss> $losses
 * @return list<array{path:string,block:string,authored:string,delivered:list<string>}>
 */
function alignment_class_loss_rows(array $losses): array
{
    return array_map(
        static fn (AlignmentClassLoss $loss): array => [
            'path' => $loss->blockPath,
            'block' => $loss->blockName,
            'authored' => $loss->authoredClass,
            'delivered' => $loss->deliveredClasses,
        ],
        $losses,
    );
}

test('alignment loss in one block is not hidden by the same class gained in another', function () {
    $authored = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center">Loses</h2>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Gains</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2 class="wp-block-heading">Loses</h2><!-- /wp:heading -->'
        . '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center">Gains</h2>'
        . '<!-- /wp:heading -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('collapsing a duplicate alignment token is not a semantic loss', function () {
    $authored = '<!-- wp:heading -->'
        . '<h2 class="wp-block-heading has-text-align-center has-text-align-center">Title</h2>'
        . '<!-- /wp:heading -->';
    $delivered = '<!-- wp:heading -->'
        . '<h2 class="wp-block-heading has-text-align-center">Title</h2>'
        . '<!-- /wp:heading -->';

    assert_eq([], (new AlignmentClassLossDetector())->detect($authored, $delivered));
});

test('a replacement alignment class is retained as the delivered value', function () {
    $authored = '<!-- wp:heading -->'
        . '<h2 class="wp-block-heading has-text-align-center">Title</h2>'
        . '<!-- /wp:heading -->';
    $delivered = '<!-- wp:heading -->'
        . '<h2 class="wp-block-heading has-text-align-right">Title</h2>'
        . '<!-- /wp:heading -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => ['has-text-align-right'],
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('the same alignment class lost by two nested blocks retains both block paths', function () {
    $authored = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center">One</h2>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center">Two</h2>'
        . '<!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $delivered = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">One</h2><!-- /wp:heading -->'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Two</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';

    assert_eq([
        [
            'path' => '0/0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
        ],
        [
            'path' => '0/1',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('vertical container and item alignment losses are both detected', function () {
    $authored = '<!-- wp:columns -->'
        . '<div class="wp-block-columns are-vertically-aligned-center">'
        . '<!-- wp:column -->'
        . '<div class="wp-block-column is-vertically-aligned-bottom"></div>'
        . '<!-- /wp:column -->'
        . '</div><!-- /wp:columns -->';
    $delivered = '<!-- wp:columns -->'
        . '<div class="wp-block-columns">'
        . '<!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/columns',
            'authored' => 'are-vertically-aligned-center',
            'delivered' => [],
        ],
        [
            'path' => '0/0',
            'block' => 'core/column',
            'authored' => 'is-vertically-aligned-bottom',
            'delivered' => [],
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('alignment-like class names outside the reviewed support tokens are ignored', function () {
    $authored = '<!-- wp:group -->'
        . '<div class="wp-block-group has-text-align-center has-text-align-justify alignwide alignwider '
        . 'alignment-helper are-vertically-aligned-around are-vertically-aligned-stretch '
        . 'is-vertically-aligned-middle"></div>'
        . '<!-- /wp:group -->';
    $delivered = '<!-- wp:group -->'
        . '<div class="wp-block-group has-text-align-center alignwide"></div>'
        . '<!-- /wp:group -->';

    assert_eq([], (new AlignmentClassLossDetector())->detect($authored, $delivered));
});

test('alignment on an owned descendant stays attributable to its block', function () {
    $authored = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link has-text-align-center">Open</a></div><!-- /wp:button -->';
    $delivered = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link">Open</a></div><!-- /wp:button -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/button',
            'authored' => 'has-text-align-center',
            'delivered' => [],
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('a changed block tree is not cross-matched by path', function () {
    $authored = '<!-- wp:heading --><h2 class="has-text-align-center">Original</h2>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:heading --><h2>Sibling</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2>Inserted</h2><!-- /wp:heading -->'
        . '<!-- wp:heading --><h2 class="has-text-align-center">Original</h2><!-- /wp:heading -->'
        . '<!-- wp:heading --><h2>Sibling</h2><!-- /wp:heading -->';

    assert_eq([], (new AlignmentClassLossDetector())->detect($authored, $delivered));
});

test('dynamic void output does not turn a carried class into a false loss', function () {
    $authored = '<!-- wp:site-title -->'
        . '<h1 class="wp-block-site-title has-text-align-center">Site</h1>'
        . '<!-- /wp:site-title -->';
    $delivered = '<!-- wp:site-title {"className":"wp-block-site-title has-text-align-center"} /-->';

    assert_eq([], (new AlignmentClassLossDetector())->detect($authored, $delivered));
});
