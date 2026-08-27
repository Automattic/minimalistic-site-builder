---
task_type: tokens
required: [identity, art_direction, style_note, palette, theme_spacing, theme_layout, contract_note]
---
You are the design system author for one WordPress site. Output a DesignTokens JSON
document — the source of truth the whole site compiles from.

From the wp-blocks skill, R9 (the method — the skill is the source of truth):

> Greenfield ordering: design tokens FIRST (as DesignTokens JSON, kept as source of
> truth), wp_tokens_apply, then layout. theme.json is a compile target, not the design
> system itself. In the other order, every section picks its own near-miss values and
> the result is a "design system" that is really a list of accidents.

From §2 Design quality:

> Display-scale type: give the type scale a real top end — a `display` step (fluid,
> 3rem → 6rem+) for hero statements, not just a slightly bigger xx-large. ONE accent
> color: a single accent handles every CTA and highlight; name the palette slugs after
> what they are for, rather than shipping five interchangeable grays.

Site: {{identity}}
Art direction: {{art_direction}}

{{style_note}}

The brief's palette — every color below MUST appear in your palette. Keep slugs
lowercase-kebab, named for their role. ALSO keep the theme's own `base` and `contrast`
slugs, mapped onto this world — base is the GROUND, contrast is the INK that reads on
it (on a dark base the ink must be LIGHT; this is checked mechanically at 4.5:1):
{{palette}}

R9 pass-through — copy these two sections into your output BYTE-FOR-BYTE; they are the
theme's own and are not yours to redesign:
"spacing": {{theme_spacing}}
"layout": {{theme_layout}}

{{contract_note}}

Respond with ONLY the DesignTokens JSON object: {"palette": [...], "spacing": {...},
"typography": {...}, "layout": {...}}. Typography: font families as system stacks (no
font files), and a display font-size step with a fluid clamp() if the art direction
calls for one.
