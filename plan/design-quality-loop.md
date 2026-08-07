# Design-quality loop state

## Current position
- **2026-08-07 (later)**: Reconciled — #230 (BIGR-778) and #227 (BIGR-775) merged to trunk. Fix BIGR-780 ✓ — **PR #233 open** (branch fix/bigr-780-mobile-root-padding). normalizeRootPadding now always synthesizes missing/zero left-right root gutters to spacing|md, always sets useRootPaddingAwareAlignments, call moved after writeTheme's shape repairs; 1751/1751 tests; evidence via pulso→pulso7 replay (theme.json only re-normalized) in gist 5ca9fea5b13f93287bb778bf8fd602c5. Cohort atlas/hearth theme.json were found already hand-touched by earlier evidence work — pulso was the clean before-instance.
- **LOOP PAUSED — stop condition hit again**: 3 design-quality PRs open unreviewed (#228 BIGR-776, #231 BIGR-779, #233 BIGR-780). (#232 is the loop-skill PR itself, not counted.) Resume with /loop /design-quality-loop after reviews land.
- Next fix when resumed: **BIGR-782** (screenshot motion race — fixes the measuring instrument), then BIGR-784 (contrast blind spots). Note: BIGR-780's merge invalidates the cohort's mobile shots for gutter-adjacent findings; prefer replays or targeted rebuilds.
- Full per-site critique reports: scratchpad `reports/<slug>.md`; crops: scratchpad `crops/<slug>/` (note: scratchpad is session-scoped; the cohort projects/ + logs remain the durable evidence source).
- Evidence replay trick (deterministic fixes): copy projects/<slug> → <slug>NNN, apply the fix output to plugin/pages/home.html, `sed s#themes/<slug>/#themes/<slug>NNN/#`, screenshot — identical geometry to the cohort shot, crops pair 1:1.

## Cohort
- Trunk commit: 888e526, built 2026-08-07, 0 image-generation failures
- Desktop `projects/<slug>/logs/home.png` (1366px), mobile `home-mobile.png` (390px)

## Ranked backlog (triaged 2026-08-07)

| # | Cluster | Incidence | Class | Status |
|---|---------|-----------|-------|--------|
| A | fix-blocks drops authored `has-text-align-*` (not mirrored in comment JSON) → off-axis eyebrows/prices | 5/7 | deterministic | **filed BIGR-779 — NEXT FIX** |
| B | No root padding synthesized when model omits it → zero mobile gutters (normalizeRootPadding early-return) | 3/7 P0 | deterministic | **pr-open #233** (BIGR-780) |
| F | Contrast repair misses captions/kickers/preset-pairs/double-classes/mobile scrim (1.2–2.1:1 shipped) | 4/7 | deterministic | filed BIGR-784 |
| C | Painted-in fake signage/text in images (wrong-brand storefront etc.) — recurrence after BIGR-768 | 6/7, 4×P0 | prompt+pipeline | filed BIGR-781 |
| D | Screenshot races motion reveal (blank images in captures) + motion.js lacks user-facing fallback | 1/7 + instrument | deterministic | filed BIGR-782 |
| E | Same copy line/quote repeats across sections page-wide | 3/7 | pipeline | filed BIGR-783 |
| H | list-thumb 390px thumbs → ~50px slivers | 2/7 | deterministic CSS | add evidence to BIGR-777 (In Progress; PR #229 merged but defect persists on trunk) |
| I | Overlay header delivered stacked, hard seam | 2/7 | — | deduped → BIGR-778 / PR #230 (open) |
| G | Long fully-centered body paragraphs (up to 11 ragged lines) | 3/7 | prompt | new (file later) |
| J | Same photo/scene reused in multiple slots | 2/7 | pipeline | new (file later) |
| K | Em dashes + boilerplate outside hero headlines | 2/7 | prompt | new (file later) |
| M | hearth "Open hours" card ships empty wp:list — promised fact missing | 1/7 P0 | pipeline | new (file later) |
| L | portfolio mobile hero collapses to solid box (stack-media-first × cover) | 1/7 | deterministic CSS | new (file later) |
| N | Misc singles (pulso H1 size + CTA competition; atlas cites; tbilisi dead space; lumen blank column; naturaleza H1 wrap) | 1/7 each | mixed | new (polish tier) |

## Open design-quality PRs (context)
- #230 Header width + transparent overlay (BIGR-778) — open; probably supersedes cluster I
- #228 Textured stage backdrop (BIGR-776) — open
- 2/3 toward the "3+ open unreviewed PRs" stop condition — fixes from this backlog will hit it after ONE more PR unless reviews land.

## Next iteration
Triage: verify A and B root causes in src/ + theme.json step, check `gh pr list` + Linear for dupes (esp. cluster I vs BIGR-778, H vs BIGR-777, C/E vs BIGR-768/773), file Linear issues for validated clusters, finalize ranked backlog here.
