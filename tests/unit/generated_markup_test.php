<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Units\GeneratedMarkup;

test('generated markup structurally repairs known fatal block attributes', function () {
    $markup = '<!-- wp:group {"align":"wide","style":{"background":{"backgroundImage":{"url":"theme:./assets/frieze.png","source":"file"},"backgroundRepeat":"repeat-x"},"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch","verticalAlignment":"space-between"},"metadata":{"source":"catalog"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"var:preset|spacing|xs"}}},"layout":{"justifyContent":"stretch","verticalAlignment":"space-between"}} -->'
        . '<p>Keep me</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

    $out = GeneratedMarkup::normalizeBlockAttributes($markup);
    $blocks = BlockMarkup::parse($out);
    $group = $blocks->attrs(0);
    $paragraph = $blocks->attrs(1);

    assert_eq('theme:./assets/frieze.png', $group['style']['background']['backgroundImage']['url']);
    assert_true(
        !array_key_exists('source', $group['style']['background']['backgroundImage']),
        'unsupported background-image source removed'
    );
    assert_eq('catalog', $group['metadata']['source'], 'unrelated source fields survive');
    assert_eq('var:preset|spacing|sm', $group['style']['spacing']['blockGap']);
    assert_eq('var:preset|spacing|sm', $paragraph['style']['spacing']['margin']['top']);
    assert_eq('flex', $group['layout']['type']);
    assert_eq('vertical', $group['layout']['orientation']);
    assert_true(!array_key_exists('justifyContent', $group['layout']), 'stretch removed from core/group');
    assert_true(!array_key_exists('verticalAlignment', $group['layout']), 'space-between alignment removed');
    assert_eq(
        'stretch',
        $paragraph['layout']['justifyContent'],
        'justifyContent repair is restricted to core/group'
    );
    assert_true(
        !array_key_exists('verticalAlignment', $paragraph['layout']),
        'invalid layout verticalAlignment is removed from every block'
    );
    assert_contains('<p>Keep me</p>', $out, 'saved HTML is untouched');
});

test('generated markup attribute repair preserves healthy markup byte-for-byte', function () {
    $markup = "<!-- wp:group  {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"space-between\",\"verticalAlignment\":\"top\"},\"style\":{\"background\":{\"backgroundImage\":{\"url\":\"https://example.test/a.jpg\"}}}}   -->\n"
        . '<div class="wp-block-group"><p>Literal var:preset|spacing|xs stays in prose.</p></div>'
        . "\n<!-- /wp:group -->";

    assert_eq($markup, GeneratedMarkup::normalizeBlockAttributes($markup));
});

test('generated markup repair changes only affected opening comments and is idempotent', function () {
    $healthy = '<!-- wp:paragraph  {"content":"Spacing xs is prose","dropCap":false} /-->';
    $markup = '<!-- wp:group {"layout":{"type":"flex","justifyContent":"stretch"}} -->'
        . '<div class="wp-block-group">Body bytes</div><!-- /wp:group -->'
        . $healthy;

    $once = GeneratedMarkup::normalizeBlockAttributes($markup);
    $twice = GeneratedMarkup::normalizeBlockAttributes($once);

    assert_contains('<div class="wp-block-group">Body bytes</div>', $once);
    assert_contains($healthy, $once, 'unaffected sibling comment retains its original spacing');
    assert_eq($once, $twice, 'attribute repair reaches a fixed point');
});

test('generated markup recognizes an explicitly namespaced core group', function () {
    $out = GeneratedMarkup::normalizeBlockAttributes(
        '<!-- wp:core/group {"layout":{"type":"flex","justifyContent":"stretch"}} /-->'
    );

    assert_contains('<!-- wp:core/group {"layout":{"type":"flex"}} /-->', $out);
});

test('generated markup canonicalizes deterministic flex aliases before semantic validation', function () {
    foreach (['flex-start' => 'left', 'flex-end' => 'right'] as $authored => $canonical) {
        $markup = '<!-- wp:group {"layout":{"type":"flex","justifyContent":"' . $authored . '"}} -->'
            . '<div class="wp-block-group"></div><!-- /wp:group -->';

        $normalized = GeneratedMarkup::normalize($markup, "flex-{$authored}");
        $blocks = BlockMarkup::parse($normalized);

        assert_eq($canonical, $blocks->attrs(0)['layout']['justifyContent']);
        assert_eq(
            $normalized,
            GeneratedMarkup::validate($normalized, "flex-{$authored}", ['version' => 3]),
            'a losslessly repairable alias does not trigger the stochastic markup-repair path',
        );
    }
});

test('generated markup hoists unambiguous group layout and border nesting mistakes', function () {
    // The pulso failure shape: a mechanically completed root object left
    // layout under style and border under style.spacing.
    $markup = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|xs","left":"var:preset|spacing|sm"},"border":{"bottom":{"width":"1px","color":"#3CFF6E"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}}} -->'
        . '<div class="wp-block-group">Terminal map</div><!-- /wp:group -->';

    $out = GeneratedMarkup::normalizeBlockAttributes($markup);
    $blocks = BlockMarkup::parse($out);
    $attrs = $blocks->attrs(0);

    assert_eq(
        ['type' => 'flex', 'flexWrap' => 'nowrap', 'justifyContent' => 'space-between'],
        $attrs['layout']
    );
    assert_true(!array_key_exists('layout', $attrs['style']), 'misnested layout removed');
    assert_eq(
        ['bottom' => ['width' => '1px', 'color' => '#3CFF6E']],
        $attrs['style']['border']
    );
    assert_true(
        !array_key_exists('border', $attrs['style']['spacing']),
        'misnested border removed'
    );
    assert_eq('var:preset|spacing|sm', $attrs['style']['spacing']['padding']['top']);
    assert_eq('var:preset|spacing|sm', $attrs['style']['spacing']['padding']['left']);
    assert_contains('<div class="wp-block-group">Terminal map</div>', $out);
});

test('generated markup leaves ambiguous or non-object hoist candidates fail-closed', function () {
    $collisions = '<!-- wp:group {"style":{"border":{"width":"2px"},"spacing":{"border":{"bottom":{"width":"1px"}}},"layout":{"type":"flex"}},"layout":{"type":"constrained"}} /-->';
    $scalars = '<!-- wp:group {"style":{"spacing":{"border":"1px"},"layout":"flex"}} /-->';
    $nonGroup = '<!-- wp:paragraph {"style":{"spacing":{"border":{"width":"1px"}},"layout":{"type":"flex"}}} /-->';

    assert_eq($collisions, GeneratedMarkup::normalizeBlockAttributes($collisions));
    assert_eq($scalars, GeneratedMarkup::normalizeBlockAttributes($scalars));
    assert_eq(
        $nonGroup,
        GeneratedMarkup::normalizeBlockAttributes($nonGroup),
        'the targeted structural repair is restricted to core/group'
    );
});

test('generated markup intake maps malformed xs preset references after delimiter repair', function () {
    $out = GeneratedMarkup::normalize(
        '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset--spacing--xs"}}} -->'
        . '<div class="wp-block-group" '
        . 'data-style="var(--wp--preset--spacing--xs)" '
        . 'aria-label="Literal > style=\'var(--wp--preset--spacing--xs)\'" '
        . 'style="gap:var(--wp--preset--spacing--xs)">'
        . '<p>Literal var(--wp--preset--spacing--xs) stays in prose.</p>'
        . '<style>.example{padding:var(--wp--preset--spacing--xs)}</style>'
        . '</div>'
        . '<!-- /wp:group -->',
        'footer'
    );

    assert_contains('"blockGap":"var:preset|spacing|sm"', $out);
    assert_true(!str_contains($out, 'spacing|xs'), 'undeclared xs slug is gone');
    assert_contains('var(--wp--preset--spacing--sm)', $out, 'saved CSS uses the same canonical alias');
    assert_contains(
        '<p>Literal var(--wp--preset--spacing--xs) stays in prose.</p>',
        $out,
        'literal user-facing text is not rewritten',
    );
    assert_contains(
        'data-style="var(--wp--preset--spacing--xs)"',
        $out,
        'similarly named data attributes are not CSS contexts',
    );
    assert_contains(
        'aria-label="Literal > style=\'var(--wp--preset--spacing--xs)\'"',
        $out,
        'style-like text inside another quoted attribute is not rewritten',
    );
    assert_contains(
        '<style>.example{padding:var(--wp--preset--spacing--sm)}</style>',
        $out,
        'raw style element content is normalized as CSS',
    );
});

test('generated markup leaves malformed comments in place while repairing later blocks', function () {
    $broken = '<!-- wp:group {"layout":{"justifyContent":"stretch"} -->'
        . '<div>Malformed but retained</div><!-- /wp:group -->';
    $healthy = '<!-- wp:group {"layout":{"type":"flex","verticalAlignment":"space-between"}} -->'
        . '<div>Healthy block</div><!-- /wp:group -->';

    $out = GeneratedMarkup::normalizeBlockAttributes($broken . $healthy);

    assert_contains($broken, $out, 'unparseable comment is not swallowed or rewritten');
    assert_contains('{"layout":{"type":"flex"}}', $out, 'later valid block is still repaired');
    assert_contains('<div>Healthy block</div>', $out);
});

test('generated markup semantic validation does not retain serializer output', function () {
    $markup = '<!-- wp:cover {"url":"theme:./assets/hero.jpg"} --><div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" src="theme:./assets/hero.jpg" '
        . 'alt="AI_IMAGE: A bakery at dawn | full-bleed hero | photorealistic | landscape"/>'
        . '</div><!-- /wp:cover -->';

    $validated = GeneratedMarkup::validate($markup, 'page-home--hero', ['version' => 3]);

    assert_eq($markup, $validated, 'validation returns the normalized intake bytes, not re-serialized HTML');
    assert_contains('AI_IMAGE: A bakery at dawn', $validated, 'cover image evidence survives until collection');
});

test('generated markup validates presets against ephemeral re-serialized HTML', function () {
    $normalized = GeneratedMarkup::normalize(
        '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm"}}}} -->'
        . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--missing)"></div>'
        . '<!-- /wp:group -->',
        'page-home--spacing'
    );
    $theme = ['settings' => ['spacing' => ['spacingSizes' => [
        ['slug' => 'sm', 'name' => 'Small', 'size' => '1rem'],
    ]]]];

    $validated = GeneratedMarkup::validate($normalized, 'page-home--spacing', $theme);

    assert_contains('var:preset|spacing|sm', $validated, 'normalized comment uses the declared token');
    assert_contains(
        'var(--wp--preset--spacing--missing)',
        $validated,
        'validation does not retain the serializer copy that re-syncs stale HTML'
    );
});

test('generated markup semantic validation reports block and preset errors together', function () {
    $markup = '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|missing"}},'
        . '"layout":{"type":"flex","justifyContent":"diagonal"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $error = null;

    try {
        GeneratedMarkup::validate($markup, 'page-home--bad', ['version' => 3]);
    } catch (RuntimeException $caught) {
        $error = $caught;
    }

    assert_true($error instanceof RuntimeException);
    assert_contains("part 'page-home--bad' failed semantic validation", $error->getMessage());
    assert_contains('preset spacing slug "missing" is not declared', $error->getMessage());
    assert_contains("layout value 'diagonal'", $error->getMessage());
});
