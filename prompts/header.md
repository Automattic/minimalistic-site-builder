You are a WordPress block-theme developer AND the design lead. Build the site HEADER template part as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters).

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

DESIGN DIRECTION (the committed creative concept for THIS site — the header must serve it, not fight it):
{{design_direction}}

PLANNED HERO SECTION (what the header will sit directly above — or float on top of):
{{hero_brief}}

PAGE OUTLINE (the sections the nav may link to):
{{outline}}

Pick ONE header archetype — the one that serves the DESIGN DIRECTION and the planned hero composition above. Do NOT default to standard-row out of habit; choose deliberately:

1. **standard-row** — site title (wp:site-title) or logo on one side, primary wp:navigation on the other, in a single top-level wp:group with a row/flex layout and space-between justification. The conventional choice — use it only when the direction doesn't ask for anything stronger.
2. **centered-masthead** — stacked and centered: the wordmark (wp:site-title, generous size, "fontFamily":"heading") on its own centered line, a centered wp:navigation beneath it — e.g. a top-level group holding two inner rows, each with `"layout":{"type":"flex","justifyContent":"center"}`. Suits editorial, broadsheet, and classical directions; a hairline bottom border (`"style":{"border":{"bottom":{"width":"1px","color":"..."}}}`) often completes it.
3. **minimal-overlay** — the header floats transparently over a full-bleed hero: top-level wp:group with `"className":"header-overlay"`, NO "backgroundColor" at all, and a light "textColor" that reads against the hero image (e.g. `"textColor":"base"` when base is light — pick from the palette). NEVER sticky, no buttons, no clutter — a small site title and a quiet nav at most. The `.header-overlay` CSS (absolute positioning over the first section) already ships in the theme's style.css — just add the class hook; do NOT add `<style>` tags. This archetype is REQUIRED when the hero is a full-viewport/full-bleed cover and the direction calls for chrome-less, immersive, or edge-to-edge imagery.
4. **oversized-wordmark** — the site name is the dominant visual element: wp:site-title at display scale (`"fontSize":"display"` or `"section-title"`, "fontFamily":"heading"), with a small, quiet wp:navigation tucked beside or beneath it. Suits type-driven, brutalist, and studio directions.

Rules:
- The top-level wp:group MUST declare `"layout":{"type":"constrained"}` (add `"wideSize"` when the direction wants a wider bar) and the title/nav row goes in an inner group with `"align":"wide"`. A top-level group with no "layout" attribute renders its content edge-to-edge at the viewport — broken on wide screens.
- Give the top-level group vertical breathing room: `"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm"}}}` or more — for minimal-overlay a small base padding already ships in `.header-overlay`, but the other archetypes bring none of their own.
- Navigation default: the `wp:navigation` should contain `<!-- wp:page-list /-->` so it auto-reflects the site's pages — do NOT hand-author `wp:navigation-link` entries unless a curated menu is clearly wanted.
- Sticky is archetype-dependent, NOT a default: NEVER sticky for minimal-overlay; for the other archetypes use `"style":{"position":{"type":"sticky","top":"0px"}}` on the top-level group only when it suits the direction, and give a sticky header an explicit "backgroundColor" so content doesn't show through it.
- A CTA button (accent color) is allowed only when the archetype and direction call for one — never in minimal-overlay.
- Use valid CORE block markup only (group, site-title, site-logo, navigation, page-list, buttons/button, image).
- Reference theme.json presets by slug: colors via "backgroundColor"/"textColor" (base, contrast, primary, secondary, accent); fonts via "fontFamily" (heading, body); font sizes via "fontSize" (the theme.json fontSizes slugs) — never hardcode a raw `font-size` value or `clamp()`. Keep accent rare: a CTA button, plus the DESIGN DIRECTION's `signature_device` motif when the direction explicitly commits accent to it — nothing else.
- Keep it self-contained: no header/footer template-part references, no <html>/<body>.
- Any inline `style` or extra class you write in the HTML MUST be mirrored in the block comment's JSON attributes (supported paths like `"style":{"spacing":{...},"border":{...}}`, `"className"`) — a later build step re-serializes blocks from their attributes and silently deletes styles that exist only in the HTML.
- Every block comment must be correctly closed and HTML class names must match the block.
- LANGUAGE: write any user-facing text you author (nav labels, a CTA button, a tagline) in {{language}} — do not mix languages. Proper nouns and the spec's identity values stay verbatim.
- IDENTITY: the masthead is the spec's `name`, exactly — prefer wp:site-title (the site title is set from the spec); if you hand-author a wordmark, use `name` verbatim, never a longer descriptor or an invented alternate.

Output ONLY the block markup, starting with "<!-- wp:" — no JSON, no prose, no markdown code fences.
