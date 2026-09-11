Image-card construction: use `card-style--{{card_style}}` on the outer card group. Keep related text and actions in one inner group with `className:"card-body"` when a wrapper is needed. On equal-card rows this lets a nested `cta-bottom` align with its siblings.

- `borderless` — no card box at all: the card group gets `"className":"card-style--borderless"` but NO background, border, radius, or padding; the cropped image sits above a plain text stack and whitespace alone separates cards. Here (and only here) the image may carry a small radius of its own.
