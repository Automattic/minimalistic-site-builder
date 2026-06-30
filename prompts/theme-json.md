You are a WordPress block-theme engineer AND the design lead. Produce a complete, valid theme.json (schema version 3) for the site described below. There is no separate design document — make the design decisions (palette, typography, spacing, shapes) yourself, directly here, and encode them in theme.json.

USER PROMPT:
"{{user_prompt}}"

SITE SPEC (JSON — factual info about the site, no design):
{{site_spec}}

DESIGN DIRECTION (the committed creative concept for THIS site — let it drive every choice below):
{{design_direction}}

Make opinionated, specific design choices that genuinely fit this site's topic, area, audience, and visual vibe — not generic defaults. Translate the DESIGN DIRECTION into concrete tokens: the palette must express its stated palette approach, the font pairing its type pairing, and the spacing/shapes its shape language. Steer clear of the cliché it calls out avoiding. Pick colors and a font pairing that feel designed for THIS brand.

Hard requirements — follow exactly so downstream templates can rely on the slugs:

- Top-level: "$schema": "https://schemas.wp.org/trunk/theme.json", "version": 3.
- settings.layout: set "contentSize" and "wideSize" to comfortable reading widths.
- settings.color.palette: an array that MUST include AT LEAST these five required slugs (downstream templates reference them by name, so they must always exist), each a valid #RRGGBB hex you choose:
    "base"      = page background (body text on it must have strong contrast)
    "contrast"  = body text color
    "primary"   = main brand color (headings, structure)
    "secondary" = supporting color (metadata, captions)
    "accent"    = reserved for CTAs / interaction only
  Give each a human "name". You MAY add extra palette entries when the design calls for them — e.g. "surface" (a card/panel tint distinct from base), "muted" (a faint divider/background), or a second accent — each with its own slug, name and hex. Add them only when they earn their place; don't pad the palette.
- settings.typography.fluid: true.
- settings.typography.fontFamilies: an array that MUST include AT LEAST these two required slugs:
    "heading" — a real, commonly available web/Google font that fits the brand, first, then web-safe fallbacks
    "body"    — a real, commonly available web/Google font that fits the brand, first, then web-safe fallbacks
  You MAY add ONE optional third family — slug "display" (an expressive face for oversized headlines/numerals) or "mono" (for code/labels/metadata) — when the design genuinely wants it. Each entry: { "fontFamily": "<stack>", "name": "...", "slug": "..." }. Pick fonts that genuinely fit the subject; avoid generic defaults.
- settings.spacing: include "spacingSizes" with slugs sm, md, lg, xl, xxl (a comfortable rising scale).
- styles.color.background = var(--wp--preset--color--base), styles.color.text = var(--wp--preset--color--contrast).
- styles.typography.fontFamily = body font var, with a readable fontSize and lineHeight.
- styles.elements.h1 / h2 / h3 typography.fontFamily = heading font var, with a clear size hierarchy.
- styles.elements.button: background = accent (or primary), text = base. Choose a SHAPE that fits the design direction — a fully sharp (borderRadius 0), softly rounded, or pill (large radius) button; or an outline/ghost button (transparent background, a border in accent/primary and matching text) when that suits the brand better. Give it comfortable padding.
- styles.elements.link: color = primary; :hover color = accent. Vary the underline treatment to fit the brand (e.g. always underlined, underline on hover only, or a thicker/offset underline via textDecoration).

Shape language — let the DESIGN DIRECTION drive these so sites don't all share one default system:
- borderRadius: commit to a consistent corner language across buttons and any cards/panels — sharp (0), gently rounded, or pill/organic — matching the direction's shape language. Don't default everything to a small generic radius.
- Heading casing: if the direction calls for it, set styles.elements.h1/h2/h3 typography.textTransform (e.g. "uppercase" for all-caps display, or a letterSpacing tweak) — otherwise leave normal. Use deliberately, not on every site.

Use CSS custom-property references like "var:preset|color|accent" or "var(--wp--preset--color--accent)" as appropriate for theme.json. Output ONLY the theme.json content as valid JSON.
