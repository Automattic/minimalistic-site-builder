### wordmark-stage

Build the opener on the name itself: the site name set giant as the level-1
headline, the way a studio or a designer opens on the name set giant, then one
short line and at most one action under it, and optionally a small facts
ledger on the trailing side. There is no picture: the name is the picture.
The build pins the name's size so it fills the width on every screen; the
DESIGN DIRECTION's heading face and case apply to it.

- Structure, in this order inside the root group (root marker, planned surface,
  `"layout":{"type":"constrained"}`): ONE `wp:group` with
  `"className":"hero-composition__copy"` and `"layout":{"type":"constrained"}`
  holding, in order, the level-1 `wp:heading` with
  `"className":"hero-composition__wordmark"` whose ENTIRE text is the site's
  exact name from the SITE SPEC (never a slogan, never a paraphrase), then at
  most ONE supporting paragraph (one sentence), then at most one planned
  button; then, optionally, ONE `wp:group` with
  `"className":"hero-composition__facts"` and
  `"layout":{"type":"flex","flexWrap":"wrap"}` holding two or three short
  `wp:paragraph` facts drawn from the SITE SPEC ("Based in Berlin",
  "Since 2016", "23 creators"), each at `"fontSize":"caption"`. Nothing else
  in the root.
- Copy budget: one level-1 heading (the name), at most ONE supporting
  paragraph, at most one planned button, at most three facts. No caption or
  credit line, no rules, no image.
- Identity: the one root group carries exactly `.hero-composition--wordmark-stage`.
- Headline: author the heading with `"fontSize":"display"`; do not author a
  size, a line height, a letter spacing or a case: the build pins the size
  and the theme owns the case.
- Blocks: use only group, heading, paragraph, and an optional planned button.
- Surface/width: the root keeps the planned `base`, `tinted` or `contrast`
  surface at the recipe's width; never an image surface.
- Mobile: the name, then the line and the action, then the facts, one stack.
- Objective failure: a headline that is not the site name, a second level-1
  heading, a picture, a fact group inside the copy group, or more than one
  fact group.
