# motion_note: map it to classes or drop it

`motion` is a bounded profile (`calm`, `energetic`, `dramatic`,
`minimal`, `none`) and `FinalizeThemeStep` enqueues that kit.
`motion_note` is a free line ("labels press on with overshoot").
No step reads it. The profile ships. The note is fake art
direction.

## Change

Pick one:

- Map the note onto existing motion classes on specific blocks
  (hero image, card grid) using the already-shipped class names.
- Or delete the field.

A note that cannot be expressed in the kit should be stripped at
`DesignDirectionStep::normalize()`, with a warning. Do not leave
it in the JSON.

## Out of scope

New keyframes. Letting page-styles write `--motion-*` (already
forbidden). CustomMotionStep, which is a different, user-asked
escape hatch.
