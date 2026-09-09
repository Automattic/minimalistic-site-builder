### project-grid-2x2

Build two or four project image tiles in rows of two.
Put each project's name and one short metadata line over the bottom of its image.

- Structure: Create one or two `wp:columns` rows with `"align":"wide"`.
- Give each row two columns of equal width.
- Put exactly one `wp:cover` in each column with `"contentPosition":"bottom left"`.
- Media: Use the same image asset path in the cover's `url` and its inner `<img>`.
- Put the `AI_IMAGE` specification in that image's alt attribute.
- Let the builder set the tile's overlay, dim ratio, gradient, and text color.
- Copy budget: Put one level-3 heading with the project name inside each cover.
- Use two to five words for that name.
- Add one paragraph with `"className":"project-meta"` for two or three short terms, such as `Identity · Web · 2025`.
- Put one section heading and at most one lead line before the grid.
- Put at most one paragraph with one link after the grid.
- Identity: Use the assigned root marker on the top-level group.

The theme sets tile proportions, radius, padding, and the bottom text position.
Omit cover height, aspect ratio, padding, and margin overrides.
Keep all tile text inside the cover.

- Surface/width: The band runs wide.
- Objective failure: Extra columns, multiple headings per tile, or text below tiles break this composition.
