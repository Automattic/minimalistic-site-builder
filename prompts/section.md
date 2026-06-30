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
  Layout:   {{section_layout}}
  Purpose:  {{section_purpose}}
  Notes:    {{content_notes}}
  Use imagery: {{wants_image}}

Rules:
- The markup is the section's content ONLY — no header, no footer, no <html>/<body>. Do NOT emit a wp:template-part.
- Wrap the whole section in a single top-level <!-- wp:group --> with a constrained or full layout, so it drops cleanly into the page in order.
- Build the section in the "{{section_layout}}" treatment. Commit to it visibly so this section reads structurally distinct from a plain centered stack:
    - image-left / image-right: a two-column wp:columns with the media in one column (left or right as named) and heading+copy in the other; use verticalAlignment "center" and an intentional column ratio (e.g. 40/60).
    - full-bleed: an align:full wp:cover (or full-width colored wp:group) that spans edge to edge, content constrained within; good for immersive heros/CTAs.
    - split-screen: two equal align:full halves meeting down the middle (wp:columns at 50/50, often one half a solid color or image, the other type) with a clear vertical seam.
    - asymmetric-grid: an off-balance grid — unequal wp:columns widths or a wp:gallery with mixed emphasis — so items are NOT a uniform row.
    - centered: a constrained, centered stack (the neutral default) — use only when the content genuinely wants quiet symmetry.
    - overlap: layered composition where an element sits over another (e.g. a wp:cover with an inner group, or negative margins via style) for depth.
    - stacked-cards: distinct card-like wp:group blocks (each with its own background/padding/border-radius) stacked or in a grid, rather than flat text.
  Keep whatever the treatment implies for media: if "Use imagery" is yes, place the image where the treatment calls for it (beside the text for image-left/right and split-screen, behind it for full-bleed/overlap, within each card for stacked-cards).
- Use valid CORE block markup only (group, cover, columns/column, heading, paragraph, buttons/button, image, list, separator, spacer; query/post-template only if useful).
- Reference theme.json presets by slug:
    colors via "backgroundColor" / "textColor" using slugs: base, contrast, primary, secondary, accent
    fonts via "fontFamily" using slugs: heading, body
  Example: <!-- wp:heading {"level":2,"fontFamily":"heading","textColor":"primary"} --><h2 class="wp-block-heading has-heading-font-family has-primary-color has-text-color">…</h2><!-- /wp:heading -->
- Reserve the accent color for buttons/CTAs only.
- Write real, specific copy in the brand voice grounded in the site spec — not lorem ipsum.
- If "Use imagery" is yes, emit generatable AI image placeholders following the IMAGE INSTRUCTIONS below. This is the "{{section_title}}" section ({{section_purpose}}) — let that steer each image's page-context and subject.
- Every block comment must be correctly closed and the HTML class names must match the block (standard WordPress block classes).

IMAGE INSTRUCTIONS:
{{image_instructions}}

Output ONLY the block markup, starting with "<!-- wp:" — no JSON, no prose, no markdown code fences.
