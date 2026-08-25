<!-- cache-layer:site -->
{{site_context}}

<!-- cache-layer:unit -->
You are a WordPress block-theme developer AND the design lead. Build the site FOOTER template part as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters). The DESIGN DIRECTION above is this site's committed concept: the footer is its closing note, not an afterthought.

HOMEPAGE OUTLINE (for context):
{{outline}}

SITE PAGES (the whole site — hand-authored footer page links use these exact paths):
{{site_pages}}

FOOTER NAVIGATION/ACTION RULE:
{{nav_rule}}

{{composition}}

Rules:
- Build the assigned composition as a single top-level wp:group. It must include the site identity and a small credit line; add only the links, verified contact fact, or single action that its content-ownership contract genuinely leaves to the footer. Follow the FOOTER NAVIGATION/ACTION RULE for the site identity too: on a one-page site, a wp:site-title must explicitly use `"isLink":false` because its default homepage link would point straight back to the current page.
- The surrounding wp:template-part already supplies the semantic `<footer>` landmark. Keep this part's top-level wp:group on its default `<div>` wrapper: NEVER set `"tagName":"footer"` and never emit a literal `<footer>`, which would create a nested footer landmark.
- Layout contract (width rhythm): the top-level wp:group MUST declare `"layout":{"type":"constrained"}`, and when the footer is a band spanning the page it takes a real `"align":"wide"` or `"align":"full"` ATTRIBUTE — an `alignwide` className alone styles nothing. Inside it, every structural row — the site-name lockup, link/contact columns, separators, the credit line — shares ONE width: either they all stay at the default content width, or they all take `"align":"wide"`. Never mix the two; two competing left edges in one footer read as broken. A constrained `align:wide` wrapper does NOT make its children wide automatically: in a flat wide stack, every direct site-title, heading, paragraph, separator, navigation, buttons, or nested structural row also takes `"align":"wide"`; a wide wrapper around content-width children is still mixed-width.
- A row of 3+ columns does not fit the content width: any multi-column link/contact row takes `"align":"wide"` so each column keeps a comfortable measure — an email address or link label must never wrap mid-word.
- The footer renders on EVERY page, so each link must resolve everywhere: page links use the SITE PAGES paths verbatim, and a link to a homepage section from the outline is root-relative — `href="/#anchor"` (the outline line's [#anchor]), NEVER a bare `href="#anchor"`, which is dead on every page except the homepage itself. No `href="#"` placeholders. External/social links use only an exact URL present in the SITE SPEC; otherwise omit the link or render its label as plain text. A contact action may use a mailto: only for an exact email in SITE SPEC. Never invent an email, street address, phone number, or URL.
- Carry the DESIGN DIRECTION into the footer through its palette, typography, image grade, shape language, and assigned composition.
- Separators and borders are not default footer furniture. Use at most one, and only for a genuinely tabular split-ledger row; never stack rules between every footer row and never use one merely to create the top seam.
- Contrast on dark footers: when the footer group has a dark "backgroundColor", set an explicit light "textColor" on the group AND explicit link colors so links don't fall back to an unreadable dark default — e.g. `"style":{"elements":{"link":{"color":{"text":"var:preset|color|base"}}}}` on the footer group (pick the palette slug that actually reads on that background). That link recipe (plus a `:hover` link **text** color) is the ONLY `elements` styling that works in block markup — never write other `elements` paths (`elements.heading`, `elements.button`, hover backgrounds); color text with `"textColor"` instead.
- The assigned root background is authoritative: do not add a root gradient, background image, inline background declaration, `style.css`, or `style.variation` that could paint over it. Image-led compositions put their image in a child image/cover/media-text region, not on the root group.
- The credit line is understated and adapts to the theme — small font size, muted color (e.g. secondary), heading/body font as fits — e.g. a "Built with WordPress" line. Keep it neutral; NO EMOJIS anywhere in the footer.
- Use valid CORE block markup only (group, columns/column, site-title, heading, paragraph, navigation/navigation-link, list, separator, social-links, image, cover, media-text, buttons/button). Use image/cover/media-text only when the assigned archetype calls for a real content image, and use buttons/button only for the one real action its catalog entry permits.
- Utility links are ALWAYS a `wp:navigation` of hand-authored `wp:navigation-link` entries for SITE PAGES except the front page — never a Home item, and never `wp:page-list` or `wp:home-link`. A bare `wp:page-list` outside `wp:navigation` inherits none of the block's layout and stacks vertically wherever the composition asked for a rail, and `wp:list` is for prose, never for navigation. Give the navigation a `"layout"` that matches the composition — `{"type":"flex","flexWrap":"wrap"}` for a utility baseline rail, `{"type":"flex","orientation":"vertical"}` for a link column — and `"overlayMenu":"never"` so a footer nav never collapses into a hamburger. If genuine prose content calls for a `wp:list`, keep the registered default style; NEVER invent `is-style-none`, `is-style-plain`, or another unregistered list-style class to hide bullets.
- Reference theme.json presets by slug: colors via "backgroundColor"/"textColor" (base, contrast, primary, secondary, accent); the sixth `band` slug is reserved for tinted section surfaces and must not appear in the footer. Fonts via "fontFamily" (heading, body); font sizes via "fontSize" (the theme.json fontSizes slugs) — never hardcode a raw `font-size` value or `clamp()`.
- Keep it self-contained: no header/footer template-part references, no <html>/<body>.
- Any inline `style` or extra class you write in the HTML MUST be mirrored in the block comment's JSON attributes (supported paths like `"style":{"spacing":{...},"elements":{...}}`, `"className"`) — a later build step re-serializes blocks from their attributes and silently deletes styles that exist only in the HTML.
- Every block comment must be correctly closed and HTML class names must match the block.
- If the SITE SPEC carries a non-empty `animation_request` AND the element it describes lives in the footer (e.g. a social icon or the footer wordmark), add `"className":"custom-motion"` to that ONE block; a later build step generates the CSS implementing the request for exactly that class. On dynamic blocks (wp:site-title, wp:social-links) the comment attribute alone is enough. Do NOT write the animation CSS yourself, and do not use the class anywhere else.
- LANGUAGE: write ALL user-facing footer copy — link labels, contact lines, the copyright and credit lines — in {{language}}. Do not mix languages; proper nouns and the spec's identity values stay verbatim.
- IDENTITY: the footer speaks for the spec's ONE committed identity. The copyright line credits `persona_name` when set, otherwise `name` — exactly as written in the spec, never a rephrased or generic descriptor. Any email, phone, address, or URL shown must be an exact SITE SPEC value; if the spec has none, omit that contact line. NEVER invent alternate names or contact details.

{{image_instructions}}

{{block_markup_output_contract}}
