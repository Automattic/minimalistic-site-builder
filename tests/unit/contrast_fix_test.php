<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
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

test('theme element heading default is used for unstyled headings', function () {
    // The theme paints unstyled headings secondary (2.85:1 on white) via
    // styles.elements.heading — assuming `contrast` would certify a failure.
    $fix = new ContrastFix(
        contrast_test_palette(), [], null,
        null, 'var(--wp--preset--color--secondary)'
    );
    $src = '<!-- wp:heading --><h2>Reads muddy</h2><!-- /wp:heading -->';
    $res = $fix->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('secondary (theme default)', $res['findings'][0]['detail']);
    assert_contains('<!-- wp:heading {"textColor":"contrast"} -->', $res['markup']);
});

test('theme global text default is used for unstyled paragraphs', function () {
    $fix = new ContrastFix(
        contrast_test_palette(), [], null,
        'var(--wp--preset--color--secondary)'
    );
    $src = '<!-- wp:paragraph --><p>Body copy in the theme default</p><!-- /wp:paragraph -->';
    $res = $fix->process($src);
    assert_eq(true, $res['changed'], 'the secondary body default fails 4.5:1 on base');
    assert_contains('<!-- wp:paragraph {"textColor":"contrast"} -->', $res['markup']);
});

test('small headings get the 4.5:1 bar, not the blanket large-text 3:1', function () {
    // #8A8A8A is ~3.5:1 on white: fine for genuinely large text, unreadable
    // at a caption-scale preset. An h5 at 0.875rem must be held to 4.5.
    $palette = contrast_test_palette();
    $palette['secondary'] = '#8A8A8A';
    $fix = new ContrastFix($palette, [], null, null, null, ['caption' => '0.875rem', 'jumbo' => '3rem']);
    $src = '<!-- wp:heading {"level":5,"fontSize":"caption","textColor":"secondary"} --><h5 class="has-caption-font-size">Fine print</h5><!-- /wp:heading -->'
        . '<!-- wp:heading {"level":5,"fontSize":"jumbo","textColor":"secondary"} --><h5 class="has-jumbo-font-size">Display size</h5><!-- /wp:heading -->'
        . '<!-- wp:heading {"level":2,"textColor":"secondary"} --><h2>Assumed large</h2><!-- /wp:heading -->';
    $res = $fix->process($src);
    assert_contains('{"level":5,"fontSize":"caption","textColor":"contrast"}', $res['markup'], 'small heading must be repaired');
    assert_contains('{"level":5,"fontSize":"jumbo","textColor":"secondary"}', $res['markup'], 'explicitly large heading keeps 3:1');
    assert_contains('{"level":2,"textColor":"secondary"}', $res['markup'], 'unsized h2 keeps the large-text assumption');
});

test('unsized deep heading levels are held to 4.5:1', function () {
    $palette = contrast_test_palette();
    $palette['secondary'] = '#8A8A8A'; // ~3.5:1 on white
    $fix = new ContrastFix($palette, [], null);
    $src = '<!-- wp:heading {"level":5,"textColor":"secondary"} --><h5>Sub-sub-heading</h5><!-- /wp:heading -->';
    $res = $fix->process($src);
    assert_eq(true, $res['changed'], 'an h5 is not large text by default');
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

test('a passing link color must not hide a different failing one in the same region', function () {
    // Paragraph 1 pins its own elements.link to base (passes on the dark
    // band); paragraph 2 inherits the global primary default, which fails.
    $src = '<!-- wp:group {"backgroundColor":"contrast"} -->' . "\n"
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"var:preset|color|base"}}}}} -->'
        . '<p class="has-link-color"><a href="/a">fine</a></p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><a href="/b">invisible</a></p><!-- /wp:paragraph -->'
        . '</div>' . "\n" . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('<!-- wp:group {"backgroundColor":"contrast","style":{"elements":{"link":{"color":', $res['markup'],
        'the inherited failing color must be repaired on the band');
    assert_contains('"text":"var:preset|color|base"}', $res['markup']);
});

test('repaired hover is held to the 4.5:1 normal-text bar, not 3:1', function () {
    // On #CCCCCC the accent (#B91C1C) sits at ~4.0:1 — good enough for large
    // text, unreadable for body-size hover links. The hover must reuse the
    // repaired link color instead.
    $src = '<!-- wp:group {"style":{"color":{"background":"#CCCCCC"}}} -->' . "\n"
        . '<div class="wp-block-group has-background"><!-- wp:paragraph --><p><a href="/x">read on</a></p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('":hover":{"color":{"text":"var:preset|color|contrast"}}', $res['markup']);
    assert_true(!str_contains($res['markup'], 'var:preset|color|accent'), 'accent hover fails body-size text on #CCCCCC');
});

test('an authored hover that reads on the background is preserved by a link repair', function () {
    // The resting link (primary) fails on the dark band, but the authored
    // hover (secondary, ~6.6:1 on contrast) passes — repair must not touch it.
    $src = '<!-- wp:group {"backgroundColor":"contrast","style":{"elements":{"link":{"color":{"text":"var:preset|color|primary"},":hover":{"color":{"text":"var:preset|color|secondary"}}}}}} -->' . "\n"
        . '<div class="wp-block-group has-link-color"><!-- wp:paragraph {"textColor":"base"} --><p><a href="/x">dim link</a></p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_true(!str_contains($res['markup'], '"link":{"color":{"text":"var:preset|color|primary"}'), 'failing resting color repaired');
    assert_contains('":hover":{"color":{"text":"var:preset|color|secondary"}}', $res['markup'], 'passing authored hover kept');
});

test('a quote cite is checked even when paragraphs carry the quote body', function () {
    // The paragraph is explicitly readable; the <cite> inherits the default
    // (dark) text and dies on the dark band. Old behavior recorded no row at
    // all for the quote because it has text-block children.
    $src = '<!-- wp:group {"backgroundColor":"contrast"} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:quote --><blockquote class="wp-block-quote">'
        . '<!-- wp:paragraph {"textColor":"base"} --><p>Wise words</p><!-- /wp:paragraph -->'
        . '<cite>A. Person</cite></blockquote><!-- /wp:quote --></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('<!-- wp:quote {"textColor":"base"} -->', $res['markup'], 'cite repair lands on the quote');
});

test('a post-title with isLink is checked as a link', function () {
    $src = '<!-- wp:group {"backgroundColor":"contrast"} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:post-title {"isLink":true,"textColor":"base"} /--></div>' . "\n"
        . '<!-- /wp:group -->';
    $res = contrast_fix()->process($src);
    $kinds = array_column($res['findings'], 'kind');
    assert_true(in_array('link', $kinds, true), 'isLink post-title must produce a link check');
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
    // base overlay at dimRatio 100 over base = solid light band; core's
    // default cover text (white) is invisible on it → flipped to contrast.
    $src = '<!-- wp:cover {"overlayColor":"base","dimRatio":100} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Light band copy</p><!-- /wp:paragraph -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('cover default (white)', $res['findings'][0]['detail']);
    assert_contains('<!-- wp:paragraph {"textColor":"contrast"} -->', $res['markup']);
});

test('imageless dark cover: core white default already reads, nothing to fix', function () {
    // The old model assumed unstyled cover text renders `contrast` and
    // "repaired" it to base; core actually renders white, which passes.
    $src = '<!-- wp:cover {"overlayColor":"contrast","dimRatio":100} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Dark band copy</p><!-- /wp:paragraph -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    $res = contrast_fix()->process($src);
    assert_eq(false, $res['changed']);
    assert_eq([], $res['findings']);
});

test('is-light covers model the black default', function () {
    // isDark:false renders black inner text; on a dark band it must flip.
    $src = '<!-- wp:cover {"overlayColor":"contrast","dimRatio":100,"isDark":false} -->' . "\n"
        . '<div class="wp-block-cover is-light"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Dark band copy</p><!-- /wp:paragraph -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    $res = contrast_fix()->process($src);
    assert_eq(true, $res['changed']);
    assert_contains('cover default (black)', $res['findings'][0]['detail']);
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
    assert_eq(true, $res['findings'][0]['residual'] ?? false, 'partial repair retains a warning disposition');
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

test('a dimRatio repair raised from the 50 default adds the numbered saved-HTML class', function () {
    $src = '<!-- wp:cover {"dimRatio":50} -->'
        . '<div class="wp-block-cover"><span aria-hidden="true" '
        . 'class="wp-block-cover__background has-background-dim"></span>'
        . '<div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';
    $doc = BlockMarkup::parse($src);
    ContrastFix::swapDimClass($doc, 0, 50, 60);
    $out = $doc->render();
    assert_contains('has-background-dim-60 has-background-dim', $out);
});

test('a dimRatio repair moved to the 50 default removes only the numbered class', function () {
    $src = '<!-- wp:cover {"dimRatio":40} -->'
        . '<div class="wp-block-cover"><span aria-hidden="true" '
        . 'class="wp-block-cover__background has-background-dim-40 has-background-dim"></span>'
        . '<div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';
    $doc = BlockMarkup::parse($src);
    ContrastFix::swapDimClass($doc, 0, 40, 50);
    $out = $doc->render();
    assert_true(!str_contains($out, 'has-background-dim-40'));
    assert_eq(1, substr_count($out, 'has-background-dim'));
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

// ── overlay-header lint (ContrastFixStep) ────────────────────────────────

/** Temp project with a palette, an overlay (or standard) header, and two pages. */
function overlay_lint_project(bool $overlay, string $textColor = 'base'): array
{
    $tmp = sys_get_temp_dir() . '/builder_overlay_' . uniqid();
    $project = (new Automattic\SiteBuild\ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', [
        'version'  => 3,
        'settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#1D4ED8', 'name' => 'Primary'],
            // A mid grey that fails even against the scrim's worst case (#666).
            ['slug' => 'secondary', 'color' => '#9E9E9E', 'name' => 'Secondary'],
        ]]],
    ]);
    $className = $overlay ? 'header-behavior-overlay-to-solid' : 'site-header';
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"className":"' . $className . '","textColor":"' . $textColor . '","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group ' . $className . ' has-' . $textColor . '-color has-text-color"><!-- wp:site-title /--></div>'
        . '<!-- /wp:group -->'
    );
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [['slug' => 'hero']]],
        ['slug' => 'menu', 'front' => false, 'sections' => [['slug' => 'menu-hero']]],
    ]]);
    // Homepage opens dark; menu opens on the light base surface — the trusted
    // scrim decides whether the committed foreground still reads there.
    $project->writeText(
        'theme/parts/page-home--hero.html',
        '<!-- wp:group {"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-contrast-background-color has-base-color has-background has-text-color">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Dark opening</h2><!-- /wp:heading --></div><!-- /wp:group -->'
    );
    $project->writeText(
        'theme/parts/page-menu--menu-hero.html',
        '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Light opening</h2><!-- /wp:heading --></div><!-- /wp:group -->'
    );
    return [$project, $tmp];
}

test('overlay header lint composites the trusted scrim before judging opening backgrounds', function () {
    [$project, $tmp] = overlay_lint_project(overlay: true);
    // A light no-media cover: raw composite (#111 at 40% over base) fails a
    // white foreground, but the kit's 60% black scrim bounds every top-state
    // pixel to <= #666, against which the foreground was already verified.
    $project->writeText(
        'theme/parts/page-menu--menu-hero.html',
        '<!-- wp:cover {"overlayColor":"contrast","dimRatio":40} -->'
        . '<div class="wp-block-cover"><span aria-hidden="true" '
        . 'class="wp-block-cover__background has-contrast-background-color has-background-dim-40 has-background-dim"></span>'
        . '<div class="wp-block-cover__inner-container">'
        . '<!-- wp:heading {"textColor":"contrast"} --><h2 class="wp-block-heading has-contrast-color has-text-color">Light cover</h2><!-- /wp:heading -->'
        . '</div></div><!-- /wp:cover -->'
    );
    quietly(fn () => (new Automattic\SiteBuild\Steps\ContrastFixStep())->run($project));
    $log = $project->readText('logs/contrast-report.txt');
    assert_true(
        !str_contains($log, 'overlay header text'),
        'the scrimmed light cover and the dark homepage opening must both pass'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('overlay header lint still warns when the foreground fails against the scrimmed background', function () {
    // #9E9E9E on scrimmed base (#666) is ~2.1:1 — genuinely unreadable in the
    // kit's own top state, so the scrim-aware lint must keep warning.
    [$project, $tmp] = overlay_lint_project(overlay: true, textColor: 'secondary');
    quietly(fn () => (new Automattic\SiteBuild\Steps\ContrastFixStep())->run($project));
    $log = $project->readText('logs/contrast-report.txt');
    assert_contains("overlay header text secondary floats over page 'menu'", $log);
    assert_contains('scrim', $log);
    assert_true(
        !str_contains($log, "floats over page 'home'"),
        'the dark homepage opening must pass'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('non-overlay headers skip the overlay lint', function () {
    [$project, $tmp] = overlay_lint_project(overlay: false);
    quietly(fn () => (new Automattic\SiteBuild\Steps\ContrastFixStep())->run($project));
    assert_true(
        !str_contains($project->readText('logs/contrast-report.txt'), 'overlay header'),
        'a solid header must not trigger overlay warnings'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});
