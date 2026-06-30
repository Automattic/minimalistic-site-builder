You are a WordPress block-theme developer AND the design lead. Build ONE section of a landing page as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters). Make tasteful, specific layout decisions; infer design intent from the brief and the theme.json tokens.

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

DESIGN DIRECTION (the committed creative concept for THIS site — honor its shape language and signature device in the layout):
{{design_direction}}

THE FULL PAGE OUTLINE (for context — build ONLY the section marked below):
{{outline}}

SECTION TO BUILD:
  Title:    {{section_title}}
  Type:     {{section_type}}
  Purpose:  {{section_purpose}}
  Notes:    {{content_notes}}
  Use imagery: {{wants_image}}

Rules:
- The markup is the section's content ONLY — no header, no footer, no <html>/<body>. Do NOT emit a wp:template-part.
- Wrap the whole section in a single top-level <!-- wp:group --> with a constrained or full layout, so it drops cleanly into the page in order.
- Use valid CORE block markup only (group, cover, columns/column, heading, paragraph, buttons/button, image, list, separator, spacer; query/post-template only if useful).
- Reference theme.json presets by slug:
    colors via "backgroundColor" / "textColor" using declared theme.json palette slugs. Required slugs always exist: base, contrast, primary, secondary, accent. If theme.json declares extras such as surface, muted, wash, ink, or a second accent, use them where they make panels, dividers, callouts, or image captions more expressive.
    fonts via "fontFamily" using slugs: heading, body
  Example: <!-- wp:heading {"level":2,"fontFamily":"heading","textColor":"primary"} --><h2 class="wp-block-heading has-heading-font-family has-primary-color has-text-color">…</h2><!-- /wp:heading -->
- Reserve the accent color for buttons/CTAs only.
- Write real, specific copy in the brand voice grounded in the site spec — not lorem ipsum.
- If "Use imagery" is yes, emit generatable AI image placeholders following the IMAGE INSTRUCTIONS below. This is the "{{section_title}}" section ({{section_purpose}}) — let that steer each image's page-context and subject.
- Every block comment must be correctly closed and the HTML class names must match the block (standard WordPress block classes).

IMAGE INSTRUCTIONS:
{{image_instructions}}

Output ONLY the block markup, starting with "<!-- wp:" — no JSON, no prose, no markdown code fences.
