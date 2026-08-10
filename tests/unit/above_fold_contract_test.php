<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\AboveFoldPartFacts;
use Automattic\SiteBuild\HeroBlueprint;

/** @param array{label:string,intent:string,destination:string}|null $action */
function above_fold_pages(?array $action = null, string $heroSurface = 'image', string $menuSurface = 'contrast'): array
{
    return [
        [
            'slug' => 'home',
            'title' => 'Home',
            'path' => '/',
            'front' => true,
            'sections' => [
                [
                    'slug' => 'hero',
                    'title' => 'Welcome',
                    'layout_archetype' => 'full-bleed-cover',
                    'background' => $heroSurface,
                    'primary_action' => $action,
                ],
                [
                    'slug' => 'proof',
                    'title' => 'Proof',
                    'layout_archetype' => 'offset-grid',
                    'background' => 'base',
                    'primary_action' => null,
                ],
            ],
        ],
        [
            'slug' => 'menu',
            'title' => 'Menu',
            'path' => '/menu/',
            'front' => false,
            'sections' => [
                [
                    'slug' => 'menu-opening',
                    'title' => 'Menu',
                    'layout_archetype' => 'mixed-width-editorial',
                    'background' => $menuSurface,
                    'primary_action' => null,
                ],
                [
                    'slug' => 'details',
                    'title' => 'Details',
                    'layout_archetype' => 'offset-grid',
                    'background' => 'base',
                    'primary_action' => null,
                ],
            ],
        ],
    ];
}

/** @param array<int,array<string,mixed>> $pages */
function above_fold_resolve(
    array $pages,
    string $recipe = 'cinematic-safe-zone',
    string $canvas = 'full-bleed',
    string $direction = 'ltr',
    ?string $forced = null,
    array $theme = ['base' => '#FFFFFF', 'contrast' => '#111111'],
    string $tagline = '',
): array {
    return AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor($recipe),
        $canvas,
        $theme,
        [
            'stable_id' => 'above-fold-fixture',
            'writing_direction' => $direction,
            'page_count' => count($pages),
            'tagline' => $tagline,
        ],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        $forced,
    );
}

function above_fold_action_markup(string $label, string $destination): string
{
    return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="'
        . htmlspecialchars($destination, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
}

function above_fold_solid_part(
    string $anchor,
    string $surface = 'contrast',
    string $body = '',
    string $className = '',
): string {
    $attrs = [
        'anchor' => $anchor,
        'backgroundColor' => $surface,
        'layout' => ['type' => 'constrained'],
    ];
    if ($className !== '') {
        $attrs['className'] = $className;
    }
    $json = (string) json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $classes = trim('wp-block-group ' . $className . " has-{$surface}-background-color has-background");
    return '<!-- wp:group ' . $json . ' --><div id="' . $anchor . '" class="' . $classes . '">'
        . $body . '</div><!-- /wp:group -->';
}

function above_fold_image_part(
    string $anchor,
    int $dimRatio = 50,
    string $body = '',
    string $className = '',
    ?string $gradient = null,
    ?string $overlayColor = 'contrast',
): string {
    $root = ['anchor' => $anchor, 'layout' => ['type' => 'constrained']];
    if ($className !== '') {
        $root['className'] = $className;
    }
    $cover = ['dimRatio' => $dimRatio];
    if ($gradient !== null) {
        $cover['gradient'] = $gradient;
    }
    if ($overlayColor !== null) {
        $cover['overlayColor'] = $overlayColor;
    }
    $dimClass = $dimRatio === 50 ? '' : ' has-background-dim-' . (10 * (int) round($dimRatio / 10));
    $overlayClass = $overlayColor === null ? '' : ' has-' . trim($overlayColor) . '-background-color';
    $gradientClasses = $gradient === null
        ? ''
        : ' wp-block-cover__gradient-background has-background-gradient has-' . $gradient . '-gradient-background';
    return '<!-- wp:group ' . json_encode($root, JSON_UNESCAPED_SLASHES) . ' -->'
        . '<div id="' . $anchor . '" class="wp-block-group ' . $className . '">'
        . '<!-- wp:cover ' . json_encode($cover, JSON_UNESCAPED_SLASHES) . ' -->'
        . '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background'
        . $overlayClass . $dimClass . ' has-background-dim' . $gradientClasses . '"></span>'
        . '<div class="wp-block-cover__inner-container">' . $body . '</div></div><!-- /wp:cover -->'
        . '</div><!-- /wp:group -->';
}

test('header override preflight rejects only incompatibility proven by caller-owned facts', function () {
    AboveFoldContract::validateHeaderArchetypePreflight(null);
    AboveFoldContract::validateHeaderArchetypePreflight('standard-row', [
        'allowed_hero_media_modes' => ['none'],
    ], 1);
    AboveFoldContract::validateHeaderArchetypePreflight('minimal-overlay');
    AboveFoldContract::validateHeaderArchetypePreflight('minimal-overlay', [
        'allowed_hero_media_modes' => ['cover-image'],
        'max_hero_images' => 1,
    ]);
    AboveFoldContract::validateHeaderArchetypePreflight('split-nav', [], null);
    AboveFoldContract::validateHeaderArchetypePreflight('split-nav', [], 2);

    assert_throws(fn () => AboveFoldContract::validateHeaderArchetypePreflight('invented-header'));
    assert_throws(fn () => AboveFoldContract::validateHeaderArchetypePreflight('double-decker'));
    assert_throws(fn () => AboveFoldContract::validateHeaderArchetypePreflight('minimal-overlay', [
        'hero_canvas' => 'framed',
    ]));
    assert_throws(fn () => AboveFoldContract::validateHeaderArchetypePreflight('minimal-overlay', [
        'allowed_hero_media_modes' => ['none', 'foreground-image'],
    ]));
    assert_throws(fn () => AboveFoldContract::validateHeaderArchetypePreflight('minimal-overlay', [
        'max_hero_images' => 0,
    ]));
    assert_throws(fn () => AboveFoldContract::validateHeaderArchetypePreflight('split-nav', [], 1));

    $generatedDrift = above_fold_pages();
    $generatedDrift[1]['sections'][0]['background'] = 'base';
    $delivery = above_fold_resolve($generatedDrift, forced: 'minimal-overlay');
    assert_eq('standard-row', $delivery['header']['archetype']);
    assert_eq('forced-header-degraded', $delivery['degradations'][0]['code']);
});

test('above-fold assignment is stable and honors one exact compatible forced archetype', function () {
    $pages = above_fold_pages();
    $first = above_fold_resolve($pages);
    $second = above_fold_resolve($pages);
    assert_eq($first, $second);
    assert_eq('overlay', $first['header']['mode']);
    assert_eq('minimal-overlay', $first['header']['archetype']);

    $forced = above_fold_resolve($pages, forced: 'standard-row');
    assert_eq('stacked', $forced['header']['mode']);
    assert_eq('standard-row', $forced['header']['archetype']);
    assert_eq([], $forced['degradations'], 'compatible override is exact, not reported as a loss');

    $framed = above_fold_resolve($pages, canvas: 'framed');
    assert_eq($framed, above_fold_resolve($pages, canvas: 'framed'));
    assert_true(!in_array($framed['header']['archetype'], [
        'minimal-overlay', 'centered-masthead', 'oversized-wordmark',
    ], true));

    $unsafeForced = above_fold_resolve($pages, forced: 'centered-masthead');
    assert_eq('standard-row', $unsafeForced['header']['archetype']);
    assert_eq('forced-header-degraded', $unsafeForced['degradations'][0]['code']);
    assert_throws(fn () => above_fold_resolve($pages, forced: 'invented-header'));
});

test('double-decker is removed from the catalog: never assigned, rejected as an override', function () {
    $pages = above_fold_pages(null, 'base', 'base');
    foreach (['focal-subject-stage', 'editorial-split'] as $recipe) {
        foreach (range(1, 40) as $seed) {
            $contract = AboveFoldContract::resolve(
                $pages,
                HeroBlueprint::defaultFor($recipe),
                'full-bleed',
                ['base' => '#FFFFFF', 'contrast' => '#111111'],
                ['stable_id' => "retired-seed-{$seed}", 'writing_direction' => 'ltr', 'page_count' => count($pages)],
                ['archetype' => 'minimal-columns', 'surface' => 'base'],
            );
            assert_true(
                $contract['header']['archetype'] !== 'double-decker',
                'double-decker is never assigned',
            );
        }
    }

    assert_throws(fn () => AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor('editorial-split'),
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'retired-forced', 'writing_direction' => 'ltr', 'page_count' => count($pages)],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'double-decker',
    ));
});

test('overlay requires an image-led recipe, every protected opening, an unframed canvas, and contrast', function () {
    $pages = above_fold_pages();
    $overlay = above_fold_resolve($pages);
    assert_eq('overlay', $overlay['header']['mode']);
    assert_eq('base', $overlay['header']['foreground_token']);
    assert_eq('contrast', $overlay['header']['protection_token']);
    assert_true($overlay['header']['protect_top_edge']);

    $pages[1]['sections'][0]['background'] = 'base';
    assert_eq('stacked', above_fold_resolve($pages)['header']['mode']);
    assert_eq('stacked', above_fold_resolve(above_fold_pages(), canvas: 'framed')['header']['mode']);
    assert_eq('stacked', above_fold_resolve(
        above_fold_pages(),
        theme: ['base' => '#777777', 'contrast' => '#888888'],
    )['header']['mode']);

    $solidStacked = above_fold_pages(null, 'contrast', 'contrast');
    $solidStacked[0]['sections'][0]['layout_archetype'] = 'mixed-width-editorial';
    assert_eq('stacked', above_fold_resolve($solidStacked, recipe: 'focal-subject-stage')['header']['mode']);
});

test('logical hero regions resolve to physical RTL sides once', function () {
    // cinematic-safe-zone centers its copy (BIGR-775), so the safe region is
    // direction-neutral while the focal region still flips physically.
    $contract = above_fold_resolve(above_fold_pages(), direction: 'rtl');
    assert_eq(['logical' => 'center', 'physical' => 'center'], $contract['regions']['text_safe']);
    assert_eq(['logical' => 'end', 'physical' => 'left'], $contract['regions']['focal']);

    // A start-anchored foreground recipe still resolves to a physical side.
    $split = above_fold_resolve(
        above_fold_pages(null, 'base', 'base'),
        recipe: 'editorial-split',
        direction: 'rtl',
    );
    assert_eq('full', $split['regions']['text_safe']['logical']);
});

test('front serialization is canonical while the interior opening subset is recipe-free', function () {
    $action = ['label' => 'See details', 'intent' => 'Show the menu.', 'destination' => '/menu/#details'];
    $contract = above_fold_resolve(above_fold_pages($action), forced: 'standard-row');
    $front = AboveFoldContract::frontContract($contract);
    assert_eq($front, AboveFoldContract::frontContract($contract));
    assert_contains('cinematic-safe-zone', $front);
    assert_contains('See details', $front);
    assert_contains('standard-row', $front);

    $interior = AboveFoldContract::openingHeaderContract($contract, 'menu');
    assert_contains('page-menu--menu-opening', $interior);
    assert_contains('standard-row', $interior);
    assert_true(!str_contains($interior, 'cinematic-safe-zone'));
    assert_true(!str_contains($interior, 'primary_action'));
    assert_true(!str_contains($interior, 'hero_section'));
    assert_throws(fn () => AboveFoldContract::openingHeaderContract($contract, 'missing'));
});

test('delivery and markup phases reject misuse and reach a fixed point without reselection', function () {
    $pages = [above_fold_pages(null, 'contrast', 'contrast')[0]];
    $contract = above_fold_resolve($pages, recipe: 'focal-subject-stage', forced: 'standard-row');
    $parts = ['page-home--hero' => above_fold_solid_part('hero', 'contrast')];
    $facts = AboveFoldPartFacts::inspect($pages, $parts, $contract);

    $delivery = AboveFoldContract::finalizeDelivery($contract, $pages, $facts);
    $againFacts = AboveFoldPartFacts::inspect($pages, $parts, $delivery);
    assert_eq($delivery, AboveFoldContract::finalizeDelivery($delivery, $pages, $againFacts));
    assert_eq('standard-row', $delivery['header']['archetype']);

    $final = AboveFoldContract::finalizeMarkup($delivery, $pages, $againFacts);
    assert_eq($final, AboveFoldContract::finalizeMarkup($delivery, $pages, $againFacts));
    assert_eq('final', $final['phase']);
    assert_throws(fn () => AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_FINAL));
    assert_throws(fn () => AboveFoldContract::finalizeDelivery($final, $pages, $againFacts));
    assert_throws(fn () => AboveFoldContract::finalizeMarkup($final, $pages, $againFacts));
});

test('overlay inspection accepts protected image and solid fallback roots but rejects objective drift', function () {
    $image = above_fold_image_part('hero', 50);
    assert_true(AboveFoldPartFacts::supportsOverlay($image, 'image', 'contrast'));
    assert_true(AboveFoldPartFacts::supportsClearOverlayTop($image, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsOverlay(above_fold_image_part('hero', 20), 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsOverlay(
        above_fold_image_part('hero', 0, gradient: 'contrast-fade'),
        'image',
        'contrast',
    ));
    assert_true(!AboveFoldPartFacts::supportsOverlay(
        above_fold_image_part('hero', 0, overlayColor: 'contrast'),
        'image',
        'contrast',
    ));
    assert_true(AboveFoldPartFacts::supportsOverlay(
        above_fold_image_part('hero', 50, overlayColor: 'contrast'),
        'image',
        'contrast',
    ));
    $gradientImage = above_fold_image_part(
        'hero',
        50,
        gradient: 'contrast-fade',
        overlayColor: 'contrast',
    );
    assert_true(AboveFoldPartFacts::supportsOverlay($gradientImage, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($gradientImage, 'image', 'contrast'));
    $customGradientImage = str_replace(
        '"overlayColor":"contrast"',
        '"overlayColor":"contrast","customGradient":"linear-gradient(#fff,#fff)"',
        $image,
    );
    assert_true(AboveFoldPartFacts::supportsOverlay($customGradientImage, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($customGradientImage, 'image', 'contrast'));
    $safeDimImage = above_fold_image_part('hero', 60);
    assert_true(AboveFoldPartFacts::supportsClearOverlayTop($safeDimImage, 'image', 'contrast'));
    $staleDimImage = str_replace('has-background-dim-60', 'has-background-dim-40', $safeDimImage);
    assert_true(AboveFoldPartFacts::supportsOverlay($staleDimImage, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($staleDimImage, 'image', 'contrast'));
    $stalePaintImage = str_replace(
        'has-background-dim-60 has-background-dim"',
        'has-background-dim-60 has-background-dim" style="background:linear-gradient(#fff,#fff)"',
        $safeDimImage,
    );
    assert_true(AboveFoldPartFacts::supportsOverlay($stalePaintImage, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($stalePaintImage, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsOverlay(
        above_fold_image_part('hero', 50, overlayColor: null),
        'image',
        'contrast',
    ), 'a default black dim layer cannot be assumed to equal the semantic contrast token');
    assert_true(!AboveFoldPartFacts::supportsOverlay(
        above_fold_image_part('hero', 50, overlayColor: 'contrast'),
        'image',
        'base',
    ), 'an exact protection-token mismatch is rejected for inverted palettes');

    $fallback = above_fold_solid_part('hero', 'contrast');
    assert_true(AboveFoldPartFacts::supportsOverlay($fallback, 'image', 'contrast'));
    assert_true(AboveFoldPartFacts::supportsClearOverlayTop($fallback, 'image', 'contrast'));
    $gradientFallback = str_replace(
        '"layout":{"type":"constrained"}',
        '"gradient":"contrast-fade","layout":{"type":"constrained"}',
        $fallback,
    );
    assert_true(AboveFoldPartFacts::supportsOverlay($gradientFallback, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($gradientFallback, 'image', 'contrast'));
    $customPaintFallback = str_replace(
        '"layout":{"type":"constrained"}',
        '"style":{"color":{"background":"#ffffff"}},"layout":{"type":"constrained"}',
        $fallback,
    );
    assert_true(AboveFoldPartFacts::supportsOverlay($customPaintFallback, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($customPaintFallback, 'image', 'contrast'));
    $classPaintFallback = str_replace(
        '"layout":{"type":"constrained"}',
        '"className":"has-base-gradient-background","layout":{"type":"constrained"}',
        $fallback,
    );
    assert_true(AboveFoldPartFacts::supportsOverlay($classPaintFallback, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($classPaintFallback, 'image', 'contrast'));
    $htmlClassPaintFallback = str_replace(
        'has-background"',
        'has-background has-base-gradient-background"',
        $fallback,
    );
    assert_true(AboveFoldPartFacts::supportsOverlay($htmlClassPaintFallback, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($htmlClassPaintFallback, 'image', 'contrast'));
    $unquotedClassPaintFallback = '<!-- wp:group {"backgroundColor":"contrast","layout":{"type":"constrained"}} -->'
        . '<div class=has-base-gradient-background></div><!-- /wp:group -->';
    assert_true(AboveFoldPartFacts::supportsOverlay($unquotedClassPaintFallback, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($unquotedClassPaintFallback, 'image', 'contrast'));
    $classOnly = '<!-- wp:group {"className":"has-contrast-background-color"} -->'
        . '<div class="wp-block-group has-contrast-background-color"></div><!-- /wp:group -->';
    assert_true(AboveFoldPartFacts::supportsOverlay($classOnly, 'image', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsOverlay(
        above_fold_image_part('hero', 50, className: 'site-build-section-rhythm-degraded-image'),
        'image',
        'contrast',
    ));
    assert_true(!AboveFoldPartFacts::supportsOverlay(above_fold_solid_part('hero', 'base'), 'image', 'contrast'));

    $lowProtectionCover = '<!-- wp:cover {"dimRatio":20} -->'
        . '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background"></span>'
        . '<div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';
    $actualImageOverPlannedSolid = above_fold_solid_part('hero', 'contrast', $lowProtectionCover);
    assert_true(!AboveFoldPartFacts::supportsOverlay(
        $actualImageOverPlannedSolid,
        'contrast',
        'contrast',
    ));

    $plannedSolidPages = above_fold_pages(null, 'contrast', 'contrast');
    $plannedSolidContract = above_fold_resolve($plannedSolidPages);
    $facts = AboveFoldPartFacts::inspect($plannedSolidPages, [
        'page-home--hero' => $actualImageOverPlannedSolid,
        'page-menu--menu-opening' => above_fold_solid_part('menu-opening', 'contrast'),
    ], $plannedSolidContract);
    assert_eq('image', $facts['opening_surfaces']['page-home--hero']);
    assert_true(!$facts['opening_overlay_support']['page-home--hero']);
});

test('a delivered solid fallback updates an image opening without unnecessarily losing overlay', function () {
    $pages = above_fold_pages(null, 'image', 'image');
    $contract = above_fold_resolve($pages);
    $parts = [
        'page-home--hero' => above_fold_image_part('hero', 50, className: 'hero-composition--cinematic-safe-zone'),
        'page-menu--menu-opening' => above_fold_solid_part('menu-opening', 'contrast'),
    ];
    $facts = AboveFoldPartFacts::inspect($pages, $parts, $contract);
    assert_true($facts['opening_overlay_support']['page-menu--menu-opening']);
    assert_eq('contrast', $facts['opening_surfaces']['page-menu--menu-opening']);

    $delivery = AboveFoldContract::finalizeDelivery($contract, $pages, $facts);
    assert_eq('overlay', $delivery['header']['mode']);
    assert_eq('minimal-overlay', $delivery['header']['archetype']);
    assert_eq('contrast', $delivery['openings'][1]['surface']);
    assert_eq([], $delivery['degradations']);
});

test('lost overlay support degrades to one reviewed stacked relation with an actionable warning', function () {
    $pages = above_fold_pages(null, 'image', 'image');
    $contract = above_fold_resolve($pages);
    $parts = [
        'page-home--hero' => above_fold_image_part('hero', 50, className: 'hero-composition--cinematic-safe-zone'),
        'page-menu--menu-opening' => above_fold_solid_part('menu-opening', 'base'),
    ];
    $facts = AboveFoldPartFacts::inspect($pages, $parts, $contract);
    $delivery = AboveFoldContract::finalizeDelivery($contract, $pages, $facts);
    assert_eq('stacked', $delivery['header']['mode']);
    assert_eq('standard-row', $delivery['header']['archetype']);
    assert_eq(['base', 'base'], array_column($delivery['openings'], 'top_protection_token'));
    assert_eq('overlay-support-lost', $delivery['degradations'][0]['code']);
    $warning = AboveFoldContract::warningRows($delivery)[0];
    assert_contains("file='aboveFold.json'", $warning);
    assert_contains('path="header.mode"', $warning);
    assert_contains('authored="overlay"', $warning);
    assert_contains('delivered="stacked"', $warning);
    assert_contains('page-menu--menu-opening', $warning);
    assert_contains('disposition=', $warning);

    $againFacts = AboveFoldPartFacts::inspect($pages, $parts, $delivery);
    assert_eq($delivery, AboveFoldContract::finalizeDelivery($delivery, $pages, $againFacts));
});

test('split navigation and removed neighbors degrade only enumerated delivery facts', function () {
    $pages = above_fold_pages(null, 'contrast', 'contrast');
    $pages[0]['sections'][0]['layout_archetype'] = 'mixed-width-editorial';
    $contract = above_fold_resolve($pages, recipe: 'focal-subject-stage', forced: 'split-nav');
    assert_eq('split-nav', $contract['header']['archetype']);

    $deliveredPages = [$pages[0]];
    $deliveredPages[0]['sections'] = [$deliveredPages[0]['sections'][0]];
    $parts = ['page-home--hero' => above_fold_solid_part('hero', 'contrast')];
    $facts = AboveFoldPartFacts::inspect($deliveredPages, $parts, $contract);
    $delivery = AboveFoldContract::finalizeDelivery($contract, $deliveredPages, $facts);
    assert_eq('standard-row', $delivery['header']['archetype']);
    assert_eq('stacked', $delivery['header']['mode']);
    assert_eq(1, $delivery['delivered']['page_count']);
    assert_eq(1, count($delivery['openings']));
    assert_eq('home', $delivery['openings'][0]['page']);
    assert_eq(null, $delivery['following_section']);
    assert_eq('footer', $delivery['seam']['following_kind']);
    assert_eq('split-nav-page-count', $delivery['degradations'][0]['code']);

    $againFacts = AboveFoldPartFacts::inspect($deliveredPages, $parts, $delivery);
    assert_eq($delivery, AboveFoldContract::finalizeDelivery($delivery, $deliveredPages, $againFacts));
    $warning = AboveFoldContract::warningRows($delivery)[0];
    assert_contains('authored="split-nav"', $warning);
    assert_contains('delivered="standard-row"', $warning);
});

test('primary action delivery requires both the exact control and a delivered page or part anchor', function () {
    $action = ['label' => 'See details', 'intent' => 'Show the menu details.', 'destination' => '/menu/#details'];
    $pages = above_fold_pages($action);
    $contract = above_fold_resolve($pages);
    $parts = [
        'page-home--hero' => above_fold_image_part(
            'hero',
            50,
            above_fold_action_markup('See details', '/menu/#details'),
            'hero-composition--cinematic-safe-zone',
        ),
        'page-menu--menu-opening' => above_fold_solid_part('menu-opening', 'contrast'),
        'page-menu--details' => above_fold_solid_part('details', 'base'),
    ];
    $facts = AboveFoldPartFacts::inspect($pages, $parts, $contract);
    assert_true($facts['primary_action_control_delivered']);
    assert_true($facts['primary_action_target_delivered']);
    assert_true($facts['primary_action_delivered']);
    assert_eq($contract['primary_action'], AboveFoldContract::finalizeDelivery(
        $contract,
        $pages,
        $facts,
    )['primary_action']);

    // The stale part byte must not keep an anchor alive after its plan entry
    // was pruned; only delivered page/section identities are authoritative.
    $pruned = $pages;
    $pruned[1]['sections'] = [$pruned[1]['sections'][0]];
    $deadFacts = AboveFoldPartFacts::inspect($pruned, $parts, $contract);
    assert_true($deadFacts['primary_action_control_delivered']);
    assert_true(!$deadFacts['primary_action_target_delivered']);
    assert_true(!$deadFacts['primary_action_delivered']);
    $delivery = AboveFoldContract::finalizeDelivery($contract, $pruned, $deadFacts);
    assert_eq(null, $delivery['primary_action']);
    assert_true(!in_array('primary-action', $delivery['ownership']['hero'], true));
    assert_eq('primary-action-target-lost', $delivery['degradations'][0]['code']);
    $warning = AboveFoldContract::warningRows($delivery)[0];
    assert_contains('/menu/#details', $warning);
    assert_contains('delivered=null', $warning);
    assert_contains('dead control', $warning);
});

test('an omitted or paraphrased primary control degrades separately from a live target', function () {
    $action = ['label' => 'See details', 'intent' => 'Show the menu details.', 'destination' => '/menu/#details'];
    $pages = above_fold_pages($action);
    $contract = above_fold_resolve($pages);
    $parts = [
        'page-home--hero' => above_fold_image_part(
            'hero',
            50,
            above_fold_action_markup('Browse details', '/menu/#details'),
            'hero-composition--cinematic-safe-zone',
        ),
        'page-menu--menu-opening' => above_fold_solid_part('menu-opening', 'contrast'),
        'page-menu--details' => above_fold_solid_part('details', 'base'),
    ];
    $facts = AboveFoldPartFacts::inspect($pages, $parts, $contract);
    assert_true(!$facts['primary_action_control_delivered']);
    assert_true($facts['primary_action_target_delivered']);
    $delivery = AboveFoldContract::finalizeDelivery($contract, $pages, $facts);
    assert_eq(null, $delivery['primary_action']);
    assert_eq('primary-action-not-delivered', $delivery['degradations'][0]['code']);
});

test('downstream consumers reject corrupt header relations and unverified token pairs', function () {
    $overlay = above_fold_resolve(above_fold_pages(null, 'image', 'contrast'));
    assert_eq('overlay', $overlay['header']['mode']);

    $mutations = [
        static function (array $contract): array {
            $contract['header']['archetype'] = 'standard-row';
            return $contract;
        },
        static function (array $contract): array {
            $contract['header']['foreground_token'] = 'accent';
            return $contract;
        },
        static function (array $contract): array {
            $contract['header']['protect_top_edge'] = false;
            return $contract;
        },
        static function (array $contract): array {
            $contract['openings'][0]['top_protection_token'] = 'base';
            return $contract;
        },
        static function (array $contract): array {
            $contract['theme_tokens']['contrast']['token'] = 'secondary';
            return $contract;
        },
    ];
    foreach ($mutations as $mutate) {
        assert_throws(fn () => AboveFoldContract::assertPhase($mutate($overlay), AboveFoldContract::PHASE_DELIVERY));
    }

    $stacked = above_fold_resolve(
        above_fold_pages(null, 'contrast', 'contrast'),
        recipe: 'focal-subject-stage',
        forced: 'standard-row',
    );
    $stacked['header']['archetype'] = 'minimal-overlay';
    assert_throws(fn () => AboveFoldContract::assertPhase($stacked, AboveFoldContract::PHASE_DELIVERY));
});

test('header text-shape facts follow the archetype and the stated tagline (BIGR-773)', function () {
    $pages = above_fold_pages(null, 'base', 'base');

    // branded-lockup + a stated tagline: the lockup renders it, two text rows.
    $lockup = above_fold_resolve(
        $pages,
        recipe: 'editorial-split',
        forced: 'branded-lockup',
        tagline: 'Handmade ceramic lamps from Copenhagen',
    );
    assert_eq('branded-lockup', $lockup['header']['archetype']);
    assert_eq(true, $lockup['header']['displays_tagline']);
    assert_eq('Handmade ceramic lamps from Copenhagen', $lockup['header']['tagline_text']);
    assert_eq(2, $lockup['header']['text_rows']);

    // branded-lockup with no stated tagline: nothing to render — one text row.
    $bare = above_fold_resolve($pages, recipe: 'editorial-split', forced: 'branded-lockup');
    assert_eq(false, $bare['header']['displays_tagline']);
    assert_eq(null, $bare['header']['tagline_text']);
    assert_eq(1, $bare['header']['text_rows']);

    // centered-masthead never renders a tagline but always stacks two rows
    // (wordmark row + nav row), so the hero eyebrow gate still engages.
    $masthead = above_fold_resolve(
        $pages,
        recipe: 'editorial-split',
        forced: 'centered-masthead',
        tagline: 'Handmade ceramic lamps from Copenhagen',
    );
    assert_eq('centered-masthead', $masthead['header']['archetype']);
    assert_eq(false, $masthead['header']['displays_tagline']);
    assert_eq(null, $masthead['header']['tagline_text']);
    assert_eq(2, $masthead['header']['text_rows']);

    // A single-row bar without a stated tagline stays one text row.
    $row = above_fold_resolve($pages, recipe: 'editorial-split', forced: 'standard-row');
    assert_eq(false, $row['header']['displays_tagline']);
    assert_eq(1, $row['header']['text_rows']);

    // standard-row + a stated tagline renders it too (BIGR-775): the hero no
    // longer carries an eyebrow, so orientation copy lives in the header.
    $rowTagline = above_fold_resolve(
        $pages,
        recipe: 'editorial-split',
        forced: 'standard-row',
        tagline: 'Handmade ceramic lamps from Copenhagen',
    );
    assert_eq(true, $rowTagline['header']['displays_tagline']);
    assert_eq('Handmade ceramic lamps from Copenhagen', $rowTagline['header']['tagline_text']);
    assert_eq(2, $rowTagline['header']['text_rows']);

    // Header degradation resets the text-shape facts with the archetype.
    $split = above_fold_resolve(
        above_fold_pages(null, 'contrast', 'contrast'),
        recipe: 'focal-subject-stage',
        forced: 'split-nav',
        tagline: 'Handmade ceramic lamps from Copenhagen',
    );
    $deliveredPages = [above_fold_pages(null, 'contrast', 'contrast')[0]];
    $deliveredPages[0]['sections'] = [$deliveredPages[0]['sections'][0]];
    $parts = ['page-home--hero' => above_fold_solid_part('hero', 'contrast')];
    $facts = AboveFoldPartFacts::inspect($deliveredPages, $parts, $split);
    $degraded = AboveFoldContract::finalizeDelivery($split, $deliveredPages, $facts);
    assert_eq('standard-row', $degraded['header']['archetype']);
    // Degradation is conservative: the stated tagline is out of scope at
    // degrade time, so the fallback standard-row renders without one even
    // though the archetype's full form could carry it (BIGR-775).
    assert_eq(false, $degraded['header']['displays_tagline']);
    assert_eq(null, $degraded['header']['tagline_text']);
    assert_eq(1, $degraded['header']['text_rows']);
});

test('a custom cover dim matching the protection color keeps overlay support (BIGR-778)', function () {
    $part = static function (string $hex, array $extra = []): string {
        $attrs = array_replace([
            'dimRatio' => 60,
            'customOverlayColor' => $hex,
            'isUserOverlayColor' => true,
        ], $extra);
        return '<!-- wp:group {"anchor":"hero","layout":{"type":"constrained"}} -->'
            . '<div id="hero" class="wp-block-group">'
            . '<!-- wp:cover ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' -->'
            . '<div class="wp-block-cover"><span aria-hidden="true" '
            . 'class="wp-block-cover__background has-background-dim-60 has-background-dim" '
            . 'style="background-color:' . $hex . '"></span>'
            . '<div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->'
            . '</div><!-- /wp:group -->';
    };

    // The exact protection hex is the protection token, however spelled.
    assert_true(AboveFoldPartFacts::supportsOverlay($part('#161513'), 'image', 'contrast', '#161513'));
    assert_true(AboveFoldPartFacts::supportsOverlay($part('#161513'), 'image', 'contrast', '161513'));
    assert_true(AboveFoldPartFacts::supportsOverlay($part('#111'), 'image', 'contrast', '#111111'));
    assert_true(AboveFoldPartFacts::supportsClearOverlayTop($part('#161513'), 'image', 'contrast', '#161513'));
    assert_true(AboveFoldPartFacts::supportsOverlay(
        $part('#161513', ['overlayColor' => '']),
        'image',
        'contrast',
        '#161513',
    ), 'a blank preset slug leaves the exact custom overlay color authoritative');
    assert_true(!AboveFoldPartFacts::supportsOverlay(
        $part('#161513', ['overlayColor' => ' ']),
        'image',
        'contrast',
        '#161513',
    ), 'a whitespace preset slug suppresses the custom color in Core but paints no usable preset');

    // A nonblank preset slug is Core's effective color even if a stale custom
    // value still happens to match the protection token.
    $wrongPreset = $part('#161513', ['overlayColor' => 'base']);
    assert_true(!AboveFoldPartFacts::supportsOverlay($wrongPreset, 'image', 'contrast', '#161513'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($wrongPreset, 'image', 'contrast', '#161513'));

    // A genuinely different color, an unparseable hex, or an unknown token
    // hex still fail: color equality is proven, never assumed.
    assert_true(!AboveFoldPartFacts::supportsOverlay($part('#141414'), 'image', 'contrast', '#161513'));
    assert_true(!AboveFoldPartFacts::supportsOverlay($part('not-a-color'), 'image', 'contrast', '#161513'));
    assert_true(!AboveFoldPartFacts::supportsOverlay($part('#161513'), 'image', 'contrast'));

    $implicitFullDim = str_replace(
        ['"dimRatio":60,', 'has-background-dim-60'],
        ['', 'has-background-dim-100'],
        $part('#161513'),
    );
    assert_true(AboveFoldPartFacts::supportsClearOverlayTop(
        $implicitFullDim,
        'image',
        'contrast',
        '#161513',
    ), 'Core Cover defaults an omitted dimRatio to its fully opaque 100% class');
    assert_eq(100.0, AboveFoldPartFacts::clearOverlayTopDimRatio(
        $implicitFullDim,
        'image',
        'contrast',
        '#161513',
    ));

    // Familiar paint classes do not prove a surface the browser suppresses,
    // nor may arbitrary saved inline CSS ride beside Core's one custom-color
    // declaration when the clear header depends on that exact paint.
    $hidden = str_replace('aria-hidden="true"', 'aria-hidden="true" hidden', $part('#161513'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($hidden, 'image', 'contrast', '#161513'));
    $displayNone = str_replace(
        'style="background-color:#161513"',
        'style="background-color:#161513;display:none"',
        $part('#161513'),
    );
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($displayNone, 'image', 'contrast', '#161513'));
    $extraClass = str_replace(
        'has-background-dim-60 has-background-dim',
        'has-background-dim-60 has-background-dim generated-hidden-overlay',
        $part('#161513'),
    );
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop($extraClass, 'image', 'contrast', '#161513'));
    $missingCoverBox = str_replace('<div class="wp-block-cover">', '<div>', $part('#161513'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop(
        $missingCoverBox,
        'image',
        'contrast',
        '#161513',
    ));
    $missingGroupBox = str_replace('class="wp-block-group"', 'class="generated-group"', $part('#161513'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop(
        $missingGroupBox,
        'image',
        'contrast',
        '#161513',
    ));
    foreach ([
        'style="opacity:.5"',
        'style="filter:opacity(0)"',
        'style="display:none/**/"',
        'style="display/*;*/:none"',
        'style="-webkit-filter:opacity(0)"',
        'style="-webkit-clip-path:inset(100%)"',
        'style="-webkit-mask-image:linear-gradient(transparent,transparent)"',
        'style="height:0;overflow:hidden"',
        'style="max-height:0;overflow:clip"',
    ] as $rootEffect) {
        $attenuated = str_replace(
            '<div class="wp-block-cover">',
            '<div class="wp-block-cover" ' . $rootEffect . '>',
            $part('#161513'),
        );
        assert_true(AboveFoldPartFacts::supportsOverlay(
            $attenuated,
            'image',
            'contrast',
            '#161513',
        ), 'saved wrapper effects keep the protective header scrim available');
        assert_true(!AboveFoldPartFacts::supportsClearOverlayTop(
            $attenuated,
            'image',
            'contrast',
            '#161513',
        ));
    }
    foreach ([
        'screen-reader-text',
        'hero-entrance',
        'ambient-drift',
        'custom-motion',
        'hover-lift',
    ] as $rootEffectClass) {
        $suppressed = str_replace(
            'class="wp-block-group"',
            'class="wp-block-group ' . $rootEffectClass . '"',
            $part('#161513'),
        );
        assert_true(AboveFoldPartFacts::supportsOverlay(
            $suppressed,
            'image',
            'contrast',
            '#161513',
        ), 'wrapper effects keep the ordinary scrimmed overlay relation');
        assert_true(!AboveFoldPartFacts::supportsClearOverlayTop(
            $suppressed,
            'image',
            'contrast',
            '#161513',
        ));
    }

    $attenuatedSolid = str_replace(
        'has-contrast-background-color has-background"',
        'has-contrast-background-color has-background" style="opacity:.5"',
        above_fold_solid_part('proof', 'contrast'),
    );
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop(
        $attenuatedSolid,
        'contrast',
        'contrast',
        '#161513',
    ));
    $clippedSolid = str_replace(
        'has-contrast-background-color has-background"',
        'has-contrast-background-color has-background" style="height:0;overflow:hidden"',
        above_fold_solid_part('proof', 'contrast'),
    );
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop(
        $clippedSolid,
        'contrast',
        'contrast',
        '#161513',
    ));
    $resetSolid = str_replace(
        'has-contrast-background-color has-background"',
        'has-contrast-background-color has-background" style="all:initial"',
        above_fold_solid_part('proof', 'contrast'),
    );
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop(
        $resetSolid,
        'contrast',
        'contrast',
        '#161513',
    ));
    $missingSolidBox = str_replace('wp-block-group', 'generated-group', above_fold_solid_part('proof', 'contrast'));
    assert_true(!AboveFoldPartFacts::supportsClearOverlayTop(
        $missingSolidBox,
        'contrast',
        'contrast',
        '#161513',
    ));

    // inspect() feeds the contract's own token hex into the comparison.
    $pages = [above_fold_pages()[0]];
    $contract = above_fold_resolve($pages, theme: ['base' => '#FFFFFF', 'contrast' => '#161513']);
    assert_eq('overlay', $contract['header']['mode']);
    $facts = AboveFoldPartFacts::inspect(
        $pages,
        ['page-home--hero' => $part('#161513'), 'page-home--proof' => above_fold_solid_part('proof', 'base')],
        $contract,
    );
    assert_eq(true, $facts['opening_overlay_support']['page-home--hero']);
});

test('overlay token roles follow luminance so dark palettes stay overlay-eligible (BIGR-778)', function () {
    // Dark theme: base is near-black, contrast is cream. The foreground must
    // be the light token and the protection dim the dark one — the reverse
    // of the light-theme assignment, which previously demanded a cream
    // scrim over the hero image and guaranteed overlay-support loss.
    $pages = [above_fold_pages()[0]];
    $dark = above_fold_resolve($pages, theme: ['base' => '#14090C', 'contrast' => '#F2E6DC']);
    assert_eq('overlay', $dark['header']['mode']);
    assert_eq('contrast', $dark['header']['foreground_token']);
    assert_eq('base', $dark['header']['protection_token']);
    assert_eq('base', $dark['openings'][0]['top_protection_token']);

    // Light theme keeps the historical assignment byte-for-byte.
    $light = above_fold_resolve($pages);
    assert_eq('overlay', $light['header']['mode']);
    assert_eq('base', $light['header']['foreground_token']);
    assert_eq('contrast', $light['header']['protection_token']);

    // A dark theme's solid interior opening supports overlay only on the
    // dark protection surface, not on a name-fixed 'contrast'.
    $twoPages = above_fold_pages(menuSurface: 'base');
    assert_eq('overlay', above_fold_resolve(
        $twoPages,
        theme: ['base' => '#14090C', 'contrast' => '#F2E6DC'],
    )['header']['mode']);
    assert_eq('stacked', above_fold_resolve(
        above_fold_pages(menuSurface: 'contrast'),
        theme: ['base' => '#14090C', 'contrast' => '#F2E6DC'],
    )['header']['mode']);
});

test('final header text-shape facts reflect whether the promised tagline was delivered', function () {
    $pages = above_fold_pages(null, 'base', 'base');
    $contract = above_fold_resolve(
        $pages,
        recipe: 'editorial-split',
        forced: 'standard-row',
        tagline: 'Handmade ceramic lamps from Copenhagen',
    );
    $hero = above_fold_solid_part('hero', 'base');
    $headerWithoutTagline = above_fold_solid_part(
        'header',
        'base',
        '<!-- wp:site-title /-->',
        'header-archetype--standard-row',
    );
    $missingFacts = AboveFoldPartFacts::inspect($pages, [
        'header' => $headerWithoutTagline,
        'page-home--hero' => $hero,
    ], $contract);

    $missing = AboveFoldContract::finalizeMarkup($contract, $pages, $missingFacts);

    assert_eq(false, $missing['header']['displays_tagline']);
    assert_eq(null, $missing['header']['tagline_text']);
    assert_eq(1, $missing['header']['text_rows']);
    $degradation = array_values(array_filter(
        $missing['degradations'],
        static fn (array $row): bool => ($row['code'] ?? '') === 'header-tagline-not-delivered',
    ));
    assert_eq(1, count($degradation));
    assert_contains('header-tagline-not-delivered', implode("\n", AboveFoldContract::warningRows($missing)));

    $identityStack = '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /--><!-- wp:site-tagline /--></div><!-- /wp:group -->';
    $headerWithTagline = str_replace('<!-- wp:site-title /-->', $identityStack, $headerWithoutTagline);
    $deliveredFacts = AboveFoldPartFacts::inspect($pages, [
        'header' => $headerWithTagline,
        'page-home--hero' => $hero,
    ], $contract);
    $delivered = AboveFoldContract::finalizeMarkup($contract, $pages, $deliveredFacts);

    assert_eq(true, $delivered['header']['displays_tagline']);
    assert_eq('Handmade ceramic lamps from Copenhagen', $delivered['header']['tagline_text']);
    assert_eq(2, $delivered['header']['text_rows']);
    assert_eq([], array_values(array_filter(
        $delivered['degradations'],
        static fn (array $row): bool => ($row['code'] ?? '') === 'header-tagline-not-delivered',
    )));

    $rawIdentity = str_replace(
        '<div class="wp-block-group">',
        '<div class="wp-block-group">Unexpected identity copy',
        $identityStack,
    );
    $rawHeader = str_replace('<!-- wp:site-title /-->', $rawIdentity, $headerWithoutTagline);
    $rawFacts = AboveFoldPartFacts::inspect($pages, [
        'header' => $rawHeader,
        'page-home--hero' => $hero,
    ], $contract);
    assert_eq(1, $rawFacts['header']['site_tagline_blocks']);
    assert_eq(0, $rawFacts['header']['malformed_site_tagline_blocks']);
    assert_eq(1, $rawFacts['header']['invalid_site_tagline_topology']);
    $rawFinal = AboveFoldContract::finalizeMarkup($contract, $pages, $rawFacts);
    assert_eq(false, $rawFinal['header']['displays_tagline']);
    assert_eq(1, $rawFinal['header']['text_rows']);
    assert_contains(
        'header-tagline-not-delivered',
        implode("\n", AboveFoldContract::warningRows($rawFinal)),
    );
    $rawAgain = $rawFinal;
    $rawAgain['phase'] = AboveFoldContract::PHASE_DELIVERY;
    assert_eq($rawFinal, AboveFoldContract::finalizeMarkup($rawAgain, $pages, $rawFacts));

    $duplicateIdentity = str_replace(
        '<!-- wp:site-tagline /-->',
        '<!-- wp:site-tagline /--><!-- wp:site-tagline /-->',
        $identityStack,
    );
    $duplicateHeader = str_replace('<!-- wp:site-title /-->', $duplicateIdentity, $headerWithoutTagline);
    $duplicateFacts = AboveFoldPartFacts::inspect($pages, [
        'header' => $duplicateHeader,
        'page-home--hero' => $hero,
    ], $contract);
    $duplicate = AboveFoldContract::finalizeMarkup($contract, $pages, $duplicateFacts);
    assert_eq(false, $duplicate['header']['displays_tagline']);
    assert_contains(
        'header-tagline-not-delivered',
        implode("\n", AboveFoldContract::warningRows($duplicate)),
    );

    $nestedIdentity = '<!-- wp:site-title --><span class="wp-block-site-title">'
        . '<!-- wp:site-tagline /--></span><!-- /wp:site-title -->';
    $nestedHeader = str_replace('<!-- wp:site-title /-->', $nestedIdentity, $headerWithoutTagline);
    $nestedFacts = AboveFoldPartFacts::inspect($pages, [
        'header' => $nestedHeader,
        'page-home--hero' => $hero,
    ], $contract);
    assert_eq(0, $nestedFacts['header']['site_tagline_blocks']);
    assert_eq(1, $nestedFacts['header']['malformed_site_tagline_blocks']);
    $nested = AboveFoldContract::finalizeMarkup($contract, $pages, $nestedFacts);
    assert_eq(false, $nested['header']['displays_tagline']);
    $nestedAgain = $nested;
    $nestedAgain['phase'] = AboveFoldContract::PHASE_DELIVERY;
    assert_eq($nested, AboveFoldContract::finalizeMarkup($nestedAgain, $pages, $nestedFacts));

    $separateIdentity = '<!-- wp:site-title /-->'
        . '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}}} -->'
        . '<div class="wp-block-group"><!-- wp:site-tagline /--></div><!-- /wp:group -->';
    $separateHeader = str_replace('<!-- wp:site-title /-->', $separateIdentity, $headerWithoutTagline);
    $separateFacts = AboveFoldPartFacts::inspect($pages, [
        'header' => $separateHeader,
        'page-home--hero' => $hero,
    ], $contract);
    assert_eq(1, $separateFacts['header']['invalid_site_tagline_topology']);
    $separateFinal = AboveFoldContract::finalizeMarkup($contract, $pages, $separateFacts);
    assert_eq(false, $separateFinal['header']['displays_tagline']);
    $separateAgain = $separateFinal;
    $separateAgain['phase'] = AboveFoldContract::PHASE_DELIVERY;
    assert_eq($separateFinal, AboveFoldContract::finalizeMarkup($separateAgain, $pages, $separateFacts));

    $noTaglineContract = above_fold_resolve(
        $pages,
        recipe: 'editorial-split',
        forced: 'standard-row',
    );
    $residualFacts = AboveFoldPartFacts::inspect($pages, [
        'header' => $headerWithTagline,
        'page-home--hero' => $hero,
    ], $noTaglineContract);
    $residual = AboveFoldContract::finalizeMarkup($noTaglineContract, $pages, $residualFacts);
    assert_contains(
        'header-tagline-unplanned-delivery',
        implode("\n", AboveFoldContract::warningRows($residual)),
    );
});

test('headerFacts reads overlay mode from the classes delivery actually emits (BIGR-799)', function () {
    // The canonical delivered overlay root, exactly as HeaderHeroStep ships it
    // (2026-08-07 cohort portfolio6/pulso2): behavior classes present, the
    // legacy bare `header-overlay` hook already stripped. The old detector
    // looked only for that legacy token and misread EVERY such header as
    // stacked, so warnings.json carried a false above-fold drift row on 4/7
    // cohort sites while masking any real future downgrade.
    $canonicalOverlay = '<!-- wp:group {"className":"header-archetype--minimal-overlay '
        . 'header-behavior-overlay-to-solid header-start-transparent header-scrolled-contrast '
        . 'header-foreground-base header-top-transparent","textColor":"base","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--minimal-overlay header-behavior-overlay-to-solid '
        . 'header-start-transparent header-scrolled-contrast header-foreground-base header-top-transparent '
        . 'has-base-color has-text-color"><!-- wp:site-title /--></div><!-- /wp:group -->';
    $facts = AboveFoldPartFacts::headerFacts($canonicalOverlay);
    assert_eq('overlay', $facts['mode']);
    assert_eq('minimal-overlay', $facts['archetype']);

    // The kit-scrim overlay variant keeps behavior + transparent start but has
    // no earned header-top-transparent class; still overlay.
    $scrimOverlay = str_replace(' header-top-transparent', '', $canonicalOverlay);
    assert_eq('overlay', AboveFoldPartFacts::headerFacts($scrimOverlay)['mode']);

    // HeaderFallback's overlay markup is deliberately behavior-class-free and
    // still marks overlay with the bare legacy token.
    $fallbackOverlay = '<!-- wp:group {"className":"header-overlay header-archetype--minimal-columns",'
        . '"textColor":"base","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-overlay header-archetype--minimal-columns has-base-color '
        . 'has-text-color"><!-- wp:site-title /--></div><!-- /wp:group -->';
    assert_eq('overlay', AboveFoldPartFacts::headerFacts($fallbackOverlay)['mode']);

    // A genuinely stacked sticky-soft delivery keeps reading as stacked — its
    // start surface is a solid palette token, never transparent.
    $stacked = '<!-- wp:group {"className":"header-archetype--minimal-columns '
        . 'header-behavior-sticky-soft header-start-base header-scrolled-base header-foreground-contrast",'
        . '"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--minimal-columns header-behavior-sticky-soft '
        . 'header-start-base header-scrolled-base header-foreground-contrast has-contrast-color '
        . 'has-text-color has-base-background-color has-background"><!-- wp:site-title /--></div>'
        . '<!-- /wp:group -->';
    assert_eq('stacked', AboveFoldPartFacts::headerFacts($stacked)['mode']);

    // And the behavior-class-free static delivery stays stacked too.
    $static = '<!-- wp:group {"className":"header-archetype--minimal-columns","backgroundColor":"base",'
        . '"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--minimal-columns has-base-background-color '
        . 'has-background"><!-- wp:site-title /--></div><!-- /wp:group -->';
    assert_eq('stacked', AboveFoldPartFacts::headerFacts($static)['mode']);
});
