### rule-row

Render the repeated items as a compact vertical ledger: name at the start,
detail at the end, and one deliberate hairline carrying the eye across each
row. This is for menus, schedules, directories, and price lists; it is not a
stack of boxes.

- Build one non-stacking `wp:columns` row per item, usually 35/65 or 40/60,
  and put `item-pattern__item` on that row. Keep the name column short and the
  detail/value column start-aligned.
- Separate rows with one plain or dotted hairline: either a `wp:separator`
  BETWEEN rows or a bottom border on each row except the last. This assigned
  recipe is an explicit exception to the general separator ration.
- Use compact internal spacing and no card background, card shadow, or card
  padding. Whitespace plus the shared rule is the structure.
- Preserve the assigned section archetype around the ledger; do not turn the
  rows into an equal-card grid.
