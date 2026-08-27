<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\IslandPagesStep;
use Automattic\SiteBuild\Steps\SectionsStep;

/**
 * island-pages: the --html-islands page step. Each direct child of the design
 * <main> ships as one core/html island in a transient theme/parts/ file, which
 * assemble-pages then concatenates into the page's post_content — the same
 * hand-off transform-site uses today, with raw HTML in place of block markup.
 *
 * These tests are the ACCEPTANCE CONTRACT for the slice. Written before the
 * implementation exists and FROZEN: the builder makes them pass and does not
 * edit them.
 *
 * MEASURED over the 300 real design pages in projects/*\/design/ (2026-08-27).
 * Every structural case below is drawn from that measurement:
 *
 *   - <main> present in 300/300; 0 files have no <main>
 *   - element-child count per <main>: 1:16  2:4  3:6  4:37  5:98  6:84
 *                                    7:39  8:10  9:3  10:1  14:2
 *   - 16 pages have exactly ONE element child of <main>; 12 of those are a
 *     non-<section> wrapper holding the real sections
 *   - <hr> siblings between sections are real and already ship as their own
 *     units (a real pages.json carries section slugs
 *     section-1,hr-2,section-3,hr-4,... for one about page)
 *   - 4 nested <section> elements
 *   - 0 files with a libxml tag mismatch; ALL emit benign HTML5 notices
 *
 * TWO PLAN AMENDMENTS ARE ENCODED HERE. Both reverse a decision in
 * plan/html-islands-plan.md because measurement contradicted its premise.
 *
 * (1) NO WRAPPER DESCENT (amends 6A).
 *     6A said to descend through non-section wrappers to find the section
 *     layer, on the premise that a naive split "yields 0 islands — page lost".
 *     Measurement says otherwise on both halves:
 *       - TransformSiteStep::extractPage has always islanded every ELEMENT
 *         child of <main>, not every <section> child. A wrapper-only page
 *         yields 1 unit, never 0. No shipped code has the blank-page bug.
 *       - Every one of the 12 wrappers carries the page's content column:
 *         `max-width:var(--wide-size); margin:0 auto; padding:0 <gutter>`.
 *         Two carry more (a continuous `border-left/right` hairline down the
 *         page; a `position:relative; overflow:hidden` radial background).
 *         ZERO carry display:grid or display:flex — 6A's stated hazard does
 *         not occur in the corpus at all, but its stated remedy does damage:
 *         descending re-parents the children and drops the inset, so every
 *         island goes full-bleed. SectionLayoutStep, which re-binds sections
 *         to the content column on the blocks path, is DROPPED on this graph,
 *         so nothing restores it.
 *     Descent trades a granularity gain on 4% of pages for a visual
 *     regression on those same pages. So: split on direct element children,
 *     never descend. A wrapper-only page is one island, intact.
 *
 * (2) NO TAG-STACK BALANCE SCANNER (amends 9B).
 *     9B asked island-pages to assert the tag stack per island and synthesize
 *     missing closers. Islands are serialized out of the DOM, so they are
 *     balanced by construction — a scanner over that output measures nothing
 *     (the same instrument error as calling saveHTML() to detect source
 *     imbalance). The real hazard 9B is reaching for is libxml silently
 *     RE-NESTING a truncated document so later sections become children of an
 *     earlier one. A tag scanner cannot see that; the structural-error check
 *     can. So the balance requirement is expressed here as an output property
 *     plus a truncation case, not as a scanner.
 */

// ---------------------------------------------------------------------------
// fixtures — hand-reduced from the corpus, preserving the exact shape (12A).
// /projects/ is gitignored, so the real files cannot be committed.
// ---------------------------------------------------------------------------

function ip_doc(string $main, string $style = 'body{margin:0}', string $chrome = ''): string
{
    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<title>t</title>\n<style>{$style}</style>\n</head>\n<body>\n"
        . $chrome
        . "<main>\n{$main}\n</main>\n"
        . "</body>\n</html>\n";
}

/** amber-ember2/design/contact.html: <main> holds ONE wrapper carrying the content column. */
function ip_wrapper_page(): string
{
    return ip_doc(
        '<div class="pg-wrap">'
        . '<section id="reach"><h2>Reach us</h2></section>'
        . '<section id="visit"><h2>Visit</h2></section>'
        . '<figure class="map"><img src="m.jpg" alt="map"></figure>'
        . '</div>',
        '.pg-wrap{max-width:var(--wide-size);margin:0 auto;padding:0 20px}',
    );
}

/** amber-ember2/design/about.html: one direct section, then a wrapper holding the rest. */
function ip_mixed_page(): string
{
    return ip_doc(
        '<section class="pagehead"><h1>About</h1></section>'
        . '<div class="wrap">'
        . '<section id="background"><h2>Background</h2></section>'
        . '<section id="method"><h2>Method</h2></section>'
        . '</div>',
    );
}

/** an about page whose sections are separated by rules — ships as hr-2, hr-4 today. */
function ip_rules_page(): string
{
    return ip_doc(
        '<section id="one"><h2>One</h2></section>'
        . '<hr class="rule">'
        . '<section id="two"><h2>Two</h2></section>'
        . '<hr class="rule">'
        . '<section id="three"><h2>Three</h2></section>',
    );
}

/**
 * Build a minimal islands project: siteSpec + design artifacts. Returns the
 * Project. $designs maps page slug => design HTML (or null to omit the file).
 *
 * @param array<string,?string> $designs
 */
function ip_project(object $project, array $designs, array $pageOverrides = []): void
{
    // PagePlanStep::flattenPages() derives `parent` ONLY by walking `children`;
    // a `parent` key on an already-flat page is ignored and comes back null.
    // So a parent relationship must be expressed here as nesting, or the
    // orphan test below passes without the code doing anything.
    $rows = [];
    $order = 0;
    foreach (array_keys($designs) as $slug) {
        $rows[$slug] = array_merge([
            'slug' => $slug,
            'title' => ucfirst($slug),
            'path' => $slug === 'home' ? '/' : "/{$slug}/",
            'front' => $slug === 'home',
            'menu_order' => $order++,
            'purpose' => '',
            'sections' => [],
        ], $pageOverrides[$slug] ?? []);
    }
    $pages = [];
    foreach ($rows as $slug => $row) {
        $parent = $row['parent'] ?? null;
        unset($row['parent']);
        if ($parent !== null && isset($rows[$parent])) {
            $rows[$parent]['children'][] = $row;
            continue;
        }
        $pages[] = &$rows[$slug];
    }
    unset($row);
    $pages = array_values(array_map(static fn ($r) => $r, $pages));
    $project->writeJson('siteSpec.json', ['slug' => 'test-site', 'writing_direction' => 'ltr', 'pages' => $pages]);
    $project->writeJson('meta.json', ['graph' => 'html-islands']);
    $project->writeText('design/site.css', 'body{margin:0}');
    foreach ($designs as $slug => $html) {
        if ($html === null) {
            continue;
        }
        $project->writeText("design/{$slug}.html", $html);
    }
}

/** @return list<string> the part slugs island-pages recorded for a page */
function ip_section_slugs(object $project, string $pageSlug): array
{
    foreach (($project->readJson('pages.json')['pages'] ?? []) as $page) {
        if (($page['slug'] ?? null) === $pageSlug) {
            return array_map(static fn (array $s): string => (string) $s['slug'], (array) ($page['sections'] ?? []));
        }
    }
    return [];
}

function ip_part_text(object $project, string $pageSlug, string $sectionSlug): string
{
    return $project->readText('theme/parts/' . SectionsStep::partSlug($pageSlug, $sectionSlug) . '.html');
}

// ---------------------------------------------------------------------------
// 1. the split (6A as amended)
// ---------------------------------------------------------------------------

test('island-pages: each direct element child of <main> becomes one island', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc(
            '<section id="hero"><h1>Hi</h1></section><section id="cta"><h2>Go</h2></section>'
        )]);
        (new IslandPagesStep())->run($project);
        assert_eq(['hero', 'cta'], ip_section_slugs($project, 'home'), 'one island per element child, in document order');
    });
});

test('island-pages: a wrapper-only <main> yields ONE island holding the wrapper intact', function () {
    // THE HOSTILE-QA CASE. The wrapper carries max-width + margin:0 auto +
    // padding — the page's whole content column. Descending would island its
    // three children and send each full-bleed. One island keeps the column.
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_wrapper_page()]);
        (new IslandPagesStep())->run($project);
        $slugs = ip_section_slugs($project, 'home');
        assert_eq(1, count($slugs), 'a wrapper-only page is exactly one island, never zero and never split');
        $part = ip_part_text($project, 'home', $slugs[0]);
        assert_contains('class="pg-wrap"', $part, 'the wrapper element itself must survive into the island');
        assert_contains('id="reach"', $part, 'wrapper children ride inside the island');
        assert_contains('id="visit"', $part, 'wrapper children ride inside the island');
        assert_contains('<figure', $part, 'a non-section wrapper child is not dropped');
    });
});

test('island-pages: a mixed page islands the direct section and the wrapper separately', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_mixed_page()]);
        (new IslandPagesStep())->run($project);
        $slugs = ip_section_slugs($project, 'home');
        assert_eq(2, count($slugs), 'one direct <section> + one wrapper = 2 islands');
        $second = ip_part_text($project, 'home', $slugs[1]);
        assert_contains('class="wrap"', $second, 'the wrapper is islanded whole, not descended into');
        assert_contains('id="background"', $second, '');
        assert_contains('id="method"', $second, '');
    });
});

test('island-pages: <hr> siblings become their own islands and are never dropped', function () {
    // Real pages.json today carries section-1,hr-2,section-3,hr-4,... Rules
    // between sections are authored furniture; dropping them is silent loss.
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_rules_page()]);
        (new IslandPagesStep())->run($project);
        $slugs = ip_section_slugs($project, 'home');
        assert_eq(5, count($slugs), 'three sections and two rules are five islands');
        assert_eq('hr-2', $slugs[1], 'an id-less element is named <tag>-<position>, matching the shipped convention');
        assert_eq('hr-4', $slugs[3], '');
        assert_contains('<hr', ip_part_text($project, 'home', 'hr-2'), 'the rule survives into its island');
    });
});

test('island-pages: a non-section child is NOT wrapped in a synthetic <section>', function () {
    // extractPage wraps non-sections in <section id=...> because the block
    // transformer needed a section root. Islands must not: design stylesheets
    // carry bare `section{padding:...}` rules, so a synthetic section would
    // give an <hr> section padding the design never authored.
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_rules_page()]);
        (new IslandPagesStep())->run($project);
        $part = ip_part_text($project, 'home', 'hr-2');
        assert_true(!str_contains($part, '<section'), 'no synthetic <section> wrapper around a rule');
    });
});

test('island-pages: nested <section> stays inside its parent island', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc(
            '<section id="outer"><h2>Outer</h2><section id="inner"><h3>Inner</h3></section></section>'
        )]);
        (new IslandPagesStep())->run($project);
        assert_eq(['outer'], ip_section_slugs($project, 'home'), 'only the top-level child is an island');
        assert_contains('id="inner"', ip_part_text($project, 'home', 'outer'), 'the nested section rides inside');
    });
});

test('island-pages: header, footer, style and script children of <main> are not islanded', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc(
            '<style>.x{color:red}</style>'
            . '<section id="hero"><h1>Hi</h1></section>'
            . '<script>window.x=1</script>'
        )]);
        (new IslandPagesStep())->run($project);
        assert_eq(['hero'], ip_section_slugs($project, 'home'), 'only content children island');
    });
});

test('island-pages: duplicate ids are de-duplicated with a numeric suffix', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc(
            '<section id="cta"><h2>A</h2></section><section id="cta"><h2>B</h2></section>'
        )]);
        (new IslandPagesStep())->run($project);
        assert_eq(['cta', 'cta-2'], ip_section_slugs($project, 'home'), 'second use of an id gets -2');
    });
});

test('island-pages: page <main> with no elements but real text is one island', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc('Just a sentence of copy.')]);
        (new IslandPagesStep())->run($project);
        assert_eq(1, count(ip_section_slugs($project, 'home')), 'text-only main still delivers a page');
        assert_contains('Just a sentence', ip_part_text($project, 'home', ip_section_slugs($project, 'home')[0]), '');
    });
});

// ---------------------------------------------------------------------------
// 2. island markup
// ---------------------------------------------------------------------------

test('island-pages: each part is exactly one core/html block', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc('<section id="hero"><h1>Hi</h1></section>')]);
        (new IslandPagesStep())->run($project);
        $part = ip_part_text($project, 'home', 'hero');
        assert_eq(1, substr_count($part, '<!-- wp:html -->'), 'exactly one opening html block delimiter');
        assert_eq(1, substr_count($part, '<!-- /wp:html -->'), 'exactly one closing html block delimiter');
        $trim = trim($part);
        assert_true(str_starts_with($trim, '<!-- wp:html -->'), 'the island\'s only top-level block is core/html');
        assert_true(str_ends_with($trim, '<!-- /wp:html -->'), 'the island closes the core/html wrapper');
        assert_contains('<!-- wp:heading', $part, 'phrasing headings become editable inner blocks');
    });
});

test('island-pages: the <main> wrapper itself is dropped, matching html-first', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc('<section id="hero"><h1>Hi</h1></section>')]);
        (new IslandPagesStep())->run($project);
        assert_true(
            !str_contains(ip_part_text($project, 'home', 'hero'), '<main'),
            'page.html emits bare wp:post-content; a second <main> would nest landmarks',
        );
    });
});

test('island-pages: island markup is tag-balanced', function () {
    // 9B as amended: an unbalanced island would adopt every later island and
    // the footer template part as DOM children. Serializing out of the DOM
    // guarantees balance; this asserts the guarantee holds rather than
    // re-deriving it with a scanner.
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_wrapper_page()]);
        (new IslandPagesStep())->run($project);
        $slugs = ip_section_slugs($project, 'home');
        $inner = trim(str_replace(['<!-- wp:html -->', '<!-- /wp:html -->'], '', ip_part_text($project, 'home', $slugs[0])));
        $prev = libxml_use_internal_errors(true);
        $frag = new DOMDocument();
        $frag->loadHTML('<?xml encoding="UTF-8"><body>' . $inner . '</body>', LIBXML_NONET);
        $mismatch = array_values(array_filter(
            libxml_get_errors(),
            static fn ($e): bool => in_array($e->code, [76, 77], true), // tag mismatch, unexpected end tag
        ));
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        assert_eq(0, count($mismatch), 'island markup must close every element it opens');
    });
});

test('island-pages: UTF-8 survives the round trip without double-encoding', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc('<section id="hero"><h1>Café — naïve “quotes”</h1></section>')]);
        (new IslandPagesStep())->run($project);
        $part = ip_part_text($project, 'home', 'hero');
        assert_contains('Café', $part, 'accented characters survive');
        assert_contains('—', $part, 'em dash survives');
        assert_true(!str_contains($part, 'Ã'), 'no ISO-8859-1 double-encoding');
    });
});

// ---------------------------------------------------------------------------
// 3. sanitization (7A)
// ---------------------------------------------------------------------------

test('island-pages: every island is re-sanitized before delivery', function () {
    // assign-image-sources mutates design/*.html AFTER inner-pages-design
    // sanitized it, so this is the last guard before raw HTML reaches
    // post_content.
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc(
            '<section id="hero"><h1>Hi</h1><script>alert(1)</script></section>'
        )]);
        (new IslandPagesStep())->run($project);
        $part = ip_part_text($project, 'home', 'hero');
        assert_true(!str_contains($part, 'alert(1)'), 'script payload must not reach post_content');
        assert_contains('Hi', $part, 'the surrounding content survives');
    });
});

test('island-pages: hostile markup is stripped in place and the island survives', function () {
    // CORRECTED 2026-08-27, after the contract was frozen. The original test was
    // named "an island whose sanitize fails is dropped" and asserted a drop path.
    // Probing DesignMarkupSanitizer through DesignDocument::sanitizedHtml() shows
    // it NEVER throws on hostile content — a <?php PI, <script>, an on* handler, a
    // javascript: href and an <iframe> all strip cleanly with one warning each and
    // return the surviving markup. So no drop path is reachable from hostile input,
    // and the original test would have passed without testing anything.
    //
    // The catch around sanitizedHtml() is still required — the engine declares
    // \RuntimeException — but an unreachable path is not something a contract can
    // assert, so it is stated in the spec rather than faked in a test.
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc(
            '<section id="ok"><h1>Kept</h1></section>'
            . '<section id="bad"><h2>Also kept</h2>'
            . '<div onclick="alert(1)">Handler</div>'
            . '<a href="javascript:alert(2)">Link</a>'
            . '<iframe src="//evil"></iframe>'
            . '</section>'
        )]);
        (new IslandPagesStep())->run($project);
        $slugs = ip_section_slugs($project, 'home');
        assert_eq(['ok', 'bad'], $slugs, 'both islands ship — stripping is not dropping');
        $bad = ip_part_text($project, 'home', 'bad');
        assert_contains('Also kept', $bad, 'safe content survives');
        assert_contains('Handler', $bad, 'the element survives; only its handler is removed');
        assert_true(!str_contains($bad, 'onclick'), 'the event handler is stripped');
        assert_true(!str_contains($bad, 'javascript:'), 'the javascript: URL is stripped');
        assert_true(!str_contains($bad, '<iframe'), 'the iframe is stripped');
        $warnings = json_encode($project->readJson('warnings.json'));
        assert_contains('island-pages', $warnings, 'every strip is reported under this step');
    });
});

// ---------------------------------------------------------------------------
// 4. parse failure (10A) and missing artifacts (8A)
// ---------------------------------------------------------------------------

test('island-pages: a structurally broken inner page is skipped with a warning', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, [
            'home' => ip_doc('<section id="hero"><h1>Hi</h1></section>'),
            'about' => "<main><section id=\"a\"><h2>A</h2>\n", // truncated: never closed
        ]);
        (new IslandPagesStep())->run($project);
        $slugs = array_column($project->readJson('pages.json')['pages'] ?? [], 'slug');
        assert_true(!in_array('about', $slugs, true), 'a page that cannot be parsed structurally is not delivered');
        assert_contains('about', json_encode($project->readJson('warnings.json')), 'the skip is actionable in warnings');
    });
});

test('island-pages: a missing inner-page artifact skips that page with a warning', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, [
            'home' => ip_doc('<section id="hero"><h1>Hi</h1></section>'),
            'about' => null, // no design/about.html on disk
        ]);
        (new IslandPagesStep())->run($project);
        $slugs = array_column($project->readJson('pages.json')['pages'] ?? [], 'slug');
        assert_eq(['home'], $slugs, 'the page with no artifact is skipped, not fatal');
        assert_contains('about', json_encode($project->readJson('warnings.json')), '');
    });
});

test('island-pages: a .failed marker skips that page', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, [
            'home' => ip_doc('<section id="hero"><h1>Hi</h1></section>'),
            'about' => ip_doc('<section id="a"><h2>A</h2></section>'),
        ]);
        $project->writeText('design/about.failed', 'generation failed');
        (new IslandPagesStep())->run($project);
        assert_eq(['home'], array_column($project->readJson('pages.json')['pages'] ?? [], 'slug'), '');
    });
});

test('island-pages: a missing FRONT page artifact is fatal', function () {
    // The templates and the seeder depend on the front page; a site with no
    // home page is not a degraded build, it is a broken one.
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => null]);
        $error = assert_throws(fn () => (new IslandPagesStep())->run($project), 'a missing front-page design must abort');
        assert_true($error instanceof \RuntimeException, 'a deliberate build abort, not an incidental Error');
        assert_contains('home', $error->getMessage(), 'the abort must name the page it could not deliver');
    });
});

test('island-pages: a structurally broken FRONT page is fatal', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => "<main><section id=\"a\"><h2>A</h2>\n"]);
        $error = assert_throws(fn () => (new IslandPagesStep())->run($project), '');
        assert_true($error instanceof \RuntimeException, 'a deliberate build abort, not an incidental Error');
        assert_contains('home', $error->getMessage(), 'the abort must name the page it could not deliver');
    });
});

test('island-pages: a skipped page named as a parent does not orphan its child', function () {
    with_project('island-pages', function ($project) {
        ip_project(
            $project,
            [
                'home' => ip_doc('<section id="hero"><h1>Hi</h1></section>'),
                'about' => null,
                'team' => ip_doc('<section id="t"><h2>Team</h2></section>'),
            ],
            ['team' => ['parent' => 'about']],
        );
        (new IslandPagesStep())->run($project);
        $pages = $project->readJson('pages.json')['pages'] ?? [];
        $team = null;
        foreach ($pages as $page) {
            if (($page['slug'] ?? null) === 'team') {
                $team = $page;
            }
        }
        assert_true($team !== null, 'the child page still ships');
        // Guard the guard: if flattenPages never populated the parent, this test
        // would pass without the step clearing anything. Prove the relationship
        // exists in the input before asserting the output cleared it.
        $flat = \Automattic\SiteBuild\Steps\PagePlanStep::flattenPages($project->readJson('siteSpec.json'));
        $inputParent = null;
        foreach ($flat as $row) {
            if (($row['slug'] ?? null) === 'team') { $inputParent = $row['parent'] ?? null; }
        }
        assert_eq('about', $inputParent, 'the fixture must actually give team a parent, or this test is vacuous');
        assert_true(($team['parent'] ?? null) === null, 'its skipped parent is cleared rather than dangling');
    });
});

// ---------------------------------------------------------------------------
// 5. pages.json and the hand-off to assemble-pages
// ---------------------------------------------------------------------------

test('island-pages: pages.json keeps siteSpec page order and identity fields', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, [
            'home' => ip_doc('<section id="hero"><h1>Hi</h1></section>'),
            'about' => ip_doc('<section id="a"><h2>A</h2></section>'),
            'contact' => ip_doc('<section id="c"><h2>C</h2></section>'),
        ]);
        (new IslandPagesStep())->run($project);
        $pages = $project->readJson('pages.json')['pages'] ?? [];
        assert_eq(['home', 'about', 'contact'], array_column($pages, 'slug'), '');
        assert_true((bool) ($pages[0]['front'] ?? false), 'the front flag carries through');
        assert_eq('/about/', (string) ($pages[1]['path'] ?? ''), 'the path carries through for nav resolution');
        assert_eq('About', (string) ($pages[1]['title'] ?? ''), '');
    });
});

test('island-pages: every section slug in pages.json has a part file on disk', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_rules_page()]);
        (new IslandPagesStep())->run($project);
        foreach (ip_section_slugs($project, 'home') as $slug) {
            $rel = 'theme/parts/' . SectionsStep::partSlug('home', $slug) . '.html';
            assert_true($project->exists($rel), "pages.json promises {$rel}; assemble-pages will warn if it is absent");
        }
    });
});

test('island-pages then assemble-pages delivers every section, in order, in post_content', function () {
    // The end-to-end acceptance of the slice: what the visitor actually gets.
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc(
            '<section id="hero"><h1>First</h1></section>'
            . '<hr class="rule">'
            . '<section id="cta"><h2>Last</h2></section>'
        )]);
        $project->writeJson('theme/theme.json', valid_theme_payload());
        $project->writeText('theme/parts/header.html', "<!-- wp:group --><div class=\"wp-block-group\"></div><!-- /wp:group -->\n");
        $project->writeText('theme/parts/footer.html', "<!-- wp:group --><div class=\"wp-block-group\"></div><!-- /wp:group -->\n");
        (new IslandPagesStep())->run($project);
        quietly(fn () => (new AssemblePagesStep())->run($project));
        $delivered = $project->readText('plugin/pages/home.html');
        assert_contains('First', $delivered, '');
        assert_contains('Last', $delivered, '');
        assert_contains('<hr', $delivered, 'the rule between them survives to delivery');
        assert_true(
            strpos($delivered, 'First') < strpos($delivered, 'Last'),
            'islands are concatenated in document order',
        );
        assert_eq(3, substr_count($delivered, '<!-- wp:html -->'), 'three islands, three core/html blocks');
    });
});

// ---------------------------------------------------------------------------
// 6. island-report.json (13A)
// ---------------------------------------------------------------------------

test('island-pages: island-report.json records per-page island counts', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, [
            'home' => ip_rules_page(),
            'contact' => ip_wrapper_page(),
        ]);
        (new IslandPagesStep())->run($project);
        assert_true($project->exists('island-report.json'), 'the graph must not ship with less visibility than the one it replaces');
        $report = $project->readJson('island-report.json');
        $byPage = [];
        foreach ((array) ($report['pages'] ?? []) as $row) {
            $byPage[(string) ($row['slug'] ?? '')] = $row;
        }
        assert_eq(5, (int) ($byPage['home']['islands'] ?? 0), 'home islands its three sections and two rules');
        assert_eq(1, (int) ($byPage['contact']['islands'] ?? 0), 'the wrapper page degrades to a single island');
        assert_eq(2, (int) ($byPage['home']['non_section_islands'] ?? -1), 'the two rules are counted as non-section islands');
    });
});

test('island-pages: island-report.json records skipped pages and their reason', function () {
    with_project('island-pages', function ($project) {
        ip_project($project, [
            'home' => ip_doc('<section id="hero"><h1>Hi</h1></section>'),
            'about' => null,
        ]);
        (new IslandPagesStep())->run($project);
        $report = $project->readJson('island-report.json');
        $skipped = (array) ($report['skipped'] ?? []);
        assert_eq(1, count($skipped), 'exactly one page was skipped');
        assert_eq('about', (string) ($skipped[0]['slug'] ?? ''), '');
        assert_true(($skipped[0]['reason'] ?? '') !== '', 'a skip with no reason is not observable');
    });
});

// ---------------------------------------------------------------------------
// 7. declaration
// ---------------------------------------------------------------------------

test('island-pages: declares the artifacts it reads and writes', function () {
    $declaration = (new IslandPagesStep())->declaration();
    assert_eq('island-pages', $declaration->id, '');
    assert_eq('island-pages', (new IslandPagesStep())->id(), 'id() and the declaration must agree');
    $writes = $declaration->writes;
    foreach (['pages.json', 'island-report.json', 'warnings.json'] as $artifact) {
        assert_true(in_array($artifact, $writes, true), "declaration must publish {$artifact}");
    }
    assert_true(in_array('theme/parts/*', $writes, true), 'the transient island parts are declared');
    assert_true(in_array('siteSpec.json', $declaration->reads, true), 'the page list source is declared');
});

test('island-pages: does not write aboveFold.json', function () {
    // The above-fold contract is derived in its own slice; a half-built
    // contract here would satisfy StepGraph::validate while failing
    // AboveFoldContract::assertContract at the consumer.
    with_project('island-pages', function ($project) {
        ip_project($project, ['home' => ip_doc('<section id="hero"><h1>Hi</h1></section>')]);
        (new IslandPagesStep())->run($project);
        assert_true(!$project->exists('aboveFold.json'), 'aboveFold.json belongs to the next slice');
    });
});
