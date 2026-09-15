<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\ContentOnlyGuard;

/**
 * A bundle shaped the way export-bundle will produce one.
 *
 * @param list<array<string, mixed>> $pages
 * @return array<string, mixed>
 */
function guard_bundle(array $pages): array
{
    return ['pages' => $pages];
}

test('a bundle built from approved patterns and theme presets passes', function () {
    $bundle = guard_bundle([
        [
            'slug' => 'home',
            'sections' => [['pattern' => 'twentytwentyfive/banner-cover-big-heading']],
            'content' => '<!-- wp:group {"align":"full"} -->'
                . '<div class="wp-block-group alignfull has-accent-1-background-color has-background">'
                . '<!-- wp:heading --><h2 class="wp-block-heading">Hello</h2><!-- /wp:heading -->'
                . '</div><!-- /wp:group -->',
        ],
    ]);

    assert_eq([], ContentOnlyGuard::check($bundle, ['twentytwentyfive/banner-cover-big-heading']));
});

/**
 * The failure this guard exists for. The markup is entirely core blocks and
 * still unusable, because its layout lives in classes only the build's own
 * stylesheet defines — which a content-only host does not ship.
 */
test('classes the destination theme does not define are reported with their weight', function () {
    $bundle = guard_bundle([
        [
            'slug' => 'home',
            'sections' => [['pattern' => 'theme/hero']],
            'content' => '<div class="wp-block-group card-style--framed">'
                . '<div class="card-media"></div><div class="card-media"></div>'
                . '</div>',
        ],
    ]);

    $violations = ContentOnlyGuard::check($bundle, ['theme/hero']);

    assert_eq(1, count($violations));
    assert_contains('3 reference(s) to 2 class(es)', $violations[0]);
    assert_contains('card-media', $violations[0]);
});

test('classes the destination theme does define are accepted', function () {
    $bundle = guard_bundle([
        [
            'slug' => 'home',
            'sections' => [['pattern' => 'theme/hero']],
            'content' => '<div class="wp-block-group card-media"></div>',
        ],
    ]);

    assert_eq([], ContentOnlyGuard::check($bundle, ['theme/hero'], ['classes' => ['card-media']]));
});

test('a block outside core is reported, since the host cannot install it', function () {
    $bundle = guard_bundle([
        [
            'slug' => 'home',
            'sections' => [['pattern' => 'theme/hero']],
            'content' => '<!-- wp:generateblocks/container --><div></div><!-- /wp:generateblocks/container -->',
        ],
    ]);

    $violations = ContentOnlyGuard::check($bundle, ['theme/hero']);

    assert_eq(1, count($violations));
    assert_contains('generateblocks/container', $violations[0]);
});

/**
 * Bare block names are core in serialized markup, so they must not be flagged.
 */
test('core blocks written without a namespace are not mistaken for foreign ones', function () {
    $bundle = guard_bundle([
        [
            'slug' => 'home',
            'sections' => [['pattern' => 'theme/hero']],
            'content' => '<!-- wp:group --><div><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        ],
    ]);

    assert_eq([], ContentOnlyGuard::check($bundle, ['theme/hero']));
});

test('a pattern outside the approved inventory is rejected', function () {
    $bundle = guard_bundle([
        [
            'slug' => 'home',
            'sections' => [['pattern' => 'dotcompatterns/unapproved-hero']],
            'content' => '<!-- wp:group --><div></div><!-- /wp:group -->',
        ],
    ]);

    $violations = ContentOnlyGuard::check($bundle, ['theme/hero']);

    assert_eq(1, count($violations));
    assert_contains('dotcompatterns/unapproved-hero', $violations[0]);
});

/**
 * Fail closed. "We did not record where this came from" is not evidence that
 * it came from somewhere allowed.
 */
test('a section with no recorded pattern counts as unapproved', function () {
    $bundle = guard_bundle([
        [
            'slug' => 'home',
            'sections' => [['intent' => 'Hero']],
            'content' => '<!-- wp:group --><div></div><!-- /wp:group -->',
        ],
    ]);

    $violations = ContentOnlyGuard::check($bundle, ['theme/hero']);

    assert_eq(1, count($violations));
    assert_contains('(unrecorded)', $violations[0]);
});


/**
 * Two of core's own classes fall outside the prefixes core mostly uses:
 * columns write `are-vertically-aligned-*`, and an image block writes
 * `wp-image-<id>`. Both were reported as classes the theme does not define,
 * which refused a bundle made of nothing but core blocks from a theme's own
 * patterns.
 */
test('core classes outside the usual prefixes are not mistaken for theme ones', function () {
    $bundle = ['pages' => [['slug' => 'home', 'sections' => [], 'content' =>
        '<!-- wp:columns {"verticalAlignment":"center"} --><div class="wp-block-columns are-vertically-aligned-center">'
        . '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img class="wp-image-5" src="/wp-content/themes/t/a.webp"/></figure><!-- /wp:image -->'
        . '</div><!-- /wp:columns -->']]];

    assert_eq([], ContentOnlyGuard::unbackedClasses($bundle, []));
});
