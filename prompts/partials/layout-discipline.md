## Section layout & edge discipline (two-layer pattern)

Every top-level section follows a two-layer pattern so full-bleed backgrounds reach
the viewport edge while readable prose stays at a comfortable line length:

- **Outer (section container):** the `wp:group`/`wp:cover` that owns the section's background, padding, and alignment. It MUST declare an `align` — `"align":"full"` for sections that bleed to the viewport edges (hero covers, photographic bands, footer bands, full-bleed CTA strips) or `"align":"wide"` for sections at the theme's wide width (feature grids, most sections). It MUST use `"layout":{"type":"default"}` or omit `layout` — do NOT put `"layout":{"type":"constrained"}` on a section container (constrained clamps it to content width and breaks edge-to-edge backgrounds). A top-level section with no `align` inherits the body's root padding and renders as a sad narrow column — that's the symptom of forgetting this.
- **Inner (content holder):** the `wp:group` nested inside that holds the readable content. `"layout":{"type":"constrained"}` is right here — it keeps copy at content width inside a full/wide section background. Use it for hero copy, CTA text+button stacks, and prose-heavy bands.

Do not add horizontal `padding.left`/`padding.right` to section containers or the page
root — the site-wide gutter already lives in theme.json's root padding, and sections
break out of it via `align:wide` / `align:full`, never via wrapper padding.
