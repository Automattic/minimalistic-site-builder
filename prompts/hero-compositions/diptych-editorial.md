### diptych-editorial

Compose two related but distinct foreground images in unequal frames, balanced
by one restrained copy anchor. Each frame must request a different filename and
subject; never duplicate one asset. The copy is compact enough that the paired
media remains the dominant gesture. Mobile becomes one clear authored sequence
that preserves both distinct frames; a separately selected single-focus variant
may omit only the secondary frame without duplicating the first. Never squeeze
two tiny side-by-side images or turn the pair into decorative thumbnails.

- Structure: one media region contains exactly two unequal image frames; one
  separate restrained region carries `hero-composition__copy`. Mark each media
  frame with `hero-composition__media` and the secondary one additionally with
  `hero-composition__media-secondary`.
- Identity: the one root group carries exactly `.hero-composition--diptych-editorial`.
- Media: exactly two foreground `wp:image` blocks with distinct AI_IMAGE
  filenames, subjects, and intentional aspect roles. Use group, columns/column,
  image, heading, paragraph, and optional planned button only.
- Surface/width: keep a solid base/tinted/contrast root and the
  mixed-width-editorial projection; images never become root backgrounds.
- Objective failure: fewer/more than two authored frames, duplicate assets,
  equal thumbnail treatment, or copy repeated between frames fails this recipe.
