<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastFix;

/** A black-on-white palette with a failing mid-tone secondary and a link-safe primary. */
function contrast_test_palette(): array
{
    return [
        'base'      => '#FFFFFF',
        'contrast'  => '#111111',
        'primary'   => '#1D4ED8', // 6.3:1 on white — passes
        'secondary' => '#999999', // 2.8:1 on white — fails for normal text
        'accent'    => '#B91C1C',
    ];
}

function contrast_fix(?string $globalLink = 'var(--wp--preset--color--primary)'): ContrastFix
{
    return new ContrastFix(contrast_test_palette(), [], $globalLink);
}

test('passing markup is untouched', function () {
    $src = '<!-- wp:paragraph -->' . "\n" . '<p>Readable default text.</p>' . "\n" . '<!-- /wp:paragraph -->';
    $res = contrast_fix()->process($src);
    assert_eq(false, $res['changed']);
    assert_eq([], $res['findings']);
    assert_eq($src, $res['markup']);
});

test('default (contrast) text on a contrast background gets flipped to base', function () {
    $src = '<!-- wp:group {"backgroundColor":"contrast"} -->' . "\n"
        . '<div class="wp-block-group has-contrast-background-color has-background">'
        . '<!-- wp:paragraph --><p>Invisible text</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('<!-- wp:paragraph {"textColor":"base"} -->', $res['markup']);
    assert_eq('text', $res['findings'][0]['kind']);
    assert_eq(true, $res['findings'][0]['repaired']);
});

test('explicit failing textColor is swapped, passing one kept', function () {
    $src = '<!-- wp:paragraph {"textColor":"secondary"} --><p>Muted but unreadable caption</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"textColor":"primary"} --><p>Fine on white</p><!-- /wp:paragraph -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('{"textColor":"contrast"}', $res['markup']);
    assert_contains('{"textColor":"primary"}', $res['markup'], 'passing color must be kept');
    assert_eq(1, count($res['findings']));
});

test('heading large-text threshold (3:1) allows what body text cannot', function () {
    // secondary (#999999) is 2.85:1 on white — fails even headings; but primary
    // passes both. Use a color between 3 and 4.5: #767676 is ~4.54... use #8A8A8A ~3.4:1.
    $palette = contrast_test_palette();
    $palette['secondary'] = '#8A8A8A'; // ~3.5:1 on white
    $fix = new ContrastFix($palette, [], null);
    $src = '<!-- wp:heading {"textColor":"secondary"} --><h2>Big heading</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"textColor":"secondary"} --><p>Body copy</p><!-- /wp:paragraph -->';
    $res = $fix->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('<!-- wp:heading {"textColor":"secondary"} -->', $res['markup'], 'heading passes at 3:1');
    assert_contains('<!-- wp:paragraph {"textColor":"contrast"} -->', $res['markup'], 'paragraph fails at 4.5:1');
});

test('inherited group textColor is honored when computing pairs', function () {
    $src = '<!-- wp:group {"backgroundColor":"contrast","textColor":"base"} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph --><p>Light on dark, fine</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src);
    assert_eq(false, $res['changed']);
});

test('links on a dark band get an explicit elements.link injected on the band', function () {
    $src = '<!-- wp:group {"backgroundColor":"contrast","textColor":"base"} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph --><p><a href="mailto:x@y.z">write us</a></p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('"link":{"color":{"text":"var:preset|color|base"}', $res['markup']);
    assert_contains(':hover', $res['markup'], 'hover link color must be pinned too');
    $kinds = array_column($res['findings'], 'kind');
    assert_true(in_array('link', $kinds, true), 'expected a link finding');
});

test('a block-authored failing link color on the page background is repaired at that block', function () {
    // The group sets its own elements.link to secondary (fails on base) — the
    // failure is block-authored, not the theme default, so fix it in place.
    $src = '<!-- wp:group {"style":{"elements":{"link":{"color":{"text":"var:preset|color|secondary"}}}}} -->' . "\n"
        . '<div class="wp-block-group has-link-color"><!-- wp:paragraph --><p><a href="/x">barely there</a></p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_eq('link', $res['findings'][0]['kind']);
    assert_eq(true, $res['findings'][0]['repaired']);
    assert_true(!str_contains($res['markup'], 'var:preset|color|secondary'), 'failing link color replaced');
});

test('failing global link on the page background is reported, not repaired here', function () {
    $fix = new ContrastFix(contrast_test_palette(), [], 'var(--wp--preset--color--secondary)');
    $src = '<!-- wp:paragraph --><p>See <a href="/x">details</a>.</p><!-- /wp:paragraph -->';
    $res = $fix->process($src);
    assert_eq(false, $res['changed']);
    assert_eq('link', $res['findings'][0]['kind']);
    assert_contains('theme level', $res['findings'][0]['detail']);
});

test('cover with image and text gets the dimRatio floor', function () {
    $src = '<!-- wp:cover {"url":"theme:./assets/hero.jpg","dimRatio":10} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:heading {"textColor":"base"} --><h1>Over the photo</h1><!-- /wp:heading -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('"dimRatio":40', $res['markup']);
    assert_eq('cover-dim', $res['findings'][0]['kind']);
});

test('cover with image, text and a healthy dim is untouched; text inside deferred to phase 2', function () {
    $src = '<!-- wp:cover {"url":"theme:./assets/hero.jpg","dimRatio":50} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:heading {"textColor":"secondary"} --><h1>Judged later against real pixels</h1><!-- /wp:heading -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    $res = contrast_fix()->process($src);
    assert_eq(false, $res['changed']);
    assert_eq([], $res['findings']);
});

test('imageless cover composites its overlay over the parent background', function () {
    // contrast overlay at dimRatio 100 over base = solid dark band; default
    // text (contrast) inside is invisible → flipped to base.
    $src = '<!-- wp:cover {"overlayColor":"contrast","dimRatio":100} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Dark band copy</p><!-- /wp:paragraph -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('<!-- wp:paragraph {"textColor":"base"} -->', $res['markup']);
});

test('repair=false lints without touching the markup', function () {
    $src = '<!-- wp:group {"backgroundColor":"contrast"} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph --><p>Header-style warning only</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src, false);
    assert_eq(false, $res['changed']);
    assert_eq($src, $res['markup']);
    assert_eq(false, $res['findings'][0]['repaired']);
});

test('mid-tone background takes the best partial repair when nothing passes', function () {
    // On #7A7A7A: base is ~4.3:1, contrast ~4.4:1 — nothing reaches 4.5, but
    // accent (~1.5:1) is a disaster worth trading up from.
    $palette = contrast_test_palette();
    $palette['secondary'] = '#7A7A7A';
    $fix = new ContrastFix($palette, [], null);
    $src = '<!-- wp:group {"backgroundColor":"secondary"} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph {"textColor":"accent"} --><p>Nearly invisible</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = $fix->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('best available', $res['findings'][0]['detail']);
});

test('swapping an explicit preset color also swaps the stale class in the HTML', function () {
    // If the old has-secondary-color token survives, the block fixer rescues
    // it into className and WP's !important preset rules can make it win.
    $palette = contrast_test_palette();
    $palette['secondary'] = '#555555'; // ~2.8:1 on the near-black contrast
    $src = '<!-- wp:group {"backgroundColor":"contrast"} -->' . "\n"
        . '<div class="wp-block-group has-contrast-background-color has-background">'
        . '<!-- wp:paragraph {"textColor":"secondary"} -->'
        . '<p class="has-secondary-color has-text-color">Dim caption</p>'
        . '<!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = (new ContrastFix($palette, [], null))->process($src);
    assert_contains('<!-- wp:paragraph {"textColor":"base"} -->', $res['markup']);
    assert_contains('<p class="has-base-color has-text-color">', $res['markup']);
    assert_true(!str_contains($res['markup'], 'has-secondary-color'), 'stale class must be gone');
});

test('the dimRatio floor swaps the stale has-background-dim-N class', function () {
    $src = '<!-- wp:cover {"url":"theme:./assets/hero.jpg","dimRatio":10} -->' . "\n"
        . '<div class="wp-block-cover">'
        . '<span aria-hidden="true" class="wp-block-cover__background has-background-dim-10 has-background-dim"></span>'
        . '<div class="wp-block-cover__inner-container">'
        . '<!-- wp:heading {"textColor":"base"} --><h1>Over the photo</h1><!-- /wp:heading -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    $res = contrast_fix()->process($src);
    assert_contains('"dimRatio":40', $res['markup']);
    assert_contains('has-background-dim-40', $res['markup']);
    assert_true(!str_contains($res['markup'], 'has-background-dim-10'), 'stale dim class must be gone');
});

test('a gradient co-authored with a solid backgroundColor is not ignored', function () {
    // Solid white background + a gradient ending near-black: text passing on
    // white alone still fails against the gradient's dark end.
    $gradients = ['veil' => 'linear-gradient(180deg, rgba(17,17,17,0) 0%, #111111 100%)'];
    $fix = new ContrastFix(contrast_test_palette(), $gradients, null);
    $src = '<!-- wp:group {"backgroundColor":"base","gradient":"veil"} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph {"textColor":"contrast"} --><p>Dies on the dark end</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = $fix->process($src);
    assert_true($res['findings'] !== [], 'the gradient stops must be part of the background set');
});

test('gradient interior colors are checked, not just the endpoints', function () {
    // A grey that clears 4.5:1 against BOTH black and white endpoints still
    // hits ~1:1 against the mid-grey the gradient renders between them.
    $palette = contrast_test_palette();
    $palette['secondary'] = '#767676';
    $gradients = ['sweep' => 'linear-gradient(180deg, #000000 0%, #FFFFFF 100%)'];
    $fix = new ContrastFix($palette, $gradients, null);
    $src = '<!-- wp:group {"gradient":"sweep"} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph {"textColor":"secondary"} --><p>Vanishes mid-gradient</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = $fix->process($src);
    assert_true($res['findings'] !== [], 'the interpolated midpoint must fail this pair');
});

test('gradient backgrounds are checked against every stop', function () {
    $gradients = ['dusk' => 'linear-gradient(180deg, #FFFFFF 0%, #111111 100%)'];
    $fix = new ContrastFix(contrast_test_palette(), $gradients, null);
    $src = '<!-- wp:group {"gradient":"dusk"} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph {"textColor":"base"} --><p>White dies on the white stop</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = $fix->process($src);
    // No palette color passes against BOTH white and near-black stops → warning.
    assert_eq(false, $res['findings'] === []);
    assert_eq(false, $res['findings'][0]['repaired']);
});
