### knockout-type

Cut the headline out of a solid panel so the photograph shows through the
letterforms and nowhere else. The visitor sees a flat field of colour with one
word or two standing open onto an image. There is NO visible rectangle of
photography in this composition — if a picture is showing anywhere outside the
letters, the recipe has not been built.

- Structure: one `wp:cover` carrying the AI_IMAGE background with
  `"dimRatio":0`, holding TWO children in this order:
  1. one `wp:group` with `"className":"hero-knockout"` and the committed
     `"backgroundColor":"contrast"`, containing ONLY the level-1 heading;
  2. one `wp:group` with `"className":"hero-composition__copy"` and the same
     solid `"backgroundColor":"contrast"`, holding at most ONE supporting
     paragraph and at most one planned button.
- Why the split: the panel is blended against the photograph, so everything
  inside it is knocked out too. A standfirst inside the panel would be
  unreadable holes over the image. Keep it in the second group.
- Headline: one level-1 heading at `"fontSize":"display"`, `"textColor":"base"`,
  and at most FOUR short words across at most two lines. Letters have to be
  large and heavy enough to hold a photograph inside them; a sentence-length
  headline reduces the effect to noise. Prefer one strong noun pair.
- Dim: the cover's `dimRatio` is 0. Any dim greys the image inside the letters,
  which is the only place the image exists.
- Image brief: the letters are small windows onto the photograph, so the
  photograph must carry a WIDE tonal range in large simple areas — a bright
  subject or bright ground against deep shadow, with the contrast visible from
  across a room. Ask for it explicitly in the image request. An evenly dark
  frame, an evenly pale frame, or a busy detailed one reads as mud or as
  nothing at all once it is seen only through letter-sized openings. Avoid
  night scenes, dim interiors without a light source, and close-up texture.
- Identity: the one root group carries exactly `.hero-composition--knockout-type`.
- Surface: the panel and the copy group both carry the contrast colour, so the
  band reads as one field. Never paint the root group with the image, never add
  a second cover, and never place the headline outside the panel.
- Objective failure: no `hero-knockout` region, more than one, a region with no
  background colour, a region holding anything besides the headline, a missing
  or extra image, or a cover with a dim over the photograph fails this recipe.
