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
    return '<!-- wp:group ' . json_encode($root, JSON_UNESCAPED_SLASHES) . ' -->'
        . '<div id="' . $anchor . '" class="wp-block-group ' . $className . '">'
        . '<!-- wp:cover ' . json_encode($cover, JSON_UNESCAPED_SLASHES) . ' -->'
        . '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background"></span>'
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
    $contract = above_fold_resolve(above_fold_pages(), direction: 'rtl');
    assert_eq(['logical' => 'start', 'physical' => 'right'], $contract['regions']['text_safe']);
    assert_eq(['logical' => 'end', 'physical' => 'left'], $contract['regions']['focal']);
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

    // A single-row bar keeps the eyebrow available.
    $row = above_fold_resolve($pages, recipe: 'editorial-split', forced: 'standard-row');
    assert_eq(1, $row['header']['text_rows']);

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
    assert_eq(false, $degraded['header']['displays_tagline']);
    assert_eq(null, $degraded['header']['tagline_text']);
    assert_eq(1, $degraded['header']['text_rows']);
});
