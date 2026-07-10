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

test('layout fixer is idempotent on everything it fixes', function () {
    $fixtures = [
        [LayoutFixer::ROLE_SECTION, '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">' . lf_columns(2, '{"align":"wide"}') . '</div><!-- /wp:group -->'],
        [LayoutFixer::ROLE_FOOTER, '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull"><!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . lf_columns(3) . '</div><!-- /wp:group --></div><!-- /wp:group -->'],
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
