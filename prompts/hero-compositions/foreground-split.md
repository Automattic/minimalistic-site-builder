### foreground-split

Build deliberately unequal copy and foreground-media regions, with visible
negative space making the imbalance feel authored. One ordinary content image
owns the media region and never becomes a background cover. Mobile becomes one
ordered stack, using the blueprint's copy-first or media-first order; never
collapse into an equal half-and-half split and never center every element.

The blueprint's `media_aspect` and `media_weight` decide what this composition
looks like. Both are assigned for this build; read them before you place
anything, and execute the pair you were given rather than the pair you would
have picked.

- Structure: use one unequal `wp:columns`/column pair (or one `wp:media-text`
  with a deliberately unequal media width), with one `hero-composition__copy`
  region and one `hero-composition__media` region.
- Copy budget: the copy region is one level-1 heading, at most ONE supporting
  paragraph, and at most one planned button — no further caption or credit
  lines, and no hairline rules between the copy lines.
- Identity: the one root group carries exactly `.hero-composition--foreground-split`.
- Media aspect: request exactly one foreground image in the blueprint's
  `media_aspect`, and keep the block ratio and saved dimensions in that same
  aspect.
  - `portrait` — a contained vertical plate. The build holds the plate to its
    portrait ratio from a root class it stamps itself, so add no class of your
    own for it. Give the plate room: the negative space around a portrait is
    part of the composition, and copy never crosses it.
  - `landscape` / `square` — a horizontal or square plate that fills its
    region's width.
- Media weight: `balanced` gives the copy the leading region and the image the
  supporting one. `dominant` reverses that emphasis — the image reads as the
  exhibit, and the copy stays concise beside it, never competing and never
  reduced to a caption under a wallpaper.
- Fold discipline: a portrait plate in a media region that spans half the
  composition or more renders taller than the first viewport and drags the
  vertically-centered copy below the fold. When the media region is that wide,
  the aspect is landscape or square; a portrait plate belongs in a clearly
  narrower region.
- Image realism: request a normal opaque image the JPG pipeline can produce.
  Never request a transparent cutout, and never overlap text across a face.
- Blocks: use only group, columns/column or media-text, image, heading,
  paragraph, and an optional planned button.
- Surface/width: keep the planned solid base/tinted/contrast surface and the
  recipe's asymmetric-split width; never paint the root with the image.
- Objective failure: equal columns, a missing or extra image, a cover
  background, an image whose aspect contradicts the blueprint, or copy and
  media regions merged into one centered stack fails this recipe.
