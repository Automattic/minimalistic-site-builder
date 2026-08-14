# Description only narrates committed fields

`design-direction.md` still asks for a vivid paragraph that
names extra fonts, textures, devices, and hexes. Those words
become fake promises. `normalize()` already falls an invalid
`card_style` back to `flush`. The description has no such gate.

Do this last. If it lands before 01–07, we only teach the model
to write a thinner novel.

## Change

- Description may only narrate committed fields.
- No extra font names, no textures, no devices, no hexes that
  are not in `palette`.
- `normalize()` strips or warns leftover promises, same as a
  bad `card_style`.

## Out of scope

Deleting the description. Using it as a third type slot or a
surface field. Those are real fields now, or they are not
promises.
