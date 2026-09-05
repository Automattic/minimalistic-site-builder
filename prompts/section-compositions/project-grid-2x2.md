### project-grid-2x2

Build the work as a grid of picture tiles: rows of two, each tile one
full-bleed image with the project's name and one short meta line laid over
the bottom of the picture. The pictures carry the band; the words stay short
and sit inside the tile, never under it.

- Structure: ONE or TWO `wp:columns` at `"align":"wide"`, each holding exactly
  two `wp:column` of equal width. Every column holds exactly ONE `wp:cover`
  with `"dimRatio":40`, `"overlayColor":"contrast"`, `"isUserOverlayColor":true`,
  `"contentPosition":"bottom left"` and `"textColor":"base"`, carrying the
  tile's generated image as its background (the same asset path on the
  block's `url` and on the inner `<img>`, the `AI_IMAGE` spec in that img's
  alt). The cover's inner blocks are ONE level-3 `wp:heading` (the project's
  name, two to five words) and ONE `wp:paragraph` with
  `"className":"project-meta"` of two or three short tags joined by " · "
  (for example "Identity · Web · 2025"). Nothing else inside a tile.
- Copy budget: one heading and at most one lead line before the grid, then
  the tiles, then at most ONE `wp:paragraph` holding one link (for example
  "More projects") after the grid. No captions, no credit lines, no rules.
- Identity: the one top-level group carries the assigned root marker class.
- Media: exactly one image per tile; two or four tiles. Use group,
  columns/column, cover, heading, and paragraph only.
- Surface/width: the grid runs wide. The theme sizes every tile to one
  landscape proportion, rounds it from the committed shape, and pins the
  words to the tile's bottom edge, so author no `minHeight`, no aspect
  ratio, no padding and no margin on the covers.
- Objective failure: a row without exactly two columns, a column without
  exactly one cover, a cover without exactly one heading, a tile count other
  than two or four, or copy placed under a tile instead of over it.
