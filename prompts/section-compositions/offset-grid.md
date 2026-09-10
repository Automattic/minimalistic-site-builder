### offset-grid

Build a staggered grid whose rows do not line up. Unequal column widths and a
different top offset per item give the band its broken rhythm. The stagger is
the composition, so it must be visible at the desktop side-by-side state.

The page plan assigns this archetype only when the DESIGN DIRECTION's rhythm
is `offset` or `gallery`. When the assignment reaches you under any other
rhythm, execute it as `equal-card-grid` with level tops instead of offsetting
the items.

- Structure: one `wp:columns` without the `equal-cards` class. Every
  `wp:column` carries an explicit `"width"` and the widths sum to 100%. Push
  every SECOND column's inner card group down with
  `"style":{"spacing":{"margin":{"top":"3rem"}}}`; use `"4rem"` for a stronger
  stagger. Odd columns take no offset.
- Copy budget: one heading and one lead line at most for the band, then one
  short caption line per item. The pictures carry the section, not the copy.
- Identity: the one top-level group carries the assigned root marker class.
- Media: at least two images, and one image per grid item. Use group,
  columns/column, image, gallery, heading, and paragraph. For more than six
  mixed-aspect items prefer one `masonry-3` group over repeated rows. Keep
  internal row margins at md/lg, never xl/xxl on top of outer section spacing.
- Surface/width: the band runs wide or full, and the `wp:columns` row takes
  `"align":"wide"` itself.
- Objective failure: level tops on every column, equal column widths, fewer
  than two images, or a stagger built with padding that leaves no visible
  offset.

2. `staggered-grid` — offset rhythm, ONLY in a section whose assigned archetype is `offset-grid` (the page plan assigns it only under the DESIGN DIRECTION's `offset` or `gallery` rhythm). The build levels staggered sibling tops in every other section — use `equal-grid` or `editorial-row` there instead.
   - `wp:columns` (no equal-cards class); each `wp:column` still gets a `"width"` and the widths MUST sum to 100%.
   - Push every SECOND column's card down by giving its inner card `wp:group` `"style":{"spacing":{"margin":{"top":"3rem"}}}` (odd columns get no offset). Use "4rem" for a stronger stagger.
   - For image galleries with more than six mixed-aspect items, prefer one `masonry-3` group over repeated `wp:columns` rows. Repeated unequal rows inherit the tallest card's height and create large accidental vertical holes. If masonry does not fit the direction, normalize image media with the documented card crop classes and keep row margins at md/lg — never stack xl/xxl row margins on top of outer section spacing.
