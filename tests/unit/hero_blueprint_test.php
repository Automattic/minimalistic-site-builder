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
            'cta_treatment', 'mobile_transformation', 'signature_device_use',
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
    $raw['signature_device_use'] = 'Place the motif once beside the copy anchor.';
    $context = [
        'signature_device' => 'One token-built corner notch.',
        'signature_device_slots' => ['hero', 'body'],
    ];
    $repairs = [];
    $warnings = [];
    $normalized = HeroBlueprint::normalize($raw, 'editorial-split', $context, $repairs, $warnings);
    assert_eq($raw, $normalized);
    assert_eq([], $repairs);
    assert_eq([], $warnings);

    $againRepairs = [];
    $againWarnings = [];
    assert_eq(
        $normalized,
        HeroBlueprint::normalize($normalized, 'editorial-split', $context, $againRepairs, $againWarnings),
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
    $normalized = HeroBlueprint::normalize($raw, 'editorial-split', [], $repairs, $warnings);

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
        HeroBlueprint::normalize($normalized, 'editorial-split', [], $againRepairs, $againWarnings),
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
    $normalized = HeroBlueprint::normalize($raw, 'cinematic-safe-zone', [], $repairs, $warnings);
    assert_eq('bottom-start', $normalized['text_anchor']);
    assert_eq('start', $normalized['text_safe_region']);
    assert_eq('end', $normalized['focal_region']);
    assert_true(count($repairs) >= 2);
});

test('hero signature use is removed with actionable warning when placement is not assigned', function () {
    $raw = HeroBlueprint::defaultFor('editorial-split');
    $raw['signature_device_use'] = 'Repeat the device across the hero.';
    $repairs = [];
    $warnings = [];
    $normalized = HeroBlueprint::normalize($raw, 'editorial-split', [
        'signature_device' => 'One corner notch.',
        'signature_device_slots' => ['body'],
    ], $repairs, $warnings);
    assert_eq('', $normalized['signature_device_use']);
    assert_eq(1, count($warnings));
    assert_contains('authored', $warnings[0]);
    assert_contains('delivered', $warnings[0]);
    assert_contains('signature_device_use', $warnings[0]);
});

test('an unusable hero blueprint degrades to complete assigned defaults and warns', function () {
    $repairs = [];
    $warnings = [];
    $normalized = HeroBlueprint::normalize('not an object', 'panorama-rail', [], $repairs, $warnings);
    assert_eq(HeroBlueprint::defaultFor('panorama-rail'), $normalized);
    assert_eq(1, count($warnings));
    assert_contains('hero_blueprint', $warnings[0]);
    assert_contains('synthesized', $warnings[0]);
});
