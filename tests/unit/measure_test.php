<?php
declare(strict_types=1);

use Automattic\SiteBuild\Measure;

test('measure catalog maps every bounded commitment to one exact width pair', function () {
    $pairs = [
        'narrow' => ['contentSize' => '640px', 'wideSize' => '1000px'],
        'standard' => ['contentSize' => '860px', 'wideSize' => '1320px'],
        'wide' => ['contentSize' => '960px', 'wideSize' => '1560px'],
        'full' => ['contentSize' => '1040px', 'wideSize' => '1760px'],
    ];

    assert_eq(array_keys($pairs), Measure::ALL);
    foreach ($pairs as $measure => $widths) {
        assert_eq($widths, Measure::widths($measure));
        assert_contains($widths['contentSize'], Measure::meaning($measure));
        assert_contains($widths['wideSize'], Measure::meaning($measure));
    }
});

test('measure rejects absent and unsupported commitments without guessing', function () {
    foreach ([null, '', 'panoramic', ['wide'], 7] as $value) {
        assert_eq(null, Measure::widths($value));
    }
    assert_eq('narrow', Measure::explicit(' Narrow '));
});

test('the widest committed reading column is derived from the catalog', function () {
    // LayoutFixer's stand-down guard reads this instead of a literal, so a new
    // catalog entry cannot fall outside it the way 960px and 1040px did.
    assert_eq(1040.0, Measure::maxContentSizePx());

    $widest = max(array_map(
        static fn (string $m): float => (float) rtrim(Measure::widths($m)['contentSize'], 'px'),
        Measure::ALL,
    ));
    assert_eq($widest, Measure::maxContentSizePx());
});
