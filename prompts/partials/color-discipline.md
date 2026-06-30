## Color pairing discipline (read before declaring any block colors)

Inheritance in WordPress block themes is unreliable. A child block whose text color
falls through to the body default renders invisible against any parent surface that
isn't the body background. Defend against it at the block level:

- **When a block declares `backgroundColor` (or `style.color.background`), it MUST also declare `textColor` (or `style.color.text`).** No exceptions — pair them at every level. A tinted `wp:group` that doesn't set its own text color passes the burden to children and the chain breaks the moment one child also skips it.
- **When a block declares a border width, it MUST also declare `borderColor`** as a palette slug — never let borders inherit `currentColor`.
- **`wp:button` MUST declare BOTH text and background colors** at the block level. `is-style-outline` buttons MUST declare `textColor` AND `borderColor` together (transparent background relies entirely on text + border for visibility). Reserve the `accent` color for buttons / CTAs only.
- **`wp:navigation` MUST declare `textColor`, `overlayBackgroundColor`, and `overlayTextColor`** (and a `blockGap`) so desktop links, the mobile overlay surface, and its text are all visible.

## Spacing discipline

- **Any block with `layout.type:"grid"` or `layout.type:"flex"` MUST declare a `blockGap`** (`style.spacing.blockGap`). This includes `wp:columns`, `wp:buttons`, `wp:navigation`, and any custom flex/grid `wp:group`. WordPress flex/grid layouts have NO gap by default — children render edge-to-edge without it. The structural test: if you wrote `layout.type:"grid"` or `"flex"`, the next attribute should be `blockGap`.

## Design token discipline (no hardcoding)

- **Reference theme.json tokens by slug.** Colors, font sizes, font families, and spacing in block markup use the declared tokens — `{"textColor":"primary"}`, `{"fontFamily":"heading"}`, `{"style":{"spacing":{"padding":{"top":"var:preset|spacing|40"}}}}`. **Do NOT introduce hardcoded hex colors, px values, or font-family names** in block attributes. The color slugs are: `base`, `contrast`, `primary`, `secondary`, `accent`. The font slugs are: `heading`, `body`.
