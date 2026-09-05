### stat-ledger

Build the section's proof as one ledger row: three or four figures side by
side, each a large number over one short label, separated by hairlines the
theme draws. No cards, no images, no paragraphs of explanation inside the row.

- Structure: one `wp:columns` with `"align":"wide"` holding three
  `wp:column` at `"width":"33.33%"` or four at `"width":"25%"`. Each column
  holds, in this order, ONE level-3 `wp:heading` whose entire text is the
  figure with its unit or sign ("120+", "98%", "$4.2M", "1,200", "12 yrs") and
  ONE `wp:paragraph` with `"fontSize":"caption"` naming what it counts in two
  to five words. Nothing else in a column.
- Figures: use only numbers the SITE SPEC or the section notes state; when a
  figure is not given, do not invent one, and drop the section to three
  figures or fold the missing one into the band's lead line. Write the figure
  once, plain: no words before the digits, no trailing sentence.
- Copy budget: one heading and at most one lead line for the band, then the
  row. No footnote after the row.
- Identity: the one top-level group carries the assigned root marker class.
- Media: none. Use group, columns/column, heading, and paragraph only.
- Surface/width: the band runs wide; the theme draws the hairlines between
  columns and sets the figure scale, so author no separators, borders,
  font sizes or colours on the figures.
- Motion: the build gives every figure the count-up entrance on its own; do
  not add motion classes inside the row.
- Objective failure: two rows, fewer than three or more than four columns, a
  column whose first block is not a figure-only heading, an image, or a card
  group inside a column.
