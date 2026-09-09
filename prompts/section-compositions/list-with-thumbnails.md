### list-with-thumbnails

Build stacked rows, each one a small thumbnail beside its own text. This is the
index recipe: a menu, a schedule, an article list, or a dense catalog. The rows
share one rhythm, and the reader scans down the column of thumbnails.

- Structure: follow the `list-thumb` construction below. Use one `wp:columns` per row
  with `"isStackedOnMobile":false`, a narrow image column at `"width":"18%"`,
  and a wide text column at `"width":"82%"`. The text column sets
  `"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}`.
- Copy budget: one heading and one lead line for the band. Each row holds a
  heading and one short line. Never put a label above a row heading; useful
  category or date metadata belongs below it.
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

`list-thumb` construction — stacked rows with a small thumbnail and text, for menus, article lists, schedules, or dense catalogs:
   - One `wp:columns` per row with `"isStackedOnMobile":false`: a narrow image column (`"width":"18%"`, image `"className":"card-media-thumb"` → the committed thumbnail crop) and a wide text column (`"width":"82%"`) with heading + one-line paragraph. `isStackedOnMobile:false` is MANDATORY for BOTH flush and framed rows — without it, Core turns the small thumbnail into a full-width image above the text at 781px. Flush rows remain the documented full-height exception because their text owns the row height. Put useful category or date metadata below the heading, never above it.
   - MANDATORY: the text `wp:column` sets `"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}`. A row's internal rhythm is typographic — inheriting the page-level `md` gap between two or three short lines inflates the row and leaves the thumbnail floating in dead space.
   - Flush rows (the modern default): add `"className":"list-thumb-flush"` to the row `wp:columns` — the theme stretches the thumb to bleed to the row's top/left/bottom edges and clips it under the row's border radius. The row keeps its border but gets NO padding and NO `verticalAlignment`; ONLY the text `wp:column` carries the row's padding, `sm` on all sides. The theme zeroes the row's column gap, so that left padding IS the image-to-text distance — at `sm` each row's text sits visibly closer to its own thumb than the `md` rhythm separating the rows; anything larger breaks that grouping.
   - Framed rows (the inset variant, for print/scrapbook moods): the row `wp:columns` carries the border, `sm` padding on all sides, and `"verticalAlignment":"center"`; the thumb sits inside the padding.
   - Optionally a `wp:separator` between rows for an index/menu feel.
