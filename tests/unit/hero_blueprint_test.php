<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;

test('hero blueprint defaults are complete and recipe-compatible', function () {
    foreach (HeroComposition::RECIPES as $recipe) {
        $blueprint = HeroBlueprint::defaultFor($recipe);
        assert_eq([
            'version', 'recipe', 'media_mode', 'headline_register', 'text_anchor',
            'headline_line_target', 'focal_region', 'text_safe_region', 'height_profile',
            'cta_treatment', 'stage_backdrop', 'mobile_transformation',
        ], array_keys($blueprint));
        assert_eq(1, $blueprint['version']);
        assert_eq($recipe, $blueprint['recipe']);
    }
});

test('hero blueprint preserves valid generated fields and reaches a fixed point', function () {
    $raw = HeroBlueprint::defaultFor('editorial-split');
    $raw['headline_register'] = 'restrained';
    $raw['text_anchor'] = 'top-end';
    $raw['cta_treatment'] = 'quiet';
    $repairs = [];
    $warnings = [];
    $normalized = HeroBlueprint::normalize($raw, 'editorial-split', $repairs, $warnings);
    assert_eq($raw, $normalized);
    assert_eq([], $repairs);
    assert_eq([], $warnings);

    $againRepairs = [];
    $againWarnings = [];
    assert_eq(
        $normalized,
        HeroBlueprint::normalize($normalized, 'editorial-split', $againRepairs, $againWarnings),
    );
    assert_eq([], $againRepairs);
    assert_eq([], $againWarnings);
});

test('hero blueprint repairs invalid enums and clamps line targets to a fixed point', function () {
    $raw = HeroBlueprint::defaultFor('editorial-split');
    $raw['recipe'] = 'typographic-poster';
    $raw['media_mode'] = 'none';
    $raw['headline_register'] = 'poster';
    $raw['height_profile'] = 'immersive';
    $raw['mobile_transformation'] = 'flatten-layers';
    $raw['cta_treatment'] = 'loud';
    $raw['headline_line_target'] = ['desktop' => [9, -2], 'mobile' => [5, 2]];
    $repairs = [];
    $warnings = [];
    $normalized = HeroBlueprint::normalize($raw, 'editorial-split', $repairs, $warnings);

    assert_eq('editorial-split', $normalized['recipe']);
    assert_eq('foreground-image', $normalized['media_mode']);
    assert_eq('display', $normalized['headline_register']);
    assert_eq('standard', $normalized['height_profile']);
    assert_eq('stack-copy-first', $normalized['mobile_transformation']);
    assert_eq('prominent', $normalized['cta_treatment']);
    assert_eq([1, 6], $normalized['headline_line_target']['desktop']);
    assert_eq([2, 5], $normalized['headline_line_target']['mobile']);
    assert_true(count($repairs) >= 6);

    $againRepairs = [];
    $againWarnings = [];
    assert_eq(
        $normalized,
        HeroBlueprint::normalize($normalized, 'editorial-split', $againRepairs, $againWarnings),
    );
    assert_eq([], $againRepairs);
    assert_eq([], $againWarnings);
});

test('cover blueprint repairs conflicting safe, focal, and anchor fields', function () {
    $raw = HeroBlueprint::defaultFor('cinematic-safe-zone');
    $raw['text_anchor'] = 'center-end';
    $raw['text_safe_region'] = 'start';
    $raw['focal_region'] = 'start';
    $repairs = [];
    $warnings = [];
    $normalized = HeroBlueprint::normalize($raw, 'cinematic-safe-zone', $repairs, $warnings);
    // The mismatched anchor falls back to the recipe's centered default, and
    // the safe region follows it; the authored focal region no longer
    // collides with the repaired safe region, so it survives.
    assert_eq('center', $normalized['text_anchor']);
    assert_eq('center', $normalized['text_safe_region']);
    assert_eq('start', $normalized['focal_region']);
    assert_true(count($repairs) >= 2);
});

test('a legacy signature_device_use field is dropped from the normalized blueprint', function () {
    $raw = HeroBlueprint::defaultFor('editorial-split');
    $raw['signature_device_use'] = 'Repeat the device across the hero.';
    $repairs = [];
    $warnings = [];
    $normalized = HeroBlueprint::normalize($raw, 'editorial-split', $repairs, $warnings);
    assert_true(!array_key_exists('signature_device_use', $normalized));
});

test('an unusable hero blueprint degrades to complete assigned defaults and warns', function () {
    $repairs = [];
    $warnings = [];
    $normalized = HeroBlueprint::normalize('not an object', 'focal-subject-stage', $repairs, $warnings);
    assert_eq(HeroBlueprint::defaultFor('focal-subject-stage'), $normalized);
    assert_eq(1, count($warnings));
    assert_contains('hero_blueprint', $warnings[0]);
    assert_contains('synthesized', $warnings[0]);
});

test('stage_backdrop texture survives on focal-subject-stage and repairs elsewhere (BIGR-776)', function () {
    $raw = HeroBlueprint::defaultFor('focal-subject-stage');
    $raw['stage_backdrop'] = 'texture';
    $repairs = [];
    $warnings = [];
    $normalized = HeroBlueprint::normalize($raw, 'focal-subject-stage', $repairs, $warnings);
    assert_eq('texture', $normalized['stage_backdrop']);
    assert_eq([], $repairs);
    assert_eq([], $warnings);

    $raw = HeroBlueprint::defaultFor('editorial-split');
    $raw['stage_backdrop'] = 'texture';
    $repairs = [];
    $normalized = HeroBlueprint::normalize($raw, 'editorial-split', $repairs, $warnings);
    assert_eq('solid', $normalized['stage_backdrop'], 'texture is repaired to solid on a recipe without the backdrop');
    assert_true(count($repairs) >= 1);

    $repairs = [];
    $garbage = HeroBlueprint::defaultFor('focal-subject-stage');
    $garbage['stage_backdrop'] = 'wallpaper';
    $normalized = HeroBlueprint::normalize($garbage, 'focal-subject-stage', $repairs, $warnings);
    assert_eq('solid', $normalized['stage_backdrop'], 'unknown backdrop values repair to the default');

    $missing = HeroBlueprint::defaultFor('focal-subject-stage');
    unset($missing['stage_backdrop']);
    $repairs = [];
    $normalized = HeroBlueprint::normalize($missing, 'focal-subject-stage', $repairs, $warnings);
    assert_eq('solid', $normalized['stage_backdrop']);
    assert_true(
        count(array_filter($repairs, static fn (string $repair): bool => str_contains($repair, 'stage_backdrop'))) === 1,
        'a keyless blueprint is repaired to the new complete fixed point instead of being mislabeled as one',
    );
});
