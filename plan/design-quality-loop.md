# Design-quality loop state

## Current position
- **2026-08-07 (iter 4)**: Fix BIGR-784 caption half ✓ — **PR #235 open** (branch fix/bigr-784-contrast-blind-spots). ContrastFix walk now records image/gallery figcaption rows (gallery caption matched only in the tail after its last child) and repairs failures via caption-text-* className hooks; ScaffoldThemeStep ships the matching `figcaption` CSS hooks; 1757/1757 tests. Evidence: lumen step-boundary replay (one real band recolored to contrast in both copies, trunk step vs branch step, fixer re-serialized) — gist 4815382fe2abe300ceca0ff5f95832d7. Remaining BIGR-784 scope (kickers/preset-pairs/double classes/mobile scrim) split to **BIGR-786** — needs a fresh cohort; the cited instances no longer exist on disk.
- Same day earlier: BIGR-780 → **PR #233 merged to trunk** (fb4ae2f), BIGR-782 → PR #234; #230/#227 merged.
- **Stop-condition override**: design-quality PRs open unreviewed (#228, #231, #234, #235) — maintainer explicitly told the loop to keep going (2026-08-07). Evidence conflicts are growing though: prefer instrument/prompt work over markup-repair fixes until reviews land.
- Next: **BIGR-781** (painted-in fake signage in images — prompt+pipeline) or **BIGR-783** (repeated copy line across sections — pipeline); both untouched by open PRs. BIGR-786 blocked on fresh cohort (build after #234 merges — deterministic screenshots).
- Full per-site critique reports: scratchpad `reports/<slug>.md`; crops: scratchpad `crops/<slug>/` (note: scratchpad is session-scoped; the cohort projects/ + logs remain the durable evidence source).
- Evidence replay trick (deterministic fixes): copy projects/<slug> → <slug>NNN, apply the fix output to plugin/pages/home.html, `sed s#themes/<slug>/#themes/<slug>NNN/#`, screenshot — identical geometry to the cohort shot, crops pair 1:1.

## Cohort
- Trunk commit: 888e526, built 2026-08-07, 0 image-generation failures
- Desktop `projects/<slug>/logs/home.png` (1366px), mobile `home-mobile.png` (390px)

## Ranked backlog (triaged 2026-08-07)

| # | Cluster | Incidence | Class | Status |
|---|---------|-----------|-------|--------|
| A | fix-blocks drops authored `has-text-align-*` (not mirrored in comment JSON) → off-axis eyebrows/prices | 5/7 | deterministic | **filed BIGR-779 — NEXT FIX** |
| B | No root padding synthesized when model omits it → zero mobile gutters (normalizeRootPadding early-return) | 3/7 P0 | deterministic | **merged #233** (BIGR-780) |
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
