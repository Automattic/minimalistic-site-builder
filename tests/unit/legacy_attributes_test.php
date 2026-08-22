<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;
use Automattic\SiteBuild\Units\LegacyAttributes;

/**
 * Unit tests for the post-generation legacy-attribute pass: deterministic
 * conversion of legacy comment attributes onto the current schema at intake,
 * BEFORE the serializer — whose reviewed migrations read saved has-text-align-*
 * classes that attribute-light markup no longer carries.
 */

test('legacy heading textAlign converts to style.typography.textAlign without needing the saved class', function () {
    // The attribute-light hazard: no has-text-align-center class anywhere, so
    // the serializer's class-reading migration can never fire. The intake
    // conversion must preserve the centering on the comment attributes alone.
    $markup = '<!-- wp:heading {"level":1,"textAlign":"center","textColor":"contrast"} -->'
        . '<h1 class="wp-block-heading">Grow with the seasons</h1><!-- /wp:heading -->';

    $out = LegacyAttributes::normalize($markup);

    assert_contains('"style":{"typography":{"textAlign":"center"}}', $out['markup']);
    assert_true(!str_contains($out['markup'], '"textAlign":"center","textColor"'), 'the legacy top-level key is gone');
    assert_contains('"textColor":"contrast"', $out['markup']);
    assert_eq(1, count($out['conversions']));
    assert_contains("converted legacy 'textAlign' to style.typography.textAlign on core/heading", $out['conversions'][0]);
    assert_eq([], $out['notes']);
});

test('legacy textAlign conversion also drops the matching saved alignment class', function () {
    $markup = '<!-- wp:heading {"textAlign":"center","className":"has-text-align-center accent-title"} -->'
        . '<h2 class="wp-block-heading has-text-align-center accent-title">Hi</h2><!-- /wp:heading -->';

    $out = LegacyAttributes::normalize($markup);

    assert_contains('"textAlign":"center"', $out['markup']); // inside style.typography now
    assert_contains('"className":"accent-title"', $out['markup'], 'the alignment class leaves className, custom classes stay');
    assert_true(!str_contains($out['markup'], 'has-text-align-center'), 'the saved class is dropped everywhere');
    assert_contains('accent-title', $out['markup']);
});

test('legacy paragraph and site-identity textAlign convert like the heading', function () {
    $paragraph = '<!-- wp:paragraph {"textAlign":"justify"} --><p>Copy</p><!-- /wp:paragraph -->';
    $siteTitle = '<!-- wp:site-title {"textAlign":"right"} /-->';

    $p = LegacyAttributes::normalize($paragraph);
    assert_contains('"style":{"typography":{"textAlign":"justify"}}', $p['markup']);

    $s = LegacyAttributes::normalize($siteTitle);
    assert_contains('"style":{"typography":{"textAlign":"right"}}', $s['markup']);
    assert_contains('core/site-title', $s['conversions'][0]);
});

test('an authored current-schema alignment wins over the legacy key', function () {
    $markup = '<!-- wp:heading {"textAlign":"center","style":{"typography":{"textAlign":"left"}}} -->'
        . '<h2 class="wp-block-heading">Hi</h2><!-- /wp:heading -->';

    $out = LegacyAttributes::normalize($markup);

    assert_contains('"textAlign":"left"', $out['markup']);
    assert_true(!str_contains($out['markup'], '"textAlign":"center"'), 'the legacy key is dropped, not merged');
    assert_eq(1, count($out['conversions']));
});

test('legacy button textAlign follows the pinned drop with a note', function () {
    $markup = '<!-- wp:button {"textAlign":"center"} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link has-text-align-center wp-element-button" '
        . 'href="#go">Go</a></div><!-- /wp:button -->';

    $out = LegacyAttributes::normalize($markup);

    assert_true(!str_contains($out['markup'], 'textAlign'), 'button alignment has no current-schema home');
    assert_true(!str_contains($out['markup'], 'has-text-align-center'));
    assert_contains('href="#go"', $out['markup']);
    assert_eq(1, count($out['notes']));
    assert_contains("dropped legacy 'textAlign' from core/button", $out['notes'][0]);
    assert_eq([], $out['conversions']);
});

test('legacy custom color and font-size attributes convert to current style paths', function () {
    $markup = '<!-- wp:paragraph {"customTextColor":"#ff0000","customBackgroundColor":"#001122","customFontSize":18} -->'
        . '<p style="color:#ff0000">Legacy</p><!-- /wp:paragraph -->';

    $out = LegacyAttributes::normalize($markup);

    assert_contains('"color":{"text":"#ff0000","background":"#001122"}', $out['markup']);
    assert_contains('"typography":{"fontSize":"18px"}', $out['markup']);
    assert_true(!str_contains($out['markup'], 'customTextColor'));
    assert_true(!str_contains($out['markup'], 'customBackgroundColor'));
    assert_true(!str_contains($out['markup'], 'customFontSize'));
    assert_eq(3, count($out['conversions']));
});

test('current-schema markup passes through untouched', function () {
    $markup = '<!-- wp:heading {"style":{"typography":{"textAlign":"center"}},"textColor":"contrast"} -->'
        . '<h2 class="wp-block-heading">Fine</h2><!-- /wp:heading -->'
        . "\n\n"
        . '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignfull"></div><!-- /wp:group -->';

    $out = LegacyAttributes::normalize($markup);

    assert_eq($markup, $out['markup']);
    assert_eq([], $out['conversions']);
    assert_eq([], $out['notes']);
});

test('an unconvertible legacy value is left for the fixer, not guessed at', function () {
    $markup = '<!-- wp:heading {"textAlign":"top-left"} --><h2 class="wp-block-heading">Hi</h2><!-- /wp:heading -->';

    $out = LegacyAttributes::normalize($markup);

    assert_eq($markup, $out['markup']);
    assert_eq([], $out['conversions']);
});

test('an image block with no src is reported as missing its required HTML source', function () {
    $markup = '<!-- wp:image {"sizeSlug":"large"} --><figure class="wp-block-image size-large">'
        . '<img alt="Team photo"/></figure><!-- /wp:image -->';

    $out = LegacyAttributes::normalize($markup);

    assert_eq($markup, $out['markup']);
    assert_eq(1, count($out['notes']));
    assert_contains('core/image block 0 has no img src', $out['notes'][0]);
});

test('GeneratedMarkup::normalize applies the legacy conversion at intake', function () {
    $raw = '<!-- wp:heading {"level":1,"textAlign":"center"} -->'
        . '<h1 class="wp-block-heading">Hello</h1><!-- /wp:heading -->';

    $notes = [];
    $repairs = [];
    $markup = GeneratedMarkup::normalize($raw, 'hero', $notes, $repairs);

    assert_contains('"style":{"typography":{"textAlign":"center"}}', $markup);
    assert_true(!str_contains($markup, '{"level":1,"textAlign"'), 'the legacy key is gone at intake');
    assert_eq(1, count($repairs), 'a lossless conversion lands in the repair report, not warnings');
    assert_eq('legacy-attributes-converted', $repairs[0]['code']);
    assert_contains('textAlign', $repairs[0]['authored']);
    assert_eq([], $notes);
});
