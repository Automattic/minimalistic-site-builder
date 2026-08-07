<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\AlignmentClassLoss;
use Automattic\SiteBuild\BlockSerializer\AlignmentClassLossDetector;

/**
 * @param list<AlignmentClassLoss> $losses
 * @return list<array{
 *     path:string,
 *     block:string,
 *     authored:string,
 *     delivered:list<string>,
 *     authoredClassOnSavedRoot:bool,
 *     authoredClassIsSafeRootTextAlignment:bool
 * }>
 */
function alignment_class_loss_rows(array $losses): array
{
    return array_map(
        static fn (AlignmentClassLoss $loss): array => [
            'path' => $loss->blockPath,
            'block' => $loss->blockName,
            'authored' => $loss->authoredClass,
            'delivered' => $loss->deliveredClasses,
            'authoredClassOnSavedRoot' => $loss->authoredClassOnSavedRoot,
            'authoredClassIsSafeRootTextAlignment' => $loss->authoredClassIsSafeRootTextAlignment,
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
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => true,
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
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => true,
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
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => true,
        ],
        [
            'path' => '0/1',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => true,
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
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
        [
            'path' => '0/0',
            'block' => 'core/column',
            'authored' => 'is-vertically-aligned-bottom',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => false,
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
            'authoredClassOnSavedRoot' => false,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('a surviving duplicate class on another descendant does not hide one element loss', function () {
    $authored = '<!-- wp:table --><figure><table><tbody><tr>'
        . '<td class="has-text-align-center">A</td>'
        . '<td class="has-text-align-center">B</td>'
        . '</tr></tbody></table></figure><!-- /wp:table -->';
    $delivered = '<!-- wp:table --><figure><table><tbody><tr>'
        . '<td>A</td><td class="has-text-align-center">B</td>'
        . '</tr></tbody></table></figure><!-- /wp:table -->';

    $losses = (new AlignmentClassLossDetector())->detect($authored, $delivered);
    assert_eq(1, count($losses));
    assert_eq('has-text-align-center', $losses[0]->authoredClass);
    assert_eq('0/0/0/0/0', $losses[0]->authoredElementPath);
    assert_true(!$losses[0]->authoredClassOnSavedRoot);
    assert_true(!$losses[0]->authoredClassIsSafeRootTextAlignment);
});

test('a valid reading-copy descendant is not marked as a safe root alignment', function () {
    $authored = '<!-- wp:paragraph --><p>Before '
        . '<span class="has-text-align-right">Child</span> After</p><!-- /wp:paragraph -->';
    $delivered = '<!-- wp:paragraph --><p>Before <span>Child</span> After</p><!-- /wp:paragraph -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/paragraph',
            'authored' => 'has-text-align-right',
            'delivered' => [],
            'authoredClassOnSavedRoot' => false,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('an identical descendant class does not hide loss of the saved-root class', function () {
    $authored = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center">'
        . 'Root <span class="has-text-align-center">child</span></h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2 class="wp-block-heading">'
        . 'Root <span class="has-text-align-center">child</span></h2><!-- /wp:heading -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => true,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('adding the same class to the root does not hide its surviving descendant occurrence', function () {
    $authored = '<!-- wp:heading --><h2 class="wp-block-heading">'
        . 'Root <span class="has-text-align-center">child</span></h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center">'
        . 'Root <span class="has-text-align-center">child</span></h2><!-- /wp:heading -->';

    assert_eq([], (new AlignmentClassLossDetector())->detect($authored, $delivered));
});

test('a root text-alignment class conflicting with inline CSS is not safe to repair', function () {
    $authored = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:right">Title</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('an inert sibling comment keeps sole-root provenance while all reset blocks repair', function () {
    $authored = '<!-- wp:heading --><!-- note --><h2 class="wp-block-heading '
        . 'has-text-align-center" style="all:unset">Title</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><!-- note --><h2 class="wp-block-heading">Title</h2>'
        . '<!-- /wp:heading -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('a removed root class is harmless when the same inline alignment still wins', function () {
    $authored = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:right">Title</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2 class="wp-block-heading" '
        . 'style="text-align:right">Title</h2><!-- /wp:heading -->';

    assert_eq([], (new AlignmentClassLossDetector())->detect($authored, $delivered));
});

test('changing inline priority does not suppress a removed root class warning', function () {
    $authored = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:center!important">Title</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2 class="wp-block-heading" '
        . 'style="text-align:center">Title</h2><!-- /wp:heading -->';

    $losses = (new AlignmentClassLossDetector())->detect($authored, $delivered);
    assert_eq(1, count($losses));
    assert_eq('has-text-align-center', $losses[0]->authoredClass);
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

test('a reordered same-name sibling loss is warned without becoming repair eligible', function () {
    $authored = '<!-- wp:heading --><h2 class="has-text-align-center">Alpha</h2>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:heading --><h2>Beta</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2>Beta</h2><!-- /wp:heading -->'
        . '<!-- wp:heading --><h2>Alpha</h2><!-- /wp:heading -->';

    $losses = (new AlignmentClassLossDetector())->detect($authored, $delivered);
    assert_eq([
        [
            'path' => '0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
    ], alignment_class_loss_rows($losses));
    assert_eq('1', $losses[0]->deliveredBlockPath, 'the warning can locate moved final content');
});

test('changed non-alignment comment metadata invalidates repair attribution', function () {
    $authored = '<!-- wp:heading {"metadata":{"name":"Authored"}} -->'
        . '<h2 class="has-text-align-center">Same</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading {"metadata":{"name":"Replacement"}} -->'
        . '<h2>Same</h2><!-- /wp:heading -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('uniquely identifiable reordered content carrying its class does not create warning noise', function () {
    $authored = '<!-- wp:heading --><h2 class="has-text-align-center">Alpha</h2>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:heading --><h2>Beta</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2>Beta</h2><!-- /wp:heading -->'
        . '<!-- wp:heading --><h2 class="has-text-align-center">Alpha</h2>'
        . '<!-- /wp:heading -->';

    assert_eq([], (new AlignmentClassLossDetector())->detect($authored, $delivered));
});

test('duplicate semantic siblings with different provenance are not repair-safe', function () {
    $authored = '<!-- wp:heading --><h2 class="has-text-align-center">Same</h2>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:heading --><h2>Same</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2>Same</h2><!-- /wp:heading -->'
        . '<!-- wp:heading --><h2>Same</h2><!-- /wp:heading -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('duplicate semantic siblings with identical provenance remain repair-safe', function () {
    $authored = '<!-- wp:heading --><h2 class="has-text-align-center">Same</h2>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:heading --><h2 class="has-text-align-center">Same</h2><!-- /wp:heading -->';
    $delivered = '<!-- wp:heading --><h2>Same</h2><!-- /wp:heading -->'
        . '<!-- wp:heading --><h2>Same</h2><!-- /wp:heading -->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => true,
        ],
        [
            'path' => '1',
            'block' => 'core/heading',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => true,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('a surviving sibling moved onto a removed block path cannot hide the removal', function () {
    $authored = '<!-- wp:heading --><h2 class="has-text-align-center">Alpha</h2>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:heading --><h2 class="has-text-align-right">Beta</h2>'
        . '<!-- /wp:heading -->';
    $delivered = '<div>Inserted freeform</div>'
        . '<!-- wp:heading --><h2 class="has-text-align-center">Alpha</h2>'
        . '<!-- /wp:heading -->';

    assert_eq([
        [
            'path' => '1',
            'block' => 'core/heading',
            'authored' => 'has-text-align-right',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});

test('dynamic void output does not turn a carried class into a false loss', function () {
    $authored = '<!-- wp:site-title -->'
        . '<h1 class="wp-block-site-title has-text-align-center">Site</h1>'
        . '<!-- /wp:site-title -->';
    $delivered = '<!-- wp:site-title {"className":"wp-block-site-title has-text-align-center"} /-->';

    assert_eq([], (new AlignmentClassLossDetector())->detect($authored, $delivered));
});

test('dynamic void output without a carried class remains a durable loss', function () {
    $authored = '<!-- wp:site-title -->'
        . '<h1 class="wp-block-site-title has-text-align-center">Site</h1>'
        . '<!-- /wp:site-title -->';
    $delivered = '<!-- wp:site-title /-->';

    assert_eq([
        [
            'path' => '0',
            'block' => 'core/site-title',
            'authored' => 'has-text-align-center',
            'delivered' => [],
            'authoredClassOnSavedRoot' => true,
            'authoredClassIsSafeRootTextAlignment' => false,
        ],
    ], alignment_class_loss_rows((new AlignmentClassLossDetector())->detect($authored, $delivered)));
});
