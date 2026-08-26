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
