<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\HeaderHeroStep;
use Automattic\SiteBuild\Steps\SectionsStep;

/**
 * Unit tests for HeaderHeroStep: the deterministic backstop for the
 * header/hero composition contract (BIGR-735).
 */

/** A minimal stacked-style header part with the given top-level attrs JSON. */
function hh_header(string $topAttrs, string $inner = '<!-- wp:site-title /-->'): string
{
    return '<!-- wp:group ' . $topAttrs . ' -->' . "\n"
        . '<div class="wp-block-group">' . $inner . '</div>' . "\n"
        . '<!-- /wp:group -->';
}

/** A hero part holding one cover with the given minHeight in vh. */
function hh_cover(string $height): string
{
    return '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group">'
        . '<!-- wp:cover {"url":"x.jpg","dimRatio":50,"minHeight":' . $height . ',"minHeightUnit":"vh","align":"full"} -->'
        . '<div class="wp-block-cover alignfull" style="min-height:' . $height . 'vh">'
        . '<!-- wp:heading {"level":1,"fontSize":"display"} --><h1 class="wp-block-heading has-display-font-size">Masthead</h1><!-- /wp:heading -->'
        . '</div>'
        . '<!-- /wp:cover --></div>' . "\n"
        . '<!-- /wp:group -->';
}

test('overlay mode wires the header-overlay class and strips the opaque chrome', function () {
    $markup = hh_header('{"backgroundColor":"base","style":{"position":{"type":"sticky","top":"0px"},"spacing":{"padding":{"top":"var:preset|spacing|sm"}}},"layout":{"type":"constrained"}}');
    $result = HeaderHeroStep::fixHeader($markup, SectionsStep::MODE_OVERLAY, 'Demo');

    assert_contains('"className":"header-overlay"', $result['markup']);
    assert_true(!str_contains($result['markup'], 'backgroundColor'), 'opaque background removed');
    assert_true(!str_contains($result['markup'], 'sticky'), 'sticky removed — the overlay floats');
    assert_contains('"spacing"', $result['markup'], 'unrelated style survives');
    assert_eq(1, count($result['notes']));

    // Idempotent: a compliant overlay header is untouched.
    $again = HeaderHeroStep::fixHeader($result['markup'], SectionsStep::MODE_OVERLAY, 'Demo');
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['notes']);
});

test('stacked mode removes a stray header-overlay class from attrs and saved HTML', function () {
    $markup = '<!-- wp:group {"className":"header-overlay","layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group header-overlay"><!-- wp:site-title /--></div>' . "\n"
        . '<!-- /wp:group -->';
    $result = HeaderHeroStep::fixHeader($markup, SectionsStep::MODE_STACKED, 'Demo');

    assert_true(!str_contains($result['markup'], 'header-overlay'), 'class gone from attrs AND html');
    assert_eq(1, count($result['notes']));
});

test('a display-scale site title is lowered to heading, syncing the saved HTML', function () {
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title {"fontSize":"section-title"} /-->'
    );
    $result = HeaderHeroStep::fixHeader($markup, SectionsStep::MODE_STACKED, 'Demo');
    assert_contains('"fontSize":"heading"', $result['markup']);
    assert_contains("lowered to 'heading'", implode(' ', $result['notes']));

    // The one sanctioned exception: a forced oversized-wordmark build.
    $forced = HeaderHeroStep::fixHeader($markup, SectionsStep::MODE_STACKED, 'Demo', [], true);
    assert_contains('"fontSize":"section-title"', $forced['markup']);
    assert_eq([], $forced['notes']);
});

test('an over-wide nav row collapses to overlayMenu:always instead of wrapping', function () {
    $nav = '<!-- wp:navigation {"fontSize":"caption"} -->'
        . '<!-- wp:navigation-link {"label":"Programación"} /-->'
        . '<!-- wp:navigation-link {"label":"Instalaciones"} /-->'
        . '<!-- wp:navigation-link {"label":"Talleres"} /-->'
        . '<!-- wp:navigation-link {"label":"Ubicación"} /-->'
        . '<!-- wp:navigation-link {"label":"Accesibilidad"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link">Entradas</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->';
    $markup = hh_header('{"backgroundColor":"base","layout":{"type":"constrained"}}', '<!-- wp:site-title /-->' . $nav);
    $result = HeaderHeroStep::fixHeader($markup, SectionsStep::MODE_STACKED, 'Pulso Sur Centro Cultural');

    assert_contains('"overlayMenu":"always"', $result['markup']);
    assert_contains('overlayMenu:always', implode(' ', $result['notes']));

    // A short three-item nav fits the row and is untouched.
    $short = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu"} /--><!-- wp:navigation-link {"label":"Visit"} /-->'
        . '<!-- /wp:navigation -->'
    );
    $fits = HeaderHeroStep::fixHeader($short, SectionsStep::MODE_STACKED, 'Demo');
    assert_eq($short, $fits['markup']);
});

test('a page-list nav is measured by the site page titles', function () {
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->'
    );
    $longTitles = ['Our Seasonal Tasting Menu', 'Private Dining and Events', 'Reservations and Contact', 'The Story of the House'];
    $result = HeaderHeroStep::fixHeader($markup, SectionsStep::MODE_STACKED, 'Demo', $longTitles);
    assert_contains('"overlayMenu":"always"', $result['markup']);

    $fits = HeaderHeroStep::fixHeader($markup, SectionsStep::MODE_STACKED, 'Demo', ['Menu', 'Visit']);
    assert_eq($markup, $fits['markup']);
});

test('estimatedRowWidth charges a button its width plus the cluster gap', function () {
    $nav = '<!-- wp:navigation-link {"label":"Home"} /-->';
    $button = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link">Go</a></div><!-- /wp:button -->';

    $without = HeaderHeroStep::estimatedRowWidth(BlockMarkup::parse(hh_header('{"layout":{"type":"constrained"}}', $nav)), 'Demo');
    $with    = HeaderHeroStep::estimatedRowWidth(BlockMarkup::parse(hh_header('{"layout":{"type":"constrained"}}', $nav . $button)), 'Demo');

    // BUTTON_PAD_PX (56) + 2 label chars * BUTTON_CHAR_PX (9) + CLUSTER_GAP_PX (32).
    // The gap term is the regression this pins: the button branch set a
    // variable the gap check never read, so every header with a button was
    // underestimated by the cluster gap.
    assert_eq(56 + 2 * 9 + 32, $with - $without);
});

test('capCovers lowers a viewport-scale cover to 80vh and leaves the rest alone', function () {
    $result = HeaderHeroStep::capCovers(hh_cover('92'));
    assert_contains('"minHeight":80', $result['markup']);
    assert_eq(1, count($result['notes']));

    // 80vh already fits beside a stacked header; px heights are not viewport math.
    $ok = hh_cover('80');
    assert_eq($ok, HeaderHeroStep::capCovers($ok)['markup']);
    $px = str_replace('"minHeightUnit":"vh"', '"minHeightUnit":"px"', hh_cover('600'));
    assert_eq($px, HeaderHeroStep::capCovers($px)['markup']);
});

test('expectedMode follows the plan and lets a forced archetype override it', function () {
    $overlayPages = [['slug' => 'home', 'front' => true, 'sections' => [
        ['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image'],
    ]]];
    putenv(SectionsStep::ARCHETYPE_ENV);
    assert_eq(SectionsStep::MODE_OVERLAY, HeaderHeroStep::expectedMode($overlayPages, ''));
    try {
        putenv(SectionsStep::ARCHETYPE_ENV . '=standard-row');
        assert_eq(SectionsStep::MODE_STACKED, HeaderHeroStep::expectedMode($overlayPages, ''));
        putenv(SectionsStep::ARCHETYPE_ENV . '=minimal-overlay');
        assert_eq(SectionsStep::MODE_OVERLAY, HeaderHeroStep::expectedMode([], ''));
    } finally {
        putenv(SectionsStep::ARCHETYPE_ENV);
    }
});

test('the step repairs the header and hero parts on disk and records warnings', function () {
    $tmp = sys_get_temp_dir() . '/builder_hh_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home', 'title' => 'Home', 'front' => true,
        'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
    ]]]);
    $project->writeText('theme/parts/header.html', hh_header('{"className":"header-overlay","layout":{"type":"constrained"}}') . "\n");
    $project->writeText('theme/parts/page-home--hero.html', hh_cover('92') . "\n");

    putenv(SectionsStep::ARCHETYPE_ENV);
    (new HeaderHeroStep())->run($project);

    assert_true(!str_contains($project->readText('theme/parts/header.html'), 'header-overlay'), 'stacked mode strips the stray overlay hook');
    assert_contains('"minHeight":80', $project->readText('theme/parts/page-home--hero.html'));
    $warnings = $project->readText('warnings.json');
    assert_contains('header/hero contract', $warnings);
    assert_true($project->exists('logs/header-hero.txt'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('capHeadlessCovers shortens an opening cover whose masthead sits below it', function () {
    // portfolio7's shipped shape (BIGR-738 follow-up): a 78vh opening cover
    // holding only a caption, with the H1 in a following group — an image-only
    // first viewport.
    $markup = '<!-- wp:group {"align":"full","anchor":"hero","layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group alignfull" id="hero">'
        . '<!-- wp:cover {"url":"x.jpg","dimRatio":40,"minHeight":78,"minHeightUnit":"vh","align":"full"} -->'
        . '<div class="wp-block-cover alignfull" style="min-height:78vh">'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">Plaza de Mayo</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:cover -->'
        . '<!-- wp:heading {"level":1,"fontSize":"display"} --><h1 class="wp-block-heading has-display-font-size">Twenty years, one street.</h1><!-- /wp:heading -->'
        . '</div>' . "\n" . '<!-- /wp:group -->';

    $result = HeaderHeroStep::capHeadlessCovers($markup);
    assert_contains('"minHeight":55', $result['markup']);
    assert_eq(1, count($result['notes']));
    assert_contains('contains no heading', $result['notes'][0]);
});

test('capHeadlessCovers leaves covers with an inner masthead and short image bands alone', function () {
    // A 92vh cover WITH its H1 inside is the composed full-bleed hero — untouched.
    $withHeading = hh_cover('92');
    assert_eq($withHeading, HeaderHeroStep::capHeadlessCovers($withHeading)['markup']);
    assert_eq([], HeaderHeroStep::capHeadlessCovers($withHeading)['notes']);

    // A headline-less image band already at/below the cap is a legitimate choice.
    $short = '<!-- wp:cover {"url":"x.jpg","minHeight":50,"minHeightUnit":"vh"} -->'
        . '<div class="wp-block-cover" style="min-height:50vh"></div><!-- /wp:cover -->';
    assert_eq($short, HeaderHeroStep::capHeadlessCovers($short)['markup']);

    // Only the FIRST cover owns the fold: a deeper decorative cover is not judged.
    $second = hh_cover('92')
        . '<!-- wp:cover {"url":"y.jpg","minHeight":90,"minHeightUnit":"vh"} -->'
        . '<div class="wp-block-cover" style="min-height:90vh"></div><!-- /wp:cover -->';
    assert_contains('"minHeight":90', HeaderHeroStep::capHeadlessCovers($second)['markup']);
});
