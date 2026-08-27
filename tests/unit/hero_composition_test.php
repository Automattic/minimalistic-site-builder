<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;

test('hero catalog entries own complete metadata, defaults, prompts, and unique hooks', function () {
    assert_eq(7, count(HeroComposition::RECIPES));
    $hooks = [];
    foreach (HeroComposition::RECIPES as $recipe) {
        $meta = HeroComposition::metadata($recipe);
        foreach ([
            'canvases', 'media_modes', 'min_images', 'max_images', 'backgrounds',
            'default_background', 'fallback_background', 'header_modes', 'copy_capacity',
            'mobile_transformations', 'layout_archetype', 'fallback_family', 'root_hook',
            'prompt', 'headline_registers', 'height_profiles', 'row_alignment', 'defaults',
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

    // The reverse of the prompt check above (BIGR-905): a retired recipe must
    // take its fragment with it. An orphan file is invisible to the loop, and
    // the next author reads it as authoring guidance for a live recipe.
    $templates = array_map(
        static fn (string $recipe): string => HeroComposition::recipeTemplate($recipe),
        HeroComposition::RECIPES,
    );
    foreach (glob(repo_path('prompts/hero-compositions/*.md')) ?: [] as $file) {
        assert_true(
            in_array('hero-compositions/' . basename($file), $templates, true),
            basename($file) . ' belongs to a cataloged recipe',
        );
    }
});

test('hero compatibility filters objective caller constraints before selection', function () {
    // BIGR-885: 'none' is requestable again, and it isolates the one imageless
    // recipe. The image-count constraint still starts at 1, because an
    // image-bearing recipe capped at zero images has no meaning.
    assert_eq(['type-manifesto'], HeroComposition::compatible(['allowed_hero_media_modes' => ['none']]));
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

test('type-manifesto is the imageless recipe and disarms image generation on both branches (BIGR-885)', function () {
    $meta = HeroComposition::metadata('type-manifesto');
    assert_eq(['none'], $meta['media_modes']);
    assert_eq(0, $meta['min_images']);
    assert_eq(0, $meta['max_images']);
    assert_eq('centered-stack', $meta['layout_archetype']);
    assert_eq(['stacked'], $meta['header_modes']);
    assert_eq(['base', 'tinted', 'contrast'], $meta['backgrounds']);

    // The recipe-id branch reads min_images.
    assert_true(!HeroComposition::usesGeneratedImages('type-manifesto'));
    // The blueprint branch reads the catalog first, so blueprint drift toward
    // an image mode still cannot arm a slot the composition has nowhere to put.
    assert_true(!HeroComposition::usesGeneratedImages(HeroBlueprint::defaultFor('type-manifesto')));
    assert_true(!HeroComposition::usesGeneratedImages([
        'recipe' => 'type-manifesto',
        'media_mode' => 'cover-image',
    ]));

    assert_eq([
        'layout_archetype' => 'centered-stack',
        'allowed_backgrounds' => ['base', 'tinted', 'contrast'],
        'default_background' => 'contrast',
        'fallback_family' => 'typographic',
    ], HeroComposition::planProjection(HeroBlueprint::defaultFor('type-manifesto')));
});

test('every hero recipe default blueprint is already a normalize fixed point', function () {
    // HeroUnit::context() rejects any blueprint that normalize() would still
    // change, so a catalog default that is not a fixed point breaks the unit.
    foreach (HeroComposition::RECIPES as $recipe) {
        $default = HeroBlueprint::defaultFor($recipe);
        $repairs = [];
        $warnings = [];
        assert_eq($default, HeroBlueprint::normalize($default, $recipe, $repairs, $warnings), $recipe);
        assert_eq([], $repairs, $recipe);
        assert_eq([], $warnings, $recipe);
    }
});

test('an imageless hero reports one actionable image row and no media-count row (BIGR-885)', function () {
    $copyOpen = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1,"fontSize":"display"} -->'
        . '<h1 class="wp-block-heading has-display-font-size">A stated position</h1><!-- /wp:heading -->'
        . '<!-- wp:group {"className":"hero-composition__standfirst"} -->'
        . '<div class="wp-block-group hero-composition__standfirst">'
        . '<!-- wp:paragraph --><p>One supporting line.</p><!-- /wp:paragraph -->';
    $copyClose = '</div><!-- /wp:group --></div><!-- /wp:group -->';
    $clean = '<!-- wp:group --><div class="wp-block-group">' . $copyOpen . $copyClose . '</div><!-- /wp:group -->';
    assert_eq([], HeroComposition::markupWarnings($clean, 'type-manifesto', 'page-home--hero'));

    $withImage = '<!-- wp:group --><div class="wp-block-group">' . $copyOpen
        . '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="theme:./assets/subject.jpg" alt="AI_IMAGE: Subject | hero slot | photorealistic | landscape" />'
        . '</figure><!-- /wp:image -->' . $copyClose . '</div><!-- /wp:group -->';
    $warnings = HeroComposition::markupWarnings($withImage, 'type-manifesto', 'page-home--hero');
    $imageless = array_values(array_filter(
        $warnings,
        fn (string $w): bool => str_contains($w, 'imageless hero media'),
    ));
    assert_eq(1, count($imageless));
    assert_contains('type-manifesto', $imageless[0]);
    assert_contains('"image_count":1', $imageless[0]);
    // The generic count rule must not double-report the same defect.
    assert_eq([], array_values(array_filter(
        $warnings,
        fn (string $w): bool => str_contains($w, 'recipe media count'),
    )));
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
    foreach (['none', 'cover-image', 'foreground-image'] as $mode) {
        assert_true(isset($mediaModes[$mode]), "fixture selections cover {$mode}");
    }
});

test('the retired diptych-editorial recipe is unknown to the catalog', function () {
    assert_true(!in_array('diptych-editorial', HeroComposition::RECIPES, true));
    assert_throws(fn () => HeroComposition::metadata('diptych-editorial'));
    assert_throws(fn () => HeroComposition::validateConstraints(['allowed_hero_media_modes' => ['diptych']]));
});

test('the retired stacked-headline-band recipe and its media mode are unknown (BIGR-905)', function () {
    assert_true(!in_array('stacked-headline-band', HeroComposition::RECIPES, true));
    assert_throws(fn () => HeroComposition::metadata('stacked-headline-band'));
    assert_throws(fn () => HeroBlueprint::defaultFor('stacked-headline-band'));

    // The recipe owned 'band-image' alone, so the mode retires with it. A
    // caller that still asks for it is refused rather than silently served a
    // recipe that composes its media some other way.
    assert_true(!in_array('band-image', HeroComposition::MEDIA_MODES, true));
    assert_true(!in_array('band-image', HeroComposition::IMAGE_MEDIA_MODES, true));
    assert_true(!in_array('band-image', HeroBlueprint::MEDIA_MODES, true));
    assert_throws(fn () => HeroComposition::validateConstraints([
        'allowed_hero_media_modes' => ['band-image'],
    ]));

    // Every surviving recipe still selects, and every mode the catalog still
    // names stays requestable.
    foreach (HeroComposition::RECIPES as $recipe) {
        foreach (HeroComposition::metadata($recipe)['media_modes'] as $mode) {
            assert_true(
                in_array($mode, HeroComposition::MEDIA_MODES, true),
                "{$recipe} media mode {$mode} is still a requestable constraint",
            );
        }
    }
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

test('split-bleed-duo is the seam recipe and holds its row to the band edges (BIGR-913)', function () {
    $meta = HeroComposition::metadata('split-bleed-duo');
    assert_eq(['foreground-image'], $meta['media_modes']);
    assert_eq(1, $meta['min_images']);
    assert_eq(1, $meta['max_images']);
    assert_eq('asymmetric-split', $meta['layout_archetype']);
    // A half-solid band cannot protect a transparent header.
    assert_eq(['stacked'], $meta['header_modes']);
    // The panel must differ from the photograph beside it, so the recipe opens
    // on a painted field rather than the page background.
    assert_eq('contrast', $meta['default_background']);
    assert_eq('full', $meta['row_alignment']);

    // Only this recipe requires an aligned row; every other entry says so
    // explicitly rather than by omission.
    foreach (HeroComposition::RECIPES as $recipe) {
        if ($recipe === 'split-bleed-duo') {
            continue;
        }
        assert_eq(null, HeroComposition::metadata($recipe)['row_alignment'], $recipe);
    }
});

test('the seam check reads the delivered row alignment, not the recipe name (BIGR-913)', function () {
    $copy = '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Two fields, one edge</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $media = '<!-- wp:group {"className":"hero-composition__media"} --><div class="wp-block-group hero-composition__media">'
        . '<img src="theme:./assets/panel.jpg" alt="AI_IMAGE: A press room | hero media panel | photorealistic | landscape" />'
        . '</div><!-- /wp:group -->';

    $gutterBound = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:media-text --><div class="wp-block-media-text">' . $media . $copy . '</div><!-- /wp:media-text -->'
        . '</div><!-- /wp:group -->';
    $warnings = HeroComposition::markupWarnings($gutterBound, 'split-bleed-duo', 'page-home--hero');
    assert_eq(1, count($warnings));
    assert_contains('recipe row alignment', $warnings[0]);
    assert_contains('full', $warnings[0]);

    $seam = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:media-text {"align":"full","imageFill":true} --><div class="wp-block-media-text alignfull">'
        . $media . $copy . '</div><!-- /wp:media-text -->'
        . '</div><!-- /wp:group -->';
    assert_eq([], HeroComposition::markupWarnings($seam, 'split-bleed-duo', 'page-home--hero'));

    // A full-aligned wp:columns row carries the seam just as well.
    $columns = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:columns {"align":"full"} --><div class="wp-block-columns alignfull">'
        . '<!-- wp:column --><div class="wp-block-column">' . $media . '</div><!-- /wp:column -->'
        . '<!-- wp:column --><div class="wp-block-column">' . $copy . '</div><!-- /wp:column -->'
        . '</div><!-- /wp:columns --></div><!-- /wp:group -->';
    assert_eq([], HeroComposition::markupWarnings($columns, 'split-bleed-duo', 'page-home--hero'));

    // The check belongs to the recipe that declares it: the same gutter-bound
    // markup is silent for a recipe with no seam to hold.
    assert_eq([], HeroComposition::markupWarnings($gutterBound, 'editorial-split', 'page-home--hero'));
});
