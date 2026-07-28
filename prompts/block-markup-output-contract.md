BLOCK-MARKUP RESPONSE CONTRACT

Return exactly the intended Gutenberg block document and nothing else.

- The first non-whitespace bytes of the response MUST be `<!-- wp:`.
- The response MUST end immediately after the final top-level block delimiter.
- Preambles, reasoning, acknowledgements, Markdown code fences, trailing notes, alternative drafts, and illustrative block examples outside the intended document are all forbidden.

## Block attributes: prefer TOON

When a block opener needs attributes, put them as **TOON** (Token-Oriented Object Notation) on lines inside the HTML comment — **not** as a one-line JSON object. A build step converts TOON attrs to standard WordPress JSON before validation.

Rules for TOON attrs:
- After `<!-- wp:blockName`, start a new line; write `key: value` pairs at indent 0 relative to the comment body (two spaces per nested level).
- Nested objects: `key:` alone on a line, then indented children.
- Quote string values that contain `:` (e.g. `var:preset|…`) or special keys (e.g. `":hover":`).
- Do **not** wrap attrs in `{` `}` JSON braces.
- Closers stay `<!-- /wp:blockName -->` with no attrs.
- Attribute **names** and preset **slugs** are unchanged from the JSON form (align, textColor, backgroundColor, layout.type, style.spacing, etc.).

Valid response example (the entire response):
<!-- wp:group
layout:
  type: constrained
-->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>Example.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

Invalid response examples (these wrappers are never valid):
- `Here is the markup:` before the first block.
- Fenced HTML beginning with "```html" and ending with "```".
- `Hope this helps` after the final block delimiter.
- JSON attrs on the opener (`<!-- wp:group {"layout":{…}} -->`) — prefer TOON as above (JSON still works if already valid, but do not emit it).
