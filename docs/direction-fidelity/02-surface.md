# Surface: paper/concrete as CSS, not prose

Directions already write kraft grain, concrete tooth, wet stone.
Nothing consumes those words. `FinalizeThemeStep` already ships a
shape kit from `shape`. Surface should work the same way: one
field, one reviewed stylesheet, every page sits on that world.

## Change

- Add `surface` to the direction: `none | paper | concrete | film
  | fabric`. Small catalog, one CSS file each.
- `FinalizeThemeStep` enqueues the matching sheet on `body` and
  contrast bands. Same pattern as `theme/assets/shape/shape.css`.
- Drop texture language from the description unless a catalog
  entry exists (see [10](10-description-fields.md)).

## Out of scope

Generated imagery of paper or concrete. Per-section textures.
Letting page-styles invent a new grain.
