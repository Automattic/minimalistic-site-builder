---
task_type: tree
required: [section, page, art_direction, style_note, voice, language, page_plan, design, axis, band_colors, manifest_slice, pattern_tree, token_slugs, epoch, image_note, heading_rule]
---
You are generating ONE section of a WordPress page as TreeIR JSON. Code decides
whether it ships; there is no conversation. The section must look like it belongs
to the same designed page as every other section — the page plan below is the
shared design language; do not improvise outside it.

From the wp-blocks skill (the method — the skill is the source of truth):

> R1 — NEVER hand-write serialized block markup ('<!-- wp:'). A tree is
> {version: 1, epoch, blocks: [...]}, and a BlockNode is {name, attributes?,
> innerBlocks?} — nothing else. innerHTML in a tree is a hard schema error
> (E_TREE_SCHEMA) precisely because it is compiler output appearing in an input.
>
> R2 — The available blocks and attributes are whatever the manifest returns at the
> current fingerprint. Never assume a block exists; never use attributes not in the
> manifest entry. When your memory and the manifest disagree, the manifest is correct.
> (Real example: core/heading no longer declares textAlign; text alignment lives at
> style.typography.textAlign.)
>
> R4 — Style through the instance's styling mechanisms, in order: (1) block supports —
> preset attributes backgroundColor, textColor, fontSize, spacing presets, layout,
> align; (2) global styles / tokens. Raw inline CSS and hand-rolled class-plus-
> stylesheet styling are forbidden at every level.
>
> R5 — retrieve first: adapt the starting pattern's idiom before inventing.
>
> §2 Design quality — a default stack on a flat band is not a finished design.
> Display-scale type for hero statements; asymmetry (uneven column splits, staggered
> grids — composition, never the anchoring axis); editorial details (uppercase
> letterspaced kickers); generous, deliberate whitespace between bands. EVERY section
> gets this attention — a section below the fold with default alignment and no band
> treatment is unfinished work.
> Match complexity to the vision: maximalism needs layered systems to feel
> intentional; minimalism needs restraint and exactness — do not confuse minimal
> with unfinished.
> One axis: composition varies down the page; the anchoring axis does not — a page
> that opens on a left-anchored hero and closes on a centered CTA reads as two
> designers.

Design values live in the applied tokens, never in your tree: NO hex colours anywhere, and no
px/rem font sizes or spacing values under `style` — spend palette slugs, the
`fontSize` slug attribute, and `var:preset|spacing|NN`. Mechanics the token system
cannot express are fine as literals: letter-spacing (`em`), hairline border widths,
border radii. A hex colour or an absolute spacing/font-size literal is a dead
artifact, not a warning.

CONTENT PLACEMENT: a block's copy lives where its save() reads it. A block that
accepts innerBlocks carries its content AS innerBlocks — core/quote holds its text
as core/paragraph children (plus the citation attribute); core/list holds
core/list-item children. Never write copy into HTML-string attributes like quote's
`value` or list's `values`: the schema keeps those only to migrate old markup, the
site's save() ignores them, the text silently vanishes from the page, and the
compile gate kills the artifact for content loss.

Site art direction (every decision serves it): {{art_direction}}
{{style_note}}
Voice: {{voice}}
Language — ALL copy you write or invent (headlines, list items, kickers, captions,
button labels) is in this ONE language; no mixed-language flourishes even where the
topic, mood, or an address suggests them. Proper nouns keep their own language; your
prose does not follow them: {{language}}

Page: {{page}}
The page's design plan — every section in order, with its band and layout; YOURS is
the one matching your section id. Respect the rhythm: bands alternate down the page
and only the accent band is loud: {{page_plan}}

Your section brief: {{section}}
Your section's design: {{design}}

BAND DISCIPLINE (non-negotiable): your root node is ONE core/group with
attributes {"align": "full", "backgroundColor": "<band background slug>",
"textColor": "<band text slug>", "layout": {"type": "constrained"}} and vertical
padding from the spacing presets (style.spacing.padding top and bottom, spelled
"var:preset|spacing|<slug>"). Padding is PROPORTIONAL: one large slug top and
bottom, never more — a band's height comes from its CONTENT, and inflating
padding to fake presence produces empty fields, not design.
Your band's colors (chosen by measured contrast — use them), WITH the band's measured
ink menus: any textColor spent on this band comes from safe_inks (any text) or
display_only_inks (large ornamental display text ONLY — oversized numerals, watermark
glyphs; never body copy, labels, links or buttons). A slug in neither menu is rejected
mechanically before the tree ships. An inner group that sets its own backgroundColor
changes the ground — the same floor is enforced against the ground each text node
ACTUALLY sits on, so re-think the ink when you re-ground: {{band_colors}}

THE LAYOUT CASCADE (why the root is shaped this way): WordPress clamps every
child of a constrained layout to the theme's contentSize via
`.is-layout-constrained > *:not(.alignwide):not(.alignfull)` — a selector no
custom CSS outranks. A band without "align": "full" therefore ships squeezed
into the narrow content column; width is fixed in the tree's attributes, never
in CSS, and a gate rejects a root that is not a full band. Inner content uses
the constrained width; an inner block that must span the whole band carries
"align": "wide"; for edge-to-edge inner content (image grids, galleries) use an
inner core/group with {"align": "full", "layout": {"type": "default"}}.

VERTICAL RHYTHM: the space between sibling blocks is the theme's blockGap
(--wp--style--block-gap, applied by layout CSS as a margin between siblings),
not your margins. When the design needs a tighter or looser rhythm inside your
band, set style.spacing.blockGap on the containing group with a spacing preset
— a deliberate decision, never margins fighting the gap.

CONTENT DENSITY: realize the copy notes FULLY. Notes promising a list (taps,
menu items, hours, features) become an ACTUAL styled list or card grid with 4-6
real entries — invented faithfully in the site's voice, never summarized into
one thin paragraph. A section whose band is mostly empty background is
unfinished work.

IMAGE GEOMETRY: every image is a composition element, never a thumbnail. In a
split or asymmetric layout the image fills its column (core/columns with the
image column at 50-58%, or core/media-text with the image side). A lone hero
image is large (sizeSlug "large", or the band's media half). Gallery images
share one consistent aspectRatio. Set width AND aspectRatio deliberately on EVERY
core/image — a default-sized image floating in a band is a defect, and an image
that arrives as a placeholder pixel has no geometry of its own to fall back on.

STATEMENT SCALE: hero and cta sections carry one display-scale statement (the
largest font-size slug available) — a cta whose text whispers in a loud band is
a defect.

THE AXIS (one decision for the whole site — the header dictates it, every band
obeys it): {{axis}}
Anchor this section on axis.section: headings, copy, button rows and column content
all range the same way. "left" means ranged left — textAlign left, buttons justified
left, NO auto-centered statement stacks. "center" means a centered discipline —
statements textAlign center in a centered column, buttons justified center. When
axis.is_break is true this section is the page's ONE argued break: commit to the
opposite anchor fully (a half-break reads as a mistake). Never invent a third
alignment regime beyond the axis and the declared break.

LAYOUT: realize design.layout ON the axis, not a default stack —
- "stack": one constrained column of statement and support — display-scale heading,
  deliberate whitespace, everything anchored per the axis (a left-axis stack keeps a
  strong left margin; only a center axis centers it).
- "split": core/columns with an UNEVEN split (58/42 or 45/55), verticalAlignment set;
  the text column anchors per the axis.
- "asymmetric": offset content — an inner group with extra top padding on one column,
  media-text with the media on one side, or staggered card rows. The offsets are the
  composition; the TEXT inside still anchors per the axis.
- "grid": core/columns rows (or the block's own grid) with consistent card treatment —
  every card the same band-aware styling, card text anchored per the axis. A card's
  background sits ON the core/column node itself, never on an inner group: an inner
  group's background covers only its content height and leaves the cell ragged
  (columns are flex items that stretch; the column node is what fills the cell).

Your block vocabulary for this section (manifest slice, with attribute schemas — use
NOTHING outside it, and no attributes absent from it; W_ATTR_UNKNOWN fails the
artifact): {{manifest_slice}}

Starting pattern (adapt its idiom to YOUR band and layout; null means compose fresh):
{{pattern_tree}}

Design tokens available — spend ONLY these slugs (backgroundColor/textColor take
palette slugs; fontSize takes font-size slugs; spacing presets are spelled
"var:preset|spacing|<slug>" inside style.spacing values): {{token_slugs}}
Font sizes are the PRESET attribute — {"fontSize": "display"} on the node — never
a slug inside style.typography.fontSize (that emits invalid CSS the browser drops).

CONTRAST DISCIPLINE (non-negotiable): every palette entry above carries its hex and
tone — USE them. Never pick a colour by the sound of its slug: on this site base and
contrast may be inverted from the WordPress default, and two slugs may share one hex.
Any textColor you set on an inner node must read against the background it actually
sits on — body text at 4.5:1 or better, display-scale headings at 3:1. The measured
band pair is always safe; every override is yours to check against the hexes.

BORDERS: a per-side border carries its colour INSIDE the side entry —
{"style": {"border": {"top": {"width": "1px", "style": "solid",
"color": "var:preset|color|<slug>"}}}}. NEVER set the flat borderColor attribute
alongside a per-side style.border: WordPress paints the undeclared sides solid at
the browser's 3px default, shipping borders nobody designed. Flat borderColor is
for a full box only, and then style.border.width must be set.

{{image_note}}

Heading discipline (the verifier fails the whole run on a broken outline):
{{heading_rule}}

Output ONLY a TreeIR JSON document: {"version": 1, "epoch": "{{epoch}}", "blocks": [...]}
- blocks[] is THIS SECTION ONLY: exactly one root core/group band as specified above.
- Write real copy from the section brief's copy notes — no lorem ipsum, no placeholders.
- If manifest_slice.declared_custom_block exists, build the section around that block
  name with its declared attributes; it will exist by publish time. Its text INHERITS
  your band's ink — the block's stylesheet owns structure, not colours. When the design
  wants the block as a distinct panel or card, set backgroundColor/textColor ON the
  block instance (it declares colour supports), checked against the hexes like any
  other override.
