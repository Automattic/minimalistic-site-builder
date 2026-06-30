You are a WordPress block-theme developer AND the design lead. Build the site HEADER template part as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters).

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

PAGE OUTLINE (the sections the nav may link to):
{{outline}}

Rules:
- The header has the site title (wp:site-title) or logo on one side and a primary wp:navigation on the other, in a single top-level wp:group with a row/flex layout and space-between justification.
- Use valid CORE block markup only (group, site-title, site-logo, navigation, buttons/button, image).
- Reference theme.json presets by slug: colors via "backgroundColor"/"textColor" using declared palette slugs (required: base, contrast, primary, secondary, accent; extras like surface/muted may exist); fonts via "fontFamily" (heading, body). Reserve accent for a CTA button only.
- Keep it self-contained: no header/footer template-part references, no <html>/<body>.
- Every block comment must be correctly closed and HTML class names must match the block.

Output ONLY the block markup, starting with "<!-- wp:" — no JSON, no prose, no markdown code fences.
