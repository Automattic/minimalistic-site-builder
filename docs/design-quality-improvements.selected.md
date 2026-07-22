# Improving creativity and design quality of generated sites
---

## B. Quality the pipeline generates and then destroys

### B1. FixBlocksStep strips the inline styles the prompts mandate — P0

The section prompt *requires* card images to carry `style="height:200px;object-fit:cover;width:100%"`, but the
Node block fixer re-serializes to canonical `save()` output and drops any style not declared in the block-comment
attributes. Evidence in both builds:

- `portfolio14/logs/fix-blocks.log`: `core/image: Expected attribute 'style' of value 'border-radius:0px', saw 'border-radius:0px;height:200px;object-fit:cover;width:100%'` (×6) — card grids ship with uneven, uncropped images.
- portfolio15: same loss ×9 in `section-bodies-of-work.html` (images left with **no** sizing at all).
- portfolio14 hero: `padding-right` stripped from the masthead column — text sits flush against its hairline rule.
- portfolio15: `box-shadow:var(--wp--preset--shadow--quiet)` stripped from series images.

**Fix options (do at least the first):**
- Prompt fix: require every inline style to be mirrored in the block-comment JSON (`"style":{"dimensions"...}` etc.) so the serializer preserves it — cheap but relies on model discipline.
- Fixer fix: diff pre/post markup per file and log/repair dropped `style`/`class` attributes instead of silently discarding them.
- Structural fix: express card-image cropping via theme-level CSS (see B5/C3) instead of per-image inline styles, so there is nothing for the fixer to strip.

### B2. Designs use font weights that are never loaded — P0

Both directions called for light display type; portfolio15 renders its Cormorant Garamond headings with
`font-weight:300` — but `functions.php` requests only `wght@400;600;700`, so the browser faux-lightens or
falls back to 400. Also `FontsStep` (weights `400;700`, `enqueue_block_assets`) is later **overwritten** by
`FinalizeThemeStep` (weights `400;600;700`, `wp_enqueue_scripts`) — duplicated responsibility, and editor
font loading is lost.

**Fix:** one font step; scan theme.json + section markup for every `fontWeight`/`font-weight` used and request
exactly those (plus italics if used) in the Google Fonts URL. Trivially verifiable in eval.

### B3. Link color makes text invisible on dark sections — P0

theme.json sets `elements.link.color` to `primary` globally (portfolio14: muted slate `#4B4E52`). On the
ink-black contact section and footer the email, phone, and "get in touch" links are unreadable — in the
screenshot the contact block appears to have a missing email and the footer reads "galleries: ." because the
link is invisible.

**Fix:** in the theme-json prompt, require link colors that work on both base and contrast backgrounds
(e.g. `currentColor` inheritance in dark groups), and/or add a section-prompt rule: any group with a dark
background must explicitly set link/text colors of children. A post-build contrast lint (WCAG check of
text/link color vs nearest background attribute) would catch this class of bug deterministically — and is
exactly what the A2 critique pass would flag visually.

### B5. The token system is bypassed for display typography — P1

Both theme.jsons define a `display` font size that **no section ever uses** (portfolio14 parts: `small` ×42,
`xx-large` ×3; the big year numerals are hardcoded `clamp(2.75rem, 5vw, 4.25rem)` — the identical clamp in
both projects, another convergence tell). Meanwhile theme-json prompt caps h1 at ~3.5rem ("avoid anything
above 4rem"), which the sections then violate ad hoc.

**Fix:** raise the ceiling for hero/display contexts (fluid clamp up to ~6rem is normal for portfolio
mastheads — portfolio14's hero headline reads small for a broadsheet concept), and instruct sections to use
the `display`/`xx-large` presets instead of inline clamps so scale stays consistent page-wide.

---

## C. Creativity ceilings built into the prompts and architecture

### C1. Sections are generated blind to each other — P1

All sections generate concurrently seeing only a one-line outline of neighbors. Consequences visible in both
builds: repeated column rhythms (portfolio15 has three identical 33/33/33 `equal-cards` rows), no page-level
rhythm of dark/light bands, and the composition menu being re-rolled independently per section.

**Fix (cheap, keeps concurrency):** extend `section-plan.md` to assign each section a **layout archetype**
(from the composition menu), a **background treatment** (base / tinted / contrast / full-bleed image), and a
one-line handoff describing what sits above and below. Pass each section its own assignment plus its
neighbors'. The plan step becomes the page's art director; sections stop clashing without seeing each
other's markup.

### C2. The design direction is one paragraph, picked at random from converging candidates — P1

The whole design system downstream hangs off a single prose paragraph. And the 4 candidates converge across
runs: portfolio14's *rejected* "Testigo del Tiempo" is essentially portfolio15's *chosen* "Archivo
Silencioso"; both runs picked Source Serif 4 for body, the same 6-step scale, the same clamp values. The
random pick (`random_int` in `DesignDirectionStep`) trades quality for variety but the variety isn't real.

**Fixes:**
- Set `temperature` (currently never set anywhere — `AnthropicClient` uses API default) for direction and
  sections; it's the cheapest diversity lever in the system.
- Make the direction structured, not just prose: palette hexes, type pairing + weights, **image grade**
  (see D2), layout motifs per section type, one "signature device". Downstream steps then execute instead
  of re-interpreting.
- Replace random pick with a cheap judge (haiku) scoring candidates for fit + distinctiveness, or keep a
  small "recently used directions/fonts" memory across builds and penalize repeats — that's what actually
  prevents every photography brief landing on the same museum-monograph look.

### C3. No custom CSS at all, narrow block whitelist, one rigid card recipe — P1/P2

Sections may not emit `<style>`; the only utility classes are the two hardcoded in `ScaffoldThemeStep`
(`.equal-cards`, `.cta-bottom`). The block whitelist omits media-text, gallery, pullquote, quote, table,
details. The card recipe forces equal widths ("MUST sum to exactly 100%") and 200px thumbnails. Direct
consequences in the logs: portfolio14's promised "timeline scrubber … masonry grid … hover overlay cards"
collapsed into four equal 25% columns — none of it is expressible in the allowed vocabulary. No overlap or
negative-margin layout appears anywhere in either build.

**Fixes, in increasing ambition:**
1. Widen the whitelist (media-text, pullquote, quote, gallery) — free variety, still core blocks.
2. Replace the single card recipe with 3–4 named recipes (equal grid, staggered/offset grid, mixed-width
   editorial row, list-with-thumbnails) and let the plan step assign one per section.
3. Add a **page-styles step**: after sections are written, one LLM pass emits a small scoped stylesheet
   (appended to `style.css`) with utility classes sections were told they may reference (`.overlap-up`,
   `.masonry-3`, hover treatments). This unlocks masonry/overlap/hover — the three devices both design
   directions promised and lost — while keeping per-section markup clean.

### C4. Header and footer never see the design direction — P1

`header.md`/`footer.md` receive only siteSpec + theme.json, and the header layout is hardcoded ("title one
side, nav the other, space-between"). portfolio15's direction explicitly said "no chrome, full-bleed
photograph" — and got a standard sticky header dropped on top of it. Headers are the most generic part of
every build.

**Fix:** pass the design direction in; offer 3–4 header archetypes (standard row, centered masthead,
minimal overlay/transparent for full-bleed heroes, oversized wordmark) and let the direction pick.
An overlay header option specifically rescues 100vh-cover heroes.

### C5. Accent-color rule contradicts the directions — P2

Both directions planned accent use beyond CTAs (portfolio14: "series labels and the timeline spine";
portfolio15: "hover underlines and tiny section markers") but three prompts repeat "accent for buttons/CTAs
only", so the model either obeys (losing the motif) or sneaks it in inconsistently (portfolio14's hero
separators). Soften to: "accent stays rare — CTAs plus at most one repeated micro-motif the direction names."

---

## D. Images and art direction

### D2. No per-project photographic grade — cross-image cohesion is luck — P1

Every image generates independently; the only shared glue is a one-sentence site blurb and per-image style
keyword (all 53 images across both builds: `photorealistic`). portfolio15 stayed coherent only because the
model spontaneously wrote "black and white … grain visible" into all 16 subjects; portfolio14 mixes
golden-hour color, B&W, and muted gray across adjacent grids.

**Fix:** make the design direction emit an explicit **image grade** line ("monochrome, high grain, charcoal
midtones, available light") and have `ImagePromptComposer` append it to every prompt. This is the single
biggest visual-cohesion lever for image-heavy home pages. Consider also `sampleImageSize: '2K'` for
hero/full-bleed images — 1K stretched to 1366px+ goes soft.

### D3. Language and identity coherence — P1

portfolio15 mixes English headings ("Decades of Witness", "Get in Touch", "Published & Exhibited") with
Spanish body copy ("El fotógrafo", all captions). Nothing in any prompt sets a language policy. Separately,
`site-spec.md` ("facts only, don't invent") produces generic mastheads — "Documentary Photography
Portfolio" — while the sections freely invent a persona ("Mercedes Alcorta", `estudio@archivosilencioso.ar`).
The masthead, footer copyright, and persona never agree, and the long generic title causes the portfolio14
footer wordmark to wrap mid-word ("PHOTOJOURNALIS T PORTFOLIO").

**Fix:** site-spec should record `language` (and enforce it in section/header/footer prompts), and when the
user gives no name, the refine or spec step should commit to one invented brand/persona name that everything
downstream reuses (masthead, email domain, copyright).



