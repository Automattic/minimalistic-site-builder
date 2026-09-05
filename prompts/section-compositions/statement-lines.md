### statement-lines

Build the set as a ledger of statements: three to six single lines, each set
large, one under the other, a hairline between them. The lines are the whole
composition; there are no cards, no columns and no images.

- Structure: ONE `wp:group` with `"className":"statement-lines"` and
  `"layout":{"type":"constrained"}` holding three to six level-3 `wp:heading`
  blocks and nothing else. Each heading is ONE line of two to eight words: a
  value, a principle, a discipline, a client, a manifesto line. Never a
  paragraph, list or image inside the group.
- Copy budget: one heading and at most one lead line for the band above the
  ledger; nothing after it.
- Identity: the one top-level group carries the assigned root marker class.
- Media: none. Use group, heading and paragraph only.
- Surface/width: the band runs wide or at the content measure; the theme
  sets the line scale and draws the hairlines, so author no separators,
  borders, font sizes or padding on the headings.
- Objective failure: no `statement-lines` group or two of them, fewer than
  three or more than six headings, any other block inside the group, or an
  image anywhere in the band.
