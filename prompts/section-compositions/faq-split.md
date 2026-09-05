### faq-split

Build one two-region row: the leading region introduces the questions, the
trailing region answers them in a native accordion. The band reads as one
conversation, not as a list of paragraphs.

- Structure: one `wp:columns` with two `wp:column` regions at 40/60 (or 34/66)
  and `"align":"wide"`. The leading column holds the section heading, one lead
  line and at most one action (`wp:buttons`). The trailing column holds ONE
  `wp:group` with `"className":"faq-list"` and `"style":{"spacing":{"blockGap":"0"}}`
  containing three to seven `wp:details` blocks, one per question.
- Each item: `<!-- wp:details --><details class="wp-block-details"><summary>The
  question, as a short sentence?</summary><!-- wp:paragraph --><p>One or two
  sentence answer.</p><!-- /wp:paragraph --></details><!-- /wp:details -->`.
  Never set `showContent`, `backgroundColor`, `textColor`, a border or padding
  on a details block: the theme paints the rows (hairline, chevron, spacing).
- Copy budget: the heading and lead line in the leading column; every answer
  one or two short sentences. Questions are real questions from the site's
  audience, never marketing statements with a question mark.
- Identity: the one top-level group carries the assigned root marker class.
- Media: none, or at most one small image in the leading column on
  `"className":"card-media"`.
- Surface/width: the band runs wide; the `wp:columns` row takes `"align":"wide"`
  itself. On a `contrast` band set an explicit `"textColor"` on the columns so
  the questions read; the theme's accordion rows inherit it.
- Objective failure: fewer than three `wp:details` items, questions written as
  headings or paragraphs instead of `<summary>`, an answer outside its
  details block, equal column widths, or the accordion in the leading column.
