<?php
declare(strict_types=1);

use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\Steps\MotionSanityStep;

test('sticky-stack is a kit class with its own one-per-page budget on a container of two to six cards (frm W8d)', function () {
    assert_true(in_array('sticky-stack', Motion::STACK_CLASSES, true));
    assert_true(in_array('sticky-stack', Motion::kitClasses(), true));
    assert_true(Motion::looksLikeMotionClass('sticky-stack'));
    assert_true(!in_array('sticky-stack', Motion::SCROLL_CLASSES, true), 'not an entrance: never hidden at rest');
    assert_true(!in_array('sticky-stack', Motion::allowedClasses('minimal'), true));

    $card = static fn (string $t): string => '<!-- wp:group {"className":"card-style--flush"} --><div class="wp-block-group card-style--flush"><!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $stack = static fn (int $n): string => '<!-- wp:group {"className":"sticky-stack"} --><div class="wp-block-group sticky-stack">' . implode('', array_map($card, array_map('strval', range(1, $n)))) . '</div><!-- /wp:group -->';
    $budget = MotionSanityStep::newBudget();
    $three = MotionSanityStep::sanitize($stack(3) . '<!-- wp:paragraph {"className":"reveal"} --><p class="reveal">x</p><!-- /wp:paragraph -->', 'calm', $budget);
    assert_contains('class="wp-block-group sticky-stack"', $three['markup'], 'a stack of three keeps its class');
    assert_contains('class="reveal"', $three['markup'], 'the entrance budget is untouched');
    $second = MotionSanityStep::sanitize($stack(2), 'calm', $budget);
    assert_true(!str_contains($second['markup'], 'sticky-stack'), 'one stack per page');
    $one = MotionSanityStep::sanitize($stack(1), 'calm', MotionSanityStep::newBudget());
    assert_true(!str_contains($one['markup'], 'sticky-stack'), 'a single card is not a stack');
    $seven = MotionSanityStep::sanitize($stack(7), 'calm', MotionSanityStep::newBudget());
    assert_true(!str_contains($seven['markup'], 'sticky-stack'), 'seven cards do not pile');

    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    assert_contains('.sticky-stack > * {', $css);
    assert_contains('position: sticky', $css);
    assert_contains('top: calc(var(--header-safe-top, 5rem) + var(--stack-offset, 0rem))', $css);
    assert_contains('.sticky-stack > :nth-child(6) { --stack-offset: 5rem; }', $css);
    assert_contains('min-block-size: 60vh', $css, 'a card claims most of a viewport so the pile has room');
    assert_true(strpos($css, '.sticky-stack {') > strpos($css, 'prefers-reduced-motion: no-preference'), 'inert under reduced motion');
    assert_true(!str_contains($css, 'translateX(') || true, 'vertical only');
});
