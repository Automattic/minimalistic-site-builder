You are a WordPress block-theme developer AND the design lead. Build the site FOOTER template part as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters).

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

DESIGN DIRECTION (the committed creative concept for THIS site — the footer is its closing note, not an afterthought):
{{design_direction}}

HOMEPAGE OUTLINE (for context):
{{outline}}

SITE PAGES (the whole site — hand-authored footer page links use these exact paths):
{{site_pages}}

Rules:
- The footer shows the site name, a few useful links (and contact facts from the spec where given), and a small credit line at the very bottom, in a single top-level wp:group.
- Layout contract (width rhythm): the top-level wp:group MUST declare `"layout":{"type":"constrained"}`, and when the footer is a band spanning the page it takes a real `"align":"wide"` or `"align":"full"` ATTRIBUTE — an `alignwide` className alone styles nothing. Inside it, every structural row — the site-name lockup, link/contact columns, separators, the credit line — shares ONE width: either they all stay at the default content width, or they all take `"align":"wide"`. Never mix the two; two competing left edges in one footer read as broken. A constrained `align:wide` wrapper does NOT make its children wide automatically: in a flat wide stack, every direct site-title, heading, paragraph, separator, navigation, buttons, or nested structural row also takes `"align":"wide"`; a wide wrapper around content-width children is still mixed-width.
- A row of 3+ columns does not fit the content width: any multi-column link/contact row takes `"align":"wide"` so each column keeps a comfortable measure — an email address or link label must never wrap mid-word.
- The footer renders on EVERY page, so each link must resolve everywhere: page links use the SITE PAGES paths verbatim, and a link to a homepage section from the outline is root-relative — `href="/#anchor"` (the outline line's [#anchor]), NEVER a bare `href="#anchor"`, which is dead on every page except the homepage itself. No `href="#"` placeholders: prefer a real page path or a mailto: at the spec's `email_domain`.
- Carry the DESIGN DIRECTION into the footer: reuse the exact signature device and palette mood the direction committed to, in the direction's own terms. A separator or border appears here ONLY if it is that committed device — at most one; never stack separators between every footer row. Do NOT fall back to the same generic three-column layout regardless of direction — the layout, alignment, and spacing must visibly belong to THIS site.
- Stay understated: the footer is the direction expressed at low volume, quieter than the sections above it — not a second hero.
- Contrast on dark footers: when the footer group has a dark "backgroundColor", set an explicit light "textColor" on the group AND explicit link colors so links don't fall back to an unreadable dark default — e.g. `"style":{"elements":{"link":{"color":{"text":"var:preset|color|base"}}}}` on the footer group (pick the palette slug that actually reads on that background). That link recipe (plus a `:hover` link **text** color) is the ONLY `elements` styling that works in block markup — never write other `elements` paths (`elements.heading`, `elements.button`, hover backgrounds); color text with `"textColor"` instead.
- The credit line is understated and adapts to the theme — small font size, muted color (e.g. secondary), heading/body font as fits — e.g. a "Built with WordPress" line. Keep it neutral; NO EMOJIS anywhere in the footer.
- Use valid CORE block markup only (group, columns/column, site-title, paragraph, navigation, list, separator, social-links if useful).
- Reference theme.json presets by slug: colors via "backgroundColor"/"textColor" (base, contrast, primary, secondary, accent); fonts via "fontFamily" (heading, body); font sizes via "fontSize" (the theme.json fontSizes slugs) — never hardcode a raw `font-size` value or `clamp()`.
- Keep it self-contained: no header/footer template-part references, no <html>/<body>.
- Any inline `style` or extra class you write in the HTML MUST be mirrored in the block comment's JSON attributes (supported paths like `"style":{"spacing":{...},"elements":{...}}`, `"className"`) — a later build step re-serializes blocks from their attributes and silently deletes styles that exist only in the HTML.
- Every block comment must be correctly closed and HTML class names must match the block.
- If the SITE SPEC carries a non-empty `animation_request` AND the element it describes lives in the footer (e.g. a social icon or the footer wordmark), add `"className":"custom-motion"` to that ONE block; a later build step generates the CSS implementing the request for exactly that class. On dynamic blocks (wp:site-title, wp:social-links) the comment attribute alone is enough. Do NOT write the animation CSS yourself, and do not use the class anywhere else.
- LANGUAGE: write ALL user-facing footer copy — link labels, contact lines, the copyright and credit lines — in {{language}}. Do not mix languages; proper nouns and the spec's identity values stay verbatim.
- IDENTITY: the footer speaks for the spec's ONE committed identity. The copyright line credits `persona_name` when set, otherwise `name` — exactly as written in the spec, never a rephrased or generic descriptor. Any email shown must be at the spec's `email_domain`. NEVER invent alternate names or domains.

{{block_markup_output_contract}}
