<?php
declare(strict_types=1);

use Automattic\SiteBuild\DirectionFidelity;

/** A page-plan-shaped direction with the fields each audit reads. */
function fidelity_direction(array $overrides = []): array
{
    return $overrides + [
        'description' => 'A neighborhood bakery.',
        'canvas' => 'full-bleed',
        'card_style' => 'flush',
        'motion' => 'calm',
        'type' => [
            'heading' => ['family' => 'Oswald', 'weights' => [700], 'italic' => false, 'axes' => [], 'character' => ''],
            'body' => ['family' => 'Inter', 'weights' => [400], 'italic' => false, 'axes' => [], 'character' => ''],
        ],
    ];
}

function fidelity_theme(array $elements = []): array
{
    return [
        'settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => '"Oswald", sans-serif'],
            ['slug' => 'body', 'fontFamily' => '"Inter", sans-serif'],
        ]]],
        'styles' => ['elements' => $elements],
    ];
}

test('a heading wired to the body family is caught', function () {
    // The old check asked whether theme.json declared a heading slug, which a
    // heading rendering with the body family answers yes to.
    $problems = DirectionFidelity::typeProblems(
        fidelity_direction(),
        fidelity_theme(['h2' => ['typography' => ['fontFamily' => 'var:preset|font-family|body']]]),
    );
    assert_eq(1, count($problems));
    assert_contains('styles.elements.h2', $problems[0]);
    assert_contains('Oswald', $problems[0]);
    assert_contains('Inter', $problems[0]);
});

test('headings left to inherit the scaffold wiring are not accused', function () {
    assert_eq([], DirectionFidelity::typeProblems(fidelity_direction(), fidelity_theme()));
    assert_eq([], DirectionFidelity::typeProblems(
        fidelity_direction(),
        fidelity_theme(['h2' => ['typography' => ['fontFamily' => 'var:preset|font-family|heading']]]),
    ));
});

test('a site title on the body family is caught too', function () {
    $theme = fidelity_theme();
    $theme['styles']['blocks']['core/site-title']['typography']['fontFamily'] =
        'var(--wp--preset--font-family--body)';
    $problems = DirectionFidelity::typeProblems(fidelity_direction(), $theme);
    assert_eq(1, count($problems));
    assert_contains('core/site-title', $problems[0]);
});

test('a full-bleed hero never trips the framed-canvas check', function () {
    // The hero runs edge-to-edge on every canvas; the mat starts at the
    // second band. The old check split on a hero class name and accused a
    // perfectly good hero when it did not match.
    $markup = '<!-- wp:cover {"align":"full"} --><div class="wp-block-cover alignfull">'
        . '<!-- wp:heading --><h1>Bread</h1><!-- /wp:heading --></div><!-- /wp:cover -->'
        . '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Daily.</p>'
        . '<!-- /wp:paragraph --></div><!-- /wp:group -->';
    assert_eq([], DirectionFidelity::canvasProblems(
        fidelity_direction(['canvas' => 'framed']),
        $markup,
        'plugin/pages/home.html',
    ));
});

test('a full-bleed band below the hero breaks the framed mat', function () {
    // "align":"full" is what the serializer emits; the repair this replaces
    // looked for a className containing alignfull, which production never
    // writes, so it could not fire at all.
    $markup = '<!-- wp:cover --><div class="wp-block-cover"><!-- wp:heading --><h1>Bread</h1>'
        . '<!-- /wp:heading --></div><!-- /wp:cover -->'
        . '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">'
        . '<!-- wp:paragraph --><p>Daily.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $problems = DirectionFidelity::canvasProblems(
        fidelity_direction(['canvas' => 'framed']),
        $markup,
        'plugin/pages/home.html',
    );
    assert_eq(1, count($problems));
    assert_contains('plugin/pages/home.html', $problems[0]);
    assert_contains('group', $problems[0]);
});

test('a full-bleed canvas is never accused of breaking a mat it never promised', function () {
    $markup = '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">'
        . '<!-- wp:paragraph --><p>Daily.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    assert_eq([], DirectionFidelity::canvasProblems(fidelity_direction(), $markup, 'plugin/pages/home.html'));
});

test('a motion class printed inside a code sample is not a placed class', function () {
    // The pass this replaces matched raw bytes, so displayed sample code read
    // as real motion — and it missed single-quoted class attributes.
    $sample = '<!-- wp:code --><pre class="wp-block-code"><code>'
        . 'class="reveal-up"</code></pre><!-- /wp:code -->';
    $problems = DirectionFidelity::motionProblems(fidelity_direction(), $sample, 'plugin/pages/home.html');
    assert_eq(1, count($problems), 'a printed class name does not satisfy the promise');

    $single = "<!-- wp:group --><div class='wp-block-group reveal-up'>"
        . '<!-- wp:paragraph --><p>Daily.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    assert_eq([], DirectionFidelity::motionProblems(fidelity_direction(), $single, 'plugin/pages/home.html'),
        'a single-quoted class attribute counts');
});

test('a real motion class satisfies the profile promise', function () {
    $markup = '<!-- wp:group {"className":"reveal-up"} --><div class="wp-block-group reveal-up">'
        . '<!-- wp:paragraph --><p>Daily.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    assert_eq([], DirectionFidelity::motionProblems(fidelity_direction(), $markup, 'plugin/pages/home.html'));
});

test('a static profile is never asked to prove movement', function () {
    $markup = '<!-- wp:paragraph --><p>Daily.</p><!-- /wp:paragraph -->';
    foreach (['none', 'minimal'] as $profile) {
        assert_eq([], DirectionFidelity::motionProblems(
            fidelity_direction(['motion' => $profile]),
            $markup,
            'plugin/pages/home.html',
        ));
    }
});

test('image cards with no committed card class are reported per page', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group card-body">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="a.jpg" alt="a"/></figure>'
        . '<!-- /wp:image --></div><!-- /wp:group -->';
    $problems = DirectionFidelity::cardStyleProblems(
        fidelity_direction(),
        $markup,
        'plugin/pages/about.html',
    );
    assert_eq(1, count($problems));
    assert_contains('plugin/pages/about.html', $problems[0], 'the row names the page it came from');
    assert_contains('card-style--flush', $problems[0]);
});

test('the fidelity walk covers every page, not just the front one', function () {
    $tmp = sys_get_temp_dir() . '/builder_fidelity_' . uniqid();
    $project = (new \Automattic\SiteBuild\ProjectStore($tmp))->create('demo');
    $project->writeJson('designDirection.json', fidelity_direction(['canvas' => 'framed']));
    $project->writeJson('theme/theme.json', fidelity_theme());
    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true],
        ['slug' => 'about', 'front' => false],
    ]]);
    $hero = '<!-- wp:cover --><div class="wp-block-cover"><!-- wp:heading --><h1>Bread</h1>'
        . '<!-- /wp:heading --></div><!-- /wp:cover -->';
    $bleed = '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">'
        . '<!-- wp:paragraph --><p>Daily.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $project->writeText('plugin/pages/home.html', $hero . $bleed);
    $project->writeText('plugin/pages/about.html', $hero . $bleed);

    $problems = DirectionFidelity::problems($project);
    $joined = implode(' ', $problems);
    assert_contains('plugin/pages/home.html', $joined);
    assert_contains('plugin/pages/about.html', $joined, 'inner pages no longer drift silently');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the walk is silent without a committed direction', function () {
    $tmp = sys_get_temp_dir() . '/builder_fidelity_none_' . uniqid();
    $project = (new \Automattic\SiteBuild\ProjectStore($tmp))->create('demo');
    assert_eq([], DirectionFidelity::problems($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});
