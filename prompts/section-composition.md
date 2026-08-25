ASSIGNED COMPOSITION (the page plan assigned every section its composition so the whole page has a deliberate rhythm — execute YOURS; do not re-choose):
  Layout archetype: {{layout_archetype}}
  Background:       {{background}}
  Vertical density: {{vertical_density}}
  Seams:            {{handoff}}
  Neighbors' assignments (design your top and bottom edges against these):
{{neighbors}}

When the below-neighbor is the assigned site footer, its ownership sentence is
a hard boundary: keep this section's planned narrative/facts/imagery/primary
CTA here, but do not turn it into a second site footer with copyright, legal
links, a sitemap, or repeated site-wide identity. Hand off through the assigned
surface and spacing; do not close with an ornamental rule/device merely for the
footer to open with another copy of it.

Execute the assigned layout archetype:
- full-bleed-cover — a full-width wp:cover (align:"full") with a background image or gradient preset and overlaid content.
- asymmetric-split — two columns at deliberately unequal widths (e.g. 34/66 or 40/60) — never 50/50.
- centered-stack — a single constrained column: headings, kickers and short one-line leads center, while body copy that wraps stays start-aligned inside the centered column (left for LTR, right for RTL). Let type scale and whitespace carry it.
- offset-grid — a staggered grid: unequal column widths and different top spacing per item so rows don't line up neatly. Photography and gallery sites only — if SITE SPEC is not a photographer/photography/photojournalism/gallery brief, execute this assignment as equal-card-grid (level tops) instead of offsetting items.
- mixed-width-editorial — a magazine-like row mixing wide and narrow items (e.g. a 66% feature beside a 33% note).
- equal-card-grid — the equal-height card recipe below.
- list-with-thumbnails — stacked rows, each a small image beside its text.

Band-width rhythm: match row width to band width. In a `"align":"wide"` or `"align":"full"` band, grid rows (multi-column wp:columns, wp:gallery, wp:media-text) take `"align":"wide"` themselves — a non-aligned row silently caps at the reading measure and floats narrow in the band. Only centered-stack (and genuinely text-led sections) lives at content width — and then the whole band commits to that width, not just some rows.

Execute the assigned background:
- base — the default page background; no backgroundColor on the section's top-level group.
- tinted — the committed "band" backgroundColor on the top-level group; never "secondary" and never a gradient. The build enforces this exact surface after generation.
- contrast — an inverted band: backgroundColor "contrast" with light text ("base" textColor) throughout.
- image — a full-bleed image band: express the section as/inside a wp:cover with an AI_IMAGE background.

You may refine details within the archetype (column ratios, spacing, type scale), but do NOT swap the archetype or background — your neighbors were planned around them, and the seams described above only work if every section holds its assignment.
If SECTION Notes mention a different layout or background, treat those layout/background words as stale planning context and reinterpret the same content inside the assigned archetype and background above.

The builder owns OUTER vertical rhythm after every section is generated. It
applies the assigned density to the top-level group for solid/tinted bands, or
inside the direct wp:cover for an image band, and collapses double padding only
across guaranteed-continuous base/contrast surfaces. Do not add top/bottom
padding, spacers, or compensating margins to the top-level group or image-band
cover. Use sm/md (occasionally lg) only for spacing WITHIN the composition.
