<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageKind;
use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

test('image kind is a closed vocabulary with one style keyword and one render clause each (frm W7a)', function () {
    assert_eq(['photo', '3d-object', 'ui-mockup', 'line-illustration', 'abstract-gradient'], ImageKind::ALL);
    assert_eq('photo', ImageKind::DEFAULT);
    assert_eq('', ImageKind::promptClause('photo'), 'a photo series is described by the grade alone');
    assert_eq('', ImageKind::promptClause(null));
    assert_eq('', ImageKind::promptClause('hologram'), 'an unknown kind falls back to photo');
    foreach (['3d-object', 'ui-mockup', 'line-illustration', 'abstract-gradient'] as $kind) {
        $clause = ImageKind::promptClause($kind);
        assert_contains('Imagery kind for all site imagery:', $clause);
        assert_true(!str_contains(ImageKind::meaning($kind), 'photograph') || $kind === 'photo');
    }
    assert_contains('no readable words', ImageKind::promptClause('ui-mockup'));
    assert_eq('3d-render', ImageKind::styleKeyword('3d-object'));
    assert_eq('flat-design', ImageKind::styleKeyword('ui-mockup'));
    assert_eq('illustration', ImageKind::styleKeyword('line-illustration'));
    assert_eq('abstract', ImageKind::styleKeyword('abstract-gradient'));
    assert_eq('photorealistic', ImageKind::styleKeyword('nonsense'));
});

test('the composer appends the imagery kind as a render instruction, transparent assets included (frm W7a)', function () {
    $plain = ImagePromptComposer::compose('A clay sphere and a torus', 'hero backdrop', '3d-render', 'A design studio.', 'Full colour, hard studio light.', false, null, 'landscape', '3d-object');
    assert_contains('Art direction for all site imagery: Full colour, hard studio light.', $plain);
    assert_contains('Imagery kind for all site imagery: smooth matte clay-like 3D objects', $plain);
    assert_true(strpos($plain, 'Imagery kind') > strpos($plain, 'Art direction'), 'the kind rides with the grade');

    $transparent = ImagePromptComposer::compose('A clay sphere', 'floating object', '3d-render', '', 'Full colour.', true, null, '', '3d-object');
    assert_true(!str_contains($transparent, 'Art direction'), 'a transparent asset skips the grade');
    assert_contains('Imagery kind for all site imagery', $transparent, 'but keeps the kind');

    $photo = ImagePromptComposer::compose('A loaf on a board', 'menu card', 'photorealistic', '', 'Warm film.', false, null, '', 'photo');
    assert_true(!str_contains($photo, 'Imagery kind'), 'a photo series adds no kind clause');
    assert_eq($photo, ImagePromptComposer::compose('A loaf on a board', 'menu card', 'photorealistic', '', 'Warm film.'), 'the default is byte-identical to the pre-field prompt');
});

test('the direction normalizes, persists, formats and reads image_kind (frm W7a)', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(['description' => 'x', 'image_kind' => ' UI-Mockup '], 'cinematic-safe-zone', 'seed', $repairs, $warnings);
    assert_eq('ui-mockup', $direction['image_kind']);
    $stray = DesignDirectionStep::normalize(['description' => 'x', 'image_kind' => 'hologram'], 'cinematic-safe-zone', 'seed', $repairs, $warnings);
    assert_eq('photo', $stray['image_kind']);
    assert_true(count(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'image_kind'))) === 1);
    assert_eq('photo', DesignDirectionStep::normalize(['description' => 'x'], 'cinematic-safe-zone')['image_kind']);
    assert_eq('photo', DesignDirectionStep::fallbackDirection('seed', 'cinematic-safe-zone')['image_kind']);

    $fact = DesignDirectionStep::format(['description' => 'x', 'image_kind' => '3d-object']);
    assert_contains('**Image kind**: 3d-object', $fact);
    assert_contains('style keyword `3d-render`', $fact);
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x', 'image_kind' => 'photo']), 'Image kind'), 'photo states no fact');

    with_project('frm-image-kind', function ($project): void {
        assert_eq('photo', DesignDirectionStep::imageKindFor($project));
        $project->writeJson('designDirection.json', ['description' => 'x', 'image_kind' => 'line-illustration']);
        assert_eq('line-illustration', DesignDirectionStep::imageKindFor($project));
    });
});
