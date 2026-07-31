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
- Identity: the one root group carries exactly `.hero-composition--layered-poster`.
- Media: exactly one wide cover image. Use group, cover, heading, paragraph,
  spacer or separator when structurally needed, and optional planned button;
  every decorative layer remains a token-built core block.
- Surface/width: use the planned image surface/full-bleed-cover projection,
  with contrast as the reviewed no-image fallback; honor the canvas width.
- Objective failure: extra images, rasterized lettering, missing safe copy zone,
  essential absolute-positioned content, or uncontrolled overlap fails.
