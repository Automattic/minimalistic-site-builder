### list-with-thumbnails

Build stacked rows, each one a small thumbnail beside its own text. This is the
index recipe: a menu, a schedule, an article list, or a dense catalog.
Use it when each image identifies its item. Use equal-card-grid for a short
set of address, hours, and contact details; those details do not need thumbnails.

- Structure: use one `wp:columns` per row
  with `"isStackedOnMobile":false`, a narrow image column at `"width":"18%"`,
  and a wide text column at `"width":"82%"`. The text column sets
  `"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}`.
- `isStackedOnMobile:false` is MANDATORY for all thumbnail treatments.
{{thumbnail_treatment}}
- Never put an eyebrow or kicker line above the row heading.
- Copy budget: Put one heading and at most one short paragraph before the rows.
  Use that paragraph as the lead. Do not add a second paragraph of body copy
  below the lead. Put required item details in the rows.
  Each row holds a heading and one short line. Never put a label line above a row heading.
- Identity: the one top-level group carries the assigned root marker class.
- Media: one thumbnail per row on `"className":"card-media-thumb"`, so the site crop
  applies to every thumbnail. Use group, columns/column, image, heading, paragraph,
  list, separator, and buttons.
- Surface/width: the band runs wide or full, and each row takes
  `"align":"wide"` itself. The theme limits the row containers, rows, and
  separators to a narrow column. Put the title and introduction in a separate
  group with the standard section width and text measure. Keep that group
  outside the row container. The band background can stay full width. Author the `18%`/`82%`
  widths without a custom `width`, `max-width`, or inline width style.
  A `wp:separator` between rows is allowed. Keep it inside the row container.
- Objective failure: rows with no thumbnail, a row that stacks the thumbnail
  above its text on desktop, `"isStackedOnMobile"` left at its default, or rows
  rebuilt as a card grid.
