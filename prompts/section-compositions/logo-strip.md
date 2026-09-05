### logo-strip

Build the proof as one quiet row of names: the clients, partners or press the
site can claim, set as words in the heading face, small and muted, evenly
spread on one line. Names, not pictures: the theme sets them as wordmarks, so
never request a logo image or a badge.

- Structure: an optional ONE `wp:paragraph` lead of at most eight words
  (for example "Trusted by teams at") centered above the row, then ONE
  `wp:group` with `"className":"logo-strip"`, `"align":"wide"` and
  `"layout":{"type":"flex","justifyContent":"center","flexWrap":"wrap"}`
  holding four to eight `wp:paragraph`, each ONE name of one to three words
  and nothing else: no link, no image, no punctuation, no fontSize, no color.
- Names: use the client, partner or publication names the SITE SPEC or the
  section notes give. When none are given, write plausible short names in the
  site's own world (a studio, a label, a magazine); never a real company the
  brief did not name.
- Copy budget: the lead line and the row. No heading, no paragraph of
  explanation, no action.
- Identity: the one top-level group carries the assigned root marker class.
- Media: none. Use group and paragraph only.
- Surface/width: the row runs wide; the theme sets the face, the scale, the
  muted ink and the spacing, so author no typography on the names.
- Objective failure: a name as an image, a name longer than three words,
  fewer than four or more than eight names, a heading inside the row, or two
  rows.
