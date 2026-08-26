### mixed-width-editorial

Build a magazine row that mixes one wide feature with one or two narrow notes.
The width difference IS the hierarchy: the feature is the story and the notes
sit beside it as margin material. Do not level the widths into a card row.

- Structure: one `wp:columns` with mixed widths that sum to 100% — for example
  66/34, 60/40, or 50/25/25. The dominant column takes the larger image
  (`"className":"card-media-tall"`) and the larger heading; the supporting
  columns stay on `"className":"card-media"`.
- Copy budget: one heading and one lead line for the band. The feature holds a
  heading and one or two short paragraphs; each note holds a heading and one
  line.
- Identity: the one top-level group carries the assigned root marker class.
- Media: one image for the feature, and at most one per note. Use group,
  columns/column, image, media-text, heading, paragraph, list, and buttons.
- Surface/width: the band runs wide or full, the `wp:columns` row takes
  `"align":"wide"` itself, and the band's copy stack takes the same align plus
  `"className":"copy-flush"`.
- Objective failure: equal column widths, a feature and a note at the same type
  scale, or a row rebuilt as a uniform card grid.
