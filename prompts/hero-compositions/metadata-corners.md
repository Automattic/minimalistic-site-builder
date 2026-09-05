### metadata-corners

Build the opener as one full-bleed portrait cover with the copy pinned to the
lower leading corner and two or three small facts about the studio riding the
top corners, the way an editorial spread labels its margins. The picture owns
the field; the headline is a stack of short uppercase-ready lines; the facts
are scenery the build lifts into the corners, never a second headline and
never an eyebrow.

- Structure: the root's one direct visual child is a `wp:cover` at
  `"align":"full"` carrying `hero-composition__media` (its one background
  image, the `AI_IMAGE` spec in the inner img's alt) and, in this order inside
  the cover's inner container: ONE `wp:group` with
  `"className":"hero-composition__copy"` and `"layout":{"type":"constrained","justifyContent":"left"}`
  holding the level-1 heading, at most ONE supporting paragraph and at most
  one planned button; then, LAST, ONE `wp:group` with
  `"className":"hero-composition__meta"` and
  `"layout":{"type":"flex","justifyContent":"space-between"}` holding two or
  three `wp:paragraph`, each one fact of one to five words ("New York",
  "Est. 2014", "Identity · Editorial · Digital"). Nothing else in the cover.
- The headline: two or three short lines that read as one stacked block; break
  them with `<br>` inside the one `<h1>`. The DESIGN DIRECTION's type
  treatment sets the case; author no uppercase of your own.
- The meta paragraphs carry `"fontSize":"caption"` and nothing else: no color,
  no alignment, no spacing. The build pins them to the top corners and keeps
  them out of the copy; never place a fact above or inside the copy group.
- Copy budget: one level-1 heading, at most ONE supporting paragraph, at most
  one planned button. No caption or credit line, no rules.
- Identity: the one root group carries exactly `.hero-composition--metadata-corners`.
- Media: exactly one landscape cover image with its subject on the trailing
  side, so the leading corner stays quiet for the copy; never a second image.
  Set an explicit `textColor` that reads over the image and the planned
  protection.
- Blocks: use only group, cover, heading, paragraph, and an optional planned
  button.
- Surface/width: the planned `image` surface with the root and cover at
  `"align":"full"` on every canvas; `contrast` is the reviewed no-image
  fallback surface.
- Mobile: the same cover, the facts in one row above the copy, the copy at the
  bottom of a shorter stage.
- Objective failure: no direct cover, more than one image, no
  `hero-composition__meta` group, fewer than two or more than three facts, a
  fact inside or before the copy group, or copy that is not start-aligned.
