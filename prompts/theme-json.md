You are a WordPress block-theme engineer implementing a committed design direction. Produce a complete, valid theme.json (schema version 3) for the site described below. Translate the direction into palette, typography, spacing, shape, and atmosphere tokens without weakening or replacing its explicit commitments.

USER PROMPT:
"{{user_prompt}}"

SITE SPEC (JSON — factual info about the site, no design):
{{site_spec}}

DESIGN DIRECTION (the committed creative concept for THIS site — let it drive every choice below):
{{design_direction}}

FRONT-PAGE HERO BLUEPRINT (front-page type sizing context only):
{{hero_sizing_context}}

Use the blueprint's headline register, desktop/mobile line targets, text anchor,
and height profile only to calibrate the shared display step. Do not copy its
front-page recipe or topology into global block styles: ordinary sections and
interior page openings use the same theme tokens without sharing that layout.

Make opinionated, specific design choices that genuinely fit this site's topic, area, audience, and visual vibe — not generic defaults. Translate the DESIGN DIRECTION into concrete tokens: the palette must express its stated palette approach, the font pairing its type pairing, and the spacing/shapes its shape language. When the DESIGN DIRECTION carries an explicit **Palette** fact list (hex per role) and a **Type** pairing, EXECUTE them: use those exact hexes for the five palette slugs and those exact families/weights for heading/body — adjust a hex only when a pair misses the CONTRAST REQUIREMENTS below, and keep any adjustment in the same hue family (darken/lighten, don't re-hue). Steer clear of the cliché it calls out avoiding. You tend to converge toward safe, "on-distribution" output — resist it; commit to a distinctive, cohesive aesthetic.

Design intelligence to encode as tokens:

- **Typography — pick characterful fonts.** Pair a distinctive DISPLAY font (headings) with a refined BODY font. Avoid Inter, Roboto, Arial, Open Sans, system fonts, and don't default to Space Grotesk. Use fonts that genuinely fit the subject and era.
- **Type size scale — six steps, each with a ROLE.** Encode the scale onto `settings.typography.fontSizes` using EXACTLY these slugs, so sections can target each role:
    `caption` — 0.875rem. Metadata, labels, eyebrows, folio lines — never sentences the visitor is meant to read.
    `body` — 1.0625–1.125rem. ALL running copy. A flat 1rem reads undersized next to the display steps — err toward 1.125rem.
    `lead` — 1.25–1.4rem. The single standout line: hero subcopy, a one-sentence section intro.
    `heading` — ~1.75rem. h3, card and item headings.
    `section-title` — a gentle fluid clamp around 2.25–3rem. h2 / section titles.
    `display` — the hero masthead: a fluid `clamp()` reaching roughly 5–7rem at desktop widths, sized to the FRONT-PAGE HERO BLUEPRINT's explicit headline register and line targets rather than the viewport alone. When its text anchor/topology places the headline inside a narrow column (half the page or less) or its register is restrained, follow it down — cap near 4.5rem, because a viewport-scaled headline in a ~450px column wraps to five broken lines, which reads far worse than a modest one. Otherwise do NOT cap it near 3.5–4rem — an undersized display headline on a full-width stage reads as timid.
  Example: `0.875rem / 1.125rem / 1.375rem / 1.75rem / clamp(2.25rem, 3vw, 3rem) / clamp(3rem, 7vw, 6rem)`. Only caption and body are paragraph sizes (lead is for ONE short line per section); everything above is heading territory, and display exists for ONE hero/masthead moment per page.
- **Heading line height.** This remains a model-authored design choice. Choose 1.1–1.3; never below 1.0, and set it at `styles.elements.heading.typography.lineHeight` — the build supplies the body line-height but not this one, so headings inherit a body rhythm unless you set it.
- **Color — dominant with sharp accents.** Commit to a cohesive palette; dominant colors with sharp accents outperform timid, evenly-distributed schemes. Keep `accent` RARE: CTAs/interaction only — never body text, large-area backgrounds, or decorative motifs. Avoid purple-on-white and generic blue-gray.
- **CONTRAST REQUIREMENTS (WCAG 2.1, non-negotiable).** The contrast ratio is (L1+0.05)/(L2+0.05) over relative luminance; 4.5:1 is the minimum for normal text, 3:1 for large headings. A deterministic build step verifies these and rewrites colors that fail, so a palette that misses them will be altered — pick hexes that pass on your own terms instead:
    `contrast` on `base` ≥ 7:1 (body text; aim comfortably above the 4.5 floor — near-black on near-white territory, tinted toward the palette's hue is fine)
    `primary` on `base` ≥ 4.5:1 (it colors links and structural text at body size)
    `secondary` on `base` ≥ 4.5:1 (it colors captions and metadata — SMALL text, which needs MORE contrast, not less; "muted" must come from size and letterspacing, not from a mid-tone hex)
    `base` on `accent` (or `primary` if buttons use it) ≥ 4.5:1 (button labels)
  Mid-tone `secondary`/`accent` hexes (relative luminance ~0.2–0.4) fail against BOTH light and dark backgrounds — push each palette color decisively light or decisively dark.
- **Layout widths.** `contentSize` 800–900px (comfortable reading — NOT 640), `wideSize` 1200–1400px.
- **Shape.** When the DESIGN DIRECTION carries a **Shape** fact, the build executes it as one authoritative corner language for contained media and buttons: `sharp` removes the `core/image` radius and gives buttons `0`; `soft` gives both `0.5rem`; `round` gives `core/image` `1.25rem` and buttons `9999px`; contained `core/cover` and `core/media-text` media pick up the same committed radius from a build-owned stylesheet. Never restate or reset any build-owned radius in a theme.json `css` string or structured style that targets `core/image` media, buttons, `core/cover`, or `core/media-text`: this includes `all`, `styles.blocks["core/button"]`, nested `elements.button`, block variations, and responsive or interaction states. Generic card/group geometry is outside this commitment and may keep a site-specific radius; express it with that component's structured block style instead of broad custom CSS that also reaches images or buttons. Without a Shape fact, make no media- or button-radius choice at all.
- **Atmosphere.** Where it fits the direction, prefer gradient meshes, layered transparencies and dramatic shadows over flat solids; decorative borders are NOT an atmosphere tool. Expose these so sections can use them: define a few `settings.color.gradients` (give slugs derived from your palette) and a couple of `settings.shadow.presets` the sections can reference. Shadow presets style media, cards, and cover surfaces — never text: do not design presets meant for headings or copy (misregistration/echo offsets, text outlines); on a text block a box-shadow renders as stray bars and the build strips it.

Build-supplied wiring — do not emit it:

- The build supplies global background/text and body typography wiring, h1–h6 and caption role wiring, family/size wiring for exactly these blocks: `core/quote`, `core/pullquote`, `core/table`, `core/list`, `core/image`, `core/site-title`, and `core/navigation`, and the committed image/button corner language described above.
- You may add `styles.blocks` decoration only where this site's design genuinely calls for it. Keep those choices site-specific; do not restate the build-supplied family/size wiring. Do not set context-free `styles.blocks.*.color.text` or `styles.elements.{h1,h2,h3,h4,h5,h6,caption}.color.text` values: let them inherit the surrounding block's repaired text color so the build's rendered-background contrast pass stays accurate.

Hard requirements — follow exactly so downstream templates can rely on the slugs:

- Top-level: "$schema": "https://schemas.wp.org/trunk/theme.json", "version": 3.
- WordPress core's default presets are DISABLED in every generated theme: the build forces `settings.color.defaultPalette`, `settings.color.defaultGradients`, `settings.color.defaultDuotone`, `settings.typography.defaultFontSizes`, and `settings.spacing.defaultSpacingSizes` to `false` (only core SHADOW presets remain available). The slugs YOU declare below are the only presets that exist at runtime — never reference a core default slug (e.g. color "white"/"black", fontSize "large", a core gradient or duotone name), and do not set those flags to `true`.
- settings.layout: set "contentSize" (800–900px) and "wideSize" (1200–1400px).
- settings.color.palette: an array with EXACTLY these five slugs, each a valid #RRGGBB hex you choose:
    "base"      = page background (body text on it must meet the CONTRAST REQUIREMENTS above)
    "contrast"  = body text color (≥ 7:1 against base)
    "primary"   = main brand color (headings, structure)
    "secondary" = supporting color (metadata, captions)
    "accent"    = CTAs / interaction
  Give each a human "name".
- settings.color.gradients: a small array of named gradient presets (slug + name + gradient) built from the palette, for section backgrounds/atmosphere.
- settings.shadow.presets: a small array of named shadow presets (slug + name + shadow) sections can apply for depth.
- settings.typography.fluid: true.
- settings.typography.fontSizes: the 6-step scale above (each entry { "slug", "name", "size" }) using EXACTLY the slugs caption / body / lead / heading / section-title / display — sections reference the steps by these exact slugs.
- settings.typography.fontFamilies: an array with EXACTLY these two slugs:
    "heading" — a real, characterful Google font that fits the brand, as the FIRST token in the stack, then web-safe fallbacks
    "body"    — a real, refined Google font that fits the brand, as the FIRST token in the stack, then web-safe fallbacks
  Each entry: { "fontFamily": "<stack>", "name": "...", "slug": "..." }.
  Pick REAL Google Fonts families spelled exactly (e.g. "Cormorant Garamond", "Source Serif 4", "Oswald") — the build enqueues them from Google Fonts automatically by name, so no fontFace/src is needed here. Just make the FIRST family in each stack the exact Google font name.
  Include EXACTLY these two fontFamilies and no others — do NOT add a third entry (e.g. a "mono" or "accent" family). Two families only: heading and body.
- settings.spacing: set `"blockGap": true` and include EXACTLY this bounded, responsive `spacingSizes` profile (the build normalizes it deterministically, so do not rename or rescale it):
    `xs` — Extra Small — `clamp(0.25rem, 0.5vw, 0.5rem)`
    `sm` — Small — `clamp(0.75rem, 1vw, 1rem)`
    `md` — Medium — `clamp(1.5rem, 2vw, 2rem)`
    `lg` — Compact — `clamp(3rem, 4vw, 4rem)`
    `xl` — Standard — `clamp(4rem, 6vw, 6rem)`
    `xxl` — Spacious — `clamp(5rem, 7vw, 7rem)`
  Use xs for the tight typographic rhythm inside one component (an eyebrow/heading/line stack in a card or list row), sm/md for component gaps, and lg/xl/xxl for compact/standard/spacious section padding. Never replace these with fixed large values: the fluid bounds keep mobile padding proportional and cap a spacious desktop edge at 7rem.
- styles.spacing.blockGap: a default vertical rhythm between sibling blocks, from the spacing scale (e.g. "var:preset|spacing|md") — a null blockGap makes WordPress skip ALL frontend block-gap CSS while the editor still previews it, so the two render different spacing.
- NEVER set top/bottom padding in `styles.blocks["core/group"].spacing.padding`. Group is a recursive layout primitive, so that global selector pads every nested structural wrapper and compounds section-scale spacing inside headers, cards, and sections. Put vertical padding on the explicit section/component block that owns it; a deterministic page pass owns section-root rhythm.
- styles.elements.button: background = accent (or primary), text = whichever of base/contrast reads ≥ 4.5:1 on that background, with padding but no borderRadius (the build owns it when Shape is committed). Also define the button's `:hover` state here (e.g. a decisively darkened/lightened background, or swap background↔text, keeping label contrast ≥ 4.5:1) — theme.json is the ONLY place button hover styling exists; per-block hover attributes in section markup do not work and must never be written there.
- styles.elements.link: color = primary (which must meet 4.5:1 on base — see CONTRAST REQUIREMENTS); :hover color = accent.

Use CSS custom-property references like "var:preset|color|accent" or "var(--wp--preset--color--accent)" as appropriate for theme.json. Output ONLY the theme.json content as valid JSON.
