### equal-card-grid

Build one row of equal-weight cards for a flat hierarchy — pricing tiers, a
trio of services, a set of equally weighted features. Every card carries the
same construction and the same crop, so the row reads as one system.

- Structure: use `wp:columns` with
  `"className":"equal-cards"`, each `wp:column` at
  `"verticalAlignment":"stretch"` and `"width":"X%"` where X is 100 divided by
  the card count. The widths sum to exactly 100%. Build every card group with
  the ASSIGNED CARD STYLE, and put a card's bottom-aligned action, when it has
  one, in a `wp:paragraph` with `"className":"text-action cta-bottom"` holding
  one link — never a button; buttons are the page's planned actions.
- Copy budget: one heading and at most one short paragraph for the band.
  Use that paragraph as the lead; do not add a second paragraph below it. Each card holds a
  heading, one short paragraph or a short list, and at most one text-link
  action.
- Identity: the one top-level group carries the assigned root marker class.
- Media: at most one image per card, on `"className":"card-media"` so every
  card crops to the same ratio. Use group, columns/column, image, heading,
  paragraph, list, and buttons.
- Surface/width: the band runs wide or full, and the `wp:columns` row takes
  `"align":"wide"` itself.
{{highlight_directive}}
- Objective failure: unequal card widths, widths that do not sum to 100%, one
  card built differently from its siblings, or mixed image crops in one row.

`equal-grid` construction — uniform card row, for flat hierarchies (pricing tiers, a trio of equally weighted features):
   - `wp:columns` with `"className":"equal-cards"`.
   - Each `wp:column` with `"verticalAlignment":"stretch"` and `"width":"X%"` where X = 100 / number_of_cards (2 cards → 50%, 3 → 33.33%, 4 → 25%). All widths MUST sum to exactly 100%.
   - Inside each column a single `wp:group` card wrapper holding the content (heading, paragraph, image, list), built per the card anatomy above.
   - Any card image: add `"className":"card-media"` to the wp:image and copy that hook alone to its wrapper (`<figure class="card-media">`) — the build crops it to the ordinary-card ratio committed by the **Image crop** fact. NEVER write the cropping as an inline style or `aspectRatio` block attribute.
   - For a bottom-aligned action, add a `wp:paragraph` with `"className":"text-action cta-bottom"` holding one link — never a `wp:buttons` (see "Buttons are budgeted" above).
     (The supporting `.equal-cards` / `.card-body` / `.cta-bottom` / `.text-action` / `.card-media*` / `.card-flush` CSS already ships in the theme's style.css — just use these class hooks.)
