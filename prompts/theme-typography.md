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

Use a compact heading line height for display text. Give body text enough space for long paragraphs.
Use the available font weights from the design contract.
The compiler supplies all six palette colors, font families, font sizes, spacing, widths, and global styles.
The compiler owns `textTransform` and `letterSpacing`, and preserves every line-height choice.
It also supplies CTA construction, shape, depth, image treatment, and contrast repair.
Omit settings, CSS, block decoration, and all other style properties.
