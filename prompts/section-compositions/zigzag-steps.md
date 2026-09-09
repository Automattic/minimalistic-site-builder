### zigzag-steps

Build three to five rows, with one step in each row.
Alternate the positions of the text and image columns between rows.

- Structure: Create one `wp:columns` row per step with `"align":"wide"`.
- Give each row a 55% text column and a 45% image column.
- Put the text column first in row one, second in row two, and continue this pattern.
- Copy budget: Put one level-3 heading of two to five words in each text column.
- Follow that heading with one or two paragraphs of at most two lines each.
- Media: Put one landscape `wp:image` with `"className":"card-media"` in the image column.
- If the plan supplies no image, use one empty `wp:group` with `"className":"step-plate"` in that column.
- Let the theme set the empty plate's size, radius, and background.
- Put one section heading and at most one lead line before the rows.
- Identity: Use the assigned root marker on the top-level group.

Keep text out of the image columns.
The theme centers the rows vertically and puts text first on phones.
Omit column alignment, order, and padding overrides.

- Surface/width: The band runs wide.
- Objective failure: Fewer than three rows, extra columns, or repeated text positions break this composition.
