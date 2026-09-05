### pricing-tiers

Build the plans as ONE row of tier cards, two or three across, every card the
same construction so the eye compares prices, not layouts. Exactly ONE tier is
the recommended plan and inverts its surface.

- Structure: one `wp:columns` with `"className":"equal-cards"` and
  `"align":"wide"`, holding two `wp:column` at `"width":"50%"` or three at
  `"width":"33.33%"`, each `"verticalAlignment":"stretch"`, each holding ONE
  card `wp:group` built per the ASSIGNED CARD STYLE. Never a second row, never
  a fourth plan (fold extras into one line under the row).
- Tier anatomy, in this order inside every card: a level-3 heading with the
  plan name; one `wp:paragraph` holding the price and its period exactly as
  the SITE SPEC or the section notes state it, with `"className":"price-figure"`
  and no fontSize or fontFamily of its own (the build sets the figure's scale);
  never invent a price, and when none is given write the plan's one-line scope
  as a plain paragraph WITHOUT the `price-figure` class; then one `wp:list` of
  three to five short features; and one
  `wp:buttons` with `"className":"cta-bottom"` holding ONE `wp:button` — the
  recommended plan's button carries the planned `primary_action`; the other
  plans' buttons use the same destination with their own short label.
- Highlight: exactly one card group (the middle of three, or the higher of two
  unless the notes name another) adds `"className":"card-highlight"` to its
  card marker classes and inverts its surface with
  `"backgroundColor":"contrast"` and `"textColor":"base"` (on a `contrast`
  band use `"base"` / `"contrast"`). No other card changes colour. Never two
  highlights, never none.
- Copy budget: one heading and one lead line for the band; per tier the name,
  the price line, the list and the action. No paragraph under the list, no
  footnote inside a card. One optional line under the row for the whole set.
- Identity: the one top-level group carries the assigned root marker class.
- Media: none. Use group, columns/column, heading, paragraph, list, and buttons.
- Surface/width: the band runs wide.
- Objective failure: two rows, one plan or four, zero or two highlight cards, a
  tier without a list or without its own button, an image, or cards built
  differently from each other.
