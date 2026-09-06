### equal-card-grid

Build one row of equal-weight cards for a flat hierarchy — pricing tiers, a
trio of services, a set of equally weighted features. Every card carries the
same construction and the same crop, so the row reads as one system.

- Structure: follow the `equal-grid` recipe above. Use `wp:columns` with
  `"className":"equal-cards"`, each `wp:column` at
  `"verticalAlignment":"stretch"` and `"width":"X%"` where X is 100 divided by
  the card count. The widths sum to exactly 100%. Build every card group with
  the ASSIGNED CARD STYLE, and put a card's bottom-aligned action, when it has
  one, in a `wp:paragraph` with `"className":"text-action cta-bottom"` holding
  one link — never a button; buttons are the page's planned actions.
- Copy budget: one heading and one lead line for the band. Each card holds a
  heading, one short paragraph or a short list, and at most one text-link
  action.
- Identity: the one top-level group carries the assigned root marker class.
- Media: at most one image per card, on `"className":"card-media"` so every
  card crops to the same ratio. Use group, columns/column, image, heading,
  paragraph, list, and buttons.
- Surface/width: the band runs wide or full, and the `wp:columns` row takes
  `"align":"wide"` itself.
- Objective failure: unequal card widths, widths that do not sum to 100%, one
  card built differently from its siblings, or mixed image crops in one row.
