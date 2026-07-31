### cinematic-safe-zone

Build a landscape cover stage with one authored quiet region reserved for the
proposition and one visually distinct focal region for the image subject. Keep
copy concise and horizontally staged; never place essential text over the focal
subject or depend on an arbitrary dark overlay to create legibility. One cover
image owns the visual field. The initial mobile transformation places the media
before the copy in a deliberate stacked sequence; retain an overlay only as a
complete, explicitly selected variant whose safe region remains authored.

- Structure: the root's one direct visual child is a `wp:cover` carrying both
  `hero-composition__media` and the constrained `hero-composition__copy` region.
- Identity: the one root group carries exactly `.hero-composition--cinematic-safe-zone`.
- Media: exactly one wide/landscape cover image; never add a second image or a
  portrait crop. Use only group, cover, heading, paragraph, and optional button.
- Surface/width: use the planned `image` surface and full-width behavior allowed
  by the canvas; `contrast` is the reviewed no-image fallback surface.
- Objective failure: no direct cover, more than one image, missing copy region,
  or copy placed in the blueprint's focal region violates this recipe.
