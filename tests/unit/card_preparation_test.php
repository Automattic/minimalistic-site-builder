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

test('card surface repair honors inherited ink and precise wrapper attributes', function () {
    $plain = preparation_card(true);
    foreach (['single' => str_replace('class="wp-block-group item-pattern__item card-style--flush card-flush"', "class='wp-block-group item-pattern__item card-style--flush card-flush'", $plain),
        'data' => str_replace('class="wp-block-group item-pattern__item card-style--flush card-flush"', 'data-class="meta" class="wp-block-group item-pattern__item card-style--flush card-flush"', $plain)] as $case => $raw) {
        $repairs = $warnings = [];
        $out = CardPreparation::enforce($raw, 'flush', 'page-menu--dishes', $repairs, $warnings);
        $document = \Automattic\SiteBuild\BlockMarkup::parse($out);
        $tag = \Automattic\SiteBuild\MarkupScan::wrapperTag($document->ownHtml(0), 0);
        $class = \Automattic\SiteBuild\MarkupScan::tagAttribute($tag, 'class');
        assert_contains('has-base-background-color', $class[0]);
        if ($case === 'data') {
            assert_contains('data-class="meta"', $out);
        }
    }
    $raw = '<!-- wp:group {"textColor":"base","backgroundColor":"contrast"} --><div class="wp-block-group">'
        . $plain . '</div><!-- /wp:group -->';
    $repairs = $warnings = [];
    $out = CardPreparation::enforce($raw, 'flush', 'page-menu--dishes', $repairs, $warnings);
    assert_eq('contrast', \Automattic\SiteBuild\BlockMarkup::parse($out)->attrs(1)['backgroundColor']);
});

test('card surface repair proves contrast for authored palette colors', function () {
    $theme = ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#1E1712'], ['slug' => 'contrast', 'color' => '#E8DCC8'],
        ['slug' => 'primary', 'color' => '#AE7541'],
    ]]]];
    $raw = str_replace('<!-- wp:paragraph -->', '<!-- wp:paragraph {"textColor":"primary"} -->', preparation_card(true));
    $repairs = $warnings = [];
    $out = CardPreparation::enforce($raw, 'flush', 'page-menu--dishes', $repairs, $warnings, $theme);
    assert_eq('base', \Automattic\SiteBuild\BlockMarkup::parse($out)->attrs(0)['backgroundColor']);
    $raw = str_replace('"primary"', '"unknown"', $raw);
    $repairs = $warnings = [];
    assert_eq($raw, CardPreparation::enforce($raw, 'flush', 'page-menu--dishes', $repairs, $warnings, $theme));
    assert_contains('repair the card surface', implode("\n", $warnings));
});

test('card surface repair enforces the exact 4.5 contrast floor', function () {
    $theme = ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#ffffff'], ['slug' => 'contrast', 'color' => '#6e7882'],
    ]]]];
    $ratio = \Automattic\SiteBuild\ContrastMath::ratio([255, 255, 255], [110, 120, 130]);
    assert_true($ratio > 4.49 && $ratio < 4.5);
    $raw = preparation_card(true);
    $repairs = $warnings = [];
    assert_eq($raw, CardPreparation::enforce($raw, 'flush', 'page-menu--dishes', $repairs, $warnings, $theme));
    assert_contains('repair the card surface', implode("\n", $warnings));
    $theme['settings']['color']['palette'][1]['color'] = '#767676';
    $out = CardPreparation::enforce($raw, 'flush', 'page-menu--dishes', $repairs, $warnings, $theme);
    assert_eq('base', \Automattic\SiteBuild\BlockMarkup::parse($out)->attrs(0)['backgroundColor']);
});

test('card surface repair uses the explicit theme text color', function () {
    foreach (['var:preset|color|base', 'var(--wp--preset--color--base)', '#ffffff'] as $ink) {
        $theme = ['settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#ffffff'], ['slug' => 'contrast', 'color' => '#000000'],
        ]]], 'styles' => ['color' => ['text' => $ink]]];
        $repairs = $warnings = [];
        $out = CardPreparation::enforce(preparation_card(true), 'flush', 'page-menu--dishes', $repairs, $warnings, $theme);
        assert_eq('contrast', \Automattic\SiteBuild\BlockMarkup::parse($out)->attrs(0)['backgroundColor']);
    }
});

test('saved text-only borderless groups lose card markers with all text and siblings intact', function () {
    foreach (['home-visit-contact' => 3, 'about-hero' => 3, 'visit-location-and-hours' => 3, 'contact-contact-details' => 2] as $file => $count) {
        $raw = file_get_contents(__DIR__ . '/../fixtures/tbilisi13-markup/' . $file . '.html');
        $sibling = '<!-- wp:paragraph --><p>Keep this sibling unchanged.</p><!-- /wp:paragraph -->';
        $repairs = $warnings = [];
        assert_eq($count, count(CardStyleContract::enforce($raw, 'borderless', $file)['warnings']));
        $out = CardPreparation::enforce($raw . $sibling, 'borderless', $file, $repairs, $warnings);
        assert_eq([], CardStyleContract::enforce($out, 'borderless', $file)['warnings']);
        assert_eq(strip_tags($raw . $sibling), strip_tags($out));
        assert_contains($sibling, $out);
        assert_true(!str_contains($out, 'card-style--borderless'));
        assert_contains("file='theme/parts/{$file}.html'; block=", implode("\n", $warnings));
        assert_contains('authored=["card-style--borderless"]; delivered=removed', implode("\n", $warnings));
        $nextRepairs = $nextWarnings = [];
        assert_eq($out, CardPreparation::enforce($out, 'borderless', $file, $nextRepairs, $nextWarnings));
        assert_eq([], $nextWarnings);
    }
});

test('a borderless image card retains its image hooks and bytes', function () {
    $raw = str_replace(['card-style--flush', ' card-flush'], ['card-style--borderless', ''], preparation_card(true));
    $repairs = $warnings = [];
    assert_eq($raw, CardPreparation::enforce($raw, 'borderless', 'image-card', $repairs, $warnings));
    assert_eq([], $warnings);
});
