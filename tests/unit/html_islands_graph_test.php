<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\AboveFoldPartFacts;
use Automattic\SiteBuild\Graph;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\Steps\HeaderHeroStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * The --html-islands graph: above-fold derivation plus assembly.
 *
 * ACCEPTANCE CONTRACT. Written before the implementation exists and FROZEN:
 * make it pass, do not edit it. If a test is wrong, STOP and raise it — the
 * architect has been wrong four times across this run and every one was caught
 * this way.
 *
 * These two halves ship together because neither works alone: StepGraph::validate()
 * refuses a composition whose steps read an artifact nobody produces, so the graph
 * cannot be wired until something produces aboveFold.json, and that producer has
 * no consumer until the graph exists.
 *
 * ── WHAT THE ABOVE-FOLD HALF ACTUALLY IS ──────────────────────────────────────
 *
 * NOT a second contract implementation. `AboveFoldContract::resolve()` builds the
 * entire contract from siteSpec, the hero blueprint, theme.json, the canvas and
 * the design CSS — it reads NO markup. Only `AboveFoldPartFacts::inspect()` reads
 * markup, and two of its eight facts already work here unchanged:
 *   part_keys — filenames
 *   header    — parses theme/parts/header.html, which transform-chrome delivers
 *               as real block markup
 * So: reuse resolve() and finalizeDelivery() verbatim; supply an islands facts
 * adapter for the other five.
 *
 * ── THE SILENT REGRESSION THIS EXISTS TO PREVENT ──────────────────────────────
 *
 * `AboveFoldPartFacts::supportsOverlay()` returns false unless the part's
 * top-level block is a `group`. An island's top-level block is `html`, so it
 * returns false for EVERY island, and `finalizeDelivery()` then degrades
 * header.mode overlay→stacked. That path is designed to be routine, so it
 * degrades silently. Untouched, every islands build ships a stacked header
 * regardless of what the design authored. `heroFacts()['root_group']` fails the
 * same way.
 *
 * MEASURED, so the adapter has verified ground to stand on (2026-08-27):
 *   - `CssSelectorMatcher::parse()` / `::matches(DOMElement, array)` resolves
 *     design selectors against design elements. Probed on the first 12 real
 *     home.html openings: 12/12 resolved a background declaration, 0 inherited.
 *   - Those values are NOT plain hexes: 8 `var(--token)`, 2 `linear-gradient()`,
 *     1 literal hex, 1 `transparent`. Custom properties MUST be resolved.
 *     `CssTokenExtractor` does NOT do this — verified to return empty
 *     palette/fonts/spacing for a plain `:root{--base:#080809}` sheet.
 *   - `AboveFoldPartFacts::anchorsIn()` already regex-scans raw `id="..."`, so
 *     anchor destinations inside islands ALREADY resolve. Pinned below.
 *   - `pages.json` on this graph carries only {slug,title} per section, so
 *     `openings[].surface` defaults to 'base' for every page. Do NOT trust it —
 *     resolve the real surface from the design CSS.
 *
 * ── THE THREE MILESTONE-4 QUESTIONS, ALREADY ANSWERED BY MEASUREMENT ──────────
 *
 *   normalize-layout  RETAIN unchanged. FixBlocksStep::normalizeLayouts is a
 *                     byte-for-byte no-op on an island and stamps layout on
 *                     chrome in the same run; StorefrontDegrade works on raw
 *                     anchors and relabelled both CTAs in an island.
 *   section-rhythm    DROP. It does not change an island but records one
 *                     degradation per page ("section 'hero' must contain exactly
 *                     one top-level wp:group") — a non-actionable warning per
 *                     page per build.
 *   header-hero       PORT the hero-echo dedupe. Measured with a control:
 *                     block hero → echo removed, 1 note, 1 warning; island hero
 *                     → echo SURVIVES, 0 notes, 0 warnings.
 */

function hi_graph_ids(StepComposition $composition): array
{
    return array_map(static fn ($s): string => $s->id(), $composition->steps());
}

function hi_clear_overlay(): void
{
    putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV);
}

/** StepComposition exposes one builder per graph; there is no forGraph(). */
function hi_composition(string $graph, FakeLlm $llm, PromptRenderer $renderer): StepComposition
{
    return match ($graph) {
        Graph::HTML_ISLANDS => StepComposition::htmlIslands($llm, $renderer),
        Graph::HTML_FIRST => StepComposition::htmlFirst($llm, $renderer),
        Graph::BLOCKS => StepComposition::blocks($llm, $renderer),
        default => throw new \InvalidArgumentException("unknown graph {$graph}"),
    };
}

/**
 * A minimal islands project at the moment island-above-fold runs: chrome parts
 * delivered by transform-chrome, island parts and pages.json by island-pages.
 *
 * The overlay fixture is not invented. Probed across every recipe x canvas x
 * archetype: exactly four combinations make resolve() choose overlay, and all
 * four need forcedHeaderArchetype 'minimal-overlay' with recipe
 * 'cinematic-safe-zone' or 'layered-poster'. They resolve protection_token
 * 'base' and opening surface 'image'.
 *
 * $opts:
 *   overlay            bool  force the overlay-capable header archetype
 *   opening_protected  bool  paint the opening island the protection token
 *   use_var            bool  paint it via var(--base) instead of a literal hex
 *   anchor_target      string an id to place inside a later island
 */
function hi_seed_islands_project(object $project, array $opts = []): void
{
    $overlay = (bool) ($opts['overlay'] ?? false);
    $protected = (bool) ($opts['opening_protected'] ?? true);
    $useVar = (bool) ($opts['use_var'] ?? false);
    $anchor = (string) ($opts['anchor_target'] ?? '');

    $protectionHex = '#080809';
    $paint = $useVar ? 'var(--base)' : $protectionHex;
    $openingBg = $protected ? $paint : '#FF00AA';

    $css = ":root{--base:{$protectionHex};--contrast:#FFFFFF}\n"
        . "body{margin:0;background:var(--base);color:var(--contrast)}\n"
        . "#hero{background:{$openingBg};position:relative}\n"
        . "#hero img{width:100%;display:block}\n"
        . "#hero::after{content:'';position:absolute;inset:0;background:rgba(8,8,9,.72)}\n";

    $anchorSection = $anchor !== ''
        ? "<section id=\"{$anchor}\"><h2>Pricing</h2></section>"
        : '<section id="cta"><h2>Get in touch</h2></section>';

    $project->writeJson('meta.json', ['graph' => 'html-islands']);
    $project->writeJson('siteSpec.json', [
        'slug' => 'unit-islands',
        'name' => 'Unit Islands',
        'language' => 'English',
        'writing_direction' => 'ltr',
        'pages' => [['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome']],
    ]);
    seed_test_design_direction($project, 'cinematic-safe-zone');
    $project->writeJson('theme/theme.json', [
        'version' => 3,
        'settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => $protectionHex, 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#FFFFFF', 'name' => 'Contrast'],
        ]]],
    ]);
    $project->writeText('design/site.css', $css);
    $project->writeText('design/home.html',
        "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>t</title>"
        . "<style>{$css}</style></head><body>"
        . '<header><p>Unit Islands</p></header>'
        . '<main>'
        . '<section id="hero"><img src="theme:./assets/h.jpg" alt="h"><h1>Build what lasts</h1></section>'
        . $anchorSection
        . '</main>'
        . '<footer><p>Closing</p></footer>'
        . '</body></html>');

    $openingSlug = 'hero';
    $secondSlug = $anchor !== '' ? $anchor : 'cta';
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
        'parent' => null, 'menu_order' => 0, 'purpose' => '',
        // island-pages writes {slug,title} only — no layout_archetype, no background.
        'sections' => [
            ['slug' => $openingSlug, 'title' => 'Build what lasts'],
            ['slug' => $secondSlug, 'title' => 'Get in touch'],
        ],
    ]]]);

    $island = static fn (string $inner): string => "<!-- wp:html -->\n{$inner}\n<!-- /wp:html -->\n";
    $project->writeText("theme/parts/page-home--{$openingSlug}.html",
        $island('<section id="hero"><img src="./assets/h.jpg" alt="h"><h1>Build what lasts</h1></section>'));
    $project->writeText("theme/parts/page-home--{$secondSlug}.html",
        $island("<section id=\"{$secondSlug}\"><h2>Get in touch</h2></section>"));
    $project->writeText('theme/parts/header.html',
        '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
        . '<!-- wp:site-title /--></header><!-- /wp:group -->' . "\n");
    $project->writeText('theme/parts/footer.html',
        '<!-- wp:group {"tagName":"footer"} --><footer class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Closing</p><!-- /wp:paragraph --></footer><!-- /wp:group -->' . "\n");

    // Env::get() reads getenv() first; there is no Env::set(). This mirrors how
    // design_direction_test.php drives the same override. Callers MUST clear it —
    // hi_clear_overlay() below, in a finally, or the next test inherits it.
    if ($overlay) {
        putenv(AboveFoldContract::HEADER_ARCHETYPE_ENV . '=minimal-overlay');
    }
}

// ---------------------------------------------------------------------------
// 1. the graph assembles and validates
// ---------------------------------------------------------------------------

test('html-islands: the composition constructs and passes graph validation', function () {
    // StepGraph::validate() runs on construction and refuses a composition whose
    // steps read an artifact no earlier step writes. This is the single strongest
    // assertion in the file: it proves every retained consumer still has a producer
    // after transform-site was split in three.
    $composition = StepComposition::htmlIslands(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    assert_true($composition instanceof StepComposition, 'the islands graph must construct');
    assert_true(count(hi_graph_ids($composition)) > 10, 'and it must be a real pipeline, not a stub');
});

test('html-islands: the graph contains the three new steps and none of the retired ones', function () {
    $ids = hi_graph_ids(StepComposition::htmlIslands(new FakeLlm(), new PromptRenderer(repo_path('prompts'))));
    foreach (['transform-chrome', 'island-pages', 'island-above-fold'] as $id) {
        assert_true(in_array($id, $ids, true), "the islands graph must run {$id}");
    }
    foreach (['transform-site', 'section-layout', 'fix-pages'] as $id) {
        assert_true(!in_array($id, $ids, true), "{$id} is html-first only and must not run here");
    }
    // Measured: section-rhythm records one non-actionable degradation per page on
    // islands. extract-patterns fails eligibility on every island section.
    assert_true(!in_array('section-rhythm', $ids, true), 'section-rhythm warns once per page on islands');
    assert_true(!in_array('extract-patterns', $ids, true), 'every island section fails pattern eligibility');
    // Measured no-op on islands, real work on chrome — retained.
    assert_true(in_array('normalize-layout', $ids, true), 'normalize-layout still does chrome work');
    assert_true(in_array('header-hero', $ids, true), 'sole producer of headerBehavior.json');
    assert_true(in_array('assemble-pages', $ids, true), '');
    assert_true(in_array('page-styles', $ids, true), '');
    assert_true(in_array('validate-theme', $ids, true), '');
});

test('html-islands: island-pages runs before assemble-pages, and chrome before above-fold', function () {
    $ids = hi_graph_ids(StepComposition::htmlIslands(new FakeLlm(), new PromptRenderer(repo_path('prompts'))));
    $at = static fn (string $id): int => (int) array_search($id, $ids, true);
    assert_true($at('island-pages') < $at('assemble-pages'), 'parts must exist before they are concatenated');
    assert_true($at('transform-chrome') < $at('island-above-fold'), 'header facts are read from the delivered chrome part');
    assert_true($at('island-pages') < $at('island-above-fold'), 'the contract reads pages.json');
    assert_true($at('island-above-fold') < $at('header-hero'), 'header-hero consumes aboveFold.json');
});

test('html-islands: the other two graphs are unchanged', function () {
    $llm = new FakeLlm();
    $renderer = new PromptRenderer(repo_path('prompts'));
    foreach ([Graph::BLOCKS, Graph::HTML_FIRST] as $graph) {
        $ids = hi_graph_ids(hi_composition($graph, $llm, $renderer));
        assert_true(!in_array('island-pages', $ids, true), "{$graph} must not gain island-pages");
        assert_true(!in_array('transform-chrome', $ids, true), "{$graph} must not gain transform-chrome");
    }
    $htmlFirst = hi_graph_ids(hi_composition(Graph::HTML_FIRST, $llm, $renderer));
    assert_true(in_array('transform-site', $htmlFirst, true), 'html-first keeps transform-site until milestone 6');
    assert_true(in_array('section-rhythm', $htmlFirst, true), 'and keeps section-rhythm');
});

// ---------------------------------------------------------------------------
// 2. postImages() and RES-1
// ---------------------------------------------------------------------------

test('html-islands: postImages drops extract-patterns for islands and keeps it elsewhere', function () {
    // Every island section fails pattern eligibility, producing one
    // non-actionable warning per section per build — AGENTS.md rung 2.
    $step = new \Automattic\SiteBuild\Steps\ThemeScreenshotStep();
    $islands = array_map(
        static fn ($s): string => $s->id(),
        StepComposition::postImages($step, null, Graph::HTML_ISLANDS),
    );
    assert_true(!in_array('extract-patterns', $islands, true), 'islands post-image list drops extract-patterns');
    assert_true(in_array('cover-contrast', $islands, true), 'cover-contrast stays — it still works on the chrome parts');
    assert_true(in_array('validate-theme', $islands, true), '');
    foreach ([Graph::BLOCKS, Graph::HTML_FIRST] as $graph) {
        $ids = array_map(static fn ($s): string => $s->id(), StepComposition::postImages($step, null, $graph));
        assert_true(in_array('extract-patterns', $ids, true), "{$graph} keeps extract-patterns");
    }
});

test('html-islands: RES-1 — the graph stays a string end to end, never a boolean', function () {
    // bin/images.php resolved the graph correctly and then flattened it with
    // `=== GRAPH_HTML_FIRST`, so an islands project was treated as blocks: the
    // collector stopped reading prose alts as image subjects. Its fallback
    // heuristic failed the same way, guessing blocks whenever
    // design/transform-report.json was absent — which this graph never writes.
    $reflection = new ReflectionMethod(StepComposition::class, 'postImages');
    $graphParam = null;
    foreach ($reflection->getParameters() as $parameter) {
        if (in_array($parameter->getName(), ['graph', 'htmlFirst'], true)) {
            $graphParam = $parameter;
        }
    }
    assert_true($graphParam !== null, 'postImages must take a graph');
    assert_eq('graph', $graphParam->getName(), 'postImages takes a graph name, not a htmlFirst boolean');
    $type = (string) $graphParam->getType();
    assert_true(str_contains($type, 'string'), "postImages graph parameter must be a string, got {$type}");
});

// ---------------------------------------------------------------------------
// 3. the hero-echo port
// ---------------------------------------------------------------------------

test('html-islands: hero-echo dedupe works on an ISLAND hero, and still on a block hero', function () {
    // Both directions in one test on purpose. Narrowing a matcher to fix one
    // side has silently broken the other on this project before.
    $header = '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
        . '<!-- wp:heading --><h2>Build what lasts</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Plymouth, NH</p><!-- /wp:paragraph -->'
        . '</header><!-- /wp:group -->';
    $blockHero = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":1} --><h1>Build what lasts</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Plymouth, NH</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $islandHero = "<!-- wp:html -->\n"
        . '<section id="hero"><h1>Build what lasts</h1><p>Plymouth, NH</p></section>'
        . "\n<!-- /wp:html -->";

    $block = HeaderHeroStep::dedupeAgainstHero($header, $blockHero, null, '');
    assert_true($block['markup'] !== $header, 'CONTROL: the block path must still remove the echo');

    $island = HeaderHeroStep::dedupeAgainstHero($header, $islandHero, null, '');
    assert_true(
        $island['markup'] !== $header,
        'an island hero must yield hero lines too — otherwise a header repeating the '
        . 'hero headline ships with zero notes and zero warnings',
    );
    assert_true(
        !str_contains($island['markup'], 'Build what lasts'),
        'the duplicated headline is what gets removed',
    );
    assert_true(count($island['notes'] ?? []) > 0, 'and the removal is reported, not silent');
});

test('html-islands: hero-echo dedupe leaves unrelated header text alone', function () {
    // The false-positive direction. Only short lines are echo candidates; a
    // header line the hero never said must survive.
    $header = '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
        . '<!-- wp:paragraph --><p>KEEP-THIS-UNRELATED-LINE</p><!-- /wp:paragraph -->'
        . '</header><!-- /wp:group -->';
    $islandHero = "<!-- wp:html -->\n<section id=\"hero\"><h1>Something else entirely</h1></section>\n<!-- /wp:html -->";
    $result = HeaderHeroStep::dedupeAgainstHero($header, $islandHero, null, '');
    assert_contains('KEEP-THIS-UNRELATED-LINE', $result['markup'], 'unrelated header copy must survive');
});

// ---------------------------------------------------------------------------
// 4. aboveFold.json
// ---------------------------------------------------------------------------

test('island-above-fold: writes a final-phase contract that survives assertContract', function () {
    // Three retained consumers call this. ThemeValidator:130 calls it "a required
    // upstream artifact"; AboveFoldContract throws on a missing version, an invalid
    // phase, an absent header contract or an unknown recipe.
    with_project('island-above-fold', function ($project) {
        hi_seed_islands_project($project);
        (new \Automattic\SiteBuild\Steps\IslandAboveFoldStep())->run($project);
        assert_true($project->exists('aboveFold.json'), 'the artifact is mandatory');
        $contract = $project->readJson('aboveFold.json');
        AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_FINAL);
        assert_eq(AboveFoldContract::VERSION, $contract['version'] ?? null, '');
        assert_true(is_array($contract['header'] ?? null), 'the header contract must be present');
        assert_true(trim((string) ($contract['hero_part'] ?? '')) !== '', 'hero_part must name a real part');
    });
});

test('island-above-fold: header facts match AboveFoldPartFacts::headerFacts exactly', function () {
    // The chrome is real block markup, so the existing reader is correct and must
    // be reused rather than reimplemented. A drifting reimplementation is the
    // defect this catches.
    with_project('island-above-fold', function ($project) {
        hi_seed_islands_project($project);
        (new \Automattic\SiteBuild\Steps\IslandAboveFoldStep())->run($project);
        $expected = AboveFoldPartFacts::headerFacts($project->readText('theme/parts/header.html'));
        $contract = $project->readJson('aboveFold.json');
        assert_true(is_array($expected), '');
        assert_eq(
            $expected['archetype'] ?? null,
            $contract['header']['archetype'] ?? null,
            'the delivered header archetype must come from the real header reader',
        );
    });
});

test('island-above-fold: an opening painted the protection token KEEPS the overlay header', function () {
    with_project('island-above-fold', function ($project) {
        hi_seed_islands_project($project, ['overlay' => true, 'opening_protected' => true]);
        (new \Automattic\SiteBuild\Steps\IslandAboveFoldStep())->run($project);
        $contract = $project->readJson('aboveFold.json');
        assert_eq(
            AboveFoldContract::MODE_OVERLAY,
            $contract['header']['mode'] ?? null,
            'a protected island opening must not lose the authored overlay header',
        );
        $codes = array_map(
            static fn (array $d): string => (string) ($d['code'] ?? ''),
            (array) ($contract['degradations'] ?? []),
        );
        assert_true(!in_array('overlay-support-lost', $codes, true), 'and must record no overlay loss');
    });
});

test('island-above-fold: an unprotected opening DEGRADES to stacked, with the reason recorded', function () {
    // The matched pair for the test above. Both directions or neither.
    with_project('island-above-fold', function ($project) {
        hi_seed_islands_project($project, ['overlay' => true, 'opening_protected' => false]);
        (new \Automattic\SiteBuild\Steps\IslandAboveFoldStep())->run($project);
        $contract = $project->readJson('aboveFold.json');
        assert_eq(
            AboveFoldContract::MODE_STACKED,
            $contract['header']['mode'] ?? null,
            'an unprotected opening must degrade rather than ship an unreadable header',
        );
        $report = json_encode($project->readJson('island-report.json'));
        assert_contains('overlay', $report, 'and the degrade must be visible in island-report.json');
    });
});

test('island-above-fold: a var(--token) opening background resolves before comparison', function () {
    // 8 of 12 probed real openings paint with var(). An adapter comparing the
    // literal string "var(--base)" to a hex reports every real design as
    // unprotected and degrades all of them.
    with_project('island-above-fold', function ($project) {
        hi_seed_islands_project($project, ['overlay' => true, 'opening_protected' => true, 'use_var' => true]);
        (new \Automattic\SiteBuild\Steps\IslandAboveFoldStep())->run($project);
        assert_eq(
            AboveFoldContract::MODE_OVERLAY,
            $project->readJson('aboveFold.json')['header']['mode'] ?? null,
            'a var() reference to the protection token counts as protected',
        );
    });
});

test('island-above-fold: an anchor destination inside an island resolves', function () {
    // anchorsIn() already regex-scans raw id= attributes, so this works today.
    // Pinned so a rewrite cannot silently lose it.
    with_project('island-above-fold', function ($project) {
        hi_seed_islands_project($project, ['anchor_target' => 'pricing']);
        (new \Automattic\SiteBuild\Steps\IslandAboveFoldStep())->run($project);
        $contract = $project->readJson('aboveFold.json');
        $codes = array_map(
            static fn (array $d): string => (string) ($d['code'] ?? ''),
            (array) ($contract['degradations'] ?? []),
        );
        assert_true(
            !in_array('primary-action-undelivered', $codes, true),
            'an anchor that exists as an id inside an island is a delivered destination',
        );
    });
});

test('island-above-fold: a well-formed island hero records no permanent degradation', function () {
    // heroFacts()['root_group'] is false for every island because the top-level
    // block is `html`. Reporting that on every build is a non-actionable warning
    // per build — AGENTS.md rung 2.
    with_project('island-above-fold', function ($project) {
        hi_seed_islands_project($project);
        (new \Automattic\SiteBuild\Steps\IslandAboveFoldStep())->run($project);
        $codes = array_map(
            static fn (array $d): string => (string) ($d['code'] ?? ''),
            (array) ($project->readJson('aboveFold.json')['degradations'] ?? []),
        );
        foreach ($codes as $code) {
            assert_true(
                !str_contains($code, 'root-group') && !str_contains($code, 'hero-shape'),
                "a well-formed island hero must not be reported as malformed (got {$code})",
            );
        }
    });
});

test('island-above-fold: declares the artifacts it reads and writes', function () {
    $declaration = (new \Automattic\SiteBuild\Steps\IslandAboveFoldStep())->declaration();
    assert_eq('island-above-fold', $declaration->id, '');
    assert_true(in_array('aboveFold.json', $declaration->writes, true), '');
    assert_true(in_array('pages.json', $declaration->reads, true), '');
    assert_true(in_array('theme/parts/header.html', $declaration->reads, true), 'header facts come from the chrome part');
});

// ---------------------------------------------------------------------------
// 5. the kses comment
// ---------------------------------------------------------------------------

test('html-islands: the kses-suspension comment describes the real mechanism', function () {
    // The suspension stays correct; its stated reason does not. It justifies
    // itself as "kses would mangle its block comments", but on this graph the
    // payload is raw section HTML whose inline style attributes and semantic
    // elements kses would strip. A security decision documented by a wrong
    // reason is worse than one documented by none.
    $source = file_get_contents(repo_path('src/Steps/ScaffoldPluginStep.php'));
    assert_true(is_string($source), '');
    assert_true(
        !preg_match('/kses would mangle its block comments/i', $source),
        'the old justification must be replaced, not merely supplemented',
    );
    assert_true(
        preg_match('/kses/i', $source) === 1,
        'the suspension must still be explained — removing the comment is not the fix',
    );
});
