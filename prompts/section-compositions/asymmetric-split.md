### asymmetric-split

Build one row of two or three regions at deliberately unequal widths, and let
the imbalance read as a decision. Never split the row into equal parts, and
never center every element until the split disappears.

Choose the region count from the content, and commit to it:

- **Two regions — a lead and a support.** The band is about ONE thing. The
  leading region carries the heading and the section's body copy; the
  supporting region carries its media, or a short list or fact stack. Widths
  are 34/66 or 40/60. This is the default; pick it unless the content is
  genuinely several items.
- **Three regions — a feature and two notes.** The band is about a feature with
  margin material beside it. Widths are 50/25/25 or 60/20/20. The feature takes
  the larger image (`"className":"card-media-tall"`) and the larger heading;
  each note stays on `"className":"card-media"`, holds a heading and one line,
  and never rises to the feature's type scale. Pick this only when you have a
  real feature and real notes — three regions of equal weight is a card grid,
  and this archetype is not one.

- Structure: one `wp:columns` (or, for two regions, one `wp:media-text` with an
  unequal media width) with explicit column widths that sum to 100%. Equal
  widths are a failure at every region count.
- Copy budget: one heading and one lead line at most for the band. At two
  regions the leading region also holds the body copy. At three, the feature
  holds a heading and one or two short paragraphs and each note holds a heading
  and one line.
- Identity: the one top-level group carries the assigned root marker class.
- Media: at most one primary image per region. Use group, columns/column,
  media-text, image, heading, paragraph, list, and buttons.
- Surface/width: inside a wide or full band the `wp:columns` row takes
  `"align":"wide"` itself, and the band's own copy stack takes the same align
  plus `"className":"copy-flush"`.
- Region balance: the regions must be able to END near each other. Do not put a
  stack of two or more images beside a region holding one or none — that
  difference cannot be absorbed by copy, and it renders as a tall blank
  quadrant under the short region. Either give the short region enough to carry
  its share, or cut the long one down.
{{pin_directive}}
- Objective failure: equal widths; a single stacked column on desktop; a tall
  media plate beside two lines of copy that strand a blank quadrant; three
  regions at one type scale; a row rebuilt as a uniform card grid.
