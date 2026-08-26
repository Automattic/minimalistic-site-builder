### offset-grid

Build a staggered grid whose rows do not line up. Unequal column widths and a
different top offset per item give the band its broken rhythm. The stagger is
the composition, so it must be visible at the desktop side-by-side state.

Use this archetype on photography and gallery sites only. When the SITE SPEC is
not a photographer, a photography, a photojournalism, or a gallery brief,
execute this assignment as `equal-card-grid` with level tops instead of
offsetting the items.

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
  mixed-aspect items prefer one `masonry-3` group over repeated rows.
- Surface/width: the band runs wide or full, and the `wp:columns` row takes
  `"align":"wide"` itself.
- Objective failure: level tops on every column, equal column widths, fewer
  than two images, or a stagger built with padding that leaves no visible
  offset.
