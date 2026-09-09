### faq-split

Build one unequal row with an introduction column and an accordion column.

- Structure: Create one `wp:columns` row with `"align":"wide"` and column widths of 40/60 or 34/66.
- Put the section heading, one lead line, and at most one action in the first column.
- Put one `wp:group` with `"className":"faq-list"` and `"style":{"spacing":{"blockGap":"0"}}` in the second column.
- Put three to seven `wp:details` blocks in that group.
- Copy budget: Write each question in its `<summary>` and its answer in one or two short sentences.
- Use questions that the site's audience can ask.
- Omit `showContent`, colors, borders, and padding from each details block.
- Media: Put at most one small image in the introduction column, with `"className":"card-media"`.
- On a contrast band, set the columns' `"textColor":"base"`.
- Identity: Use the assigned root marker on the top-level group.

Use this details structure:
`<!-- wp:details --><details class="wp-block-details"><summary>Question?</summary><!-- wp:paragraph --><p>Answer.</p><!-- /wp:paragraph --></details><!-- /wp:details -->`

The theme supplies the accordion borders, indicator, and spaces.
Keep each answer inside its details block.

- Surface/width: The band runs wide.
- Objective failure: Fewer than three details blocks, equal columns, or answers outside their details blocks break this composition.
