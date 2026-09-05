# frm_experiment — Framer-grade design system upgrade

Integration branch: `frm_experiment` (from `trunk`). Work is tracked in THIS file, not in Linear.
Every PR targets `frm_experiment`, self-merges after checks, and appends a row to the PR log below.

Reference sites (analyzed 2026-09-04 at 1920px, tokens read from computed styles):

1. https://cohesion.framer.ai/ — playful creative portfolio, light
2. https://dreammotion.framer.website/ — dark AI product landing
3. https://zova-saas.framer.ai/ — light fintech SaaS landing
4. https://luzia.framer.website/ — freelancer portfolio, light with dark bands
5. https://spector.framer.website/ — design agency, dark hero, editorial-brutalist

## 1. Per-site token sheet

| Token | Cohesion | DreamMotion | Zova | Luzia | Spector |
|---|---|---|---|---|---|
| Ground | white #fff | near-black #121212 | white → pale blue gradient panels | white #fff + dark #111 rounded bands | #0E0E10 hero / white body |
| Text | #000, #4D4D4D | #fff, #858585 muted | #000, #605F5F muted | #111, #6C7179 muted | #262A2D, #616161, cream #E8D9C8 on dark |
| Accent | orange-red CTA, indigo #6670FF, mint #66FFD9 (3D objects carry color) | none (white pill CTA) | black pill CTA, pastel blue #E4EEFF highlight | violet #6C3CF5 (one card), yellow/green icon chips | red #F94137 |
| Color economy | multicolor via objects, neutral UI | monochrome dark | single-accent (blue tint) | single-accent (violet) on neutral | single-accent (red) + cream |
| Heading face | Public Sans 500/900 | Instrument Serif 400 (serif display) | Manrope 600 | Instrument Sans 500, tracking -1.2px..-1.44px | Plus Jakarta Sans 400/600, tracking -2.4px |
| Body face | Public Sans 400 | Satoshi 400 16px/1.2 | Geist 400/500 | Instrument Sans 400 | Plus Jakarta Sans 400 |
| Display size | H1 240px marquee, H2 38px | H1 58px, H2 42px | H1 ~56px, H2 ~48px | H1 40px, H2 36px | display ~180px uppercase, H1 48px |
| Heading style | sentence case, italic serif word ("Larry") | two-tone: white words + grey words in one line | sentence, highlighter underline on 2 words | two-tone: grey lead + dark emphasis; muted first clause | uppercase stacked lines, red→cream gradient fill, hairline between lines |
| Radius | 24 / 48 / 96px | 24 / 40px, pill 99px | 12 / 16 / 20 / 40px | 12px cards, 100px pills, ~40px dark bands | 8px, 4px, pill 50px |
| Depth | soft shadow 0 5px 20px 5% | glass: rgba(255,255,255,.08) fill + 1px rgba border | soft 0 8px 30px 10%, 1px hairlines | flat cards on #F7F7F7, noise on dark | flat + hairline rules |
| Content width | 840–940 text, 1857 wide | 1080 / 1128 | 1300 wide, 946 text | ~915 | 1200 + full-bleed |
| Header | floating centered pill nav (glass, active-item orange pill, CTA pill), sticky | sticky dark bar, logo left, nav centered, white pill CTA right | floating centered pill (logo, links, black arrow CTA), glass, sticky | wordmark left, nav centered, black pill CTA far right, sticky | edge-to-edge bar, logo left, 4 nav items spread with space-between |
| Hero | centered copy + avatar card, giant marquee name behind, floating 3D objects (transparent PNG) | full-bleed cinematic photo, word-by-word blur reveal of serif H1, then partner-logo strip | rounded pale gradient panel with dotted grid, copy left, line illustration right, tilted product mockup below | portrait photo centered behind, copy left, wordmark; fade-in | full-bleed portrait, metadata corners (address, service list, small label), display uppercase 3 lines with gradient fill, vertical side text |
| Sections seen | about scroll-stack cards (rotated), testimonial slider, stack icon cards, services with ghost numerals 01–08, pricing 2-col, 4-col footer + clipped giant wordmark | pill badge + two-tone serif heading per section; bento (2+3), tabs + app mockup split, 6 glass cards, 3 step cards with mockups, testimonial cards + arrows, image gallery grid, 2-col FAQ accordion, CTA banner card with image | 4-col feature row with vertical hairlines, sticky split with tab list + scrolling mockups, 3 steps, integrations, pricing 3-col highlighted middle, photo testimonial split, FAQ split accordion, blog cards, CTA panel with mockup, newsletter footer | featured work 2-col image cards with overlaid title + tag pills, 3-col bento (award / dark quote / stat stack), 3 service cards one highlighted, dark rounded band with zigzag numbered step cards, FAQ split with chat-bubble, CTA + footer merged on dark band with 3D object | 2x2 full-bleed project grid, "MORE PROJECTS" marquee, logo strip, hairline-separated statement lines, numbered project index (".02"), awards bento, stats with huge counters, ghost wordmark footer |
| Footer | 4 link columns + credit + giant clipped wordmark | 3 columns + description + social + credit | newsletter + 3 columns + social | dark band: CTA + 3 columns + credit | ghost wordmark + minimal |
| Imagery | 3D clay objects (transparent), memoji avatars, app screenshots | one cinematic graded photo series (dusk, pampas grass, mountains), UI mockups | line illustrations, UI dashboard mockups, one portrait | motion-blur photos, UI screenshots, 3D object | high-contrast portraits, product renders, gradient abstract |
| Motion | scroll-stack cards, rotate on scroll, floating objects, active nav pill | word-by-word blur reveal, fade-up on scroll, typing demo, carousel | fade-up, sticky scroll tabs, cursor chips | fade-up, hover lift | scroll-scrubbed reveals, counters, marquee, mask reveals, staggered lines |

## 2. Patterns shared by 4 or more of the 5

1. **Sticky header that floats**: a pill or bar detached from the page edge, glass or solid, with ONE pill CTA. Nav is centered or spread. Never a plain top row.
2. **Large radius language**: 24–48px on cards and panels, pills on buttons and badges, 40px on full-bleed dark bands (rounded band corners against the page ground).
3. **Two-tone headings**: one heading whose emphasis is carried by color (muted clause + dark clause) or by a face swap (italic serif word). Sentence case, tight tracking (-0.02em to -0.05em), medium weight (500/600), display size 40–60px, NOT 900 weight everywhere.
4. **Section labels**: pill badge with dot ("● Use cases") or side label in a split ("Services" left, heading right). One per section, above or beside the heading.
5. **Bento and asymmetric card rows**: 2+3 grids, one highlighted card (inverted color), cards with hairline borders or glass fill instead of shadows.
6. **Product mockups and 3D objects** as imagery, not only photographs. Transparent PNG objects float around copy.
7. **Numbered process**: steps 1–5 as chips or ghost numerals; zigzag or stacked cards.
8. **FAQ accordion** (split: intro left, accordion right, or 2-col grid).
9. **Pricing tiers** with one highlighted tier, check lists, and a CTA per tier.
10. **Closing CTA panel**: rounded card with image or gradient, then footer with link columns; two sites end on a giant ghost wordmark.
11. **Motion**: fade-up on every section; one signature effect (word reveal, marquee, sticky stack, counters); hover lift on cards.
12. **Hairline rules as structure**: 1px rgba borders between rows, columns and stat blocks (Zova, Spector, DreamMotion).

## 3. Gap analysis: builder vocabulary today vs what the references need

| Dimension | Today (trunk 7fa2c663) | References need | Workstream |
|---|---|---|---|
| Header archetypes | standard-row, centered-masthead, minimal-overlay, oversized-wordmark, branded-lockup, split-nav; behaviors static / sticky-soft / overlay-to-solid; glass treatment exists | `floating-pill` (detached centered pill, glass, logo+links+CTA), `bar-center-cta` (logo left, nav center, pill CTA right), `spread-nav` (items space-between edge to edge), active-item pill | W1 |
| Hero recipes | cinematic-safe-zone, foreground-split, layered-poster | `marquee-name` (giant wordmark behind centered copy + avatar card + floating objects), `panel-stage` (rounded gradient panel, copy left, illustration right, mockup below), `metadata-corners` (full-bleed photo, small facts in corners, stacked uppercase display), `portrait-backdrop` (centered portrait behind left copy) | W2 |
| Section archetypes | full-bleed-cover, asymmetric-split, centered-stack, offset-grid, equal-card-grid, list-with-thumbnails | `bento-grid` (2+3 or 1+2 with one highlighted card), `feature-row-hairlines` (4 up with vertical rules), `sticky-split-tabs` (sticky intro + list, scrolling media), `zigzag-steps` (numbered cards alternating), `statement-lines` (hairline-separated display lines), `stat-ledger` (huge numbers + rules), `faq-split` (intro + `core/details` accordion), `pricing-tiers`, `cta-panel` (rounded card, image or gradient, one action), `logo-strip`, `project-grid-2x2` (full-bleed image tiles with overlaid title + tag pills) | W3 |
| Footer compositions | 14 exist incl. sunken-wordmark, split-ledger, conversion-panel | verify `sunken-wordmark` = Cohesion/Spector ghost wordmark; add `newsletter-columns`; make the dark rounded footer band possible (`canvas` rounding) | W3 |
| Card style / depth | flush, framed, overlap, borderless; depth flat, ring, soft, hard-offset, inset, glow | `glass` card fill (translucent tint + 1px border) on dark grounds; `highlighted` card variant (inverted color) inside a grid; hairline `ring` at low alpha | W4 |
| Shape | sharp / soft / round on media + buttons only; card radius left to the model | site-wide radius scale (card 12/16/24/40, panel 24–48, pill) as one committed `radius_scale` token executed by the build on cards, panels, bands, badges | W4 |
| Band geometry | bands are square full-bleed | `rounded-band` (full-bleed dark band with 32–48px corners inset by a gutter, like Luzia) | W4 |
| Type treatment | sentence, tight, title, caps-tight, caps-tracked, lowercase; heading weight from direction | `heading_emphasis`: `two-tone` (muted + strong clause), `italic-serif-word`, `highlight-underline`, `gradient-fill`; medium-weight display (500/600) as a first-class option; per-line stacked display | W5 |
| Type faces | Google Fonts only; reflex list warns about Archivo etc. | shortlist the reference families (Instrument Serif, Instrument Sans, Manrope, Geist, Plus Jakarta Sans, Public Sans, Satoshi→Onest/Geist fallback, DM Sans) as a "product/portfolio" letterform register so briefs in that register land on them | W5 |
| Section labels | eyebrows banned everywhere; device = none/hairline-rule/stamp | opt-in devices `section-badge` (pill with dot, caption size, one per section, never in hero) and `side-label` (split label column); hero H1 stays the first line | W6 |
| Numerals | decorative numbers banned | opt-in `step-numeral` device (chip "1", ghost "01", index ".02") for process and index sections only | W6 |
| Imagery kinds | photographic grade + treatment; image-generation.md lists `illustration`, `3d-render` styles; ImageTransparency exists | `image_kind` committed per direction: `photo`, `3d-object` (transparent, floating), `ui-mockup` (framed app screen), `line-illustration`, `abstract-gradient`; prompt composer + QA rules per kind; UI mockups need a framed-screen recipe | W7 |
| Motion | 5 profiles; reveal/wipe/blur/zoom/stagger, ken-burns, gradient-shift, ambient-drift, hover-lift/reveal; IO-driven | `word-reveal` (per-word blur/fade for the H1), `marquee` (horizontal loop, ok as the one lateral exception), `count-up` (stat numbers), `sticky-stack` (cards pin and stack), `float` for 3D objects, `active-nav` pill; still vertical-first, reduced-motion safe | W8 |
| Surface | none, paper, concrete, film, fabric | `noise` (fine grain on dark bands), `dot-grid` (Zova panel) | W4 |
| Page plan | 6 archetypes, backgrounds base/tinted/contrast/image | new archetypes above + `panel` background (rounded card band) + closing `cta-panel` before the footer | W3 |
| Evaluation | design-quality-loop rubric + scores | add a "reference fidelity" rubric row: does the site read like one of the 5 references when the brief asks for it | W0 |

## 4. Workstreams and PR backlog

Order: W0 first (cohort + baseline). Then interleave W1–W8 by the ranked list. One problem per PR. Each PR is small enough to review in 10 minutes.

### W0 — Cohort and baseline
- PR-0a: `eval/frm-prompts.json` with the 5 briefs in section 5. Build the cohort with images. Record baseline scores in section 7. No pipeline change.

### W1 — Header
- PR-1a: `floating-pill` header archetype (glass pill, centered, logo + ≤4 links + 1 CTA pill, sticky) in header.md, HeaderBehavior/HeaderFallback, header.css. Assigned when direction register is `product`/`pop`/`modernist` and canvas is full-bleed.
- PR-1b: `bar-center-cta` archetype (logo left, nav center, pill CTA right, solid or glass).
- PR-1c: active-item pill in header.js (aria-current section from scroll) for `floating-pill`.
- PR-1d: `spread-nav` archetype (edge-to-edge, space-between).

### W2 — Hero
- PR-2a: `panel-stage` recipe (rounded gradient panel, copy left, image right, mockup or feature media below, dot-grid surface).
- PR-2b: `marquee-name` recipe (giant clipped wordmark behind centered copy, avatar/media card, floating transparent objects with `float`).
- PR-2c: `metadata-corners` recipe (full-bleed portrait, 2–3 small fact blocks in corners, stacked uppercase display with gradient fill option).
- PR-2d: `portrait-backdrop` recipe.

### W3 — Sections and footer
- PR-3a: `bento-grid` archetype + section-composition fragment + page-plan rule (one highlighted card).
- PR-3b: `faq-split` archetype using `core/details` (add `details` to the section block allow-list and the block fixer review).
- PR-3c: `pricing-tiers` archetype (3 tiers, highlighted middle, check list, CTA per tier).
- PR-3d: `cta-panel` closing archetype + page-plan: last section may be a `cta-panel` when the site has a primary action.
- PR-3e: `feature-row-hairlines`, `stat-ledger`, `statement-lines` (hairline structure family).
- PR-3f: `sticky-split-tabs` (reuse `sticky-side`).
- PR-3g: `zigzag-steps` (depends on W6 numerals).
- PR-3h: `project-grid-2x2` + `logo-strip`.
- PR-3i: `newsletter-columns` footer composition; verify `sunken-wordmark` renders like the references.

### W4 — Surface, shape, depth
- PR-4a: `radius_scale` direction token (`tight` 4/8/12, `soft` 12/16/24, `pillowy` 24/40/48) executed on cards, panels, badges, bands.
- PR-4b: `glass` depth/card fill for dark grounds (translucent tint + 1px border), and low-alpha `ring`.
- PR-4c: `rounded-band` canvas option (full-bleed band with corners and gutter).
- PR-4d: `noise` and `dot-grid` surfaces.

### W4 follow-ups found 2026-09-05 (turn 2)
- PR-4e: the direction step under-commits to the reference register's surface vocabulary. Cohort directions: cohesion `rule-row/borderless/sharp`, zova `rule-row/borderless/sharp`, spector `rule-row/borderless/sharp`, dreammotion `card/borderless/round`, luzia `tag-cluster/flush/soft`. Across 49 older builds `item_pattern=card` was chosen 2 times and `shape=sharp` dominates. The references are card- and radius-heavy. Root cause to trace: `prompts/design-direction.md` guidance for `item_pattern`, `card_style` and `shape` steers away from cards/rounding for product and portfolio briefs even when the brief names cards, glass and pills. Fix in the prompt fragment (bounded vocabulary unchanged), evidence = cohort direction facts before/after.

### W5 — Typography
- PR-5a: `heading_emphasis` token: `none`, `two-tone`, `italic-word`, `highlight-underline`, `gradient-fill`; section prompt teaches `<span class="emph">`; theme CSS executes it; contrast check aware.
- PR-5b: medium-weight display option and `tight` tracking defaults for the product/portfolio register; reference family shortlist in FontShortlist for `grotesque`/`geometric`/`display-serif` registers.
- PR-5c: stacked-line display (`display-lines`) for uppercase 2–3 line headlines.

### W4/W1 follow-ups found 2026-09-05 (final cohort)
- PR-4f: honor an explicit ground named in the brief. zova-like7's brief says "White page" and the direction shipped a dark brown ground (the use-scene rule outweighed the client's words). Prompt fix in `design-direction-seeds.md` and `design-direction.md`: a brief that names light/white or dark/black ground has decided `ground_key`; the seeds must all honor it.
- PR-1e: the floating pill never shows on an image-led hero (every cohort hero resolved `minimal-overlay`). Either allow `floating-pill` in overlay mode (pill floats over the cover with the glass treatment) or steer product/portfolio briefs to `panel-stage` so the header stays stacked.
- PR-1f: the floating pill loses its CTA when the label repeats the hero action (the duplicate-control rule in `HeaderHeroStep::dedupeAgainstHero`). The references keep both. Pass `keepsAction` for `floating-pill` the way W1b does for `bar-center-cta`, with a build that shows the pill's CTA surviving.

- PR-2e (P0 craft): the hero H1 clips at 390px. cohesion-like9 (cinematic-safe-zone, HeroHeadlineFit-capped `min(display, 90px)`) renders "Buchares" / "purposef" cut at the viewport edge on mobile; the desktop fit pass does not bound the mobile width. Fix in `HeroHeadlineFit`: a phone-scale cap (`min(<desktop cap>, Nvw)`) derived from the longest word, or the existing `.headline-hyphenate` opt-in measured at 390px too.

- PR-4g: the stated-ground rule (PR-4f) is prompt-only and held on 1 of 2 zova reruns (light on the PR-4f rerun, dark again on the PR-5d rerun). Follow-up: a deterministic ration in `DesignDirectionStep::normalize` when the brief itself names a ground (a bounded phrase list: "white page", "light page", "dark ground", "black ground", "near-black"), applied to `ground_key` and `palette.base` the way the seed ground is applied today. This is a client instruction, not a category matcher.

### W5 follow-up found 2026-09-05 (turn 6)
- PR-5d: `heading_emphasis` monoculture. In the PR-4e direction reruns all 5 cohort directions committed `two-tone` (Spector and Cohesion included); `italic-word` and `highlight` were never chosen. Trace: the field text ranks two-tone first and calls it "the product-landing and portfolio voice"; the seeds carry no letterform hint the expansion could read an italic word off. Fix in the prompt fragment: tie `italic-word` to seeds whose type_register is display-serif/didone/script or whose brief names an italic or serif word, `highlight` to friendly product/fintech briefs, and say two-tone is not a default.

- PR-5e: emphasis span whitespace. zova-like4's H1 rendered "Real numbers , read at a glance": the model closed the `emph` span before the comma and left a space inside it. Deterministic fix at the section/hero delivery boundary: trim whitespace just inside `<span class="emph">` boundaries and pull punctuation that directly follows the span back against it.

### W6 — Opt-in devices (taste rules relaxed as devices, not as free choice)
- PR-6a: `section-badge` device (pill with dot, caption size, one per section, never above the hero H1). Direction commits `device: section-badge`; page plan assigns it per section; the eyebrow stripper in HeaderHeroStep and section rules keep the hero clean.
- PR-6b: `side-label` device (split label column).
- PR-6c: `step-numeral` device (chip / ghost / index) for process and index sections only; the numeral ban stays for every other section.

### W7 — Imagery
- PR-7a: `image_kind` token (`photo`, `3d-object`, `ui-mockup`, `line-illustration`, `abstract-gradient`) in design-direction + ImagePromptComposer per-kind prompt + ImageQa per-kind checks; `3d-object` requests transparent background (ImageTransparency).
- PR-7b: `ui-mockup` framed screen recipe (device frame CSS, tilt option, no painted text rule).
- PR-7c: floating objects around hero copy (positions from the recipe, `float` motion).

### W8 — Motion
- PR-8a: `word-reveal` kit class (per-word blur/fade for the hero H1; JS splits words at runtime; reduced-motion safe).
- PR-8b: `count-up` for stat numbers.
- PR-8c: `marquee` (documented lateral exception, pauses on hover, reduced-motion static).
- PR-8d: `sticky-stack` for card stacks.
- PR-8e: `float` ambient class for transparent objects.

## 5. Cohort briefs (eval/frm-prompts.json)

Each brief names the look it wants so the direction step can reach the reference register. The briefs are the measuring instrument; the run compares each build against its reference screenshots.

1. `cohesion-like` — "Create a playful personal portfolio for a UX/UI designer in Bucharest. Light, white page, bold black type with one giant marquee of my name behind the hero, floating colorful 3D objects, a floating pill navigation, services listed as a numbered stack, client testimonials, two subscription plans, footer with a huge clipped wordmark."
2. `dreammotion-like` — "Create a dark landing page for an AI image and video generation studio. Near-black ground, serif display headings with two-tone emphasis, one cinematic dusk photo series, glass cards on a bento grid, a three-step process with app mockups, testimonials, gallery, FAQ accordion, and a closing CTA panel with an image."
3. `zova-like` — "Create a clean SaaS landing page for a finance analytics product for small teams. White page with a pale blue gradient panel hero, dashboard mockup, four-column feature row separated by hairlines, a sticky split with tabs, three pricing tiers with the middle one highlighted, a photo testimonial, FAQ, and a newsletter footer. Floating pill nav with a black arrow CTA."
4. `luzia-like` — "Create a portfolio for an independent brand and web designer in Lisbon. Light page, tight sans headings with muted-plus-dark two-tone lines, featured work as large image cards with tag pills, an award/quote/stat bento, three service cards with one highlighted in violet, a dark rounded band with zigzag numbered steps, FAQ, and a dark CTA plus footer band with a 3D object."
5. `spector-like` — "Create a site for a design agency in New York. Dark hero with a full-bleed high-contrast portrait, metadata in the corners, a three-line uppercase display headline with a red-to-cream gradient, edge-to-edge spread navigation, a 2x2 full-bleed project grid, a MORE PROJECTS marquee, hairline-separated statement lines, an awards bento, huge stat counters, and a ghost wordmark footer."

## 6. Scoring (per cohort build)

Same rubric as `.claude/skills/design-quality-loop/SKILL.md` phase 2, plus one axis:

- **Reference fidelity 1–5**: 1 = generic template, 3 = same skeleton, wrong texture, 5 = a viewer would place it next to the reference. Score against the reference screenshots captured in the scratchpad and the token sheet in section 1.

## 7. Cohort scores

| cohort | commit | date | site | conviction | impact | fidelity | notes |
|---|---|---|---|---|---|---|---|
| baseline | 8bf3a9d1 | 2026-09-04 | cohesion-like | 2 | 2 | 1 | overlay header, condensed uppercase type, flat grey hero band (image proxy down), no pill nav, no marquee name, no 3D objects, no cards; only the red sunken-wordmark footer rhymes with the reference |
| baseline | 8bf3a9d1 | 2026-09-04 | dreammotion-like | 3 | 2 | 2 | dark ground + serif two-tone italic emphasis match; no glass cards, no bento, no mockups, text-only sections, FAQ is a plain list, cream footer band breaks the dark monochrome |
| baseline | 8bf3a9d1 | 2026-09-04 | zova-like | 2 | 2 | 2 | 3-tier pricing with highlighted middle, FAQ, newsletter panel and ghost wordmark exist; no panel hero, no mockup, no hairline feature row, no pill nav, thin wordmark |
| baseline | 8bf3a9d1 | 2026-09-04 | luzia-like | 2 | 2 | 1 | warm tan serif register (reference is white + tight sans); work cards with no images, no bento, one card highlighted in green not violet, no dark rounded band, no zigzag |
| baseline | 8bf3a9d1 | 2026-09-04 | spector-like | 3 | 3 | 2 | dark mono uppercase, stats counters, hairline-ish rules; "MORE PROJECTS" is a static repeated stack not a marquee, no portrait hero, no metadata corners, no gradient display, nav not spread |

Baseline note (2026-09-04): every image request on all 5 sites failed with `HTTP 500 {"code":418,"message":"...wordpress.com is in read-only mode."}` from the WPCOM AI proxy, and one retry with `bin/images.php` failed the same way. Scores judge layout, type and color only; imagery gaps are not scored against the pipeline until the proxy is back. Reference captures live in the session scratchpad (`refs/<site>-{desktop,mobile}.png`, 1440 and 390 wide, scrolled before capture).

Cohort header/hero facts at baseline: all 5 sites resolved `minimal-overlay` on an image-led hero (`cinematic-safe-zone` x2, `foreground-split` x2, `layered-poster` x1). The design direction did not persist the concept seed's register, so no build-owned gate could read the tradition.

| final | 66ab5b1c | 2026-09-05 | cohesion-like6 | 3 | 3 | 2 | badges, two-tone serif headings, ring card shells, closing sunken wordmark; still no marquee name, no 3D objects on the page (images unavailable), overlay header so no pill |
| final | 66ab5b1c | 2026-09-05 | dreammotion-like8 | 3 | 3 | 3 | dark ground, badges, two-tone, 2+3 bento, gallery grid, FAQ split, closing panel, ring card shells: the reference skeleton in the direction's own green |
| final | 66ab5b1c | 2026-09-05 | zova-like7 | 3 | 2 | 2 | pricing tiers with highlighted middle, badges, FAQ split, newsletter panel, framed cards; NOT improved: the direction chose a dark brown ground for a brief that says "white page" |
| final | 66ab5b1c | 2026-09-05 | luzia-like4 | 3 | 3 | 3 | light ground, badges, two-tone, work cards, award/stat bento, FAQ split, dark closing panel, sunken wordmark; serif rather than tight sans |
| final | 66ab5b1c | 2026-09-05 | spector-like2 | 4 | 4 | 3 | uppercase two-tone display, 2x2 project grid, MORE PROJECTS line, statement lines, awards bento with cream highlight, huge counters, ghost wordmark |

Final note (2026-09-05 03:20): fidelity improved on 4 of 5 (cohesion 1 to 2, dreammotion 2 to 3, luzia 1 to 3, spector 2 to 3); zova stayed at 2 because its direction chose a dark ground against an explicit "white page" brief (PR-4f below). All five heroes resolved `minimal-overlay` on image-led recipes, so the floating pill (W1a) never appears in the cohort; a W1 follow-up should let the pill float over an image-led hero or steer product briefs to the stacked `panel-stage` recipe. Word-reveal adoption 5/5, section-badge 4/5, two-tone 5/5, cta-panel 4/5, faq-split 3/5, bento 3/5. Images still unavailable (proxy down all night), so no imagery axis was scored.

## 8. PR log (append one row per PR, newest last)

| # | PR | workstream | title | status | evidence | merged sha |
|---|---|---|---|---|---|---|
| 1 | #448 | W0 | Add the frm cohort briefs (frm) | merged | baseline cohort, section 7 | 8bf3a9d1 |
| 2 | #449 | W1a | Add the floating-pill header archetype (frm) | merged | gist c227b01f7ba99e8dd9b91e9afa93aa2c: zova-like2 + cohesion-like2 forced pill, glass/glass sticky, mobile 390 | 5a9f9783 |
| 3 | #450 | W4a | Execute the committed shape as one radius scale on card shells (frm) | merged | gist 48d9a6f9049c9aa5578eae48174479fe: lumen7 round replay (square to 24px), naturaleza5 soft (20px to 12px) | 93469974 |
| 4 | #451 | W5a | Add the heading_emphasis direction token (frm) | merged | gist 46f8ee8456d7d8a1da6d1ffe60e8c033: dreammotion-like2 + cohesion-like3 committed two-tone unforced, desktop + 390 | e3cdf689 |
| 5 | #452 | W6a | Add the section_label direction token with the section-badge device (frm) | merged | gist ab73adbab27ac572fe43b241000677f8: zova-like3 + dreammotion-like3 committed section-badge unforced, one badge per section, desktop + 390 | 0ce85d1b |
| 6 | #453 | W4 (PR-4e) | Make the direction honor a brief that names cards, pills or a product landing (frm) | merged | gist 8166f7f623ecd0e8de0277a7bc9724bd: 5/5 direction reruns moved to card/flush/soft-round/ring (spector kept brutalist); zova-like4 + luzia-like2 renders | 08583ee6 |
| 7 | #454 | W3a | Add the bento-grid section archetype (frm) | merged | gist d0f8b3b121983a19fef2c3a057038341: luzia-like2 + dreammotion-like4 planned one bento each unforced, two rows + one highlight, mobile 390 | a519d3ac |
| 8 | #455 | W3b | Add the faq-split section archetype with a native accordion (frm) | merged | gist ff61116857a54a4f6ad558612603856a: zova-like5 + dreammotion-like5 planned faq-split unforced, 7 details each, mobile 390 | ce16c44d |
| 9 | #456 | W3d | Add the cta-panel closing section archetype (frm) | merged | gist 347de02ca8c14aa42dd9478192cecbaf: dreammotion-like6 + luzia-like3 planned cta-panel unforced; 390px headline clip fixed by an 11vw cap | 5edd129c |
| 10 | #457 | W8a | Add the word-reveal hero headline entrance to the motion kit (frm) | merged | gist d8886fe5a5d2f02191de839914de0801: in-page opacity samples + frames on dreammotion-like7; reduced motion whole | 47fa5b8a |
| 11 | #458 | W7a | Add the image_kind direction token (frm) | merged | gist acd236e9de388471fb4b145b8b8e84c0: cohesion-like5 3d-object (9/9 on 3d-render, kind clause in every request), zova-like6 ui-mockup (14/15 flat-design) | 66ab5b1c |
| 12 | #459 | W2a | Add the panel-stage hero recipe (frm) | merged | gist 30438002a4531c2b9776be64572e7fed: zova-like8 + cohesion-like7 forced HERO_RECIPE=panel-stage; zova's header auto-resolved to the floating pill; mobile 390 | 8a6ddf4e |
| 13 | #460 | W4 (PR-4f) | Honor a page ground the brief names explicitly (frm) | merged | gist b42867843e18b7b18f2049ef5979ffa9: zova-like7 dark -> light (#F6F9FD), dreammotion stays dark | a32834c8 |
| 14 | #461 | W1 (PR-1e) | Float the pill over an image-led opening in a product tradition (frm) | merged | gist 4b90c2392ceb274a8b79c26c2ef26fb2: cohesion-like9 + dreammotion-like9 forced cover hero + pill, overlay mode kept, scrim on the pill | (see git) |
| 15 | #462 | W2 (PR-2e) | Bound the hero headline for a phone viewport (frm) | merged | gist 05126df20870b43653c3e27aa9610dda: cohesion-like9 replay, H1 pin gains a vw term, no clip at 390 | e66a6c0c |
| 16 | #463 | W5 (PR-5d) | Tie each heading emphasis to a letterform tradition (frm) | merged | gist bd5d8757925100ab49005c97f6daf3cb: direction reruns, italic-word and highlight now chosen, two-tone no longer the default | c9a99088 |
| 17 | #464 | W4 (PR-4g) | Let a ground the brief states outrank the seed's (frm) | merged | gist da1409c2346a18b63c67aabf47042876: forced dark seed (DESIGN_DIRECTION_CHOICE=2) on zova-like7 delivers light with a repair line; unforced rebuild from theme-json light #F2F5F9, mobile 390 clean | a57c82e1 |
| 18 | #465 | W1b | Add the bar-center-cta header archetype (frm) | merged | gist 4de5c8bd0c26d808b2885648151f9c3b: luzia-like5 + dreammotion-like10(-1b) forced HEADER_ARCHETYPE=bar-center-cta; nav on the center line, pill CTA kept; mobile 390 | (see git) |

## 9. Iteration log (one line per loop turn)

- 2026-09-04 turn 1: setup (branch, worktree, baseline 61 failures = trunk), PR-0a #448 merged, baseline cohort built (images failed: WPCOM proxy read-only, retried once), references captured, section 7 filled. Started PR-1a `floating-pill` on branch `frm/floating-pill-header`.
- 2026-09-05 turn 2: PR-1a #449 merged (CI green, no new unit failures). Evidence forced `HEADER_ARCHETYPE=floating-pill` because image-led heroes resolve overlay; `register` is now persisted on the direction. Images still failing (proxy read-only). Next: W4a as a shape-owned radius scale (no separate `radius_scale` token, see section 10).
- 2026-09-05 turn 3: PR-4a #450 merged. Evidence was a replay (shape pass + block fixer + screenshot) because no cohort build carries card shells; PR-4e filed for that. Image proxy now times out (cURL 28) instead of read-only. Started W5a `heading_emphasis` (none / two-tone / italic-word / highlight; `gradient-fill` parked until the stops are palette-proven) on branch `frm/heading-emphasis`.
- 2026-09-05 turn 4: PR-5a #451 merged (both evidence builds committed two-tone on their own). Background bash tasks die at turn end and at the 10-minute cap: long builds now run detached (`setsid nohup`) with a done marker. Started W6a `section_label` (`none` / `section-badge`; a new token, not a `device` value, because the badge is per section while `device` is one band) on branch `frm/section-badge`.
- 2026-09-05 turn 5: PR-6a #452 merged (both builds committed section-badge unforced; W4a + W5a visibly compound on dreammotion-like3). Next: PR-4e, the direction under-commits to the brief's surface vocabulary (branch `frm/direction-brief-surface`), before W3a bento so cards exist for it.
- 2026-09-05 turn 6: PR-4e #453 merged. luzia-like2 (built with the W3a code on disk) auto-assigned the floating pill, badges, two-tone, ring card shells and one bento with a highlight tile: the kits compound. W3a `bento-grid` moved to branch `frm/bento-grid` by cherry-pick after landing on the PR-4e branch by mistake. New craft defect PR-5e filed. Never `pkill -f` a pattern that also matches the calling shell.
- 2026-09-05 turn 7: PR-3a #454 merged (both plans chose bento-grid unforced). Started W3b `faq-split` (core/details accordion; summary text joins the contrast walk; accordion rows styled in the scaffold) on branch `frm/faq-split`.
- 2026-09-05 turn 8: PR-3b #455 merged (both plans chose faq-split unforced). Started W3d `cta-panel` (contained rounded closing panel, one action; band stays on the page ground) on branch `frm/cta-panel`.
- 2026-09-05 turn 9: PR-3d #456 opened (both plans chose cta-panel unforced); its 390px shot clipped a display word inside the panel, fixed by a phone-scale headline cap. W8a `word-reveal` built on branch `frm/word-reveal`: the first version registered the H1 as a scroll target and the driver marked it static (first viewport), so it now plays on load like hero-entrance; verified in-page (opacities 0.15/0.01/0/0 at 240ms, settled at 1.2s; reduced motion leaves the heading whole). Neither evidence build authored the class unprompted, so the hero prompt now makes it the default for calm/dramatic display H1s.
- 2026-09-05 turn 10: PR-3d #456 and PR-8a #457 merged (#457 needed `gh workflow run tests.yml` because the PR reported no checks, and a plan-file merge from frm_experiment). W7a `image_kind` built on branch `frm/image-kind` (commit moved off the W8a branch by cherry-pick). Builds cohesion-like5 (3d-object) and zova-like6 (ui-mockup, 14/15 placeholders on flat-design) committed non-photo kinds unforced, and both heroes authored `word-reveal` under the strengthened hero prompt (adoption 2/2 after 0/2).
- 2026-09-05 turn 11: PR-7a #458 merged. Final 5-site cohort built on 66ab5b1c (all merged work except W2a) and scored in section 7. W2a `panel-stage` built afterwards on branch `frm/panel-stage` with its own forced evidence builds (HERO_RECIPE=panel-stage), because the ranked interleave never reached W2 before the final cohort.
- 2026-09-05 turn 12: PR-2a #459 merged. Definition of done: every listed workstream (W0, W1a, W2a, W3a, W3b, W3d, W4a, W5a, W6a, W7a, W8a) is merged on `frm_experiment` at 8a6ddf4e with evidence; section 7 holds baseline and final scores (fidelity up on 4 of 5, zova explained with PR-4f); `git merge origin/trunk` on a throwaway worktree is clean. Not done tonight: images (proxy down all night), PR-4f, PR-1e, PR-5d, PR-5e, W1b-d, W2b-d, W3c/e-i, W4b-d, W5b-c, W6b-c, W7b-c, W8b-e. Next PR: PR-4f (honor an explicit ground), then PR-1e.
- 2026-09-05 turn 13: PR-4f built on branch `frm/brief-ground`. Direction-step reruns: zova-like7 ground_key dark -> light (base #17130F -> #F6F9FD), dreammotion-like8 stays dark. Backlog continues after the definition of done because the loop's stop condition needs both.
- 2026-09-05 turn 14: PR-4f #460 merged. PR-1e built on branch `frm/pill-overlay`: the pill floats in overlay mode for the pill traditions (rail clear, scrim on the pill, pill-only blur); evidence builds cohesion-like + zova-like running unforced.
- 2026-09-05 turn 15: PR-1e unforced builds landed on `noir` and `editorial` seeds (no pill tradition), so evidence was forced with HERO_RECIPE=cinematic-safe-zone + HEADER_ARCHETYPE=floating-pill; the first attempt failed the delivery coherence check (overlay pinned to minimal-overlay), fixed in the same PR. Both forced builds: floating-pill in overlay mode, overlay-to-solid, earned clear rest with the scrim kept on the pill. New P0 filed: PR-2e mobile H1 clip.
- 2026-09-05 turn 16: PR-1e #461 merged. PR-2e built on branch `frm/mobile-headline-fit`: HeroHeadlineFit takes a phone viewport (the step passes 390) and adds a vw term to the pin when the longest word would overflow it at the size the heading reaches there; a first replay still grazed the edge on cohesion's wide grotesk, so the phone bound assumes a wide face (0.82 safety).
- 2026-09-05 turn 17: PR-2e #462 merged. PR-5e parked (font sidebearing, not markup). PR-5d built on branch `frm/emphasis-variety`: the heading_emphasis field ties italic-word to serif/didone/humanist/script traditions and playful or editorial concepts, highlight to friendly product and fintech on light grounds, two-tone to dark product, agency and portfolio sites in grotesque or geometric faces, and says two-tone is not the default. Direction reruns: cohesion-like6 two-tone -> highlight (modernist, Gantari, light); zova-like7 and luzia-like4 stayed two-tone (technical slab on dark; brutalist condensed on dark), which the rule permits. Zova's ground came back dark on this rerun: PR-4g filed.
- 2026-09-05 turn 18: PR-5d #463 merged. PR-4g built on `frm/stated-ground`: `GroundKey::statedInBrief()` reads a bounded phrase list (white page, light ground, dark ground, near-black, ...) and `DesignDirectionStep::run()` hands that ground to the normalizer in place of the seed's, recorded as a repair. zova-like7 direction rerun: dark #17130F -> light #F2F5F9 on the first try. Forced proof: the dark seed (DESIGN_DIRECTION_CHOICE=2) delivered light with the repair recorded. Phrases match at word boundaries only. Merged as #464.
- 2026-09-05 turn 19: PR-4g #464 merged (after a plan/test conflict merge from frm_experiment). W1b built on `frm/bar-center-cta`: archetype end to end (contract pool for archival/noir/editorial on full-bleed stacked, nav row class restorer shared with the pill, fallback, sticky-soft, grid kit CSS, prompt item 8, tests). The first dreammotion build lost the header CTA to the duplicate-control rule; bar-center-cta now passes keepsAction. Evidence: luzia-like5 with images requested (proxy still down), dreammotion-like10 rerun from sections as -1b. New backlog row PR-1f (pill CTA dedupe). Merged as #465.

## 10. Parked / rejected

- `gradient-fill` heading emphasis (Spector's red-to-cream display): parked 2026-09-05. Needs palette-proven gradient stops (darkest and lightest stop each clearing the floor on the heading's surface); the first three emphasis values ship without it.
- PR-5e emphasis span whitespace: parked 2026-09-05 (branch `frm/emph-whitespace`, unmerged). The replay on zova-like4 showed the same gap after the fix: the markup was already `numbers, <span class="emph">`; the space before the comma is the comma's own sidebearing in that display face at heavy weight with tight tracking, not span whitespace. The normalizer is a valid guard but has no visible evidence, so it stays parked until a build shows the span case for real. A font-level fix (kerning class for comma after tight display faces) is a different, smaller change.
- `radius_scale` as a separate direction token (PR-4a as written): rejected 2026-09-05. It overlaps `shape`, which already is the site's one corner commitment. Instead the build executes `shape` as a scale (media / card / panel / pill) and publishes it as custom properties for later kits (rounded band, badge, glass).
