<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldPartFacts;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\HeaderFallback;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroFallback;
use Automattic\SiteBuild\PageOpeningFallback;

/** @return array<string,mixed> */
function hero_fallback_contract(string $mode = 'stacked'): array
{
    return test_above_fold_contract(
        $mode === 'overlay' ? 'cinematic-safe-zone' : 'foreground-split',
        $mode === 'overlay' ? 'minimal-overlay' : 'standard-row',
        [
            'label' => 'See the work',
            'intent' => 'show portfolio',
            'destination' => '/work/',
        ],
    );
}

/** @return array<string,mixed> */
function hero_fallback_input(): array
{
    $blueprint = HeroBlueprint::defaultFor('foreground-split');
    $blueprint['mobile_transformation'] = 'stack-copy-first';
    return [
        'site_spec' => ['name' => 'Northlight Studio', 'title' => 'Northlight Studio'],
        'page' => ['slug' => 'home', 'title' => 'Home', 'front' => true],
        'section' => [
            'slug' => 'welcome',
            'title' => 'Hero',
            'purpose' => 'PLANNING-PROSE-MUST-NOT-RENDER',
            'content_notes' => 'NOT-VISITOR-COPY',
            'background' => 'base',
            'primary_action' => [
                'label' => 'See the work',
                'intent' => 'show portfolio',
                'destination' => '/work/',
            ],
        ],
        'hero_blueprint' => $blueprint,
    ];
}

test('hero family fallback retains its key, exact markers, identity, and validated action', function () {
    $result = HeroFallback::render(hero_fallback_input(), hero_fallback_contract(), 'transport failed');
    $markup = $result->markup;

    $document = BlockMarkup::parse($markup);
    assert_eq(1, count(array_filter(
        $document->indices(),
        static fn (int $index): bool => $document->parent($index) === null,
    )), 'fallback has exactly one top-level block');
    assert_contains('hero-composition--foreground-split', $markup);
    assert_contains('hero-mobile--stack-copy-first', $markup);
    assert_contains('hero-fallback--foreground-split', $markup);
    assert_contains('hero-composition__copy', $markup);
    assert_contains('Northlight Studio', $markup);
    assert_eq(1, substr_count($markup, 'See the work'));
    assert_eq(1, substr_count($markup, 'href="/work/"'));
    assert_true(!str_contains($markup, 'show portfolio'), 'planning intent is never button copy');
    assert_true(!str_contains($markup, 'PLANNING-PROSE-MUST-NOT-RENDER'));
    assert_true(!str_contains($markup, 'NOT-VISITOR-COPY'));
    assert_true(!str_contains($markup, 'AI_IMAGE:'), 'fallback invents no media');
    assert_eq([], $result->repairs);
    $wire = json_decode((string) json_encode($result), true);
    assert_eq($markup, $wire['markup'] ?? null);
    $warning = implode("\n", $result->warnings);
    foreach (["file='theme/parts/page-home--welcome.html'", "block='root wp:group'", 'authored=', 'delivered=', 'disposition='] as $needle) {
        assert_contains($needle, $warning);
    }
});

test('overlay hero fallback exposes the exact solid protection surface without inventing an image', function () {
    $contract = hero_fallback_contract('overlay');
    $result = HeroFallback::render(hero_fallback_input(), $contract);

    assert_contains('"backgroundColor":"contrast"', $result->markup);
    assert_contains('"textColor":"base"', $result->markup);
    assert_true(AboveFoldPartFacts::supportsOverlay($result->markup, 'image', 'contrast'));
    assert_true(!str_contains($result->markup, '<!-- wp:image'));
    assert_true(!str_contains($result->markup, '<!-- wp:cover'));
});

test('reviewed hero fallback families have distinct code-owned topology', function () {
    $input = hero_fallback_input();
    $cover = HeroFallback::render(
        $input,
        test_above_fold_contract('cinematic-safe-zone', 'standard-row'),
    )->markup;
    $split = HeroFallback::render(
        $input,
        test_above_fold_contract('foreground-split', 'standard-row'),
    )->markup;

    assert_contains('hero-fallback__cover-stage', $cover);
    assert_contains('hero-fallback__split', $split);
    assert_contains('wp:columns', $split);
    assert_true($cover !== $split);
});

test('hero fallback uses the dynamic real site title instead of inventing generic copy', function () {
    $input = [
        'site_spec' => [],
        'page' => ['slug' => 'home', 'front' => true],
        'section' => ['slug' => 'hero'],
    ];
    $markup = HeroFallback::render(
        $input,
        test_above_fold_contract('foreground-split', 'standard-row'),
    )->markup;

    assert_contains('wp:site-title', $markup);
    assert_true(!str_contains($markup, 'Welcome'));
});

test('header fallback is readable and exactly marked in stacked and overlay modes', function () {
    foreach (['stacked', 'overlay'] as $mode) {
        $contract = hero_fallback_contract($mode);
        $result = HeaderFallback::render(['site_spec' => ['name' => 'Northlight']], $contract, 'bad header');
        $facts = AboveFoldPartFacts::headerFacts($result->markup);
        assert_eq($mode, $facts['mode']);
        assert_eq($contract['header']['archetype'], $facts['archetype']);
        assert_eq($contract['header']['foreground_token'], $facts['foreground']);
        if ($mode === 'stacked') {
            assert_eq('base', $facts['background']);
        } else {
            assert_true(!str_contains($result->markup, '"backgroundColor"'));
        }
        assert_contains("file='theme/parts/header.html'", implode("\n", $result->warnings));
    }
});

test('header fallback delivers a dynamic tagline whenever the contract promises one', function () {
    $contract = hero_fallback_contract('stacked');
    $contract['header']['displays_tagline'] = true;
    $contract['header']['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $contract['header']['text_rows'] = 2;

    $result = HeaderFallback::render([], $contract, 'bad header');
    $facts = AboveFoldPartFacts::headerFacts($result->markup);

    assert_eq(1, $facts['site_tagline_blocks']);
    assert_eq(0, $facts['invalid_site_tagline_topology']);
    assert_eq(1, substr_count($result->markup, '<!-- wp:site-tagline'));
});

test('interior opening fallback uses the real page title and no hero recipe context', function () {
    $input = [
        'page' => ['slug' => 'about', 'title' => 'About the Studio', 'front' => false],
        'section' => ['slug' => 'about-opening', 'purpose' => 'PLANNING-ONLY'],
    ];
    $contract = hero_fallback_contract('overlay');
    $result = PageOpeningFallback::render($input, $contract, 'bad opening');

    assert_contains('About the Studio', $result->markup);
    assert_contains('"backgroundColor":"contrast"', $result->markup);
    assert_true(!str_contains($result->markup, 'PLANNING-ONLY'));
    assert_true(!str_contains($result->markup, 'hero-composition--'));
    assert_true(AboveFoldPartFacts::supportsOverlay($result->markup, 'image', 'contrast'));
    assert_contains("file='theme/parts/page-about--about-opening.html'", implode("\n", $result->warnings));
});

test('interior opening fallback uses the dynamic post title instead of inventing visitor copy', function () {
    $input = [
        'page' => ['slug' => 'about', 'title' => '', 'front' => false],
        'section' => ['slug' => 'about-opening'],
    ];

    $markup = PageOpeningFallback::render($input, hero_fallback_contract('stacked'), 'bad opening')->markup;

    assert_contains('<!-- wp:post-title {"level":1,"isLink":false,"fontSize":"section-title"} /-->', $markup);
    assert_true(!str_contains($markup, '>Page<'));
});

test('header fallback rides its title in a wide row so worst-case chrome stays band-aligned (BIGR-778)', function () {
    foreach (['stacked', 'overlay'] as $mode) {
        $contract = hero_fallback_contract($mode);
        $markup = HeaderFallback::render(['site_spec' => ['name' => 'Northlight']], $contract, 'bad header')->markup;
        assert_contains('"align":"wide"', $markup, $mode);
        assert_contains('alignwide', $markup, $mode);
        // The row wraps the title: a bare site-title in a constrained root
        // would sit at contentSize while every section row sits at wideSize.
        assert_true(
            strpos($markup, 'alignwide') < strpos($markup, 'wp:site-title'),
            'the site-title sits inside the wide row',
        );
    }
});

test('the fallback hero carries the committed media aspect marker (BIGR-925)', function () {
    // The fallback builds its root classes directly rather than through
    // withRootClassMarker, so it needs the aspect stamped here too — otherwise
    // a transport failure on a portrait build loses the plate ratio.
    $contract = hero_fallback_contract();
    $contract['media_aspect'] = 'portrait';
    $markup = HeroFallback::render(hero_fallback_input(), $contract, 'transport failed')->markup;
    assert_contains('hero-media--portrait', $markup);

    // An aspect the recipe cannot serve never reaches the markup: render()
    // asserts the contract first, and that is where it is rejected.
    $contract['media_aspect'] = 'none';
    assert_throws(fn () => HeroFallback::render(hero_fallback_input(), $contract, 'transport failed'));
});

test('the header fallback row is the pill when the contract floats (frm W1a)', function () {
    $contract = test_above_fold_contract('foreground-split', 'floating-pill');
    $markup = HeaderFallback::render(['site_spec' => ['name' => 'Northlight']], $contract, 'bad header')->markup;
    assert_contains('header-archetype--floating-pill', $markup);
    assert_contains('"className":"header-pill"', $markup);
    assert_contains('class="wp-block-group alignwide header-pill"', $markup);
    assert_eq('floating-pill', AboveFoldPartFacts::headerFacts($markup)['archetype']);

    $plain = HeaderFallback::render([], test_above_fold_contract('foreground-split', 'standard-row'), 'bad header')->markup;
    assert_true(!str_contains($plain, 'header-pill'), 'a bar archetype gets no pill class');
});

test('the header fallback row is the centered bar when the contract commits bar-center-cta (frm W1b)', function () {
    $contract = test_above_fold_contract('foreground-split', 'bar-center-cta');
    $markup = HeaderFallback::render(['site_spec' => ['name' => 'Northlight']], $contract, 'bad header')->markup;
    assert_contains('header-archetype--bar-center-cta', $markup);
    assert_contains('"className":"header-bar-center"', $markup);
    assert_contains('class="wp-block-group alignwide header-bar-center"', $markup);
    assert_true(!str_contains($markup, 'header-pill'), 'the bar gets no pill class');
    assert_eq('bar-center-cta', AboveFoldPartFacts::headerFacts($markup)['archetype']);
});
