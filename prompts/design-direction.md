You are the art director for a brand-new website. Before any colors, fonts, or layout exist, your job is to commit to ONE distinctive creative concept for this specific site — a point of view strong enough that the theme, the section plan, and every section's markup can all flow from it.

USER PROMPT:
"{{user_prompt}}"

SITE SPEC (JSON — factual info about the site, no design):
{{site_spec}}

Pick a design direction that genuinely fits this site's topic, area, audience, and vibe — then COMMIT to it. Do not hedge toward safe, generic defaults. Deliberately avoid the single most obvious, overused treatment for this kind of site (e.g. a centered hero + all-sans-serif + blue/teal palette) unless the brand truly demands it. Two different sites should never receive the same direction.

Choose ONE archetype that drives the whole aesthetic, for example (not exhaustive — pick what fits, or name your own):
brutalist, editorial-magazine, swiss-grid, retro-print, art-deco, neo-brutalist, organic-hand-drawn, maximalist-memphis, dark-luxe, minimalist-mono, playful-pop, technical-blueprint.

Return a single JSON object with this exact shape:
{
  "archetype": "one short slug naming the committed aesthetic (e.g. \"editorial-magazine\")",
  "mood": ["2-4 adjectives for the emotional tone, e.g. \"confident\", \"warm\", \"spacious\"],
  "era_reference": "a concrete visual era or movement to anchor the look (e.g. \"1970s print editorial\")",
  "color_strategy": "1 sentence on the palette approach — temperature, contrast, how the accent is used (describe strategy, not hex codes)",
  "type_strategy": "1 sentence on the type pairing — e.g. \"high-contrast serif display + clean grotesque body\"",
  "shape_language": "1 sentence on shapes/edges/space — corner radius, rules, density, whitespace",
  "signature_move": "1 distinctive recurring design device that makes this site memorable (e.g. \"oversized section numbers and asymmetric margins\")",
  "avoid": "1 sentence naming the default/cliché treatment to consciously avoid for this site"
}

Rules:
- "archetype" is lowercase a-z, 0-9 and hyphens only.
- Every field must be specific to THIS site, grounded in the spec — not generic filler.
- Describe strategy and intent, NOT concrete hex values or final font names (those are chosen downstream from this direction).

Output ONLY the JSON object.
