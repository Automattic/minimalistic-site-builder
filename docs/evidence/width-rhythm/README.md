# Section container width & page rhythm

Evidence for the width/rhythm normalization PR: the six demo sites showed
recurring container-width defects — sections too narrow, too wide, or mixing
competing widths on one surface. All of them trace to four markup patterns the
model drifts into, now repaired deterministically by `src/LayoutFixer.php`
(running inside the `fix-blocks` step) and discouraged at the source by
hardened prompts.

## The four failure patterns

1. **Top-level group missing its `layout` attribute** — an `align:full` band
   with no `"layout"` gets flow layout: no centering, no page gutter, children
   render edge-to-edge (tbilisi "The Cuisine").
2. **Align classes without the align attribute** — `"className":"alignwide"`
   styles nothing; WordPress computes widths from the `align` attribute
   (portfolio footer).
3. **Grid rows capped at the reading measure inside a wide band** — non-aligned
   `wp:media-text` / multi-column `wp:columns` / `wp:gallery` rows silently cap
   at contentSize and float narrow in a 1320px band (portfolio "A Decade of
   Turning Points"); the same content boxed in a hard `contentSize` wrapper is
   the second shape of it. Related: a cover's inner group squeezed far below
   the theme measure (portfolio2 hero at 640px of an 88vh cover).
4. **Footer rows at mixed widths** — site-title lockup at 860px beside 1320px
   link columns puts two competing left edges on one surface (portfolio,
   naturaleza); 3+ column link rows left at content width wrap email addresses
   mid-word (portfolio2, tbilisi2).

## Before / after (1440px viewport, crops from full-page captures)

| Issue | Before | After |
|---|---|---|
| portfolio "A Decade of Turning Points" narrow timeline | [portfolio-decade-before](portfolio-decade-before.png) | [portfolio-decade-after](portfolio-decade-after.png) |
| tbilisi "The Cuisine" edge-to-edge, no page margins | [tbilisi-cuisine-before](tbilisi-cuisine-before.png) | [tbilisi-cuisine-after](tbilisi-cuisine-after.png) |
| portfolio footer mixed widths (title lockup vs columns) | [portfolio-footer-before](portfolio-footer-before.png) | [portfolio-footer-after](portfolio-footer-after.png) |
| tbilisi2 footer columns squeezed, email wraps mid-word | [tbilisi2-footer-before](tbilisi2-footer-before.png) | [tbilisi2-footer-after](tbilisi2-footer-after.png) |

Also fixed, verified the same way (not pictured): naturaleza footer title
lockup misaligned with its wide rows; portfolio2 footer columns squeezed at
860px; portfolio2 hero headline measure 640px → 860px (DOM-verified — the
text happens to wrap at the same word at this viewport, so the crop is not
illustrative); naturaleza hero cover cap 560px → theme measure.

## How the demos were re-verified

```bash
# apply the new pass to the existing projects (normally runs inside fix-blocks)
php -r '... Steps\FixBlocksStep::normalizeLayouts($project) ...'
node bin/block-fixer/fix-templates.js projects/*/theme

# capture
node bin/screenshot/screenshot.js http://127.0.0.1:<port>/ out.png --width=1440
```

After the pass, `ThemeValidator::layoutWarnings()` (the same rules as a
dry-run linter, now part of `bin/eval.php` reporting) is clean on all six
projects, and the full unit suite passes (276 tests, 14 new).
