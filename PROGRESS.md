# Builder — Progress / Final Summary

A minimalistic, no-agentic-loop WordPress site builder. Each site element is one
isolated LLM call driven by a dynamic prompt template; all output is saved as
local files; steps run as a fixed deterministic sequence.

## Status: COMPLETE — all phases done

---

## Phase 0 — proxy access (resolved)

Verified empirically (not assumed) that the **wpcom AI proxy cannot reach Claude**
with the available credentials:
- The Anthropic key is not a valid proxy bearer (proxy → 404 `not_found_error`).
- The only working proxy token (`GOOGLE_VERTEX_API_TOKEN`) is scoped to Google
  Vertex only — Claude/Anthropic models return 404 through it.
- telex confirms the pattern: it calls Anthropic **directly** and uses the proxy
  only for Google-Vertex image generation.

**User decision: use Anthropic-direct.** `api.anthropic.com/v1/messages`,
`x-api-key`, model `claude-opus-4-8`. Confirmed round-trip ("pong"). Transport is
behind the `Llm` interface so a proxy transport can be swapped in later.

---

## Phase 1 — implementation (complete)

8 steps, each implemented, unit-tested, and committed to trunk one per commit.

The pipeline (current — see **Phase 3** below for the design-half refactor):

| # | Step | Type | Input → Output |
|---|------|------|----------------|
| 1 | scaffold-theme   | det | — → theme/style.css, readme.txt (placeholders) |
| 2 | site-spec        | LLM | meta.json prompt → siteSpec.json (**factual info only** — no design) |
| 3 | apply-identity   | det | siteSpec → filled style.css/readme.txt |
| 4a | theme-json    | LLM | meta.json prompt + siteSpec → theme/theme.json (v3); **design decisions made inline** (no design.md) |
| 4b | section-plan  | LLM | meta.json prompt + siteSpec → sections.json (ordered section briefs). **Runs concurrently with theme-json** (`ConcurrentGroup`, one batched call) |
| 5 | sections        | LLM | siteSpec + theme.json + sections.json → parts/{header,footer,section-*}.html. **One concurrent batch — every part generated in parallel** (with AI_IMAGE placeholders) |
| 6 | assemble-landing-page | det | sections.json + parts/ → templates/{front-page,index}.html (compose template parts in order) + theme.json templateParts |
| 7 | collect-images  | det | parts/ + templates/ → images.json (parse AI_IMAGE placeholders; before fix-blocks) |
| 8 | fix-blocks      | det | templates/ + parts/ → same files re-serialized (block validation) |
| 9 | finalize-theme  | det | theme.json → theme/functions.php (Google Fonts loading) |
| + | generate-images  | net | images.json → theme/assets/*.jpg via WPCOM proxy (Imagen); rewrites theme: src. **Opt-in** (`--with-images` / `bin/images.php`) |

**Architecture** (zero PHP dependencies — plain PHP + cURL):
`Env`, `Llm` interface + `AnthropicClient`, `Project`, `ProjectStore`,
`PromptRenderer`, `Step`/`ConcurrentStep` + the pipeline steps, `ConcurrentGroup`,
`Pipeline`, `ThemeValidator`.
Prompts: `prompts/*.md`. Runners: `bin/build.php`, `bin/eval.php`, `bin/inspect.php`.

**Concurrency.** LLM work is parallelised via a shared `curl_multi` transport
(mirroring `WpcomImageClient`'s image batching, reusing `parseSse`; the pure
`AnthropicClient::retryTextBatch()` retries only transient failures and aborts
the batch on a permanent one). Two batch entry points sit on top of it:
`Llm::completeJsonBatch()` for structured steps (theme.json, the section plan)
and `Llm::completeBatch()` for steps whose answer IS the payload — the section
parts return raw block markup verbatim rather than escaping it inside a JSON
string (brittle + wasteful). The transport runs at most `MAX_CONCURRENCY` (5)
requests in flight at once — a wide fan-out (every landing-page part) is split
into ordered windows so it never trips the API's rate limits. A `ConcurrentStep`
exposes `requests()`/`consume()` so its prompts can be fired together; a
`ConcurrentGroup` (itself a `Step`) merges several steps' requests into one batch.
This overlaps theme-json beside the section plan, and generates every landing-page
part (header, footer, each section) at once — replacing the old single
landing-page mega-call that dominated build time.

**Block validation fixer** (`bin/block-fixer/`, step 8): a verbatim copy of telex's
`server/scripts/block-fixer` lib (`blockFixer.js` + `paragraphFixer.js`) plus a
one-shot CLI (`fix-templates.js`). AI-generated block markup often carries
style/attribute/element-order mismatches that trigger "unexpected or invalid
content" in the editor/Playground; the fixer parses each `templates/*.html` and
`parts/*.html` with `@wordpress/blocks` and re-serializes it to match WordPress
`save()` exactly. `FixBlocksStep` shells out to Node (`node_modules` is gitignored;
telex runs the same lib as a warm HTTP sidecar). Re-serialization is idempotent.

**Tests: 62 unit + 2 integration = 64 passing.** Run with
`php tests/run.php` and `php tests/run-integration.php`. The integration test
runs the real `Pipeline` with a `FakeLlm` and asserts the output passes
`ThemeValidator` (files present, theme.json v3, balanced block grammar, no
leftover placeholders, fonts enqueued) — this is the full-sequence integration test.

Per-step real verification was done cumulatively: each new LLM step's first real
run re-runs all prior steps, exercising the whole chain up to that point.

---

## Phase 2 — end-to-end validation (5 sites)

Generated all 5 sites with `bin/eval.php`. Full data: `eval/report.md`,
`eval/results.json`. All **structurally valid**.

### Speed (model: claude-opus-4-8, sequential)
| Site | site-spec | design-dir | design-doc | theme-json | landing | Total |
|---|---|---|---|---|---|---|
| climate-care-blog | 7.4 | 37.9 | 32.6 | 21.9 | 113.6 | **213s** |
| photo-portfolio   | 7.5 | 36.1 | 33.4 | 18.7 | 92.5  | **188s** |
| pizza-menu        | 8.2 | 32.8 | 34.0 | 22.9 | 137.5 | **235s** |
| bakery-catalog    | 8.5 | 33.8 | 29.0 | 18.5 | 149.3 | **239s** |
| bicycle-store     | 7.7 | 33.1 | 32.6 | 21.9 | 132.0 | **227s** |

(scaffold/apply-identity/finalize are deterministic, ~0s.) Mean ≈ **220s/site**.
**landing-page is the bottleneck** (~50–60% of total): large block-markup output.

### Quality
| Site | Brand | Fonts (loaded) | Sections | Front-page blocks |
|---|---|---|---|---|
| climate-care-blog | Greener Nest | Fraunces + Work Sans | 6 | 83 |
| photo-portfolio | Stillrange | Cormorant Garamond + Inter | 7 | 58 |
| pizza-menu | Forno Vero | Playfair Display + Lora | 7 | 103 |
| bakery-catalog | Hearth & Crumb | Playfair Display + Source Serif Pro | 6 | 114 |
| bicycle-store | Verge Cycles | Archivo + Inter | 6 | 91 |

Observed quality (via `bin/inspect.php`): distinct, fitting brand identities;
section structure matches `key_sections`; real CTAs; descriptive image alts;
**100% design-token discipline** — every color/font used in markup is a declared
theme.json preset (zero undeclared tokens across all 5 sites).

### Adjustment made from eval findings
The eval surfaced one real gap: **theme.json named Google fonts but nothing loaded
them**, so every site fell back to system fonts. Added **step 8 (finalize-theme)**:
generates `theme/functions.php` enqueuing the heading+body families from Google
Fonts (skips generic/system families). Verified all 5 font URLs return HTTP 200
and each `functions.php` is valid PHP. Re-ran the report (`bin/eval.php --report`)
— all 5 now load real webfonts.

---

## What you get per site (`projects/<slug>/`)
`meta.json`, `siteSpec.json`, `design.md`, and a complete
block theme under `theme/`: `style.css`, `readme.txt`, `theme.json`,
`functions.php`, `parts/{header,footer}.html`, `templates/{index,front-page}.html`.

---

## Phase 3 — design-pipeline refactor (siteSpec → design.md)

Refactored the design half of the pipeline so **facts** and **design decisions**
are cleanly separated, the intermediate design-direction artifact is dropped, and
the design doc follows a recognized standard.

Before: `site-spec (facts + colors/typography/layout) → design-direction
(designDirection.json) → design-doc (ad-hoc design.md)`.
After: `site-spec (facts only) → design-doc (design.md per the DESIGN.md standard,
from prompt + siteSpec)`.

- **siteSpec.json is factual only.** Fixed properties: `name`, `slug`, `title`,
  `site_type`, `topic`, `area`, `audience`, `visual_vibe` (a short mood phrase —
  not concrete colors/fonts), `sections`. Any concrete facts the user stated
  (hours, location, products…) pass through as extra keys. No `colors`,
  `typography`, or `layout` are invented here.
- **design.md follows the [DESIGN.md standard](https://github.com/google-labs-code/design.md):**
  YAML front matter with `colors` (base/contrast/primary/secondary/accent),
  `typography` (heading/body), `rounded`, `spacing`, then a Markdown body
  (Overview, Colors, Typography, Layout, Shapes, Components, Imagery, Do's/Don'ts).
  It is the single source of design truth. `DesignDocStep` validates the front
  matter tokens and core sections are present.
- **design-direction step removed.** `DesignDirectionStep` + `designDirection.json`
  + `prompts/design-direction.md` are gone. `theme-json` now reads the exact token
  values straight from design.md's front matter.

## Live preview
`php bin/playground.php <slug>` boots a local WordPress Playground (via
`npx @wp-playground/cli`), mounts `projects/<slug>/theme` into
`wp-content/themes/<slug>`, sets the site title/tagline from siteSpec, activates
the theme, and auto-logs into wp-admin. **Verified**: `bakery-catalog` renders at
85 KB with the brand "Hearth & Crumb" in the header, both Google fonts loaded,
and all front-page sections present.

## Remaining issues / recommendations
1. **Live render confirmed via Playground** (`bin/playground.php`). A scripted
   headless smoke test (boot + assert markers + teardown) could be added to CI;
   today it is a manual/verified command.
2. ~~**landing-page latency** dominates (~2 min).~~ **Done.** The landing-page
   mega-call was split into a concurrent `section-plan` (beside theme-json) plus a
   per-section `sections` batch (header, footer, every section in parallel) and a
   deterministic `assemble-landing-page` compose step. Each section is a template
   part; the page composes them in plan order.
3. **Single page only.** Only the front page + index fallback are generated.
   Natural extensions (same one-shot pattern): per-page templates (about, contact),
   block patterns, a richer index/query for blog/catalog sites.
4. **Images: done (opt-in).** landing-page emits telex-style `AI_IMAGE` placeholders
   (`src="theme:./assets/x.jpg"` + `alt="AI_IMAGE: desc | style | aspect-ratio"`);
   collect-images parses them to `images.json` (before fix-blocks, which strips
   cover-background alts); generate-images turns them into real `theme/assets/*.jpg`
   via the WPCOM proxy (Google Imagen) and rewrites the `theme:` refs to served
   URLs. Gated behind `--with-images` / `bin/images.php` (slow + networked).
   A failed image is marked `failed` and never aborts the build.
5. **Font weights** assume 400/600/700 exist (true for all fonts picked so far).
   A font lacking one would 400 the combined request; per-family enqueue would
   isolate that if it ever happens.

## How to run
```
cp .env.example .env   # set ANTHROPIC_API_KEY
php tests/run.php && php tests/run-integration.php

# One-shot: prompt -> build (tokens + wall time) -> Playground + URL
php bin/create.php "A neighborhood tea house and loose-leaf tea shop"

# Lower-level pieces
php bin/build.php "A cozy neighborhood bakery" --slug=my-bakery
php bin/inspect.php my-bakery
php bin/playground.php my-bakery   # boot a local WP with the theme activated
php bin/eval.php                   # regenerate the 5 eval sites
```

`bin/create.php` reports per-step time + tokens and totals, writes
`projects/<slug>/build-stats.json`, then launches Playground. Example run
("tea house" → "Steeped & Still"): **241.9s wall, 5 LLM requests,
19,068 in + 24,505 out = 43,573 tokens** on claude-opus-4-8, served at the
printed URL with the theme active and fonts loaded. Flags: `--slug=`, `--port=`,
`--no-serve` (build + metrics only).

---

## Phase 5 — multi-page generation + content plugin (2026-07-10)

The builder now produces a whole site, not a single landing page:

- **siteSpec.json carries a page tree** (`pages`, first entry = homepage;
  slugs unique across the tree, max depth 2). One-page sites degrade to just
  the homepage entry.
- **page-plan** (replaces section-plan) plans EVERY page's sections — one
  request per page, batched, still concurrent with theme-json. Output is
  `pages.json` (flat entries: slug/title/path/front/parent/menu_order +
  validated section briefs; per-page one-shot repair).
- **sections** generates header + footer + every page's section parts in one
  batch (`parts/page-<page>--<section>.html`, transient). Sections know their
  own page's outline plus the site page list, and link across pages with real
  paths.
- **assemble-pages** (replaces assemble-landing-page; runs AFTER fix-blocks)
  inlines the fixed markup into `plugin/pages/<slug>.html`, writes the seeder
  manifest and the deterministic `page.html`/`index.html` templates, keeps
  header/footer as the only theme parts, and deletes the transient parts.
  `front-page.html` is gone — the seeded homepage + `page_on_front` owns the
  front.
- **scaffold-plugin** writes the deterministic companion plugin
  (`plugin/site-content.php`, identity filled by apply-identity): activation
  seeds the pages (kses bypassed for the trusted markup, `theme:./assets/`
  resolved against the active theme, front page pointed, state recorded in
  one option), deactivation deletes exactly those pages and restores the
  options.
- **Scanners follow the content**: page-styles, fonts-php, and the validator
  read theme parts/templates AND `plugin/pages/*` via
  `Project::markupFiles()`.
- **Playground blueprint** installs the plugin next to the theme, activates
  it after the theme, and sets pretty permalinks so the page paths resolve.
- Nav is automatic: the header's `wp:navigation` + `wp:page-list` reflects the
  seeded pages in `menu_order` (children nest as submenus).

### Phase 5.1 — content images live in the media library (2026-07-10)

Content images are now treated as content, not theme assets:

- **assemble-pages** writes `plugin/images.json` — every asset the page
  markup references, titled from the collected spec's subject.
- **generate-images** copies those files into `plugin/images/` (the theme
  keeps its copy: chrome may share it, and it's the fallback).
- **The seeder imports them on activation** — `wp_upload_bits` +
  `wp_insert_attachment` + generated metadata — and resolves the page
  markup against the import: `wp:image` blocks get the real attachment id
  (unknowable at build time) injected into their attributes plus the
  paired `wp-image-<id>` class, so core srcset/lightbox engage; cover urls
  and inline backgrounds get a plain URL swap; unshipped files fall back
  to the theme's assets. Deactivation deletes the imported attachments
  with the pages.
