You are a WordPress block-theme engineer AND the design lead. Produce a complete, valid theme.json (schema version 3) for the site described below. There is no separate design document — make the design decisions (palette, typography, spacing, shapes) yourself, directly here, and encode them in theme.json.

USER PROMPT:
"{{user_prompt}}"

SITE SPEC (JSON — factual info about the site, no design):
{{site_spec}}

DESIGN DIRECTION (the committed creative concept for THIS site — let it drive every choice below):
{{design_direction}}

Make opinionated, specific design choices that genuinely fit this site's topic, area, audience, and visual vibe — not generic defaults. Translate the DESIGN DIRECTION into concrete tokens: the palette must express its stated palette approach, the font pairing its type pairing, and the spacing/shapes its shape language. When the DESIGN DIRECTION carries an explicit **Palette** fact list (hex per role) and a **Type** pairing, EXECUTE them: use those exact hexes for the five palette slugs and those exact families/weights for heading/body — adjust a hex only if base/contrast lack readable contrast, and keep any adjustment in the same hue family. Steer clear of the cliché it calls out avoiding. You tend to converge toward safe, "on-distribution" output — resist it; commit to a distinctive, cohesive aesthetic.

Design intelligence to encode as tokens:

- **Typography — pick characterful fonts.** Pair a distinctive DISPLAY font (headings) with a refined BODY font. Avoid Inter, Roboto, Arial, Open Sans, system fonts, and don't default to Space Grotesk. Use fonts that genuinely fit the subject and era.
- **Type size scale — six steps, each with a ROLE.** Encode the scale onto `settings.typography.fontSizes` using EXACTLY these slugs, so sections can target each role:
    `caption` — 0.875rem. Metadata, labels, eyebrows, folio lines — never sentences the visitor is meant to read.
    `body` — 1.0625–1.125rem. ALL running copy. A flat 1rem reads undersized next to the display steps — err toward 1.125rem.
    `lead` — 1.25–1.4rem. The single standout line: hero subcopy, a one-sentence section intro.
    `heading` — ~1.75rem. h3, card and item headings.
    `section-title` — a gentle fluid clamp around 2.25–3rem. h2 / section titles.
    `display` — the hero masthead: a fluid `clamp()` reaching roughly 5–7rem at desktop widths, sized to the DESIGN DIRECTION's ambition (a broadsheet/poster direction earns ~7rem; a quiet editorial one ~5rem). Do NOT cap it near 3.5–4rem — an undersized hero headline reads as timid.
  Example: `0.875rem / 1.125rem / 1.375rem / 1.75rem / clamp(2.25rem, 3vw, 3rem) / clamp(3rem, 7vw, 6rem)`. Only caption and body are paragraph sizes (lead is for ONE short line per section); everything above is heading territory, and display exists for ONE hero/masthead moment per page.
- **Line height.** Body 1.5–1.65; headings 1.1–1.3; never below 1.0. Set `styles.typography.lineHeight` and `styles.elements.heading.typography.lineHeight`.
- **Color — dominant with sharp accents.** Commit to a cohesive palette; dominant colors with sharp accents outperform timid, evenly-distributed schemes. Keep `accent` RARE: CTAs/interaction, plus at most the ONE micro-motif the DESIGN DIRECTION's `signature_device` explicitly commits accent to (e.g. eyebrow labels, hairline rules, hover underlines) — never body text, large-area backgrounds, or any motif the direction didn't name. Avoid purple-on-white and generic blue-gray.
- **Layout widths.** `contentSize` 800–900px (comfortable reading — NOT 640), `wideSize` 1200–1400px.
- **Atmosphere.** Where it fits the direction, prefer gradient meshes, layered transparencies, dramatic shadows and decorative borders over flat solids. Expose these so sections can use them: define a few `settings.color.gradients` (give slugs derived from your palette) and a couple of `settings.shadow.presets` the sections can reference.

Hard requirements — follow exactly so downstream templates can rely on the slugs:

- Top-level: "$schema": "https://schemas.wp.org/trunk/theme.json", "version": 3.
- settings.layout: set "contentSize" (800–900px) and "wideSize" (1200–1400px).
- settings.color.palette: an array with EXACTLY these five slugs, each a valid #RRGGBB hex you choose:
    "base"      = page background (body text on it must have strong contrast)
    "contrast"  = body text color
    "primary"   = main brand color (headings, structure)
    "secondary" = supporting color (metadata, captions)
    "accent"    = CTAs / interaction, plus the direction's signature_device motif when it names accent
  Give each a human "name".
- settings.color.gradients: a small array of named gradient presets (slug + name + gradient) built from the palette, for section backgrounds/atmosphere.
- settings.shadow.presets: a small array of named shadow presets (slug + name + shadow) sections can apply for depth.
- settings.typography.fluid: true.
- settings.typography.fontSizes: the 6-step scale above (each entry { "slug", "name", "size" }) using EXACTLY the slugs caption / body / lead / heading / section-title / display — sections reference the steps by these exact slugs.
- settings.typography.fontFamilies: an array with EXACTLY these two slugs:
    "heading" — a real, characterful Google font that fits the brand, as the FIRST token in the stack, then web-safe fallbacks
    "body"    — a real, refined Google font that fits the brand, as the FIRST token in the stack, then web-safe fallbacks
  Each entry: { "fontFamily": "<stack>", "name": "...", "slug": "..." }.
  Pick REAL Google Fonts families spelled exactly (e.g. "Cormorant Garamond", "Source Serif 4", "Oswald") — the build enqueues them from Google Fonts automatically by name, so no fontFace/src is needed here. Just make the FIRST family in each stack the exact Google font name.
  Include EXACTLY these two fontFamilies and no others — do NOT add a third entry (e.g. a "mono" or "accent" family). Two families only: heading and body.
- settings.spacing: include "spacingSizes" with slugs sm, md, lg, xl, xxl (a comfortable rising scale).
- styles.color.background = var(--wp--preset--color--base), styles.color.text = var(--wp--preset--color--contrast).
- styles.typography.fontFamily = body font var, fontSize = var(--wp--preset--font-size--body), lineHeight 1.5–1.65.
- styles.elements.heading.typography.lineHeight = a tight heading line-height (1.1–1.3).
- styles.elements.h1 / h2 / h3 typography.fontFamily = heading font var, with sizes drawn from the scale via `var(--wp--preset--font-size--<slug>)` references: h1 = display (it renders once per page, as the hero masthead), h2 = section-title, h3 = heading.
- styles.elements.button: background = accent (or primary), text = base, with padding and borderRadius.
- styles.elements.link: color = primary; :hover color = accent.

Use CSS custom-property references like "var:preset|color|accent" or "var(--wp--preset--color--accent)" as appropriate for theme.json. Output ONLY the theme.json content as valid JSON.
