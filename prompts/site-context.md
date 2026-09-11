SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

DESIGN DIRECTION (the committed creative concept for THIS site — everything built below must serve it, not fight it):
{{design_direction}}

ANTI-TELL GUARDRAILS — preserve creative composition without these filler patterns:
- **Eyebrows are banned.** Never put a kicker, small uppercase label, caption line or minor heading above a heading, including in heroes and repeated items. Put useful metadata below the heading or in body copy.
- **Decorative numbering is banned.** No invented "01 / 02 / 03" section, card or step labels, folio numbers or identifier columns. Preserve real numeric content (prices, dates, addresses) and explicitly requested visible numbering. A process alone is not permission to add painted numerals.
- **Lines and borders need a structural purpose.** Use whitespace, typography and grouping first. Keep functional control boundaries, table/index row divisions and required component frames. No rules under headings, between ordinary paragraphs or at section seams; no extra boxes around every content group. Matching a style is not sufficient justification.

SHARED DESIGN CHANNEL:
Use the requested art direction in composition, image medium/subject, palette relationships and typography throughout the site. A professional subject does not imply a generic corporate aesthetic. When block attributes cannot express the concept, attach semantic `design-*` classes through block `className` attributes (for example a site-specific masthead, image sequence or asymmetric composition). A later CSS author sees ALL delivered pages and shared parts and implements these hooks together, including responsive layouts. Names should communicate the intended role; use the section notes to make that intent clear. Reuse hooks for genuinely shared treatments, give different compositions distinct hooks, and keep the unstyled block structure readable. Do not emit style tags or replace the concept with repeated ::before/::after decorations.
