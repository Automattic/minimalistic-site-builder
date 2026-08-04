<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\HeaderHeroStep;

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
        . '<div class="wp-block-cover alignfull" style="min-height:' . $height . 'vh"></div>'
        . '<!-- /wp:cover --></div>' . "\n"
        . '<!-- /wp:group -->';
}

test('overlay mode wires the header-overlay class and strips the opaque chrome', function () {
    $markup = hh_header('{"backgroundColor":"base","style":{"position":{"type":"sticky","top":"0px"},"spacing":{"padding":{"top":"var:preset|spacing|sm"}}},"layout":{"type":"constrained"}}');
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_OVERLAY, 'Demo');

    assert_contains('"className":"header-overlay"', $result['markup']);
    assert_true(!str_contains($result['markup'], 'backgroundColor'), 'opaque background removed');
    assert_true(!str_contains($result['markup'], 'sticky'), 'sticky removed — the overlay floats');
    assert_contains('"spacing"', $result['markup'], 'unrelated style survives');
    assert_eq(2, count($result['notes']));
    assert_contains('overlay header wiring', $result['notes'][0]);
    assert_contains('saved HTML', $result['notes'][1]);

    // Idempotent: a compliant overlay header is untouched.
    $again = HeaderHeroStep::fixHeader($result['markup'], AboveFoldContract::MODE_OVERLAY, 'Demo');
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['notes']);
});

test('stacked mode removes a stray header-overlay class from attrs and saved HTML', function () {
    $markup = '<!-- wp:group {"className":"header-overlay","layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group header-overlay"><!-- wp:site-title /--></div>' . "\n"
        . '<!-- /wp:group -->';
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');

    assert_true(!str_contains($result['markup'], 'header-overlay'), 'class gone from attrs AND html');
    assert_eq(1, count($result['notes']));
});

test('a display-scale site title is lowered to heading, syncing the saved HTML', function () {
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title {"fontSize":"section-title"} /-->'
    );
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo');
    assert_contains('"fontSize":"heading"', $result['markup']);
    assert_contains("lowered to 'heading'", implode(' ', $result['notes']));

    // The one sanctioned exception: a forced oversized-wordmark build.
    $forced = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', [], true);
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
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Pulso Sur Centro Cultural');

    assert_contains('"overlayMenu":"always"', $result['markup']);
    assert_contains('overlayMenu:always', implode(' ', $result['notes']));

    // A short three-item nav fits the row and is untouched.
    $short = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu"} /--><!-- wp:navigation-link {"label":"Visit"} /-->'
        . '<!-- /wp:navigation -->'
    );
    $fits = HeaderHeroStep::fixHeader($short, AboveFoldContract::MODE_STACKED, 'Demo');
    assert_eq($short, $fits['markup']);
});

test('a page-list nav is measured by the site page titles', function () {
    $markup = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:site-title /--><!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->'
    );
    $longTitles = ['Our Seasonal Tasting Menu', 'Private Dining and Events', 'Reservations and Contact', 'The Story of the House'];
    $result = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', $longTitles);
    assert_contains('"overlayMenu":"always"', $result['markup']);

    $fits = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Demo', ['Menu', 'Visit']);
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

test('dedupeAgainstHero strips echoed caption lines and the duplicate-label CTA', function () {
    $hero = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p>SAN TELMO · BUENOS AIRES</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":1} --><h1>Vegetarian Argentine Cuisine</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>A long standfirst that runs well past twelve words so it can never'
        . ' participate in echo matching against header chrome at all.</p><!-- /wp:paragraph -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" href="/visit/">Reserve a Table</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->';
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:paragraph {"fontSize":"caption"} --><p>San Telmo, Buenos Aires</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p>Open Tuesday to Sunday</p><!-- /wp:paragraph -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" href="/visit/">Reserve a Table</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->'
    );
    $action = ['label' => 'Reserve a Table', 'intent' => 'Invite a booking', 'destination' => '/visit/'];
    $result = HeaderHeroStep::dedupeAgainstHero($header, $hero, $action);

    assert_true(!str_contains($result['markup'], 'San Telmo'), 'echoed location line removed');
    assert_contains('Open Tuesday to Sunday', $result['markup'], 'non-echoed chrome line survives');
    assert_true(!str_contains($result['markup'], 'Reserve a Table'), 'duplicate CTA removed');
    assert_true(!str_contains($result['markup'], 'wp:buttons'), 'empty buttons wrapper removed with its only button');
    assert_contains('wp:site-title', $result['markup'], 'identity chrome untouched');
    assert_eq(2, count($result['notes']));

    // Idempotent, and a header with nothing duplicated is untouched.
    $again = HeaderHeroStep::dedupeAgainstHero($result['markup'], $hero, $action);
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['notes']);
});

test('dedupeAgainstHero leaves distinct chrome, partial overlap, and other-label buttons alone', function () {
    $hero = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Bienvenidos — San Telmo, Buenos Aires</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    // Shares only the place name with the hero eyebrow — different information.
    $header = hh_header(
        '{"backgroundColor":"base","layout":{"type":"constrained"}}',
        '<!-- wp:paragraph {"fontSize":"caption"} --><p>Vegetarian kitchen · Calle Defensa, San Telmo</p><!-- /wp:paragraph -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" href="/menu/">See the Menu</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->'
    );
    $action = ['label' => 'Reserve a Table', 'intent' => 'Invite a booking', 'destination' => '/visit/'];
    $result = HeaderHeroStep::dedupeAgainstHero($header, $hero, $action);
    assert_eq($header, $result['markup']);
    assert_eq([], $result['notes']);

    // No contract action: buttons are never candidates.
    $none = HeaderHeroStep::dedupeAgainstHero($header, $hero, null);
    assert_eq($header, $none['markup']);
});

test('the step repairs header and hero parts without promoting successful repairs to warnings', function () {
    $tmp = sys_get_temp_dir() . '/builder_hh_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $pages = [[
        'slug' => 'home', 'title' => 'Home', 'front' => true,
        'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base', 'primary_action' => null]],
    ]];
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', AboveFoldContract::resolve(
        pages: $pages,
        blueprint: HeroBlueprint::defaultFor('focal-subject-stage'),
        canvas: 'full-bleed',
        themeContext: ['version' => 3],
        siteContext: ['stable_id' => 'demo', 'writing_direction' => 'ltr', 'page_count' => 1],
        footerContext: ['archetype' => 'typographic-billboard', 'surface' => 'base'],
    ));
    $project->writeText('theme/parts/header.html', hh_header('{"className":"header-overlay","layout":{"type":"constrained"}}') . "\n");
    $project->writeText('theme/parts/page-home--hero.html', hh_cover('92') . "\n");

    putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
    (new HeaderHeroStep())->run($project);

    assert_true(!str_contains($project->readText('theme/parts/header.html'), 'header-overlay'), 'stacked mode strips the stray overlay hook');
    assert_contains('"minHeight":80', $project->readText('theme/parts/page-home--hero.html'));
    assert_true(!$project->exists('warnings.json'), 'successful contract repairs stay in the step report only');
    assert_true($project->exists('logs/header-hero.txt'));
    $report = $project->readText('logs/header-hero.txt');
    assert_contains("removed the 'header-overlay' class", $report);
    assert_contains('cover minHeight 92vh lowered to 80vh', $report);
    assert_eq('final', $project->readJson('aboveFold.json')['phase'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the step removes an exact hero action when its delivered target is dead', function () {
    $tmp = sys_get_temp_dir() . '/builder_hh_dead_action_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $action = ['label' => 'Missing work', 'intent' => 'Reach missing work.', 'destination' => '/missing/'];
    $pages = [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
        'sections' => [[
            'slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'mixed-width-editorial',
            'background' => 'contrast', 'primary_action' => $action,
        ]],
    ]];
    $delivery = AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor('focal-subject-stage'),
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'dead-action', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'standard-row',
    );
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', $delivery);
    $project->writeText('theme/parts/header.html', hh_header('{"className":"header-archetype--standard-row","backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}}'));
    $sibling = '<!-- wp:paragraph --><p>Sibling proposition remains.</p><!-- /wp:paragraph -->';
    $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/missing/">Missing work</a></div><!-- /wp:button -->';
    $hero = '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage hero-mobile--stack-media-first">'
        . $sibling . '<!-- wp:buttons --><div class="wp-block-buttons">' . $button . '</div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->';
    $project->writeText('theme/parts/page-home--hero.html', $hero);

    (new HeaderHeroStep())->run($project);

    $deliveredHero = $project->readText('theme/parts/page-home--hero.html');
    assert_contains($sibling, $deliveredHero);
    assert_true(!str_contains($deliveredHero, $button));
    assert_eq(null, $project->readJson('pages.json')['pages'][0]['sections'][0]['primary_action']);
    $final = $project->readJson('aboveFold.json');
    assert_eq('final', $final['phase']);
    assert_eq(null, $final['primary_action']);
    assert_eq('primary-action-target-lost', $final['degradations'][0]['code']);
    $warnings = implode("\n", $project->readJson('warnings.json')['header-hero'] ?? []);
    assert_contains('code="primary-action-target-lost"', $warnings);
    assert_contains('/missing/', $warnings);
    assert_contains('delivered=removed', $warnings);
    assert_contains('dead control', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('late overlay protection loss produces matching stacked bytes and the 80vh cover cap', function () {
    $tmp = sys_get_temp_dir() . '/builder_hh_overlay_loss_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $pages = [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
        'sections' => [[
            'slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'full-bleed-cover',
            'background' => 'image', 'primary_action' => null,
        ]],
    ]];
    $delivery = AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor('cinematic-safe-zone'),
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'overlay-loss', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'minimal-overlay',
    );
    assert_eq('overlay', $delivery['header']['mode']);
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', $delivery);
    $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}'));
    $hero = str_replace(
        '<!-- wp:group {"layout":{"type":"constrained"}} -->',
        '<!-- wp:group {"className":"hero-composition--cinematic-safe-zone hero-mobile--stack-media-first","layout":{"type":"constrained"}} -->',
        hh_cover('92'),
    );
    $hero = str_replace(
        'class="wp-block-group"',
        'class="wp-block-group hero-composition--cinematic-safe-zone hero-mobile--stack-media-first"',
        $hero,
    );
    $project->writeText('theme/parts/page-home--hero.html', $hero);

    (new HeaderHeroStep())->run($project);

    $final = $project->readJson('aboveFold.json');
    assert_eq('stacked', $final['header']['mode']);
    assert_eq('standard-row', $final['header']['archetype']);
    assert_eq('overlay-support-lost', $final['degradations'][0]['code']);
    $header = $project->readText('theme/parts/header.html');
    assert_true(!str_contains($header, 'header-overlay'));
    assert_contains('header-archetype--standard-row', $header);
    assert_contains('"backgroundColor":"base"', $header);
    assert_contains('has-base-background-color', $header);
    assert_contains('has-background', $header);
    assert_contains('has-contrast-color', $header);
    assert_contains('has-text-color', $header);
    assert_contains('"minHeight":80', $project->readText('theme/parts/page-home--hero.html'));
    $warnings = implode("\n", $project->readJson('warnings.json')['header-hero'] ?? []);
    assert_contains('code="overlay-support-lost"', $warnings);
    assert_contains('top-edge protection', $warnings);
    assert_contains('delivered="stacked"', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});
