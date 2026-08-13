# Audit card, shape, canvas, motion on the page

`card_style`, `shape`, `canvas`, and `motion` already have
code-owned kits (`CardStyleContract`, the shape sheet, framed
mat, the motion profile). They still get promised and then
half-used. The page has no check that the assigned value is
visible.

## Change

After assemble (and after convert on HTML-first), walk the home
markup:

| Field | Fail if |
|---|---|
| `card_style` | image cards exist and none carry `card-style--{assigned}` |
| `shape` | contained media or buttons ignore the shape kit |
| `canvas` | `framed` but bands below the hero go `align:full` (or the reverse) |
| `motion` | profile is not `none`/`minimal` but home has zero motion classes, or profile is `none` and classes remain |

Repair what is deterministic (stamp the card class, strip illegal
motion). Warn the rest into `warnings.json`. Same shape as
contrast-fix. The walker lives in
[08](08-fidelity-step.md).

## Out of scope

New card styles. New motion profiles. Prompt bans for unused
kits.
