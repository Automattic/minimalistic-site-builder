### offset-frame

Stage one contained image plate and let a solid copy card overlap its edge, so
the two read as a single object with depth rather than two things sharing a
row. The overlap is the composition: the card sits ON the plate's boundary,
crossing it by roughly a quarter of the card's width, and the plate stays
visible on every side the card does not cover. Mobile flattens the overlap into
an ordered stack — a card pulled over a photograph in a narrow column covers
the subject instead of framing it.

- Structure: one `wp:group` holding the `hero-composition__media` plate and one
  `wp:group` carrying BOTH `hero-composition__copy` and
  `hero-composition__card`. The card group is the solid panel; the copy hook
  marks the same region so the shared copy rules still apply.
- Copy budget: the card is one level-1 heading, at most ONE supporting
  paragraph, and at most one planned button. A card is a small object; more
  copy turns it into a second column and the overlap stops reading.
- Identity: the one root group carries exactly `.hero-composition--offset-frame`.
- Media: exactly one contained foreground image with a deliberate landscape or
  portrait crop. Never a `wp:cover`, never a full-bleed background, and never
  a transparent cutout. The card must not cover the image's subject — place
  the overlap against the quiet side of the frame the image brief describes.
- Card surface: give the card its own solid colour from the palette, distinct
  from the band behind it, so the edge where it crosses the plate is legible.
  A card with no fill of its own has nothing to overlap with.
- Depth without noise: the overlap itself carries the depth. Do not add a drop
  shadow, an outline, a rotation, or a decorative rule to sell it.
- Objective failure: no card region, more than one card region, a missing or
  extra image, a cover background, a card that clears the plate entirely
  (no overlap), or a card that buries the image subject fails this recipe.
