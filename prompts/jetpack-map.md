## Maps

This build runs on a host that can turn an address into a real interactive
map. You do NOT write the map markup yourself: you reserve its place with a
placeholder block, and a later host step replaces that block with a working
map.

Emit a map placeholder ONLY when SITE SPEC states a real address, and only in
a section whose purpose is to say where the place is — how to visit, how to
find it, where it operates. Never invent an address, never assemble one out of
a city name the spec merely mentions in passing, and never decorate an ordinary
content section with a map. Where the spec states no address, write the section
without a map rather than mapping a place that does not exist. Never emit more
than one placeholder in a section.

The placeholder is a single paragraph block carrying the `jetpack-map-placeholder`
class, whose only text is the map spec:

```html
<!-- wp:paragraph {"className":"jetpack-map-placeholder"} -->
<p class="jetpack-map-placeholder">JP_MAP: 14 Rue de Rivoli, 75004 Paris, France | Atelier Rivoli</p>
<!-- /wp:paragraph -->
```

### Spec Format

```
JP_MAP: address | marker-title
```

- `JP_MAP:` — Required prefix marker (exactly as written)
- `|` — Pipe character used as the separator between the two values
- `address` — The address as SITE SPEC states it, copied rather than rewritten.
  The host geocodes this string, so keep whatever the spec gives — street,
  city, postcode, country — and add nothing it does not say. An address may
  not contain `|`.
- `marker-title` — What the pin is called: the business or place name, written
  in the site's language. A visitor reads it, so it is a name, not a
  description. It may not contain `|`.

### Rules

- The placeholder paragraph's text is the ENTIRE spec: no heading, no extra
  copy, no markup inside it. Any surrounding heading, address line, or opening
  hours is a normal sibling block, outside the placeholder.
- The placeholder block carries `className` and NOTHING else — no font size, no
  colour, no spacing. It is never styled, because it is not copy: the host
  replaces the whole block before a visitor sees it.
- A map does not replace the written address. When the section shows the
  address as text, keep that text and add the placeholder beside it; a visitor
  who wants to copy an address should not have to read it off a map.
- Keep writing the section's other blocks as usual. The placeholder replaces
  only the map itself.
- Never emit `<iframe>`, an embed block, a static map image, or any
  `wp:jetpack/*` block of your own. The placeholder is the only map output.

### Example Section Fragment

```html
<!-- wp:heading -->
<h2 class="wp-block-heading">Find us</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>14 Rue de Rivoli, 75004 Paris</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"jetpack-map-placeholder"} -->
<p class="jetpack-map-placeholder">JP_MAP: 14 Rue de Rivoli, 75004 Paris, France | Atelier Rivoli</p>
<!-- /wp:paragraph -->
```
