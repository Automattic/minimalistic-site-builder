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

{{composition}}

Rules:
- The markup is the section's content ONLY — no header, no footer, no <html>/<body>. Do NOT emit a wp:template-part.
- NEVER include site chrome in the section: no wordmark, no site-title lockup, no navigation or menu links — even if the DESIGN DIRECTION, hero composition, or Notes mention them. The real site header is a separate part rendered above (often overlaid on) your section; duplicating it here puts two headers on the page. If the Notes say "wordmark top-left" or "nav reduced to one link", skip that furniture and build only the section's own content.
- Wrap the whole section in a single top-level <!-- wp:group --> with a constrained or full layout, so it drops cleanly into the page in order.
- Use valid CORE block markup only (group, cover, columns/column, heading, paragraph, buttons/button, image, gallery, media-text, quote, pullquote, list, separator, spacer; query/post-template only if useful).
- Reach beyond group/columns when the content calls for it:
    media-text — a split row with the image filling one half edge-to-edge and copy in the other; supports `"mediaPosition":"right"`, `"verticalAlignment"`, `"isStackedOnMobile":true`. The best tool for alternating feature rows and about/story sections.
    pullquote — ONE oversized editorial line (a manifesto, a review, a customer's best sentence) as a typographic centerpiece between denser sections.
    quote — attributed testimonials or reviews at reading size, with a citation.
    gallery — photo grids with proper gutters; set `"columns"` (2–4) and it handles responsive wrapping. Better than hand-built image columns for photo-led sections.
- Reference theme.json presets by slug:
    colors via "backgroundColor" / "textColor" using slugs: base, contrast, primary, secondary, accent
    fonts via "fontFamily" using slugs: heading, body
    font sizes via "fontSize" using slugs: caption, body, lead, heading, section-title, display (e.g. "fontSize":"display" → class `has-display-font-size`)
  Example: <!-- wp:heading {"level":2,"fontFamily":"heading","textColor":"primary"} --><h2 class="wp-block-heading has-heading-font-family has-primary-color has-text-color">…</h2><!-- /wp:heading -->
- ALL text sizing comes from the fontSizes presets via the "fontSize" attribute. NEVER hardcode a font size — no raw values or `clamp()` in `"style":{"typography":{"fontSize":...}}` and no hand-written `font-size:` inline styles. The scale (including the masthead-scale `display` step) already lives in theme.json; if no preset genuinely fits a rare case, reference a preset variable through the block attribute (`"style":{"typography":{"fontSize":"var:preset|font-size|<slug>"}}`) — never a raw value.
- Paragraph scale discipline — each step of the scale has a role; use the right one:
    running copy (any paragraph, list, or card text that wraps past ~2 lines) = the `body` step. That's the theme default, so usually NO "fontSize" attribute at all. Never push multi-line reading copy up the scale for emphasis, and never shrink it to `caption`.
    `caption` = genuine metadata only — labels, eyebrows, image captions, folio lines. Not sentences the visitor is meant to read.
    `lead` = the ONE standout line a section gets (if it has one): the hero's supporting line or a single-sentence intro under the section title. One per section, kept short.
  The upper steps belong to headings — the contrast between big headings and modest copy IS the hierarchy.
- Reserve the accent color for buttons/CTAs only.
- Write real, specific copy in the brand voice grounded in the site spec — never lorem ipsum.

Section discipline:
- **Margin reset:** add `"style":{"spacing":{"margin":{"top":"0"}}}` to the section's top-level group so it sits flush in the page flow.
- **Width discipline:** heroes, cover blocks and feature/card grids use `"align":"wide"` or `"align":"full"`. Reserve the default (content) width for text-heavy reading sections only.
- **Text measure:** the band may be full-width, but the TEXT inside it must keep a readable measure. Wrap headline/copy stacks in an inner group with `"layout":{"type":"constrained"}` whose `contentSize` is at or below the theme's contentSize — never the wide size (1200px+). Paragraphs running the full width of a wide band read as broken.
- **NO decorative HTML comments** — never write `<!-- Hero Section -->`, `<!-- Services -->` and the like. Only `<!-- wp:... -->` block comments are allowed.
- **NO EMOJIS** anywhere — not in headings, paragraphs, button text, list items, or any content.
- Be bold with layout WITHIN your archetype (see COMPOSITION above): overlap, generous or controlled whitespace, distinctive treatments that match the direction's mood — not the safe default.

Hero notes (if this is the hero section):
- The primary headline is a level-1 `wp:heading` with `"fontSize":"display"` — the theme.json `display` step is a fluid masthead size defined for exactly this one moment. Do not shrink it with a smaller preset and do not override it with an inline size.
- The supporting line under the masthead is ONE short sentence with `"fontSize":"lead"` — body-size subcopy gets lost under a masthead-scale headline, and anything longer or larger competes with it.
- Do NOT default to "text left, image right." Execute your archetype from the COMPOSITION block above in the spirit of the DESIGN DIRECTION's committed hero composition.
- Express a full-bleed hero as a `wp:cover` (align:"full") with an inner `wp-block-cover__image-background` — see the IMAGE INSTRUCTIONS pattern below.
- Text over a photo MUST stay readable at every viewport: give the cover `dimRatio` 40+ or a gradient overlay that visibly darkens (or lightens) the area BEHIND the text, and pick the text color against the DIMMED image, not the raw one. Never float light text over an un-dimmed light image area. Remember the site header may float transparently over the very top of your cover — keep some overlay coverage there too, not only behind the headline.
- A full-bleed hero BACKGROUND image (the `wp-block-cover__image-background`) MUST be `landscape` — never `square` or `portrait` — so it fills the banner cleanly. A `framed`/inset or foreground image inside the hero (e.g. a portrait in a contained frame, or a second image layered over the background) is free to be `portrait` or `square` — pick the aspect ratio that fits its own slot, and let the frame follow that aspect rather than cropping the image toward a different shape.

Text orientation (all sections):
- Keep all headline and body copy horizontal. NEVER rotate reading text — no `writing-mode: vertical-rl`/`vertical-lr`, no `transform: rotate` on headings, paragraphs, or the hero H1. Vertical orientation is allowed ONLY for a tiny decorative label or eyebrow (e.g. a frame number or single short word), never for a heading or a sentence.

Visual richness beyond the one hero image — build atmosphere with tokens, NOT extra photos and NOT `<style>` tags:
- Use theme.json gradient and shadow presets (`"gradient":"<slug>"` on cover/group backgrounds; the shadow presets for depth), color blocks, typographic scale, decorative borders (`"style":{"border":{...}}`), and spacing rhythm via inline `style` on group/heading wrappers.

Card & grid recipes — let the DESIGN DIRECTION and the section's purpose pick the recipe; do NOT default every card section to the same equal grid:

1. `equal-grid` — uniform card row, for flat hierarchies (pricing tiers, a trio of equally weighted features):
   - `wp:columns` with `"className":"equal-cards"`.
   - Each `wp:column` with `"verticalAlignment":"stretch"` and `"width":"X%"` where X = 100 / number_of_cards (2 cards → 50%, 3 → 33.33%, 4 → 25%). All widths MUST sum to exactly 100%.
   - Inside each column a single `wp:group` card wrapper holding the content (heading, paragraph, image, list).
   - Any card image: `style="height:200px;object-fit:cover;width:100%"`.
   - For a bottom-aligned CTA, wrap it in `wp:buttons` with `"className":"cta-bottom"`.
     (The supporting `.equal-cards` / `.cta-bottom` CSS already ships in the theme's style.css — just use these class hooks.)
2. `staggered-grid` — offset rhythm, for directions that promise energy or a broken grid:
   - `wp:columns` (no equal-cards class); each `wp:column` still gets a `"width"` and the widths MUST sum to 100%.
   - Push every SECOND column's card down by giving its inner card `wp:group` `"style":{"spacing":{"margin":{"top":"3rem"}}}` (odd columns get no offset). Use "4rem" for a stronger stagger.
3. `editorial-row` — one dominant card plus supporting cards, for curated/selected-work sections:
   - `wp:columns` with mixed widths that sum to 100% (e.g. 50/25/25 or 60/40); the dominant column gets the bigger image (`style="height:320px;object-fit:cover;width:100%"`) and a larger heading; supporting cards stay at `height:200px`.
4. `list-thumb` — stacked rows with a small thumbnail and text, for menus, article lists, or dense catalogs:
   - One `wp:columns` per row: a narrow image column (`"width":"18%"`, image `style="height:110px;object-fit:cover;width:100%"`) and a wide text column (`"width":"82%"`) with heading + one-line paragraph.
   - Optionally a `wp:separator` between rows for an index/menu feel.

Layout utility classes (optional, powerful) — a later build step generates the CSS for EXACTLY these class names, tuned to this design direction. You MAY add them via `"className"` on the blocks noted; NEVER invent other utility classes and NEVER add `<style>` tags:
- `overlap-up` — on a group/columns block: pulls it upward with a negative top margin so it overlaps the element above (e.g. a card row breaking into the hero). If you put it on the section's TOP-LEVEL group, omit that group's margin-top:0 reset — the class supplies the pull.
- `masonry-3` — on a group whose direct children are cards/images of varying height: flows them into a 3-column masonry (fewer columns on small screens). Use instead of forcing unequal content into equal columns.
- `hover-lift` — on a card `wp:group` or an image: lifts with a soft shadow on hover.
- `hover-reveal` — on a card `wp:group` with an image: the image dims/zooms on hover while captions and details remain visible at rest. Do not depend on hidden overlay text.
- `sticky-side` — on ONE `wp:column` of a two-column layout: that column stays pinned while the other scrolls (desktop only). Good for a sticky title/intro beside a long list.
Combine them with the recipes (e.g. a staggered-grid of hover-lift cards, or a masonry-3 gallery of hover-reveal tiles) when the direction calls for that energy.

- Where imagery genuinely strengthens this section, emit generatable AI image placeholders following the IMAGE INSTRUCTIONS below. This is the "{{section_title}}" section ({{section_purpose}}) — let that steer each image's page-context and subject.
- Every block comment must be correctly closed and the HTML class names must match the block (standard WordPress block classes).

IMAGE INSTRUCTIONS:
{{image_instructions}}

Output ONLY the block markup, starting with "<!-- wp:" — no JSON, no prose, no markdown code fences.
