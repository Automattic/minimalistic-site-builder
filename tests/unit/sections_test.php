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

/** Minimal valid section output: one closed top-level wp:group. */
function sections_part(string $heading): string
{
    return '<!-- wp:group --><!-- wp:heading --><h2>' . $heading
        . '</h2><!-- /wp:heading --><!-- /wp:group -->';
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

test('sections rejects a missing structural role or semantic type', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $pages = $project->readJson('pages.json');

    unset($pages['pages'][0]['sections'][0]['role']);
    $project->writeJson('pages.json', $pages);
    assert_throws(function () use ($renderer, $project) {
        (new SectionsStep(new FakeLlm(), $renderer))->requests($project);
    }, "expected 'hero'");

    $pages['pages'][0]['sections'][0]['role'] = 'hero';
    unset($pages['pages'][0]['sections'][0]['type']);
    $project->writeJson('pages.json', $pages);
    assert_throws(function () use ($renderer, $project) {
        (new SectionsStep(new FakeLlm(), $renderer))->requests($project);
    }, 'missing semantic type');

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

test('header archetype pool offers minimal-overlay only for image-led heroes', function () {
    $imageHero = [['slug' => 'hero', 'role' => 'hero', 'type' => 'cinematic-welcome', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image']];
    assert_eq(SectionsStep::HEADER_ARCHETYPES, SectionsStep::headerArchetypePool($imageHero));

    $imageBand = [['slug' => 'hero', 'role' => 'hero', 'type' => 'cinematic-welcome', 'layout_archetype' => 'centered-stack', 'background' => 'image']];
    assert_true(in_array('minimal-overlay', SectionsStep::headerArchetypePool($imageBand), true), 'image band hero allows overlay');

    $plainHero = [['slug' => 'hero', 'role' => 'hero', 'type' => 'quiet-welcome', 'layout_archetype' => 'centered-stack', 'background' => 'base']];
    $pool = SectionsStep::headerArchetypePool($plainHero);
    assert_true(!in_array('minimal-overlay', $pool, true), 'plain hero excludes overlay');
    assert_eq(count(SectionsStep::HEADER_ARCHETYPES) - 1, count($pool));

    // A framed canvas keeps a mat around the hero, so the overlay is out even
    // over an image-led cover.
    $framed = SectionsStep::headerArchetypePool($imageHero, 'framed');
    assert_true(!in_array('minimal-overlay', $framed, true), 'framed canvas excludes overlay');

    // A one-page site has no pages to split across split-nav's two navs.
    $single = SectionsStep::headerArchetypePool($imageHero, '', 1);
    assert_true(!in_array('split-nav', $single, true), 'single-page site excludes split-nav');
    assert_true(in_array('minimal-overlay', $single, true), 'overlay gating is independent of page count');
});

test('header assignment offers two distinct archetypes from the pool', function () {
    putenv(SectionsStep::ARCHETYPE_ENV); // a forced archetype in the caller's env would skip the two-pick branch
    $sections = [['slug' => 'hero', 'role' => 'hero', 'type' => 'quiet-welcome', 'layout_archetype' => 'centered-stack', 'background' => 'base']];
    for ($i = 0; $i < 10; $i++) {
        $assignment = SectionsStep::headerAssignment($sections);
        assert_true((bool) preg_match('/\*\*([a-z-]+)\*\* or \*\*([a-z-]+)\*\*/', $assignment, $m), 'two archetypes offered');
        assert_true($m[1] !== $m[2], 'the two picks are distinct');
        foreach ([$m[1], $m[2]] as $pick) {
            assert_true(in_array($pick, SectionsStep::headerArchetypePool($sections), true), "'{$pick}' is in the compatible pool");
        }
    }
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
            SectionsStep::headerAssignment([['slug' => 'hero', 'role' => 'hero', 'type' => 'quiet-welcome']]);
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

    assert_contains('ASSIGNED HEADER ARCHETYPES for this build:', $reqs['header']['prompt']);
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
    $llm->queueText(sections_part('Hero'));
    $llm->queueText(sections_part('About'));
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

test('sections retries only the invalid part once and retains valid siblings', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $invalid = '<!-- wp:group {"layout":{"type":"flex","justifyContent":"diagonal"}} -->'
        . '<div class="wp-block-group"><p>Hero copy</p></div><!-- /wp:group -->';
    $llm->queueText($invalid);
    $llm->queueText(sections_part('About'));
    $llm->queueText(
        '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><p>Hero copy</p></div><!-- /wp:group -->'
    );

    (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq(2, $llm->completeBatchCalls, 'one initial batch plus one invalid-only repair batch');
    assert_eq(6, count($llm->calls), 'cache warm + four initial parts + one repaired part');
    $repair = $llm->calls[5];
    assert_eq('page-home--hero-markup-repair', $repair['opts']['log_label'] ?? null);
    assert_contains("layout value 'diagonal'", $repair['prompt']);
    assert_contains($invalid, $repair['prompt'], 'repair receives the rejected output');
    assert_true(
        !str_contains($repair['prompt'], 'Build the site FOOTER template part'),
        'a valid sibling is not regenerated in the repair request'
    );
    assert_contains('Hero copy', $project->readText('theme/parts/page-home--hero.html'));
    foreach (['header.html', 'footer.html', 'page-home--about.html'] as $file) {
        assert_true($project->exists('theme/parts/' . $file), "valid sibling {$file} retained");
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections repairs only a section whose root group closes too early', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $invalid = '<!-- wp:group --><!-- wp:columns --><!-- wp:column -->'
        . '<div class="wp-block-column"><p>Hero copy</p></div><!-- /wp:column --><!-- /wp:columns -->'
        . '<!-- /wp:group --><!-- wp:column -->'
        . '<div class="wp-block-column"></div><!-- /wp:column -->';
    $llm->queueText($invalid);
    $llm->queueText(sections_part('About'));
    $llm->queueText(
        '<!-- wp:group --><!-- wp:columns --><!-- wp:column -->'
        . '<div class="wp-block-column"><p>Hero copy</p></div><!-- /wp:column --><!-- /wp:columns -->'
        . '<!-- /wp:group -->'
    );

    (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq(2, $llm->completeBatchCalls, 'malformed section root receives one repair batch');
    $repair = $llm->calls[5];
    assert_eq('page-home--hero-markup-repair', $repair['opts']['log_label'] ?? null);
    assert_contains('must contain exactly one top-level wp:group', $repair['prompt']);
    assert_contains($invalid, $repair['prompt']);
    assert_contains('Hero copy', $project->readText('theme/parts/page-home--hero.html'));
    assert_true($project->exists('theme/parts/page-home--about.html'), 'valid sibling is retained');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections writes nothing when the one semantic repair remains invalid', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText(
        '<!-- wp:group {"layout":{"type":"flex","justifyContent":"diagonal"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
    );
    $llm->queueText(sections_part('About'));
    $llm->queueText(
        '<!-- wp:group {"layout":{"type":"flex","justifyContent":"sideways"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
    );
    $error = null;

    try {
        (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    } catch (RuntimeException $caught) {
        $error = $caught;
    }

    assert_true($error instanceof RuntimeException);
    assert_contains('markup repair failed after one attempt', $error->getMessage());
    assert_contains('page-home--hero', $error->getMessage());
    assert_contains("layout value 'sideways'", $error->getMessage());
    assert_eq(2, $llm->completeBatchCalls, 'a failed repair is never retried recursively');
    foreach (['header.html', 'footer.html', 'page-home--hero.html', 'page-home--about.html'] as $file) {
        assert_true(!$project->exists('theme/parts/' . $file), "no {$file} written");
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
    $llm->queueText(sections_part('Hero'));
    $llm->queueText(sections_part('About'));
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
    $llm->queueText(sections_part('Hero'));
    $llm->queueText(sections_part('About'));
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    $header = $project->readText('theme/parts/header.html');
    assert_true(!str_contains($header, '```'), 'code fence stripped');
    assert_contains('wp:group', $header);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections throws when a part has no block markup', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('just text, no blocks'); // hero — invalid
    $llm->queueText(sections_part('About'));
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionsStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections writes nothing when any part is invalid (no partial output)', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // header — valid
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // footer — valid
    $llm->queueText('just text, no blocks');                               // page-home--hero — invalid
    $llm->queueText(sections_part('About'));
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionsStep($llm, $renderer))->run($project);
    });
    // The valid header/footer must NOT have been written before the bad part threw.
    assert_true(!$project->exists('theme/parts/header.html'), 'no part written when a sibling is invalid');
    assert_true(!$project->exists('theme/parts/footer.html'), 'no part written when a sibling is invalid');
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
    $llm->queueText(sections_part('Hero'));
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
