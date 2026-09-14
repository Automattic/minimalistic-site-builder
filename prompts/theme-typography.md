Choose the remaining typography values for this WordPress theme.

DESIGN CONTRACT:
{{design_contract}}

HERO TYPE CONTEXT:
{{hero_sizing_context}}

Return a JSON object with only `styles`.
Set `styles.typography.lineHeight` to `1.5`, `1.6`, or `1.7`.
Set `styles.elements.heading.typography.lineHeight` to `1.05`, `1.1`, `1.15`, or `1.2`.
Set `styles.elements.button.typography.fontWeight` to `400`, `500`, `600`, or `700`.
Set `styles.blocks.core/navigation.typography.fontWeight` to `400`, `500`, `600`, or `700`.
Set `styles.elements.heading.typography.fontWeight` to one of the committed heading weights: {{heading_weights}}.
Set `styles.elements.button.typography.textTransform` to `none`, `uppercase`, or `lowercase`.
Set `styles.elements.button.typography.letterSpacing` to `0`, `0.02em`, `0.05em`, or `0.1em`. Use tracking above `0.02em` only with `uppercase`.
Set `styles.spacing.blockGap` to one of `var:preset|spacing|xs`, `var:preset|spacing|sm`, `var:preset|spacing|md`, `var:preset|spacing|lg`, `var:preset|spacing|xl`, or `var:preset|spacing|xxl`.
Follow the design contract `density` for the block gap: `packed` and `dense` use `xs` or `sm`, `measured` uses `md`, `airy` and `expansive` use `lg` or `xl`.

Use a compact heading line height for display text. Give body text enough space for long paragraphs.
Use the available font weights from the design contract.
The compiler supplies all six palette colors, font families, font sizes, spacing, widths, and global styles.
The compiler owns `textTransform` and `letterSpacing` for headings, and preserves every line-height choice.
It also supplies CTA construction, shape, depth, image treatment, and contrast repair.
Omit settings, CSS, block decoration, and all other style properties.
