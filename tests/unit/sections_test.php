<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/**
 * Unit tests for SectionsStep: it fires one request per part (header, footer,
 * each PAGE's sections), validates the markup, and writes the part files.
 */

/** One page entry for a pages.json fixture. */
function sections_page(string $slug, array $sections, array $overrides = []): array
{
    static $order = ['home' => 0, 'menu' => 10];
    $sections = array_map(static function (mixed $section): mixed {
        if (is_array($section) && !array_key_exists('primary_action', $section)) {
            $section['primary_action'] = null;
        }
        return $section;
    }, $sections);
    return array_merge([
        'slug'       => $slug,
        'title'      => ucwords($slug),
        'path'       => $slug === 'home' ? '/' : "/{$slug}/",
        'front'      => $slug === 'home',
        'parent'     => null,
        'menu_order' => $order[$slug] ?? 0,
        'purpose'    => 'About ' . $slug,
        'sections'   => $sections,
    ], $overrides);
}

function sections_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_sec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', [
        'version' => 3,
        'settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => '#274c77'],
            ['slug' => 'secondary', 'name' => 'Secondary', 'color' => '#e5e7eb'],
            ['slug' => 'accent', 'name' => 'Accent', 'color' => '#9a3412'],
        ]]],
    ]);
    $project->writeJson('designDirection.json', [
        'description' => 'A clear, confident direction.',
        'canvas' => 'full-bleed',
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ]);
    $project->writeJson('pages.json', ['pages' => [
        sections_page('home', [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the base about split below.'],
            ['slug' => 'about', 'title' => 'About', 'role' => 'closing', 'type' => 'founder-letter', 'layout_archetype' => 'asymmetric-split', 'background' => 'base', 'vertical_density' => 'standard', 'handoff' => 'Between the image hero above and the footer below.'],
        ]),
    ]]);
    return [$project, $tmp];
}

/** Reconstruct the logical prompt text from a possibly layered request. */
function sections_request_text(array $request): string
{
    return implode('', $request['cached_prefixes'] ?? []) . $request['prompt'];
}

test('sections requests one part per header/footer/page-section', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_eq(['header', 'footer', 'page-home--hero', 'page-home--about'], array_keys($reqs));
    assert_true(!array_key_exists('cached_prefixes', $reqs['header']), 'header is not cached');
    assert_true(!array_key_exists('cached_prefixes', $reqs['footer']), 'footer is not cached');
    assert_true(!array_key_exists('cached_prefixes', $reqs['page-home--hero']), 'the dedicated hero request is not cached');
    assert_eq(2, count($reqs['page-home--about']['cached_prefixes'] ?? []), 'ordinary sections retain both cache layers');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections fans out across every page and gives each section its own page context', function () {
    [$project, $tmp] = sections_fixture();
    $project->writeJson('pages.json', ['pages' => [
        sections_page('home', [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the footer below.'],
            ['slug' => 'home-details', 'title' => 'Home Details', 'role' => 'closing', 'type' => 'details', 'layout_archetype' => 'centered-stack', 'background' => 'base', 'vertical_density' => 'standard', 'handoff' => 'Between the hero above and the footer below.'],
        ]),
        sections_page('menu', [
            ['slug' => 'menu-hero', 'title' => 'Menu Hero', 'role' => 'hero', 'type' => 'menu-introduction', 'layout_archetype' => 'centered-stack', 'background' => 'tinted', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the bread list below.'],
            ['slug' => 'breads', 'title' => 'Breads', 'role' => 'closing', 'type' => 'bread-catalog', 'layout_archetype' => 'list-with-thumbnails', 'background' => 'base', 'vertical_density' => 'standard', 'handoff' => 'Between the tinted hero above and the footer below.'],
        ], ['purpose' => 'What we bake']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_eq(
        ['header', 'footer', 'page-home--hero', 'page-home--home-details', 'page-menu--menu-hero', 'page-menu--breads'],
        array_keys($reqs)
    );

    assert_true(!array_key_exists('cached_prefixes', $reqs['page-home--hero']));
    $homePrefixes = $reqs['page-home--home-details']['cached_prefixes'] ?? [];
    $menuPrefixes = $reqs['page-menu--menu-hero']['cached_prefixes'] ?? [];
    assert_eq($homePrefixes[0] ?? null, $menuPrefixes[0] ?? null, 'build layer is byte-identical across pages');
    assert_true(($homePrefixes[1] ?? null) !== ($menuPrefixes[1] ?? null), 'page layer differs across pages');

    // Each section sees ITS page's outline, not another page's.
    $menuBreads = sections_request_text($reqs['page-menu--breads']);
    assert_contains('1. Menu Hero (menu-introduction)', $menuBreads);
    assert_true(!str_contains($menuBreads, '1. Hero (hero)'), 'menu section not given the home outline');
    assert_contains('"Menu"', $menuBreads);

    // Every part knows the whole site's page list (for internal links / nav).
    assert_contains('/menu/', sections_request_text($reqs['page-home--hero']));
    assert_contains('/menu/', $reqs['header']['prompt']);
    assert_contains('/menu/', $reqs['footer']['prompt']);

    // The header is briefed on the FRONT page's hero and outline.
    assert_contains('1. Hero (hero)', $reqs['header']['prompt']);

    // Role and free-form semantic type remain distinct in each section brief.
    assert_true((bool) preg_match('/Role:\s+closing/', $reqs['page-menu--breads']['prompt']));
    assert_true((bool) preg_match('/Type:\s+bread-catalog/', $reqs['page-menu--breads']['prompt']));
    assert_contains('GLOBAL HEADER CONTRACT (this page opening only):', sections_request_text($reqs['page-menu--menu-hero']));
    assert_true(
        !str_contains(sections_request_text($reqs['page-menu--breads']), 'GLOBAL HEADER CONTRACT (this page opening only):'),
        'only the interior page opening receives the header-facing contract',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections passes the design direction and the front-page edge briefs to chrome prompts', function () {
    [$project, $tmp] = sections_fixture();
    $project->writeJson('designDirection.json', [
        'title'       => 'Archivo Silencioso',
        'description' => 'Full-bleed black-and-white photography, chrome-less.',
        'canvas' => 'full-bleed',
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ]);
    $project->writeJson('pages.json', ['pages' => [
        sections_page('home', [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'immersive-introduction', 'purpose' => 'Immerse the visitor', 'content_notes' => 'Full-viewport cover photo.', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the header and about section.'],
            ['slug' => 'about', 'title' => 'About', 'role' => 'closing', 'type' => 'about', 'layout_archetype' => 'centered-stack', 'background' => 'base', 'vertical_density' => 'spacious', 'handoff' => 'Between the hero and footer.'],
        ]),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_contains('Archivo Silencioso', $reqs['header']['prompt']);
    assert_contains('Archivo Silencioso', $reqs['footer']['prompt']);
    assert_contains('Full-viewport cover photo.', $reqs['header']['prompt']);
    assert_contains('Title: About', $reqs['footer']['prompt']);
    assert_contains('Role: closing', $reqs['footer']['prompt']);
    assert_contains('Layout archetype: centered-stack', $reqs['footer']['prompt']);
    assert_contains('Background: base', $reqs['footer']['prompt']);
    assert_true(
        !str_contains($reqs['footer']['prompt'], 'Full-viewport cover photo.'),
        'the footer receives the final section, not the hero brief'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('footer composition is stable, varied, and shared with the closing-section handoff', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $step = new SectionsStep(new FakeLlm(), $renderer);
    $reqs = $step->requests($project);
    $footer = $reqs['footer']['prompt'];

    assert_true(
        (bool) preg_match(
            '/ASSIGNED FOOTER COMPOSITION for this build: \*\*([a-z-]+)\*\*/',
            $footer,
            $match,
        ),
        'footer prompt carries one assigned composition'
    );
    $archetype = $match[1];
    assert_true(in_array($archetype, SectionsStep::FOOTER_ARCHETYPES, true));
    $surface = FooterComposition::surface($archetype);
    assert_contains("ASSIGNED FOOTER SURFACE: **{$surface}**", $footer);
    $closingPrompt = sections_request_text($reqs['page-home--about']);
    assert_contains(
        "Below: the site footer (this is the last section) — assigned {$archetype} composition opening on the exact **{$surface}** background surface",
        $closingPrompt
    );
    assert_contains('This section owns its planned narrative, facts, imagery, and primary CTA', $closingPrompt);
    assert_contains('otherwise make one decisive color or image cut', $closingPrompt);

    $pages = $project->readJson('pages.json')['pages'];
    $siteSpec = $project->readText('siteSpec.json');
    $direction = \Automattic\SiteBuild\Steps\DesignDirectionStep::readFor($project);
    assert_eq(
        SectionsStep::footerArchetype($pages, $siteSpec, $direction),
        SectionsStep::footerArchetype($pages, $siteSpec, $direction),
        'identical build context always selects the same composition'
    );

    $picks = [];
    foreach (range(1, 30) as $n) {
        $pick = SectionsStep::footerArchetype($pages, "{\"name\":\"Site {$n}\"}", "Direction {$n}");
        $picks[$pick] = true;
    }
    assert_true(count($picks) >= 4, 'different site identities spread across the footer catalog');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('footer prompt renders only its selected high-impact recipe without overriding signature placement', function () {
    [$project, $tmp] = sections_fixture();
    $project->writeJson('designDirection.json', [
        'title' => 'Restricted device',
        'description' => 'A graphic editorial system.',
        'signature_device' => 'Use the stepped accent only in the hero and nowhere else.',
        'canvas' => 'full-bleed',
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ]);
    $reqs = (new SectionsStep(new FakeLlm(), new PromptRenderer(repo_path('prompts'))))->requests($project);
    $footer = $reqs['footer']['prompt'];

    assert_true(
        (bool) preg_match(
            '/ASSIGNED FOOTER COMPOSITION for this build: \*\*([a-z-]+)\*\*/',
            $footer,
            $match,
        ),
        'footer prompt carries one assigned composition'
    );
    $archetype = $match[1];
    $recipeMarkers = [
        'typographic-billboard' => 'ONE viewport-filling brand line',
        'photographic-split' => 'deliberately unequal 60/40 or 65/35',
        'image-plinth' => 'Treat ONE foreground wp:image as the focal object',
        'conversion-panel' => 'Build a bold, offset invitation',
        'editorial-colophon' => 'final plate of a book or',
        'split-ledger' => 'Build a strong 65/35 or 70/30 split',
    ];
    assert_contains($recipeMarkers[$archetype], $footer);
    foreach ($recipeMarkers as $otherArchetype => $marker) {
        if ($otherArchetype !== $archetype) {
            assert_true(!str_contains($footer, $marker), "recipe for {$otherArchetype} stays out of the prompt");
        }
    }
    assert_contains('ONE dominant focal gesture and low content density', $footer);
    assert_contains('FIT-TEXT IDENTITY LINE', $footer);
    assert_contains('"fitText":true', $footer);
    assert_contains('signature-device PLACEMENT restrictions are binding', $footer);
    assert_contains('ONLY when the direction explicitly makes it site-wide', $footer);
    assert_contains('NEVER set `"tagName":"footer"`', $footer);
    assert_contains('External/social links use only an exact URL present in the SITE SPEC', $footer);
    assert_contains('NEVER invent `is-style-none`, `is-style-plain`', $footer);
    assert_eq(
        FooterComposition::usesGeneratedImage($archetype),
        str_contains($footer, 'AI_IMAGE: subject | page-context | style | aspect-ratio'),
        'only image-led recipes receive image-generation instructions'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections wires the spec language into every part prompt', function () {
    [$project, $tmp] = sections_fixture();
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'language' => 'es-AR']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    foreach (['header', 'footer', 'page-home--hero', 'page-home--about'] as $key) {
        assert_contains('in es-AR', sections_request_text($reqs[$key]));
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections falls back to a descriptive language phrase for specs without one', function () {
    [$project, $tmp] = sections_fixture(); // fixture spec has no "language"
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_contains('in the language the user prompt is written in', sections_request_text($reqs['page-home--hero']));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections routes the front hero to one recipe and keeps generic composition on ordinary sections', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    $hero = sections_request_text($reqs['page-home--hero']);
    assert_true(
        (bool) preg_match('/ASSIGNED HERO COMPOSITION for this build: \*\*([a-z-]+)\*\*/', $hero, $match),
        'front hero receives one code-owned recipe assignment',
    );
    assert_contains('hero-composition--' . $match[1], $hero);
    assert_contains('NORMALIZED HERO BLUEPRINT', $hero);
    assert_true(!str_contains($hero, 'Execute the assigned layout archetype:'), 'generic section topology stays out of HeroUnit');
    assert_contains('Above: the site header (this is the first section)', $hero);
    assert_contains('Below: "About" — asymmetric-split on base background, standard vertical density', $hero);

    $about = sections_request_text($reqs['page-home--about']);
    assert_true((bool) preg_match('/Layout archetype:\s+asymmetric-split/', $about), 'about archetype in prompt');
    assert_contains('Above: "Hero" — full-bleed-cover on image background, standard vertical density', $about);
    assert_contains('Below: the site footer (this is the last section)', $about);

    // The shared outline carries the whole page's rhythm to every part.
    assert_contains('1. Hero (hero) — full-bleed-cover on image background, standard vertical density', $reqs['header']['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('outline and neighbors tolerate a plan without art-direction fields', function () {
    $sections = [
        ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero'],
        ['slug' => 'about', 'title' => 'About', 'role' => 'closing', 'type' => 'about'],
    ];
    assert_eq("1. Hero (hero) [#hero]\n2. About (about) [#about]", SectionsStep::outline($sections));
    assert_eq("Above: \"Hero\"\nBelow: the site footer (this is the last section)", SectionsStep::neighbors($sections, 1));
});

test('sections throws when a planned section is missing composition fields', function () {
    $tmp = sys_get_temp_dir() . '/builder_sec_old_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('pages.json', ['pages' => [
        sections_page('home', [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the header and the next section.'],
            ['slug' => 'about', 'title' => 'About', 'role' => 'closing', 'type' => 'about'],
        ]),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($renderer, $project) {
        (new SectionsStep(new FakeLlm(), $renderer))->requests($project);
    }, 'missing layout_archetype');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections repairs a missing structural role or semantic type deterministically', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $pages = $project->readJson('pages.json');

    // The role is a pure function of position, and the type has a safe
    // generic default — both are corrected in place, not rejected.
    unset($pages['pages'][0]['sections'][0]['role']);
    unset($pages['pages'][0]['sections'][0]['type']);
    $project->writeJson('pages.json', $pages);
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    $hero = sections_request_text($reqs['page-home--hero']);
    assert_contains('hero', $hero, 'the positional role reaches the prompt');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections persists the deterministic plan repairs back into pages.json', function () {
    [$project, $tmp] = sections_fixture();
    $pages = $project->readJson('pages.json');
    unset($pages['pages'][0]['sections'][0]['role']);
    unset($pages['pages'][0]['sections'][0]['type']);
    $project->writeJson('pages.json', $pages);

    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // header
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // footer
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    // The plan artifact on disk carries the corrected role/type the parts
    // were generated from. These semantics-preserving corrections belong in
    // the step report, never warnings.json.
    $hero = $project->readJson('pages.json')['pages'][0]['sections'][0];
    assert_eq('hero', $hero['role']);
    assert_eq('content', $hero['type']);
    $warnings = $project->exists('warnings.json')
        ? implode(' ', $project->readJson('warnings.json')['sections'] ?? [])
        : '';
    assert_true(!str_contains($warnings, "role '' corrected to 'hero'"));
    assert_true(!str_contains($warnings, 'missing semantic type'));
    $report = $project->readText('logs/sections.txt');
    assert_contains("role '' corrected to 'hero'", $report);
    assert_contains("missing semantic type; defaulted to 'content'", $report);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections degrades a permanent generation batch failure at each unit boundary', function () {
    [$project, $tmp] = sections_fixture();
    $pages = $project->readJson('pages.json');
    unset($pages['pages'][0]['sections'][0]['role']);
    unset($pages['pages'][0]['sections'][0]['type']);
    $project->writeJson('pages.json', $pages);
    // No queued responses: cache warming degrades, then the all-or-nothing
    // raw-text batch throws. Contract-critical units fall back at their
    // planned keys and the ordinary sibling is the smallest removable unit.
    $llm = new FakeLlm();
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SectionsStep($llm, $renderer))->run($project);

    assert_true($project->exists('theme/parts/header.html'));
    assert_true($project->exists('theme/parts/footer.html'));
    assert_true($project->exists('theme/parts/page-home--hero.html'));
    assert_true(!$project->exists('theme/parts/page-home--about.html'));
    $delivered = $project->readJson('pages.json')['pages'];
    assert_eq(['hero'], array_column($delivered[0]['sections'], 'slug'));
    assert_eq('delivery', $project->readJson('aboveFold.json')['phase']);
    $warnings = implode("\n", $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains('generation batch failed', $warnings);
    assert_contains('header fallback', $warnings);
    assert_contains('topology-family fallback', $warnings);
    assert_contains("part 'page-home--about'", $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('heroBrief uses the positional front opening that routes to HeroUnit', function () {
    $brief = SectionsStep::heroBrief([
        ['slug' => 'intro', 'title' => 'Opening', 'role' => 'content', 'type' => 'opening-note', 'purpose' => 'Orient visitors.', 'content_notes' => 'Lead clearly.'],
        ['slug' => 'stale', 'title' => 'Stale Hero', 'role' => 'hero', 'type' => 'hero'],
    ]);
    assert_contains('Title: Opening', $brief);
    assert_contains('Role: content', $brief);
    assert_contains('Type: opening-note', $brief);
    assert_contains('Purpose: Orient visitors.', $brief);
    assert_contains('Notes: Lead clearly.', $brief);
    assert_true(!str_contains($brief, 'Stale Hero'), 'a later semantic role cannot override positional routing');

    $brief = SectionsStep::heroBrief([['slug' => 'intro', 'title' => 'Intro', 'role' => 'content', 'type' => 'opening-note']]);
    assert_contains('Title: Intro', $brief);

    assert_eq('(No hero section planned.)', SectionsStep::heroBrief([]));
});

test('finalSectionBrief describes the positional footer neighbor', function () {
    $brief = SectionsStep::finalSectionBrief([
        ['title' => 'Hero', 'role' => 'hero', 'type' => 'welcome', 'content_notes' => 'Opening image.'],
        [
            'title' => 'Reserve',
            'role' => 'closing',
            'type' => 'reservation',
            'purpose' => 'Turn interest into a booking.',
            'content_notes' => 'Show the canonical hours and one booking action.',
            'layout_archetype' => 'asymmetric-split',
            'background' => 'contrast',
            'vertical_density' => 'spacious',
            'handoff' => 'Contrast band meets the footer.',
        ],
    ]);
    foreach (
        [
            'Title: Reserve',
            'Role: closing',
            'Type: reservation',
            'Purpose: Turn interest into a booking.',
            'Notes: Show the canonical hours and one booking action.',
            'Layout archetype: asymmetric-split',
            'Background: contrast',
            'Vertical density: spacious',
            'Planned handoff: Contrast band meets the footer.',
        ] as $expected
    ) {
        assert_contains($expected, $brief);
    }
    assert_true(!str_contains($brief, 'Opening image.'), 'only the section directly above the footer is described');
    assert_eq('(No final section planned.)', SectionsStep::finalSectionBrief([]));
});

test('a singleton hero gives the footer its exact primary-action ownership facts', function () {
    $brief = SectionsStep::finalSectionBrief([[
        'title' => 'Welcome',
        'role' => 'hero',
        'type' => 'welcome',
        'primary_action' => [
            'label' => 'View the menu',
            'intent' => 'Help visitors explore the current menu.',
            'destination' => '/menu/',
        ],
    ]]);

    assert_contains('Primary action label (authoritative): View the menu', $brief);
    assert_contains('Primary action destination: /menu/', $brief);
    assert_contains('Primary action intent (planning context, never button copy): Help visitors explore the current menu.', $brief);
});

test('the canonical front contract reaches only the dedicated hero request', function () {
    [$project, $tmp] = sections_fixture(); // front hero is image-led → overlay mode
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);
    $hero = sections_request_text($reqs['page-home--hero']);
    $about = sections_request_text($reqs['page-home--about']);
    assert_contains('AUTHORITATIVE ABOVE-FOLD CONTRACT', $hero, 'the front opening receives the canonical contract');
    assert_contains('"mode": "overlay"', $hero, 'the contract carries the computed mode exactly');
    assert_true(
        !str_contains($about, 'ABOVE-FOLD CONTRACT (authoritative; front page only):'),
        'non-opening sections share no viewport with the header'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('an overlay-planned site whose palette fails the scrim check is briefed as stacked', function () {
    putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV); // a forced archetype would bypass the contract pool
    [$project, $tmp] = sections_fixture(); // front hero is image-led → the PLAN alone says overlay
    // Every palette role is a mid grey: the contract's token verification
    // cannot prove a readable overlay foreground, so the resolved MODE
    // diverges from the structural plan and both seams brief stacked.
    $project->writeJson('theme/theme.json', [
        'version' => 3,
        'settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'name' => 'Base', 'color' => '#8A8A8A'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#7A7A7A'],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => '#6E6E6E'],
            ['slug' => 'secondary', 'name' => 'Secondary', 'color' => '#9A9A9A'],
            ['slug' => 'accent', 'name' => 'Accent', 'color' => '#808080'],
        ]]],
    ]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    $header = $reqs['header']['prompt'];
    assert_contains('"mode": "stacked"', $header, 'the unreadable palette downgrades the briefed relation');
    assert_true(
        !str_contains($header, 'ASSIGNED HEADER ARCHETYPE for this build: **minimal-overlay**'),
        'the unreadable overlay must not be assigned'
    );
    assert_true(
        !str_contains($header, 'DETERMINISTIC HEADER BEHAVIOR: overlay-to-solid'),
        'the behavior contract must not promise the overlay shell'
    );
    $hero = sections_request_text($reqs['page-home--hero']);
    assert_contains('"mode": "stacked"', $hero, 'the hero composes for stacked chrome');
    assert_true(!str_contains($hero, '"mode": "overlay"'), 'no overlay contract reaches the hero');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('HEADER_ARCHETYPE env forces the header archetype in the header prompt', function () {
    [$project, $tmp] = sections_fixture();
    putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV . '=branded-lockup');
    try {
        $renderer = new PromptRenderer(repo_path('prompts'));
        $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);
        assert_contains('ASSIGNED HEADER ARCHETYPE for this build: **branded-lockup**', $reqs['header']['prompt']);
    } finally {
        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('the header prompt carries an archetype assignment and the full catalog', function () {
    putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    // The fixture's front hero is an image-led cover over a dark opening, so
    // the computed overlay mode mandates the single minimal-overlay archetype.
    assert_contains('ASSIGNED HEADER ARCHETYPE for this build: **minimal-overlay**', $reqs['header']['prompt']);
    assert_contains('branded-lockup', $reqs['header']['prompt']); // new catalog entries render
    assert_contains('wp:site-logo', $reqs['header']['prompt']);
    assert_contains('DETERMINISTIC HEADER BEHAVIOR: overlay-to-solid', $reqs['header']['prompt']);
    assert_contains('NEVER add `style.position`', $reqs['header']['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections writes header, footer and a part per page section', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    assert_eq(1, $llm->completeBatchCalls, 'all parts sent through one concurrent batch');
    assert_eq(1, $llm->completeCalls, 'step sends exactly one single cache warm-up probe');
    assert_eq(0, $llm->remaining(), 'one queued response per part, all consumed');
    foreach (['parts/header.html', 'parts/footer.html', 'parts/page-home--hero.html', 'parts/page-home--about.html'] as $rel) {
        assert_true($project->exists('theme/' . $rel), "{$rel} written");
        assert_contains('wp:', $project->readText('theme/' . $rel));
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('constrainedPart adds a missing layout to a part top-level group', function () {
    // The tbilisi4 failure shape: className + spacing, no "layout" — the inner
    // align:wide row then renders edge-to-edge at the viewport.
    $in = '<!-- wp:group {"className":"header-overlay","style":{"spacing":{"padding":{"top":"var:preset|spacing|md"}}}} -->' . "\n"
        . '<div class="wp-block-group header-overlay"><!-- wp:site-title /--></div>' . "\n"
        . '<!-- /wp:group -->';
    $out = GeneratedMarkup::constrainedPart($in);
    assert_contains('"layout":{"type":"constrained"}', $out);
    assert_contains('"className":"header-overlay"', $out, 'existing attributes preserved');
    assert_contains('<div class="wp-block-group header-overlay"><!-- wp:site-title /--></div>', $out, 'body untouched');

    // An attribute-less top-level group gets one too.
    $out = GeneratedMarkup::constrainedPart('<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->');
    assert_contains('"layout":{"type":"constrained"}', $out);
});

test('constrainedPart leaves an explicit layout and non-group markup alone', function () {
    $flex = '<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} --><div class="wp-block-group"></div><!-- /wp:group -->';
    assert_eq($flex, GeneratedMarkup::constrainedPart($flex));

    $cover = '<!-- wp:cover {"align":"full"} --><div class="wp-block-cover"></div><!-- /wp:cover -->';
    assert_eq($cover, GeneratedMarkup::constrainedPart($cover));
});

test('sections writes header AND footer with a constrained layout when the model omits one', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group {"className":"header-overlay"} --><!-- wp:site-title /--><!-- /wp:group -->');
    // The naturaleza6 failure: footer group with align:full but no layout —
    // flow, not constrained, so its text ran edge-to-edge at the viewport.
    $llm->queueText('<!-- wp:group {"tagName":"footer","align":"full"} --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    assert_contains('"layout":{"type":"constrained"}', $project->readText('theme/parts/header.html'));
    assert_contains('"layout":{"type":"constrained"}', $project->readText('theme/parts/footer.html'));
    // HeroUnit establishes its objective root envelope; ordinary sections
    // retain their existing width behavior.
    $hero = $project->readText('theme/parts/page-home--hero.html');
    assert_contains('"layout":{"type":"constrained"}', $hero);
    assert_contains('hero-composition--', $hero);
    assert_true(!str_contains($project->readText('theme/parts/page-home--about.html'), '"layout"'), 'ordinary section untouched');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('normalizePresetRefs canonicalizes both delimiter positions independently', function () {
    $cases = [
        ['var:preset|spacing|xl', 'var:preset|spacing|xl'],
        ['var:preset--spacing--xl', 'var:preset|spacing|xl'],
        ['var:preset|spacing--xl', 'var:preset|spacing|xl'],
        ['var:preset--spacing|xl', 'var:preset|spacing|xl'],
        ['var:preset:spacing:sm', 'var:preset|spacing|sm'],
        ['var:preset|color:accent', 'var:preset|color|accent'],
        ['var:preset--font-size--lead', 'var:preset|font-size|lead'],
    ];

    foreach ($cases as [$input, $expected]) {
        assert_eq($expected, GeneratedMarkup::normalizePresetRefs($input), "normalizes {$input}");
    }
});

test('normalizePresetRefs accepts serializer-escaped delimiters in either position', function () {
    $cases = [
        ['var:preset\u002d\u002dspacing\u002d\u002dxl', 'var:preset|spacing|xl'],
        ['var:preset\u002d\u002dspacing|xl', 'var:preset|spacing|xl'],
        ['var:preset|spacing\u002d\u002dxl', 'var:preset|spacing|xl'],
        ['var:preset\u002D\u002dspacing\u002d\u002Dxl', 'var:preset|spacing|xl'],
        ['var:preset\u007cspacing\u007Cxl', 'var:preset|spacing|xl'],
    ];

    foreach ($cases as [$input, $expected]) {
        assert_eq($expected, GeneratedMarkup::normalizePresetRefs($input), "normalizes {$input}");
    }
});

test('normalizePresetRefs leaves unknown refs and CSS custom properties untouched and is idempotent', function () {
    $input = '<!-- wp:group {"style":{"spacing":{"padding":{'
        . '"top":"var:preset|spacing--xl",'
        . '"right":"var:preset--unknown--xl",'
        . '"bottom":"var:preset|not-spacing|xl"'
        . '}}}} -->'
        . '<div style="padding-top:var(--wp--preset--spacing--xl);color:var(--wp--preset--color--ink)">x</div>'
        . '<!-- /wp:group -->';

    $once = GeneratedMarkup::normalizePresetRefs($input);
    $twice = GeneratedMarkup::normalizePresetRefs($once);

    assert_contains('"top":"var:preset|spacing|xl"', $once);
    assert_contains('"right":"var:preset--unknown--xl"', $once);
    assert_contains('"bottom":"var:preset|not-spacing|xl"', $once);
    assert_contains('var(--wp--preset--spacing--xl)', $once);
    assert_contains('var(--wp--preset--color--ink)', $once);
    assert_eq($once, $twice, 'normalization is idempotent');
});

test('sections strips a stray markdown code fence from a part response', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText("```html\n<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->\n```");
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    $header = $project->readText('theme/parts/header.html');
    assert_true(!str_contains($header, '```'), 'code fence stripped');
    assert_contains('wp:group', $header);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections falls back at the failed front-hero key without promoting its sibling', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('just text, no blocks'); // hero — invalid
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    // The contract-critical opening retains its key through a recipe-family
    // fallback; the ordinary sibling is not promoted into an opening role it
    // was never generated to satisfy.
    assert_true($project->exists('theme/parts/page-home--hero.html'), 'failed hero receives a fallback at its existing key');
    assert_contains('hero-fallback--', $project->readText('theme/parts/page-home--hero.html'));
    assert_contains('<h2>About</h2>', $project->readText('theme/parts/page-home--about.html'));
    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(['hero', 'about'], array_column($sections, 'slug'));
    assert_eq('hero', $sections[0]['role']);
    assert_eq('closing', $sections[1]['role']);
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("file='theme/parts/page-home--hero.html'", $joined);
    assert_contains('topology-family fallback', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections replaces a complete but invalid interior opening at its key and preserves siblings', function () {
    [$project, $tmp] = sections_fixture();
    $project->writeJson('pages.json', ['pages' => [
        sections_page('home', [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between header and about.'],
            ['slug' => 'about', 'title' => 'About', 'role' => 'closing', 'type' => 'about', 'layout_archetype' => 'asymmetric-split', 'background' => 'base', 'vertical_density' => 'standard', 'handoff' => 'Between hero and footer.'],
        ]),
        sections_page('menu', [
            ['slug' => 'menu-opening', 'title' => 'Menu Opening', 'role' => 'hero', 'type' => 'opening', 'layout_archetype' => 'centered-stack', 'background' => 'contrast', 'vertical_density' => 'compact', 'handoff' => 'Between header and breads.'],
            ['slug' => 'breads', 'title' => 'Breads', 'role' => 'closing', 'type' => 'catalog', 'layout_archetype' => 'list-with-thumbnails', 'background' => 'base', 'vertical_density' => 'standard', 'handoff' => 'Between opening and footer.'],
        ]),
    ]]);
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h1>Hero</h1><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $invalidOpening = '<!-- wp:paragraph --><p>Complete, but not a contract opening root.</p><!-- /wp:paragraph -->';
    $llm->queueText($invalidOpening);
    $sibling = '<!-- wp:heading --><h2>Breads remain.</h2><!-- /wp:heading -->';
    $llm->queueText($sibling);

    (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $opening = $project->readText('theme/parts/page-menu--menu-opening.html');
    assert_true(!str_contains($opening, $invalidOpening));
    assert_contains('Menu', $opening, 'fallback uses the real page title');
    assert_contains($sibling, $project->readText('theme/parts/page-menu--breads.html'));
    assert_eq(['menu-opening', 'breads'], array_column(
        $project->readJson('pages.json')['pages'][1]['sections'],
        'slug',
    ));
    $joined = implode("\n", $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("file='theme/parts/page-menu--menu-opening.html'", $joined);
    assert_contains('page-opening fallback', $joined);
    assert_contains('one complete top-level wp:group', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections retains a hero fallback when every generated page section is unusable', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // header — valid
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // footer — valid
    $llm->queueText('just text, no blocks');                               // page-home--hero — invalid
    $llm->queueText('also just text, no blocks');                          // page-home--about — invalid
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    assert_true($project->exists('theme/parts/header.html'), 'surviving header is delivered');
    assert_true($project->exists('theme/parts/footer.html'), 'surviving footer is delivered');
    assert_contains('hero-fallback--', $project->readText('theme/parts/page-home--hero.html'));
    assert_true(!$project->exists('theme/parts/page-home--about.html'), 'ordinary failed sibling is pruned');
    assert_eq(['hero'], array_column(
        $project->readJson('pages.json')['pages'][0]['sections'],
        'slug',
    ));
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains('topology-family fallback', $joined);
    assert_contains("part 'page-home--about': unusable generated markup", $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections falls back to deterministic chrome when the header markup is unusable', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('just prose, not a header');                            // header — invalid
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // footer — valid
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    $header = $project->readText('theme/parts/header.html');
    assert_contains('wp:site-title', $header, 'fallback chrome carries the site title');
    assert_contains('"layout":{"type":"constrained"}', $header);
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("file='theme/parts/header.html'", $joined);
    assert_contains('delivered=mode-aware', $joined);
    assert_contains('header fallback', $joined);
    assert_contains('disposition=', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections keeps the assigned surface when generated footer markup is unusable', function () {
    [$project, $tmp] = sections_fixture();
    $pages = $project->readJson('pages.json')['pages'];
    $archetype = SectionsStep::footerArchetype(
        $pages,
        $project->readText('siteSpec.json'),
        \Automattic\SiteBuild\Steps\DesignDirectionStep::readFor($project)
    );
    $surface = FooterComposition::surface($archetype);

    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('just prose, not a footer');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');

    (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $footer = $project->readText('theme/parts/footer.html');
    assert_contains("\"backgroundColor\":\"{$surface}\"", $footer);
    assert_contains("has-{$surface}-background-color", $footer);
    assert_contains('wp:site-title', $footer);
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("part 'footer': unusable generated markup", $joined);
    assert_contains("file='theme/parts/footer.html'", $joined);
    assert_contains("block='part root'", $joined);
    assert_contains('authored=', $joined);
    assert_contains('delivered=deterministic minimal footer', $joined);
    assert_contains('disposition=', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('fallback chrome relies on the template-part landmark instead of nesting one', function () {
    foreach (['header', 'footer'] as $key) {
        $markup = SectionsStep::fallbackChrome($key);
        assert_contains('<div class="wp-block-group', $markup);
        assert_true(!str_contains($markup, '"tagName"'), "{$key} fallback has no redundant tagName");
        assert_true(!str_contains($markup, "<{$key}"), "{$key} fallback has no nested semantic landmark");
    }
    $contrastFooter = SectionsStep::fallbackChrome('footer', 'contrast');
    assert_contains('"backgroundColor":"contrast"', $contrastFooter);
    assert_contains('"textColor":"base"', $contrastFooter);
    assert_contains('"isLink":false', $contrastFooter);
    assert_contains('has-contrast-background-color', $contrastFooter);
    assert_true(
        !str_contains(SectionsStep::fallbackChrome('footer', 'contrast', 2), '"isLink":false'),
        'a multi-page fallback may link the site title home'
    );
});

test('chrome nav rules follow the page count: anchors for one page, page-list for several', function () {
    [$project, $tmp] = sections_fixture(); // homepage only
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_contains('do NOT use `<!-- wp:page-list /-->`', $reqs['header']['prompt']);
    assert_contains('href="#menu-highlights"', $reqs['header']['prompt']);
    assert_contains('This site is ONE page: NEVER use `wp:page-list`', $reqs['footer']['prompt']);
    assert_contains('root-relative `/#anchor`', $reqs['footer']['prompt']);
    assert_contains('`href="/"`', $reqs['footer']['prompt']);
    assert_contains('site-title MUST explicitly set `"isLink":false`', $reqs['footer']['prompt']);

    $project->writeJson('pages.json', ['pages' => [
        sections_page('home', [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the footer below.'],
        ]),
        sections_page('menu', [
            ['slug' => 'menu-hero', 'title' => 'Menu Hero', 'role' => 'hero', 'type' => 'menu-introduction', 'layout_archetype' => 'centered-stack', 'background' => 'tinted', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the footer below.'],
        ]),
    ]]);
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_contains('should contain `<!-- wp:page-list /-->`', $reqs['header']['prompt']);
    assert_true(!str_contains($reqs['header']['prompt'], 'do NOT use `<!-- wp:page-list /-->`'), 'multi-page header keeps the page-list default');
    assert_contains('A compact `wp:page-list` is permitted', $reqs['footer']['prompt']);
    assert_true(!str_contains($reqs['footer']['prompt'], 'This site is ONE page'), 'multi-page footer may list pages');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section prompts carry the slug as the anchor and the outline exposes it', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    $about = sections_request_text($reqs['page-home--about']);
    assert_contains('"anchor":"about"', $about);
    assert_contains('id="about"', $about);
    assert_contains('[#about]', $about); // its own outline line ends with the anchor
    assert_contains('[#hero]', $reqs['header']['prompt']); // the header sees the anchors too
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections salvages a truncated section part instead of failing the build', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK'); // cache warm-up probe
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    // The BIGR-716 portfolio2 shape: the about section's stream was cut off
    // inside a paragraph's comment JSON, leaving two groups unclosed.
    $llm->queueText(<<<HTML
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:heading --><h2 class="wp-block-heading">About</h2><!-- /wp:heading -->
<!-- wp:group {"layout":{"type":"flex"}} -->
<div class="wp-block-group">
<!-- wp:paragraph --><p>Complete.</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"typography":{"textTransform":"
HTML);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    $about = $project->readText('theme/parts/page-home--about.html');
    assert_true(!str_contains($about, 'textTransform'), 'the half-written delimiter is trimmed');
    assert_contains('<p>Complete.</p>', $about, 'the last complete block survives');
    assert_eq(
        substr_count($about, '<!-- wp:group'),
        substr_count($about, '<!-- /wp:group -->'),
        'every group opener has a closer',
    );
    assert_eq(substr_count($about, '<div'), substr_count($about, '</div>'), 'the div stack is rebalanced');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections persists an exhausted abnormal batch note even when balanced markup needs no salvage', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK'); // cache warm-up probe
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $llm->batchNotes = [
        'page-home--hero' => [
            "part 'page-home--hero': model response remained abnormally terminated after 1 regeneration(s) "
                . '(generation was truncated (stop reason: max_tokens)); best partial response retained',
        ],
    ];

    (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_contains('<h2>Hero</h2>', $project->readText('theme/parts/page-home--hero.html'));
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("part 'page-home--hero': model response remained abnormally terminated", $joined);
    assert_contains('max_tokens', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});
