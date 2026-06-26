You are a WordPress block-theme engineer. Produce a complete, valid theme.json (schema version 3) from this design.

DESIGN DOCUMENT (Markdown):
{{design_md}}

DESIGN DIRECTION (JSON, for exact token values):
{{design_direction}}

Hard requirements — follow exactly so downstream templates can rely on the slugs:

- Top-level: "$schema": "https://schemas.wp.org/trunk/theme.json", "version": 3.
- settings.layout: set "contentSize" and "wideSize" to comfortable reading widths.
- settings.color.palette: an array with EXACTLY these five slugs, using the design's hex values:
    "base"      = page background
    "contrast"  = body text color
    "primary"
    "secondary"
    "accent"
  Give each a human "name".
- settings.typography.fluid: true.
- settings.typography.fontFamilies: an array with EXACTLY these two slugs:
    "heading" — chosen heading font first, then web-safe fallbacks
    "body"    — chosen body font first, then web-safe fallbacks
  Each entry: { "fontFamily": "<stack>", "name": "...", "slug": "..." }.
- settings.spacing: include "spacingScale" or "spacingSizes".
- styles.color.background = var(--wp--preset--color--base), styles.color.text = var(--wp--preset--color--contrast).
- styles.typography.fontFamily = body font var, with a readable fontSize and lineHeight.
- styles.elements.h1 / h2 / h3 typography.fontFamily = heading font var, with a clear size hierarchy.
- styles.elements.button: background = accent (or primary), text = base, with padding and borderRadius.
- styles.elements.link: color = primary; :hover color = accent.

Use CSS custom-property references like "var:preset|color|accent" or "var(--wp--preset--color--accent)" as appropriate for theme.json. Output ONLY the theme.json content as valid JSON.
