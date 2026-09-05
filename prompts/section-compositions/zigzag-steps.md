### zigzag-steps

Build the sequence as a ladder: three to five rows, one step each, the copy on
one side and the media on the other, the sides swapping from row to row so the
eye walks down a zigzag. Every row is built the same way; only the side changes.

- Structure: three to five `wp:columns`, each with `"align":"wide"` and two
  `wp:column` at `"width":"55%"` (copy) and `"width":"45%"` (media). Row one
  puts the copy column FIRST, row two puts the media column FIRST, and so on,
  alternating to the last row. Each row is one step; never a third column,
  never a nested `wp:columns`.
- Copy column, in this order: the step numeral paragraph when the DESIGN
  DIRECTION carries a **Step numeral** fact (see the Step numerals rule), ONE
  level-3 `wp:heading` of two to five words, and ONE or TWO `wp:paragraph` of
  at most two lines each. Nothing else.
- Media column: ONE `wp:image` with `"className":"card-media"` in the
  blueprint's landscape crop, or, when the plan supplies no image for that
  step, ONE empty `wp:group` with `"className":"step-plate"` and
  `"backgroundColor":"band"` that the theme sizes as a plate. Never text in
  the media column.
- Copy budget: one heading and at most one lead line for the band, then the
  ladder. No footnote after the ladder.
- Identity: the one top-level group carries the assigned root marker class.
- Media: at most one image per step. Use group, columns/column, image,
  heading, and paragraph only.
- Surface/width: the band runs wide; the theme centers each row vertically
  and stacks the row copy-first on phones, so author no vertical alignment,
  order or padding on the columns.
- Objective failure: fewer than three or more than five rows, a row without
  exactly two columns, two consecutive rows with the copy on the same side, a
  third column, or text in the media column.
