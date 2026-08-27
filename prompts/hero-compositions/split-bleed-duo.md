### split-bleed-duo

Build two panels that meet at one hard vertical seam and run edge to edge
across the band: one panel is a single photograph, the other is a painted field
holding the copy. No gutter, no rounded corner, no gap, no shadow between them
— the seam is the composition, and anything that softens it removes the whole
idea. Mobile stacks the two panels in the blueprint's order, each still full
width, with the seam becoming horizontal.

- Structure: ONE `wp:media-text` with `"align":"full"`, `"imageFill":true` and
  a `mediaPosition` chosen from the blueprint's mobile order. Its media half is
  the `hero-composition__media` region; its content half is the
  `hero-composition__copy` region. A `wp:columns` with `"align":"full"` and two
  columns is the only other accepted row.
- Panel balance: the two panels are near-equal — half and half, or a
  deliberate 55/45. This recipe is not the place for a narrow plate beside a
  wide copy column; the catalog already holds that shape.
- Copy budget: the copy panel is one level-1 heading, at most ONE supporting
  paragraph, and at most one planned button. Nothing else — no caption, no
  credit line, no rule.
- Identity: the one root group carries exactly `.hero-composition--split-bleed-duo`.
- Media: exactly one image, and it fills its whole panel through the
  media-text image fill. Never a `wp:cover`, never a transparent cutout, and
  never an image that leaves its panel with visible background around it.
- Surface: the copy panel carries the planned solid base/tinted/contrast
  colour, and it must differ from the photograph's own field so the seam reads
  at a glance. Keep every copy line on that panel; text never crosses onto the
  photograph.
- Objective failure: a row that is not full-aligned, a gap or gutter between
  the panels, more or fewer than one image, a cover background, copy sitting
  over the photograph, or the two panels collapsed into one column fails this
  recipe.
