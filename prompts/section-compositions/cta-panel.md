### cta-panel

Build the final invitation as one contained panel on the page ground.
The band uses its planned base or tinted surface. The panel carries the contrast.

- Structure: Put exactly one inner `wp:group` in the top-level group.
- Set its `"className":"cta-panel"`, `"align":"wide"`, and `"layout":{"type":"constrained"}`.
- Set its `"backgroundColor":"contrast"` and `"textColor":"base"`.
- Use the theme's gradient preset only if the design direction specifies that gradient.
- Set the panel's top and bottom padding to `var:preset|spacing|xl`.
- Set the panel's left and right padding to `var:preset|spacing|lg`.
- Let the theme set the panel radius and clip its contents.
- Copy budget: Put one heading, one lead line, and exactly one `wp:button` in the panel.
- Use the planned `primary_action` for the button's label and destination.
- Center the short text and action if the panel has no image.
- Media: If the plan supplies an image, use a 60/40 columns row with text first and the image second.
- Mark that image with `"className":"card-media"`.
- Identity: Use the assigned root marker on the top-level group.

Keep all section content inside the panel.
Keep the top-level padding under the builder's density rules.

- Surface/width: The panel and its band run wide.
- Objective failure: Multiple panels, extra text, or more than one button breaks this composition.
