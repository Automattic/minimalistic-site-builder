### stacked-headline-band

Stack the proposition above one full-width image band. The copy comes first at
the reading measure: the level-1 headline, then one standfirst, then the
planned action. The image band sits under all of it and runs edge to edge.
Text NEVER sits over the image, so this recipe carries no overlay-contrast
risk and needs no protection token. Mobile keeps the same order and gives the
band a taller crop; never turn the band into a background for the copy.

- Structure: the root group holds exactly two children. The FIRST is the
  `hero-composition__copy` group at the constrained reading measure. The SECOND
  is the band: put `hero-composition__media` and `"align":"full"` on the
  `wp:image` block itself, as a direct child of the root. Do not wrap that
  image in a group — a constrained wrapper caps the band at the reading measure
  and the band then stops short of both viewport edges. Never put a `wp:cover`
  in this recipe, and never nest the copy inside the media region.
- Copy budget: the copy region is one level-1 heading, at most ONE supporting
  paragraph, and at most one planned button — no caption line, no credit line,
  and no hairline rule between the copy lines.
- Identity: the one root group carries exactly `.hero-composition--stacked-headline-band`.
- Media: exactly one content image — the band itself — in the `landscape` or
  `ultrawide` aspect. The reviewed stylesheet crops the band to a letterbox
  ratio, so ask for a wide scene whose subject reads across the frame. Use only
  group, image, heading, paragraph, and an optional planned button.
- Band height: the band is short by construction. The headline, the support
  line, the planned action AND a meaningful share of the band land inside the
  first desktop viewport together. Never give the band a viewport-scale
  `minHeight` or an authored height, and never add a spacer above it.
- Surface/width: keep the planned solid base/tinted/contrast surface, and give
  the root `"align":"full"` so the band reaches both viewport edges on every
  canvas, framed included. This hero is copy-led, so its root takes the `lg`
  spacing preset for top AND bottom padding. Never paint the root with the
  image.
- Objective failure: text placed over the image band, a `wp:cover` anywhere, a
  band that stops short of the viewport edge, a missing copy or media region
  hook, more or fewer than one image, or a band so tall that the action falls
  below the fold.
