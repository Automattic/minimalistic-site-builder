### cta-panel

Build the page's closing invitation as one contained panel: a rounded card the
width of the wide measure, sitting on the page ground with air around it, not a
full-bleed band. The band itself stays quiet; the panel carries the contrast.

- Structure: the top-level group (root marker, background as planned) holds
  ONE inner `wp:group` with `"className":"cta-panel"`, `"align":"wide"`,
  `"backgroundColor":"contrast"` and `"textColor":"base"` (or the theme's
  gradient preset via `"gradient"` when the direction commits one), a
  `"layout":{"type":"constrained"}` and `"style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl","left":"var:preset|spacing|lg","right":"var:preset|spacing|lg"}}}`.
  The build rounds and clips the panel from the committed shape; author no
  radius or shadow on it.
- Inside the panel, EITHER a centered stack (heading, one lead line, one
  `wp:buttons`, all center-aligned) OR one `wp:columns` at 60/40 with the copy
  stack in the leading column and one image (`"className":"card-media"`) in
  the trailing column. Pick the split only when the plan supplies a real
  image for it.
- Copy budget: one heading (the invitation, specific to this site), one line,
  and EXACTLY ONE `wp:button` whose label and destination come from the
  planned `primary_action`. No second paragraph, no list, no second button.
- Identity: the one top-level group carries the assigned root marker class.
- Media: none, or the one image in the split variant. Use group, columns/column,
  heading, paragraph, image, and buttons.
- Surface/width: the band runs wide; the panel takes `"align":"wide"` and the
  band's own padding stays at the planned density so the panel floats on the
  page ground with the theme's rhythm above and below.
- Objective failure: no `cta-panel` group or two of them, zero or two buttons,
  a full-bleed cover instead of a contained panel, or copy beyond the one
  heading and one line.
