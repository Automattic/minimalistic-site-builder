### marquee-name

Build the opener with the site name giant and clipped behind a centered stack
of avatar, headline and action. The name is
scenery: it is painted by the build at display scale in the heading face and at
very low opacity, so it reads as texture behind the copy, never as a second
headline. The header sits above the stage on the page ground.

The blueprint's `media_aspect` decides the avatar plate: `square` or `portrait`.

- Structure, in this order inside the root group (root marker, planned surface,
  `"layout":{"type":"constrained"}`, generous top and bottom padding from the
  spacing presets): ONE `wp:group` with `"className":"hero-composition__media"`
  (`"layout":{"type":"constrained"}`) holding exactly one `wp:image` — a small
  portrait or avatar of the person, the site owner, in the blueprint's aspect;
  then ONE `wp:group` with `"className":"hero-composition__copy"` and
  `"layout":{"type":"constrained"}` holding the level-1 heading
  (`"textAlign":"center"`), at most ONE supporting paragraph
  (`"align":"center"`), and at most one planned button (centered `wp:buttons`);
  then, LAST, ONE `wp:paragraph` with `"className":"hero-composition__marquee"`
  whose only content is the site name from the SITE SPEC, exactly as written,
  with the attribute `aria-hidden="true"` on the `<p>`. Nothing else in the
  root.
- The marquee paragraph carries no fontSize, no color, no typography style and
  no alignment: the build paints it. Never put the name in a heading, never
  repeat it inside the copy group, never author a second decorative line.
- Copy budget: one level-1 heading, at most ONE supporting paragraph, at most
  one planned button. No caption, no credit line, no rules.
- Identity: the one root group carries exactly `.hero-composition--marquee-name`.
- Image: request a normal opaque portrait the JPG pipeline can produce (the
  DESIGN DIRECTION's Image kind names the style); the build rounds and tilts the
  plate. Never a transparent cutout, never text painted into the image.
- Blocks: use only group, image, heading, paragraph, and an optional planned
  button.
- Surface/width: the root keeps the planned `base` or `tinted` surface and the
  recipe's centered-stack width; it is never painted with an image and never
  with `contrast`.
- Mobile: the same centered stack, avatar first; the name clips harder at
  phone widths and that is expected.
- Objective failure: no `hero-composition__marquee` paragraph, the name inside
  the copy group or in a heading, a cover background, more than one image, or
  copy split into columns.
