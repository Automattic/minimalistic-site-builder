<?php
declare(strict_types=1);

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
    $project->writeJson('theme/theme.json', ['version' => 3]);
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
    assert_eq(2, count($reqs['page-home--hero']['cached_prefixes'] ?? []), 'sections carry both cache layers');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections fans out across every page and gives each section its own page context', function () {
    [$project, $tmp] = sections_fixture();
    $project->writeJson('pages.json', ['pages' => [
        sections_page('home', [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the footer below.'],
        ]),
        sections_page('menu', [
            ['slug' => 'menu-hero', 'title' => 'Menu Hero', 'role' => 'hero', 'type' => 'menu-introduction', 'layout_archetype' => 'centered-stack', 'background' => 'tinted', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the bread list below.'],
            ['slug' => 'breads', 'title' => 'Breads', 'role' => 'closing', 'type' => 'bread-catalog', 'layout_archetype' => 'list-with-thumbnails', 'background' => 'base', 'vertical_density' => 'standard', 'handoff' => 'Between the tinted hero above and the footer below.'],
        ], ['purpose' => 'What we bake']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_eq(
        ['header', 'footer', 'page-home--hero', 'page-menu--menu-hero', 'page-menu--breads'],
        array_keys($reqs)
    );

    $homePrefixes = $reqs['page-home--hero']['cached_prefixes'] ?? [];
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

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections passes the design direction and hero brief to header and footer prompts', function () {
    [$project, $tmp] = sections_fixture();
    $project->writeJson('designDirection.json', [
        'title'       => 'Archivo Silencioso',
        'description' => 'Full-bleed black-and-white photography, chrome-less.',
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

test('sections passes each part its assigned composition and its neighbors\' assignments', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    $hero = sections_request_text($reqs['page-home--hero']);
    assert_true((bool) preg_match('/Layout archetype:\s+full-bleed-cover/', $hero), 'hero archetype in prompt');
    assert_true((bool) preg_match('/Background:\s+image/', $hero), 'hero background in prompt');
    assert_true((bool) preg_match('/Vertical density:\s+standard/', $hero), 'hero density in prompt');
    assert_contains('Between the site header above and the base about split below.', $hero);
    assert_contains('Above: the site header (this is the first section)', $hero);
    assert_contains('Below: "About" — asymmetric-split on base background, standard vertical density', $hero);
    assert_contains('If SECTION Notes mention a different layout or background', $hero);

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
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero'],
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
    // were generated from — a warning saying "corrected" must not leave the
    // defect in the artifact downstream consumers read.
    $hero = $project->readJson('pages.json')['pages'][0]['sections'][0];
    assert_eq('hero', $hero['role']);
    assert_eq('content', $hero['type']);
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("role '' corrected to 'hero'", $joined);
    assert_contains("missing semantic type; defaulted to 'content'", $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections leaves pages.json untouched when the generation batch fails operationally', function () {
    [$project, $tmp] = sections_fixture();
    $pages = $project->readJson('pages.json');
    unset($pages['pages'][0]['sections'][0]['role']);
    unset($pages['pages'][0]['sections'][0]['type']);
    $project->writeJson('pages.json', $pages);
    $before = $project->readText('pages.json');

    // No queued responses: cache warming degrades, then the actual batch
    // throws. Repairs used to build the requests must remain in-memory until
    // generated output has been normalized successfully.
    $llm = new FakeLlm();
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(static fn () => (new SectionsStep($llm, $renderer))->run($project));

    assert_eq($before, $project->readText('pages.json'), 'operational failure leaves the input artifact byte-identical');
    assert_true(!$project->exists('warnings.json'), 'uncommitted repairs do not produce delivered-output warnings');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('heroBrief selects only the structural hero role, not the semantic type', function () {
    $brief = SectionsStep::heroBrief([
        ['slug' => 'decoy', 'title' => 'Not the Hero', 'role' => 'content', 'type' => 'hero'],
        ['slug' => 'intro', 'title' => 'Big Hero', 'role' => 'hero', 'type' => 'cinematic-welcome', 'purpose' => 'Wow', 'content_notes' => 'Edge to edge.'],
    ]);
    assert_contains('Title: Big Hero', $brief);
    assert_contains('Role: hero', $brief);
    assert_contains('Type: cinematic-welcome', $brief);
    assert_contains('Purpose: Wow', $brief);
    assert_contains('Notes: Edge to edge.', $brief);
    assert_true(!str_contains($brief, 'Not the Hero'), 'a semantic type named hero does not override the structural role');

    // No hero-role section: do not silently treat another structural role as the hero.
    $brief = SectionsStep::heroBrief([['slug' => 'intro', 'title' => 'Intro', 'role' => 'content', 'type' => 'opening-note']]);
    assert_eq('(No hero section planned.)', $brief);

    assert_eq('(No hero section planned.)', SectionsStep::heroBrief([]));
});

/** A one-page pages.json fixture whose front hero has the given plan fields. */
function sections_pages_with_hero(array $hero, array $more = []): array
{
    return array_merge([sections_page('home', [array_merge([
        'slug' => 'hero', 'role' => 'hero', 'type' => 'welcome',
    ], $hero)])], $more);
}

test('header mode is overlay only for image-led full-bleed heroes over dark openings', function () {
    $imageHero = sections_pages_with_hero(['layout_archetype' => 'full-bleed-cover', 'background' => 'image']);
    assert_eq(SectionsStep::MODE_OVERLAY, SectionsStep::headerMode($imageHero));

    $imageBand = sections_pages_with_hero(['layout_archetype' => 'centered-stack', 'background' => 'image']);
    assert_eq(SectionsStep::MODE_OVERLAY, SectionsStep::headerMode($imageBand), 'image band hero floats the header');

    $plainHero = sections_pages_with_hero(['layout_archetype' => 'centered-stack', 'background' => 'base']);
    assert_eq(SectionsStep::MODE_STACKED, SectionsStep::headerMode($plainHero));

    // A framed canvas keeps a mat around the hero — nothing to float over.
    assert_eq(SectionsStep::MODE_STACKED, SectionsStep::headerMode($imageHero, 'framed'));

    // The header renders on EVERY page: one page opening on a light band
    // breaks the one-text-color guarantee, so the site falls back to stacked.
    $lightInnerPage = sections_pages_with_hero(
        ['layout_archetype' => 'full-bleed-cover', 'background' => 'image'],
        [sections_page('menu', [['slug' => 'menu-hero', 'role' => 'hero', 'type' => 'menu', 'background' => 'base']])],
    );
    assert_eq(SectionsStep::MODE_STACKED, SectionsStep::headerMode($lightInnerPage));

    $darkInnerPage = sections_pages_with_hero(
        ['layout_archetype' => 'full-bleed-cover', 'background' => 'image'],
        [sections_page('menu', [['slug' => 'menu-hero', 'role' => 'hero', 'type' => 'menu', 'background' => 'contrast']])],
    );
    assert_eq(SectionsStep::MODE_OVERLAY, SectionsStep::headerMode($darkInnerPage), 'contrast bands read as dark openings');
});

test('header archetype pool follows the header mode', function () {
    // Overlay mode: the pool IS minimal-overlay — the audited failure was the
    // overlay CSS shipping unused because the archetype lost a random draw.
    $imageHero = sections_pages_with_hero(['layout_archetype' => 'full-bleed-cover', 'background' => 'image']);
    assert_eq(['minimal-overlay'], SectionsStep::headerArchetypePool($imageHero));

    // Stacked, one-page, plan has a hero: overlay, split-nav and the
    // display-scale oversized-wordmark are all out.
    $plainHero = sections_pages_with_hero(['layout_archetype' => 'centered-stack', 'background' => 'base']);
    $pool = SectionsStep::headerArchetypePool($plainHero);
    foreach (['minimal-overlay', 'split-nav', 'oversized-wordmark'] as $out) {
        assert_true(!in_array($out, $pool, true), "'{$out}' excluded for a one-page headline-led site");
    }
    assert_true(in_array('centered-masthead', $pool, true), 'masthead allowed over a non-image hero');

    // Stacked because framed, but still image-led: the tall centered-masthead
    // would push a viewport-scale cover below the fold.
    $framed = SectionsStep::headerArchetypePool($imageHero, 'framed');
    assert_true(!in_array('centered-masthead', $framed, true), 'masthead excluded over an image-led hero');
    assert_true(!in_array('minimal-overlay', $framed, true), 'framed canvas never floats the header');

    // A two-page site keeps split-nav in stacked mode.
    $twoPages = sections_pages_with_hero(
        ['layout_archetype' => 'centered-stack', 'background' => 'base'],
        [sections_page('menu', [['slug' => 'menu-hero', 'role' => 'hero', 'type' => 'menu', 'background' => 'base']])],
    );
    assert_true(in_array('split-nav', SectionsStep::headerArchetypePool($twoPages), true));
});

test('header assignment offers two distinct stacked archetypes from the pool', function () {
    putenv(SectionsStep::ARCHETYPE_ENV); // a forced archetype in the caller's env would skip the two-pick branch
    $pages = sections_pages_with_hero(['layout_archetype' => 'centered-stack', 'background' => 'base']);
    for ($i = 0; $i < 10; $i++) {
        $assignment = SectionsStep::headerAssignment($pages);
        assert_true((bool) preg_match('/\*\*([a-z-]+)\*\* or \*\*([a-z-]+)\*\*/', $assignment, $m), 'two archetypes offered');
        assert_true($m[1] !== $m[2], 'the two picks are distinct');
        foreach ([$m[1], $m[2]] as $pick) {
            assert_true(in_array($pick, SectionsStep::headerArchetypePool($pages), true), "'{$pick}' is in the compatible pool");
        }
        assert_contains('STACKS as an opaque bar', $assignment, 'stacked assignments carry the contract');
    }
});

test('header assignment mandates minimal-overlay with its class hook in overlay mode', function () {
    putenv(SectionsStep::ARCHETYPE_ENV);
    $pages = sections_pages_with_hero(['layout_archetype' => 'full-bleed-cover', 'background' => 'image']);
    $assignment = SectionsStep::headerAssignment($pages);
    assert_contains('ASSIGNED HEADER ARCHETYPE for this build: **minimal-overlay**', $assignment);
    assert_contains('"className":"header-overlay"', $assignment);
});

test('header contract text matches the mode and reaches only hero-role sections', function () {
    assert_contains('floats TRANSPARENTLY', SectionsStep::headerContract(SectionsStep::MODE_OVERLAY));
    assert_contains('reach the very top edge', SectionsStep::headerContract(SectionsStep::MODE_OVERLAY));
    assert_contains('OPAQUE site header', SectionsStep::headerContract(SectionsStep::MODE_STACKED));
    assert_contains('"minHeight":80', SectionsStep::headerContract(SectionsStep::MODE_STACKED));

    [$project, $tmp] = sections_fixture(); // front hero is image-led → overlay mode
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);
    $hero = sections_request_text($reqs['page-home--hero']);
    $about = sections_request_text($reqs['page-home--about']);
    assert_contains('HEADER CONTRACT (this is a page-opening section)', $hero, 'the page-opening section gets the contract');
    assert_contains('floats TRANSPARENTLY', $hero, 'the contract carries the computed mode');
    assert_true(
        !str_contains($about, 'HEADER CONTRACT (this is a page-opening section)'),
        'non-opening sections share no viewport with the header'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('HEADER_ARCHETYPE env forces the header archetype in the header prompt', function () {
    [$project, $tmp] = sections_fixture();
    putenv(SectionsStep::ARCHETYPE_ENV . '=branded-lockup');
    try {
        $renderer = new PromptRenderer(repo_path('prompts'));
        $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);
        assert_contains('ASSIGNED HEADER ARCHETYPE for this build: **branded-lockup**', $reqs['header']['prompt']);
    } finally {
        putenv(SectionsStep::ARCHETYPE_ENV);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('an unknown HEADER_ARCHETYPE aborts instead of silently generating', function () {
    putenv(SectionsStep::ARCHETYPE_ENV . '=mega-header');
    try {
        assert_throws(function () {
            SectionsStep::headerAssignment(sections_pages_with_hero(['type' => 'quiet-welcome']));
        }, 'not a header archetype');
    } finally {
        putenv(SectionsStep::ARCHETYPE_ENV);
    }
});

test('the header prompt carries an archetype assignment and the full catalog', function () {
    putenv(SectionsStep::ARCHETYPE_ENV); // a forced archetype in the caller's env would skip the two-pick branch
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    // The fixture's front hero is an image-led cover over a dark opening, so
    // the computed overlay mode mandates the single minimal-overlay archetype.
    assert_contains('ASSIGNED HEADER ARCHETYPE for this build: **minimal-overlay**', $reqs['header']['prompt']);
    assert_contains('branded-lockup', $reqs['header']['prompt']); // new catalog entries render
    assert_contains('wp:site-logo', $reqs['header']['prompt']);
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
    // Sections are not touched — their width discipline is their own.
    assert_true(!str_contains($project->readText('theme/parts/page-home--hero.html'), '"layout"'), 'section untouched');
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

test('sections drops a part with no block markup and prunes it from the plan', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('just text, no blocks'); // hero — invalid
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $llm->queueText('still just text, no blocks'); // hero repair — also invalid
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    // One unusable section is dropped; every paid-for sibling still ships,
    // and the plan is pruned so downstream steps agree with the parts on disk.
    assert_true(!$project->exists('theme/parts/page-home--hero.html'), 'unusable section not written');
    assert_contains('<h2>About</h2>', $project->readText('theme/parts/page-home--about.html'));
    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(['about'], array_column($sections, 'slug'), 'dropped section pruned from the plan');
    assert_eq('hero', $sections[0]['role'], 'positional roles are recomputed after pruning');
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("part 'page-home--hero': unusable generated markup", $joined);
    assert_contains("role 'closing' corrected to 'hero'", $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections delivers deterministic chrome and an empty front page when every generated section is unusable', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // header — valid
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // footer — valid
    $llm->queueText('just text, no blocks');                               // page-home--hero — invalid
    $llm->queueText('also just text, no blocks');                          // page-home--about — invalid
    $llm->queueText('repair still not blocks');                            // hero repair — invalid
    $llm->queueText('repair still not blocks either');                     // about repair — invalid
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    assert_true($project->exists('theme/parts/header.html'), 'surviving header is delivered');
    assert_true($project->exists('theme/parts/footer.html'), 'surviving footer is delivered');
    assert_eq([], $project->readJson('pages.json')['pages'][0]['sections'], 'front page remains with no sections');
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("part 'page-home--hero': unusable generated markup", $joined);
    assert_contains("part 'page-home--about': unusable generated markup", $joined);
    assert_contains("empty front page delivered", $joined);
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
    $llm->queueText('repair: still just prose');                            // header repair — invalid
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    $header = $project->readText('theme/parts/header.html');
    assert_contains('wp:site-title', $header, 'fallback chrome carries the site title');
    assert_contains('"layout":{"type":"constrained"}', $header);
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("part 'header': unusable generated markup", $joined);
    assert_contains('deterministic minimal header delivered', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('header nav rule follows the page count: anchors for one page, page-list for several', function () {
    [$project, $tmp] = sections_fixture(); // homepage only
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_contains('do NOT use `<!-- wp:page-list /-->`', $reqs['header']['prompt']);
    assert_contains('href="#menu-highlights"', $reqs['header']['prompt']);

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

test('sections recovers an unusable part with a one-shot repair pass (BIGR-738)', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // header — valid
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // footer — valid
    // The atlas2/portfolio3 failure shape: plausible markup wrapped in prose,
    // rejected as "does not contain a standalone block document".
    $llm->queueText("Sure! Here is the hero section you asked for:\n"
        . '<!-- wp:group {"anchor":"hero","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group"><p>Hero</p></div><!-- /wp:group -->'
        . "\nAnd one alternative version:\n"
        . '<!-- wp:group --><div class="wp-block-group"><p>Alt</p></div><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->'); // about — valid
    // The repair pass returns the corrected single document.
    $llm->queueText('<!-- wp:group {"anchor":"hero","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group"><p>Hero</p></div><!-- /wp:group -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    // The section ships and stays in the plan instead of being dropped.
    assert_contains('<p>Hero</p>', $project->readText('theme/parts/page-home--hero.html'));
    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(['hero', 'about'], array_column($sections, 'slug'), 'no section pruned');
    $joined = implode(' ', $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains('recovered by the one-shot repair pass', $joined);
    assert_true(!str_contains($joined, 'dropped from the page plan'), 'nothing dropped');

    // The repair prompt shows the model its own response and the exact error.
    $repairPrompt = $llm->calls[count($llm->calls) - 1]['prompt'];
    assert_contains('YOUR PREVIOUS RESPONSE', $repairPrompt);
    assert_contains('Sure! Here is the hero section', $repairPrompt);
    assert_contains('block document', $repairPrompt);
    exec('rm -rf ' . escapeshellarg($tmp));
});
