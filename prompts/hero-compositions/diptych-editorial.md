### diptych-editorial

Compose two related but distinct foreground images in unequal frames, balanced
by one restrained copy anchor. Each frame must request a different filename and
subject; never duplicate one asset. The copy is compact enough that the paired
media remains the dominant gesture, and the whole gesture — both frames, the
headline, and any planned action — fits the first desktop viewport: crop the
primary frame to make that true rather than letting a tall portrait plate push
the copy or the secondary frame below the fold. Mobile becomes one clear
authored sequence that preserves both distinct frames; a separately selected
single-focus variant may omit only the secondary frame without duplicating the
first. Never squeeze two tiny side-by-side images or turn the pair into
decorative thumbnails.

- Structure: one media region contains exactly two unequal image frames; one
  separate restrained region carries `hero-composition__copy`. Mark each media
  frame with `hero-composition__media` and the secondary one additionally with
  `hero-composition__media-secondary`. Compose the secondary frame as a
  deliberate counterpoint — clearly smaller and offset beside the copy or
  against an edge of the primary — never as a leftover strip stacked full-width
  directly beneath the primary frame. The primary frame is a FRAME, not a
  band: never open the section with it spanning the full wide measure ahead
  of the copy (that is panorama-rail's topology, and it drags the headline to
  the fold line); keep the primary inside a column or offset grid so copy and
  media share the first viewport side by side.
- Identity: the one root group carries exactly `.hero-composition--diptych-editorial`.
- Media: exactly two foreground `wp:image` blocks with distinct AI_IMAGE
  filenames, subjects, and intentional aspect roles. Use group, columns/column,
  image, heading, paragraph, and optional planned button only.
- Surface/width: keep a solid base/tinted/contrast root and the
  mixed-width-editorial projection; images never become root backgrounds.
- Objective failure: fewer/more than two authored frames, duplicate assets,
  equal thumbnail treatment, copy repeated between frames, a secondary frame
  stacked as a full-width strip under the primary, or a primary frame so tall
  that the copy anchor leaves the first viewport fails this recipe.
