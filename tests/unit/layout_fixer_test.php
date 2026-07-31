<?php
declare(strict_types=1);

use Automattic\SiteBuild\LayoutFixer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\ThemeValidator;

// Fixtures below are distilled from the real failure shapes observed across
// the six demo builds (see PR "improve section container width & rhythm").

function lf_column(): string
{
    return '<!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column -->';
}

function lf_columns(int $n, string $attrs = ''): string
{
    $json = $attrs === '' ? '' : ' ' . $attrs;
    return "<!-- wp:columns{$json} --><div class=\"wp-block-columns\">"
        . str_repeat(lf_column(), $n)
        . '</div><!-- /wp:columns -->';
}

test('layout fixer adds constrained layout to a top-level group without one', function () {
    // tbilisi "The Cuisine": align:full band with NO layout attribute — its
    // alignwide children rendered edge-to-edge at the viewport.
    $markup = '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">'
        . lf_columns(2, '{"align":"wide"}')
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 840.0);
    assert_contains('"layout":{"type":"constrained"}', $r['markup']);
    assert_true($r['notes'] !== [], 'expected a note');
});

test('layout fixer leaves an HTML-first section root layout-less', function () {
    // HTML-first: the transformer emits no layout so the carried design CSS
    // owns width; stamping constrained back boxes every full-bleed hero.
    $markup = '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">'
        . lf_columns(2, '{"align":"wide"}')
        . '</div><!-- /wp:group -->';
    foreach ([LayoutFixer::ROLE_SECTION, LayoutFixer::ROLE_TEMPLATE] as $role) {
        $r = LayoutFixer::fix($markup, $role, 840.0, [], true);
        assert_true(!str_contains($r['markup'], '"constrained"'), "{$role} stays layout-less on HTML-first");
        assert_eq($markup, $r['markup'], "{$role} markup is untouched on HTML-first");

        $legacy = LayoutFixer::fix($markup, $role, 840.0);
        assert_contains('"layout":{"type":"constrained"}', $legacy['markup']);
    }
});

test('layout fixer still constrains HTML-first header and footer roots', function () {
    // Header/footer width still comes from the theme on both paths.
    $markup = '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">'
        . '</div><!-- /wp:group -->';
    foreach ([LayoutFixer::ROLE_HEADER, LayoutFixer::ROLE_FOOTER] as $role) {
        foreach ([true, false] as $htmlFirst) {
            $r = LayoutFixer::fix($markup, $role, 840.0, [], $htmlFirst);
            assert_contains('"layout":{"type":"constrained"}', $r['markup']);
        }
    }
});

test('layout fixer skips the HTML-first section width heuristics that assume theme width', function () {
    // freeGridsFromNarrowWrappers would force align:wide onto the grid; the
    // carried design CSS already sized it.
    $grid = '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignwide">' . lf_columns(2) . '</div><!-- /wp:group -->';
    assert_eq($grid, LayoutFixer::fix($grid, LayoutFixer::ROLE_SECTION, 860.0, [], true)['markup']);
    assert_contains('"align":"wide"', LayoutFixer::fix($grid, LayoutFixer::ROLE_SECTION, 860.0)['markup']);

    // restoreCoverMeasure would drop the design's deliberate narrow measure.
    $cover = '<!-- wp:cover --><div class="wp-block-cover">'
        . '<!-- wp:group {"layout":{"type":"constrained","contentSize":"420px"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
        . '</div><!-- /wp:cover -->';
    assert_contains('"contentSize":"420px"', LayoutFixer::fix($cover, LayoutFixer::ROLE_SECTION, 860.0, [], true)['markup']);
    assert_true(
        !str_contains(LayoutFixer::fix($cover, LayoutFixer::ROLE_SECTION, 860.0)['markup'], '420px'),
        'legacy still restores the theme measure',
    );
});

test('layout fixer leaves an explicit non-constrained layout alone', function () {
    $markup = '<!-- wp:group {"layout":{"type":"flex"}} --><div class="wp-block-group"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 840.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer promotes an alignwide className to the real align attribute', function () {
    // portfolio footer: className:"alignwide" styles nothing — WordPress
    // computes widths from the attribute.
    $markup = '<!-- wp:group {"className":"alignwide has-background","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignwide has-background"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_contains('"align":"wide"', $r['markup']);
    assert_contains('"className":"has-background"', $r['markup']);
    assert_true(!str_contains($r['markup'], 'alignwide"'), 'alignwide class token should be gone from attributes');
});

test('layout fixer preserves JSON object and array shapes when rewriting a dirty node', function () {
    $markup = '<!-- wp:group {"align":"full","metadata":{},"items":[],"numeric":{"0":"zero"}} -->'
        . '<div class="wp-block-group alignfull"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"metadata":{}', $r['markup']);
    assert_contains('"items":[]', $r['markup']);
    assert_contains('"numeric":{"0":"zero"}', $r['markup']);
});

test('layout fixer promotes align classes on gallery and media-text grids', function () {
    $markup = '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:gallery {"className":"alignfull mosaic"} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->'
        . '<!-- wp:media-text {"align":"wide","className":"alignfull timeline"} --><div class="wp-block-media-text"></div><!-- /wp:media-text -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('wp:gallery {"className":"mosaic","align":"full"}', $r['markup']);
    assert_contains('wp:media-text {"align":"wide","className":"timeline"}', $r['markup']);
    assert_true(!str_contains($r['markup'], '"className":"alignfull'), 'align class tokens should be removed from grid className values');
});

test('layout fixer evens out mixed-width footer rows', function () {
    // portfolio/naturaleza footers: site-title lockup at content width beside
    // alignwide link columns — two competing left edges.
    $markup = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">'
        . '<!-- wp:group {"layout":{"type":"flex"}} --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->'
        . lf_columns(2, '{"align":"wide"}')
        . '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_contains('wp:group {"layout":{"type":"flex"},"align":"wide"}', $r['markup']);
    assert_contains('wp:separator {"align":"wide"}', $r['markup']);
});

test('layout fixer canonicalizes full and wide footer rows to wide', function () {
    $markup = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">'
        . '<!-- wp:group {"align":"full","layout":{"type":"flex"}} --><div class="wp-block-group alignfull"></div><!-- /wp:group -->'
        . lf_columns(2, '{"align":"wide"}')
        . '<!-- wp:separator {"align":"full"} --><hr class="wp-block-separator alignfull"/><!-- /wp:separator -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_contains('wp:group {"align":"wide","layout":{"type":"flex"}}', $r['markup']);
    assert_contains('wp:separator {"align":"wide"}', $r['markup']);
    assert_true(!str_contains($r['markup'], 'wp:separator {"align":"full"}'), 'full-width structural row should be canonicalized');
});

test('layout fixer passes a wide constrained footer wrapper width to its leaf rows', function () {
    // portfolio6: the wrappers and columns were wide, but the title, copy and
    // rules inside each constrained wrapper still fell back to contentSize.
    $markup = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">'
        . '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:paragraph --><p>Buenos Aires · Documentary Photojournalism</p><!-- /wp:paragraph -->'
        . '<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator is-style-wide"/><!-- /wp:separator -->'
        . '</div><!-- /wp:group -->'
        . lf_columns(3, '{"align":"wide"}')
        . '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->'
        . '<!-- wp:paragraph --><p>Built with WordPress</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_contains('wp:site-title {"align":"wide"}', $r['markup']);
    assert_eq(2, substr_count($r['markup'], 'wp:paragraph {"align":"wide"}'));
    assert_contains('wp:separator {"className":"is-style-wide","align":"wide"}', $r['markup']);
    assert_contains('wp:separator {"align":"wide"}', $r['markup']);
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_FOOTER, 860.0)['notes']);
});

test('layout fixer preserves a wide footer wrapper with an explicitly aligned composition', function () {
    $markup = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">'
        . '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">Centered credit</p><!-- /wp:paragraph -->'
        . '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer keeps a consistent content-width footer untouched', function () {
    // No wide sibling → no promotion; a deliberate content-width footer stays.
    $markup = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">'
        . '<!-- wp:group {"layout":{"type":"flex"}} --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->'
        . lf_columns(2)
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_eq([], $r['notes']);
});

test('layout fixer widens a 3+ column footer row and its wrappers', function () {
    // portfolio2/tbilisi2 footers: three columns squeezed into the content
    // width, email addresses wrapping mid-word.
    $markup = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">'
        . '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . lf_columns(3)
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_contains('wp:columns {"align":"wide"}', $r['markup']);
    assert_contains('"layout":{"type":"constrained"},"align":"wide"', $r['markup']);
});

test('layout fixer widens footer wrapper ancestors even when columns are already aligned', function () {
    $markup = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">'
        . '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:group --><div class="wp-block-group">'
        . lf_columns(3, '{"align":"wide"}')
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_eq(3, substr_count($r['markup'], '"align":"wide"'), 'columns and both group ancestors should be wide');
});

test('layout fixer does not widen footer columns when the band itself is content width', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . lf_columns(3)
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_eq([], $r['notes']);
});

test('layout fixer widens grid rows sitting at content width inside a wide band', function () {
    // portfolio "A Decade of Turning Points": media-text timeline rows were
    // non-aligned children of the wide band, capping at 860px of 1320px.
    $markup = '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:group {"layout":{"type":"constrained","contentSize":"860px"}} --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">T</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:media-text --><div class="wp-block-media-text"></div><!-- /wp:media-text -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('wp:media-text {"align":"wide"}', $r['markup']);
    // The text-only intro wrapper keeps its reading measure.
    assert_contains('"contentSize":"860px"', $r['markup']);
});

test('layout fixer frees a grid boxed inside a narrow contentSize wrapper', function () {
    $markup = '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:group {"layout":{"type":"constrained","contentSize":"800px"}} --><div class="wp-block-group">'
        . lf_columns(2)
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_true(!str_contains($r['markup'], 'contentSize'), 'narrow cap should be dropped');
    assert_contains('wp:columns {"align":"wide"}', $r['markup']);
});

test('layout fixer propagates grid width through nested wrappers without contentSize', function () {
    $markup = '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:group --><div class="wp-block-group">'
        . lf_columns(2, '{"align":"wide"}')
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq(4, substr_count($r['markup'], '"align":"wide"'), 'root, grid, and both wrapper ancestors should be wide');
});

test('layout fixer only follows plain group paths when widening grids', function () {
    $markup = '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:cover --><div class="wp-block-cover">'
        . '<!-- wp:group --><div class="wp-block-group">' . lf_columns(2) . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:cover -->'
        . '</div><!-- /wp:group -->';
    assert_eq([], LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer leaves grid rows alone in a content-width section', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . lf_columns(2)
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
});

test('layout fixer restores the cover measure when squeezed far below the theme contentSize', function () {
    // portfolio2 hero: display headline pinned into a 640px box of an 88vh cover.
    $markup = '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:cover {"align":"wide"} --><div class="wp-block-cover alignwide">'
        . '<!-- wp:spacer {"height":"20px"} --><div class="wp-block-spacer"></div><!-- /wp:spacer -->'
        . '<!-- wp:group {"layout":{"type":"constrained","contentSize":"640px"}} --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">H</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:cover -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_true(!str_contains($r['markup'], '"contentSize":"640px"'), '640px cover cap should be dropped');

    // A measure close to the theme's (800 of 860) is a deliberate choice — kept.
    $kept = str_replace('640px', '800px', $markup);
    assert_eq([], LayoutFixer::fix($kept, LayoutFixer::ROLE_SECTION, 860.0)['notes']);

    // Without a known theme contentSize the rule stays out of the way.
    assert_eq([], LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, null)['notes']);
});

test('layout fixer preserves narrow component measures nested inside cover content', function () {
    $markup = '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">'
        . '<!-- wp:cover {"align":"wide"} --><div class="wp-block-cover alignwide">'
        . '<!-- wp:group {"layout":{"type":"constrained","contentSize":"640px"}} --><div class="wp-block-group">'
        . '<!-- wp:group {"className":"card","layout":{"type":"constrained","contentSize":"320px"}} --><div class="wp-block-group card"></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"badge","layout":{"type":"constrained","contentSize":"240px"}} --><div class="wp-block-group badge"></div><!-- /wp:group -->'
        . '</div><!-- /wp:cover -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_true(!str_contains($r['markup'], '"contentSize":"640px"'), 'primary cover measure should be restored');
    assert_contains('"contentSize":"320px"', $r['markup']);
    assert_contains('"contentSize":"240px"', $r['markup']);
});

test('layout fixer is idempotent on everything it fixes', function () {
    $fixtures = [
        [LayoutFixer::ROLE_SECTION, '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">' . lf_columns(2, '{"align":"wide"}') . '</div><!-- /wp:group -->'],
        [LayoutFixer::ROLE_FOOTER, '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull"><!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . lf_columns(3) . '</div><!-- /wp:group --></div><!-- /wp:group -->'],
        [LayoutFixer::ROLE_SECTION, '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:group --><div class="wp-block-group"><!-- wp:group {"layout":{"type":"constrained","contentSize":"700px"}} --><div class="wp-block-group">' . lf_columns(2, '{"align":"wide"}') . '</div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group -->'],
        [LayoutFixer::ROLE_FOOTER, '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:group {"align":"full"} --><div class="wp-block-group">' . lf_columns(3, '{"align":"wide"}') . '</div><!-- /wp:group --></div><!-- /wp:group -->'],
    ];
    foreach ($fixtures as [$role, $markup]) {
        $first = LayoutFixer::fix($markup, $role, 860.0);
        assert_true($first['notes'] !== [], 'fixture should need fixing');
        $second = LayoutFixer::fix($first['markup'], $role, 860.0);
        assert_eq([], $second['notes']);
        assert_eq($first['markup'], $second['markup']);
    }
});

test('layout fixer refuses to touch unbalanced or unparseable markup', function () {
    $unbalanced = '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">';
    $r = LayoutFixer::fix($unbalanced, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($unbalanced, $r['markup']);

    $badJson = '<!-- wp:group {"align":} --><div class="wp-block-group"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($badJson, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($badJson, $r['markup']);
});

test('normalize-layout step repairs attributes on disk before the policy passes run', function () {
    // PR #109 review, finding 1: attribute repair must happen BEFORE
    // contrast/motion enforcement, or repaired attributes bypass both.
    $tmp = sys_get_temp_dir() . '/builder_normalize_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:group {"backgroundColor":"contrast"}} --><div class="wp-block-group has-contrast-background-color has-background"></div><!-- /wp:group -->'
    );
    (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep())->run($project);
    $markup = $project->readText('theme/parts/section-hero.html');
    assert_contains('wp:group {"backgroundColor":"contrast","layout":{"type":"constrained"}}', $markup);
    assert_contains('normalize-layout.log', implode(' ', glob($tmp . '/demo/logs/*') ?: []));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator layout warnings report what the fixer would change', function () {
    $tmp = sys_get_temp_dir() . '/builder_layout_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px', 'wideSize' => '1320px']]]);
    $project->writeText(
        'theme/parts/section-cuisine.html',
        '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">' . lf_columns(2, '{"align":"wide"}') . '</div><!-- /wp:group -->'
    );
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">' . lf_columns(3) . '</div><!-- /wp:group -->'
    );
    $warnings = ThemeValidator::layoutWarnings($project);
    assert_contains('section-cuisine', implode(' ', $warnings));
    assert_contains('footer', implode(' ', $warnings));

    // Normalized markup → no warnings.
    \Automattic\SiteBuild\Steps\FixBlocksStep::normalizeLayouts($project);
    assert_eq([], ThemeValidator::layoutWarnings($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

// ── Spacing-attribute canonicalization & rhythm mirror-copy (BIGR-674 case 1) ──

// ── Top-level support-key canonicalization (BIGR-718) ────────────────────

test('layout fixer folds a top-level spacing attribute into style.spacing', function () {
    // atlas page-home--trust-builders, verbatim: "spacing" written as a
    // SIBLING of "style". It is not a registered core/group attribute, so
    // the serializer's closed comment-attribute domain failed the build.
    $markup = '<!-- wp:group {"style":{"border":{"radius":"18px","width":"1px","color":"#dce4de"}},"spacing":{"padding":{"left":"var:preset|spacing|md"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-border-color" style="border-color:#dce4de;border-width:1px;border-radius:18px;padding-left:var(--wp--preset--spacing--md)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"style":{"border":{"radius":"18px","width":"1px","color":"#dce4de"},"spacing":{"padding":{"left":"var:preset|spacing|md"}}}', $r['markup']);
    assert_eq(1, substr_count($r['markup'], '"spacing"'), 'the top-level spacing key should be gone');
    assert_contains('wp:group declared "spacing" at the top level of its attributes where WordPress ignores it — moved to style.spacing', implode("\n", $r['notes']));
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer folds top-level border and typography attributes into style', function () {
    $markup = '<!-- wp:heading {"level":2,"typography":{"fontWeight":"600"},"border":{"radius":"4px"}} -->'
        . '<h2 class="wp-block-heading" style="border-radius:4px;font-weight:600">Title</h2><!-- /wp:heading -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"style":{"border":{"radius":"4px"},"typography":{"fontWeight":"600"}}', $r['markup']);
    assert_contains('moved to style.typography', implode("\n", $r['notes']));
    assert_contains('moved to style.border', implode("\n", $r['notes']));
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer preserves support-named attributes on unregistered blocks', function () {
    // The serializer deliberately preserves custom/missing blocks raw. Their
    // own registry may define these as real top-level attributes, so only the
    // pinned core registry can authorize the style-support repair.
    $markup = '<!-- wp:vendor/card {"spacing":{"density":"compact"},"border":{"variant":"soft"},"typography":{"scale":"display"}} -->'
        . '<div class="vendor-card">Card</div><!-- /wp:vendor/card -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);

    $serialized = (new \Automattic\SiteBuild\BlockSerializer\Serializer())->transform($r['markup'])->html;
    assert_contains('wp:vendor/card {"spacing":{"density":"compact"},"border":{"variant":"soft"},"typography":{"scale":"display"}}', $serialized);
    assert_true(!str_contains($serialized, '"style"'), 'the missing-block fallback must keep the custom attribute domain raw');
});

test('layout fixer merges a top-level spacing family without overriding the canonical one', function () {
    // The canonical style.spacing members win; only missing ones are adopted.
    $markup = '<!-- wp:group {"spacing":{"padding":{"top":"4rem"},"blockGap":"var:preset|spacing|sm"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|xl"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--xl)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"style":{"spacing":{"padding":{"top":"var:preset|spacing|xl"},"blockGap":"var:preset|spacing|sm"}}', $r['markup']);
    assert_true(!str_contains($r['markup'], '4rem'), 'the canonical padding must win over the misplaced value');
    assert_contains('merged blockGap into style.spacing', implode("\n", $r['notes']));
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer recursively merges split support members and the block fixer drops nothing', function () {
    // Canonical values win at conflicts (padding.top), while missing nested
    // members from both padding and blockGap are still recovered.
    $pre = '<!-- wp:group {"spacing":{"padding":{"top":"4rem","bottom":"var:preset|spacing|md"},"blockGap":{"top":"var:preset|spacing|lg"}},"style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","left":"var:preset|spacing|sm"},"blockGap":{"left":"var:preset|spacing|xs"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--xl);padding-left:var(--wp--preset--spacing--sm);padding-bottom:var(--wp--preset--spacing--md)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($pre, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"padding":{"top":"var:preset|spacing|xl","left":"var:preset|spacing|sm","bottom":"var:preset|spacing|md"}', $r['markup']);
    assert_contains('"blockGap":{"left":"var:preset|spacing|xs","top":"var:preset|spacing|lg"}', $r['markup']);
    assert_true(!str_contains($r['markup'], '4rem'), 'the canonical nested padding.top must win');
    assert_contains('merged padding.bottom/blockGap.top into style.spacing', implode("\n", $r['notes']));
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);

    $theme = php_block_fixer_test_theme(['parts/split-support-members.html' => $r['markup']]);
    try {
        $report = (new \Automattic\SiteBuild\PhpBlockFixer())->fix($theme);
        assert_contains('0 style/class value(s) dropped', $report);
        $written = file_get_contents($theme . '/parts/split-support-members.html');
        assert_contains('"padding":{"top":"var:preset|spacing|xl","left":"var:preset|spacing|sm","bottom":"var:preset|spacing|md"}', $written);
        assert_contains('"blockGap":{"left":"var:preset|spacing|xs","top":"var:preset|spacing|lg"}', $written);
        assert_contains('padding-bottom:var(--wp--preset--spacing--md)', $written);
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('layout fixer composes the top-level fold with the style.padding canonicalization', function () {
    // Both misspellings on one block: the top-level family folds first, then
    // the style.padding sibling merges into the same style.spacing.padding.
    $markup = '<!-- wp:group {"spacing":{"padding":{"top":"var:preset|spacing|lg"}},"style":{"padding":{"bottom":"var:preset|spacing|md"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--lg);padding-bottom:var(--wp--preset--spacing--md)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"style":{"spacing":{"padding":{"top":"var:preset|spacing|lg","bottom":"var:preset|spacing|md"}}}', $r['markup']);
    assert_eq(1, substr_count($r['markup'], '"padding"'), 'both misplaced spellings should collapse into one canonical box');
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer leaves a non-object top-level support key for the gate', function () {
    $markup = '<!-- wp:group {"spacing":"compact","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer leaves explicit non-object style shapes for the gate', function () {
    foreach (['null', '"invalid"', '[]'] as $styleJson) {
        $markup = '<!-- wp:group {"style":' . $styleJson . ',"spacing":{"padding":{"left":"1rem"}},"layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group" style="padding-left:1rem"></div><!-- /wp:group -->';
        $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
        assert_eq([], $r['notes']);
        assert_eq($markup, $r['markup']);
    }

    $markup = '<!-- wp:group {"style":null,"spacing":{"padding":{"left":"1rem"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="padding-left:1rem"></div><!-- /wp:group -->';
    $theme = php_block_fixer_test_theme(['parts/invalid-style-shape.html' => $markup]);
    try {
        $report = (new \Automattic\SiteBuild\PhpBlockFixer())->fix($theme);
        assert_contains('FAILED parts/invalid-style-shape.html', $report);
        assert_contains("Unsupported comment attribute 'spacing' for core/group", $report);
        assert_eq($markup, file_get_contents($theme . '/parts/invalid-style-shape.html'));
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('normalize-layout repair lets the PHP block fixer serialize the atlas repro it rejected', function () {
    // End-to-end story for BIGR-718: the raw markup fails fix-blocks closed
    // ("Unsupported comment attribute"), the LayoutFixer repair re-nests the
    // family, and the same serializer then accepts the file byte-cleanly —
    // the inline CSS the model wrote survives with zero dropped values.
    // (Theme fixture helpers live in php_block_fixer_test.php; the runner
    // loads every test file before executing any case.)
    $pre = '<!-- wp:group {"style":{"border":{"radius":"18px","width":"1px","color":"#dce4de"}},"spacing":{"padding":{"left":"var:preset|spacing|md"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-border-color" style="border-color:#dce4de;border-width:1px;border-radius:18px;padding-left:var(--wp--preset--spacing--md)"></div><!-- /wp:group -->';

    $theme = php_block_fixer_test_theme(['parts/page-home--trust-builders.html' => $pre]);
    try {
        $report = (new \Automattic\SiteBuild\PhpBlockFixer())->fix($theme);
        assert_contains('FAILED parts/page-home--trust-builders.html', $report);
        assert_contains("Unsupported comment attribute 'spacing' for core/group", $report);
        assert_eq($pre, file_get_contents($theme . '/parts/page-home--trust-builders.html'));
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }

    $post = LayoutFixer::fix($pre, LayoutFixer::ROLE_SECTION, 860.0)['markup'];
    $theme = php_block_fixer_test_theme(['parts/page-home--trust-builders.html' => $post]);
    try {
        $report = (new \Automattic\SiteBuild\PhpBlockFixer())->fix($theme);
        assert_contains('0 style/class value(s) dropped', (string) $report);
        $written = file_get_contents($theme . '/parts/page-home--trust-builders.html');
        assert_contains('"style":{"border":{"radius":"18px","width":"1px","color":"#dce4de"},"spacing":{"padding":{"left":"var:preset|spacing|md"}}}', $written);
        assert_contains('padding-left:var(--wp--preset--spacing--md)', $written);
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('layout fixer moves a style.margin sibling of style.spacing into style.spacing.margin', function () {
    // tbilisi25 signature-dishes cards: margin authored as a SIBLING of
    // spacing — WordPress ignores that path, so re-serialization dropped
    // margin-top:3rem and the rhythm gate rejected the build.
    $markup = '<!-- wp:group {"className":"hover-lift","style":{"spacing":{"padding":{"top":"var:preset|spacing|sm"}},"margin":{"top":"3rem"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hover-lift" style="margin-top:3rem;padding-top:var(--wp--preset--spacing--sm)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"spacing":{"padding":{"top":"var:preset|spacing|sm"},"margin":{"top":"3rem"}}', $r['markup']);
    assert_eq(1, substr_count($r['markup'], '"margin"'), 'the misplaced key should be gone');
    assert_true($r['notes'] !== [], 'expected a note');
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer moves a style.padding sibling into style.spacing without spacing present', function () {
    $markup = '<!-- wp:group {"style":{"padding":{"top":"var:preset|spacing|md"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--md)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"style":{"spacing":{"padding":{"top":"var:preset|spacing|md"}}}', $r['markup']);
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer merges misplaced spacing sides without overriding the canonical ones', function () {
    // SectionRhythm owns the root's vertical margins/padding: a misplaced
    // sibling key must not reintroduce spacing the rhythm owner set to zero.
    $markup = '<!-- wp:group {"align":"full","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}},"margin":{"top":"4rem","left":"1rem"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignfull" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"margin":{"top":"0","bottom":"0","left":"1rem"}', $r['markup']);
    assert_true(!str_contains($r['markup'], '4rem'), 'owned zero must win over the misplaced vertical value');
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer leaves correctly nested spacing attributes untouched', function () {
    $markup = '<!-- wp:group {"align":"full","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"padding":{"top":"var:preset|spacing|lg","bottom":"var:preset|spacing|lg"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignfull" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--lg);padding-bottom:var(--wp--preset--spacing--lg)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer maps CSS flex justification vocabulary to Gutenberg values', function () {
    $markup = '<!-- wp:group {"layout":{"type":"flex","justifyContent":"flex-end","verticalAlignment":"center"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $result = LayoutFixer::fix($markup, LayoutFixer::ROLE_HEADER, 860.0);

    assert_contains('"justifyContent":"right"', $result['markup']);
    assert_contains('mapped it to Gutenberg', implode("\n", $result['notes']));
    assert_eq([], LayoutFixer::fix($result['markup'], LayoutFixer::ROLE_HEADER, 860.0)['notes']);
});

test('layout fixer repairs malformed rendered preset variables only with matching attrs', function () {
    $matching = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"var:preset|spacing|md"}}}} -->'
        . '<p style="margin-top:var(--wp--spacing--md)">Copy</p><!-- /wp:paragraph -->';
    $result = LayoutFixer::fix($matching, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('margin-top:var(--wp--preset--spacing--md)', $result['markup']);
    assert_contains('restored the canonical CSS variable spelling', implode("\n", $result['notes']));
    assert_eq([], LayoutFixer::fix($result['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);

    $htmlOnly = '<!-- wp:paragraph --><p style="margin-top:var(--wp--spacing--md)">Copy</p><!-- /wp:paragraph -->';
    assert_eq($htmlOnly, LayoutFixer::fix($htmlOnly, LayoutFixer::ROLE_SECTION, 860.0)['markup']);

    $disagreeing = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"var:preset|spacing|lg"}}}} -->'
        . '<p style="margin-top:var(--wp--spacing--md)">Copy</p><!-- /wp:paragraph -->';
    assert_eq($disagreeing, LayoutFixer::fix($disagreeing, LayoutFixer::ROLE_SECTION, 860.0)['markup']);
});

test('layout fixer repairs attribute JSON whose only reading deletes a stray closer', function () {
    // A stray `}` closes the attrs object before ",layout" — json_decode
    // fails and block serialization would erase every attribute of the block.
    // Deleting any brace of the run yields the same, single valid object, so
    // the repair is unambiguous and applied.
    $markup = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">'
        . '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}},"layout":{"type":"constrained"}}', $r['markup']);
    assert_eq(2, substr_count($r['markup'], '"layout":{"type":"constrained"}'));
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer restores an omitted final root attribute closer', function () {
    // portfolio33: all nested objects were balanced, but the heading attrs
    // omitted only the outermost `}`. The block parser consequently treated
    // attrs as null and re-serialization dropped the declared top margin.
    $markup = '<!-- wp:heading {"textAlign":"center","level":2,"fontFamily":"heading","fontSize":"section-title","style":{"typography":{"fontWeight":"400"},"spacing":{"margin":{"top":"var:preset|spacing|sm"}}} -->'
        . '<h2 class="wp-block-heading has-text-align-center has-heading-font-family has-section-title-font-size" style="margin-top:var(--wp--preset--spacing--sm);font-weight:400">Title</h2>'
        . '<!-- /wp:heading -->';

    $result = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);

    assert_contains('"top":"var:preset|spacing|sm"}}}} -->', $result['markup']);
    assert_contains('omitted their final root closer', implode("\n", $result['notes']));
    assert_eq([], LayoutFixer::fix($result['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer refuses a stray-closer repair with several distinct valid readings', function () {
    // The tbilisi24 hours-contact payload, verbatim: three different brace
    // deletions parse — border/padding as siblings of spacing, padding inside
    // border, or everything inside spacing. Guessing wrong would silently ship
    // ignored attributes (PR #109 review, finding 4), so nothing is touched
    // and the fix-blocks gate rejects the build loudly instead.
    $markup = '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|sm"},"border":{"top":{"color":"var:preset|color|secondary","width":"1px"}},"padding":{"top":"var:preset|spacing|sm"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);

    // Reviewer's heading example: deleting one brace hoists lineHeight to the
    // ignored style.lineHeight, deleting another restores typography.lineHeight
    // — two valid readings, so no repair.
    $heading = '<!-- wp:heading {"style":{"typography":{"fontSize":"2rem"},"lineHeight":"1.2"}},"level":2} -->'
        . '<h2 class="wp-block-heading">T</h2><!-- /wp:heading -->';
    $r = LayoutFixer::fix($heading, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($heading, $r['markup']);
});

test('layout fixer deep-merges duplicate same-depth comment JSON keys and stays idempotent', function () {
    // naturaleza (BIGR-719): a duplicate "style" key in valid JSON. Every
    // json_decode downstream keeps only the last duplicate, so the spacing
    // member would vanish while the HTML still carries margin-top:0 — and if
    // this fixer dirtied the node, its own re-render would bake that loss in.
    $markup = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"0"}}},'
        . '"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}}} -->'
        . '<p style="margin-top:0"><a href="mailto:reservas@naturalezasabia.com.ar">'
        . 'reservas@naturalezasabia.com.ar</a></p><!-- /wp:paragraph -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains(
        '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"0"}},'
            . '"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}}} -->',
        $r['markup']
    );
    assert_contains('<p style="margin-top:0">', $r['markup'], 'authored HTML is untouched');
    assert_eq(1, count($r['notes']));
    assert_contains('wp:paragraph attributes declared "style" more than once', $r['notes'][0]);
    assert_contains('deep-merged', $r['notes'][0]);

    // Idempotent, so the same pass doubles as a dry-run linter.
    $again = LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $again['notes']);
    assert_eq($r['markup'], $again['markup']);

    // A scalar conflict resolves last-wins — the same outcome json_decode
    // gave — while earlier non-conflicting members now survive a re-render
    // of the dirtied node instead of being silently deleted.
    $group = '<!-- wp:group {"align":"wide","align":"full","style":{"spacing":{"padding":{"top":"1rem"}}},'
        . '"style":{"color":{"background":"#123456"}}} -->'
        . '<div class="wp-block-group alignfull"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($group, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"align":"full"', $r['markup']);
    assert_true(!str_contains($r['markup'], '"align":"wide"'), 'conflicting scalar keeps the last declaration');
    assert_contains('"padding":{"top":"1rem"}', $r['markup']);
    assert_contains('"background":"#123456"', $r['markup']);
    assert_contains('"layout":{"type":"constrained"}', $r['markup'], 'later rules run on the merged attributes');
});

test('layout fixer merges escaped-equivalent duplicate keys before later rules dirty the node', function () {
    $markup = '<!-- wp:group {"style":{"elements":{"link":{"color":{"text":"var:preset|color|accent"}}}},'
        . '"\u0073tyle":{"color":{"background":"#123456"}},'
        . '"layout":{"type":"flex","justifyContent":"flex-start"}} -->'
        . '<div class="wp-block-group has-background has-link-color" style="background-color:#123456">'
        . '<p><a href="#">Link</a></p></div><!-- /wp:group -->';

    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains(
        '"style":{"elements":{"link":{"color":{"text":"var:preset|color|accent"}}},'
            . '"color":{"background":"#123456"}}',
        $r['markup'],
        'the earlier style member must survive the later layout rewrite',
    );
    assert_contains('"justifyContent":"left"', $r['markup']);
    assert_contains('attributes declared "style" more than once', implode("\n", $r['notes']));
    assert_contains('mapped it to Gutenberg', implode("\n", $r['notes']));

    $again = LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $again['notes']);
    assert_eq($r['markup'], $again['markup']);
});

test('layout fixer rejects stray-closer repairs that create duplicate keys', function () {
    // Deleting the stray closer here merges two "style" members into one
    // object; json_decode keeps only the last, silently losing the background
    // (PR #109 review, finding 5). Such candidates never count as valid.
    foreach (['"style"', '"\u0073tyle"'] as $duplicateKey) {
        $markup = '<!-- wp:group {"style":{"color":{"background":"#000"}}},'
            . $duplicateKey
            . ':{"spacing":{"padding":{"top":"1rem"}}}} -->'
            . '<div class="wp-block-group"></div><!-- /wp:group -->';
        $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
        assert_eq([], $r['notes']);
        assert_eq($markup, $r['markup']);
    }
});

test('layout fixer repairs an opener independently of an earlier unterminated one', function () {
    // The attrs scan is bounded at "-->": an unterminated attrs object no
    // longer swallows the following comments, so the later opener repairs on
    // its own (PR #109 review, finding 6).
    $markup = '<!-- wp:paragraph {"align":"left" --><p>x</p><!-- /wp:paragraph -->'
        . '<!-- wp:group {"align":"full"}} --><div class="wp-block-group alignfull"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('wp:group {"align":"full"} -->', $r['markup']);
    assert_contains('wp:paragraph {"align":"left" -->', $r['markup']);
});

test('layout fixer does not mirror spacing from a bare child of a wrapperless block', function () {
    // The first element after the opener is a content <p>, not the group's
    // wrapper. Mirroring its margin into the group would make Gutenberg
    // regenerate the group as an empty styled div and delete the copy
    // (PR #109 review, finding 2).
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<p style="margin-top:3rem">Copy</p><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer refuses to mirror values resolved through wrapper-local custom properties', function () {
    // Re-serialization drops the --offset definition; mirroring the reference
    // would ship margin-top:var(--offset) with nothing to resolve it and the
    // gate would never see the loss (PR #109 review, finding 3). Global
    // --wp--preset-- variables survive serialization and still mirror.
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="--offset:3rem;margin-top:var(--offset)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);

    $gap = '<!-- wp:columns -->'
        . '<div class="wp-block-columns" style="--g:2rem;gap:var(--g)">' . lf_column() . '</div><!-- /wp:columns -->';
    $r = LayoutFixer::fix($gap, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($gap, $r['markup']);
});

test('layout fixer leaves attribute JSON alone when no single-closer repair makes it parse', function () {
    $markup = '<!-- wp:group {"align":} --><div class="wp-block-group"></div><!-- /wp:group -->'
        . '<!-- wp:group {"style":{"a":1}},"b":{c}} --><div class="wp-block-group"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer mirror-copies HTML-only vertical spacing into style.spacing', function () {
    $markup = '<!-- wp:group {"align":"full","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignfull" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">'
        . '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group" style="margin-top:3rem;padding-top:var(--wp--preset--spacing--sm)"></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"margin":{"top":"3rem"},"padding":{"top":"var:preset|spacing|sm"}}}}', $r['markup']);
    // The root's owned declarations were already mirrored by SectionRhythm.
    assert_contains('wp:group {"align":"full","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"layout":{"type":"constrained"}}', $r['markup']);
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer moves an HTML-only gap into style.spacing.blockGap and deletes the inline copy', function () {
    // tbilisi31/naturaleza32: columns declared gap only inline — Gutenberg
    // never re-emits gap (blockGap renders via the layout stylesheet), so the
    // declaration was reported dropped and the rhythm gate failed the build.
    $markup = '<!-- wp:columns {"align":"wide"} -->'
        . '<div class="wp-block-columns alignwide are-vertically-aligned-center" style="gap:var(--wp--preset--spacing--lg)">'
        . lf_column()
        . '</div><!-- /wp:columns -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('wp:columns {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|lg"}}}', $r['markup']);
    assert_true(!str_contains($r['markup'], 'gap:var('), 'inline gap declaration should be deleted');
    assert_contains('class="wp-block-columns alignwide are-vertically-aligned-center"', $r['markup']);
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer deletes an inline gap duplicating the declared blockGap', function () {
    $markup = '<!-- wp:columns {"style":{"spacing":{"blockGap":"var:preset|spacing|lg"}}} -->'
        . '<div class="wp-block-columns" style="gap:var(--wp--preset--spacing--lg);margin-top:0">'
        . lf_column()
        . '</div><!-- /wp:columns -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_true(!str_contains($r['markup'], 'gap:var('), 'redundant inline gap should be deleted');
    assert_contains('style="margin-top:0"', $r['markup']);
    assert_contains('"blockGap":"var:preset|spacing|lg"', $r['markup']);
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer leaves a gap that disagrees with the declared blockGap for the gate', function () {
    $markup = '<!-- wp:columns {"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}}} -->'
        . '<div class="wp-block-columns" style="gap:var(--wp--preset--spacing--xl)">'
        . lf_column()
        . '</div><!-- /wp:columns -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer maps row/column gap longhands and two-value gap onto blockGap sides', function () {
    $markup = '<!-- wp:group {"layout":{"type":"flex"}} -->'
        . '<div class="wp-block-group" style="row-gap:2rem;column-gap:var(--wp--preset--spacing--md)"></div><!-- /wp:group -->'
        . '<!-- wp:columns --><div class="wp-block-columns" style="gap:1rem 2rem">' . lf_column() . '</div><!-- /wp:columns -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"blockGap":{"top":"2rem","left":"var:preset|spacing|md"}', $r['markup']);
    assert_contains('"blockGap":{"top":"1rem","left":"2rem"}', $r['markup']);
    assert_true(!str_contains($r['markup'], '-gap:'), 'inline gap longhands should be deleted');
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
});

test('layout fixer only trusts a gap-bearing wrapper carrying the block own class', function () {
    // A bare child element must not donate its gap to the enclosing block.
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<ul style="gap:2rem"><li>item</li></ul><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer leaves gap values blockGap cannot express for the gate', function () {
    $markup = '<!-- wp:columns -->'
        . '<div class="wp-block-columns" style="gap:min(2rem, 5vw)">' . lf_column() . '</div><!-- /wp:columns -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

// ── Bare-slug spacing repair ─────────────────────────────────────────────

test('layout fixer rewrites a bare preset slug the HTML confirms into var:preset syntax', function () {
    // jolly-lagoon daily-specials cards: the model wrote "top":"sm" in the
    // attributes but the correct var() in the HTML. save() renders the bare
    // slug as literal `padding-top:sm`, so re-serialization dropped the real
    // CSS and the rhythm gate rejected the build.
    $markup = '<!-- wp:group {"className":"hover-lift","style":{"spacing":{"padding":{"top":"sm","bottom":"md","left":"sm","right":"sm"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hover-lift" style="padding-top:var(--wp--preset--spacing--sm);padding-right:var(--wp--preset--spacing--sm);padding-bottom:var(--wp--preset--spacing--md);padding-left:var(--wp--preset--spacing--sm)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"top":"var:preset|spacing|sm"', $r['markup']);
    assert_contains('"bottom":"var:preset|spacing|md"', $r['markup']);
    assert_contains('"left":"var:preset|spacing|sm"', $r['markup']);
    assert_contains('"right":"var:preset|spacing|sm"', $r['markup']);
    assert_true($r['notes'] !== [], 'expected a note');
});

test('layout fixer rewrites bare slugs on any block when the theme spacing scale defines them', function () {
    // calm-island cta-closing: paragraphs and separators (no wrapper-classed
    // container to trust) carried bare-slug margins; save() rendered
    // `margin-top:sm`, validation failed, re-serialization dropped the CSS.
    $scale = ['sm', 'md', 'lg', 'xl', 'xxl'];
    $markup = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"sm","bottom":"md"}}}} -->'
        . '<p class="has-text-align-center" style="margin-top:var(--wp--preset--spacing--sm);margin-bottom:var(--wp--preset--spacing--md)">Order online.</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:separator {"style":{"spacing":{"margin":{"top":"md","bottom":"0"}}}} -->'
        . '<hr class="wp-block-separator is-style-wide" style="margin-top:var(--wp--preset--spacing--md);margin-bottom:0"/>'
        . '<!-- /wp:separator -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0, $scale);
    assert_contains('"margin":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|md"}', $r['markup']);
    assert_contains('"margin":{"top":"var:preset|spacing|md","bottom":"0"}', $r['markup']);
    assert_true(!str_contains($r['markup'], '"top":"sm"'), 'bare slugs should be rewritten');
});

test('layout fixer rewrites a scale-confirmed bare slug even without inline HTML evidence', function () {
    $markup = '<!-- wp:heading {"style":{"spacing":{"margin":{"top":"lg"}}}} -->'
        . '<h2 class="wp-block-heading">Fresh daily</h2><!-- /wp:heading -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0, ['sm', 'md', 'lg']);
    assert_contains('"top":"var:preset|spacing|lg"', $r['markup']);
});

test('layout fixer rewrites a bare-slug blockGap the theme scale defines', function () {
    $markup = '<!-- wp:group {"style":{"spacing":{"blockGap":"sm"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0, ['sm', 'md']);
    assert_contains('"blockGap":"var:preset|spacing|sm"', $r['markup']);
});

test('layout fixer leaves bare values the scale does not define and valid CSS alone', function () {
    $markup = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"tight","bottom":"0"}}}} -->'
        . '<p>Copy</p><!-- /wp:paragraph -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0, ['sm', 'md']);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer leaves a bare slug alone when the HTML does not confirm it', function () {
    // No scale, no matching var() declaration: ambiguous, the gate judges it.
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"sm"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--lg)"></div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

// ── Dynamic chrome block spacing (site-title / site-tagline / site-logo) ──

test('layout fixer deletes doomed inline spacing a dynamic chrome block already carries in attributes', function () {
    // mellow-meadow footer: the model expanded site-title (a dynamic block —
    // empty save()) into an h2 whose inline margin duplicated the attribute.
    // Re-serialization deletes the h2, the textual drop-detector counted the
    // inline copy as lost rhythm, and the gate failed the build even though
    // block supports render the margin from the attribute at runtime.
    $markup = '<!-- wp:site-title {"level":2,"style":{"spacing":{"margin":{"top":"var:preset|spacing|sm"}}}} -->'
        . '<h2 class="wp-block-site-title" style="margin-top:var(--wp--preset--spacing--sm)"><a href="/">Hearth &amp; Crumb</a></h2>'
        . '<!-- /wp:site-title -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_true(!str_contains($r['markup'], 'margin-top:var(--wp--preset--spacing--sm)'), 'doomed inline margin should be deleted');
    assert_contains('"margin":{"top":"var:preset|spacing|sm"}', $r['markup']);
    assert_true($r['notes'] !== [], 'expected a note');
});

test('layout fixer adopts HTML-only spacing on a dynamic chrome block into its attributes', function () {
    $markup = '<!-- wp:site-title -->'
        . '<h2 class="wp-block-site-title" style="margin-top:var(--wp--preset--spacing--sm);letter-spacing:2px"><a href="/">X</a></h2>'
        . '<!-- /wp:site-title -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_contains('"margin":{"top":"var:preset|spacing|sm"}', $r['markup']);
    assert_true(!str_contains($r['markup'], 'margin-top:'), 'adopted inline margin should be deleted');
    assert_contains('letter-spacing:2px', $r['markup']);
});

test('layout fixer leaves a dynamic chrome block whose attribute disagrees with its inline spacing', function () {
    $markup = '<!-- wp:site-title {"style":{"spacing":{"margin":{"top":"var:preset|spacing|lg"}}}} -->'
        . '<h2 class="wp-block-site-title" style="margin-top:var(--wp--preset--spacing--sm)"><a href="/">X</a></h2>'
        . '<!-- /wp:site-title -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_FOOTER, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});

test('layout fixer does not mirror-copy over a declared attribute or into non-container blocks', function () {
    // Conflicting attribute: declared value stays authoritative and the gate
    // keeps judging the mismatch. Paragraphs are not rhythm containers here.
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|md"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--sm)">'
        . '<!-- wp:paragraph --><p style="margin-top:3rem">Copy</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_eq([], $r['notes']);
    assert_eq($markup, $r['markup']);
});
