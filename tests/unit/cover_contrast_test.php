<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\CoverContrastStep;

const COVER_WHITE = [255, 255, 255];
const COVER_BLACK = [17, 17, 17];
const BLACK_OVERLAY = [['rgb' => [0, 0, 0], 'alpha' => 1.0]];

function cover_candidates(): array
{
    return ['base' => COVER_WHITE, 'contrast' => COVER_BLACK];
}

test('planCover keeps a passing cover unchanged', function () {
    // White heading over a dark image with a 50% black dim: passes easily.
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 3.0]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 50, [40, 40, 40], cover_candidates());
    assert_eq(50, $plan['dim']);
    assert_eq([], $plan['swaps']);
    assert_eq(true, $plan['pass']);
});

test('planCover bumps dim over a bright image until light text reads', function () {
    // White text over a bright image (only readable once heavily dimmed).
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 4.5]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 40, [220, 220, 210], cover_candidates());
    assert_eq(true, $plan['pass']);
    assert_true($plan['dim'] > 40, "expected dim above 40, got {$plan['dim']}");
    assert_eq([], $plan['swaps'], 'the designed color must be kept when dim alone fixes it');
});

test('planCover flips dark text to base when dimming makes things worse', function () {
    // Dark text over a mid image with a black overlay: more dim = darker
    // background = even less contrast for dark text. Flip to base (white).
    $texts = [['index' => 3, 'rgb' => COVER_BLACK, 'threshold' => 4.5]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 40, [120, 120, 120], cover_candidates());
    assert_eq(true, $plan['pass']);
    assert_eq(['3' => 'base'], array_map('strval', $plan['swaps']));
});

test('planCover enforces the 40 floor even when the input dim is lower', function () {
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 3.0]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 0, [40, 40, 40], cover_candidates());
    assert_true($plan['dim'] >= 40, "floor not applied: {$plan['dim']}");
});

test('planCover leaves an over-dimmed cover (dim > 80) as-is when it passes', function () {
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 4.5]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 90, [220, 220, 220], cover_candidates());
    assert_eq(90, $plan['dim']);
    assert_eq(true, $plan['pass']);
});

test('planCover mixed texts: passing text keeps its color, failing one swaps', function () {
    $texts = [
        ['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 3.0],  // fine on dark
        ['index' => 2, 'rgb' => COVER_BLACK, 'threshold' => 4.5],  // dies on dark
    ];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 60, [50, 50, 50], cover_candidates());
    assert_eq(true, $plan['pass']);
    assert_true(!isset($plan['swaps'][1]), 'passing text must not be swapped');
    assert_eq('base', $plan['swaps'][2] ?? null);
});

test('gradient overlays: content position picks the stop the text sits on', function () {
    $overlay = [
        ['rgb' => [0, 0, 0], 'alpha' => 0.1],
        ['rgb' => [0, 0, 0], 'alpha' => 0.8],
    ];
    assert_eq([['rgb' => [0, 0, 0], 'alpha' => 0.8]], CoverContrastStep::overlayForPosition($overlay, 'bottom left'));
    assert_eq([['rgb' => [0, 0, 0], 'alpha' => 0.1]], CoverContrastStep::overlayForPosition($overlay, 'top center'));
    assert_eq($overlay, CoverContrastStep::overlayForPosition($overlay, 'center center'), 'centered = worst case, all stops');
    assert_eq($overlay, CoverContrastStep::overlayForPosition($overlay, ''));
});

test('regionAverage measures the sampled half of a synthetic image', function () {
    if (!extension_loaded('imagick')) {
        return; // environment without imagick: the step skips itself the same way
    }
    // Top half white, bottom half black.
    $im = new Imagick();
    $im->newImage(64, 64, new ImagickPixel('white'));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('black'));
    $draw->rectangle(0, 32, 63, 63);
    $im->drawImage($draw);
    $im->setImageFormat('png');
    $path = tempnam(sys_get_temp_dir(), 'covtest') . '.png';
    file_put_contents($path, $im->getImageBlob());

    $top = CoverContrastStep::regionAverage($path, 'top left');
    $bottom = CoverContrastStep::regionAverage($path, 'bottom left');
    unlink($path);
    assert_true($top[0] > 240, "top half should be white-ish, got {$top[0]}");
    assert_true($bottom[0] < 15, "bottom half should be black-ish, got {$bottom[0]}");
});
