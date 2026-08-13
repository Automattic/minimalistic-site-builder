# Fidelity step: warn when we break a promise

Nothing walks `designDirection.json` against what shipped. Builds
go green with Caveat in the prose and Work Sans on the page.
`warnings.json` is the queue for the future repair pass
(BIGR-722). Direction loss is not in that queue.

## Change

New step after assemble (and after convert, on HTML-first). Walk
the direction field by field. Compare to `theme.json`, home
markup, and `functions.php` enqueues.

For every broken promise write: `field`, authored value,
delivered value (or `removed`), file/block. Declare
`warnings.json` in `writes`. Never abort the build. Isolated
loss, then continue.

This step is the home for the [04](04-audit-tokens.md) checks and
the reporter for [01](01-third-font.md), [02](02-surface.md),
[03](03-bind-hexes-and-fonts.md), [05](05-motion-note.md), and
[07](07-signature-device.md).

## Out of scope

A vision pass on the screenshot. Regenerating sections from the
warning rows. That is a later loop, not this step.
