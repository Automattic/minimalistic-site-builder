# Porting Telex's Theme Design Intelligence into builder2

**Audience:** a coding agent working in `/home/matias/dev/a8c/builder2/`.
**Goal:** make builder2 produce themes of the same aesthetic quality as Telex's
one-shot assistant, by transplanting Telex's *design intelligence* (the rich
aesthetic guidance baked into its prompts) into builder2's existing pipeline —
**without** copying Telex's architecture.

This document is based on the Telex revision currently checked out at
`/home/matias/dev/a8c/telex/` (trunk @ `624366e7`). All Telex references below
point at `/home/matias/dev/a8c/telex/server/prompts/`.

---

## 1. The core idea (read this first)

Telex and builder2 already share the **same conceptual pipeline**:

> classify → spec → commit to a design direction → theme.json (tokens) →
> plan sections → generate markup → assemble → images.

The difference is **not structure** — it's the **density and specificity of the
design guidance** inside the prompts. Telex's prompts carry hundreds of lines of
hard-won "design intelligence" (anti-AI-slop rules, typographic scales, hero
composition theory, color strategy, layout-width discipline, card-layout
recipes). builder2's prompts are correct and clean but **thin** on this guidance,
so its themes converge toward safe/generic output.

**The port is therefore prompt-level, not code-level.** You are enriching
builder2's existing prompt files with Telex's design intelligence, adapted to
builder2's constraints (core blocks + theme.json tokens, no Tailwind, no
multi-page). You should need **little or no change to the PHP pipeline.**

### The "4 designs" exclusion is already handled

The user asked to exclude Telex's "generate 4 designs and let the user pick one"
step. **builder2 never had it.** Telex generates four `style-directions.md`
directions, renders four `design-previews.md` HTML previews, and pauses for a
human pick (`GenerateDesignPreviewsTask` + `selectDesign()`), spanning two HTTP
requests. builder2's `DesignDirectionStep` already does the single-direction
equivalent: it commits to **one** creative concept in `designDirection.md` and
every downstream step reads it. So:

- **Do NOT** port `style-directions.md`'s "generate 4 variations" machinery,
  `design-previews.md`'s full HTML-preview generation, `design-selection.md`, or
  `design-acknowledgment.md`.
- **DO** harvest the *design-intelligence content* embedded in those files (hero
  composition theory, anti-patterns, topic grounding) and fold it into
  builder2's single `design-direction.md`. That content is the valuable part;
  the four-way fan-out is the part we drop.

---

## 2. Architecture crosswalk

| Concern | Telex (one-shot assistant) | builder2 (today) |
|---|---|---|
| Orchestration | Task queue, `QueuedAssistantController::buildTaskQueue()`, streaming, 2 HTTP requests | Deterministic `Pipeline` of `Step`s, one CLI run (`bin/build.php`) |
| Output format | One big `<artefact>` **XML** validated against `spec/artefact.xsd` | Files written directly to `projects/<slug>/theme/` |
| Markup vocabulary | Core blocks **+ Tailwind utility classes + custom blocks + style.css** | **Core blocks only**, styled via theme.json presets; `style.css` is header-only |
| Pages | Multi-page (`content/pages/*.html`, CPTs, content-loader) | **Single landing page** (`front-page.html` = header + section parts + footer) |
| Design selection | 4 directions → 4 previews → human pick | **1** committed direction (`designDirection.md`) — no pick |
| Guides | LLM-selected `guides/*.md` injected (`guide-selection.md`) | none |
| Images | `AI_IMAGE:` alt placeholders → Imagen via WPCOM proxy | **Same `AI_IMAGE:` convention** → `WpcomImageClient` (Imagen) |
| Models | per-step (haiku/sonnet/opus/gemini) in `server/config.json` | per-step in `step_models()` (`src/bootstrap.php`) |

### Step-for-step mapping (what to enrich)

| Telex prompt / section | builder2 target | Action |
|---|---|---|
| `project-type-classification.md` | (n/a — builder is theme-only) | skip |
| `site-spec-generation.md` | `prompts/site-spec.md` | minor: keep builder's "facts only" split; optionally adopt Telex's richer inference examples |
| `style-directions.md` (grounding, anti-patterns, hero theory) | `prompts/design-direction.md` | **enrich heavily** |
| `create-project-theme.md` → "Design Thinking" + "Frontend Aesthetics" | `prompts/design-direction.md` **and** `prompts/theme-json.md` | **enrich heavily** |
| `create-project-theme.md` → "Frontend Aesthetics" (type scale, line-height, layout widths, color) | `prompts/theme-json.md` | **enrich heavily** |
| `create-project-theme.md` → "Generating pages" + "Card layouts in rows" + `design-previews.md` (hero layout variety, visual richness) | `prompts/section.md` | **enrich heavily** |
| `create-project-theme.md` → footer credit, sticky nav, navigation block | `prompts/header.md`, `prompts/footer.md` | enrich |
| `image-generation-instructions.md` | `prompts/image-generation.md` | already strong; reconcile small diffs |
| `create-project-theme.md` → home page "maximalist", auxiliary page calibration | `prompts/section-plan.md` | adapt (single-page: fold "maximalist home" energy into the hero + overall page) |
| `guides/*.md`, CPT/data-persistence/custom-block rules, artefact XML/XSD | — | **exclude** (out of builder's scope; see §6) |

---

## 3. Adaptation rules (Telex → builder constraints)

Telex assumes capabilities builder2 does not have. Translate, don't copy:

1. **No Tailwind.** Telex tells the model to use Tailwind motion utilities
   (`transition`, `duration-300`, `motion-safe:animate-fade`) and utility
   classes. builder2 emits **core blocks only**. Drop Tailwind references.
   Express motion/atmosphere through what core blocks + theme.json + inline
   `style` allow: `wp:cover` with `hasParallax`, dimRatio overlays, gradient
   backgrounds (theme.json `settings.color.gradients` + `wp:cover`/group
   `gradient` attr), spacing rhythm, and inline `style` on group/heading
   wrappers. If you want CSS-only effects (e.g. equal-height cards, entrance
   animations), append them to the scaffolded **`theme/style.css`** (see rule 3).
2. **Style via theme.json preset slugs, not arbitrary hex.** builder2's
   contracts fix five color slugs (`base`, `contrast`, `primary`, `secondary`,
   `accent`) and two font slugs (`heading`, `body`). Telex's "use specific hex /
   name specific fonts in markup" guidance must be **redirected**: concrete hex
   and font *names* are chosen once, in `theme-json.md`; section markup
   references them by **slug** only. Keep this invariant — downstream assembly
   and `ThemeValidator` rely on it.
3. **`style.css` is available but currently header-only.** `ScaffoldThemeStep`
   writes `theme/style.css` with just the theme header. If you port Telex's
   card-layout CSS (`.equal-cards …`) or any CSS-only effect, either (a) append
   a small, fixed CSS block to that scaffold, or (b) add a deterministic step
   that appends design-direction-appropriate CSS. Prefer (a) for the universal
   equal-cards rules; keep it minimal. **Do not** introduce `<style>` tags in
   block markup — Telex strips them and builder has no place for them in parts.
4. **Fonts must actually load.** Telex enqueues Google Fonts via a
   `functions.php` `enqueue_block_assets` hook. builder2 has no `functions.php`.
   If `theme-json.md` picks real Google fonts, they will be referenced but not
   loaded. **Decision point for the implementer:** either (a) add a tiny
   deterministic step / scaffold that writes a `functions.php` enqueuing the
   chosen fonts (read the `heading`/`body` families from theme.json), or (b)
   embed `@font-face`/`fontFace` `src` URLs in theme.json `fontFamilies`.
   Option (a) matches Telex (see `create-project-theme.md` lines 152–164).
   Without one of these, "distinctive typography" guidance is cosmetic. **Flag
   this to the user if unspecified.**
5. **Single page, not many.** Telex's multi-page rules (auxiliary page
   calibration, `content/pages/*.html`, content-loader, CPT sample content) do
   **not** apply. Concentrate all the "home page is the centerpiece /
   maximalist" energy into builder's one landing page — primarily the hero plus
   a richer, well-sequenced section set.
6. **No artefact XML.** Telex's `--- artefact.xml ---` / XSD / streaming-format
   rules are Telex-transport-specific. builder writes files directly. Ignore all
   of it.

---

## 4. Per-file porting instructions

For each target, the design intelligence to inject is quoted/summarized from the
Telex revision. Keep builder2's existing structural rules; **add** the design
guidance. Respect builder2's prompt style: end with the existing "Output ONLY …"
instruction so parsing stays intact.

### 4.1 `prompts/design-direction.md` — the creative seed

This is the highest-leverage file: every later step reads `designDirection.md`.
builder2's version is good but short. Fold in, from Telex:

- **From `style-directions.md` "Design Grounding":** "Think like a specialist
  designer hired for this exact brief. Ground the direction in real-world visual
  traditions connected to the site's topic — the materials, spaces, cultural
  references, and design conventions of its industry. A Georgian restaurant
  evokes Caucasus earth tones and ornate patterns; a photojournalist portfolio
  evokes high-contrast editorial layouts and documentary rawness. Directions
  should feel researched, not generated from a generic style menu."
- **From `style-directions.md` "Anti-Patterns" + `create-project-theme.md`
  line 128:** explicitly forbid generic AI output — purple gradients on white,
  safe blue/gray corporate schemes, arbitrary rainbow accents; the fonts Inter,
  Roboto, Arial, Open Sans, system fonts; the "text left, image right" default
  hero; and **topic-agnostic styles** ("if you could swap the site topic and the
  direction still works unchanged, it's too generic"). Add Telex's caution
  (line 130) to **not converge on the same trendy pick across generations**
  (it calls out Space Grotesk by name).
- **From `style-directions.md` "Hero Section — The First Impression":** the
  direction must describe a **distinctive hero composition** cinematically —
  spatial composition (where the eye lands first: centered title-card,
  asymmetric editorial spread, diagonal split, edge-to-edge bleed), image
  treatment (full-bleed vs contained frame vs overlap), typography staging
  (massive display vs elegant understatement, text interacting with imagery),
  and rhythm (expansive/slow vs compact/energetic).
- **From `create-project-theme.md` "Design Thinking" (lines 99–112):** treat
  vague prompts as **creative freedom, not a reason to default to safe** —
  invent a distinctive identity; pick an extreme tone (brutally minimal,
  maximalist, retro-futuristic, organic, luxury, editorial, brutalist, art-deco,
  industrial, …); commit fully. "Choose a clear conceptual direction and execute
  it with precision. Bold maximalism and refined minimalism both work — the key
  is intentionality, not intensity."

builder2 already has the "commit to ONE concept, avoid the obvious default,
two sites never get the same direction, ~150-word markdown brief" framing —
**keep it**. The brief should now also explicitly call out the **hero
composition strategy** so `section.md` can honor it. Keep "strategy not hex /
not final font names" (those are chosen in theme.json).

### 4.2 `prompts/theme-json.md` — encode the aesthetic as tokens

builder2's theme-json prompt has solid hard requirements. Add Telex's
**`create-project-theme.md` "Frontend Aesthetics"** intelligence, translated to
theme.json:

- **Typography scale (lines 117–119):** "Choose fonts that are beautiful,
  unique, characterful — avoid Arial/Inter; pair a distinctive display font with
  a refined body font." Encode a **grounded, usable size scale**: body 1rem;
  headings scale modestly (h1 ≤ 2.5–3rem); use `clamp()` for display but cap
  ~3.5rem; avoid sizes above 4rem. Telex's recommended 6-step scale:
  `0.875 / 1 / 1.25 / 1.75 / 2.25 / clamp(2.5rem, 4vw, 3.5rem)`. Map this onto
  `settings.typography.fontSizes` (give slugs) and the `styles.elements.h1/h2/h3`
  sizes. **Line height:** body 1.5–1.65, headings 1.1–1.3, never below 1.0 —
  set `styles.typography.lineHeight` and
  `styles.elements.heading.typography.lineHeight`.
- **Color strategy (line 120):** "Commit to a cohesive aesthetic. Dominant
  colors with sharp accents outperform timid, evenly-distributed palettes."
  Reinforce builder's existing rule that **accent is reserved for CTAs /
  interaction only**.
- **Layout & container widths (line 124):** `contentSize` 800–900px (not 640),
  `wideSize` 1200–1400px. (This matches `design-previews.md`'s 800/1280
  constants.) Keep builder's `settings.layout` requirement, just pin the ranges.
- **Backgrounds/atmosphere (lines 123/125):** prefer gradient meshes, layered
  transparencies, dramatic shadows, decorative borders over flat solids where it
  fits the direction — expose this via theme.json `settings.color.gradients` and
  a couple of `settings.shadow` presets the sections can use.
- Keep builder's exact five color slugs / two font slugs / spacing-scale
  contract — **do not loosen it.** The design intelligence steers the *values*;
  the slugs stay fixed.

### 4.3 `prompts/section.md` — where most quality is won or lost

This generates each section's block markup. Inject, adapted to **core blocks**:

- **From `create-project-theme.md` "Generating pages" universal rules
  (lines 170–184):**
  - Treat every section as a **self-contained block** with one dominant semantic
    wrapper (builder already wraps in one top-level group — keep it).
  - **Section margin reset:** add `"style":{"spacing":{"margin":{"top":"0"}}}`
    to the top-level group of each section.
  - **Section width discipline:** heroes, covers, and feature grids use
    `"align":"wide"` or `"align":"full"`; reserve default (content) width for
    text-heavy reading sections only.
  - **No decorative HTML comments** (`<!-- Hero Section -->` etc.) — only
    `wp:` block comments. (Telex `create-project.md` lines 158–159 too.)
  - Write **real, specific copy** in brand voice grounded in the spec — never
    lorem ipsum. (builder already says this — keep.)
  - Bold, asymmetric, overlapping, creative-whitespace layouts that match the
    direction's mood — not the safe default.
- **From `design-previews.md` "Hero image layout variety" (lines 140–153):**
  the hero must **not default to "image on the right."** Offer the menu:
  full-bleed background with overlaid text, left-aligned image, centered/stacked,
  asymmetric/grid-breaking, partial coverage, split-diagonal, framed/inset.
  Pick the one that serves the committed direction (which `design-direction.md`
  now names). Express full-bleed heroes as `wp:cover` with an
  `wp-block-cover__image-background` (builder's `image-generation.md` already
  shows this exact pattern).
- **From `design-previews.md` "Creating Visual Richness" (lines 206–216):**
  beyond the one hero image, build atmosphere with CSS gradients/color blocks/
  typographic scale/shadows/borders/spacing — via theme.json presets and inline
  `style`, **not** external images and **not** `<style>` tags.
- **From `create-project-theme.md` "Card layouts in rows" (lines 220–261):**
  port the equal-height/equal-width card recipe. In core blocks: `wp:columns`
  (add a class like `equal-cards`) → each `wp:column` with
  `verticalAlignment:"stretch"` and `width` = `100 / N` % so all columns sum to
  100%. Images in cards `style="height:200px;object-fit:cover;width:100%"`. The
  supporting CSS (`.equal-cards > .wp-block-column { display:flex; … }` and the
  `.cta-bottom { margin-top:auto }` rule) must live in **`theme/style.css`** (see
  §3 rule 3) since builder has no per-section `<style>`. Add a class hook on the
  column block and append the CSS block in the scaffold.
- **NO EMOJIS anywhere** (Telex repeats this everywhere: `create-project-theme.md`
  line 21, `design-previews.md` line 5). Add it.
- Keep builder's "core block markup only / reference presets by slug / accent =
  CTA only / output starts with `<!-- wp:`" rules verbatim.

### 4.4 `prompts/header.md` & `prompts/footer.md`

- **Navigation (from `create-project-theme.md` line 12):** the header nav should
  use `<!-- wp:navigation -->` containing `<!-- wp:page-list /-->` by default
  (auto-reflects pages) unless a curated menu is wanted. Consider a **sticky
  nav** when it suits the design (line 59). builder's header prompt currently
  says "primary wp:navigation" — make the page-list default explicit.
- **Footer credit (from `create-project-theme.md` lines 22–23):** include a
  small credit line at the very bottom, styled to match the theme. Telex's line
  credits Telex/WordPress; for builder, use an equivalent neutral credit (or a
  builder/WordPress credit) — confirm wording with the user. builder's footer
  prompt already asks for "a small credit line" — keep, and specify it adapts to
  the theme's type/colors.
- Apply the same color-slug / accent-for-CTA-only discipline (already present).

### 4.5 `prompts/section-plan.md`

builder's plan prompt is already close to Telex's intent. Adapt the **single
high-impact home page** idea (`create-project-theme.md` lines 185–197) since
builder has only the landing page:

- The page is the centerpiece — give it the most creative energy; **minimum 3
  unique, image-rich content sections** plus hero and a strong closing CTA
  (builder already requires hero first, cta/contact last — keep).
- Tailor section choice to `site_type` (portfolio → project gallery; SaaS →
  feature grids; restaurant → menu/reservations; agency → case-study cards;
  blog → latest posts) — Telex's examples at lines 191–197.
- Let the design direction's **signature device and mood** drive which sections
  exist and how they're framed (builder already says this — reinforce).
- Imagery discipline lives in the section-generation step (only where imagery genuinely helps) rather than a per-section plan flag.

### 4.6 `prompts/image-generation.md`

builder's image instructions are **already excellent** and in some ways ahead of
Telex (builder added a `page-context` field: `subject | page-context | style |
aspect-ratio`). Telex uses `description | style | aspect-ratio`
(`image-generation-instructions.md`). **Keep builder's richer 4-field format** —
just confirm `CollectImagesStep` parses it (it does today). Only reconcile if you
notice drift: both share the `AI_IMAGE:` marker, the `theme:./assets/` prefix,
the style enum, aspect ratios, and the **grid/row consistency rule** (same aspect
ratio for sibling images) — all already present in builder. No change required
beyond a consistency pass. (Note: builder uses `.jpg`, Telex uses `.png` — keep
builder's `.jpg`; it's wired to `WpcomImageClient`.)

---

## 5. Suggested implementation order

1. **`design-direction.md`** — biggest leverage; enrich first (§4.1). Rebuild a
   couple of demos and eyeball `designDirection.md`: is the concept specific,
   topic-grounded, and does it name a non-default hero composition?
2. **`theme-json.md`** — encode the type scale, line-heights, width ranges,
   color strategy, gradients/shadows (§4.2). Verify `ThemeValidator` still
   passes (five color slugs, two font slugs, version 3, spacing scale intact).
3. **`section.md`** — the richest port: section discipline, hero variety, card
   recipe, visual richness, no-emoji, no-decorative-comments (§4.3). Add the
   `.equal-cards` CSS to the `style.css` scaffold and the column class hook.
4. **`header.md` / `footer.md`** — page-list nav default, optional sticky,
   credit line (§4.4).
5. **`section-plan.md`** — concentrate "centerpiece" energy, type-specific
   section menu (§4.5).
6. **Fonts loading decision** (§3 rule 4) — add `functions.php` enqueue or
   theme.json `fontFace` src so chosen fonts actually render. **Ask the user**
   which approach if unspecified.
7. **`image-generation.md`** — consistency pass only (§4.6).

Each prompt is plain text rendered by `PromptRenderer::render()` with
`{{placeholder}}` substitution; **every `{{placeholder}}` must resolve** or the
build fails loud, so don't introduce new placeholders without wiring them in the
corresponding `Step::requests()`/`run()`. Most of this port adds *prose*, not
placeholders, so the PHP stays untouched.

---

## 6. Explicitly DO NOT port

These are Telex-specific or out of builder2's scope. Skip them entirely:

- **The 4-design generation + selection** — `style-directions.md`'s 4-way
  fan-out, `design-previews.md`'s full HTML preview rendering,
  `design-selection.md`, `design-acknowledgment.md`,
  `GenerateDesignPreviewsTask`, `selectDesign()`, the two-request pause. (Harvest
  their *design-intelligence prose* into `design-direction.md`, per §4.1; drop
  the mechanism.)
- **Artefact transport** — `--- artefact.xml ---` format, `spec/artefact*.md`,
  `spec/artefact.xsd`, the streaming `ArtefactStreamProcessor` rules in
  `create-project.md`.
- **Guide selection/injection** — `guide-selection.md`, `ContextGuideService`,
  `guides/*.md` (wp-best-practices, blueprint, wpds, etc.). builder's scope is a
  single landing-page theme; these target multi-feature builds.
- **Multi-page / content system** — `content/pages/*.html`, `content-loader.php`,
  the `<!-- telex:meta -->` header, "always register pages on activation",
  auxiliary-page calibration. builder builds one page.
- **CPTs & sample content** — "every CPT ships sample content",
  `content/cpt/*`, `content-cpt-seeding.md`.
- **Data persistence & custom blocks** — "custom blocks for named features",
  `data-persistence-pattern.md`, CPT+REST+block wiring, `blocks-inside-themes`,
  `wp-abilities-api`, `interactivity-api`. builder emits core blocks only.
- **Project-type classification** — builder is theme-only.
- **Cover/description generation** — `project-description.md`,
  `CoverGenerationService` (a Telex gallery feature).
- **Telex persona / response-format rules** — `create-project.md`'s "You are
  Telex", narrative-before-artefact, communication tone. builder steps return
  raw artifacts, not chat.

---

## 7. Acceptance criteria & verification

You're done when a fresh build visibly clears the "AI slop" bar Telex targets:

1. **Build the demo set** and inspect output + screenshots:
   ```bash
   php bin/build-demos.php --with-images
   ```
   Each build writes `projects/<slug>/logs/home.png` (headless Playground
   full-page screenshot) and `projects/<slug>/logs/project.log`.
2. **Per-theme design checks** (the things the ported intelligence should fix):
   - The hero is **not** the default "image-right, centered sans-serif" — it
     uses the composition named in `designDirection.md`.
   - Typography: distinctive display + body pairing (no Inter/Roboto/Arial),
     sane scale (no >4rem monsters), readable line-heights.
   - Color: cohesive, dominant-with-accent; accent only on CTAs; not
     purple-on-white or generic blue/gray.
   - Two different prompts produce **visibly different** directions (run the same
     prompt twice and confirm divergence, per Telex's "never converge" rule).
   - Cards in rows are equal-height/equal-width and sum to 100% (the
     `.equal-cards` CSS landed in `style.css`).
   - No emojis; no decorative HTML comments; widths use the 800/1200–1400 ranges.
   - Chosen fonts actually **render** (fonts-loading decision from §3 rule 4).
3. **Structural validation still passes** — `FinalizeThemeStep` /
   `ThemeValidator` report no problems (five color slugs, two font slugs,
   theme.json v3, balanced block grammar, no unfilled `{{placeholders}}`),
   and `php tests/run.php` is green.
4. **Spot-check `designDirection.md`** for a few demos: is it specific, topic
   grounded, committed (not hedging), and does it name a signature device and a
   cliché-to-avoid?

---

## 8. Quick reference — source locations

**Telex design intelligence (harvest from):**
- `server/prompts/create-project-theme.md` — Design Thinking (99–112), Frontend
  Aesthetics (114–134), Generating pages (170–218), Card layouts (220–261),
  footer credit (22–23), navigation (12), sticky nav (59).
- `server/prompts/style-directions.md` — Design Grounding (13–14), Differentiation
  (16–25), Hero theory (26–36), Anti-Patterns (37–45). *(content only — not the
  4-way mechanism.)*
- `server/prompts/design-previews.md` — Aesthetics (8–17), hero layout variety
  (139–153), visual richness (206–216), layout widths (56–105). *(content only.)*
- `server/prompts/image-generation-instructions.md` — image convention (builder
  already exceeds this).
- `server/prompts/site-spec-generation.md` — inference examples.

**builder2 targets (edit these):**
- `prompts/design-direction.md`, `prompts/theme-json.md`, `prompts/section.md`,
  `prompts/section-plan.md`, `prompts/header.md`, `prompts/footer.md`,
  `prompts/image-generation.md`.
- `src/steps/ScaffoldThemeStep.php` — append `.equal-cards` CSS to `style.css`;
  (optional) write `functions.php` for font enqueue.
- `src/StepDefaults.php` / `src/SiteBuilder.php` — model defaults and the default
  pipeline composition if you tune models or add a fonts/CSS step. Pipeline
  order already matches Telex's intent; no reordering needed.

**builder2 invariants to preserve:**
- Five color slugs `base/contrast/primary/secondary/accent`; two font slugs
  `heading/body`; theme.json version 3; spacing scale `xs/sm/md/lg/xl/xxl`.
- Sections reference presets **by slug**; markup is **core blocks only**; output
  starts with `<!-- wp:`; no `<style>` tags in parts.
- Every `{{placeholder}}` in a prompt must be wired in its `Step`.
</content>
</invoke>
