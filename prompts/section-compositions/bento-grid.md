### bento-grid

Build two card rows of unequal count so the set reads as tiles on a board, not
as a ledger: a row of TWO cards over a row of THREE (or three over two). Every
card carries the same construction and crop; exactly ONE card is the highlight.

- Structure: two `wp:columns` rows, each with `"className":"equal-cards"` and
  `"align":"wide"`. The two-card row uses `"width":"50%"` twice (or 60/40 when
  one tile carries the section's main proof); the three-card row uses
  `"width":"33.33%"` three times. Widths in a row sum to 100%. Each
  `wp:column` takes `"verticalAlignment":"stretch"` and holds ONE card
  `wp:group` built per the ASSIGNED CARD STYLE.
- Highlight: exactly one card group (the first tile of the two-card row unless
  the notes name another) adds `"className":"card-highlight"` to its card
  marker classes and inverts its surface with `"backgroundColor":"contrast"`
  and `"textColor":"base"` (on a `contrast` band use `"base"` / `"contrast"`).
  No other card changes colour. Never two highlights, never none.
- Copy budget: one heading and one lead line for the band. Each card holds a
  short heading and one short paragraph; at most one action in the whole band,
  inside the highlight card as a `wp:buttons` with `"className":"cta-bottom"`.
- Identity: the one top-level group carries the assigned root marker class.
- Media: at most one image per card on `"className":"card-media"`, and it is
  fine for a tile to carry none; a text-only tile is a legitimate bento tile.
  Use group, columns/column, image, heading, paragraph, list, and buttons.
- Surface/width: the band runs wide or full.
- Objective failure: one row instead of two, two rows of the same count, a row
  whose widths do not sum to 100%, zero or two highlight cards, or a card built
  differently from its siblings.
