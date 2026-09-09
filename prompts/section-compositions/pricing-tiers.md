### pricing-tiers

Build one row of two or three plan cards with the same structure.
Highlight exactly one recommended plan.

- Structure: Create one `wp:columns` row with `"className":"equal-cards"` and `"align":"wide"`.
- Use two columns at 50% each, or three columns at 33.33% each.
- Set each column to `"verticalAlignment":"stretch"`.
- Put one card group in each column, per the ASSIGNED CARD STYLE.
- Copy budget: Start each card with a level-3 heading that names the plan.
- Put the stated price and period in a paragraph with `"className":"price-figure"`.
- Let the theme set the price's font size and font family.
- If the brief supplies no price, use a plain scope paragraph without `price-figure`.
- Put one `wp:list` of three to five short features after that paragraph.
- Put one `wp:buttons` with `"className":"cta-bottom"` and one button after the list.
- Use the planned `primary_action` for the recommended plan's button.
- Use that destination with a suitable label for each other plan.
- Add `card-highlight` to the recommended card's classes.
- Use `"backgroundColor":"contrast"` and `"textColor":"base"` on that card.
- On a contrast band, reverse those two presets for the recommended card.
- Highlight the middle of three plans, or the higher of two, unless the notes specify another plan.
- Put one section heading and at most one lead line before the row.
- Put at most one shared note after the row.
- Identity: Use the assigned root marker on the top-level group.

- Media: Use no images. Use only prices from the brief or SITE SPEC.
Keep extra plan information in the shared note instead of a fourth card.

- Surface/width: The band runs wide.
- Objective failure: Multiple rows, unequal card structures, or missing plan actions break this composition.
