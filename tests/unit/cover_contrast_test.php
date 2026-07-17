<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\CoverContrastStep;
use Automattic\SiteBuild\Steps\GenerateImagesStep;

const COVER_WHITE = [255, 255, 255];
const COVER_BLACK = [17, 17, 17];
const BLACK_OVERLAY = [['rgb' => [0, 0, 0], 'alpha' => 1.0]];

function cover_candidates(): array
{
    return ['base' => COVER_WHITE, 'contrast' => COVER_BLACK];
}

test('cover-contrast declaration does not depend on the shared contrast report', function () {
    $fixer = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return 'block-fixer: ok';
        }
    };
    $declaration = (new CoverContrastStep($fixer))->declaration();

    assert_true(in_array(GenerateImagesStep::COMPLETION_ARTIFACT, $declaration->reads, true));
    assert_true(!in_array('logs/contrast-report.txt', $declaration->reads, true));
    assert_true(!in_array('logs/contrast-report.txt', $declaration->writes, true));
    assert_true(!in_array('logs/cover-contrast-report.txt', $declaration->writes, true));
});

test('cover-contrast refuses to run without image-generation completion', function () {
    $tmp = sys_get_temp_dir() . '/builder_cover_order_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $fixer = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return 'block-fixer: ok';
        }
    };

    assert_throws(fn () => (new CoverContrastStep($fixer))->run($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

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

test('gradient overlays: stop selection respects the gradient direction', function () {
    $overlay = [
        ['rgb' => [0, 0, 0], 'alpha' => 0.1],
        ['rgb' => [0, 0, 0], 'alpha' => 0.8],
    ];
    $up = 'linear-gradient(0deg, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.8) 100%)';
    // 0deg runs bottom→top: the FIRST stop is at the bottom of the cover.
    assert_eq([$overlay[0]], CoverContrastStep::overlayForPosition($overlay, 'bottom left', $up));
    assert_eq([$overlay[1]], CoverContrastStep::overlayForPosition($overlay, 'top center', $up));
    $down = 'linear-gradient(to bottom, rgba(0,0,0,0.1), rgba(0,0,0,0.8))';
    assert_eq([$overlay[1]], CoverContrastStep::overlayForPosition($overlay, 'bottom left', $down));
    // Horizontal or angled gradients have no vertical stop order to trust.
    $angled = 'linear-gradient(135deg, rgba(0,0,0,0.1), rgba(0,0,0,0.8))';
    assert_eq($overlay, CoverContrastStep::overlayForPosition($overlay, 'bottom left', $angled));
    $horizontal = 'linear-gradient(to right, rgba(0,0,0,0.1), rgba(0,0,0,0.8))';
    assert_eq($overlay, CoverContrastStep::overlayForPosition($overlay, 'top left', $horizontal));
});

/** Scaffold a temp project with theme.json, one template and a flat cover image. */
function cover_step_project(string $markup, string $imageColor): array
{
    $tmp = sys_get_temp_dir() . '/builder_cover_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', [
        'version'  => 3,
        'settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#1D4ED8', 'name' => 'Primary'],
        ]]],
        'styles'   => ['elements' => ['link' => ['color' => ['text' => 'var(--wp--preset--color--primary)']]]],
    ]);
    $project->writeJson(GenerateImagesStep::COMPLETION_ARTIFACT, ['status' => 'completed']);
    $project->writeText('theme/templates/front-page.html', $markup);
    $im = new Imagick();
    $im->newImage(64, 64, new ImagickPixel($imageColor));
    $im->setImageFormat('png');
    $project->writeText('theme/assets/hero.png', $im->getImageBlob());
    return [$project, $tmp];
}

function cover_step_run(Project $project, bool $failFixer = false): void
{
    $fixer = $failFixer
        ? new class implements BlockFixer {
            public function fix(string $themeDir): string
            {
                throw new \RuntimeException('node exploded');
            }
        }
        : new class implements BlockFixer {
            public function fix(string $themeDir): string
            {
                return 'block-fixer: ok';
            }
        };
    ob_start();
    (new CoverContrastStep($fixer))->run($project);
    ob_end_clean();
}

test('cover-contrast writes its own report without changing the shared contrast report', function () {
    $tmp = sys_get_temp_dir() . '/builder_cover_report_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson(GenerateImagesStep::COMPLETION_ARTIFACT, ['status' => 'completed']);
    $project->writeText(
        'theme/templates/front-page.html',
        '<!-- wp:cover {"url":"theme:./assets/broken.png"} -->'
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Cover text</p><!-- /wp:paragraph -->'
        . '</div></div><!-- /wp:cover -->',
    );
    $project->writeText('theme/assets/broken.png', 'not an image');
    $project->writeText('logs/contrast-report.txt', "phase-one report\n");

    cover_step_run($project);

    assert_eq("phase-one report\n", $project->readText('logs/contrast-report.txt'));
    assert_contains(
        '-- cover contrast (measured against generated images) --',
        $project->readText('logs/cover-contrast-report.txt'),
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('cover links are checked against the real image and pinned on the cover', function () {
    if (!extension_loaded('imagick')) {
        return;
    }
    // White text passes on the black photo, but the anchor renders the
    // theme's primary link default — unreadable on black. Phase one deferred
    // it (unknown background); this step must catch and pin it.
    $markup = '<!-- wp:cover {"url":"theme:./assets/hero.png","dimRatio":50} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph {"textColor":"base"} --><p><a href="/menu">See the menu</a></p><!-- /wp:paragraph -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    [$project, $tmp] = cover_step_project($markup, 'black');
    cover_step_run($project);
    $out = $project->readText('theme/templates/front-page.html');
    assert_contains('"link":{"color":{"text":"var:preset|color|base"}', $out, 'cover must pin a readable link color');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('cover text inside an opaque descendant background is left alone', function () {
    if (!extension_loaded('imagick')) {
        return;
    }
    // The group paints base; its contrast text is correct (phase one's call).
    // Judging it against the black photo would wrongly flip it to base.
    $markup = '<!-- wp:cover {"url":"theme:./assets/hero.png","dimRatio":50} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:group {"backgroundColor":"base"} --><div class="wp-block-group has-base-background-color has-background">'
        . '<!-- wp:paragraph {"textColor":"contrast"} --><p>Dark on light, correct</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    [$project, $tmp] = cover_step_project($markup, 'black');
    cover_step_run($project);
    $out = $project->readText('theme/templates/front-page.html');
    assert_contains('{"textColor":"contrast"}', $out, 'text on its own background must not be swapped');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('unstyled cover text is modeled as core white, not theme contrast', function () {
    if (!extension_loaded('imagick')) {
        return;
    }
    // On a bright photo the old contrast-default model passed at dim 40;
    // WordPress actually renders white, which fails — the dim must rise.
    $markup = '<!-- wp:cover {"url":"theme:./assets/hero.png","dimRatio":40} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Unstyled over a bright photo</p><!-- /wp:paragraph -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    [$project, $tmp] = cover_step_project($markup, 'white');
    cover_step_run($project);
    $out = $project->readText('theme/templates/front-page.html');
    assert_true(!str_contains($out, '"dimRatio":40'), 'a bright image under white text cannot stay at dim 40');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a fixer failure rolls the persisted cover repairs back', function () {
    if (!extension_loaded('imagick')) {
        return;
    }
    $markup = '<!-- wp:cover {"url":"theme:./assets/hero.png","dimRatio":40} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Unstyled over a bright photo</p><!-- /wp:paragraph -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    [$project, $tmp] = cover_step_project($markup, 'white');
    cover_step_run($project, failFixer: true);
    assert_eq($markup, $project->readText('theme/templates/front-page.html'),
        'attribute edits must not ship without the fixer re-sync');
    assert_contains('rolled back', $project->readText('logs/cover-contrast-report.txt'));
    exec('rm -rf ' . escapeshellarg($tmp));
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

test('covers in the content plugin pages are verified and repaired too', function () {
    if (!extension_loaded('imagick')) {
        return;
    }
    // After assemble-pages every page's sections live in plugin/pages/*, not
    // theme parts — the step must follow the content there.
    $markup = '<!-- wp:cover {"url":"theme:./assets/hero.png","dimRatio":40} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Unstyled over a bright photo</p><!-- /wp:paragraph -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    [$project, $tmp] = cover_step_project(
        '<!-- wp:template-part {"slug":"header"} /-->',
        'white'
    );
    $project->writeText('plugin/pages/menu.html', $markup);
    cover_step_run($project);
    $out = $project->readText('plugin/pages/menu.html');
    assert_true(!str_contains($out, '"dimRatio":40'), 'a plugin-page cover must be re-checked against the real image');
    assert_contains('plugin/pages/menu.html', $project->readText('logs/cover-contrast-report.txt'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a fixer failure rolls plugin-page cover repairs back too', function () {
    if (!extension_loaded('imagick')) {
        return;
    }
    $markup = '<!-- wp:cover {"url":"theme:./assets/hero.png","dimRatio":40} -->' . "\n"
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Unstyled over a bright photo</p><!-- /wp:paragraph -->'
        . '</div></div>' . "\n" . '<!-- /wp:cover -->';
    [$project, $tmp] = cover_step_project(
        '<!-- wp:template-part {"slug":"header"} /-->',
        'white'
    );
    $project->writeText('plugin/pages/menu.html', $markup);
    cover_step_run($project, failFixer: true);
    assert_eq($markup, $project->readText('plugin/pages/menu.html'),
        'plugin-page attribute edits must not ship without the fixer re-sync');
    exec('rm -rf ' . escapeshellarg($tmp));
});
