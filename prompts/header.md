<!-- cache-layer:site -->
{{site_context}}

<!-- cache-layer:unit -->
You are a WordPress block-theme developer AND the design lead. Build the site HEADER template part as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters). The DESIGN DIRECTION above is this site's committed concept: the header must serve it, not fight it.

PLANNED HERO SECTION (what the header will sit directly above — or float on top of):
{{hero_brief}}

AUTHORITATIVE ABOVE-FOLD CONTRACT (canonical facts shared with the front-page hero; follow these exact mode, archetype, foreground/protection, viewport, ownership, and seam values):
{{above_fold_contract}}

HOMEPAGE OUTLINE (what the header sits above — or floats on — on the front page):
{{outline}}

SITE PAGES (the whole site — the navigation rule below says how the nav reflects them):
{{site_pages}}

{{archetype_assignment}}

{{header_behavior}}

ASSIGNED HEADER RECIPE:
{{archetype_recipe}}

Rules:
- The top-level wp:group MUST declare `"layout":{"type":"constrained"}` (add `"wideSize"` when the direction wants a wider bar) and the title/nav row goes in an inner group with `"align":"wide"`. A top-level group with no "layout" attribute renders its content edge-to-edge at the viewport — broken on wide screens.
- Give the top-level group vertical breathing room: `"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm"}}}` — every archetype, including minimal-overlay, owns this inner spacing; the trusted outer shell owns positioning and state only. Do not go beyond `md` padding: every pixel the header spends comes out of the hero's first viewport.
- HEIGHT BUDGET — the header is SECONDARY chrome, the hero headline is the page's focal point. The whole header renders as ONE compact bar, roughly 100px tall or less on desktop — never a second stacked strip or topbar row. Never build stacked eyebrow rows, multi-line lockups taller than the logo, or padded strips that push the bar toward 150px+ — the audited failure mode is a 200px masthead shoving the hero's headline and CTA below the fold.
- NO WRAPPING — the title/nav row must never wrap to a second line at any viewport 720px or wider (the theme hamburger engages below 720px, so a wrapped header is what tablets actually see). Budget the row's width honestly: wordmark + every nav label at its letter-spacing + gaps + any CTA must fit a ~1000px row. Keep it to at most 5 nav items INCLUDING the CTA button, labels of 1-2 short words. When the row still cannot plausibly fit — many items, long labels, wide tracking — cut items or shorten labels until it does.
- Omit `"overlayMenu"` on the header wp:navigation (WordPress defaults to `"mobile"`): inline links on desktop, one hamburger below the breakpoint. NEVER write `"overlayMenu":"always"` — that makes the hamburger the nav at every width, including desktop. NEVER write `"overlayMenu":"never"` — that skips the hamburger machinery entirely, so a phone has no collapsed menu and the row runs off a 375px screen. Solve a too-wide row by cutting items or shortening labels, never by making the hamburger the desktop nav and never by disabling the menu. Split-nav still authors two wp:navigation halves for the desktop row; a later pass keeps exactly one hamburger on mobile.
- TYPE HIERARCHY — the site title stays at or below `"fontSize":"heading"`; NEVER `"section-title"` or `"display"` (sole exception: the oversized-wordmark archetype, whose whole point is a display-scale wordmark — and which is only assigned when the hero cedes that role). Navigation, topbar and tagline text stay at `"fontSize":"caption"`. This keeps the hero's `display` headline at least ~2x the wordmark at every viewport — the two must read as different levels, not competing titles.
- NO ECHO — never repeat a line from the PLANNED HERO SECTION brief (its eyebrow, location line, tagline, or headline words) as header text. The hero renders those ~200px below the header; a duplicated eyebrow reads as a rendering bug. Author the header's own text (or use wp:site-tagline) only when the archetype calls for it.
- TAGLINE — the contract's `header.displays_tagline` / `header.tagline_text` is the single authority on the tagline. `wp:site-tagline` is a dynamic block: it renders exactly `tagline_text` at runtime, nothing you author. When `displays_tagline` is false, never emit wp:site-tagline anywhere in the header (the text is empty — the block renders a blank line, and a deterministic pass strips it), and never fake a tagline with an authored paragraph describing the site — that sentence is the hero's proposition territory and duplicates it ~150px above.
- SEAM — design the boundary between the header and what renders under it; never leave it accidental. Either (a) the header shares the page background (`base`) with NO bottom border, so it dissolves into the page's opening band, or (b) it is a deliberately contrasting opaque bar (its own background color clearly distinct from the band below). A 1px hairline as the ONLY thing separating a page-colored header from the hero — or a border between the header's own rows as the page's only visible line — reads as a stray rule, not design. (minimal-overlay has no seam: it floats. floating-pill has none either: the page ground shows around the pill.)
{{nav_rule}}
- `wp:site-logo` renders NOTHING until the site owner uploads a logo in the editor — that is expected and fine: include it where the archetype calls for it so the slot is ready, keep its declared width modest (40-64), and make sure the header still reads as complete without it (the wp:site-title always carries the identity). NEVER fake a logo with a wp:image or an emoji/character.
- Scroll behavior is the deterministic assignment above, not an authored block style. NEVER add `style.position`, inline `position`, legacy `header-overlay`, behavior classes, positioning or scroll-behavior CSS, or JavaScript. The trusted outer theme shell owns positioning and the deterministic backstop chooses safe top/scrolled palette surfaces and foreground color from the real theme palette.
- A CTA button is allowed only when the archetype and direction call for one — never in minimal-overlay or spread-nav, always exactly one in floating-pill and in bar-center-cta. The DESIGN DIRECTION's **CTA style** owns its fill, text color, border, padding, width behavior, interaction states, and arrow; emit a plain `wp:button` without local construction attributes or style-variation classes. A button may fill its container only when that container is at most one third of the content width (a narrow column or a card); in any wider container it keeps its intrinsic width, so never set `"width":100` on a hero, band, or half-column button.
- Use valid CORE block markup only (group, site-title, site-tagline, site-logo, navigation, navigation-link, buttons/button, image).
- Reference theme.json presets by slug: colors via "backgroundColor"/"textColor" (base, contrast, primary, secondary, accent); the sixth `band` slug is reserved for tinted section surfaces and must not appear in the header. Fonts via "fontFamily" (heading, body); font sizes via "fontSize" (the theme.json fontSizes slugs) — never hardcode a raw `font-size` value or `clamp()`. Keep accent rare and never add it locally to a button; the committed CTA construction decides whether it uses accent.
- Keep it self-contained: no header/footer template-part references, no <html>/<body>.
- Any inline `style` or extra class you write in the HTML MUST be mirrored in the block comment's JSON attributes (supported paths like `"style":{"spacing":{...},"border":{...}}`, `"className"`) — a later build step re-serializes blocks from their attributes and silently deletes styles that exist only in the HTML.
- Every block comment must be correctly closed and HTML class names must match the block.
- If the SITE SPEC carries a non-empty `animation_request` AND the element it describes lives in the header (the logo, the site title, the nav — the ONE block the request most plausibly describes), add `"className":"custom-motion"` to that ONE block; a later build step generates the CSS implementing the request for exactly that class. On dynamic blocks (wp:site-logo, wp:site-title) the comment attribute alone is enough. Do NOT write the animation CSS yourself, and do not use the class anywhere else.
- LANGUAGE: write any user-facing text you author (nav labels, a CTA button, a tagline) in {{language}} — do not mix languages. Proper nouns and the spec's identity values stay verbatim.
- IDENTITY: the masthead is the spec's `name`, exactly — prefer wp:site-title (the site title is set from the spec); if you hand-author a wordmark, use `name` verbatim, never a longer descriptor or an invented alternate.

{{block_markup_output_contract}}
