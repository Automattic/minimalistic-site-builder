<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * Unit tests for PagePlanStep: per-section normalization (unique file-safe
 * slugs, art-direction enums, adjacency rule, card-grid cap — unchanged from
 * the landing-page era), the page-tree flattening, the per-page request
 * fan-out, and the end-to-end write of pages.json.
 */

/** A valid planned section; override fields per test. */
function plan_section(array $overrides = []): array
{
    return array_merge([
        'slug'             => 'hero',
        'title'            => 'Hero',
        'type'             => 'hero',
        'layout_archetype' => 'full-bleed-cover',
        'background'       => 'image',
        'vertical_density' => 'standard',
        'handoff'          => 'Sits between the site header above and the base-background about split below.',
    ], $overrides);
}

/** A siteSpec with a two-page tree (home + menu with one child). */
function plan_spec(array $overrides = []): array
{
    return array_merge([
        'name'     => 'Demo',
        'language' => 'en',
        'sections' => ['Hero', 'About'],
        'pages'    => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
            ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'What we bake', 'children' => [
                ['title' => 'Breads', 'slug' => 'breads', 'purpose' => 'Bread list', 'children' => []],
            ]],
        ],
    ], $overrides);
}

test('PagePlanStep declares its durable warning sink', function () {
    $step = new PagePlanStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    assert_eq(['pages.json', 'warnings.json'], $step->declaration()->writes);
});

test('PagePlanStep::jsonSchema constrains the complete section shape', function () {
    $schema = PagePlanStep::jsonSchema();

    assert_eq('object', $schema['type']);
    assert_eq(['sections'], $schema['required']);
    assert_eq(false, $schema['additionalProperties']);

    $sections = $schema['properties']['sections'];
    assert_eq('array', $sections['type']);

    $item = $sections['items'];
    $fields = [
        'slug',
        'title',
        'type',
        'purpose',
        'content_notes',
        'layout_archetype',
        'background',
        'vertical_density',
        'handoff',
    ];
    assert_eq('object', $item['type']);
    assert_eq($fields, $item['required']);
    assert_eq(false, $item['additionalProperties']);
    assert_eq($fields, array_keys($item['properties']));
    foreach ($fields as $field) {
        assert_eq('string', $item['properties'][$field]['type'], "{$field} is constrained to a string");
    }
    assert_true(!array_key_exists('enum', $item['properties']['type']), 'type remains a free-form semantic label');
    assert_true(!array_key_exists('role', $item['properties']), 'role is derived after generation, not requested from the model');
    assert_eq(PagePlanStep::ARCHETYPES, $item['properties']['layout_archetype']['enum']);
    assert_eq(PagePlanStep::BACKGROUNDS, $item['properties']['background']['enum']);
});

test('PagePlanStep::normalize forces unique, file-safe slugs and fills defaults', function () {
    $sections = PagePlanStep::normalize([
        plan_section(['slug' => null]),                  // slug derived from title
        plan_section(['slug' => 'Our Story!', 'title' => 'About', 'layout_archetype' => 'asymmetric-split', 'background' => 'base']),
        plan_section(['title' => 'Another Hero', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']), // duplicate slug -> hero-2
        'not-an-array',                                  // skipped
    ]);

    $slugs = array_column($sections, 'slug');
    assert_eq(['hero', 'our-story', 'hero-2'], $slugs);
    assert_eq('hero', $sections[0]['type'], 'type preserved');
    assert_eq(['hero', 'content', 'closing'], array_column($sections, 'role'), 'roles derived after invalid entries are skipped');
});

test('PagePlanStep::normalize preserves a novel free-form type', function () {
    $sections = PagePlanStep::normalize([
        plan_section(['type' => 'seasonal-tasting-menu']),
    ]);

    assert_eq('seasonal-tasting-menu', $sections[0]['type']);
    assert_eq('hero', $sections[0]['role']);
});

test('PagePlanStep::normalize stamps positional roles and ignores model annotations', function () {
    $sections = PagePlanStep::normalize([
        plan_section(['role' => 'closing']),
        plan_section(['slug' => 'middle', 'layout_archetype' => 'asymmetric-split']),
        plan_section(['slug' => 'end', 'role' => 'sidebar', 'layout_archetype' => 'centered-stack']),
    ]);

    assert_eq(['hero', 'content', 'closing'], array_column($sections, 'role'));
});

test('PagePlanStep::normalize stamps a singleton as hero', function () {
    $sections = PagePlanStep::normalize([plan_section(['role' => 'closing'])]);

    assert_eq('hero', $sections[0]['role']);
});

test('PagePlanStep removes template-owned footer identities without touching siblings and reaches a fixed point', function () {
    $hero = plan_section(['slug' => 'welcome', 'title' => 'Welcome']);
    $story = plan_section([
        'slug'             => 'story',
        'title'            => 'Story',
        'type'             => 'editorial-story',
        'layout_archetype' => 'asymmetric-split',
        'background'       => 'base',
    ]);
    $closing = plan_section([
        'slug'             => 'footerless-closing',
        'title'            => 'Footerless Closing',
        'type'             => 'cta',
        'layout_archetype' => 'centered-stack',
        'background'       => 'contrast',
    ]);
    $copyright = plan_section([
        'slug' => 'copyright-licensing',
        'title' => 'Copyright & Licensing',
        'type' => 'policy',
        'layout_archetype' => 'offset-grid',
    ]);
    $legalTeam = plan_section([
        'slug' => 'legal-team',
        'title' => 'Contact the legal team',
        'type' => 'team',
        'layout_archetype' => 'mixed-width-editorial',
    ]);
    $siteGuide = plan_section([
        'slug' => 'site-details',
        'title' => 'Archaeological site information',
        'type' => 'visitor-guide',
        'layout_archetype' => 'list-with-thumbnails',
    ]);
    $siteInformation = plan_section([
        'slug' => 'site-information',
        'title' => 'Site Information',
        'type' => 'visitor-guide',
        'layout_archetype' => 'equal-card-grid',
    ]);
    $siteInfo = plan_section([
        'slug' => 'site-info',
        'title' => 'Site Info',
        'type' => 'utility',
        'layout_archetype' => 'full-bleed-cover',
    ]);
    // The prompt commits slug/type to English identifiers; a localized TITLE
    // alone is on-page copy, never a footer identity.
    $localizedTitle = plan_section([
        'slug' => 'visit',
        'title' => 'Visítanos',
        'type' => 'utility',
        'layout_archetype' => 'centered-stack',
    ]);
    $raw = [
        $hero,
        plan_section(['slug' => 'utility', 'title' => 'Footer Info', 'type' => 'contact']),
        $story,
        plan_section(['slug' => 'site-footer', 'title' => 'Links', 'type' => 'navigation']),
        plan_section(['slug' => 'legal', 'title' => 'Legal', 'type' => 'footerInfo']),
        plan_section(['slug' => 'chrome', 'title' => 'Chrome', 'type' => 'site chrome']),
        plan_section(['slug' => 'colophon', 'title' => 'Credits', 'type' => 'credits']),
        $localizedTitle,
        $siteInfo,
        $copyright,
        $legalTeam,
        $siteGuide,
        $siteInformation,
        $closing,
    ];

    $warnings = [];
    $filtered = PagePlanStep::removeTemplateFooterSections($raw, $warnings, 'home');

    assert_eq(
        [$hero, $story, $localizedTitle, $siteInfo, $copyright, $legalTeam, $siteGuide, $siteInformation, $closing],
        $filtered,
        'non-footer siblings remain byte-for-byte and in order'
    );
    assert_eq(5, count($warnings), 'each removed section has its own actionable warning');
    $joined = implode("\n", $warnings);
    assert_contains("file='pages.json'", $joined);
    assert_contains("pages[slug='home'].sections[1]", $joined);
    assert_contains('"title":"Footer Info"', $joined);
    assert_contains('delivered=removed', $joined);
    assert_contains('disposition=', $joined);
    assert_contains('theme/parts/footer.html', $joined);

    $normalized = PagePlanStep::normalize($filtered);
    assert_eq(
        ['hero', 'content', 'content', 'content', 'content', 'content', 'content', 'content', 'closing'],
        array_column($normalized, 'role')
    );

    $warningsAtFixedPoint = $warnings;
    assert_eq(
        $filtered,
        PagePlanStep::removeTemplateFooterSections($filtered, $warnings, 'home'),
        'a second pass makes no content change'
    );
    assert_eq($warningsAtFixedPoint, $warnings, 'a second pass adds no duplicate warning');
});

test('PagePlanStep::normalize rejects an empty type', function () {
    assert_throws(function () {
        PagePlanStep::normalize([plan_section(['type' => '  '])]);
    }, 'missing type');
});

test('PagePlanStep::normalize keeps the art-direction fields on a valid plan', function () {
    $sections = PagePlanStep::normalize([
        plan_section(),
        plan_section(['slug' => 'work', 'title' => 'Work', 'role' => 'content', 'type' => 'case-study-mosaic', 'layout_archetype' => 'offset-grid', 'background' => 'base', 'handoff' => 'Between the image hero above and the contrast CTA below.']),
        plan_section(['slug' => 'cta', 'title' => 'CTA', 'role' => 'closing', 'type' => 'cta', 'layout_archetype' => 'centered-stack', 'background' => 'contrast', 'handoff' => 'Between the base offset grid above and the footer below.']),
    ]);

    assert_eq(3, count($sections));
    assert_eq('full-bleed-cover', $sections[0]['layout_archetype']);
    assert_eq('image', $sections[0]['background']);
    assert_contains('site header', $sections[0]['handoff']);
});

test('PagePlanStep::normalize rejects an unknown layout_archetype', function () {
    assert_throws(function () {
        PagePlanStep::normalize([plan_section(['layout_archetype' => 'fancy-mosaic'])]);
    }, 'invalid layout_archetype');
});

test('PagePlanStep::normalize rejects an unknown background', function () {
    assert_throws(function () {
        PagePlanStep::normalize([plan_section(['background' => 'plaid'])]);
    }, 'invalid background');
});

test('PagePlanStep::normalize rejects a missing handoff', function () {
    assert_throws(function () {
        PagePlanStep::normalize([plan_section(['handoff' => '  '])]);
    }, 'handoff');
});

test('PagePlanStep::normalize requires a known vertical density', function () {
    assert_throws(function () {
        PagePlanStep::normalize([plan_section(['vertical_density' => 'enormous'])]);
    }, 'invalid vertical_density');

    assert_throws(function () {
        $section = plan_section();
        unset($section['vertical_density']);
        PagePlanStep::normalize([$section]);
    }, 'missing vertical_density');
});

test('PagePlanStep::normalize rations spacious density across the page', function () {
    assert_throws(function () {
        PagePlanStep::normalize([
            plan_section(['slug' => 'one', 'layout_archetype' => 'centered-stack', 'vertical_density' => 'spacious']),
            plan_section(['slug' => 'two', 'layout_archetype' => 'asymmetric-split', 'vertical_density' => 'spacious']),
        ]);
    }, 'adjacent spacious sections must be rejected');

    assert_throws(function () {
        PagePlanStep::normalize([
            plan_section(['slug' => 'one', 'layout_archetype' => 'centered-stack', 'vertical_density' => 'spacious']),
            plan_section(['slug' => 'two', 'layout_archetype' => 'asymmetric-split', 'vertical_density' => 'standard']),
            plan_section(['slug' => 'three', 'layout_archetype' => 'offset-grid', 'vertical_density' => 'spacious']),
            plan_section(['slug' => 'four', 'layout_archetype' => 'mixed-width-editorial', 'vertical_density' => 'compact']),
            plan_section(['slug' => 'five', 'layout_archetype' => 'centered-stack', 'vertical_density' => 'spacious']),
        ]);
    }, 'more than two spacious sections must be rejected');
});

test('PagePlanStep::normalize rejects spacious density for content-dense sections', function () {
    foreach ([
        ['type' => 'gallery', 'layout_archetype' => 'centered-stack'],
        ['type' => 'features', 'layout_archetype' => 'offset-grid'],
        ['type' => 'services', 'layout_archetype' => 'equal-card-grid'],
        ['type' => 'faq', 'layout_archetype' => 'centered-stack'],
        // "type" is free-form model output: capitalization and compound
        // spellings still name the dense role.
        ['type' => 'Gallery', 'layout_archetype' => 'centered-stack'],
        ['type' => 'image-gallery', 'layout_archetype' => 'offset-grid'],
        ['type' => 'Pricing Table', 'layout_archetype' => 'centered-stack'],
    ] as $dense) {
        $message = '';
        try {
            PagePlanStep::normalize([
                plan_section(array_merge($dense, ['vertical_density' => 'spacious'])),
            ]);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }
        assert_contains('content-dense', $message);
        assert_contains("not 'spacious'", $message);
    }

    $shortCta = PagePlanStep::normalize([
        plan_section([
            'type' => 'cta',
            'layout_archetype' => 'mixed-width-editorial',
            'vertical_density' => 'spacious',
        ]),
    ]);
    assert_eq('spacious', $shortCta[0]['vertical_density'], 'short editorial CTA may deliberately breathe');
});

test('PagePlanStep::normalize rejects adjacent duplicate archetypes', function () {
    assert_throws(function () {
        PagePlanStep::normalize([
            plan_section(),
            plan_section(['slug' => 'work', 'role' => 'content', 'layout_archetype' => 'equal-card-grid', 'background' => 'base']),
            plan_section(['slug' => 'team', 'role' => 'closing', 'layout_archetype' => 'equal-card-grid', 'background' => 'tinted']),
        ]);
    }, 'adjacent');
});

test('PagePlanStep::normalize allows a repeated archetype when not adjacent', function () {
    $sections = PagePlanStep::normalize([
        plan_section(['layout_archetype' => 'equal-card-grid', 'background' => 'base']),
        plan_section(['slug' => 'story', 'role' => 'content', 'layout_archetype' => 'centered-stack', 'background' => 'tinted']),
        plan_section(['slug' => 'team', 'role' => 'closing', 'layout_archetype' => 'equal-card-grid', 'background' => 'base']),
    ]);
    assert_eq(3, count($sections));
});

test('PagePlanStep::normalize reports every violation in one rejection', function () {
    try {
        PagePlanStep::normalize([
            plan_section(['background' => 'plaid']),
            plan_section(['slug' => 'work', 'role' => 'content', 'layout_archetype' => 'centered-stack', 'handoff' => '']),
            plan_section(['slug' => 'team', 'role' => 'closing', 'layout_archetype' => 'centered-stack']),
        ]);
        assert_true(false, 'expected the plan to be rejected');
    } catch (RuntimeException $e) {
        assert_contains("invalid background 'plaid'", $e->getMessage());
        assert_contains("missing 'handoff'", $e->getMessage());
        assert_contains('adjacent sections', $e->getMessage());
    }
});

test('PagePlanStep::normalize does not report adjacency between invalid archetypes', function () {
    try {
        PagePlanStep::normalize([
            plan_section(['layout_archetype' => 'fancy-mosaic']),
            plan_section(['slug' => 'work', 'role' => 'closing', 'layout_archetype' => 'fancy-mosaic']),
        ]);
        assert_true(false, 'expected the plan to be rejected');
    } catch (RuntimeException $e) {
        assert_contains('invalid layout_archetype', $e->getMessage());
        assert_true(!str_contains($e->getMessage(), 'adjacent'), 'enum error must not cascade into an adjacency error');
    }
});

test('PagePlanStep::normalize rejects an interior page opening with a full-bleed cover', function () {
    try {
        PagePlanStep::normalize([
            plan_section(), // full-bleed-cover first
            plan_section(['slug' => 'cta', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
        ], front: false);
        assert_true(false, 'expected the interior plan to be rejected');
    } catch (RuntimeException $e) {
        assert_contains('INTERIOR page', $e->getMessage());
        assert_contains('full-bleed-cover', $e->getMessage());
        assert_contains('COMPACT', $e->getMessage());
    }
});

test('PagePlanStep::normalize allows a full-bleed cover opening on the front page and deeper in interior pages', function () {
    // Front page: cover opening is the point of a homepage hero.
    assert_eq(2, count(PagePlanStep::normalize([
        plan_section(),
        plan_section(['slug' => 'cta', 'role' => 'closing', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ], front: true)));
    // Interior page: a cover is only banned as the OPENING section.
    assert_eq(2, count(PagePlanStep::normalize([
        plan_section(['slug' => 'intro', 'layout_archetype' => 'centered-stack', 'background' => 'tinted']),
        plan_section(['slug' => 'gallery-band', 'role' => 'closing']),
    ], front: false)));
});

test('PagePlanStep::repairVariety demotes an interior page\'s leading full-bleed cover', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(),
        plan_section(['slug' => 'cta', 'role' => 'closing', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ], front: false);

    assert_true($sections[0]['layout_archetype'] !== 'full-bleed-cover', 'leading cover reassigned');
    PagePlanStep::normalize($sections, front: false); // the result passes interior validation
    // The same plan on the FRONT page is left alone.
    $front = PagePlanStep::repairVariety([plan_section()], front: true);
    assert_eq('full-bleed-cover', $front[0]['layout_archetype']);
});

test('PagePlanStep::normalize caps equal-card-grid at twice per page', function () {
    assert_throws(function () {
        PagePlanStep::normalize([
            plan_section(['layout_archetype' => 'equal-card-grid']),
            plan_section(['slug' => 'a', 'role' => 'content', 'layout_archetype' => 'centered-stack']),
            plan_section(['slug' => 'b', 'role' => 'content', 'layout_archetype' => 'equal-card-grid']),
            plan_section(['slug' => 'c', 'role' => 'content', 'layout_archetype' => 'offset-grid']),
            plan_section(['slug' => 'd', 'role' => 'closing', 'layout_archetype' => 'equal-card-grid']),
        ]);
    }, 'equal-card-grid');
});

test('PagePlanStep::repairVariety reassigns the later section of each adjacent duplicate pair', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'layout_archetype' => 'centered-stack']),
        plan_section(['slug' => 'b', 'role' => 'content', 'layout_archetype' => 'asymmetric-split']),
        plan_section(['slug' => 'c', 'role' => 'closing', 'layout_archetype' => 'asymmetric-split']),
    ]);

    assert_eq('centered-stack', $sections[0]['layout_archetype'], 'untouched');
    assert_eq('asymmetric-split', $sections[1]['layout_archetype'], 'first of the pair kept');
    assert_true($sections[2]['layout_archetype'] !== 'asymmetric-split', 'later section reassigned');
    PagePlanStep::normalize($sections); // the result passes validation
});

test('PagePlanStep::repairVariety fixes a run of three duplicates without creating new ones', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'layout_archetype' => 'centered-stack']),
        plan_section(['slug' => 'b', 'role' => 'content', 'layout_archetype' => 'centered-stack']),
        plan_section(['slug' => 'c', 'role' => 'closing', 'layout_archetype' => 'centered-stack']),
    ]);

    PagePlanStep::normalize($sections);
    assert_eq('centered-stack', $sections[0]['layout_archetype']);
    assert_eq('centered-stack', $sections[2]['layout_archetype'], 'non-adjacent repeat is allowed and kept');
});

test('PagePlanStep::repairVariety leaves a valid plan unchanged', function () {
    $raw = [
        plan_section(),
        plan_section(['slug' => 'work', 'role' => 'content', 'layout_archetype' => 'offset-grid']),
        plan_section(['slug' => 'cta', 'role' => 'closing', 'layout_archetype' => 'centered-stack']),
    ];
    assert_eq(
        array_column($raw, 'layout_archetype'),
        array_column(PagePlanStep::repairVariety($raw), 'layout_archetype')
    );
});

test('PagePlanStep::repairVariety reassigns equal-card-grids beyond the cap to non-grids', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'layout_archetype' => 'equal-card-grid']),
        plan_section(['slug' => 'b', 'role' => 'content', 'layout_archetype' => 'centered-stack']),
        plan_section(['slug' => 'c', 'role' => 'content', 'layout_archetype' => 'equal-card-grid']),
        plan_section(['slug' => 'd', 'role' => 'content', 'layout_archetype' => 'offset-grid']),
        plan_section(['slug' => 'e', 'role' => 'closing', 'layout_archetype' => 'equal-card-grid']),
    ]);

    PagePlanStep::normalize($sections);
    $grids = array_filter(array_column($sections, 'layout_archetype'), fn ($a) => $a === 'equal-card-grid');
    assert_eq(2, count($grids), 'first two grids kept, the third reassigned');
    assert_eq('equal-card-grid', $sections[0]['layout_archetype']);
    assert_eq('equal-card-grid', $sections[2]['layout_archetype']);
});

test('PagePlanStep::repairVariety leaves invalid archetypes for normalize to reject', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'layout_archetype' => 'fancy-mosaic']),
        plan_section(['slug' => 'b', 'role' => 'closing', 'layout_archetype' => 'fancy-mosaic']),
    ]);
    assert_eq(['fancy-mosaic', 'fancy-mosaic'], array_column($sections, 'layout_archetype'));
});

test('PagePlanStep::repairVariety demotes spacious pauses that break the density rules', function () {
    // A dense section, an adjacent pause, and a pause beyond the cap all
    // demote to 'standard'; the surviving pauses stay isolated.
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'type' => 'gallery', 'layout_archetype' => 'offset-grid', 'vertical_density' => 'spacious']),
        plan_section(['slug' => 'b', 'role' => 'content', 'layout_archetype' => 'centered-stack', 'vertical_density' => 'spacious']),
        plan_section(['slug' => 'c', 'role' => 'content', 'layout_archetype' => 'asymmetric-split', 'vertical_density' => 'spacious']),
        plan_section(['slug' => 'd', 'role' => 'content', 'layout_archetype' => 'mixed-width-editorial', 'vertical_density' => 'spacious']),
        plan_section(['slug' => 'e', 'role' => 'closing', 'layout_archetype' => 'centered-stack', 'vertical_density' => 'spacious']),
    ]);

    assert_eq('standard', $sections[0]['vertical_density'], 'dense gallery demoted');
    assert_eq(['spacious', 'standard', 'spacious'], array_column(array_slice($sections, 1, 3), 'vertical_density'));
    assert_eq('standard', $sections[4]['vertical_density'], 'third pause is beyond the page cap');
    PagePlanStep::normalize($sections); // the result passes validation
});

test('PagePlanStep::repairVariety leaves valid densities and invalid enums alone', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'vertical_density' => 'enormous']),
        plan_section(['slug' => 'b', 'layout_archetype' => 'centered-stack', 'vertical_density' => 'spacious']),
        plan_section(['slug' => 'c', 'layout_archetype' => 'offset-grid', 'vertical_density' => 'compact']),
    ]);
    assert_eq(['enormous', 'spacious', 'compact'], array_column($sections, 'vertical_density'));
});

test('PagePlanStep::flattenPages walks the tree depth-first with paths and menu order', function () {
    $flat = PagePlanStep::flattenPages(plan_spec());

    assert_eq(['home', 'menu', 'breads'], array_column($flat, 'slug'));
    assert_eq('/', $flat[0]['path']);
    assert_eq(true, $flat[0]['front']);
    assert_eq(null, $flat[0]['parent']);
    assert_eq('/menu/', $flat[1]['path']);
    assert_eq(false, $flat[1]['front']);
    assert_eq('/menu/breads/', $flat[2]['path']);
    assert_eq('menu', $flat[2]['parent']);
    assert_eq([0, 10, 20], array_column($flat, 'menu_order'));
});

test('PagePlanStep::flattenPages paths front-page children under the front slug', function () {
    $flat = PagePlanStep::flattenPages(plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => [
            ['title' => 'Visit', 'slug' => 'visit', 'purpose' => 'Directions', 'children' => []],
        ]],
    ]]));

    assert_eq('/', $flat[0]['path']);
    // WordPress resolves the child's URI from its parent's post_name even
    // when the parent is the front page — advertising "/visit/" would 404.
    assert_eq('/home/visit/', $flat[1]['path']);
    assert_eq('home', $flat[1]['parent']);
});

test('PagePlanStep::flattenPages degrades a pageless spec to a single homepage', function () {
    $flat = PagePlanStep::flattenPages(['name' => 'Solo', 'description' => 'One thing.']);
    assert_eq(1, count($flat));
    assert_eq('home', $flat[0]['slug']);
    assert_eq(true, $flat[0]['front']);
    assert_eq('One thing.', $flat[0]['purpose']);
});

test('PagePlanStep::sitePagesList renders one line per page with path and front marker', function () {
    $list = PagePlanStep::sitePagesList(PagePlanStep::flattenPages(plan_spec()));
    assert_contains('"Home" — / (front page): Welcome', $list);
    assert_contains('"Menu" — /menu/: What we bake', $list);
    assert_contains('"Breads" — /menu/breads/: Bread list', $list);
});

test('page-plan fans out one request per page with per-page context', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppl_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['language' => 'es-AR']));
    $renderer = new PromptRenderer(repo_path('prompts'));

    $reqs = (new PagePlanStep(new FakeLlm(), $renderer))->requests($project);

    assert_eq(['home', 'menu', 'breads'], array_keys($reqs));
    assert_contains('in es-AR', $reqs['home']['prompt']);
    assert_contains('front page', $reqs['home']['prompt']);          // front emphasis
    assert_contains('interior page', $reqs['menu']['prompt']);       // interior emphasis
    assert_contains('"Menu"', $reqs['menu']['prompt']);              // its own title
    assert_contains('What we bake', $reqs['menu']['prompt']);        // its own purpose
    assert_contains('/menu/breads/', $reqs['menu']['prompt']);       // site pages list
    assert_contains('`type` is an open-ended semantic label, always in English', $reqs['home']['prompt']);
    assert_contains('"slug" and "type" are machine-facing identifiers and are ALWAYS plain English words', $reqs['home']['prompt']);
    assert_contains('builder derives each section\'s structural role', $reqs['home']['prompt']);
    assert_contains('examples:', $reqs['home']['prompt']);
    assert_contains('Never plan a footer or site-chrome section', $reqs['home']['prompt']);
    assert_contains('appends it after this page\'s LAST section', $reqs['home']['prompt']);
    assert_true(!str_contains($reqs['home']['prompt'], '"role"'), 'role is absent from the requested JSON shape');
    assert_true(!str_contains($reqs['home']['prompt'], '"type": "one of:'), 'semantic types are examples, not a closed list');

    $expectedSchema = ['name' => 'page_plan', 'schema' => PagePlanStep::jsonSchema()];
    foreach ($reqs as $req) {
        assert_eq($expectedSchema, $req['json_schema'] ?? null, 'every page request carries the same output schema');
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan writes pages.json with sections per page', function () {
    $tmp = sys_get_temp_dir() . '/builder_pp_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec());

    $llm = new FakeLlm();
    // One response per page, in flattened order: home, menu, breads.
    $llm->queueJson(['sections' => [
        plan_section(),
        plan_section(['slug' => 'cta', 'title' => 'CTA', 'role' => 'closing', 'type' => 'cta', 'layout_archetype' => 'centered-stack', 'background' => 'contrast', 'handoff' => 'Between the image hero above and the footer below.']),
    ]]);
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'menu-hero', 'title' => 'Menu Hero', 'layout_archetype' => 'centered-stack', 'background' => 'tinted', 'handoff' => 'Between the site header above and the bread list below.']),
        plan_section(['slug' => 'breads', 'title' => 'Breads', 'role' => 'closing', 'type' => 'bread-catalog', 'layout_archetype' => 'list-with-thumbnails', 'background' => 'base', 'handoff' => 'Between the tinted menu hero above and the footer below.']),
    ]]);
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'bread-list', 'title' => 'Bread List', 'type' => 'bread-catalog', 'layout_archetype' => 'list-with-thumbnails', 'background' => 'base', 'handoff' => 'Between the site header above and the footer below.']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('pages.json');
    assert_eq(3, count($plan['pages']));
    assert_eq('home', $plan['pages'][0]['slug']);
    assert_eq(true, $plan['pages'][0]['front']);
    assert_eq('hero', $plan['pages'][0]['sections'][0]['slug']);
    assert_eq('closing', $plan['pages'][0]['sections'][1]['role']);
    assert_eq('menu', $plan['pages'][1]['slug']);
    assert_eq('/menu/', $plan['pages'][1]['path']);
    assert_eq('menu-hero', $plan['pages'][1]['sections'][0]['slug']);
    assert_eq('bread-catalog', $plan['pages'][1]['sections'][1]['type']);
    assert_eq('menu', $plan['pages'][2]['parent']);
    assert_eq(20, $plan['pages'][2]['menu_order']);
    assert_eq(0, $llm->remaining(), 'every queued page response was consumed');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan removes a generated footer before recomputing variety and roles', function () {
    $tmp = sys_get_temp_dir() . '/builder_pp_footer_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    // Removing the footer makes the surviving centered sections adjacent, so
    // the filtered plan—not the original three-section sequence—must drive
    // the existing variety repair round.
    $llm->queueJson(['sections' => [
        plan_section([
            'slug'             => 'welcome',
            'layout_archetype' => 'centered-stack',
            'background'       => 'base',
        ]),
        plan_section([
            'slug'             => 'footer-info',
            'title'            => 'Footer Info',
            'type'             => 'site-footer',
            'layout_archetype' => 'offset-grid',
            'background'       => 'tinted',
        ]),
        plan_section([
            'slug'             => 'reserve',
            'title'            => 'Reserve',
            'type'             => 'cta',
            'layout_archetype' => 'centered-stack',
            'background'       => 'contrast',
        ]),
    ]]);
    $llm->queueJson(['sections' => [
        plan_section([
            'slug'             => 'welcome',
            'layout_archetype' => 'centered-stack',
            'background'       => 'base',
        ]),
        plan_section([
            'slug'             => 'reserve',
            'title'            => 'Reserve',
            'type'             => 'cta',
            'layout_archetype' => 'asymmetric-split',
            'background'       => 'contrast',
        ]),
    ]]);

    (new PagePlanStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(['welcome', 'reserve'], array_column($sections, 'slug'));
    assert_eq(['hero', 'closing'], array_column($sections, 'role'));
    assert_eq(
        ['centered-stack', 'asymmetric-split'],
        array_column($sections, 'layout_archetype'),
        'variety is validated against the surviving adjacency'
    );
    assert_eq(2, count($llm->calls), 'the filtered adjacency receives one semantic repair');
    assert_true(
        !str_contains($llm->calls[1]['prompt'], '"slug": "footer-info"'),
        'the repair prompt cannot ask the model to restore removed site chrome'
    );

    $warnings = $project->readJson('warnings.json')['page-plan'] ?? [];
    $joined = implode("\n", $warnings);
    assert_contains("pages[slug='home'].sections[1]", $joined);
    assert_contains('"type":"site-footer"', $joined);
    assert_contains('delivered=removed', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan substitutes a valid page when the generated footer was its only section', function () {
    $tmp = sys_get_temp_dir() . '/builder_pp_footer_only_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'site-footer', 'title' => 'Site Footer', 'type' => 'footer']),
    ]]);

    (new PagePlanStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(1, count($sections));
    assert_eq('content', $sections[0]['slug']);
    assert_eq('hero', $sections[0]['role']);
    PagePlanStep::normalize($sections);
    assert_eq(1, count($llm->calls), 'footer-only content is degraded deterministically, without another model call');

    $warnings = $project->readJson('warnings.json')['page-plan'] ?? [];
    $joined = implode("\n", $warnings);
    assert_contains('delivered=removed', $joined);
    assert_contains('delivered=one synthesized content section', $joined);
    assert_contains('cannot abort the build', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan stamps roles after a repair even when model role annotations stay wrong or missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppr_roles_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    // The initial plan needs a semantic repair for its duplicate archetype.
    // Its role annotations are deliberately wrong and must not create another
    // validation failure.
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'welcome', 'role' => 'closing', 'layout_archetype' => 'centered-stack', 'background' => 'base']),
        plan_section(['slug' => 'story', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'tinted']),
        plan_section(['slug' => 'visit', 'role' => 'content', 'layout_archetype' => 'asymmetric-split', 'background' => 'contrast']),
    ]]);
    // The repaired plan fixes the actual art-direction error, but repeats bad
    // role annotations and omits one. Normalization must stamp all positions.
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'welcome', 'role' => 'closing']),
        plan_section(['slug' => 'story', 'layout_archetype' => 'offset-grid', 'background' => 'base']),
        plan_section(['slug' => 'visit', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);

    (new PagePlanStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(2, count($llm->calls), 'one initial request plus one repair');
    assert_eq(['hero', 'content', 'closing'], array_column($sections, 'role'));
    assert_true(
        !str_contains($llm->calls[1]['prompt'], 'must have role'),
        'model role annotations never become repair errors',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan repairs only the invalid page with one follow-up call', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppr_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
        ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'What we bake', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    // home plan is valid…
    $llm->queueJson(['sections' => [plan_section()]]);
    // …menu plan violates the adjacency rule…
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'a', 'layout_archetype' => 'centered-stack', 'background' => 'base']),
        plan_section(['slug' => 'b', 'role' => 'closing', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);
    // …and the repair call returns a fixed menu plan (compact opening — menu
    // is an interior page, so a full-bleed cover would be re-rejected).
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'a', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image']),
        plan_section(['slug' => 'b', 'role' => 'closing', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('pages.json');
    assert_eq('asymmetric-split', $plan['pages'][1]['sections'][0]['layout_archetype']);
    // Batch (2 calls) + one repair; the repair prompt carries the rejected
    // plan and the specific error, labeled per page in the LLM logs.
    assert_eq(3, count($llm->calls));
    $repairPrompt = $llm->calls[2]['prompt'];
    assert_contains('IT WAS REJECTED', $repairPrompt);
    assert_contains('adjacent sections', $repairPrompt);
    assert_contains('change only ONE of the two sections', $repairPrompt);
    assert_contains('also update its content_notes', $repairPrompt);
    assert_eq('page-plan-menu-repair', $llm->calls[2]['opts']['log_label'] ?? null);
    assert_eq(
        ['name' => 'page_plan', 'schema' => PagePlanStep::jsonSchema()],
        $llm->calls[2]['opts']['json_schema'] ?? null,
        'the semantic repair call remains schema-constrained',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan repairs every invalid page in ONE batched round', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppb_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
        ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'What we bake', 'children' => []],
        ['title' => 'About', 'slug' => 'about', 'purpose' => 'Who we are', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    // home is valid; menu and about each break the adjacency rule, so two of
    // the three pages need a repair.
    $llm->queueJson(['sections' => [plan_section()]]);
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'm1', 'layout_archetype' => 'centered-stack', 'background' => 'base']),
        plan_section(['slug' => 'm2', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'a1', 'layout_archetype' => 'offset-grid', 'background' => 'base']),
        plan_section(['slug' => 'a2', 'layout_archetype' => 'offset-grid', 'background' => 'contrast']),
    ]]);
    // Both repairs come back fixed, in page order.
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'm1', 'layout_archetype' => 'centered-stack', 'background' => 'base']),
        plan_section(['slug' => 'm2', 'layout_archetype' => 'asymmetric-split', 'background' => 'contrast']),
    ]]);
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'a1', 'layout_archetype' => 'offset-grid', 'background' => 'base']),
        plan_section(['slug' => 'a2', 'layout_archetype' => 'mixed-width-editorial', 'background' => 'contrast']),
    ]]);

    (new PagePlanStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $pages = $project->readJson('pages.json')['pages'];
    assert_eq('asymmetric-split', $pages[1]['sections'][1]['layout_archetype']);
    assert_eq('mixed-width-editorial', $pages[2]['sections'][1]['layout_archetype']);

    // The whole point: two failing pages cost ONE extra round-trip, not two.
    assert_eq(2, $llm->completeJsonBatchCalls, 'the initial fan-out plus ONE batched repair round');
    assert_eq(0, $llm->completeJsonCalls, 'repairs never go out one page at a time');

    // Each repair keeps its own per-page label and schema inside the batch.
    assert_eq(5, count($llm->calls));
    assert_eq('page-plan-menu-repair', $llm->calls[3]['opts']['log_label'] ?? null);
    assert_eq('page-plan-about-repair', $llm->calls[4]['opts']['log_label'] ?? null);
    assert_contains('IT WAS REJECTED', $llm->calls[3]['prompt']);
    assert_contains('IT WAS REJECTED', $llm->calls[4]['prompt']);
    assert_eq(0, $llm->remaining(), 'fan-out and repair rounds consumed the whole queue');
    assert_eq(
        ['name' => 'page_plan', 'schema' => PagePlanStep::jsonSchema()],
        $llm->calls[4]['opts']['json_schema'] ?? null,
        'every batched repair stays schema-constrained',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan falls back to a mechanical fix when the repair still breaks a variety rule', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppv_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A portfolio']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    // The plan has adjacent duplicates…
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'credibility-block', 'layout_archetype' => 'centered-stack', 'background' => 'base']),
        plan_section(['slug' => 'closing-cta', 'role' => 'closing', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);
    // …and the repair fumbles it by moving BOTH sections to the same new archetype.
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'credibility-block', 'layout_archetype' => 'asymmetric-split', 'background' => 'base']),
        plan_section(['slug' => 'closing-cta', 'role' => 'closing', 'layout_archetype' => 'asymmetric-split', 'background' => 'contrast']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer))->run($project);

    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(2, count($llm->calls), 'no second LLM repair — the fallback is mechanical');
    assert_eq('asymmetric-split', $sections[0]['layout_archetype'], 'first of the pair kept');
    assert_true($sections[1]['layout_archetype'] !== 'asymmetric-split', 'later section reassigned');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan enforces the compact interior opening through repair and mechanical fallback', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A tavern']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
        ['title' => 'Visit', 'slug' => 'visit', 'purpose' => 'Find us', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    // Home may open with a cover…
    $llm->queueJson(['sections' => [plan_section()]]);
    // …the interior page may not…
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'visit-hero']),
        plan_section(['slug' => 'directions', 'role' => 'closing', 'layout_archetype' => 'asymmetric-split', 'background' => 'base']),
    ]]);
    // …and the repair insists on the cover, so the mechanical fallback demotes it.
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'visit-hero']),
        plan_section(['slug' => 'directions', 'role' => 'closing', 'layout_archetype' => 'asymmetric-split', 'background' => 'base']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('pages.json');
    assert_eq('full-bleed-cover', $plan['pages'][0]['sections'][0]['layout_archetype'], 'front page keeps its cover');
    assert_true(
        $plan['pages'][1]['sections'][0]['layout_archetype'] !== 'full-bleed-cover',
        'interior opening demoted to a compact archetype'
    );
    assert_eq(3, count($llm->calls));
    assert_contains('INTERIOR page', $llm->calls[2]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan coerces a field the repair round could not fix, instead of aborting', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppr2_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));

    // The model repeats the same invalid enum in its repair. That used to end
    // the build; the deterministic pass now answers it and the build ships.
    $bad = ['sections' => [plan_section(['background' => 'plaid'])]];
    $llm = new FakeLlm();
    $llm->queueJson($bad);
    $llm->queueJson($bad);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('pages.json');
    assert_eq('base', $plan['pages'][0]['sections'][0]['background']);
    // The repair round still ran: coercion is the backstop, not the first move.
    assert_eq(2, count($llm->calls));
    // A changed delivered value is recorded durably, not just narrated.
    $warnings = $project->readJson('warnings.json');
    assert_contains('plaid', implode("\n", $warnings['page-plan']));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan repairs an empty page plan and falls back when the repair is empty too', function () {
    $tmp = sys_get_temp_dir() . '/builder_pp0_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    $llm->queueJson(['sections' => []]);
    $llm->queueJson(['sections' => []]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer))->run($project);

    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(1, count($sections));
    assert_eq('content', $sections[0]['slug']);
    assert_eq('hero', $sections[0]['role']);
    // The empty plan went through the repair path before degrading.
    assert_eq(2, count($llm->calls));
    assert_contains('has no sections', $llm->calls[1]['prompt']);
    $joined = implode("\n", $project->readJson('warnings.json')['page-plan'] ?? []);
    assert_contains('authored=empty repaired sections array', $joined);
    assert_contains('delivered=one synthesized content section', $joined);
    assert_contains('disposition=', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan generated JSON fallback preserves valid page siblings', function () {
    $tmp = sys_get_temp_dir() . '/builder_pp_json_fallback_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
        ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'Breads', 'children' => []],
    ]]));
    $step = new PagePlanStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $step->consumeGeneratedJsonFailure(
        $project,
        ['home' => ['sections' => [
            plan_section(['slug' => 'welcome', 'title' => 'Welcome']),
        ]]],
        ['menu' => 'syntax error after bounded JSON repair'],
    );

    $pages = $project->readJson('pages.json')['pages'];
    assert_eq(['welcome'], array_column($pages[0]['sections'], 'slug'), 'valid sibling stays authored');
    assert_eq(['content'], array_column($pages[1]['sections'], 'slug'), 'failed sibling gets one fallback');
    assert_eq('centered-stack', $pages[1]['sections'][0]['layout_archetype'], 'interior fallback stays compact');
    $joined = implode("\n", $project->readJson('warnings.json')['page-plan'] ?? []);
    assert_contains("pages[slug='menu'].sections", $joined);
    assert_contains('syntax error after bounded JSON repair', $joined);
    assert_contains('delivered=one synthesized content section', $joined);
    assert_contains('disposition=', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan degrades when a rejected plan receives unusable repair JSON', function () {
    $tmp = sys_get_temp_dir() . '/builder_pp_repair_json_fallback_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));
    $llm = new class implements Llm {
        public function complete(string $prompt, array $opts = []): string
        {
            throw new RuntimeException('unused');
        }

        public function completeJson(string $prompt, array $opts = []): array
        {
            throw new RuntimeException('unused');
        }

        public function completeJsonBatch(array $requests): array
        {
            throw new GeneratedJsonException([
                'home' => 'repair response remained truncated',
            ]);
        }

        public function completeBatch(array $requests): \Automattic\SiteBuild\TextBatchResult
        {
            throw new RuntimeException('unused');
        }
    };
    $step = new PagePlanStep($llm, new PromptRenderer(repo_path('prompts')));

    $step->consume($project, ['home' => ['sections' => [
        plan_section(['background' => 'plaid']),
    ]]]);

    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(['content'], array_column($sections, 'slug'));
    $joined = implode("\n", $project->readJson('warnings.json')['page-plan'] ?? []);
    assert_contains('authored=unusable generated repair JSON', $joined);
    assert_contains('repair response remained truncated', $joined);
    assert_contains('delivered=one synthesized content section', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('PagePlanStep::recoverSections coerces a mis-filled field through the mechanical backstop', function () {
    $warnings = [];
    $out = PagePlanStep::recoverSections([
        plan_section(['slug' => 'hero', 'background' => 'plaid']),
    ], true, $warnings, 'home');

    assert_eq('base', $out[0]['background']);
    assert_contains('plaid', $warnings[0]);
    // Survives the validator that rejected the repair round.
    PagePlanStep::normalize($out, true);
});

test('PagePlanStep::recoverSections returns empty for an empty plan', function () {
    $warnings = [];
    assert_eq([], PagePlanStep::recoverSections([], true, $warnings));
    assert_eq([], PagePlanStep::recoverSections(null, true, $warnings));
    assert_eq([], $warnings, 'empty input is not a coercion');
});

test('PagePlanStep::acceptRepairedSections degrades residual validation instead of throwing', function () {
    // Simulate a future normalize rule the field/variety passes missed: hand
    // an unrepaired invalid list straight to the accept step. The build must
    // keep a page, not abort.
    $warnings = [];
    $out = PagePlanStep::acceptRepairedSections([
        plan_section(['slug' => 'broken', 'layout_archetype' => 'not-a-real-archetype']),
    ], true, $warnings, 'home');

    assert_eq(1, count($out));
    assert_eq('content', $out[0]['slug']);
    assert_eq('full-bleed-cover', $out[0]['layout_archetype']);
    assert_contains('residual validation', $warnings[0]);
    assert_contains("page 'home'", $warnings[0]);
    assert_contains('not-a-real-archetype', $warnings[0]);
    PagePlanStep::normalize($out, true);
});

test('PagePlanStep::fallbackSections is a minimal plan normalize accepts', function () {
    $front = PagePlanStep::fallbackSections(true);
    assert_eq(1, count($front));
    assert_eq('full-bleed-cover', $front[0]['layout_archetype']);
    PagePlanStep::normalize($front, true);

    $interior = PagePlanStep::fallbackSections(false);
    assert_eq(1, count($interior));
    assert_eq('centered-stack', $interior[0]['layout_archetype']);
    PagePlanStep::normalize($interior, false);
});

test('PagePlanStep::repairFields coerces a cross-wired enum instead of rejecting', function () {
    // 'contrast' is a valid background, so the model emitting it as a
    // layout_archetype is the commonest field slip — and the one that used to
    // end a build outright when the repair round reproduced it.
    $warnings = [];
    $out = PagePlanStep::repairFields([
        plan_section(['slug' => 'hero']),
        plan_section(['slug' => 'before-after-preview', 'layout_archetype' => 'contrast']),
    ], $warnings);

    assert_contains('contrast', $warnings[0]);
    assert_eq(true, in_array($out[1]['layout_archetype'], PagePlanStep::ARCHETYPES, true));
    // Never lands on the two archetypes that carry their own rules.
    assert_eq(false, in_array($out[1]['layout_archetype'], ['full-bleed-cover', 'equal-card-grid'], true));
    // And the coerced value survives the validator it used to fail.
    PagePlanStep::normalize($out, true);
});

test('PagePlanStep::repairFields fills every other field-level rejection', function () {
    $warnings = [];
    $out = PagePlanStep::repairFields([
        plan_section(['slug' => 'hero', 'title' => 'Hero']),
        plan_section([
            'slug'             => 'gap',
            'title'            => 'Gap',
            'background'       => 'plaid',
            'vertical_density' => 'roomy',
            'type'             => '',
            'handoff'          => '',
        ]),
        plan_section(['slug' => 'closer', 'title' => 'Closer']),
    ], $warnings);

    assert_eq('base', $out[1]['background']);
    assert_eq('standard', $out[1]['vertical_density']);
    assert_eq('content', $out[1]['type']);
    // The synthesized handoff names the real neighbours, not a placeholder.
    assert_contains('Hero', $out[1]['handoff']);
    assert_contains('Closer', $out[1]['handoff']);
    assert_eq(4, count($warnings));
});

test('PagePlanStep::repairFields hands repairVariety no new work', function () {
    // A coerced archetype must not collide with either neighbour, or the
    // variety pass would have to undo it.
    $warnings = [];
    $sections = [];
    foreach (['a', 'b', 'c', 'd', 'e'] as $i => $slug) {
        $sections[] = plan_section([
            'slug'             => $slug,
            'layout_archetype' => $i % 2 === 0 ? 'nonsense' : 'centered-stack',
        ]);
    }
    $out = PagePlanStep::repairFields($sections, $warnings);

    $archetypes = array_column($out, 'layout_archetype');
    foreach ($archetypes as $i => $archetype) {
        assert_eq(true, in_array($archetype, PagePlanStep::ARCHETYPES, true));
        if ($i > 0) {
            assert_eq(false, $archetype === $archetypes[$i - 1]);
        }
    }
    // Interior page: the opening section must not have become a cover.
    assert_eq(false, $archetypes[0] === 'full-bleed-cover');
    PagePlanStep::normalize(PagePlanStep::repairVariety($out, false), false);
});
