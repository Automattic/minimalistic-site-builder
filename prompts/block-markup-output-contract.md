BLOCK-MARKUP RESPONSE CONTRACT

Return exactly the intended Gutenberg block document and nothing else.

- The first non-whitespace bytes of the response MUST be `<!-- wp:`.
- The response MUST end immediately after the final top-level block delimiter.
- Preambles, reasoning, acknowledgements, Markdown code fences, trailing notes, alternative drafts, and illustrative block examples outside the intended document are all forbidden.

## Block attributes: TOON only (mandatory)

When a block opener needs attributes, you MUST write them as **TOON** (Token-Oriented Object Notation) inside the HTML comment. **JSON attributes on openers are forbidden** and fail the build.

A deterministic PHP step converts TOON attrs to WordPress JSON before validation. You never write that JSON yourself.

Rules for TOON attrs:
- After `<!-- wp:blockName`, start a new line; write `key: value` pairs (two spaces per nested level).
- Nested objects: `key:` alone on a line, then indented children.
- Quote string values that contain `:` (e.g. `var:preset|…`) or special keys (e.g. `":hover":`).
- Do **not** wrap attrs in `{` `}` or emit `<!-- wp:name {"key":"value"} -->`.
- Closers stay `<!-- /wp:blockName -->` with no attrs.
- Openers with no attributes stay `<!-- wp:blockName -->` (or `<!-- wp:blockName /-->` for void blocks).
- Attribute **names** and preset **slugs** are the same as in theme docs (align, textColor, backgroundColor, layout.type, style.spacing, etc.).

Valid response example (the entire response):
<!-- wp:group
layout:
  type: constrained
-->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>Example.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

Invalid response examples (these fail the build):
- `Here is the markup:` before the first block.
- Fenced HTML beginning with "```html" and ending with "```".
- `Hope this helps` after the final block delimiter.
- JSON attrs on any opener, e.g. `<!-- wp:group {"layout":{"type":"constrained"}} -->` — **always use TOON instead**.
