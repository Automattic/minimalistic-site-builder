### type-manifesto

Build one imageless, type-led band. The level-1 headline runs the full wide
measure at display scale and is the only focal gesture. Below it, one short
standfirst sits in a narrower column that steps toward the trailing edge of the
same measure; it never centers under the headline and never spans the headline's
full width. The planned surface and the direction's own texture carry the mood,
because no photograph does. Negative space is the composition: the band holds a
headline, one supporting line, and at most one action, and nothing else.

- Structure: the one root `wp:group` holds ONE `wp:group` marked
  `hero-composition__copy` at `"align":"wide"` with
  `"layout":{"type":"constrained"}`. Inside it, put the level-1 heading first at
  `"align":"wide"`, then ONE `wp:group` marked `hero-composition__standfirst`
  that holds the supporting paragraph and the optional planned button. The
  reviewed stylesheet gives that inner group its narrower offset measure, so do
  not build the offset from columns, spacers, or an empty column.
- Copy budget: the copy region is one level-1 heading, at most ONE supporting
  paragraph, and at most one planned button. Add no caption line, no credit
  line, and no hairline rule. Keep the standfirst to one short sentence.
- Scale: give the heading the `display` font-size preset. The headline spans the
  wide measure here, so the preset always fits its own measure. Never set a raw
  `font-size`, a `clamp()`, or `"fitText"` on it, and never step the headline
  down to a body preset — a small headline leaves the band empty and fails the
  recipe.
- Alignment: the headline and the standfirst both start at the reading edge of
  their own box. Set `"style":{"typography":{"textAlign":"left"}}` on the
  heading for a left-to-right language, and `"right"` for a right-to-left one.
  Never center the headline: the offset between a flush headline and an offset
  standfirst is what this recipe reads as.
- Identity: the one root group carries exactly `.hero-composition--type-manifesto`.
- Media: ZERO images. Use only group, heading, paragraph, and buttons/button.
  Never emit `wp:image`, `wp:gallery`, `wp:media-text`, or `wp:cover`, and never
  set a background image, a background video, or a decorative image on any
  block.
- Surface/width: keep the planned solid `base`, `tinted`, or `contrast` surface
  and the recipe's `centered-stack` projection. Set an explicit `textColor` on
  the root whenever the surface is not `base`, and set explicit link colors when
  the band holds a link. Give the root the `lg` spacing preset on its top AND
  bottom padding, so the band breathes between the header and the next section.
- Objective failure: any `<img>` in the hero fails. A cover, a media region, a
  headline below display scale, a standfirst centered under the headline, or a
  standfirst as wide as the headline also fails.
