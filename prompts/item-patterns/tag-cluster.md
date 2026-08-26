### tag-cluster

Render the repeated items as a wrapping cluster of compact inline chips for
skills, genres, amenities, ingredients, or other short categorical labels.
The cluster is one dense visual phrase, not a grid of miniature cards.

- Use one `wp:group` with a wrapping flex layout for the set. Each short
  `wp:paragraph` chip carries `item-pattern__item`, compact horizontal/vertical
  padding, and one consistent subtle border or secondary surface.
- Chips contain short labels only: no heading-plus-description anatomy, no
  image, no button, and no nested card body.
- Let the cluster wrap naturally and keep its gap compact. Do not force equal
  widths or one chip per row.
- Preserve the assigned section archetype around the cluster; in a split, the
  cluster belongs in one region while the other carries the section intro.
