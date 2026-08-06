### layered-poster

Use one cover image beneath controlled block-built type and color layers, with
one unmistakable copy-safe zone. Every layer has a content or legibility role;
avoid ornamental clutter, rasterized text, and uncontrolled overlap. The copy
may be bold but remains confined to its safe region while the image focal area
stays visible. Mobile flattens the authored layers into a clear reading order;
never simulate desktop overlap by clipping or absolute-positioning essential
content off screen.

- Structure: the root's one direct visual child is a `wp:cover` marked
  `hero-composition__media`; nested core-block groups create one bounded
  `hero-composition__copy` zone and the controlled type/color layers.
- Safe zone discipline: the headline, the supporting copy, and any planned
  action ALL live inside the one authored copy-safe zone over the image's
  quiet area. Scale the poster gesture to that zone: when the zone spans less
  than half the wide measure, the `display` preset no longer fits its own
  words — step down to the largest heading preset whose longest word holds on
  one line (uppercase and wide-tracked type break soonest). Bound the zone's width explicitly (a constrained inner group or
  column) so no line of supporting text runs past it into the busy or focal
  area — when the copy does not fit the zone legibly, shorten the copy rather
  than widening the zone.
- Copy budget: the safe zone holds exactly one level-1 heading, at most ONE
  supporting paragraph, and at most one planned button. No hairline rules,
  caption lines, or credit lines beneath the standfirst.
- Vertical stage: the copy-safe zone rides the cover's vertical center — the
  cover's content position stays on the center row (e.g. `"center left"`),
  never pinned to the top or bottom edge of the viewport-scale stage. The
  poster's drama comes from type scale inside the zone, not from copy shoved
  into a corner with dead canvas beneath it.
- Identity: the one root group carries exactly `.hero-composition--layered-poster`.
- Media: exactly one wide cover image. Use group, cover, heading, paragraph,
  spacer when structurally needed, and optional planned button; every
  decorative layer remains a token-built core block.
- Surface/width: use the planned image surface/full-bleed-cover projection,
  with contrast as the reviewed no-image fallback; the root and cover run
  `"align":"full"` edge-to-edge on every canvas, framed included.
- Objective failure: extra images, rasterized lettering, missing safe copy zone,
  essential absolute-positioned content, or uncontrolled overlap fails.
