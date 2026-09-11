<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\CardPreparation;
use Automattic\SiteBuild\Units\CardStyleContract;

function preparation_card(bool $image): string
{
    $picture = '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/dish.jpg" alt="Dish"/></figure><!-- /wp:image -->';
    $body = '<!-- wp:group {"className":"card-body","style":{"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm","left":"var:preset|spacing|sm","right":"var:preset|spacing|sm"}}}} -->'
        . '<div class="wp-block-group card-body"><!-- wp:paragraph --><p>Keep this dish description.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    return '<!-- wp:group {"className":"item-pattern__item card-style--flush card-flush","style":{"spacing":{"blockGap":"0"}}} -->'
        . '<div class="wp-block-group item-pattern__item card-style--flush card-flush">' . ($image ? $picture : '') . $body . '</div><!-- /wp:group -->';
}

test('text items lose image-card hooks and retain their content and surface', function () {
    $raw = preparation_card(false);
    $repairs = $warnings = [];
    $out = CardPreparation::enforce($raw, 'flush', 'page-menu--sides', $repairs, $warnings);
    assert_true(!str_contains($out, 'card-style--flush'));
    assert_true(!str_contains($out, 'card-flush'));
    assert_true(!str_contains($out, 'card-body'));
    assert_contains('Keep this dish description.', $out);
    assert_contains('item-pattern__item', $out);
    assert_contains('delivered=removed', implode("\n", $warnings));
    $strict = CardStyleContract::enforce($out, 'flush', 'page-menu--sides');
    assert_eq([], $strict['warnings']);
    $nextRepairs = $nextWarnings = [];
    assert_eq($out, CardPreparation::enforce($out, 'flush', 'page-menu--sides', $nextRepairs, $nextWarnings));
    assert_eq([], $nextWarnings);
});

test('a complete flush image card receives its absent surface', function () {
    $raw = preparation_card(true);
    $repairs = $warnings = [];
    $out = CardPreparation::enforce($raw, 'flush', 'page-menu--dishes', $repairs, $warnings);
    assert_contains('"backgroundColor":"base"', $out);
    assert_contains('has-base-background-color has-background', $out);
    assert_contains('Keep this dish description.', $out);
    $strict = CardStyleContract::enforce($out, 'flush', 'page-menu--dishes');
    assert_eq([], $strict['warnings']);
    $nextRepairs = $nextWarnings = [];
    assert_eq($out, CardPreparation::enforce($out, 'flush', 'page-menu--dishes', $nextRepairs, $nextWarnings));
    assert_eq([], $nextWarnings);
});

test('card preparation retains an authored surface and unrelated siblings', function () {
    $raw = str_replace('"className":"item-pattern__item', '"backgroundColor":"band","className":"item-pattern__item', preparation_card(true));
    $sibling = '<!-- wp:paragraph --><p>Unrelated details.</p><!-- /wp:paragraph -->';
    $repairs = $warnings = [];
    assert_eq($raw . $sibling, CardPreparation::enforce($raw . $sibling, 'flush', 'page-menu--dishes', $repairs, $warnings));
    assert_eq([], $warnings);
});

test('card preparation preserves unsafe boundaries for the strict validator', function () {
    $raw = substr(preparation_card(false), 0, -strlen('</div><!-- /wp:group -->'));
    $repairs = $warnings = [];
    assert_eq($raw, CardPreparation::enforce($raw, 'flush', 'page-menu--sides', $repairs, $warnings));
    assert_true(CardStyleContract::enforce($raw, 'flush', 'page-menu--sides')['warnings'] !== []);
});
