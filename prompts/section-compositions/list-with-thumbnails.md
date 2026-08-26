### list-with-thumbnails

Build stacked rows, each one a small thumbnail beside its own text. This is the
index recipe: a menu, a schedule, an article list, or a dense catalog. The rows
share one rhythm, and the reader scans down the column of thumbnails.

- Structure: follow the `list-thumb` recipe above. Use one `wp:columns` per row
  with `"isStackedOnMobile":false`, a narrow image column at `"width":"18%"`,
  and a wide text column at `"width":"82%"`. The text column sets
  `"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}`.
- Copy budget: one heading and one lead line for the band. Each row holds a
  heading and one short line. Never put a label line above a row heading.
- Identity: the one top-level group carries the assigned root marker class.
- Media: one thumbnail per row on `"className":"card-media-thumb"`, so every
  thumbnail crops square. Use group, columns/column, image, heading, paragraph,
  list, separator, and buttons.
- Surface/width: the band runs wide or full, and each row takes
  `"align":"wide"` itself. A `wp:separator` between rows is allowed here,
  because the index reading is what the rule serves.
- Objective failure: rows with no thumbnail, a row that stacks the thumbnail
  above its text on desktop, `"isStackedOnMobile"` left at its default, or rows
  rebuilt as a card grid.
