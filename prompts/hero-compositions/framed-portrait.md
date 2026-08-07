### framed-portrait

Stage one contained vertical foreground image as a portrait object with ample
negative space and an offset horizontal type block. Preserve the portrait crop
instead of stretching it into a landscape cover. Copy stays restrained enough
to let the vertical frame lead, with at most one planned action. Mobile keeps
the intact portrait and then stacks the regions in the blueprint's order; never
turn the image into a full-bleed background or overlap text across a face.

- Structure: use an unequal columns or media-text frame with one offset
  `hero-composition__copy` region and one contained `hero-composition__media`.
- Copy budget: the copy region is one level-1 heading, at most ONE supporting
  paragraph, and at most one planned button — no further caption or credit
  lines, and no hairline rules between the copy lines.
- Identity: the one root group carries exactly `.hero-composition--framed-portrait`.
- Media: exactly one portrait-oriented foreground image; its block ratio and
  saved dimensions must remain portrait. Use group, columns/column or
  media-text, image, heading, paragraph, and optional planned button only.
- Surface/width: use the planned solid base/tinted/contrast surface and keep
  negative space around the portrait within the asymmetric-split projection.
- Objective failure: landscape/cover treatment, more than one image, loss of
  the contained frame, or copy over the portrait fails this recipe.
