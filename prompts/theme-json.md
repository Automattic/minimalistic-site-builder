You are a WordPress block-theme engineer AND the design lead. Produce a complete, valid theme.json (schema version 3) for the site described below. There is no separate design document — make the design decisions (palette, typography, spacing, shapes) yourself, directly here, and encode them in theme.json.

USER PROMPT:
"{{user_prompt}}"

SITE SPEC (JSON — factual info about the site, no design):
{{site_spec}}

DESIGN DIRECTION (the committed creative concept for THIS site — let it drive every choice below):
{{design_direction}}

Make opinionated, specific design choices that genuinely fit this site's topic, area, audience, and visual vibe — not generic defaults. Translate the DESIGN DIRECTION into concrete tokens: the palette must express its color_strategy, the font pairing its type_strategy, and the spacing/shapes its shape_language. Honor its "avoid" note. Pick colors and a font pairing that feel designed for THIS brand.

Hard requirements — follow exactly so downstream templates can rely on the slugs:

- Top-level: "$schema": "https://schemas.wp.org/trunk/theme.json", "version": 3.
- settings.layout: set "contentSize" and "wideSize" to comfortable reading widths.
- settings.color.palette: an array with EXACTLY these five slugs, each a valid #RRGGBB hex you choose:
    "base"      = page background (body text on it must have strong contrast)
    "contrast"  = body text color
    "primary"   = main brand color (headings, structure)
    "secondary" = supporting color (metadata, captions)
    "accent"    = reserved for CTAs / interaction only
  Give each a human "name".
- settings.typography.fluid: true.
- settings.typography.fontFamilies: an array with EXACTLY these two slugs:
    "heading" — a real, commonly available web/Google font that fits the brand, first, then web-safe fallbacks
    "body"    — a real, commonly available web/Google font that fits the brand, first, then web-safe fallbacks
  Each entry: { "fontFamily": "<stack>", "name": "...", "slug": "..." }. Pick fonts that genuinely fit the subject; avoid generic defaults.
- settings.spacing: include "spacingSizes" with slugs sm, md, lg, xl, xxl (a comfortable rising scale).
- styles.color.background = var(--wp--preset--color--base), styles.color.text = var(--wp--preset--color--contrast).
- styles.typography.fontFamily = body font var, with a readable fontSize and lineHeight.
- styles.elements.h1 / h2 / h3 typography.fontFamily = heading font var, with a clear size hierarchy.
- styles.elements.button: background = accent (or primary), text = base, with padding and borderRadius.
- styles.elements.link: color = primary; :hover color = accent.

Use CSS custom-property references like "var:preset|color|accent" or "var(--wp--preset--color--accent)" as appropriate for theme.json. Output ONLY the theme.json content as valid JSON.
