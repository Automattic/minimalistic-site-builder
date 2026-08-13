### editorial-split

Build deliberately unequal copy and foreground-media regions, with visible
negative space making the imbalance feel authored. One ordinary content image
owns the media region and never becomes a background cover. The copy region may
hold a standard proposition and one planned action. Mobile becomes one ordered
stack, using the blueprint's copy-first or media-first order; never collapse
into a generic equal half-and-half split or center every element.

- Structure: use one unequal `wp:columns`/column pair (or one `wp:media-text`
  with a deliberately unequal media width), with one `hero-composition__copy`
  region and one `hero-composition__media` region.
- Copy budget: the copy region is one level-1 heading, at most ONE supporting
  paragraph, and at most one planned button — no further caption or credit
  lines, and no hairline rules between the copy lines.
- Identity: the one root group carries exactly `.hero-composition--editorial-split`.
- Media: exactly one foreground image with a deliberate non-cover aspect. Use
  only group, columns/column or media-text, image, heading, paragraph, and an
  optional planned button.
- Surface/width: keep the planned solid base/tinted/contrast surface and the
  recipe's asymmetric-split width; never paint the root with the image.
- Objective failure: equal columns, missing/extra images, a cover background,
  or copy/media helper regions merged into one generic centered stack fails.
- not_for: a single cinematic cover, a type-only hero, or a brief that needs
  the headline centered over a full-bleed photograph.
- anti-patterns: 50/50 columns, centering every child, turning the image into
  a cover background, a hairline rule between the H1 and the standfirst.
