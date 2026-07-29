You wrote one part of a WordPress block theme as Gutenberg block markup, but the response could not be used by the build: {{error}}.

YOUR PREVIOUS RESPONSE:
--------------------------------------------------------------------------------
{{raw}}
--------------------------------------------------------------------------------

Return the SAME part, corrected so it parses as ONE standalone block document:

- Keep the authored content and design exactly as written wherever it is already valid block markup — repair ONLY what makes the document unusable (unclosed or mismatched block comments, prose or markdown mixed between blocks, a second document, stray text outside the top-level blocks).
- The whole part must be wrapped in a single top-level `<!-- wp:group -->` that declares `"layout":{"type":"constrained"}`; add that wrapper if the original lacked it, preserving any `anchor`/`id` the original carried.
- Every `<!-- wp:... -->` opener must have its matching closer, and nothing but block markup may appear in the response.

{{block_markup_output_contract}}
