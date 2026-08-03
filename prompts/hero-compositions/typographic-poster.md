### typographic-poster

Let real horizontal typography, scale, token-built color shapes, and whitespace
carry the entire stage without generated imagery. Use one dominant display
gesture plus readable supporting copy and at most one planned action. The
display heading owns the full wide measure: give it a container spanning the
wide content width (or clearly dominating it) so the headline sets at full
scale without breaking a word across lines — asymmetry comes from where the
supporting copy and action sit, never from squeezing the headline into a narrow
column. Keep the supporting copy to a strict budget: one short eyebrow line,
one standfirst, and at most one line of hard facts. Archival reference codes,
keyword stacks, index lists, and other marginal metadata are noise, not
composition — leave them out. Layers must remain core-block shapes and text,
never rasterized lettering, invented icons, or ornamental image assets. Mobile
flattens those layers in an authored reading order while retaining decisive
asymmetry; never fall back to a centered headline above a generic paragraph.

- Structure: compose nested groups for one `hero-composition__copy` poster
  field; there is no `hero-composition__media` region. Real block typography is
  the focal gesture.
- Identity: the one root group carries exactly `.hero-composition--typographic-poster`.
- Media: exactly zero image, cover, or media-text blocks. Use only group,
  columns/column when composition needs it, heading, paragraph, spacer,
  separator when concept-owned, and an optional planned button.
- Surface/width: use the planned base/tinted/contrast surface and the
  mixed-width-editorial projection; token-built layers may not escape the root.
- Objective failure: any image placeholder, rasterized lettering, missing
  display heading, a display heading confined to a narrow column that forces
  mid-word line breaks, metadata side rails, or a generic centered
  headline/paragraph stack fails.
