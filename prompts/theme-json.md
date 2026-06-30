{{> partials/aesthetics.md}}

---

# Your task: lock the design system as theme.json

You are a WordPress block-theme engineer AND the design lead. This step locks the
**design tokens** before any layout is composed — the palette, the type scale, the
spacing, the shapes. Every downstream section trusts these tokens and references them
by slug, so get them right here. Produce a complete, valid theme.json (schema version 3).

USER PROMPT:
"{{user_prompt}}"

SITE SPEC (JSON — factual info about the site, no design):
{{site_spec}}

DESIGN DIRECTION (the committed creative concept for THIS site — let it drive every choice below):
{{design_direction}}

Translate the DESIGN DIRECTION into concrete tokens: the palette must express its stated palette approach (one dominant color, one sharp accent — not a timid evenly-distributed set), the font pairing its type pairing, and the spacing/shapes its shape language. Steer clear of the cliché it calls out avoiding, the banned font families, and the banned color clichés (purple-on-white, safe blue-and-gray corporate).

Hard requirements — follow exactly so downstream templates can rely on the slugs:

- Top-level: `"$schema": "https://schemas.wp.org/trunk/theme.json"`, `"version": 3`.
- `settings.layout`: `"contentSize"` 800–960px (comfortable reading) and `"wideSize"` 1200–1400px.
- `settings.useRootPaddingAwareAlignments`: `true` (so full/wide sections reach the viewport edge).
- `settings.color.palette`: an array with EXACTLY these five slugs, each a valid #RRGGBB hex you choose:
    `base`      = page background
    `contrast`  = body text color
    `primary`   = main brand color (headings, structure)
    `secondary` = supporting color (metadata, captions)
    `accent`    = reserved for CTAs / interaction only
  Give each a human "name".
  **Contrast is computed downstream and rejected if it fails — do the math now, do not guess:**
    - `contrast` on `base` MUST clear WCAG-AA 4.5:1 (aim 7:1). So must `primary` on `base` and `secondary` on `base` (these render as headings/metadata).
    - The button pairing (`accent` background + `base` text, or whatever you wire on the button element) MUST clear 4.5:1 — check BOTH light-on-light and dark-on-dark.
    - No two slugs may be near-matches: any text/background pair whose lightness differs by less than ~25 (of 100) steps will be rejected. Push pairings toward higher contrast — deeper darks, lighter lights.
- `settings.typography.fluid`: `true`.
- `settings.typography.fontFamilies`: EXACTLY these two slugs:
    `heading` — a distinctive, real Google/web font fitting the brand (NOT a banned family), first, then web-safe fallbacks
    `body`    — a refined, readable real Google/web font, first, then web-safe fallbacks
  Each entry: `{ "fontFamily": "<stack>", "name": "...", "slug": "..." }`.
- `settings.typography.fontSizes`: a grounded 6-step scale with slugs `small, medium, large, x-large, xx-large, display`, sized roughly `0.875rem / 1rem / 1.25rem / 1.75rem / 2.25rem / clamp(2.5rem, 4vw, 3.5rem)`. **Cap display text around 3.5rem — never above 4rem.**
- `settings.spacing.spacingSizes`: slugs sm, md, lg, xl, xxl (a comfortable rising scale).
- `styles.spacing.blockGap`: a spacing token (REQUIRED — the site-wide vertical rhythm between sibling blocks).
- `styles.spacing.padding`: set ONLY horizontal `left`/`right` to `"clamp(1.5rem, 5vw, 4rem)"` (fluid page gutter); set `top`/`bottom` to `"0"`.
- `styles.color.background` = `var(--wp--preset--color--base)`, `styles.color.text` = `var(--wp--preset--color--contrast)`.
- `styles.typography.fontFamily` = body font var; `lineHeight` 1.5–1.65; a readable `fontSize`.
- `styles.elements.heading.typography.lineHeight` between 1.1 and 1.3; `styles.elements.h1/h2/h3` use the heading font with a clear size hierarchy drawn from the scale above (h1 ≤ display).
- `styles.elements.button`: background = accent (or primary), text = base, with padding and borderRadius matching the shape language.
- `styles.elements.link`: color = primary; `:hover` color = accent.
- `styles.blocks.core/navigation.spacing.blockGap`: a spacing token (nav links have no gap otherwise).

Use CSS custom-property references like `"var:preset|color|accent"` or `"var(--wp--preset--color--accent)"` as appropriate for theme.json. Output ONLY the theme.json content as valid JSON.
