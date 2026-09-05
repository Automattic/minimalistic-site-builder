### portrait-backdrop

Build the opener around one large portrait of the person, centered on the
page ground with air around it, and put the words under it in one row: the
headline on the leading side, one short line and the action on the trailing
side, the way a personal portfolio introduces its author with the face first.
The portrait is a plate the build sizes and rounds, never a cover and never a
cutout; the header sits above it on the page ground.

The blueprint's `media_aspect` decides the plate: `portrait` or `square`.

- Structure, in this order inside the root group (root marker, planned surface,
  `"layout":{"type":"constrained"}`): ONE `wp:group` with
  `"className":"hero-composition__media"` and `"layout":{"type":"constrained"}`
  holding exactly one `wp:image` with `"className":"hero-composition__portrait"`
  — a head-and-shoulders portrait of the site owner in the blueprint's aspect;
  then ONE `wp:group` with `"className":"hero-composition__copy"` and
  `"layout":{"type":"constrained"}` holding ONE `wp:columns` at
  `"align":"wide"` with two `wp:column`: the leading column at `"width":"60%"`
  holds the level-1 heading (two or three short lines, start-aligned); the
  trailing column at `"width":"40%"` holds at most ONE supporting paragraph
  and at most one planned button. Nothing else in the root.
- The copy row is the whole copy budget: one level-1 heading, at most ONE
  supporting paragraph, at most one planned button. No caption, no credit
  line, no rules, no second image.
- Identity: the one root group carries exactly `.hero-composition--portrait-backdrop`.
- Image: request a normal opaque portrait the JPG pipeline can produce (the
  DESIGN DIRECTION's Image kind names the style), the person centered, plain
  quiet backdrop, the face toward the camera. Never a transparent cutout,
  never text painted into the image. Author no width, height, radius or
  alignment on the image: the build sizes the plate.
- Blocks: use only group, columns/column, image, heading, paragraph, and an
  optional planned button.
- Surface/width: the root keeps the planned `base` or `tinted` surface and the
  recipe's asymmetric-split width; it is never painted with an image and never
  with `contrast`.
- Mobile: the portrait first, then the headline, then the line and the
  action, one stack.
- Objective failure: no `hero-composition__portrait` image inside the media
  group, more than one image, a cover background, the copy row without two
  columns, or the headline placed above the portrait.
