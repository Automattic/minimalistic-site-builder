<?php
declare(strict_types=1);

use Automattic\SiteBuild\BandColor;
use Automattic\SiteBuild\GroundTint;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Units\BandSurfaceContract;

test('BandColor derives a same-family surface ten lightness points from light and dark bases', function () {
    foreach (['#F4EBDA', '#17181A', '#4E6352', '#3300CC', '#556600', '#BB9988'] as $base) {
        $band = BandColor::fromBase($base);
        assert_true(is_string($band), "{$base} produces a band");
        assert_true(BandColor::valid($base, $band), "{$base} and {$band} satisfy the closed contract");
        assert_eq(GroundTint::classify($base), GroundTint::classify($band));
        assert_true(abs(abs((float) BandColor::lightness($base) - (float) BandColor::lightness($band)) - 0.10) < 0.01);
        assert_eq(BandColor::lightness($base) < 0.5, BandColor::lightness($band) < 0.5);
    }
});

test('BandColor rejects same-color, wrong-family, over-wide, and key-crossing surfaces', function () {
    assert_true(!BandColor::valid('#F4EBDA', '#F4EBDA'), 'a zero-delta band is inert');
    assert_true(!BandColor::valid('#F4EBDA', '#D8E9F4'), 'a cool band drifts from a warm base');
    assert_true(!BandColor::valid('#F4EBDA', '#735B31'), 'an over-wide step is not a band');
    assert_true(!BandColor::valid('#707070', '#898989'), 'the surface may not cross the light/dark key');
});

test('design direction repairs a missing or drifted band after committing the ground', function () {
    $raw = [
        'description' => 'A warm editorial field.',
        'ground_tint' => 'warm',
        'palette' => [
            'base' => '#F4EBDA',
            'contrast' => '#201A12',
            'primary' => '#684E28',
            'secondary' => '#725D3E',
            'accent' => '#A3441D',
            'band' => '#D8E9F4',
        ],
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ];
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize($raw, repairs: $repairs, warnings: $warnings);

    assert_true(is_array($direction));
    assert_true(BandColor::valid($direction['palette']['base'], $direction['palette']['band']));
    assert_true($direction['palette']['band'] !== '#D8E9F4');
    assert_contains('palette.band', implode("\n", $repairs));
    assert_eq([], $warnings);

    $againRepairs = [];
    $againWarnings = [];
    $again = DesignDirectionStep::normalize($direction, repairs: $againRepairs, warnings: $againWarnings);
    assert_eq($direction, $again, 'the committed band reaches a fixed point');
    assert_true(!str_contains(implode("\n", $againRepairs), 'palette.band'));
});

test('theme-json writes back and repairs the committed band relation', function () {
    $theme = valid_theme_payload();
    foreach ($theme['settings']['color']['palette'] as &$entry) {
        if ($entry['slug'] === 'base') {
            $entry['color'] = '#F4EBDA';
        }
        if ($entry['slug'] === 'band') {
            $entry['color'] = '#4F6F48';
        }
    }
    unset($entry);

    [$fixed, $warnings, $repairs] = ThemeJsonStep::repairColors($theme, ['band' => '#D8E9F4']);
    $palette = array_column($fixed['settings']['color']['palette'], 'color', 'slug');
    assert_true(BandColor::valid($palette['base'], $palette['band']));
    assert_true($palette['band'] !== '#D8E9F4', 'an invalid direction value cannot survive writeback');
    assert_contains("palette slug 'band'", implode("\n", $repairs));
    assert_eq([], $warnings);

    [$again, $againWarnings, $againRepairs] = ThemeJsonStep::repairColors($fixed, ['band' => $palette['band']]);
    assert_eq($fixed, $again);
    assert_eq([], $againWarnings);
    assert_eq([], $againRepairs);
});

test('BandSurfaceContract resolves tinted roots to band and removes competing broad fills', function () {
    $raw = '<!-- wp:group {"backgroundColor":"secondary","gradient":"soft-wash","style":{"color":{"background":"#abcdef","gradient":"linear-gradient(red,blue)"}},"className":"keep has-secondary-background-color has-background"} -->'
        . '<div class="wp-block-group keep has-secondary-background-color has-background" style="background:#abcdef">'
        . '<!-- wp:paragraph --><p>Surviving copy.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    $first = BandSurfaceContract::enforce($raw, 'tinted', 'page-home--process');
    assert_contains('"backgroundColor":"band"', $first->markup);
    assert_contains('has-band-background-color', $first->markup);
    assert_contains('Surviving copy.', $first->markup);
    assert_true(!str_contains($first->markup, 'secondary-background'));
    assert_true(!str_contains($first->markup, 'soft-wash'));
    assert_true(!str_contains($first->markup, '#abcdef'));
    assert_eq([], $first->warnings);
    assert_true(in_array('tinted-band-surface-enforced', array_column($first->repairs, 'code'), true));

    $second = BandSurfaceContract::enforce($first->markup, 'tinted', 'page-home--process');
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->repairs);
    assert_eq([], $second->warnings);
});

test('BandSurfaceContract preserves the isolated unit and warns when no block root can be repaired', function () {
    $raw = '<p>usable pre-transformation bytes</p>';
    $result = BandSurfaceContract::enforce($raw, 'tinted', 'page-home--process');

    assert_eq($raw, $result->markup);
    assert_eq([], $result->repairs);
    assert_eq(1, count($result->warnings));
    foreach (["file='generated section page-home--process'", "block='root'", 'authored=tinted', 'delivered=pre-transformation markup', 'disposition='] as $context) {
        assert_contains($context, $result->warnings[0]);
    }
});

test('BandSurfaceContract warns actionably when opaque root styling must be removed', function () {
    $raw = '<!-- wp:group {"style":"background:#abcdef; color:red"} -->'
        . '<div class="wp-block-group"><p>Safe sibling content.</p></div><!-- /wp:group -->';
    $result = BandSurfaceContract::enforce($raw, 'tinted', 'page-home--process');

    assert_contains('Safe sibling content.', $result->markup);
    assert_contains('"backgroundColor":"band"', $result->markup);
    assert_eq(1, count($result->warnings));
    foreach (["file='parts/page-home--process.html'", "block='root wp:group'", 'authored style=', 'delivered=removed', 'disposition='] as $context) {
        assert_contains($context, $result->warnings[0]);
    }
});
