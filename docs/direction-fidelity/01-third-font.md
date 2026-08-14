# Third font: load the Caveat we promised

Directions keep naming a third face in prose (Caveat on flavor
labels, a condensed grotesque, a marker hand). `theme.json` may
only declare `heading` and `body`. `ThemeJsonStep::REQUIRED_FONTS`
and `prompts/theme-json.md` both forbid a third family. The
handwriting never loads, so Jujubas ships Fraunces + Work Sans.

## Change

- Add optional `type.accent` on `designDirection.json` (script,
  condensed, or mono). Empty is valid. Do not invent a face the
  seed does not need.
- Allow an `accent` fontFamily slug in theme.json. Load the
  weights the direction commits. `FontsPhpStep` / `BundleFontsStep`
  already scan families: they will pick it up if the slug exists.
- Give sections one legal hook (`fontFamily: "accent"`) for flavor
  names, prices, folio, numerals. Not body copy.
- `normalizeTypeSlot` already exists for heading/body. Reuse it.

## Out of scope

A fourth family. Foundry fonts that are not on Google Fonts.
Rotating or handwriting-as-image.
