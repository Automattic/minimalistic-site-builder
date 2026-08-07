### cinematic-safe-zone

Build a landscape cover stage whose proposition sits centered in the frame: one
authored quiet region in the horizontal center reserved for the copy, with the
image's subject interest reading toward the frame's edges. Keep copy concise
and centered; never depend on an arbitrary dark overlay to create legibility —
use the planned protection tokens. One cover image owns the visual field. The
initial mobile transformation places the media before the copy in a deliberate
stacked sequence; retain an overlay only as a complete, explicitly selected
variant whose safe region remains authored.

- Structure: the root's one direct visual child is a `wp:cover` carrying both
  `hero-composition__media` and the constrained `hero-composition__copy` region.
- Centered copy: the copy region is horizontally centered in the cover (a
  constrained inner group with `"justifyContent":"center"`), the heading and
  standfirst carry `"textAlign":"center"` (`has-text-align-center`), and the
  buttons row centers itself. Never pin the copy to a corner of the frame.
- Restrained headline: the blueprint's register is `restrained` — the image
  carries the impact, so the headline holds a heading-scale preset that fits
  its own measure on one or two lines; never the `display` preset.
- Copy budget: the copy region is exactly one level-1 heading, at most ONE
  supporting paragraph, and at most one planned button. No further caption or
  credit lines.
- Identity: the one root group carries exactly `.hero-composition--cinematic-safe-zone`.
- Media: exactly one wide/landscape cover image; never add a second image or a
  portrait crop. Use only group, cover, heading, paragraph, and optional button.
- Surface/width: use the planned `image` surface with the root and cover at
  `"align":"full"` edge-to-edge on every canvas (framed included); `contrast`
  is the reviewed no-image fallback surface.
- Objective failure: no direct cover, more than one image, missing copy region,
  corner-pinned or start-aligned copy, or copy placed over the image's subject
  violates this recipe.
