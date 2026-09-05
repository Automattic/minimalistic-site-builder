### panel-stage

Build the opener as one contained stage: a rounded, tinted panel sitting on the
page ground with air around it, holding the copy on the leading side and one
foreground illustration or product mockup on the trailing side. The header
sits above the panel on the page ground; the panel never touches the viewport
edges and is never a cover.

The blueprint's `media_aspect` and `media_weight` decide the plate: read them
before you place anything and execute the pair you were given.

- Structure: the root group (root marker, planned surface) holds ONE inner
  `wp:group` with `"className":"hero-composition__panel"`, `"align":"wide"`,
  `"backgroundColor":"band"`, `"layout":{"type":"constrained"}` and
  `"style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl","left":"var:preset|spacing|lg","right":"var:preset|spacing|lg"}}}`.
  The build rounds the panel from the committed shape and lays a faint dot
  grid on it; author no radius, gradient, shadow or pattern of your own.
- Inside the panel: one unequal `wp:columns` (55/45 or 60/40) with one
  `hero-composition__copy` column (leading) and one `hero-composition__media`
  column (trailing) holding exactly one foreground image in the blueprint's
  `media_aspect`. When the plan supplies a second image, it sits BELOW that
  row inside the panel as one full-width `wp:image` with
  `"className":"hero-composition__stage"` — the product mockup — in
  landscape. Never more than two images in the hero.
- Copy budget: one level-1 heading, at most ONE supporting paragraph, and at
  most one planned button. No caption or credit lines, no rules.
- Identity: the one root group carries exactly `.hero-composition--panel-stage`.
- Image realism: request normal opaque images the JPG pipeline can produce
  (the DESIGN DIRECTION's Image kind names the style). Never a transparent
  cutout, never text painted into the image.
- Blocks: use only group, columns/column, image, heading, paragraph, and an
  optional planned button.
- Surface/width: the root keeps the planned `base` or `tinted` surface and the
  recipe's asymmetric-split width; the panel carries the tint (`band`), so the
  root is never painted with an image and never with `contrast`.
- Mobile: one ordered stack, copy first, the plate below, the stage image
  last; the panel keeps its rounded shape at every width.
- Objective failure: no `hero-composition__panel` group, a cover background,
  equal columns, more than two images, a panel painted `contrast`, or copy and
  media merged into one centered stack.
