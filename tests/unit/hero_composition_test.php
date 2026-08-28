<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;

test('hero catalog entries own complete metadata, defaults, prompts, and unique hooks', function () {
    assert_eq(5, count(HeroComposition::RECIPES));
    $hooks = [];
    foreach (HeroComposition::RECIPES as $recipe) {
        $meta = HeroComposition::metadata($recipe);
        foreach ([
            'canvases', 'media_modes', 'min_images', 'max_images', 'backgrounds',
            'default_background', 'fallback_background', 'header_modes', 'copy_capacity',
            'mobile_transformations', 'layout_archetype', 'fallback_family', 'root_hook',
            'prompt', 'headline_registers', 'height_profiles', 'media_aspects',
            'media_weights', 'required_region', 'defaults',
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
    assert_true(in_array('foreground-split', $oneImage, true));
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

    // Capacity is a ceiling since BIGR-912, so it can no longer empty the
    // pool: every cataloged recipe fits under 'expanded'.
    assert_eq(HeroComposition::RECIPES, HeroComposition::compatible(['hero_copy_capacity' => 'expanded']));
    assert_eq(HeroComposition::RECIPES, HeroComposition::compatible(['hero_copy_capacity' => 'standard']));

    // With capacity as a ceiling, no requestable constraint set can empty the
    // pool: every combination the validator accepts still has a recipe. The
    // empty-pool throw in select() stays as defense for a future catalog that
    // narrows a row, and this invariant is what would catch that edit.
    $modeSets = [];
    foreach (HeroComposition::MEDIA_MODES as $mode) {
        $modeSets[] = [$mode];
    }
    $modeSets[] = HeroComposition::MEDIA_MODES;
    foreach (HeroComposition::CANVASES as $canvas) {
        foreach ($modeSets as $modes) {
            foreach ([1, 2] as $maxImages) {
                foreach (HeroComposition::COPY_CAPACITIES as $capacity) {
                    $constraints = [
                        'hero_canvas' => $canvas,
                        'allowed_hero_media_modes' => $modes,
                        'max_hero_images' => $maxImages,
                        'hero_copy_capacity' => $capacity,
                    ];
                    assert_true(
                        HeroComposition::compatible($constraints) !== [],
                        'every requestable constraint set keeps a recipe: ' . json_encode($constraints),
                    );
                }
            }
        }
    }
});

test('hero catalog exposes image gating and deterministic page-plan projection', function () {
    assert_true(HeroComposition::usesGeneratedImages('foreground-split'));
    // A blueprint whose media degraded to 'none' disarms image generation
    // even though the recipe itself is image-bearing.
    assert_true(!HeroComposition::usesGeneratedImages([
        'recipe' => 'foreground-split',
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
    // Every cataloged recipe must be reachable from the corpus. The old
    // floor of five survived the BIGR-912 merge only because three of the
    // recipes it counted were the same composition.
    assert_eq(
        count(HeroComposition::RECIPES),
        count($selected),
        'objective fixture set selects every cataloged recipe',
    );
    foreach (['none', 'cover-image', 'foreground-image'] as $mode) {
        assert_true(isset($mediaModes[$mode]), "fixture selections cover {$mode}");
    }
});

test('the retired diptych-editorial recipe is unknown to the catalog', function () {
    assert_true(!in_array('diptych-editorial', HeroComposition::RECIPES, true));
    assert_throws(fn () => HeroComposition::metadata('diptych-editorial'));
    assert_throws(fn () => HeroComposition::validateConstraints(['allowed_hero_media_modes' => ['diptych']]));
});

test('the three merged contained-split recipes are unknown to the catalog (BIGR-912)', function () {
    foreach (['editorial-split', 'framed-portrait', 'focal-subject-stage'] as $merged) {
        assert_true(!in_array($merged, HeroComposition::RECIPES, true));
        assert_throws(fn () => HeroComposition::metadata($merged));
        assert_throws(fn () => HeroBlueprint::defaultFor($merged));
    }

    // The one recipe that replaced them keeps every shape they could draw:
    // the portrait plate framed-portrait owned, the dominant exhibit scale
    // focal-subject-stage owned, and the balanced imbalance of editorial-split.
    $meta = HeroComposition::metadata('foreground-split');
    assert_eq(['portrait', 'landscape', 'square'], $meta['media_aspects']);
    assert_eq(['balanced', 'dominant'], $meta['media_weights']);
    assert_eq(['compact', 'standard', 'immersive'], $meta['height_profiles']);
    assert_eq(['restrained', 'display'], $meta['headline_registers']);
    assert_eq('foreground-split', $meta['fallback_family']);
});

test('media axes are seeded per site inside the recipe allowed values (BIGR-912)', function () {
    // Stable for one site, and stable per axis.
    $first = HeroComposition::selectMediaAxes('alder-studio', 'A measured cobalt edge.', 'foreground-split');
    assert_eq($first, HeroComposition::selectMediaAxes('alder-studio', 'A measured cobalt edge.', 'foreground-split'));
    assert_eq($first, HeroComposition::selectMediaAxes('  ALDER-Studio ', 'A measured cobalt edge.', 'foreground-split'));

    // Spread across sites: the merge is only honest if all six pairs the
    // recipe allows actually reach real builds.
    $pairs = [];
    foreach (range(1, 60) as $index) {
        $axes = HeroComposition::selectMediaAxes("site-{$index}", 'one concept seed', 'foreground-split');
        assert_true(in_array($axes['media_aspect'], ['portrait', 'landscape', 'square'], true));
        assert_true(in_array($axes['media_weight'], ['balanced', 'dominant'], true));
        $pairs[$axes['media_aspect'] . '/' . $axes['media_weight']] = true;
    }
    assert_eq(6, count($pairs), 'every allowed aspect/weight pair is reachable');

    // A recipe whose slot has one shape keeps it, whatever the seed.
    foreach (['cinematic-safe-zone', 'layered-poster'] as $cover) {
        assert_eq(
            ['media_aspect' => 'landscape', 'media_weight' => 'dominant'],
            HeroComposition::selectMediaAxes('any-site', 'any seed', $cover),
        );
    }
    assert_eq(
        ['media_aspect' => 'none', 'media_weight' => 'none'],
        HeroComposition::selectMediaAxes('any-site', 'any seed', 'type-manifesto'),
    );

    // The seeded pick is a valid blueprint value for its recipe, so it never
    // arrives as a normalize repair.
    foreach (HeroComposition::RECIPES as $recipe) {
        $axes = HeroComposition::selectMediaAxes('seeded-site', 'seeded concept', $recipe);
        $repairs = [];
        $warnings = [];
        $normalized = HeroBlueprint::normalize(
            array_merge(HeroBlueprint::defaultFor($recipe), $axes),
            $recipe,
            $repairs,
            $warnings,
        );
        assert_eq($axes['media_aspect'], $normalized['media_aspect']);
        assert_eq($axes['media_weight'], $normalized['media_weight']);
        assert_eq([], $repairs, "{$recipe} seeded axes need no repair");
    }
});

test('every recipe pins the media axes its slot can serve (BIGR-912)', function () {
    foreach (HeroComposition::RECIPES as $recipe) {
        $meta = HeroComposition::metadata($recipe);
        assert_true($meta['media_aspects'] !== [], "{$recipe} names its media aspects");
        assert_true($meta['media_weights'] !== [], "{$recipe} names its media weights");
        foreach ($meta['media_aspects'] as $aspect) {
            assert_true(in_array($aspect, HeroComposition::MEDIA_ASPECTS, true));
        }
        foreach ($meta['media_weights'] as $weight) {
            assert_true(in_array($weight, HeroComposition::MEDIA_WEIGHTS, true));
        }
        // 'none' is the imageless value on both axes, so a recipe carries it
        // only when it carries no image at all — and then on both axes.
        $imageless = (int) $meta['max_images'] === 0;
        assert_eq($imageless, in_array('none', $meta['media_aspects'], true), "{$recipe} aspect none");
        assert_eq($imageless, in_array('none', $meta['media_weights'], true), "{$recipe} weight none");

        $default = $meta['defaults'];
        assert_true(in_array($default['media_aspect'], $meta['media_aspects'], true));
        assert_true(in_array($default['media_weight'], $meta['media_weights'], true));
    }
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
    $coverWarnings = HeroComposition::markupWarnings($foreground, 'foreground-split', 'page-home--hero');
    assert_eq(1, count($coverWarnings));
    assert_contains('foreground recipe cover usage', $coverWarnings[0]);

    $portrait = '<!-- wp:group --><div class="wp-block-group">' . $copy
        . '<!-- wp:group {"className":"hero-composition__media"} --><div class="wp-block-group hero-composition__media">'
        . '<img src="theme:./assets/portrait.jpg" alt="AI_IMAGE: Person | portrait slot | photorealistic | landscape" />'
        . '</div><!-- /wp:group --></div><!-- /wp:group -->';
    // foreground-split serves three aspects, so the blueprint's committed one
    // is what the delivered image must match (BIGR-912). A landscape file in a
    // composition built for a portrait plate is the drift this reports.
    $aspectWarnings = HeroComposition::markupWarnings(
        $portrait,
        'foreground-split',
        'page-home--hero',
        HeroBlueprint::defaultFor('foreground-split') + [],
    );
    assert_eq([], $aspectWarnings, 'a landscape file matches the landscape default');

    $portraitBlueprint = HeroBlueprint::defaultFor('foreground-split');
    $portraitBlueprint['media_aspect'] = 'portrait';
    $aspectWarnings = HeroComposition::markupWarnings(
        $portrait,
        'foreground-split',
        'page-home--hero',
        $portraitBlueprint,
    );
    assert_eq(1, count($aspectWarnings));
    assert_contains('recipe image aspect', $aspectWarnings[0]);
    assert_contains('portrait', $aspectWarnings[0]);
    assert_contains('landscape', $aspectWarnings[0]);

    // Without a blueprint the catalog list still bounds the check: the pinned
    // cover recipes keep their exact aspect, and the multi-aspect recipe
    // accepts any aspect its slot can serve rather than guessing one.
    $coverAspect = HeroComposition::markupWarnings($portrait, 'foreground-split', 'page-home--hero');
    assert_eq([], $coverAspect);
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
    // foreground-split is compact: the H1 plus one paragraph is the budget.
    $warnings = HeroComposition::markupWarnings($overBudget, 'foreground-split', 'page-home--hero');
    $budget = array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'hero copy budget')));
    assert_eq(1, count($budget));
    assert_contains('max_text_blocks', $budget[0]);

    $inBudget = '<!-- wp:group --><div class="wp-block-group">' . $media
        . '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">One subject — staged</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>A standfirst.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group --></div><!-- /wp:group -->';
    $warnings = HeroComposition::markupWarnings($inBudget, 'foreground-split', 'page-home--hero');
    assert_eq([], array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'hero copy budget'))));
    // The em dash inside the H1 is its own advisory.
    $dash = array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'hero headline punctuation')));
    assert_eq(1, count($dash));

    $cleanHeadline = str_replace('One subject — staged', 'One subject staged', $inBudget);
    $warnings = HeroComposition::markupWarnings($cleanHeadline, 'foreground-split', 'page-home--hero');
    assert_eq([], array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'hero headline punctuation'))));
});

test('knockout-type puts the image inside the letters, nowhere else (BIGR-935)', function () {
    $meta = HeroComposition::metadata('knockout-type');
    assert_eq(['cover-image'], $meta['media_modes']);
    assert_eq(1, $meta['min_images']);
    assert_eq(1, $meta['max_images']);
    // multiply only cuts letters out of dark ink, so the surface is pinned.
    assert_eq(['contrast'], $meta['backgrounds']);
    assert_eq('contrast', $meta['default_background']);
    // A solid field cannot protect a transparent header.
    assert_eq(['stacked'], $meta['header_modes']);
    // Read as a shape before it is read as words.
    assert_eq(['display', 'poster'], $meta['headline_registers']);
    assert_eq(
        [
            'class' => 'hero-knockout',
            'holds_headline' => true,
            'needs_background' => true,
            'blend_by_luminance' => true,
        ],
        $meta['required_region'],
    );

    foreach (HeroComposition::RECIPES as $recipe) {
        if ($recipe === 'knockout-type') {
            continue;
        }
        assert_eq(null, HeroComposition::metadata($recipe)['required_region'], $recipe);
    }
});

test('the knockout panel check reads the delivered region, not the recipe name (BIGR-935)', function () {
    $headline = '<!-- wp:heading {"level":1,"fontSize":"display","textColor":"base"} -->'
        . '<h1 class="wp-block-heading has-base-color">Cast Iron</h1><!-- /wp:heading -->';
    $support = '<!-- wp:group {"className":"hero-composition__copy","backgroundColor":"contrast"} -->'
        . '<div class="wp-block-group hero-composition__copy has-contrast-background-color">'
        . '<!-- wp:paragraph --><p>Foundry and fabrication in Sheffield.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $cover = static fn (string $inner): string =>
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:cover {"dimRatio":0} --><div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" src="theme:./assets/iron.jpg"'
        . ' alt="AI_IMAGE: A ladle pouring molten iron | hero cover behind the knockout headline | photorealistic | landscape" />'
        . $inner . '</div><!-- /wp:cover --></div><!-- /wp:group -->';
    $panel = static fn (string $attrs, string $classes, string $inner): string =>
        '<!-- wp:group ' . $attrs . ' --><div class="wp-block-group ' . $classes . '">'
        . $inner . '</div><!-- /wp:group -->';

    $good = $cover($panel('{"className":"hero-knockout","backgroundColor":"contrast"}', 'hero-knockout', $headline) . $support);
    assert_eq([], HeroComposition::markupWarnings($good, 'knockout-type', 'page-home--hero'));

    // No panel: the headline sits on the open photograph and nothing is cut.
    $noPanel = $cover($headline . $support);
    $warnings = HeroComposition::markupWarnings($noPanel, 'knockout-type', 'page-home--hero');
    assert_eq(1, count($warnings));
    assert_contains('recipe required region', $warnings[0]);
    assert_contains('hero-knockout', $warnings[0]);

    // A panel with no colour has nothing to cut the letters from.
    $noColour = $cover($panel('{"className":"hero-knockout"}', 'hero-knockout', $headline) . $support);
    $warnings = HeroComposition::markupWarnings($noColour, 'knockout-type', 'page-home--hero');
    assert_eq(1, count($warnings));
    assert_contains('recipe region surface', $warnings[0]);

    // A standfirst inside the panel is knocked out too, and unreadable.
    $crowded = $cover($panel(
        '{"className":"hero-knockout","backgroundColor":"contrast"}',
        'hero-knockout',
        $headline . '<!-- wp:paragraph --><p>Since 1968.</p><!-- /wp:paragraph -->',
    ));
    $warnings = HeroComposition::markupWarnings($crowded, 'knockout-type', 'page-home--hero');
    $contents = array_values(array_filter(
        $warnings,
        static fn (string $row): bool => str_contains($row, 'recipe region contents'),
    ));
    assert_eq(1, count($contents));
    assert_contains('"other_blocks":1', $contents[0]);

    // A subheading inside the panel crowds it exactly like a paragraph does.
    $subheaded = $cover($panel(
        '{"className":"hero-knockout","backgroundColor":"contrast"}',
        'hero-knockout',
        $headline . '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Since 1968</h2><!-- /wp:heading -->',
    ));
    $warnings = HeroComposition::markupWarnings($subheaded, 'knockout-type', 'page-home--hero');
    $contents = array_values(array_filter(
        $warnings,
        static fn (string $row): bool => str_contains($row, 'recipe region contents'),
    ));
    assert_eq(1, count($contents));
    assert_contains('"other_blocks":1', $contents[0]);

    // Two panels are two knockouts.
    $twice = $cover(
        $panel('{"className":"hero-knockout","backgroundColor":"contrast"}', 'hero-knockout', $headline)
        . $panel('{"className":"hero-knockout","backgroundColor":"contrast"}', 'hero-knockout', '')
        . $support,
    );
    $warnings = HeroComposition::markupWarnings($twice, 'knockout-type', 'page-home--hero');
    assert_eq(1, count($warnings));
    assert_contains('recipe required region', $warnings[0]);

    // The check belongs to the recipe that declares the region: the same
    // panel-less markup is silent for a recipe with no region of its own.
    assert_eq([], HeroComposition::markupWarnings($noPanel, 'cinematic-safe-zone', 'page-home--hero'));
});
