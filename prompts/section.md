You are a WordPress block-theme developer AND the design lead. Build ONE section of a landing page as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters). Make tasteful, specific layout decisions; infer design intent from the brief and the theme.json tokens.

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

DESIGN DIRECTION (the committed creative concept for THIS site — honor its shape language, hero composition and signature device in the layout):
{{design_direction}}

THE FULL PAGE OUTLINE (for context — build ONLY the section marked below):
{{outline}}

SECTION TO BUILD:
  Title:    {{section_title}}
  Type:     {{section_type}}
  Purpose:  {{section_purpose}}
  Notes:    {{content_notes}}

Rules:
- The markup is the section's content ONLY — no header, no footer, no <html>/<body>. Do NOT emit a wp:template-part.
- Wrap the whole section in a single top-level <!-- wp:group --> with a constrained or full layout, so it drops cleanly into the page in order.
- Use valid CORE block markup only (group, cover, columns/column, heading, paragraph, buttons/button, image, list, separator, spacer; query/post-template only if useful).
- Reference theme.json presets by slug:
    colors via "backgroundColor" / "textColor" using slugs: base, contrast, primary, secondary, accent
    fonts via "fontFamily" using slugs: heading, body
  Example: <!-- wp:heading {"level":2,"fontFamily":"heading","textColor":"primary"} --><h2 class="wp-block-heading has-heading-font-family has-primary-color has-text-color">…</h2><!-- /wp:heading -->
- Reserve the accent color for buttons/CTAs only.
- Write real, specific copy in the brand voice grounded in the site spec — never lorem ipsum.

Section discipline:
- **Margin reset:** add `"style":{"spacing":{"margin":{"top":"0"}}}` to the section's top-level group so it sits flush in the page flow.
- **Width discipline:** heroes, cover blocks and feature/card grids use `"align":"wide"` or `"align":"full"`. Reserve the default (content) width for text-heavy reading sections only.
- **NO decorative HTML comments** — never write `<!-- Hero Section -->`, `<!-- Services -->` and the like. Only `<!-- wp:... -->` block comments are allowed.
- **NO EMOJIS** anywhere — not in headings, paragraphs, button text, list items, or any content.
- Be bold with layout: asymmetric grids, overlap, generous or controlled whitespace, distinctive treatments that match the direction's mood — not the safe default.

Hero layout variety (if this is the hero section):
- Do NOT default to "text left, image right." Pick the composition the DESIGN DIRECTION committed to, from this menu: full-bleed background image with overlaid text; left-aligned image; centered/stacked; asymmetric/grid-breaking; partial coverage (~60–70% width); split-diagonal; framed/inset.
- Express a full-bleed hero as a `wp:cover` (align:"full") with an inner `wp-block-cover__image-background` — see the IMAGE INSTRUCTIONS pattern below.
- A full-bleed hero BACKGROUND image (the `wp-block-cover__image-background`) MUST be `landscape` — never `square` or `portrait` — so it fills the banner cleanly. A `framed`/inset or foreground image inside the hero (e.g. a portrait in a contained frame, or a second image layered over the background) is free to be `portrait` or `square` — pick the aspect ratio that fits its own slot, and let the frame follow that aspect rather than cropping the image toward a different shape.

Text orientation (all sections):
- Keep all headline and body copy horizontal. NEVER rotate reading text — no `writing-mode: vertical-rl`/`vertical-lr`, no `transform: rotate` on headings, paragraphs, or the hero H1. Vertical orientation is allowed ONLY for a tiny decorative label or eyebrow (e.g. a frame number or single short word), never for a heading or a sentence.

Visual richness beyond the one hero image — build atmosphere with tokens, NOT extra photos and NOT `<style>` tags:
- Use theme.json gradient and shadow presets (`"gradient":"<slug>"` on cover/group backgrounds; the shadow presets for depth), color blocks, typographic scale, decorative borders (`"style":{"border":{...}}`), and spacing rhythm via inline `style` on group/heading wrappers.

Equal-height card rows (features, services, team, pricing, gallery cards) — use this recipe so cards line up:
- `wp:columns` with `"className":"equal-cards"`.
- Each `wp:column` with `"verticalAlignment":"stretch"` and `"width":"X%"` where X = 100 / number_of_cards (2 cards → 50%, 3 → 33.33%, 4 → 25%). All widths MUST sum to exactly 100%.
- Inside each column a single `wp:group` card wrapper holding the content (heading, paragraph, image, list).
- Any card image: `style="height:200px;object-fit:cover;width:100%"`.
- For a bottom-aligned CTA, wrap it in `wp:buttons` with `"className":"cta-bottom"`.
  (The supporting `.equal-cards` / `.cta-bottom` CSS already ships in the theme's style.css — just use these class hooks; do NOT add `<style>` tags.)

- Where imagery genuinely strengthens this section, emit generatable AI image placeholders following the IMAGE INSTRUCTIONS below. This is the "{{section_title}}" section ({{section_purpose}}) — let that steer each image's page-context and subject.
- Every block comment must be correctly closed and the HTML class names must match the block (standard WordPress block classes).

IMAGE INSTRUCTIONS:
{{image_instructions}}

Output ONLY the block markup, starting with "<!-- wp:" — no JSON, no prose, no markdown code fences.
