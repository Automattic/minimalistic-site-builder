# Multi-Page Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate a whole multi-page site: the site spec carries a tree of pages, every page gets its own planned + generated sections, and all page content (homepage included) ships as a deterministic companion plugin that seeds the pages on activation and removes them on deactivation.

**Architecture:** The spec gains a `pages` tree. The section-plan step becomes a per-page fan-out (`page-plan`, output `pages.json`); the sections step generates every page's sections in one concurrent batch as transient theme parts; after fix-blocks, a deterministic `assemble-pages` step inlines the fixed section markup into `plugin/pages/<slug>.html`, writes the plugin manifest, writes the theme's `page.html`/`index.html` templates (front-page.html is gone — the seeded homepage + `page_on_front` replaces it), and deletes the transient parts. A deterministic scaffold step writes the content-seeder plugin (static PHP with `{{identity}}` placeholders filled by apply-identity). The Playground blueprint installs and activates both theme and plugin.

**Tech Stack:** Plain PHP (no deps), existing Step/ConcurrentStep/Pipeline machinery, existing Node block-fixer, WordPress block themes + a classic activation/deactivation-hook plugin.

## Global Constraints

- Green field: no backwards compatibility, no migration paths (AGENTS.md).
- Four-space indentation everywhere.
- Zero PHP dependencies; files-on-disk are the interface between steps.
- The companion plugin is deterministic — no LLM involvement; identical code for every site except filled `{{THEME_NAME}}`/`{{THEME_SLUG}}`/`{{DESCRIPTION}}` placeholders.
- Content markup keeps `theme:./assets/...` srcs at build time; the plugin rewrites them to the active theme's URL at activation (build-time image rewriting stays theme-only).
- All copy language rules flow from `siteSpec.language` exactly as today.
- Tests: zero-dependency harness (`tests/lib.php`), run `php tests/run.php`; integration `php tests/run-integration.php`.

## Locked interfaces (used across tasks)

- `siteSpec.json.pages`: tree `[{title, slug, purpose, children: [...]}]`, first entry = homepage.
- `pages.json` (written by page-plan): `{"pages":[{slug, title, path, front(bool), parent(string|null), menu_order(int), purpose, sections:[<section brief as today>]}]}` — FLAT list, parents before children, display order.
- Transient part filename: `theme/parts/page-<pageSlug>--<sectionSlug>.html` via `SectionsStep::partSlug($pageSlug, $sectionSlug)` = `"page-{$pageSlug}--{$sectionSlug}"`.
- Plugin layout: `plugin/site-content.php` (main file, scaffolded), `plugin/pages.json` (manifest `{"pages":[{slug,title,front,menu_order,parent}]}`), `plugin/pages/<slug>.html` (assembled).
- `Project::pluginPath(string $rel = ''): string` — like `themePath()`, rooted at `plugin/`.
- Step ids: `scaffold-plugin`, `page-plan` (replaces `section-plan`), `assemble-pages` (replaces `assemble-landing-page`).
- Pipeline order: scaffold-theme, scaffold-plugin, refine-prompt, site-spec, apply-identity, design-direction, [theme-json+page-plan], sections, collect-images, fix-blocks, assemble-pages, page-styles, fonts-php, finalize-theme.

---

### Task 1: siteSpec pages tree

**Files:**
- Modify: `prompts/site-spec.md`
- Modify: `src/Steps/SiteSpecStep.php`
- Test: `tests/unit/site_spec_test.php`

**Interfaces:**
- Produces: `siteSpec.json.pages` (tree, above), `SiteSpecStep::normalizePages(mixed $raw, array $spec): array` (public static, pure).

- [ ] Add to `prompts/site-spec.md` fixed properties (after `sections`):

```
  "pages": [                // the site's page tree — the FIRST page is the homepage. 1-6 top-level pages; nest "children" ONLY where the site genuinely needs a second level (max depth 2). A one-page site (e.g. a simple landing page) is just the homepage entry.
    {
      "title": string,      // short, nav-friendly title in the site's `language` (the homepage is usually that language's word for "Home")
      "slug": string,       // lowercase a-z 0-9 hyphens, unique across the WHOLE tree ("home" for the homepage)
      "purpose": string,    // 1 sentence: what this page is for and what content it holds
      "children": []        // sub-pages, same shape; [] when none
    }
  ]
```

Plus a paragraph: pages are factual scope decisions (what the site covers), grounded in site_type/area — e.g. restaurant → home, menu, about, visit; portfolio → home, work, about, contact. `sections` stays: it is the homepage's section hint list.

- [ ] Tests (`site_spec_test.php`): model returns tree → slugs slugified + globally unique; missing/empty `pages` → defaults to single home page (`slug: home`, title `Home`, purpose from description); first page forced `front` semantics by position (no flag stored in spec — position is the contract); non-array children dropped to `[]`.
- [ ] Implement `normalizePages()` (recursive, collects `$seen` slugs across the whole tree, dedupes with `-2` suffixes like section slugs, keeps `title` fallback `ucwords(slug)`), call it from `normalize()`. Run tests. Commit.

### Task 2: page-plan step (per-page section plans)

**Files:**
- Rename: `src/Steps/SectionPlanStep.php` → `src/Steps/PagePlanStep.php` (class `PagePlanStep`, id `page-plan`)
- Rename: `prompts/section-plan.md` → `prompts/page-plan.md`
- Modify: `src/StepDefaults.php`, `src/SiteBuilder.php` (group membership only)
- Test: rename `tests/unit/section_plan_test.php` → `tests/unit/page_plan_test.php`

**Interfaces:**
- Consumes: `siteSpec.json.pages` (Task 1).
- Produces: `pages.json` (locked shape). Static helpers: `PagePlanStep::flattenPages(array $spec): array` (pure — flat entries `{slug,title,path,front,parent,menu_order,purpose}` depth-first, menu_order 0,10,20…; front path `/`, child path `/parent/child/`), `PagePlanStep::normalize($raw)` (unchanged section validation, reused per page).

- [ ] `requests()`: one request per flattened page, key = page slug. Prompt vars: existing ones + `page_title`, `page_slug`, `page_purpose`, `page_emphasis`, `site_pages`.
  - `site_pages`: one line per page: `- "<title>" — <path><front marker> : <purpose>`.
  - `page_emphasis` (const strings in the class): front → "This page is the site's front page and centerpiece — give it the most creative energy: a strong hero, at least 3 unique, image-rich content sections, and a compelling closing CTA. Aim for 5 to 8 sections."; interior → "This is one interior page of a multi-page site. Aim for 3 to 6 sections. Open with a COMPACT page hero that orients the visitor on this page (not a second homepage hero), cover only THIS page's purpose (don't rebuild content that lives on other pages), and close with a next step that points onward."
- [ ] `prompts/page-plan.md`: derived from section-plan.md — replace the landing-page framing with "plan ONE page of a multi-page site", insert `THIS PAGE:` block + `SITE PAGES` list + `{{page_emphasis}}`, keep all art-direction rules (archetypes, backgrounds, adjacency, card-grid cap, handoff, hero-first, cta/contact-last, language, identity, no-chrome).
- [ ] `consume()`: for each page slug, validate via `normalize()`; on failure repair ONCE per page (existing repair pattern, prompt = that page's request); write `pages.json` with flattened entries + their `sections`.
- [ ] `StepDefaults`: key `page-plan`, env `LLM_MODEL_PAGE_PLAN` / `LLM_TEMPERATURE_PAGE_PLAN` (drop the SECTION_PLAN names).
- [ ] Tests: flattenPages paths/menu_order/parents; requests keyed per page with per-page vars in prompt; consume writes pages.json with sections per page; one bad page triggers repair for that page only; step id `page-plan`. Run. Commit.

### Task 3: sections step goes multi-page

**Files:**
- Modify: `src/Steps/SectionsStep.php`
- Modify: `prompts/section.md`, `prompts/header.md`, `prompts/footer.md`
- Test: `tests/unit/sections_test.php`

**Interfaces:**
- Consumes: `pages.json`.
- Produces: `theme/parts/header.html`, `theme/parts/footer.html`, `theme/parts/page-<p>--<s>.html`; `SectionsStep::partSlug()`; `SectionsStep::sitePagesList(array $pages): string` (pure, shared with Task 2's format — put it here, PagePlanStep calls it).

- [ ] `requests()`: read `pages.json`; header/footer prompts get `outline` = FRONT page outline, plus new `site_pages`; per page × section requests keyed `partSlug()`, each section prompt gets its own page's `outline`, `page_title`, `site_pages`, existing composition block (neighbors within its page).
- [ ] `run()`: unchanged validation; file per key.
- [ ] `prompts/section.md`: add after outline: `THIS SECTION'S PAGE: "{{page_title}}" — one page of a multi-page site.` and `SITE PAGES (for internal links): {{site_pages}}`; add rule: "When a button or link leads to another page of THIS site, use that page's path from SITE PAGES (e.g. href=\"/menu/\") — never a placeholder '#' when a real page exists. Do not link the page to itself."
- [ ] `prompts/header.md` / `footer.md`: retitle outline block ("HOMEPAGE OUTLINE"), add `SITE PAGES: {{site_pages}}` (header note: wp:page-list auto-lists exactly these pages; footer may hand-author page links using the paths).
- [ ] Tests: two pages → part files `page-home--hero.html` etc.; each section prompt contains its own page's outline + site_pages + cross-page path; header prompt contains homepage outline. Run. Commit.

### Task 4: content-seeder plugin scaffold

**Files:**
- Create: `src/Steps/ScaffoldPluginStep.php`
- Modify: `src/Steps/ApplyIdentityStep.php`, `src/Project.php` (`pluginPath()`)
- Test: `tests/unit/scaffold_plugin_test.php`, extend `tests/unit/apply_identity_test.php`

**Interfaces:**
- Produces: `plugin/site-content.php` (placeholders `{{THEME_NAME}}`, `{{THEME_SLUG}}`, `{{DESCRIPTION}}`), filled by apply-identity. Step id `scaffold-plugin`.

- [ ] Plugin main file (nowdoc const `PLUGIN_PHP`), complete behavior:
  - Header: `Plugin Name: {{THEME_NAME}} Content`, Description `Seeds the generated content for {{THEME_NAME}}: creates the site pages on activation and removes them on deactivation.`, Version 0.1.0, Requires 6.5/PHP 7.4, GPL-2.0-or-later, Text Domain `{{THEME_SLUG}}-content`.
  - `ABSPATH` guard. Option name const `BUILDER_CONTENT_STATE_OPTION = 'builder_content_state'`.
  - `builder_content_activate()`: no-op if state option exists (idempotent); snapshot `show_on_front`/`page_on_front`; `kses_remove_filters()` (trusted build output — block comments must survive user-less activation) … seed … `kses_init_filters()`; for each manifest page in order: read `pages/<slug>.html`, `str_replace('theme:./assets/', trailingslashit(get_stylesheet_directory_uri()) . 'assets/', …)`, `wp_insert_post` (page, publish, title, post_name, menu_order, post_parent via already-created parent id map); record ids; front page → `update_option('show_on_front','page')` + `page_on_front`; save state option; `flush_rewrite_rules()`.
  - `builder_content_deactivate()`: delete recorded ids (`wp_delete_post($id, true)`), restore the two front options from state, delete option, `flush_rewrite_rules()`.
- [ ] Tests: step writes file + `php -l` passes on it (exec `PHP_BINARY -l`); apply-identity fills plugin placeholders (extend existing test file list); WP-stub harness test: define stub WP functions (options array, `wp_insert_post` capturing args returning ids, `wp_delete_post`, `get_stylesheet_directory_uri`, `trailingslashit`, `kses_*`, `flush_rewrite_rules`, `register_*_hook` no-ops, `is_wp_error` false, `ABSPATH`), include the filled plugin file once, call activate → assert pages created in order with parent ids + menu_order, front options set, `theme:./assets/` rewritten; call deactivate → pages deleted, options restored, state cleared; second activate after seed → no duplicates (idempotence via option guard). Run. Commit.

### Task 5: assemble-pages step

**Files:**
- Create: `src/Steps/AssemblePagesStep.php`; Delete: `src/Steps/AssembleLandingPageStep.php`
- Test: `tests/unit/assemble_pages_test.php` (replaces assemble_landing_page_test.php)

**Interfaces:**
- Consumes: `pages.json`, fixed `theme/parts/*`.
- Produces: `theme/templates/page.html` (header part + bare `<!-- wp:post-content /-->` + footer part — bare so sections keep root-like full-width flow), `theme/templates/index.html` (today's `index()` composition), theme.json `templateParts` = header/footer only, `plugin/pages/<slug>.html` (page's fixed parts concatenated in plan order), `plugin/pages.json` manifest, transient `theme/parts/page-*.html` deleted. Pure helpers: `pageTemplate(): string`, `index(): string`, `pageContent(array $sectionMarkups): string`.

- [ ] Also add to `ScaffoldThemeStep::STYLE_CSS` (small addition, same reasoning as the root rule): `.wp-block-post-content > * { margin-block-start: 0; }` — sections bring their own padding; the flow gap would open page-background stripes between bands inside post content.
- [ ] Tests: given pages.json + part files → plugin pages inlined in order; manifest matches locked shape (front flag on first, parents preserved); transient parts removed, header/footer kept; missing part → RuntimeException naming it; templates written; templateParts = header+footer. Run. Commit.

### Task 6: pipeline rewire + scanners + validator

**Files:**
- Modify: `src/SiteBuilder.php`, `src/Steps/PageStylesStep.php` (`usedClasses`), `src/Steps/FontsPhpStep.php` (`themeMarkup`), `src/ThemeValidator.php`
- Test: `tests/unit/site_builder_test.php`, `tests/unit/pipeline_test.php`, `tests/unit/step_model_test.php`, `tests/unit/validator_test.php`, `tests/unit/page_styles_test.php`, `tests/unit/fonts_php_test.php`

- [ ] `SiteBuilder::pipeline()` → locked order above.
- [ ] Both scanners additionally glob `$project->pluginPath('pages') . '/*.html'` (content classes/fonts must be covered by the theme stylesheet/fonts).
- [ ] `ThemeValidator`: required files → `templates/index.html`, `templates/page.html`, `parts/header.html`, `parts/footer.html`; ALSO validate block balance + placeholder scan over `plugin/pages/*.html` when present.
- [ ] Update id-list assertions; scanner tests get one case with a class/font only in `plugin/pages/x.html`. Run. Commit.

### Task 7: Playground blueprint ships the plugin

**Files:**
- Modify: `src/PlaygroundArtifact.php`
- Test: `tests/unit/playground_artifact_test.php`

- [ ] `siteOptions()`: add `'permalink_structure' => '/%postname%/'` (page paths like `/menu/` must resolve; WP lazily rebuilds rewrite rules, and the seeder flushes on activation).
- [ ] `blueprint()`: after `activateTheme`, when `plugin/site-content.php` exists: `mv` `…/project/<slug>/plugin` → `/wordpress/wp-content/plugins/<slug>-content`, then `{'step':'activatePlugin','pluginPath':'<slug>-content/site-content.php'}` (theme first — the seeder resolves asset URLs against the active theme).
- [ ] Tests: blueprint with plugin present includes both steps in order after activateTheme; without plugin dir → theme-only blueprint unchanged; permalink option present. Run. Commit.

### Task 8: bins, integration test, docs

**Files:**
- Modify: `bin/inspect.php`, `bin/eval.php`, `tests/integration/pipeline_test.php`, `README.md`, `PROGRESS.md`

- [ ] `bin/inspect.php`: outline `theme/templates/page.html` + every `plugin/pages/*.html` instead of front-page.html.
- [ ] `bin/eval.php`: step id list → new ids; `front_page_blocks` metric → count blocks in the manifest's front page content file.
- [ ] Integration test: script a 2-page site (home + menu): spec with `pages` tree; two page-plan responses; header/footer + 3 section markups; assert plugin/pages/{home,menu}.html exist with sections in order, manifest front/menu_order, page.html template, no front-page.html, ThemeValidator passes, hover-lift CSS + fonts as today. Step-order test → new ids.
- [ ] Docs: README pipeline table/step list refresh; PROGRESS.md short "multi-page + content plugin" addendum. Run full unit + integration. Commit.

## Self-review notes

- Spec coverage: pages tree (T1), per-page plans (T2), per-page generation (T3), homepage+pages as companion plugin with activation/deactivation seeding (T4/T5/T7), deterministic scaffold (T4). ✔
- collect-images runs pre-assemble, so image specs are collected from transient parts before they move into the plugin — `sources` paths in images.json go stale after assemble; acceptable (informational only; GenerateImagesStep rewrites by glob, not sources).
- Image URLs in content: never rewritten at build time; seeder rewrites at activation. GenerateImagesStep keeps writing files into `theme/assets/` — content references resolve there at runtime. ✔
- Nav: header already uses wp:navigation + wp:page-list; seeded pages appear automatically ordered by menu_order (children nest). ✔
