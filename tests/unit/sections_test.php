<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * Unit tests for SectionsStep: it fires one request per part (header, footer,
 * each section), validates the markup, and writes the part files.
 */

function sections_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_sec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'handoff' => 'Between the site header above and the base about split below.'],
        ['slug' => 'about', 'title' => 'About', 'type' => 'about', 'layout_archetype' => 'asymmetric-split', 'background' => 'base', 'handoff' => 'Between the image hero above and the footer below.'],
    ]]);
    return [$project, $tmp];
}

test('sections requests one part per header/footer/section', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_eq(['header', 'footer', 'section-hero', 'section-about'], array_keys($reqs));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections passes the design direction and hero brief to header and footer prompts', function () {
    [$project, $tmp] = sections_fixture();
    $project->writeJson('designDirection.json', [
        'title'       => 'Archivo Silencioso',
        'description' => 'Full-bleed black-and-white photography, chrome-less.',
    ]);
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero', 'purpose' => 'Immerse the visitor', 'content_notes' => 'Full-viewport cover photo.', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'handoff' => 'Between the header and about section.'],
        ['slug' => 'about', 'title' => 'About', 'type' => 'about', 'layout_archetype' => 'centered-stack', 'background' => 'base', 'handoff' => 'Between the hero and footer.'],
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

    foreach (['header', 'footer', 'section-hero', 'section-about'] as $key) {
        assert_contains('in es-AR', $reqs[$key]['prompt']);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections falls back to a descriptive language phrase for specs without one', function () {
    [$project, $tmp] = sections_fixture(); // fixture spec has no "language"
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_contains('in the language the user prompt is written in', $reqs['section-hero']['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections passes each part its assigned composition and its neighbors\' assignments', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    $hero = $reqs['section-hero']['prompt'];
    assert_true((bool) preg_match('/Layout archetype:\s+full-bleed-cover/', $hero), 'hero archetype in prompt');
    assert_true((bool) preg_match('/Background:\s+image/', $hero), 'hero background in prompt');
    assert_contains('Between the site header above and the base about split below.', $hero);
    assert_contains('Above: the site header (this is the first section)', $hero);
    assert_contains('Below: "About" — asymmetric-split on base background', $hero);
    assert_contains('If SECTION Notes mention a different layout or background', $hero);

    $about = $reqs['section-about']['prompt'];
    assert_true((bool) preg_match('/Layout archetype:\s+asymmetric-split/', $about), 'about archetype in prompt');
    assert_contains('Above: "Hero" — full-bleed-cover on image background', $about);
    assert_contains('Below: the site footer (this is the last section)', $about);

    // The shared outline carries the whole page's rhythm to every part.
    assert_contains('1. Hero (hero) — full-bleed-cover on image background', $reqs['header']['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('outline and neighbors tolerate a plan without art-direction fields', function () {
    $sections = [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
        ['slug' => 'about', 'title' => 'About', 'type' => 'about'],
    ];
    assert_eq("1. Hero (hero)\n2. About (about)", SectionsStep::outline($sections));
    assert_eq("Above: \"Hero\"\nBelow: the site footer (this is the last section)", SectionsStep::neighbors($sections, 1));
});

test('sections throws when a planned section is missing composition fields', function () {
    $tmp = sys_get_temp_dir() . '/builder_sec_old_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($renderer, $project) {
        (new SectionsStep(new FakeLlm(), $renderer))->requests($project);
    }, 'missing layout_archetype');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('heroBrief summarizes the hero section and falls back to the first section', function () {
    $brief = SectionsStep::heroBrief([
        ['slug' => 'intro', 'title' => 'Intro', 'type' => 'content'],
        ['slug' => 'hero', 'title' => 'Big Hero', 'type' => 'hero', 'purpose' => 'Wow', 'content_notes' => 'Edge to edge.'],
    ]);
    assert_contains('Title: Big Hero', $brief);
    assert_contains('Purpose: Wow', $brief);
    assert_contains('Notes: Edge to edge.', $brief);

    // No hero-typed section: fall back to the first section.
    $brief = SectionsStep::heroBrief([['slug' => 'intro', 'title' => 'Intro', 'type' => 'content']]);
    assert_contains('Title: Intro', $brief);

    assert_eq('(No hero section planned.)', SectionsStep::heroBrief([]));
});

test('header archetype pool offers minimal-overlay only for image-led heroes', function () {
    $imageHero = [['slug' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image']];
    assert_eq(SectionsStep::HEADER_ARCHETYPES, SectionsStep::headerArchetypePool($imageHero));

    $imageBand = [['slug' => 'hero', 'type' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'image']];
    assert_true(in_array('minimal-overlay', SectionsStep::headerArchetypePool($imageBand), true), 'image band hero allows overlay');

    $plainHero = [['slug' => 'hero', 'type' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']];
    $pool = SectionsStep::headerArchetypePool($plainHero);
    assert_true(!in_array('minimal-overlay', $pool, true), 'plain hero excludes overlay');
    assert_eq(count(SectionsStep::HEADER_ARCHETYPES) - 1, count($pool));

    // A framed canvas keeps a mat around the hero, so the overlay is out even
    // over an image-led cover.
    $framed = SectionsStep::headerArchetypePool($imageHero, 'framed');
    assert_true(!in_array('minimal-overlay', $framed, true), 'framed canvas excludes overlay');
});

test('header assignment offers two distinct archetypes from the pool', function () {
    $sections = [['slug' => 'hero', 'type' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']];
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
            SectionsStep::headerAssignment([['slug' => 'hero', 'type' => 'hero']]);
        }, 'not a header archetype');
    } finally {
        putenv(SectionsStep::ARCHETYPE_ENV);
    }
});

test('the header prompt carries an archetype assignment and the full catalog', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_contains('ASSIGNED HEADER ARCHETYPES for this build:', $reqs['header']['prompt']);
    assert_contains('branded-lockup', $reqs['header']['prompt']); // new catalog entries render
    assert_contains('wp:site-logo', $reqs['header']['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections writes header, footer and a part per section', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    foreach (['parts/header.html', 'parts/footer.html', 'parts/section-hero.html', 'parts/section-about.html'] as $rel) {
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
    $out = SectionsStep::constrainedPart($in);
    assert_contains('"layout":{"type":"constrained"}', $out);
    assert_contains('"className":"header-overlay"', $out, 'existing attributes preserved');
    assert_contains('<div class="wp-block-group header-overlay"><!-- wp:site-title /--></div>', $out, 'body untouched');

    // An attribute-less top-level group gets one too.
    $out = SectionsStep::constrainedPart('<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->');
    assert_contains('"layout":{"type":"constrained"}', $out);
});

test('constrainedPart leaves an explicit layout and non-group markup alone', function () {
    $flex = '<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} --><div class="wp-block-group"></div><!-- /wp:group -->';
    assert_eq($flex, SectionsStep::constrainedPart($flex));

    $cover = '<!-- wp:cover {"align":"full"} --><div class="wp-block-cover"></div><!-- /wp:cover -->';
    assert_eq($cover, SectionsStep::constrainedPart($cover));
});

test('sections writes header AND footer with a constrained layout when the model omits one', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
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
    assert_true(!str_contains($project->readText('theme/parts/section-hero.html'), '"layout"'), 'section untouched');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('markup repairs the var:preset--type--slug typo into the pipe form', function () {
    $in = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset--spacing--xxl","bottom":"var:preset--spacing--md"},"blockGap":"var:preset|spacing|sm"},"typography":{"fontSize":"var:preset--font-size--lead"}}} -->'
        . '<div class="wp-block-group">x</div><!-- /wp:group -->';
    $out = SectionsStep::markup($in, 'section-test');
    assert_contains('"top":"var:preset|spacing|xxl"', $out);
    assert_contains('"bottom":"var:preset|spacing|md"', $out);
    assert_contains('"fontSize":"var:preset|font-size|lead"', $out);
    assert_contains('"blockGap":"var:preset|spacing|sm"', $out, 'valid refs untouched');

    // The serializer-escaped spelling (-- for --) is repaired too,
    // and CSS custom properties (var(--wp--preset--…)) are never touched.
    $escaped = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset\u002d\u002dspacing\u002d\u002dxxl"}}}} -->'
        . '<div style="padding-top:var(--wp--preset--spacing--xxl)">x</div><!-- /wp:group -->';
    $out = SectionsStep::markup($escaped, 'section-test');
    assert_contains('"top":"var:preset|spacing|xxl"', $out);
    assert_contains('var(--wp--preset--spacing--xxl)', $out);
});

test('sections strips a stray markdown code fence from a part response', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
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

test('sections throws when a part has no block markup', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('just text, no blocks'); // hero — invalid
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionsStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections writes nothing when any part is invalid (no partial output)', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // header — valid
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // footer — valid
    $llm->queueText('just text, no blocks');                               // section-hero — invalid
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionsStep($llm, $renderer))->run($project);
    });
    // The valid header/footer must NOT have been written before the bad part threw.
    assert_true(!$project->exists('theme/parts/header.html'), 'no part written when a sibling is invalid');
    assert_true(!$project->exists('theme/parts/footer.html'), 'no part written when a sibling is invalid');
    exec('rm -rf ' . escapeshellarg($tmp));
});
