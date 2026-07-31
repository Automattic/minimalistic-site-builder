You are a WordPress block-theme developer AND the design lead. Build the site HEADER template part as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters).

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

DESIGN DIRECTION (the committed creative concept for THIS site — the header must serve it, not fight it):
{{design_direction}}

PLANNED HERO SECTION (what the header will sit directly above — or float on top of):
{{hero_brief}}

AUTHORITATIVE ABOVE-FOLD CONTRACT (canonical facts shared with the front-page hero; follow these exact mode, archetype, foreground/protection, viewport, ownership, and seam values):
{{above_fold_contract}}

HOMEPAGE OUTLINE (what the header sits above — or floats on — on the front page):
{{outline}}

SITE PAGES (the whole site — the navigation rule below says how the nav reflects them):
{{site_pages}}

{{archetype_assignment}}

Header archetype catalog (how to build each):

1. **standard-row** — site title (wp:site-title) or logo on one side, primary wp:navigation on the other, in a single top-level wp:group with a row/flex layout and space-between justification. The conventional choice — lean on the direction's typography and spacing so it doesn't read as a default.
2. **centered-masthead** — stacked and centered: the wordmark (wp:site-title, generous size, "fontFamily":"heading") on its own centered line, a centered wp:navigation beneath it — e.g. a top-level group holding two inner rows, each with `"layout":{"type":"flex","justifyContent":"center"}`. EXACTLY these two rows — no third eyebrow/tagline row above the wordmark; this is already the tallest archetype, and the hero pays for every extra pixel. Suits editorial, broadsheet, and classical directions; a hairline bottom border (`"style":{"border":{"bottom":{"width":"1px","color":"..."}}}`) often completes it.
3. **minimal-overlay** — the header floats transparently over a full-bleed hero: top-level wp:group with `"className":"header-overlay"`, NO "backgroundColor" at all, and a light "textColor" that reads against the hero image (e.g. `"textColor":"base"` when base is light — pick from the palette). NEVER sticky, no buttons, no clutter — a small site title and a quiet nav at most. The `.header-overlay` CSS (absolute positioning over the first section) already ships in the theme's style.css — the `"className":"header-overlay"` hook on the top-level wp:group is REQUIRED, not optional (a deterministic pass verifies it; without the class the header silently degrades to an opaque stacked bar); do NOT add `<style>` tags. Only ever assigned when the build has verified the hero is an image-led, full-bleed cover it can float over AND every page's opening section reads as a dark band (so the one light textColor you pick reads everywhere) — never when the DESIGN DIRECTION's **Canvas** is `framed`: the overlay spans the full viewport while a framed hero keeps a mat of page background around it, so the header would hang over the mat instead of the image.
4. **oversized-wordmark** — the site name is the dominant visual element: wp:site-title at display scale (`"fontSize":"display"` or `"section-title"`, "fontFamily":"heading"), with a small, quiet wp:navigation tucked beside or beneath it. Suits type-driven, brutalist, and studio directions. Only ever assigned when the planned hero does NOT open with a display-scale headline of its own — two display-scale titles ~100px apart are two competing mastheads, so on headline-led sites this archetype is off the table.
5. **branded-lockup** — a logo-led brand lockup on one side, navigation on the other: wp:site-logo (small — e.g. `{"width":48,"shouldSyncIcon":true}`, optionally `"className":"is-style-rounded"`) beside a tight vertical stack of wp:site-title (compact size, e.g. "fontSize":"medium") over wp:site-tagline (a step smaller, quieter color); put the title+tagline stack in its own inner wp:group with `"style":{"spacing":{"blockGap":"0"}}` so it reads as one unit. Suits friendly, product, boutique, and small-business directions.
6. **double-decker** — two stacked bars: a slim top strip (wp:site-tagline or ONE short authored line at `"fontSize":"caption"`, quieter or inverted color) above the main row of wp:site-title + wp:navigation, with a hairline border separating the two rows. The top strip counts against the height budget below — keep it to a single caption-size line with `sm` padding at most. Suits editorial, news, and commerce directions.
7. **split-nav** — the wordmark sits centered between two halves of the navigation: a single flex row with space-between holding a left wp:navigation, the centered wp:site-title ("fontFamily":"heading"), and a right wp:navigation. This is the one archetype where you hand-author wp:navigation-link entries (split the PAGE OUTLINE's likely pages across the two navs) instead of using page-list. Suits fashion, restaurant, hotel, and classical/symmetric directions.

Rules:
- The top-level wp:group MUST declare `"layout":{"type":"constrained"}` (add `"wideSize"` when the direction wants a wider bar) and the title/nav row goes in an inner group with `"align":"wide"`. A top-level group with no "layout" attribute renders its content edge-to-edge at the viewport — broken on wide screens.
- Give the top-level group vertical breathing room: `"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm"}}}` — for minimal-overlay a small base padding already ships in `.header-overlay`, but the other archetypes bring none of their own. Do not go beyond `md` padding: every pixel the header spends comes out of the hero's first viewport.
- HEIGHT BUDGET — the header is SECONDARY chrome, the hero headline is the page's focal point. The whole header renders as ONE compact bar (double-decker's slim top strip is the only sanctioned second row, and it counts against the budget), roughly 100px tall or less on desktop. Never build stacked eyebrow rows, multi-line lockups taller than the logo, or padded strips that push the bar toward 150px+ — the audited failure mode is a 200px masthead shoving the hero's headline and CTA below the fold.
- NO WRAPPING — the title/nav row must never wrap to a second line at any viewport 600px or wider (WordPress's hamburger only engages below 600px, so a wrapped header is what tablets actually see). Budget the row's width honestly: wordmark + every nav label at its letter-spacing + gaps + any CTA must fit a ~1000px row. Keep it to at most 5 nav items INCLUDING the CTA button, labels of 1-2 short words, and when the row still cannot plausibly fit — many items, long labels, wide tracking — set `"overlayMenu":"always"` on the wp:navigation so it collapses to a menu at every width instead of wrapping.
- TYPE HIERARCHY — the site title stays at or below `"fontSize":"heading"`; NEVER `"section-title"` or `"display"` (sole exception: the oversized-wordmark archetype, whose whole point is a display-scale wordmark — and which is only assigned when the hero cedes that role). Navigation, topbar and tagline text stay at `"fontSize":"caption"`. This keeps the hero's `display` headline at least ~2x the wordmark at every viewport — the two must read as different levels, not competing titles.
- NO ECHO — never repeat a line from the PLANNED HERO SECTION brief (its eyebrow, location line, tagline, or headline words) as header text. The hero renders those ~200px below the header; a duplicated eyebrow reads as a rendering bug. Author the header's own text (or use wp:site-tagline) only when the archetype calls for it.
- SEAM — design the boundary between the header and what renders under it; never leave it accidental. Either (a) the header shares the page background (`base`) with NO bottom border, so it dissolves into the page's opening band, or (b) it is a deliberately contrasting opaque bar (its own background color clearly distinct from the band below). A 1px hairline as the ONLY thing separating a page-colored header from the hero — or a border between the header's own rows as the page's only visible line — reads as a stray rule, not design. (minimal-overlay has no seam: it floats.)
{{nav_rule}}
- `wp:site-logo` renders NOTHING until the site owner uploads a logo in the editor — that is expected and fine: include it where the archetype calls for it so the slot is ready, keep its declared width modest (40-64), and make sure the header still reads as complete without it (the wp:site-title always carries the identity). NEVER fake a logo with a wp:image or an emoji/character.
- Sticky is archetype-dependent, NOT a default: NEVER sticky for minimal-overlay; for the other archetypes use `"style":{"position":{"type":"sticky","top":"0px"}}` on the top-level group only when it suits the direction, and give a sticky header an explicit "backgroundColor" so content doesn't show through it.
- A CTA button (accent color) is allowed only when the archetype and direction call for one — never in minimal-overlay.
- Use valid CORE block markup only (group, site-title, site-tagline, site-logo, navigation, navigation-link, page-list, buttons/button, image).
- Reference theme.json presets by slug: colors via "backgroundColor"/"textColor" (base, contrast, primary, secondary, accent); fonts via "fontFamily" (heading, body); font sizes via "fontSize" (the theme.json fontSizes slugs) — never hardcode a raw `font-size` value or `clamp()`. Keep accent rare: a CTA button, plus the DESIGN DIRECTION's `signature_device` motif when the direction explicitly commits accent to it — nothing else.
- Keep it self-contained: no header/footer template-part references, no <html>/<body>.
- Any inline `style` or extra class you write in the HTML MUST be mirrored in the block comment's JSON attributes (supported paths like `"style":{"spacing":{...},"border":{...}}`, `"className"`) — a later build step re-serializes blocks from their attributes and silently deletes styles that exist only in the HTML.
- Every block comment must be correctly closed and HTML class names must match the block.
- If the SITE SPEC carries a non-empty `animation_request` AND the element it describes lives in the header (the logo, the site title, the nav — e.g. "the logo should spin on hover"), add `"className":"custom-motion"` to that ONE block; a later build step generates the CSS implementing the request for exactly that class. On dynamic blocks (wp:site-logo, wp:site-title) the comment attribute alone is enough. Do NOT write the animation CSS yourself, and do not use the class anywhere else.
- LANGUAGE: write any user-facing text you author (nav labels, a CTA button, a tagline) in {{language}} — do not mix languages. Proper nouns and the spec's identity values stay verbatim.
- IDENTITY: the masthead is the spec's `name`, exactly — prefer wp:site-title (the site title is set from the spec); if you hand-author a wordmark, use `name` verbatim, never a longer descriptor or an invented alternate.

{{block_markup_output_contract}}
