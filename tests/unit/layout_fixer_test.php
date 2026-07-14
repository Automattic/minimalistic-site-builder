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

test('layout fixer repairs attribute JSON whose object closes early', function () {
    // tbilisi24 hours-contact inner cards, verbatim shape: one stray `}` after
    // "padding" closes the attrs object early — json_decode fails and the Node
    // fixer erased EVERY attribute, fatally dropping padding-top.
    $markup = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull">'
        . '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|sm"},"border":{"top":{"color":"var:preset|color|secondary","width":"1px"}},"padding":{"top":"var:preset|spacing|sm"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--secondary);border-top-width:1px;padding-top:var(--wp--preset--spacing--sm)"></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = LayoutFixer::fix($markup, LayoutFixer::ROLE_SECTION, 860.0);
    assert_contains('"spacing":{"blockGap":"var:preset|spacing|sm","padding":{"top":"var:preset|spacing|sm"}}', $r['markup']);
    assert_contains('"border":{"top":{"color":"var:preset|color|secondary","width":"1px"}}', $r['markup']);
    assert_eq(2, substr_count($r['markup'], '"layout":{"type":"constrained"}'));
    assert_eq([], LayoutFixer::fix($r['markup'], LayoutFixer::ROLE_SECTION, 860.0)['notes']);
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
