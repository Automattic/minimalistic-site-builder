You are a WordPress block-theme developer AND the design lead. Build the site FOOTER template part as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters).

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

DESIGN DIRECTION (the committed creative concept for THIS site — the footer is its closing note, not an afterthought):
{{design_direction}}

PAGE OUTLINE (for context):
{{outline}}

Rules:
- The footer shows the site name, a few useful links (and contact facts from the spec where given), and a small credit line at the very bottom, in a single top-level wp:group.
- Carry the DESIGN DIRECTION into the footer: reuse its signature device (hairline rules, folio/numbered labels, a monogram, generous negative space — whatever the direction committed to) and its palette mood. Do NOT fall back to the same generic three-column layout regardless of direction — the layout, alignment, and spacing must visibly belong to THIS site.
- Stay understated: the footer is the direction expressed at low volume, quieter than the sections above it — not a second hero.
- Contrast on dark footers: when the footer group has a dark "backgroundColor", set an explicit light "textColor" on the group AND explicit link colors so links don't fall back to an unreadable dark default — e.g. `"style":{"elements":{"link":{"color":{"text":"var:preset|color|base"}}}}` on the footer group (pick the palette slug that actually reads on that background).
- The credit line is understated and adapts to the theme — small font size, muted color (e.g. secondary), heading/body font as fits — e.g. a "Built with WordPress" line. Keep it neutral; NO EMOJIS anywhere in the footer.
- Use valid CORE block markup only (group, columns/column, site-title, paragraph, navigation, list, separator, social-links if useful).
- Reference theme.json presets by slug: colors via "backgroundColor"/"textColor" (base, contrast, primary, secondary, accent); fonts via "fontFamily" (heading, body); font sizes via "fontSize" (the theme.json fontSizes slugs) — never hardcode a raw `font-size` value or `clamp()`.
- Keep it self-contained: no header/footer template-part references, no <html>/<body>.
- Every block comment must be correctly closed and HTML class names must match the block.
- LANGUAGE: write ALL user-facing footer copy — link labels, contact lines, the copyright and credit lines — in {{language}}. Do not mix languages; proper nouns and the spec's identity values stay verbatim.
- IDENTITY: the footer speaks for the spec's ONE committed identity. The copyright line credits `persona_name` when set, otherwise `name` — exactly as written in the spec, never a rephrased or generic descriptor. Any email shown must be at the spec's `email_domain`. NEVER invent alternate names or domains.

Output ONLY the block markup, starting with "<!-- wp:" — no JSON, no prose, no markdown code fences.
