You are a WordPress block-theme developer AND the design lead. Build ONE section of a landing page as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters). Make tasteful, specific layout decisions; infer design intent from the brief and the theme.json tokens.

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

THE FULL PAGE OUTLINE (for context — build ONLY the section marked below):
{{outline}}

SECTION TO BUILD:
  Title:    {{section_title}}
  Type:     {{section_type}}
  Purpose:  {{section_purpose}}
  Notes:    {{content_notes}}
  Use imagery: {{wants_image}}

Return a single JSON object with EXACTLY this shape:
{ "markup": "<!-- wp:group ... -->...<!-- /wp:group -->" }

Rules:
- The markup is the section's content ONLY — no header, no footer, no <html>/<body>. Do NOT emit a wp:template-part.
- Wrap the whole section in a single top-level <!-- wp:group --> with a constrained or full layout, so it drops cleanly into the page in order.
- Use valid CORE block markup only (group, cover, columns/column, heading, paragraph, buttons/button, image, list, separator, spacer; query/post-template only if useful).
- Reference theme.json presets by slug:
    colors via "backgroundColor" / "textColor" using slugs: base, contrast, primary, secondary, accent
    fonts via "fontFamily" using slugs: heading, body
  Example: <!-- wp:heading {"level":2,"fontFamily":"heading","textColor":"primary"} --><h2 class="wp-block-heading has-heading-font-family has-primary-color has-text-color">…</h2><!-- /wp:heading -->
- Reserve the accent color for buttons/CTAs only.
- Write real, specific copy in the brand voice grounded in the site spec — not lorem ipsum.
- If "Use imagery" is yes, emit generatable AI image placeholders using ONLY native src and alt attributes — no custom data attributes:
    src — a theme-relative path "theme:./assets/<name>.jpg" where <name> is lowercase a-z, 0-9 and hyphens, unique and descriptive. Always .jpg.
    alt — the generation spec in this EXACT format: "AI_IMAGE: <description> | <style> | <aspect-ratio>"
      <description>: 1-3 specific sentences (composition, colors, mood, subject) — this is the generation prompt. Start with where and how the image is used, since you are placing it and know its role: name this section ("{{section_title}}") and its purpose (e.g. "Full-bleed hero background for the {{section_title}} section, with the headline overlaid on top —" or "Gallery card in the {{section_title}} section —"), then describe the image. For cover/hero backgrounds, state that text is overlaid and ask for the focal subject off-center with calm, low-detail areas so the overlaid text stays legible. When several images sit together in this section, make each describe a distinct subject so they don't read alike.
      <style>: one of photorealistic, digital-art, illustration, minimalist, flat-design, 3d-render, abstract, watercolor.
      <aspect-ratio>: one of square (1:1), landscape (16:9, use for heroes/banners), portrait (9:16).
    For wp:cover backgrounds, set the SAME "theme:./assets/<name>.jpg" on BOTH the block's "url" attribute and the inner <img class="wp-block-cover__image-background"> src, and put the AI_IMAGE spec in that img's alt.
    Images shown together in a row/grid (cards, team, gallery) MUST share the same aspect-ratio. Every image filename must be unique.
- Every block comment must be correctly closed and the HTML class names must match the block (standard WordPress block classes).

Output ONLY the JSON object.
