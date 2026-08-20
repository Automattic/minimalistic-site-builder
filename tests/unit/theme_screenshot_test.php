<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\ThemeScreenshotStep;
use Automattic\SiteBuild\ThemeScreenshot;

/**
 * The theme's preview card. WordPress reads it from screenshot.<ext> in the
 * theme root at 1200x900 and shows the grey placeholder when no such file
 * exists, so the step always writes one: the front page's hero image cropped
 * to JPEG when the build generated pixels, a PNG poster composed from the
 * theme palette when it did not.
 *
 * Fixtures are built through whichever image runtime is loaded, so the suite
 * covers the Imagick path where that extension is present and the GD path
 * where it is not. A runtime with neither registers these as explicit skips,
 * matching the step's own behavior.
 */

/**
 * JPEG bytes: a $w x $h canvas of one solid RGB color, optionally with a
 * differently colored strip down the left twelfth (to prove a centred crop
 * drops it).
 *
 * These helpers read and write through whichever runtime is loaded, exactly
 * like the code under test, so the suite covers the Imagick path on a runner
 * that has it and the GD path on one that does not — and skips only where
 * ThemeScreenshot itself would produce nothing.
 *
 * @param array{0:int,1:int,2:int}|null $leftEdge
 */
function screenshot_fixture(
    int $r,
    int $g,
    int $b,
    int $w = 400,
    int $h = 400,
    ?array $leftEdge = null
): string {
    if (extension_loaded('imagick')) {
        $im = new Imagick();
        $im->newImage($w, $h, sprintf('#%02X%02X%02X', $r, $g, $b), 'jpeg');
        if ($leftEdge !== null) {
            $draw = new ImagickDraw();
            $draw->setFillColor(new ImagickPixel(sprintf('#%02X%02X%02X', ...$leftEdge)));
            $draw->rectangle(0, 0, intdiv($w, 12) - 1, $h - 1);
            $im->drawImage($draw);
        }
        $im->setImageCompressionQuality(95);
        $bytes = $im->getImageBlob();
        $im->clear();
        return $bytes;
    }

    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($im, $r, $g, $b));
    if ($leftEdge !== null) {
        imagefilledrectangle(
            $im,
            0,
            0,
            intdiv($w, 12) - 1,
            $h - 1,
            (int) imagecolorallocate($im, ...$leftEdge)
        );
    }
    ob_start();
    imagejpeg($im, null, 95);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);
    return $bytes;
}

/** @return array{0:int,1:int,2:int} The RGB of the pixel at ($x, $y). */
function screenshot_pixel(string $bytes, int $x, int $y): array
{
    if (extension_loaded('imagick')) {
        $im = new Imagick();
        $im->readImageBlob($bytes);
        $pixel = $im->getImagePixelColor($x, $y)->getColor();
        $im->clear();
        return [(int) $pixel['r'], (int) $pixel['g'], (int) $pixel['b']];
    }

    $im = imagecreatefromstring($bytes);
    assert_true($im !== false, 'screenshot bytes decode as an image');
    // Flat colour is written as a palette PNG, where imagecolorat returns the
    // palette index rather than a packed RGB.
    if (!imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    $rgb = imagecolorat($im, $x, $y);
    imagedestroy($im);
    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

/** @return array{0:int,1:int} The width and height of the card bytes. */
function screenshot_size(string $bytes): array
{
    if (extension_loaded('imagick')) {
        $im = new Imagick();
        $im->readImageBlob($bytes);
        $size = [$im->getImageWidth(), $im->getImageHeight()];
        $im->clear();
        return $size;
    }

    $im = imagecreatefromstring($bytes);
    assert_true($im !== false, 'screenshot bytes decode as an image');
    $size = [imagesx($im), imagesy($im)];
    imagedestroy($im);
    return $size;
}

/** Assert two colors match within $tolerance per channel (JPEG is lossy). */
function assert_color_near(array $expected, array $actual, int $tolerance = 12, string $msg = ''): void
{
    foreach ($expected as $i => $channel) {
        assert_true(
            abs($channel - $actual[$i]) <= $tolerance,
            'expected rgb(' . implode(',', $expected) . ') got rgb(' . implode(',', $actual) . ')'
            . ($msg !== '' ? " — {$msg}" : '')
        );
    }
}

/**
 * The bytes of whichever card the step wrote, asserting there is exactly one:
 * WordPress prefers screenshot.png over screenshot.jpg, so a leftover poster
 * beside a real screenshot would be the one it shows.
 */
function screenshot_card(Project $project): string
{
    $found = array_values(array_filter(
        ['theme/screenshot.jpg', 'theme/screenshot.png'],
        static fn (string $path) => $project->exists($path)
    ));
    assert_eq(1, count($found), 'exactly one screenshot card ships: ' . implode(', ', $found));
    return $project->readText($found[0]);
}

/** Seed the front page markup the step scans for the hero image reference. */
function screenshot_front_page(Project $project, string $markup): void
{
    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'front' => true],
    ]]);
    $project->writeText('plugin/pages/home.html', $markup);
}

/** A minimal theme.json carrying only the palette the poster draws from. */
function screenshot_theme_json(Project $project, array $palette): void
{
    $entries = [];
    foreach ($palette as $slug => $color) {
        $entries[] = ['slug' => $slug, 'name' => ucfirst($slug), 'color' => $color];
    }
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => $entries]]]);
}

/** Skip a project-free case on a runtime that can draw no card at all. */
function screenshot_require_image_runtime(): void
{
    if (!ThemeScreenshot::available()) {
        skip_test('theme-screenshot needs imagick or gd');
    }
}

/**
 * Register a case that needs an image runtime and a throwaway project. An
 * explicit skip, not the file-level return the imagick tests use: a runner
 * that can draw nothing should say the coverage was skipped rather than
 * register it as a pass.
 */
function shot_test(string $name, callable $fn): void
{
    test($name, function () use ($fn) {
        screenshot_require_image_runtime();
        with_project('builder_shot_', $fn);
    });
}

shot_test('theme-screenshot crops the front page hero image to the WordPress card size', function (Project $project) {
    // 400x400 source, 1200x900 target: a centered cover crop keeps the
    // middle band, so every sampled pixel is the source color.
    $project->writeText('theme/assets/hero.jpg', screenshot_fixture(200, 40, 40));
    screenshot_front_page($project, '<!-- wp:image --><img src="theme:./assets/hero.jpg" /><!-- /wp:image -->');
    screenshot_theme_json($project, ['base' => '#FFFFFF', 'contrast' => '#111111']);

    quietly(fn () => (new ThemeScreenshotStep())->run($project));

    // JPEG, not PNG: the same crop stored losslessly is over a megabyte in
    // every generated theme.
    assert_true($project->exists('theme/screenshot.jpg'), 'a cropped photo ships as screenshot.jpg');
    $card = screenshot_card($project);
    assert_eq([ThemeScreenshot::WIDTH, ThemeScreenshot::HEIGHT], screenshot_size($card));
    assert_color_near([200, 40, 40], screenshot_pixel($card, 600, 450));
    // Nothing degraded, so nothing is recorded for a repair pass to chase.
    assert_true(!$project->exists('warnings.json'), 'a clean crop warns about nothing');
});

shot_test('theme-screenshot takes the front page images in document order', function (Project $project) {
    $project->writeText('theme/assets/hero.jpg', screenshot_fixture(20, 120, 220));
    $project->writeText('theme/assets/later.jpg', screenshot_fixture(240, 200, 20));
    screenshot_front_page(
        $project,
        '<img src="theme:./assets/hero.jpg" /><img src="theme:./assets/later.jpg" />'
    );

    quietly(fn () => (new ThemeScreenshotStep())->run($project));

    // The hero is the front page's first section, so its image is the
    // first reference in the assembled markup.
    assert_color_near([20, 120, 220], screenshot_pixel(screenshot_card($project), 600, 450));
});

shot_test('theme-screenshot skips decorative .png assets and uses the photo behind them', function (Project $project) {
    // The image pipeline reserves .png for assets keyed to transparency —
    // ornaments on a flat background, not a preview of the whole site.
    $project->writeText('theme/assets/flourish.png', screenshot_fixture(10, 10, 10));
    $project->writeText('theme/assets/hero.jpg', screenshot_fixture(30, 180, 90));
    screenshot_front_page(
        $project,
        '<img src="theme:./assets/flourish.png" /><img src="theme:./assets/hero.jpg" />'
    );

    quietly(fn () => (new ThemeScreenshotStep())->run($project));

    assert_color_near([30, 180, 90], screenshot_pixel(screenshot_card($project), 600, 450));
});

shot_test('theme-screenshot reads the post-generation asset URLs too', function (Project $project) {
    $project->writeText('theme/assets/hero.jpg', screenshot_fixture(120, 60, 200));
    // generate-images rewrites theme:./assets/<file> to the served path
    // once the real pixels exist; both spellings must resolve.
    screenshot_front_page($project, '<img src="/wp-content/themes/demo/assets/hero.jpg" />');

    quietly(fn () => (new ThemeScreenshotStep())->run($project));

    assert_color_near([120, 60, 200], screenshot_pixel(screenshot_card($project), 600, 450));
});

shot_test('theme-screenshot falls back to images.json when the front page has no photo', function (Project $project) {
    // A site whose only picture lives in the shared header never appears
    // in the page content; the generated-image manifest still names it.
    $project->writeText('theme/assets/masthead.jpg', screenshot_fixture(15, 90, 75));
    screenshot_front_page($project, '<p>No images in the content.</p>');
    $project->writeJson('images.json', [
        ['filename' => 'missing.jpg', 'status' => 'failed'],
        ['filename' => 'masthead.jpg', 'status' => 'completed'],
    ]);

    quietly(fn () => (new ThemeScreenshotStep())->run($project));

    assert_color_near([15, 90, 75], screenshot_pixel(screenshot_card($project), 600, 450));
});

shot_test('theme-screenshot composes a palette poster when the build generated no images', function (Project $project) {
    screenshot_theme_json($project, [
        'base'      => '#FDF6EC',
        'contrast'  => '#14213D',
        'accent'    => '#E5533C',
        'secondary' => '#8D99AE',
    ]);

    quietly(fn () => (new ThemeScreenshotStep())->run($project));

    // PNG, not JPEG: flat rectangles compress to a few kilobytes and would
    // ring at every edge as JPEG.
    assert_true($project->exists('theme/screenshot.png'), 'the flat poster ships as screenshot.png');
    $png = screenshot_card($project);
    assert_eq([ThemeScreenshot::WIDTH, ThemeScreenshot::HEIGHT], screenshot_size($png));
    // The poster is a miniature page: a hero band in the contrast color
    // over the page background, with a headline bar and an accent button.
    assert_color_near([20, 33, 61], screenshot_pixel($png, 1100, 100), 2, 'hero band');
    assert_color_near([253, 246, 236], screenshot_pixel($png, 600, 230), 2, 'headline bar');
    assert_color_near([229, 83, 60], screenshot_pixel($png, 200, 490), 2, 'button');
    assert_color_near([253, 246, 236], screenshot_pixel($png, 600, 600), 2, 'page background');
    assert_color_near([141, 153, 174], screenshot_pixel($png, 200, 700), 2, 'card');
    // A build without images is a normal build, not a degraded one.
    assert_true(!$project->exists('warnings.json'), 'the poster path warns about nothing');
});

shot_test('theme-screenshot survives a palette it cannot read', function (Project $project) {
    // theme.json allows color values a rectangle fill has no way to use.
    screenshot_theme_json($project, ['base' => 'var(--brand)', 'contrast' => 'hsl(210 40% 20%)']);

    quietly(fn () => (new ThemeScreenshotStep())->run($project));

    assert_eq(
        [ThemeScreenshot::WIDTH, ThemeScreenshot::HEIGHT],
        screenshot_size(screenshot_card($project))
    );
});

shot_test('theme-screenshot skips an undecodable asset and still ships the real card', function (Project $project) {
    $project->writeText('theme/assets/hero.jpg', 'this is not an image');
    $project->writeText('theme/assets/second.jpg', screenshot_fixture(70, 70, 200));
    screenshot_front_page(
        $project,
        '<img src="theme:./assets/hero.jpg" /><img src="theme:./assets/second.jpg" />'
    );

    quietly(fn () => (new ThemeScreenshotStep())->run($project));

    // One broken asset must not cost the real screenshot.
    assert_color_near([70, 70, 200], screenshot_pixel(screenshot_card($project), 600, 450));
    // And the successful crop clears the failure: warnings.json is the
    // repair pass's queue, so a row that no longer describes the delivered
    // theme would send it chasing a defect that isn't there.
    assert_true(!$project->exists('warnings.json'), 'a later success clears the earlier attempt');
});

shot_test('theme-screenshot records the reason when every candidate is undecodable', function (Project $project) {
    $project->writeText('theme/assets/hero.jpg', 'this is not an image');
    screenshot_front_page($project, '<img src="theme:./assets/hero.jpg" />');
    screenshot_theme_json($project, ['base' => '#FFFFFF', 'contrast' => '#111111']);

    quietly(fn () => (new ThemeScreenshotStep())->run($project));

    // Degrading to the poster still ships a card, and says why.
    assert_eq(
        [ThemeScreenshot::WIDTH, ThemeScreenshot::HEIGHT],
        screenshot_size(screenshot_card($project))
    );
    $warnings = $project->readJson('warnings.json')['theme-screenshot'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('assets/hero.jpg', $warnings[0]);
});

shot_test('theme-screenshot replaces the poster once the real hero exists', function (Project $project) {
    screenshot_theme_json($project, ['base' => '#FFFFFF', 'contrast' => '#101010']);
    $step = new ThemeScreenshotStep();

    // First run: in-pipeline, before the opt-in image step has run.
    quietly(fn () => $step->run($project));
    assert_color_near([16, 16, 16], screenshot_pixel(screenshot_card($project), 600, 100), 2);

    // Second run: the host generated images and ran the step again.
    $project->writeText('theme/assets/hero.jpg', screenshot_fixture(220, 130, 40));
    screenshot_front_page($project, '<img src="theme:./assets/hero.jpg" />');
    quietly(fn () => $step->run($project));

    assert_color_near([220, 130, 40], screenshot_pixel(screenshot_card($project), 600, 450));
});

test('ThemeScreenshot::cover centers the crop on the long axis', function () {
    screenshot_require_image_runtime();
    // A wide source: the cover crop keeps the middle and drops the sides, so a
    // marked left edge must not survive into the card.
    $png = ThemeScreenshot::cover(screenshot_fixture(0, 160, 0, 800, 300, [255, 0, 0]));
    assert_true($png !== null, 'the crop produced bytes');
    assert_eq([ThemeScreenshot::WIDTH, ThemeScreenshot::HEIGHT], screenshot_size($png));
    assert_color_near([0, 160, 0], screenshot_pixel($png, 5, 450), 12, 'the red left edge was cropped away');
});

test('ThemeScreenshot::cover returns null for bytes that are not an image', function () {
    screenshot_require_image_runtime();
    assert_eq(null, ThemeScreenshot::cover('nope'));
});
