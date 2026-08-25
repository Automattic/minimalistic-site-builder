<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;

test('hero catalog entries own complete metadata, defaults, prompts, and unique hooks', function () {
    assert_eq(6, count(HeroComposition::RECIPES));
    $hooks = [];
    foreach (HeroComposition::RECIPES as $recipe) {
        $meta = HeroComposition::metadata($recipe);
        foreach ([
            'canvases', 'media_modes', 'min_images', 'max_images', 'backgrounds',
            'default_background', 'fallback_background', 'header_modes', 'copy_capacity',
            'mobile_transformations', 'layout_archetype', 'fallback_family', 'root_hook',
            'prompt', 'headline_registers', 'height_profiles', 'defaults',
        ] as $field) {
            assert_true(array_key_exists($field, $meta), "{$recipe} metadata has {$field}");
        }
        assert_true(in_array('stacked', $meta['header_modes'], true), "{$recipe} supports stacked header");
        assert_true(in_array('full-bleed', $meta['canvases'], true), "{$recipe} supports full-bleed");
        assert_true(in_array('framed', $meta['canvases'], true), "{$recipe} supports framed");
        assert_true(is_file(repo_path('prompts/' . HeroComposition::recipeTemplate($recipe))), "{$recipe} prompt exists");
        assert_contains('.hero-composition--' . $recipe, HeroComposition::rootHook($recipe));
        $hooks[] = HeroComposition::rootHook($recipe);

        $default = HeroBlueprint::defaultFor($recipe);
        assert_eq($recipe, $default['recipe']);
        assert_true(in_array($default['media_mode'], $meta['media_modes'], true));
        assert_true(in_array($default['mobile_transformation'], $meta['mobile_transformations'], true));
    }
    assert_eq(count($hooks), count(array_unique($hooks)), 'root hooks are unique');
});

test('hero compatibility filters objective caller constraints before selection', function () {
    // The image-free constraint values were retired with typographic-poster:
    // every cataloged hero bears an image, so they fail loud at validation.
    assert_throws(fn () => HeroComposition::validateConstraints(['allowed_hero_media_modes' => ['none']]));
    assert_throws(fn () => HeroComposition::validateConstraints(['max_hero_images' => 0]));
    $oneImage = HeroComposition::compatible(['max_hero_images' => 1]);
    assert_true(in_array('editorial-split', $oneImage, true));
    assert_true(in_array('cinematic-safe-zone', $oneImage, true));

    $foreground = HeroComposition::compatible(['allowed_hero_media_modes' => ['foreground-image']]);
    foreach ($foreground as $recipe) {
        assert_true(in_array('foreground-image', HeroComposition::metadata($recipe)['media_modes'], true));
    }
});

test('hero selection is stable inside the compatible pool', function () {
    $constraints = ['allowed_hero_media_modes' => ['foreground-image'], 'max_hero_images' => 1];
    $first = HeroComposition::select('site-17', 'Paper Night — a vivid seed.', $constraints);
    $second = HeroComposition::select('site-17', 'Paper Night — a vivid seed.', $constraints);
    assert_eq($first, $second);
    assert_true(in_array($first, HeroComposition::compatible($constraints), true));
});

test('invalid hero constraints and empty compatible pools fail preflight', function () {
    assert_throws(fn () => HeroComposition::validateConstraints(['hero_canvas' => 'poster']));
    assert_throws(fn () => HeroComposition::validateConstraints(['max_hero_images' => 3]));
    assert_throws(fn () => HeroComposition::validateConstraints(['allowed_hero_media_modes' => []]));
    assert_throws(fn () => HeroComposition::validateConstraints(['typo' => 'framed']));

    // 'expanded' capacity stays a valid enum value with no cataloged recipe
    // left, so it exercises the loud empty-pool path.
    $impossible = ['hero_copy_capacity' => 'expanded'];
    assert_eq([], HeroComposition::compatible($impossible));
    assert_throws(fn () => HeroComposition::select('site', 'seed', $impossible));
});

test('hero catalog exposes image gating and deterministic page-plan projection', function () {
    assert_true(HeroComposition::usesGeneratedImages('editorial-split'));
    // A blueprint whose media degraded to 'none' disarms image generation
    // even though the recipe itself is image-bearing.
    assert_true(!HeroComposition::usesGeneratedImages([
        'recipe' => 'editorial-split',
        'media_mode' => 'none',
    ]));

    assert_eq([
        'layout_archetype' => 'full-bleed-cover',
        'allowed_backgrounds' => ['image', 'contrast'],
        'default_background' => 'image',
        'fallback_family' => 'cover',
    ], HeroComposition::planProjection(HeroBlueprint::defaultFor('cinematic-safe-zone')));
});

test('structured selector fixture corpus exercises eligibility and media distribution', function () {
    $corpus = json_decode(
        (string) file_get_contents(repo_path('tests/fixtures/hero-selector-fixtures.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $fixtures = $corpus['fixtures'] ?? [];
    assert_true(is_array($fixtures) && count($fixtures) >= 8);
    $selected = [];
    $mediaModes = [];
    foreach ($fixtures as $fixture) {
        assert_true(is_array($fixture));
        $constraints = $fixture['constraints'] ?? [];
        assert_true(is_array($constraints));
        $eligible = HeroComposition::compatible($constraints);
        if (isset($fixture['eligible'])) {
            assert_eq($fixture['eligible'], $eligible, (string) ($fixture['id'] ?? 'fixture'));
        }
        $recipe = HeroComposition::select(
            (string) ($fixture['stable_identifier'] ?? ''),
            (string) ($fixture['concept_seed'] ?? ''),
            $constraints,
        );
        assert_true(in_array($recipe, $eligible, true));
        $selected[$recipe] = true;
        foreach (HeroComposition::metadata($recipe)['media_modes'] as $mode) {
            $mediaModes[$mode] = true;
        }
    }
    assert_true(count($selected) >= 5, 'objective fixture set exercises broad catalog selection');
    foreach (['cover-image', 'foreground-image'] as $mode) {
        assert_true(isset($mediaModes[$mode]), "fixture selections cover {$mode}");
    }
});

test('the retired diptych-editorial recipe is unknown to the catalog', function () {
    assert_true(!in_array('diptych-editorial', HeroComposition::RECIPES, true));
    assert_throws(fn () => HeroComposition::metadata('diptych-editorial'));
    assert_throws(fn () => HeroComposition::validateConstraints(['allowed_hero_media_modes' => ['diptych']]));
});

test('hero recipe inspection keeps cover and aspect drift actionable at their exact boundary', function () {
    $copy = '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">Copy</div><!-- /wp:group -->';
    $cover = '<!-- wp:cover {"className":"hero-composition__media"} --><div class="wp-block-cover hero-composition__media">'
        . '<img src="theme:./assets/subject.jpg" alt="AI_IMAGE: Subject | foreground slot | photorealistic | landscape" />'
        . '</div><!-- /wp:cover -->';
    $foreground = '<!-- wp:group --><div class="wp-block-group">' . $copy . $cover . '</div><!-- /wp:group -->';
    $coverWarnings = HeroComposition::markupWarnings($foreground, 'editorial-split', 'page-home--hero');
    assert_eq(1, count($coverWarnings));
    assert_contains('foreground recipe cover usage', $coverWarnings[0]);

    $portrait = '<!-- wp:group --><div class="wp-block-group">' . $copy
        . '<!-- wp:group {"className":"hero-composition__media"} --><div class="wp-block-group hero-composition__media">'
        . '<img src="theme:./assets/portrait.jpg" alt="AI_IMAGE: Person | portrait slot | photorealistic | landscape" />'
        . '</div><!-- /wp:group --></div><!-- /wp:group -->';
    $aspectWarnings = HeroComposition::markupWarnings($portrait, 'framed-portrait', 'page-home--hero');
    assert_eq(1, count($aspectWarnings));
    assert_contains('recipe image aspect', $aspectWarnings[0]);
    assert_contains('portrait', $aspectWarnings[0]);
    assert_contains('landscape', $aspectWarnings[0]);
});

test('the band recipe is the only one selectable through media mode band-image (BIGR-885)', function () {
    $pool = HeroComposition::compatible(['allowed_hero_media_modes' => ['band-image']]);
    assert_eq(['stacked-headline-band'], $pool);
    assert_eq(
        'stacked-headline-band',
        HeroComposition::select('band-site', 'A wide horizon under a short line.', [
            'allowed_hero_media_modes' => ['band-image'],
        ]),
    );

    $meta = HeroComposition::metadata('stacked-headline-band');
    // The copy is the section's first element, so an overlay header would
    // float over the headline instead of over media.
    assert_eq(['stacked'], $meta['header_modes']);
    // The copy sits on a solid surface; an 'image' surface would wrap the band
    // in a cover and put the copy back over pixels.
    assert_eq(['base', 'tinted', 'contrast'], $meta['backgrounds']);
    assert_eq('mixed-width-editorial', $meta['layout_archetype']);

    // The trap this recipe was written against: a media mode outside the
    // hard-coded pair silently disarms image generation on both branches.
    assert_true(HeroComposition::usesGeneratedImages('stacked-headline-band'));
    assert_true(HeroComposition::usesGeneratedImages(
        HeroBlueprint::defaultFor('stacked-headline-band')
    ));
    assert_true(!HeroComposition::usesGeneratedImages([
        'recipe' => 'stacked-headline-band',
        'media_mode' => 'none',
    ]));

    // The blueprint accepts the new mode and keeps the cover-only spatial
    // fields at their non-cover values, so the default is a fixed point.
    $repairs = [];
    $normalized = HeroBlueprint::normalize(
        HeroBlueprint::defaultFor('stacked-headline-band'),
        'stacked-headline-band',
        $repairs,
    );
    assert_eq('band-image', $normalized['media_mode']);
    assert_eq('none', $normalized['focal_region']);
    assert_eq('full', $normalized['text_safe_region']);
    assert_eq([], $repairs);
});

test('the band recipe reports text over the band, a cover, and a short band (BIGR-885)', function () {
    $copy = '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">A wide horizon</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>One orienting line.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $band = '<!-- wp:image {"align":"full","className":"hero-composition__media"} -->'
        . '<figure class="wp-block-image alignfull hero-composition__media">'
        . '<img src="theme:./assets/band.jpg" alt="AI_IMAGE: A wide valley | wide band under the copy | photorealistic | landscape" />'
        . '</figure><!-- /wp:image -->';
    $valid = '<!-- wp:group --><div class="wp-block-group">' . $copy . $band . '</div><!-- /wp:group -->';
    assert_eq([], HeroComposition::markupWarnings($valid, 'stacked-headline-band', 'page-home--hero'));

    // The recipe's own objective failure: copy authored inside the band.
    $overBand = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:group {"align":"full","className":"hero-composition__media"} -->'
        . '<div class="wp-block-group alignfull hero-composition__media">'
        . '<img src="theme:./assets/band.jpg" alt="AI_IMAGE: A wide valley | wide band | photorealistic | landscape" />'
        . $copy . '</div><!-- /wp:group --></div><!-- /wp:group -->';
    $warnings = HeroComposition::markupWarnings($overBand, 'stacked-headline-band', 'page-home--hero');
    $overlaid = array_values(array_filter(
        $warnings,
        fn (string $w): bool => str_contains($w, 'band recipe text over media'),
    ));
    assert_eq(1, count($overlaid));
    assert_contains('text_blocks_inside_media', $overlaid[0]);

    // A cover is the other way copy lands on the photograph.
    $covered = '<!-- wp:group --><div class="wp-block-group">' . $copy
        . '<!-- wp:cover {"align":"full","className":"hero-composition__media"} -->'
        . '<div class="wp-block-cover alignfull hero-composition__media">'
        . '<img src="theme:./assets/band.jpg" alt="AI_IMAGE: A wide valley | wide band | photorealistic | landscape" />'
        . '</div><!-- /wp:cover --></div><!-- /wp:group -->';
    $warnings = HeroComposition::markupWarnings($covered, 'stacked-headline-band', 'page-home--hero');
    assert_eq(1, count(array_filter(
        $warnings,
        fn (string $w): bool => str_contains($w, 'band recipe cover usage'),
    )));

    // A band capped below full width breaks the recipe's other promise.
    $narrow = str_replace(
        ['{"align":"full","className":"hero-composition__media"}', ' alignfull'],
        ['{"className":"hero-composition__media"}', ''],
        $valid,
    );
    $warnings = HeroComposition::markupWarnings($narrow, 'stacked-headline-band', 'page-home--hero');
    assert_eq(1, count(array_filter(
        $warnings,
        fn (string $w): bool => str_contains($w, 'band recipe media width'),
    )));

    // A missing region hook stays actionable at its own boundary.
    $noRegion = str_replace('hero-composition__media', 'band', $valid);
    $warnings = HeroComposition::markupWarnings($noRegion, 'stacked-headline-band', 'page-home--hero');
    assert_eq(1, count(array_filter(
        $warnings,
        fn (string $w): bool => str_contains($w, 'recipe band media region'),
    )));

    // A full-bleed band spans the viewport, so ultrawide fits it too.
    $ultrawide = str_replace('| landscape', '| ultrawide', $valid);
    assert_eq([], HeroComposition::markupWarnings($ultrawide, 'stacked-headline-band', 'page-home--hero'));

    // A portrait band is the aspect the letterbox crop cannot serve.
    $portrait = str_replace('| landscape', '| portrait', $valid);
    $warnings = HeroComposition::markupWarnings($portrait, 'stacked-headline-band', 'page-home--hero');
    $aspect = array_values(array_filter(
        $warnings,
        fn (string $w): bool => str_contains($w, 'recipe image aspect'),
    ));
    assert_eq(1, count($aspect));
    assert_contains('landscape or ultrawide', $aspect[0]);
});

test('hero copy budget and headline punctuation overruns warn without hiding valid heroes (BIGR-775)', function () {
    $media = '<!-- wp:group {"className":"hero-composition__media"} --><div class="wp-block-group hero-composition__media">'
        . '<img src="theme:./assets/subject.jpg" alt="AI_IMAGE: Subject | foreground slot | photorealistic | landscape" />'
        . '</div><!-- /wp:group -->';
    $overBudget = '<!-- wp:group --><div class="wp-block-group">' . $media
        . '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">One subject</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>A standfirst.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>A second line.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>A third caption line.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group --></div><!-- /wp:group -->';
    // focal-subject-stage is compact: the H1 plus one paragraph is the budget.
    $warnings = HeroComposition::markupWarnings($overBudget, 'focal-subject-stage', 'page-home--hero');
    $budget = array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'hero copy budget')));
    assert_eq(1, count($budget));
    assert_contains('max_text_blocks', $budget[0]);

    $inBudget = '<!-- wp:group --><div class="wp-block-group">' . $media
        . '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">One subject — staged</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>A standfirst.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group --></div><!-- /wp:group -->';
    $warnings = HeroComposition::markupWarnings($inBudget, 'focal-subject-stage', 'page-home--hero');
    assert_eq([], array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'hero copy budget'))));
    // The em dash inside the H1 is its own advisory.
    $dash = array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'hero headline punctuation')));
    assert_eq(1, count($dash));

    $cleanHeadline = str_replace('One subject — staged', 'One subject staged', $inBudget);
    $warnings = HeroComposition::markupWarnings($cleanHeadline, 'focal-subject-stage', 'page-home--hero');
    assert_eq([], array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'hero headline punctuation'))));
});
