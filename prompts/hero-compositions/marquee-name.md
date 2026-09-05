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
  root, except the floating objects below on a `3d-object` site.
- Floating objects (ONLY when the DESIGN DIRECTION's Image kind is
  `3d-object`): after the marquee paragraph, LAST in the root, ONE `wp:group`
  with `"className":"hero-composition__objects"` and `aria-hidden="true"` on
  its `<div>`, holding two to four `wp:image`, each ONE clay object alone
  (a sphere, a torus, a cube, a cone, a ribbon) on a flat white backdrop, in
  the `3d-render` style, `square` aspect, with a `.png` filename (the build
  keys the white out and floats the cutout). No caption, no link, no class on
  the images; the build pins each object to a corner slot around the copy
  and drifts it. Never place an object inside the copy or media groups.
- The marquee paragraph carries no fontSize, no color, no typography style and
  no alignment: the build paints it. Never put the name in a heading, never
  repeat it inside the copy group, never author a second decorative line.
- Copy budget: one level-1 heading, at most ONE supporting paragraph, at most
  one planned button. No caption, no credit line, no rules.
- Identity: the one root group carries exactly `.hero-composition--marquee-name`.
- Image: request a normal opaque portrait the JPG pipeline can produce (the
  DESIGN DIRECTION's Image kind names the style); the build rounds and tilts the
  plate. Never a transparent cutout for the avatar, never text painted into
  an image; the floating objects are the one `.png` exception on a
  `3d-object` site.
- Blocks: use only group, image, heading, paragraph, and an optional planned
  button.
- Surface/width: the root keeps the planned `base` or `tinted` surface and the
  recipe's centered-stack width; it is never painted with an image and never
  with `contrast`.
- Mobile: the same centered stack, avatar first; the name clips harder at
  phone widths and that is expected.
- Objective failure: no `hero-composition__marquee` paragraph, the name inside
  the copy group or in a heading, a cover background, more than one avatar
  image, an object group with fewer than two or more than four `.png`
  objects, or copy split into columns.
