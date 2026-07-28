BLOCK-MARKUP RESPONSE CONTRACT

Return exactly the intended Gutenberg block document and nothing else.

- The first non-whitespace bytes of the response MUST be `<!-- wp:`.
- The response MUST end immediately after the final top-level block delimiter.
- Preambles, reasoning, acknowledgements, Markdown code fences, trailing notes, alternative drafts, and illustrative block examples outside the intended document are all forbidden.

Valid response example (the entire response):
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>Example.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

Invalid response examples (these wrappers are never valid):
- `Here is the markup:` before the first block.
- Fenced HTML beginning with "```html" and ending with "```".
- `Hope this helps` after the final block delimiter.
