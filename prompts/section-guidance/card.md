Card anatomy — apply only to an image card: an outer repeated-content group pairing one primary image with its related text and actions. The ASSIGNED CARD STYLE overrides absent or conflicting prose in the DESIGN DIRECTION. Every outer image-card group carries exactly one `card-style--{{card_style}}` marker. When a card uses an inner group for its text and actions, put ALL of that content in ONE such wrapper and give it `"className":"card-body"` regardless of treatment. This wrapper is required for flush/overlap and optional for framed/borderless; in an equal grid it grows so a nested `cta-bottom` aligns with sibling cards.

{{card_recipe}}

Card images use `"className":"card-media"` on the wp:image and copy that hook to its wrapper. A dominant card in an asymmetric split may use `card-media-tall`. These hooks execute the committed Image crop; NEVER write cropping as an inline style or `aspectRatio` block attribute.
