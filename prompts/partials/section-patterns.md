## Section rhythm

Agency-grade homepages routinely run **6–8 structurally varied sections** — treat that
as the target, not a 3-section floor. Vary the *structure* section to section: do not
stack the same centered column grid six times. A strong page has rhythm — full-bleed
bands alternating with constrained prose, a dense grid answered by a quiet single
statement, an asymmetric split followed by a horizontal scroller.

## CSS pattern catalog (reach for ≥2 of these per homepage)

The theme's `style.css` already defines these utility classes and a staggered
page-load reveal. Compose them with core blocks by adding the matching `className`
to a `wp:group` (or the relevant block) — no custom blocks, no inline keyframes
needed. Apply them where the section's content calls for the move; don't sprinkle
every one on every page.

- **Marquee / ticker strip** — `<!-- wp:group {"className":"marquee","align":"full"} -->` wrapping a single heading/paragraph of short text. The text scrolls horizontally forever. Great for a tagline strip, a list of services, or a "now serving / open hours" band between sections.
- **Horizontal scroll row** — `<!-- wp:group {"className":"scroll-row"} -->` wrapping a `wp:columns` of card groups. The row scrolls sideways with scroll-snap. Use for menus, product/portfolio cards, testimonials — anywhere a grid would force everything into one cramped viewport.
- **Sticky rail** — `<!-- wp:group {"className":"sticky-rail"} -->` for a column that stays pinned while its neighbor scrolls (place inside a two-column section). Use for a persistent label, a stepwise narrative, or a tall index beside flowing content.
- **Layered / rotated card stack** — `<!-- wp:group {"className":"stacked-cards"} -->` wrapping `wp:group` cards; alternate children rotate slightly and overlap for a hand-pinned, collaged feel. Use for highlights, reviews, polaroid-style imagery.
- **Asymmetric color blocks** — sequential full-bleed `wp:cover` / `wp:group {"align":"full"}` bands in distinct palette colors (not all on the page background). Let one dominant color carry the page and answer it with sharp accent bands rather than a uniform white run.
- **Sticker / pill overlays** — add `className":"sticker"` to a small `wp:group` and place it over a `wp:cover`/image; it sits as a rotated, accent-colored badge. Use for a price tag, a "since 1998", a one-word call-out.

The page itself fades its top-level sections in on load with a staggered delay (the
`reveal` behavior is wired globally in `style.css`) — you don't need to add per-section
animation markup for that to happen.
