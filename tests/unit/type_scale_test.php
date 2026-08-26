<?php
declare(strict_types=1);

use Automattic\SiteBuild\TypeScale;

test('type-scale catalog derives six ordered roles from one body anchor and ratio', function () {
    $displayMaxima = [
        'compact' => '2.5rem',
        'classic' => '4rem',
        'editorial' => '6rem',
        'dramatic' => '8rem',
        'brutal' => '12rem',
    ];

    assert_eq(array_keys($displayMaxima), TypeScale::ALL);
    foreach ($displayMaxima as $scale => $displayMax) {
        $profile = TypeScale::fontSizes($scale);
        assert_true(is_array($profile));
        assert_eq(
            ['caption', 'body', 'lead', 'heading', 'section-title', 'display'],
            array_column($profile, 'slug'),
            "{$scale} keeps the semantic role order",
        );
        assert_eq('1rem', $profile[1]['size'], "{$scale} uses the one body anchor");
        assert_contains(', ' . $displayMax . ')', $profile[5]['size'], "{$scale} reaches its display anchor");
    }
});

test('type-scale caption floor and fluid upper steps stay safe at both extremes', function () {
    $compact = TypeScale::fontSizes('compact');
    $brutal = TypeScale::fontSizes('brutal');

    assert_eq('0.795rem', $compact[0]['size']);
    assert_eq('0.75rem', $brutal[0]['size'], 'extreme ratios cannot shrink metadata below the floor');
    assert_eq('clamp(1.976rem, 2vw, 1.988rem)', $compact[4]['size']);
    assert_eq('clamp(5.369rem, 12vw, 12rem)', $brutal[5]['size']);
});

test('type-scale keeps heading, section-title and display strictly ordered at every width', function () {
    // Resolve one preset to rendered px at a viewport width (16px root).
    $px = static function (string $size, int $viewport): float {
        if (preg_match('/^clamp\(([\d.]+)rem, ([\d.]+)vw, ([\d.]+)rem\)$/', $size, $m) === 1) {
            return max((float) $m[1] * 16, min((float) $m[2] / 100 * $viewport, (float) $m[3] * 16));
        }
        assert_true(str_ends_with($size, 'rem'), "fixed size {$size} is rem");
        return (float) $size * 16;
    };

    foreach (TypeScale::ALL as $scale) {
        $profile = TypeScale::fontSizes($scale);
        foreach ([390, 768, 1280, 1920] as $viewport) {
            $heading = $px($profile[3]['size'], $viewport);
            $section = $px($profile[4]['size'], $viewport);
            $display = $px($profile[5]['size'], $viewport);
            assert_true(
                $heading < $section,
                "{$scale}@{$viewport}px: heading {$heading} renders below section-title {$section}",
            );
            assert_true(
                $section < $display,
                "{$scale}@{$viewport}px: section-title {$section} renders below display {$display}",
            );
        }
    }
});

test('type-scale rejects absent and unsupported commitments without guessing', function () {
    foreach ([null, '', 'heroic', ['classic'], 7] as $value) {
        assert_eq(null, TypeScale::fontSizes($value));
    }
    assert_eq('editorial', TypeScale::explicit(' Editorial '));
});
