### feature-row-hairlines

Build the set as one quiet row of text columns divided by hairlines: three or
four capabilities side by side, each a short heading over one or two lines.
The hairlines are the structure; there are no cards, no tiles and no images.

- Structure: one `wp:columns` with `"align":"wide"` holding three `wp:column`
  at `"width":"33.33%"` or four at `"width":"25%"`. Each column holds, in
  this order, ONE level-3 `wp:heading` of two to four words and ONE or TWO
  `wp:paragraph` of at most two lines each. Nothing else in a column: no
  group wrapper, no card marker classes, no image, no button.
- Copy budget: one heading and at most one lead line for the band, then the
  row. No footnote after the row.
- Identity: the one top-level group carries the assigned root marker class.
- Media: none. Use group, columns/column, heading, and paragraph only.
- Surface/width: the band runs wide; the theme draws the hairlines between
  the columns and turns them horizontal when the row stacks on phones, so
  author no separators, borders or padding on the columns.
- Objective failure: two rows, fewer than three or more than four columns, a
  column that does not open with its heading, a card or group shell inside a
  column, an image, or a paragraph longer than two lines.
