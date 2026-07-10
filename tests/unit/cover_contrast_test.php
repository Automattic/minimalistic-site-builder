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
    // White heading over a uniformly dark image with a 50% black dim.
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 3.0]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 50, [[30, 30, 30], [40, 40, 40], [60, 60, 60]], cover_candidates());
    assert_eq(50, $plan['dim']);
    assert_eq([], $plan['swaps']);
    assert_eq(null, $plan['overlay']);
    assert_eq(true, $plan['pass']);
});

test('planCover bumps dim over a bright image until light text reads', function () {
    // White text over a uniformly bright image (readable once heavily dimmed).
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 4.5]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 40, [[210, 210, 200], [220, 220, 210], [230, 230, 225]], cover_candidates());
    assert_eq(true, $plan['pass']);
    assert_true($plan['dim'] > 40, "expected dim above 40, got {$plan['dim']}");
    assert_eq([], $plan['swaps'], 'the designed color must be kept when dim alone fixes it');
    assert_eq(null, $plan['overlay']);
});

test('planCover flips dark text to base when dimming makes things worse', function () {
    // Dark text over a mid image with a black overlay: more dim = darker
    // background = even less contrast for dark text. Flip to base (white).
    $texts = [['index' => 3, 'rgb' => COVER_BLACK, 'threshold' => 4.5]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 40, [[110, 110, 110], [120, 120, 120], [130, 130, 130]], cover_candidates());
    assert_eq(true, $plan['pass']);
    assert_eq(['3' => 'base'], array_map('strval', $plan['swaps']));
    assert_eq(null, $plan['overlay']);
});

test('planCover enforces the 40 floor even when the input dim is lower', function () {
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 3.0]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 0, [[40, 40, 40]], cover_candidates());
    assert_true($plan['dim'] >= 40, "floor not applied: {$plan['dim']}");
});

test('planCover leaves an over-dimmed cover (dim > 80) as-is when it passes', function () {
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 4.5]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 90, [[220, 220, 220]], cover_candidates());
    assert_eq(90, $plan['dim']);
    assert_eq(true, $plan['pass']);
});

test('planCover mixed texts: passing text keeps its color, failing one swaps', function () {
    $texts = [
        ['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 3.0],  // fine on dark
        ['index' => 2, 'rgb' => COVER_BLACK, 'threshold' => 4.5],  // dies on dark
    ];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 60, [[45, 45, 45], [50, 50, 50], [55, 55, 55]], cover_candidates());
    assert_eq(true, $plan['pass']);
    assert_true(!isset($plan['swaps'][1]), 'passing text must not be swapped');
    assert_eq('base', $plan['swaps'][2] ?? null);
});

test('planCover escalates to a solid overlay on a busy image under a weak gradient', function () {
    // Region spans near-black to near-white (a crowd/architecture photo)
    // and the designed gradient stop is only 15% alpha: even at max dim the
    // overlay can't tame either end, and no text color passes against BOTH
    // ends — so the overlay must become solid (darker overlay, lighter text).
    $weakStop = [['rgb' => [17, 17, 17], 'alpha' => 0.15]];
    $busy = [[25, 25, 25], [128, 128, 128], [230, 230, 230]];
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 4.5]];
    $plan = CoverContrastStep::planCover($texts, $weakStop, 40, $busy, cover_candidates());
    assert_eq(true, $plan['pass']);
    assert_eq('contrast', $plan['overlay'], 'darker overlay + lighter text preferred');
    assert_eq('base', $plan['swaps'][1] ?? null);
});

test('planCover on a busy region trusts a solid overlay without escalating', function () {
    // Busy region but the cover already has a full-strength dark overlay:
    // effective opacity at dim 50 is 0.5 ≥ the busy minimum, so the designed
    // overlay is kept and only ratios decide.
    $busy = [[25, 25, 25], [128, 128, 128], [230, 230, 230]];
    $texts = [['index' => 1, 'rgb' => COVER_WHITE, 'threshold' => 4.5]];
    $plan = CoverContrastStep::planCover($texts, BLACK_OVERLAY, 50, $busy, cover_candidates());
    assert_eq(true, $plan['pass']);
    assert_eq(null, $plan['overlay'], 'a real overlay must not be replaced');
});

test('planCover on a busy region distrusts ratio-passing text under a weak overlay', function () {
    // Dark text over a busy region with NO effective overlay: the p25 sample
    // ratio may pass, but texture readability requires a real veil — expect
    // the solid-overlay escalation (this is the portfolio12 hero case).
    $zeroAlphaStop = [['rgb' => [17, 17, 17], 'alpha' => 0.0]];
    $busyBright = [[100, 100, 100], [160, 160, 160], [230, 230, 230]]; // spread > 0.25
    $texts = [['index' => 1, 'rgb' => COVER_BLACK, 'threshold' => 3.0]];
    $plan = CoverContrastStep::planCover($texts, $zeroAlphaStop, 50, $busyBright, cover_candidates());
    assert_true($plan['overlay'] !== null, 'expected solid-overlay escalation despite passing flat ratios');
    assert_eq(true, $plan['pass']);
});

test('planCover escalates when the overlay stop behind the content has zero alpha', function () {
    // Gradient darkens the bottom but the content sits at the top: the
    // effective overlay is alpha 0, so no dimRatio helps — and against a
    // busy region no text color passes raw. Must escalate to a solid overlay.
    $zeroAlphaStop = [['rgb' => [17, 17, 17], 'alpha' => 0.0]];
    $busy = [[25, 25, 25], [130, 130, 130], [225, 225, 225]];
    $texts = [['index' => 5, 'rgb' => COVER_BLACK, 'threshold' => 3.0]];
    $plan = CoverContrastStep::planCover($texts, $zeroAlphaStop, 50, $busy, cover_candidates());
    assert_eq(true, $plan['pass']);
    assert_true($plan['overlay'] !== null, 'expected solid-overlay escalation');
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

test('regionStats measures the sampled half and spreads low/high on a split image', function () {
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

    $top = CoverContrastStep::regionStats($path, 'top left');
    $bottom = CoverContrastStep::regionStats($path, 'bottom left');
    // The full center region straddles both halves: low ends dark, high bright.
    $center = CoverContrastStep::regionStats($path, '');
    unlink($path);

    assert_true($top[1][0] > 240, "top half should be white-ish, got {$top[1][0]}");
    assert_true($bottom[1][0] < 15, "bottom half should be black-ish, got {$bottom[1][0]}");
    assert_true($center[0][0] < 15, "center low percentile should be dark, got {$center[0][0]}");
    assert_true($center[2][0] > 240, "center high percentile should be bright, got {$center[2][0]}");
});
