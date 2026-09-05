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

### W5 — Typography
- PR-5a: `heading_emphasis` token: `none`, `two-tone`, `italic-word`, `highlight-underline`, `gradient-fill`; section prompt teaches `<span class="emph">`; theme CSS executes it; contrast check aware.
- PR-5b: medium-weight display option and `tight` tracking defaults for the product/portfolio register; reference family shortlist in FontShortlist for `grotesque`/`geometric`/`display-serif` registers.
- PR-5c: stacked-line display (`display-lines`) for uppercase 2–3 line headlines.

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

## 8. PR log (append one row per PR, newest last)

| # | PR | workstream | title | status | evidence | merged sha |
|---|---|---|---|---|---|---|
| 1 | #448 | W0 | Add the frm cohort briefs (frm) | merged | baseline cohort, section 7 | 8bf3a9d1 |
| 2 | #449 | W1a | Add the floating-pill header archetype (frm) | merged | gist c227b01f7ba99e8dd9b91e9afa93aa2c: zova-like2 + cohesion-like2 forced pill, glass/glass sticky, mobile 390 | 5a9f9783 |

## 9. Iteration log (one line per loop turn)

- 2026-09-04 turn 1: setup (branch, worktree, baseline 61 failures = trunk), PR-0a #448 merged, baseline cohort built (images failed: WPCOM proxy read-only, retried once), references captured, section 7 filled. Started PR-1a `floating-pill` on branch `frm/floating-pill-header`.
- 2026-09-05 turn 2: PR-1a #449 merged (CI green, no new unit failures). Evidence forced `HEADER_ARCHETYPE=floating-pill` because image-led heroes resolve overlay; `register` is now persisted on the direction. Images still failing (proxy read-only). Next: W4a as a shape-owned radius scale (no separate `radius_scale` token, see section 10).

## 10. Parked / rejected

- `radius_scale` as a separate direction token (PR-4a as written): rejected 2026-09-05. It overlaps `shape`, which already is the site's one corner commitment. Instead the build executes `shape` as a scale (media / card / panel / pill) and publishes it as custom properties for later kits (rounded band, badge, glass).
