- `reveal` — on a group/image/heading: fades in with a small rise when scrolled into view. The default entrance.
- `reveal-up` — like `reveal` but with a longer rise, for a band that should arrive with presence.
- `reveal-fade` — pure fade, no movement; for quiet, editorial content.
- `reveal-scale` — fades in while settling down from a slight zoom; suits imagery and framed cards.
- `reveal-blur` — fades in while it sharpens from a soft blur, with no travel; for a display heading or imagery that should arrive with quiet weight.
- `reveal-wipe` — the content is unmasked top-to-bottom in place, with no travel; a cinematic entrance for full-bleed imagery or a strong heading.
- `reveal-wipe-up` — the content is unmasked bottom-to-top in place, with no travel; an editorial, print-like entrance for framed imagery or a rule-led band.
- `reveal-aperture` — the mask opens from the vertical center to both edges, with no travel; a letterbox entrance for wide imagery and gallery bands.
- `reveal-zoom` — fades in while settling down from a slight enlargement (the inverse of `reveal-scale`); suits photography, covers, and full-bleed media.
- `stagger-children` — ONLY on a container (`wp:columns`, `wp:gallery`, or a card-grid group) whose direct children are cards/columns: the children cascade in one by one. Each child waits for its own viewport entry, so this also works when a row stacks on mobile. Never combine with a `reveal-*` class on the same block.
- `ken-burns` — AMBIENT: on a `wp:cover` or image figure — its image zooms very slowly.
- `gradient-shift` — AMBIENT: on a group whose background is a gradient — the gradient drifts slowly.
- `ambient-drift` — AMBIENT: on ONE small decorative element (never a text band) — a slow vertical float.

Profile choreography (follow the DESIGN DIRECTION motion note when more specific):
{{profile_choreography}}
All kit movement is vertical: an entrance rises, unmasks, sharpens, or settles in place — nothing moves sideways. Vary the entrances down the page: neighboring sections should not repeat the same `reveal-*` class when a different one fits their content.
Match the entrance to the site's visual world, not just to the profile: a print/editorial direction suits `reveal-wipe`/`reveal-wipe-up` and `reveal-fade`; a photographic or gallery direction suits `reveal-zoom`, `reveal-blur`, and `reveal-aperture`; a quiet product or text-led direction suits `reveal`/`reveal-fade`; a lively brand suits `reveal-up` with `stagger-children`. When the DESIGN DIRECTION's motion note names classes, treat that named set as the site's motion palette and choose from it first.
The budget is a ceiling, not a quota; zero motion classes is valid when the composition already has enough presence.

Motion budget (hard rules — a deterministic build step strips violations, so overspending just wastes your choices):
- At most ONE motion class per block (`hover-lift`/`hover-reveal` don't count toward this limit).
- Even though hover is a separate budget, never combine `ambient-drift` + `hover-lift` on one block or `ken-burns` + `hover-reveal` on one block: each pair fights over the same transform. Put hover on a nested card/image wrapper instead.
- Put a `reveal*` class on the actual content block that should enter, NEVER on an empty-padded outer section shell: the viewport trigger follows the animated block's outer edge.
- Motion is seasoning, not sauce: at most one or two entrances per section — the section's key content group, or its one card grid via `stagger-children`. A deterministic pass keeps only the first two, so NEVER put the same reveal on every repeated row; animate their shared container once or leave most rows still. Let text-heavy sections stay still.
- The three AMBIENT classes are signature effects: at most ONE ambient effect on the WHOLE page, and only if this section is the page's focal moment. Look at the COMPOSITION block's neighbors — mid-page support sections get no ambient motion.
- NEVER write `is-visible` or any `motion-*` runtime state class (the theme's script owns them), and NEVER invent motion class names beyond this list.
