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
    assert_eq('clamp(1.581rem, 2vw, 1.988rem)', $compact[4]['size']);
    assert_eq('clamp(3.464rem, 12vw, 12rem)', $brutal[5]['size']);
});

test('type-scale rejects absent and unsupported commitments without guessing', function () {
    foreach ([null, '', 'heroic', ['classic'], 7] as $value) {
        assert_eq(null, TypeScale::fontSizes($value));
    }
    assert_eq('editorial', TypeScale::explicit(' Editorial '));
});
