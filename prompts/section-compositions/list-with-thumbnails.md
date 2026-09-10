### list-with-thumbnails

Build stacked rows, each one a small thumbnail beside its own text. This is the
index recipe: a menu, a schedule, an article list, or a dense catalog.
Use it when each image identifies its item. Use equal-card-grid for a short
set of address, hours, and contact details; those details do not need thumbnails.

- Structure: use one `wp:columns` per row
  with `"isStackedOnMobile":false`, a narrow image column at `"width":"18%"`,
  and a wide text column at `"width":"82%"`. The text column sets
  `"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}`.
- `isStackedOnMobile:false` is MANDATORY for BOTH flush and framed rows.
- Flush rows: put `"className":"list-thumb-flush"` on the row columns, with
  border/radius but NO padding and NO `verticalAlignment`. ONLY the text
  column carries `sm` padding on all sides. The theme stretches the thumbnail
  to the row edges and zeroes the column gap; that text padding is the entire
  image-to-text distance, so never enlarge it beyond `sm`.
- Framed rows: the row columns carry the border, `sm` padding on all sides,
  and `"verticalAlignment":"center"`; the thumbnail sits inside the padding.
- Never put an eyebrow or kicker line above the row heading.
- Copy budget: Put one heading and at most one short paragraph before the rows.
  Use that paragraph as the lead. Do not add a second paragraph of body copy
  below the lead. Put required item details in the rows.
  Each row holds a heading and one short line. Never put a label line above a row heading.
- Identity: the one top-level group carries the assigned root marker class.
- Media: one thumbnail per row on `"className":"card-media-thumb"`, so every
  thumbnail crops square. Use group, columns/column, image, heading, paragraph,
  list, separator, and buttons.
- Surface/width: the band runs wide or full, and each row takes
  `"align":"wide"` itself. The theme gives the introduction, row containers,
  rows, and separators one shared width limit. The band background can stay full width.
  Keep the introduction and rows in the same column. Author the `18%`/`82%`
  widths without a custom `width`, `max-width`, or inline width style.
  A `wp:separator` between rows is allowed. Keep it inside the row container.
- Objective failure: rows with no thumbnail, a row that stacks the thumbnail
  above its text on desktop, `"isStackedOnMobile"` left at its default, or rows
  rebuilt as a card grid.
