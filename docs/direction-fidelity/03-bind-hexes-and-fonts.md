# Bind hexes and fonts when theme.json drifts

Palette and the two type slots are already structured.
`ThemeJsonStep` backfills missing slugs from the direction, then
from neutrals. It does not overwrite a theme.json that picked a
different family or a different hex. Contrast repair can also
walk a named color off its hue ("rosa goiaba" becoming another
red).

## Change

- When heading/body family or a palette hex disagrees with the
  direction, write the direction's value back (after the existing
  hue-preserving contrast repair only).
- Record a warning when `secondary` or `accent` is rewritten far
  enough that it no longer matches the named color.
- Keep the five slugs. Do not add palette roles here.

## Out of scope

Inventing extra palette tokens. Changing contrast math. Third
font (that is [01](01-third-font.md)).
