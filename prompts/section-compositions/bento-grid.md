### bento-grid

Build two card rows with unequal counts: two cards then three, or three cards then two.
Use one card style for all five cards.

- Structure: Create two `wp:columns` rows with `"className":"equal-cards"` and `"align":"wide"`.
- Use 50/50 widths for two cards, or 60/40 if one card needs more space.
- Use 33.33% widths for three cards.
- Set each `wp:column` to `"verticalAlignment":"stretch"`.
- Put one card `wp:group` in each column, per the ASSIGNED CARD STYLE.
- Add `"className":"card-highlight"` to exactly one card, in addition to its card marker classes.
- Use `"backgroundColor":"contrast"` and `"textColor":"base"` for that card.
- On a contrast band, use `"backgroundColor":"base"` and `"textColor":"contrast"` for that card.
- Use the first card of the two-card row as the highlight, unless the notes specify another card.
- Keep the same surface on all other cards.
- Copy budget: Put one short heading and one short paragraph in each card.
- Put at most one action in the section, inside the highlight card's `wp:buttons` with `"className":"cta-bottom"`.
- Media: Put at most one image in each card, with `"className":"card-media"`.
- Put one section heading and at most one lead line before the rows.
- Identity: Use the assigned root marker on the top-level group.

- Surface/width: The band runs wide or full. A text-only card is valid.
- Objective failure: One row, equal row counts, or more than one highlight breaks this composition.
