# Direction fidelity

The generator already writes a rich `designDirection.json`. The page that
ships is thinner than that contract. Palette, type, image grade, card
style, shape, canvas, and motion are structured fields. Texture, a third
typeface, signature devices, and most of `motion_note` live only in the
description and never become tokens, CSS, or markup.

This folder is the work list for **say it → ship it**. It is not a
variety track and not another pile of prompt bans. A promise that no
step can execute should stop being asked for.

Suggested PR order: [01](01-third-font.md) → [02](02-surface.md) →
[08](08-fidelity-step.md), then [03](03-bind-hexes-and-fonts.md)–
[05](05-motion-note.md), then [07](07-signature-device.md), then
[09](09-convert-html.md) on the HTML-first branch, then
[10](10-description-fields.md) so new directions cannot invent a fourth
thing we still cannot build.

**On `feat/direction-fidelity`:** items 01–08 and 10 are wired in the
default pipeline (optional `type.accent`, `surface` + `device` kits,
theme.json hex/family bind, motion-note mapping, image-grade fight
strip, `direction-fidelity` step). Item 09 stays on the HTML-first
branch.

| # | Promise | File |
|---|---------|------|
| 1 | Third font: load the Caveat we promised | [01-third-font.md](01-third-font.md) |
| 2 | Surface: paper/concrete as CSS, not prose | [02-surface.md](02-surface.md) |
| 3 | Bind hexes and fonts when theme.json drifts | [03-bind-hexes-and-fonts.md](03-bind-hexes-and-fonts.md) |
| 4 | Audit card, shape, canvas, motion on the page | [04-audit-tokens.md](04-audit-tokens.md) |
| 5 | motion_note: map it to classes or drop it | [05-motion-note.md](05-motion-note.md) |
| 6 | image_grade on every image, rewrite fights | [06-image-grade.md](06-image-grade.md) |
| 7 | One CSS device. Strip unbuildable motifs | [07-signature-device.md](07-signature-device.md) |
| 8 | Fidelity step: warn when we break a promise | [08-fidelity-step.md](08-fidelity-step.md) |
| 9 | Convert can't eat the HTML we just honored | [09-convert-html.md](09-convert-html.md) |
| 10 | Description only narrates committed fields | [10-description-fields.md](10-description-fields.md) |

## Related

[taste-skill.md](taste-skill.md) — what to take from
[Leonxlnx/taste-skill](https://github.com/Leonxlnx/taste-skill)
(MIT). Take: Fraunces-as-default and cream/brass palette
rotation ([01](01-third-font.md), [03](03-bind-hexes-and-fonts.md)),
grain as a fixed overlay ([02](02-surface.md)), "motion claimed =
motion shown" ([05](05-motion-note.md)), mechanical pre-flight
([04](04-audit-tokens.md), [08](08-fidelity-step.md)), catalog
`not_for` / anti-patterns. Do not take the React/GSAP stack or
their anti-center hero rule.

### External

- <https://github.com/Leonxlnx/taste-skill>
- [v2 SKILL.md](https://github.com/Leonxlnx/taste-skill/blob/main/skills/taste-skill/SKILL.md)
- [CHANGELOG](https://github.com/Leonxlnx/taste-skill/blob/main/CHANGELOG.md)
- [imagegen-frontend-web](https://github.com/Leonxlnx/taste-skill/blob/main/skills/imagegen-frontend-web/SKILL.md)
- [examples](https://github.com/Leonxlnx/taste-skill/tree/main/examples)
- <https://tasteskill.dev>
