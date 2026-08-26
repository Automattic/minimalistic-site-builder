### asymmetric-split

Build two columns at deliberately unequal widths, and let the imbalance read as
a decision. One column leads and the other supports. Never split the row into
two equal halves, and never center every element until the split disappears.

- Structure: one `wp:columns` (or one `wp:media-text` with an unequal media
  width) with exactly two regions. Give the columns explicit widths that sum to
  100% — for example 34/66 or 40/60. 50/50 is a failure.
- Copy budget: one heading, one lead line at most, and the section's body copy
  in the leading column. Keep the supporting column to its media, or to a short
  list or fact stack.
- Identity: the one top-level group carries the assigned root marker class.
- Media: at most one primary image for the supporting column. Use group,
  columns/column, media-text, image, heading, paragraph, list, and buttons.
- Surface/width: inside a wide or full band the `wp:columns` row takes
  `"align":"wide"` itself, and the band's own copy stack takes the same align
  plus `"className":"copy-flush"`.
- Objective failure: equal columns, a single stacked column on desktop, or a
  tall media plate beside two lines of copy that strand a blank quadrant.
