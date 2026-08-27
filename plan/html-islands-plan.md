# HTML Islands graph (`--html-islands`)

> Reviewed 2026-08-26 via deep plan review. Mode: HOLD SCOPE. Verdict and
> registries at the end of this document. Fourteen issues were raised and
> resolved; their decisions are folded into the sections below.

## Goal

Replace the `html-first` graph with one that delivers the authored HTML+CSS design to the visitor without converting page bodies to block markup. Each design `<section>` ships as one `core/html` block inside the seeded page's `post_content`. The theme stays a block theme: `theme.json`, `templates/page.html`, and the `header`/`footer` template parts are unchanged in kind, and their contents are still real blocks.

At completion the repository has two graphs, `blocks` and `html-islands`. `html-first` is deleted.

## Background

Two graphs exist today (`src/StepComposition.php`):

- **blocks** — the model authors block markup directly.
- **html-first** — the model authors an HTML+CSS design document, and `transform-site` compiles it to blocks.

The design artifacts (`design/home.html`, `design/<slug>.html`, `design/site.css`) are complete standalone HTML documents: `<header>`, `<main>` containing sections, `<footer>`, and a `<style>` block mirrored byte-identically to `design/site.css`. Roughly eighteen steps downstream of `transform-site` exist to perform that conversion and repair its output.

## Evidence and premise

### Cost and speed: not a justification

Four html-first builds (`projects/*/build-stats.json`):

| build | total tokens | wall | block-tail tokens | block-tail wall |
|---|---|---|---|---|
| amber-ember2 | 94,465 | 213s | 0 | 17.4s (8%) |
| eager-willow | 98,744 | 229s | 0 | 19.4s (8%) |
| dapper-summit | 104,472 | 239s | 0 | 23.0s (10%) |
| coral-lantern | 123,745 | 269s | 0 | 36.0s (13%) |

`transform-site` and every step after it spend **zero tokens**. The transformer is deterministic PHP; `HeaderUnit`/`FooterUnit` fired no requests in any of the four. All spend is upstream — `inner-pages-design` alone is 56k–86k, 60–70% of each build. Of the block-tail wall time, 85–88% is `page-styles`, whose CSS merge this graph still needs.

**Do not justify this graph on cost or speed.**

### Fidelity: asserted, not yet measured

`design/transform-report.json` on all four healthy builds reads `{"fallback_codes":[], "repair_outcomes":[], "dropped_fragments":[]}`, and `transform-site` appears zero times across six builds' `warnings.json`.

So: **structural loss is measured at zero. Visual loss is unmeasured.** Block markup plus `theme.json` layout CSS can render different geometry from faithfully-converted elements, and no instrument covers that channel. The original plan conflated the two and asserted both.

This was reviewed and the decision was to proceed on judgment. Milestone 5 is therefore the **first test** of the premise, not a confirmation of it.

### Justifications that survive

1. **Tail risk.** A page body failing transform validation reroutes to the legacy blocks path — the failure mode behind the inner-page CSS-cap incident. With no page-body conversion, that reroute cannot occur.
2. **Design vocabulary.** `prompts/inner-page-design.md:42` and `TransformSiteStep::SUPPORTED_SLICE` forbid SVG, custom elements, and behavior-bearing markup because the transformer cannot carry them. Islands can relax the inert half.
3. **Code removed.** Retiring html-first deletes the conversion machinery and the dormant fallback cluster, closing an open P2 TODO.
4. **Fidelity**, pending milestone 5.

## Approach

### Island granularity

One `core/html` block per design section. `do_blocks` concatenates islands, so sections remain DOM siblings.

**Reordering is CSS-safe.** Measured across 4,513 selectors in 64 `design/site.css` files: **0** are reorder-fragile at the section level — no `section + section`, no `section:nth-child`, no `main > *:nth`. The 3.7% positional selectors are all internal to a section and travel with it. This is what makes per-section granularity deliver real editor value rather than a trap.

The design's `<main>` wrapper is dropped, matching html-first: `AssemblePagesStep::pageTemplate()` emits `<!-- wp:post-content /-->` bare.

### The split algorithm (Issue 6 → 6A)

A naive split on direct `<section>` children of `<main>` loses content. Measured across 255 design files: 1,314 section children, **70 non-section children**, 4 nested sections, 0 significant text nodes, 0 files without `<main>`.

The non-section children are not decorative:

| file | `<main>` children | naive split yields |
|---|---|---|
| `contact.html` | one `<div class="pg-wrap">` holding all 3 sections | **0 islands — page lost** |
| `about-services.html` | 1 `<section>` + `<div class="wrap">` holding 6 | 1 of 7 sections |
| `about.html` | 8 sections separated by `<hr class="rule">` | separators vanish |

**Rule: split at the highest level that yields more than one unit; otherwise island the whole `<main>` as one.** Descend through non-section wrappers to find the section layer. Non-section siblings (`<hr>`) become their own islands rather than being dropped. A page whose structure resists splitting degrades to one island — losing editor granularity, never content. This is the AGENTS.md degrade ladder applied structurally.

Wrapper CSS (`.wrap`, `.pg-wrap`) may be layout-bearing (`display:grid`), which is why wrappers are not stripped and their children re-parented; the whole-`<main>` fallback preserves them intact.

### Balance guard (Issue 9 → 9B)

An island with unbalanced tags would make every later island and the footer template part its DOM children. Measured 0-of-255 design files with tag-mismatch or unexpected-end-tag errors, so this is hardening, not a live defect.

`island-pages` asserts the tag stack per island and synthesizes missing closers. Follow `MarkupSalvage`'s existing precedent: **an open element with no complete child to keep is dropped whole rather than closed around a cut-off sentence.** `MarkupSalvage::openElements()` is not directly reusable — it throws on non-wrapper fragments — so this needs its own tag-stack scan.

### Parse failures (Issue 10 → 10A)

`DOMDocument` with `libxml_use_internal_errors(true)` returns a partial tree and no exception, so a truncated design would ship a short page as a successful build. All 255 corpus files emit benign HTML5/processing-instruction notices, so the filter must distinguish those from structural errors. Structural errors degrade that page with an actionable warning.

### Missing artifacts (Issue 8 → 8A)

A missing or unusable `design/<slug>.html` skips that page with an actionable warning, reusing the path `assemble-pages` already has for pages with no surviving markup. The front page stays fatal — the templates and seeder depend on it.

This honors the standing warning in TODOS.md's dead-fallback entry: *"decide direction before adding any new throwing step to the design region."* `island-pages` is a new step in exactly that region, and milestone 6 removes the net it might otherwise have assumed. It degrades; it does not throw.

### `aboveFold.json` (Issue 3 → 3A)

`TransformSiteStep` is the only producer of `aboveFold.json` in the html-first graph. Three retained consumers require it:

- `HeaderHeroStep` — mandatory, sole producer of `headerBehavior.json`, which `AssemblePagesStep` and `FinalizeThemeStep` both read.
- `ThemeValidator:130,138` — calls it "a required upstream artifact."
- `AboveFoldContract:929-939` — throws on missing version, invalid phase, or absent header contract.

**`island-pages` derives the contract from the design HTML** — parsing the `<header>` and first `<section>` — rather than from block structure, which no longer exists for the hero. Same contract, different reader.

`StepGraph::validate()` runs on every composition construction, so a missing producer fails loudly at assembly rather than shipping broken.

### Sanitization (Issue 7 → 7A)

`AssignImageSourcesStep` mutates `design/*.html` *after* `InnerPagesDesignStep` sanitized it, which makes `TransformSiteStep`'s re-sanitize the last guard before delivery. **`island-pages` carries that `DesignMarkupSanitizer` call**, per section, before emitting each island.

`DesignMarkupSanitizer` is a frozen facade over a hardened engine, documented as the shared trust boundary for untrusted design HTML. The seeder's `WP_HTML_Tag_Processor` pass at activation is a second, deliberately different implementation of the same contract. Both remain.

**Also in milestone 4:** rewrite the kses-suspension comment at `ScaffoldPluginStep:197-206`. It justifies itself as "kses would mangle its block comments," but on this graph the payload is raw section HTML whose inline `style` attributes and semantic elements kses would strip. The suspension stays correct; its stated reason no longer matches the mechanism, and a security decision documented by a wrong reason is worse than one documented by none.

### Chrome

Header and footer stay real blocks in `theme/parts/`. `HeaderUnit`/`FooterUnit` are extracted into `transform-chrome`.

Not optional: `HeaderHeroStep` produces `headerBehavior.json` for two downstream consumers; the adaptive-header kit keys off `.site-header-shell--sticky-soft` / `--overlay-to-solid` classes the transform applies (`assets/header/header.js:8`); and the nav is the part owners actually edit.

### CSS

`design/site.css` reaches `theme/style.css` verbatim through the existing `page-styles` merge, scrubbed by `CssScrub` only for third-party fetches.

**Per-page CSS needs no new work.** `PageStylesStep` in html-first mode declares `design/*` in its reads and consumes each page's `<style data-page-css>` chunk directly from the design artifact — 37–49 rules and 3–4KB per inner page — scoping each with `PageScope::bodyClass($slug)` so one page's element rules cannot restyle its siblings. Islands preserve `design/*` byte-identically, so this flows unchanged. `design/page-artifact-map.json` is likewise written by `InnerPagesDesignStep:189,407`, not transform-site, so no new producer is needed.

`CssContrastCheckEngine::check()` is a DOM/`CssSelectorMatcher` HTML matcher, so it reads raw island markup fine — arguably better than block markup, since that is what the CSS was authored against.

The design stylesheet's document-level rules (`html`, `body`, `*`) and element rules (`header{…}`, `footer{…}`) now also match the block-built chrome and the editor canvas. **Deliver verbatim and fix collisions in the chrome**: the chrome was authored in the same design document, so its rules applying to it is correct, and `theme.json`-generated styles are the side that should yield. Fallback if milestone 5 shows chrome damage: re-home only the three document-level rules onto an island wrapper. Do not rewrite authored selectors wholesale.

### Post-image phase (Issue 4 → 4A)

`StepComposition::postImages()` returns `generate-images → theme-screenshot → cover-contrast → extract-patterns → validate-theme` for *any* graph, and `bin/build.php` runs it after the graph on `--with-images`. The original plan never mentioned it.

- **`extract-patterns` is dropped from the islands post-image list.** Every island section fails eligibility, producing one non-actionable warning per section per build — a violation of AGENTS.md rung 2.
- **`cover-contrast` gets an HTML path.** It reads `plugin/pages/*.html` and rewrites `wp:cover` `dimRatio`/text color; on islands there are no cover blocks, so it becomes a silent no-op. Its reason for existing — the LLM picks cover text color against an image it has never seen — applies identically to islands. It must find hero and background images in island markup instead.

`postImages()` becomes graph-aware rather than taking a `?bool $htmlFirst`.

### Observability (Issue 13 → 13A)

`design/transform-report.json` becomes meaningless on this graph while four new quiet-degrade paths appear. **`island-report.json` replaces it**, recording per page:

- sections found, and the split level chosen (per-section vs whole-`<main>`)
- non-section siblings islanded
- balance repairs and elements dropped (9B)
- parse degrades (10A)
- pages skipped (8A)

Without it, a fidelity-motivated graph would ship with *less* visibility than the one it replaces. This also gives milestone 5's gate quantitative evidence rather than screenshots alone.

### Graph selection and resume (Issue 5 → 5A)

Replace `SITE_BUILD_HTML_FIRST` (boolean) with `SITE_BUILD_GRAPH`, taking `blocks | html-first | html-islands` during the transition and `blocks | html-islands` after:

- `htmlFirstSelected(): bool` → `selectedGraph(): string`
- `resumeHtmlFirst(?string, ?bool): ?bool` → `resumeGraph(?string $recorded, ?string $requested): ?string`
- `SiteBuilder::createProject()`'s `?bool $htmlFirst` → `?string $graph`
- `bin/build.php` / `bin/build-demos.php` gain `--html-islands`; all graph flags mutually exclusive

**`resumeGraph()` must reject a retired graph name, not fall through.** Today an unrecognized record returns null and the caller's selection stands; after deletion a `graph: html-first` project would silently resume on `blocks`, reading artifacts it never wrote.

**The wpcom peer host is coordinated as part of milestone 1**, not afterwards. AGENTS.md documents that hosts drive a fixed `StepComposition` and must pass `htmlFirst:` to `createProject()` themselves, so this signature change breaks them. TODOS.md already records hosts drifting from published step lists as a known failure mode here.

The per-step `htmlFirst: bool` flags mean "the design owns page width and typography, not the theme," which stays true. Keep them boolean, pass `true`, rename to `designOwnsLayout` in milestone 6 **as its own commit** (Issue 11 → 11A) — a 10-constructor mechanical rename must not share a diff with an irreversible deletion.

`FallbackBuildPipeline` does not wrap the islands graph.

## Step disposition

| Step | Disposition |
|---|---|
| `transform-site` | Split: chrome half → `transform-chrome`; page half deleted |
| `section-layout`, `fix-pages` | Dropped; used by no other graph — classes deleted |
| `section-rhythm`, `normalize-layout` | Chrome scope. Classes stay for the blocks graph. Determine in milestone 4 whether either is a no-op on chrome-only input |
| `header-hero` | Retained, chrome scope. Sole producer of `headerBehavior.json`. Cover-fold cap and hero-echo dedupe must read island HTML |
| `contrast-fix`, `motion-sanity`, `fix-blocks` | Retained, chrome scope |
| `collect-images` | Retained; `parseAssignedImages` already reads an `<img>` with an assigned theme path |
| `resolve-nav-links` | Retained; resolves against island HTML |
| `assemble-pages` | Retained; concatenates islands |
| `page-styles` | Reduced to CSS merge and scrub |
| `extract-patterns` | Deferred to milestone 8; dropped from graph and post-image list |
| `validate-theme` | Retained, chrome and theme scope |

## Patterns

Patterns are worth more on this graph than on the blocks graph. A blocks-path user can hand-build a matching section in the editor; an islands-path user would have to hand-write HTML in the design's class vocabulary.

The `core/html` ban is policy, not a technical barrier. `ExtractPatternsStep::eligibilityFailure()` guards `raw_php` separately and earlier — that is what stops a `<?php` tag executing from the included pattern file. The `['html','embed','shortcode']` list beneath it was written when a `core/html` block meant the transformer had failed.

**Blocker: section-ID collision.** 5.5% of 4,513 rules key on an ID (`#hero`, `#cta h2`). Design navs use `href="#hero"`. Inserting a hero pattern onto a page that has one yields duplicate ids — invalid HTML, and the anchor resolves to the first.

**Fix:** at `island-pages` time, additively mirror each ID-keyed rule to a class equivalent (`#hero {…}` also emitted as `.sec-hero {…}`) and add the class to the section. Nothing authored is rewritten; page content keeps its ids so anchors work. Only the extracted pattern copy drops the `id`.

**Scheduling (Issue 14 → 14A):** `feature/deterministic-patterns` is rewriting `ExtractPatternsStep.php` — 70 unmerged commits, +1,988 lines, 46 behind trunk, no open PR. The ban survives that rewrite at line 662 (trunk: 687). **Milestone 8 depends on that stack landing**, then rebases the one-line ban lift onto whatever the file becomes. Fighting a 1,988-line rewrite for a one-line change is waste.

## Sanitizer extension (milestone 7)

Relaxing the SVG ban requires extending both `MarkupSanitizer` and the seeder's `_content_sanitize()` to strip `<script>`, `on*` handlers, `javascript:` URLs, `<foreignObject>`, and off-document `<use href>` from inside SVG subtrees. Until both are extended and tested, the prompt ban stays.

## What gets deleted

| Path | Note |
|---|---|
| `src/Steps/TransformSiteStep.php` | Page half; chrome half → `transform-chrome` |
| `src/Steps/SectionLayoutStep.php` | html-first only |
| `src/Steps/FixPagesStep.php` | html-first only |
| `src/Steps/HomepageDesignStep.php` | Already in neither graph — dead code |
| `src/FallbackBuildPipeline.php` | Only trigger is a `MalformedDesignException` from `homepage-design`, which no composition contains |
| `src/MalformedDesignException.php` | Thrown only by `HomepageDesignStep` |
| `StepComposition::blocksTail()` + `BLOCKS_TAIL_SEEDS` | Feeds only the fallback wrapper |
| `SiteBuilder::pipeline()` fallback wiring | `src/SiteBuilder.php:62-80` |
| `prompts/homepage-design.md` | Retired step's prompt |
| `tests/unit/fallback_build_pipeline_test.php`, `tests/unit/homepage_design_zz_review_test.php` | Cover deleted code |
| `tests/unit/utf8_double_encoding_test.php` (partial) | Drop `HomepageDesignStep` cases only |
| `tests/unit/step_composition_test.php:173` | `blocksTail` coverage |

Plus the dead `htmlFirst` branches in `section-rhythm`, `normalize-layout`, and `extract-patterns`.

**Tag the commit immediately before the deletion** (`pre-html-islands-cutover`). Milestone 6 removes the comparison baseline; a tag preserves it permanently at zero cost.

## Scope

**In:** the graph; `island-pages` and `transform-chrome`; the `SITE_BUILD_GRAPH` refactor including the wpcom peer; graph-aware `postImages()` and the cover-contrast HTML path; `island-report.json`; CLI flags; AGENTS.md and TODOS.md updates; unit coverage for every path below; one integration test replacing `tests/integration/html_first_build_test.php`; the deletion above.

**Follow-on milestones:** SVG prompt relaxation (7), patterns (8).

**Out entirely:** any change to the blocks graph. Retiring the blocks graph to reach one graph (considered, declined — see *Dream state delta*).

## Test plan

Nine cases this review added, on top of the plan's original coverage:

| Case | Type |
|---|---|
| wrapper descent — `<main>` holding only `<div class="pg-wrap">` | unit |
| mixed — 1 direct section + wrapper holding 6 | unit |
| stray `<hr>` siblings become their own islands | unit |
| nested `<section>` | unit |
| balance: synthesize closers; drop element with no complete child | unit |
| libxml structural error → page degrade + warning | unit |
| `aboveFold.json` derived from design HTML | unit |
| `DesignMarkupSanitizer` re-sanitize per section | unit |
| missing artifact → page skipped + warning; front page fatal | unit |
| `postImages()` graph-awareness | unit |
| `cover-contrast` HTML path | unit |

**Write the wrapper case first.** It is the hostile-QA test: under the original plan's rule it produces a green build and a blank `contact.html`.

**Fixtures (Issue 12 → 12A):** hand-reduce minimal committed fixtures preserving the exact structural shape of `contact.html`, `about-services.html`, and `about.html`. The real corpus lives under `/projects/`, which is gitignored. A fixture that loses the shape tests nothing.

## Milestones

1. **Graph selection refactor.** `SITE_BUILD_GRAPH` across `StepComposition`, `SiteBuilder`, both CLI entry points, **and the wpcom peer**. Existing two graphs, no behavior change.
2. **`island-pages`.** Split (6A), balance (9B), parse degrade (10A), re-sanitize (7A), `aboveFold.json` derivation (3A), missing-artifact skip (8A), `island-report.json` (13A), `pages.json`, parts. Depends on 1. Shares a design-document helper with 3.
3. **`transform-chrome`.** Extract `HeaderUnit`/`FooterUnit`. Independent of 2.
4. **Graph assembly.** `StepComposition::htmlIslands()`, chrome-scope the retained repair steps, reduce `page-styles`, graph-aware `postImages()` + cover-contrast HTML path (4A), rewrite the kses comment. Depends on 2 and 3.
5. **Comparison gate.** Build a named prompt set on `--html-first` and `--html-islands` via `/Users/matt/git/site-builder-eval`, screenshot both, diff against `design/preview.html`, open each in the editor. **Gates milestone 6.** Budget: ~105k tokens/build × 2 arms × set size — a 5-prompt set is ~1.05M tokens, the only real expense in this plan. Fix the set size before starting.
6. **Delete html-first.** Tag first. Deletion commit, then the `designOwnsLayout` rename as a separate commit (11A). Add the retired-graph error to `resumeGraph()`. Update AGENTS.md; reword TODOS.md "Legacy pipeline retirement" to reference html-islands and retarget its trigger to milestone 5's evidence; mark "The design-failure fallback net is dead code" resolved.
7. **Sanitizer extension and SVG relaxation.** Depends on 6.
8. **Patterns.** Depends on 6 **and on `feature/deterministic-patterns` landing** (14A). ID-to-class mirroring, lift `core/html` from the forbidden list, return `extract-patterns` to the graph and the post-image list.

## Risks

**Global CSS collision with chrome.** Highest-probability source of visible breakage. Milestone 5 targets it; fallback scoped above.

**`header-hero`'s hero-facing repairs go blind.** Cover fold budget and hero-echo dedupe read block markup; on islands the hero is opaque HTML. Port them to read island HTML or accept a header that can duplicate the hero's CTA. Decide in milestone 4.

**Editor experience.** A `core/html` block previews as rendered HTML but edits only as source. Reordering is safe (0/4513 reorder-fragile). Milestone 5 includes seeing it before milestone 6 makes it irreversible.

**Milestone 6 is one-way.** Reversibility 1/5. The pre-cutover tag makes it forensically recoverable, not shippably reversible.

**Existing projects become unresumable.** Every `projects/*` recording `graph: html-first` loses `--from`. The `resumeGraph()` error makes it legible instead of silent.

**Premise untested until milestone 5.** Structural loss measures zero and visual loss is unmeasured. If milestone 5 shows a small delta, milestones 6–8 should not proceed.

---

## Review outputs

### NOT in scope

| Considered | Why deferred |
|---|---|
| Retiring the blocks graph (one-graph endpoint) | Declined during review; compounds an already one-way milestone 6 |
| Islands as a transform-failure fallback only | Declined; captures tail risk but no fidelity, SVG, or path reduction |
| Measure-first Phase 0 before building | Declined; milestone 5 absorbs the measurement |
| Per-section fidelity triage (mixed blocks + islands) | Declined; mixed page content is harder to reason about |
| Wrapper replication per island | Rejected — breaks `display:grid` wrappers |
| Rewiring `FallbackBuildPipeline` instead of deleting | Rejected; milestone 6 deletes it |

### What already exists

| Sub-problem | Existing code | Reused |
|---|---|---|
| Raw HTML in `post_content` | `ScaffoldPluginStep` kses suspension + HTML-API sanitize | unchanged |
| Island serialization | `core/html` → `SaveStrategy::RAW_CONTENT` | unchanged |
| Image paths in raw HTML | `AssignImageSourcesStep` + `CollectImagesStep::parseAssignedImages` | unchanged |
| Per-page CSS scoping | `PageStylesStep` + `PageScope::bodyClass()` | unchanged |
| Page artifact map | `InnerPagesDesignStep:189,407` | unchanged |
| Contrast checking | `CssContrastCheckEngine` (DOM matcher) | unchanged |
| Trust boundary | `DesignMarkupSanitizer` (frozen facade) | carried into `island-pages` |
| Chrome blocks | `HeaderUnit` / `FooterUnit` | extracted |
| A/B comparison | `/Users/matt/git/site-builder-eval` | milestone 5 |

Nothing is rebuilt in parallel.

### Dream state delta

```
  CURRENT              THIS PLAN                 TODOS.md 12-MONTH IDEAL
  ───────              ─────────                 ───────────────────────
  blocks +             blocks +                  ONE graph
  html-first           html-islands              ("two living generation
                                                   paths are maintenance debt")
  ~150KB repair code   minus 4 classes           slimmed to what fires
```

The plan moves sideways relative to the repo's stated target. `section-rhythm`, `normalize-layout`, and `extract-patterns` stay alive specifically because blocks needs them. Widening scope to close that gap was offered and declined; TODOS.md's "Legacy pipeline retirement" stays open, reworded in milestone 6.

### Failure modes registry

| Codepath | Failure mode | Rescued? | Test? | User sees | Logged |
|---|---|---|---|---|---|
| `island-pages` split | wrapper-only `<main>` | Y (6A) | Y | full page | island-report |
| `island-pages` split | stray `<hr>`/`<div>` siblings | Y (6A) | Y | own island | island-report |
| `island-pages` split | nested `<section>` | Y (6A) | Y | one island | island-report |
| `island-pages` balance | unbalanced island | Y (9B) | Y | closed or element dropped | island-report |
| `island-pages` parse | structural libxml error | Y (10A) | Y | degraded page + warning | warnings.json |
| `island-pages` artifact | design page absent | Y (8A) | Y | page skipped | warnings.json |
| `island-pages` artifact | front page absent | N (fatal) | Y | build aborts | fatal |
| `island-pages` aboveFold | contract underivable | Y (3A) | Y | build completes | island-report |
| `island-pages` sanitize | script-capable markup | Y (7A) | Y | stripped | warnings.json |
| `transform-chrome` | HeaderUnit malformed | Y | Y | fallback header | warnings.json |
| `postImages` | extract-patterns ineligible | Y (4A) | Y | nothing | n/a |
| `postImages` | cover-contrast no-op | Y (4A) | Y | correct contrast | report |
| `resumeGraph` | retired graph name | Y | Y | explicit error | fatal |

**No row is `RESCUED=N, TEST=N, USER SEES=Silent`.** The one fatal is deliberate and matches AGENTS.md's fatal list.

### Diagrams

System architecture, data flow with shadow paths, and the dependency before/after are in the review transcript; the split-algorithm table and failure registry above carry the decision content. **Stale diagram audit:** AGENTS.md's HTML-first step map is already stale on trunk (it lists `homepage-design` where the code has `design-preview` and `splice-home-design`, per TODOS.md). Milestone 6 corrects it.

### Completion summary

```
  +====================================================================+
  |            DEEP PLAN REVIEW — COMPLETION SUMMARY                    |
  +====================================================================+
  | Mode selected        | HOLD SCOPE                                   |
  | Pacing mode          | Standard                                     |
  | Review context       | Plan document (0 commits ahead of trunk)      |
  | System audit         | 2 CRITICAL: pattern stack collision,         |
  |                      | postImages unaddressed                       |
  | Step 0               | Approach B chosen over measure-first         |
  | Section 1  (Arch)    | 3 issues — aboveFold orphaned (CRITICAL)     |
  | Section 2  (Errors)  | 13 paths mapped, 6 GAPS — all closed         |
  | Section 3  (Security)| 6 threats, 0 High unmitigated                |
  | Section 4  (Data/UX) | 7 edge cases, 1 gap closed (10A)             |
  | Section 5  (Quality) | 2 issues — DRY helper, rename bundling       |
  | Section 6  (Tests)   | 11 cases, 9 newly added                      |
  | Section 7  (Perf)    | 0 issues                                     |
  | Section 8  (Cost)    | 4 NEGLIGIBLE, 1 NOTABLE (~1.05M tok gate)    |
  | Section 9  (Observ)  | 1 gap — island-report.json                   |
  | Section 10 (Deploy)  | 2 risks — peer break, one-way M6             |
  | Section 11 (Future)  | Reversibility 2/5; 2 debt items              |
  | Section 12 (Design)  | 0 blocking                                   |
  +--------------------------------------------------------------------+
  | NOT in scope         | 6 items                                      |
  | What already exists  | 9 reuses, 0 rebuilds                         |
  | Failure modes        | 13 rows, 0 CRITICAL GAPS remaining           |
  | TODOS.md updates     | 2 proposed, 2 accepted                       |
  | Issues raised        | 14 + 2 TODOs, all resolved                   |
  | Unresolved decisions | 0                                            |
  +====================================================================+
```

### Readiness verdict

**READY WITH CONDITIONS.** No CRITICAL GAPs remain in the failure registry. Address during implementation:

1. Write the wrapper-descent test (`contact.html` shape) before the split implementation — it is the case that produced a blank page under the original rule.
2. Extract the design-document load/locate/sanitize helper once, shared by `island-pages` and `transform-chrome`, before both classes are written.
3. Fix milestone 5's prompt-set size and budget before starting it.
4. Decide `header-hero`'s hero-facing repairs (port vs accept) during milestone 4.
5. Confirm `section-rhythm` and `normalize-layout` are not no-ops on chrome-only input during milestone 4.
6. Do not proceed past milestone 5 if the comparison shows a small visual delta — the premise is untested until then.
7. Milestone 8 waits on `feature/deterministic-patterns`.

---

## Amendments from implementation (2026-08-27)

Three decisions in the sections above were changed during milestones 2–3 because
measuring the corpus contradicted the premise they rested on. The sections above
are left as written; these override them.

### 6A is withdrawn — no wrapper descent

6A said to descend through non-section wrappers, on the premise that a naive
split "yields 0 islands — page lost". Both halves of that premise are wrong.

`TransformSiteStep::extractPage` has always islanded every **element** child of
`<main>`, not every `<section>` child. A wrapper-only page yields one unit, never
zero. No shipped code has the blank-page bug 6A was written to prevent; the bug
belonged to a hypothetical rule nobody proposed.

And the wrappers are not what 6A assumed. All 12 wrapper pages in the corpus
(of 300) carry the page's content column on the wrapper itself:

| page | wrapper rule |
|---|---|
| amber-ember2/contact | `.pg-wrap{max-width:var(--wide-size);margin:0 auto;padding:0 20px}` |
| swift-grove/about, /contact | `.page{max-width:var(--wide-size);margin:0 auto}` |
| clever-valley/contact | `.pg{max-width:…;margin:0 auto;border-left:1px solid var(--hairline);border-right:…}` |
| sunny-ember/contact | `.page-wrap{position:relative;overflow:hidden;background:radial-gradient(…)}` |
| (8 more) | `max-width:var(--wide-size); margin:0 auto; padding:0 <gutter>` |

**Zero carry `display:grid` or `display:flex`** — 6A's stated hazard does not occur
in the corpus at all. But its stated remedy does damage: descending re-parents the
children and drops the wrapper, so every island loses the content column and goes
full-bleed. `SectionLayoutStep`, which re-binds sections to the content column on
the blocks path, is dropped from this graph, so nothing restores it. Two pages
would lose more than the inset — a continuous hairline border down the page, and a
page-level radial background.

Descent trades a granularity gain on 4% of pages for a visual regression on those
same pages. **Split on direct element children of `<main>`; never descend.** A
wrapper-only page is one island, intact.

### 9B is withdrawn — no tag-stack scanner

9B asked `island-pages` to assert the tag stack per island and synthesize missing
closers. Islands are serialized out of the DOM, so they are balanced by
construction; a scanner over that output measures nothing. (Same instrument error
as using `saveHTML()` to detect source imbalance.)

The hazard 9B is reaching for is real but different: libxml silently **re-nesting**
a truncated document so later sections become children of an earlier one. A tag
scanner cannot see that. The structural-error check from 10A can. Balance is
therefore asserted as an output property plus a truncation case, not built as a
scanner.

### Milestone 2 splits in two

`aboveFold.json` derivation is separated from `island-pages` into its own slice,
because reading the consumers changed what the work is.

3A said to "derive the contract from the design HTML". In fact
`AboveFoldContract::resolve()` — which builds the whole contract — reads
`siteSpec`, the hero blueprint, `theme.json`, the canvas and the design CSS, and
**no markup at all**. Only `AboveFoldPartFacts::inspect()` reads markup, and of
the eight facts it returns, two need no change on this graph: `part_keys` is
filenames, and `header` parses `theme/parts/header.html`, which `transform-chrome`
still delivers as real blocks.

So the work is an **islands facts adapter** for the remaining five
(`opening_overlay_support`, `opening_surfaces`, the two `primary_action_*`, and
`hero`), not a second contract implementation. That matters beyond size: a
conservative adapter that reports "no overlay support" would make
`finalizeDelivery` degrade every islands build's header from overlay to stacked —
quietly, since that degrade path is designed to be routine. The adapter has to read
island HTML for real, and the degradation must be visible in `island-report.json`.

| slice | scope |
|---|---|
| 2a `island-pages` | split, parse degrade, re-sanitize, missing-artifact skip, `pages.json`, parts, `island-report.json` |
| 2b `island-above-fold` | the facts adapter + `aboveFold.json`; depends on 2a and on `transform-chrome` |

### Milestone 4's three open conditions, resolved by measurement

The review left three questions for "decide during milestone 4". All three were
answered by running the real code against island-shaped input, each with a
known-answer control on block input.

**`normalize-layout`: retain graph-wide, unchanged.** Both halves were probed.
`FixBlocksStep::normalizeLayouts` is a byte-for-byte no-op on a `wp:html` island
(nothing to stamp a layout on) and does real work on chrome in the same run
(stamped `layout:constrained` on a header group). `StorefrontDegrade::markup()`
operates on raw anchors, so it works on islands directly — it relabelled both
purchase CTAs in an island and repointed `/cart/` and `/checkout/` at `/contact/`.
No chrome-scoping is needed; the step is already correct on this graph.

**`section-rhythm`: drop from the islands graph.** Probed against an island page:
it does not throw and does not change the island, but it records one degradation
per page — `section 'hero' must contain exactly one top-level wp:group`. That is
one non-actionable warning per page per build, the same AGENTS.md rung-2 violation
that removed `extract-patterns` from the post-image list. Chrome-scoping it would
be meaningless: `planEntries()` reads only page section parts from `pages.json`,
so it has no chrome to work on.

**`header-hero`'s hero-echo dedupe: port it, do not accept the loss.** This is the
one that matters. `dedupeAgainstHero()` collects the hero's short lines with
`BlockMarkup::parse($heroMarkup)`, taking only `paragraph` and `heading` blocks.
An island hero parses as a single `html` block, so it yields zero lines. Measured
against a control:

| hero | header rewritten | notes | warnings |
|---|---|---|---|
| block markup | yes — echo removed | 1 | 1 |
| island | **no — echo survives** | **0** | **0** |

A header that repeats the hero's headline and CTA ships, with nothing in
`warnings.json` to say so. That is a `RESCUED=N, TEST=N, USER SEES=Silent` row,
which the failure registry claims does not exist — so the registry gains a row
and the port is required, not optional.

The port is small: `dedupeAgainstHero()` needs only the hero's short text lines,
so the block path stays and an island path reads `<p>` and `<h1>`–`<h6>` text out
of the raw HTML. Everything downstream of `$heroLines` is unchanged.

### RES-1, carried from milestone 1

`bin/images.php:39-55` (`images_html_first_from_project`) resolves the graph name
correctly and then flattens it to a boolean with
`=== StepComposition::GRAPH_HTML_FIRST`. On an islands project that yields
`false`, so `CollectImagesStep` is constructed as if this were the blocks graph
and stops reading prose alts as image subjects. Its fallback heuristic fails the
same way: absent `design/transform-report.json` — which this graph never writes —
it guesses `blocks`. Milestone 4 must keep the graph a string end to end.

### 4A's cover-contrast path, re-derived

4A said `cover-contrast` "must find hero and background images in island markup
instead". Measuring the corpus says the mechanism it would look for does not
exist on this graph, and the one that replaces it is different in kind.

Across all 300 design pages:

| measure | count |
|---|---|
| CSS rules with a `background: url()` / `background-image: url()` | **0** |
| `<img>` tags | 1,012 |
| files with a `position:absolute` rule | 241 of 300 |
| absolute rules that also set a text `color` | 151 |

There are **no CSS background images anywhere in the corpus**. Every image is an
`<img>` in flow. So there is nothing for a background-image finder to find.

Text over an image is authored instead as a **pseudo-element scrim** over an
`<img>`. The most common absolute selectors in the corpus are exactly that:

```
75  .hero-media::after      56  #hero::before      18  #hero img
67  .hero-media             55  #hero::after       16  .hero-photo::after
```

So the islands equivalent of "raise `dimRatio`" is "adjust the authored
`::before`/`::after` scrim, or flip the text colour" — a CSS edit, not a block
attribute edit. The reason the step exists is unchanged: the model chose the text
colour before the image existed.

Two consequences for scheduling:

1. This is not a bullet inside milestone 4. It is its own slice, and it needs a
   contrast instrument that can reason about a pseudo-element scrim.
   `CssSelectorMatcher::matches()` takes a `DOMElement` and has an
   `accountForPseudoStateSuffix` flag; whether it can address `::after` at all is
   the first thing to settle.
2. Until it exists, `cover-contrast` on the islands graph is a **no-op that must
   say so** — one report line per build, not silence. Its block path still does
   real work on the chrome parts, which stay block markup, so the step is not
   dropped.

---

## Milestone 5 result (2026-08-27): STOP. Do not proceed to milestone 6.

Ten builds, five prompts, one arm per graph, same codebase. ~1.01M tokens.

### What the gate found

**Content fidelity does not separate the arms.** Both preserve 100% of authored
section ids and all images. A 72.2% text score on one html-first case was an
instrument artifact — tracklist titles and durations split across separate
nodes — not loss. `transform-report.json` reads clean, as it always has.

**The islands arm loses the styling system.** This is the visual channel the plan
said was unmeasured. It is now measured, and it fails.

The design documents ship only a skeleton stylesheet — 436 bytes to 4.8KB — and
carry a class vocabulary they never style: on one build the delivered page used
37 design classes and only 7 had a matching rule in `theme/style.css`. On
`html-first` that does not matter, because the transformer compiles the design
into blocks and `theme.json` supplies grid, spacing, measure and type scale.
Islands preserve the authored markup and nothing styles it. Heroes render well;
everything below falls back to browser defaults.

This is structural, not a defect to patch. The premise — that delivering the
authored HTML raises fidelity — assumed the design document was the source of
truth for appearance. It is not. The block layer is.

### Where the arms do win

| measure | html-first | html-islands |
|---|---|---|
| authored section ids kept | 100% | 100% |
| warnings raised | 1,222 | 701 (−43%) |
| delivered page weight | 157 KB | 57 KB (−64%) |
| tokens | 521k | 490k (−6%) |
| wall clock | 1,111s | 1,054s (−5%) |
| renders as designed | yes | hero only |

The token and wall differences are inside run-to-run noise. The warning and page
weight reductions are real and large.

### Two defects the gate surfaced, both fixed

1. **Every image lost its source.** `DesignMarkupSanitizer` strips the `theme:`
   scheme, and 7A told `island-pages` to re-sanitize each section. Measured 9
   images with 0 sources against html-first's 9 of 9. 7A's premise was wrong:
   `TransformSiteStep` sanitizes only *repair* fragments, so html-first never
   re-sanitizes after `assign-image-sources` writes `theme:` paths. Fixed by
   allowing the `theme:` scheme, tested in both directions.
2. **The degrade path aborted the build.** `stackedFallbackContract()` emitted a
   contract with an empty `following_section.layout_archetype`, which
   `validate-theme` treats as fatal. A degrade that makes a later step fatal is
   worse than no degrade.

### What to do instead

The graph is built, tested and works. Milestones 6–8 do not proceed. The
surviving reasons to keep it are tail risk, design vocabulary (SVG), and code
removed — none of which justify deleting `html-first`. Options, in order:

1. **Keep both graphs, ship neither change.** `html-islands` stays available
   behind `SITE_BUILD_GRAPH` for the tail-risk and SVG cases. Cheapest.
2. **Make the design document a complete stylesheet.** The real blocker is that
   `inner-pages-design` authors classes it does not style. If the design shipped
   its own full CSS, islands would deliver it verbatim and the premise would
   hold. That is a prompt-and-validation problem, not a pipeline one, and it is
   worth measuring before anything else.
3. **Retire the branch.** Only after 2 is ruled out.

Do not delete `html-first` on this evidence.

---

## Milestone 5 verdict CORRECTED (2026-08-27): the first reading was wrong

The verdict recorded above compared arm against arm and concluded the islands
graph "loses the styling system". That comparison was the wrong one, and the
conclusion does not survive the right one.

The plan's own milestone 5 says to **diff against the design**. Doing that
inverts the result.

### The measurement that matters

Sections delivered **verbatim** — the authored `<main>` child's text present
unchanged in the delivered page — across all five cases:

| graph | verbatim | rate |
|---|---|---|
| **html-islands** | **34 / 34** | **100%** |
| html-first | 28 / 36 | 78% |

Islands deliver the design document exactly. On `artist-music` the delivered
island payload is 102.2% of the authored `<main>` markup by byte — the extra 2%
is the `theme:` src rewrite and whitespace. That is the graph's entire stated
purpose, and it works.

### The screenshots answer the question directly

Rendering `design/home.html` in a browser beside the delivered site:

- **islands**: the design render and the delivered page **match**. Same hero,
  same typography, same below-fold plainness. Whatever the design authored is
  what ships.
- **html-first**: they do **not** match, and not subtly. On `artist-music` the
  design document renders as a bare unstyled page — Times New Roman, blue
  underlined links, default bullets, no colour — while the delivered site is a
  polished dark editorial page. `theme.json` and the block layer invent the
  entire visual identity.

### What the earlier verdict actually measured

Two errors compounded:

1. **Wrong baseline.** Arm-vs-arm cannot answer a fidelity question. It measured
   which output looked nicer, not which matched its source.
2. **A false generalisation from one case.** I recorded "the islands designs ship
   a 436-byte skeleton". Measured across all ten builds, the 436B skeleton
   appears in three islands cases **and two html-first cases**. It is
   design-generation variance hitting both graphs equally, not a property of
   either.

Where the design's own CSS is thin, islands faithfully render a thin design and
html-first papers over it with theme.json. That is a difference in *what the
graphs are for*, not evidence against islands.

### Revised standing

**Milestone 6 is not blocked by fidelity.** On the criterion the plan set —
does the delivered page match the authored design — islands wins outright,
34/34 against 28/36, and is the only graph whose render matches its design.

The open question is no longer "is islands faithful" but **"should the design
document be the source of truth for appearance?"** The graph assumes yes. If
that holds, the real work is upstream: `inner-pages-design` emitting a 436-byte
stylesheet on 5 of 10 builds is the defect worth fixing, and it degrades both
graphs — it is simply visible on islands and hidden on html-first.

Deleting html-first still needs a deliberate decision, because the block layer
demonstrably adds design that thin documents lack. But it must be argued on that
basis, not on a fidelity claim that measurement contradicts.
