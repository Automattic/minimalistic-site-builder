### full-bleed-cover

Build one full-width band that an image or a gradient carries, with the copy
laid over it. The band is a picture first and a text block second, so keep the
copy short enough to stay legible on the surface below it. One decisive
surface owns the whole band; do not stack a second card or panel on top of the
cover to hold the words.

- Structure: the section's one top-level group holds ONE `wp:cover` at
  `"align":"full"` (`"align":"wide"` when the Canvas is `framed`). The cover
  carries a background image or a theme gradient preset, and its inner blocks
  hold the copy.
- Copy budget: one heading, at most one supporting paragraph, and at most one
  `wp:buttons`. No caption line, no credit line, and no hairline rule.
- Identity: the one top-level group carries the assigned root marker class.
- Media: the cover's own background image, or a gradient preset when the band
  is not image-led. Use group, cover, heading, paragraph, and buttons only.
- Surface/width: the cover runs edge to edge and keeps the theme's reading
  measure for its text. Set an explicit `textColor` that reads against the
  image or the gradient, and explicit link colors when the band holds a link.
- Objective failure: a band with no `wp:cover`, copy in a panel beside the
  cover instead of over it, or text that the background makes unreadable.
