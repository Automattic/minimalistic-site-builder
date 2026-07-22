# Improving creativity and design quality of generated sites

Findings from inspecting the pipeline (`src/`, `prompts/`) and two builds from the same brief —
`projects/portfolio14` ("Archivo Vivo", 2026-07-01) and `projects/portfolio15` ("Archivo Silencioso", 2026-07-02) —
including their screenshots, LLM logs, image logs, fix-blocks logs, and final theme markup.

**The headline:** the design-direction and section prompts already ask for bold, distinctive work, and the
models deliver ambitious directions — but the ambition is then lost to mechanical constraints (block fixer
stripping styles, font weights not loaded, no custom CSS, capped type scale), blind spots (no feedback loop,
sections generated in isolation, broken screenshot tooling), and homogenizing defaults (same fonts across runs,
random direction pick, no temperature). Most wins here are recovering quality the LLM already produced.

Priorities: **P0** = high impact, unblocks evaluation or fixes visible breakage · **P1** = clear design-quality win ·
**P2** = polish / larger investment.

---

## A. Evaluation and feedback infrastructure

### A1. Screenshots are broken by lazy-loading — most images render as blank boxes — P0

`bin/screenshot.mjs` captures with `captureBeyondViewport: true` and a 900px viewport, never scrolling.
WordPress adds `loading="lazy"` to below-the-fold images, so they never load: in `portfolio15/logs/home.png`
the entire "2001", "2010–2019" and all six "La Salada" series images are empty frames with captions;
in portfolio14 every essay-section cover below the fold is a gray box. Only hero + first row load.

This is not a site bug (all 16 srcs in portfolio15 resolve to real files in `theme/assets/`), but it corrupts
every visual evaluation we do — human or automated — and makes the pages look far worse than they are.

**Fix:** before capture, either scroll the page to the bottom in steps (CDP `Input.dispatchMouseEvent`/
`Emulation.setScrollbarsHidden` + `window.scrollTo` via `Runtime.evaluate`), or run
`document.querySelectorAll('img[loading=lazy]').forEach(i => i.loading = 'eager')` and wait for
network idle / `document.images` completeness before `Page.captureScreenshot`. Cheap, single-file change,
prerequisite for everything in A2.

### A2. No feedback loop of any kind — P0

`Pipeline.php` is explicitly "no agentic loop … each step is one shot". Nothing ever sees a rendered result:
no screenshot critique, no self-review, no regeneration on quality grounds. Every defect below shipped
silently. Once A1 is fixed, add a single **render → critique → targeted revise** pass:

1. Build, screenshot the home page (already have playground + screenshot tooling).
2. One vision-model call: score the page against the chosen design direction, list concrete defects
   (contrast failures, cramped/overflowing text, dead space, repeated compositions, tiny heroes).
3. Re-run only the flagged section parts with the critique appended to the section prompt.

Even one iteration would have caught nearly every issue in section B. This is the single highest-leverage
architectural change for home-page quality.

### A3. Validation aborts instead of repairing — P1

When `ThemeJsonStep`/`SectionsStep` validation fails the build throws; `ThemeValidator` isn't even in the
pipeline (only `bin/eval.php`). Add an LLM repair round-trip: feed the validation error back to the same
step once before failing.

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

### B4. Dead anchors and placeholder links everywhere — P1

Every in-page link in both builds is dead: `#gallery`, `#contact`, `#professional-inquiries`
(portfolio14), `#get-in-touch` (portfolio15) — zero `id=` attributes exist in any part. portfolio15 ships
`<a href="#">Agencia</a>` and dummy `https://instagram.com/` links; portfolio14's contact form posts to `action="#"`.

**Fix:** have `AssembleLandingPageStep` inject `"anchor":"<section-slug>"` on each section's top-level group
(deterministic, no LLM involvement), and tell the header/footer/section prompts the exact anchor list they
may link to. Forbid `href="#"` — prefer `mailto:` or omit the link.

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

### D1. Aspect-ratio vocabulary is wrong for photography sites — P1

Only `square (1:1)`, `landscape (16:9)`, `portrait (9:16)` exist. 9:16 is a phone-story ratio; documentary
photography lives in 3:2, 4:3, 4:5. portfolio15's "1983–1995" row renders three skinny 9:16 slivers where a
photo essay wants 4:5 or 3:4 frames. Imagen supports `3:4` and `4:3` natively — add both keywords to
`image-generation.md` and the `WpcomImageClient` ratio map. One-line change, big effect on any
photo-heavy site.

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

### D4. Fake contact form — P2

portfolio14 ships a raw `wp:html` form (`action="#"`, hardcoded hex colors bypassing tokens). It looks real
and silently discards submissions — worse than no form. Forbid `<form>` in sections; require `mailto:` CTA
patterns instead (portfolio15's contact section is the better model).

---

## Suggested order of attack

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 1 | A1 screenshot lazy-load fix | XS | Unblocks all visual eval |
| 2 | B2 font-weight loading + single font step | S | Fixes typography on every build |
| 3 | B3 link/contrast rules (+ lint) | S | Fixes invisible text on dark sections |
| 4 | B1 fixer style-stripping (prompt + diff warning) | S | Recovers card grids, hero spacing, shadows |
| 5 | D1 add 3:4 / 4:3 ratios | XS | Correct framing for photo sites |
| 6 | D2 image grade from design direction | S | Cross-image cohesion |
| 7 | B4 deterministic section anchors | S | Working navigation |
| 8 | C1 layout/background assignments in section plan | M | Page rhythm, no repeated compositions |
| 9 | C2 structured direction + temperature + anti-repeat | M | Real cross-run variety |
| 10 | A2 render → critique → revise loop | M/L | Catches everything else, compounds all above |
| 11 | C3 page-styles step + wider blocks + card recipes | L | Masonry/overlap/hover — the promised devices |
| 12 | C4 header archetypes with direction | M | Kills the most generic element |

Items 1–7 are mechanical fixes recovering quality the LLM already produces; 8–12 raise the ceiling.
