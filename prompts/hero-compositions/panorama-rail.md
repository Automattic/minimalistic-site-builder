### panorama-rail

Pair one wide foreground visual field with a single compact information rail.
The panorama is a full-width letterboxed band — the first visual element of the
section, sitting close under the header — and the rail is an intentionally
narrow anchor in its own row after the band, never a second equal column. The
rail owns the concise proposition plus any planned action, offset decisively
toward the start or end of the measure so its row reads composed, not centered.
Preserve the panoramic crop and strong horizontal rhythm on wide screens. On
mobile the media remains an ordinary image and the compact rail stays below it;
never crop the panorama into a tall cover or multiply the rail into
dashboard-like panels.

- Structure: build one wide foreground `hero-composition__media` band first,
  then one compact `hero-composition__copy` rail in a separate following row
  inside the root group. Never place the media and the rail side by side in one
  columns row: a rail taller than the panorama opens dead canvas above the
  image, and a narrow column cannot carry the rail's headline scale.
- Identity: the one root group carries exactly `.hero-composition--panorama-rail`.
- Media: exactly one wide landscape image spanning the full wide width, never a
  cover background. Use group, image, columns/column when needed for the rail
  row, heading, paragraph, and an optional planned button only.
- Band budget: the band and the rail share one first viewport. Request a truly
  panoramic crop (clearly wider than 2:1) so the band stays around half the
  viewport height, leaving the rail's headline, support line, and any planned
  action inside the fold — a band tall enough to push the action below the
  first viewport is an objective failure.
- Surface/width: use the planned solid base/tinted/contrast surface and the
  mixed-width-editorial projection; the media band is visibly wider than rail.
- Objective failure: a tall crop, media and rail as side-by-side columns, equal
  media/copy widths, multiple rail panels, missing image, empty canvas between
  the header and the panorama or above the image, or a mobile rail placed above
  the image fails this recipe.
