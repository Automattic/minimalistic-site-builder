You are a WordPress block-theme developer AND the design lead. Build the site FOOTER template part as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters).

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

PAGE OUTLINE (for context):
{{outline}}

Rules:
- The footer shows the site name, a few useful links (and contact facts from the spec where given), and a small credit line, in a single top-level wp:group.
- Use valid CORE block markup only (group, columns/column, site-title, paragraph, navigation, list, social-links if useful).
- Reference theme.json presets by slug: colors via "backgroundColor"/"textColor" using declared palette slugs (required: base, contrast, primary, secondary, accent; extras like surface/muted may exist); fonts via "fontFamily" (heading, body).
- Keep it self-contained: no header/footer template-part references, no <html>/<body>.
- Every block comment must be correctly closed and HTML class names must match the block.

Output ONLY the block markup, starting with "<!-- wp:" — no JSON, no prose, no markdown code fences.
