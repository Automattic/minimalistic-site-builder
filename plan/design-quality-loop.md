# Design-quality loop state

## Current position
- **2026-08-07 (iter 9)**: Fix BIGR-789 ✓ — **PR #241 open**: alignment discipline in section.md + centered-stack rewording (section-composition.md, page-plan.md). Incidence 5/7 sites before → 0/3 branch rebuilds (portfolio2/naturaleza2/lumen2 in projects/, kept as rebuild evidence); centered display blocks retained. Gist 6b2f2577583cb1f6b1052731f5b0b57a. PR count since 10-PR directive: 4 (#237, #238, #240, #241).
- **2026-08-07 (iter 12-13)**: Fix BIGR-792 ✓ — **PR #244 merged to trunk** (15903a3; no display-scale emails; portfolio4 rebuild clean, gist 281c8983ec553fd96c04cbee5270a1f2). Fix BIGR-793 ✓ — **PR #245 merged to trunk** (ede7f3e; filename-collision photo reuse; CollectImagesStep renames different-subject collisions; naturaleza4 replay with one generated variant image, gist e83c180c7a70c6966424f27f4fcc787d). PR count since directive: 8 (#237 #238 #240 #241 #242 #243 #244 #245).
- **2026-08-07 (iter 11)**: Fix BIGR-791 ✓ — **PR #243 merged to trunk** (8404b62): hero.md TEXT BUDGET now bounds the standfirst (~180 chars). True incidence 1/7 (tbilisi 240; earlier 3/7 was a sweep-window bug, corrected in Linear comment). After: 0/3 rebuilds over budget (atlas2/naturaleza3/tbilisi2 kept). Gist 75a7957508818655185bf7d9ad4fde70. PR count since directive: 6 (#237 #238 #240 #241 #242 #243).
- **2026-08-07 (iter 10)**: Fix BIGR-790 ✓ — **PR #242 merged to trunk** (33ae47a): heading-punctuation rule in section.md (no em/en dash in any heading, mirrors hero H1 rule), with lossless handling for semantic ranges. Before 2/7 sites (7 headings) → 0/2 branch rebuilds (lumen3/portfolio3 kept in projects/). Gist bae550b1e39de27645ab21f1f6be1be6. Also: BIGR-786 re-validated on fresh cohort — none of its 4 mechanisms reproduce; watch-item comment added, stays Backlog. PR count since 10-PR directive: 5 (#237, #238, #240, #241, #242).
- **2026-08-07 (iter 8, fresh cohort)**: Cohort rebuilt on trunk 78b2a4b (7 sites, images, desktop+mobile shots; note trunk predates PRs #236/#237/#238, so their defect classes recur as expected — extra incidence evidence only, not refiled). Fix BIGR-788 ✓ — **PR #240 merged to trunk** (753df61): cinematic×stack-media-first mobile panel now forces base copy color; invisible H1 fixed on portfolio + pulso, hearth byte-identical. Evidence gist 8f38ee01dba01e3858facd24608b2d9d. PR count since 10-PR directive: 3 (#237, #238, #240).

## Fresh-cohort backlog (triaged 2026-08-07, trunk 78b2a4b)
| # | Cluster | Incidence | Class | Status |
|---|---------|-----------|-------|--------|
| P0-A | stack-media-first mobile panel → invisible H1 | 2/7 | deterministic CSS | **merged #240** (BIGR-788) |
| G | Long fully-centered body paragraphs (220-434 chars) | 5/7 | prompt | next: file+fix |
| K | Em dashes in headings ("Step One — …" ×6 lumen; "2004 — 2024" portfolio) | 2/7 | prompt | **merged #242** (BIGR-790) |
| W | Giant email display text wraps mid-domain (portfolio inquiries@…) | 1/7 | CSS/prompt | new |
| J | Same photo in two slots (naturaleza defensa street ×2) | 1/7 | pipeline | new (polish) |
| C786 | Contrast minors: naturaleza pink band 4.43:1; portfolio hero sub-par over busy crowd; pulso Chromatic Echo heading unverified | 3/7 | deterministic | add evidence to BIGR-786 |
| — | Wrong-brand storefront (hearth FIELD & FLOUR), garbled banners (portfolio) | 2/7 | — | covered by merged #236 |

- **2026-08-07 (iter 7)**: Fix BIGR-787 ✓ — **PR #238 merged to trunk** (95eedf5). CollectImagesStep removes theme: asset references no placeholder declares (mangled AI_IMAGE markers); media removal extracted to shared MediaReferenceRemoval; unsafe blocks stay byte-identical, CSS-only cover sources are recognized, and orphaned caption removal records actionable loss evidence. 1858/1858 tests. Evidence: hearth2 replay, gist 83e2c663b64f19cd22592daeef37a868. PR count since 10-PR directive: 2 (#237, #238).
- **2026-08-07 (iter 6)**: Fix BIGR-783 ✓ — **PR #237 merged to trunk** (0447f8e). New deterministic copy-dedupe step after section-rhythm: label-styled paragraphs (exact or strict echo: >=3 shared tokens, >=0.8 containment, no supersets) + quote bodies (6-token shared opening); earliest wins, footer is read-only canon at the closing seam, sole-child removals widen to the emptied wrapper, planned sections anchor-only, pages exceeding the 4-removal safety cap are preserved transactionally with actionable warnings. Malformed pages remain byte-identical; ordered quote prefixes retain repeated tokens. 1850/1850 tests. Cohort verification: only tbilisi triggers (its 2 real dups); 6/7 pages untouched. Evidence: tbilisi26/27 replay, gist ab570fe63d792b4d45c5cba0245f899f. **Maintainer directive 2026-08-07: keep looping until 10 more PRs are open (ignore the 3-open-PR stop condition).** PR count since directive: 1 (#237).
- **2026-08-07 (iter 5)**: Fix BIGR-781 ✓ — **PR #236 merged to trunk** (f7d94d4). Two-part fix: image-generation.md subject guidance no longer blesses incidental storefront/menu text (describe unavoidable text surfaces as BARE — naming lettering even to negate it came back as mirrored glyphs in an interim rebuild); ImagePromptComposer adds a conditional positively-phrased lettering clause when the subject names a text carrier (EN+ES allowlist, new {{lettering_clause}} template slot; conditional so clean prompts never get the concept planted), including a dedicated unmarked-surface clause for transparent carrier assets. 1833/1833 tests before review fix; 1833/1833 after. Evidence: 3 affected demos rebuilt on-branch (atlas2, naturaleza2, hearth4 in projects/, before copies in projects/_before-bigr781/) — 0 fake/garbled painted text in 36 images vs 6/7 sites before; gist 36b052212eabb7330809614d097d59c2. Residual minor class noted in PR: real tool-brand wordmark (tape measure). Side-finding filed: **BIGR-787** (malformed AI_IMAGE alt → unresolved theme: src ships broken img; 1/4 hearth rebuilds).
- Reconciled 2026-08-07: #229 (BIGR-777), #230 (BIGR-778), #234 (BIGR-782), #235 (BIGR-784 caption half), #236 (BIGR-781), #237 (BIGR-783), #238 (BIGR-787), #240 (BIGR-788), #242 (BIGR-790), #243 (BIGR-791), #244 (BIGR-792), #245 (BIGR-793) all MERGED. Open unreviewed: #228, #241.
- **2026-08-07 (iter 4)**: Fix BIGR-784 caption half ✓ — **PR #235 merged to trunk** (e9796cb). ContrastFix walk now records image/gallery figcaption rows (gallery caption matched only in the tail after its last child) and repairs failures via caption-text-* className hooks (scoped `> figcaption` selectors); ScaffoldThemeStep ships the matching CSS hooks; 1758/1758 tests. Evidence: lumen step-boundary replay — gist 4815382fe2abe300ceca0ff5f95832d7. Remaining BIGR-784 scope (kickers/preset-pairs/double classes/mobile scrim) split to **BIGR-786** — needs a fresh cohort; the cited instances no longer exist on disk.
- **2026-08-07 (iter 3)**: Fix BIGR-782 ✓ — **PR #234** (this branch, reviewed 2026-08-07, merging). screenshot.js now captures under emulated prefers-reduced-motion (rides motion.css's accessibility contract → deterministic, fully visible captures); motion.js gains a 4s observer watchdog (IO always delivers an initial batch, so silence while visible = broken observer → fail open; hidden pages re-arm). Harness: silent/healthy/hidden watchdog tests. Evidence: pulso→pulso8 replay, IO stubbed silent via addInitScript, 6s captures, motion.js swapped between shots — gist cad2b2541fd05256950a4f55ab076ccc.
- Earlier same day: BIGR-779 → **#231 merged** (e89288f); BIGR-780 → **#233 merged** (fb4ae2f); #230 (BIGR-778) + #227 merged; cohort atlas/hearth theme.json were already hand-touched by prior evidence work (pulso was the clean before).
- **Stop-condition note**: remaining design-quality PR open unreviewed: #228. Maintainer explicitly told the loop to keep going despite open PRs (2026-08-07). Prefer replay evidence since cohort artifacts increasingly diverge from trunk.
- Next: **BIGR-781** (painted-in fake signage in images — prompt+pipeline) or **BIGR-783** (repeated copy line across sections — pipeline); both untouched by open PRs. BIGR-786 blocked on fresh cohort (build after #234 merges — deterministic screenshots; rebuild cohort before the next Triage).
- Full per-site critique reports: scratchpad `reports/<slug>.md`; crops: scratchpad `crops/<slug>/` (note: scratchpad is session-scoped; the cohort projects/ + logs remain the durable evidence source).
- Evidence replay trick (deterministic fixes): copy projects/<slug> → <slug>NNN, apply the fix output to plugin/pages/home.html, `sed s#themes/<slug>/#themes/<slug>NNN/#`, screenshot — identical geometry to the cohort shot, crops pair 1:1.

## Cohort
- Trunk commit: 888e526, built 2026-08-07, 0 image-generation failures
- Desktop `projects/<slug>/logs/home.png` (1366px), mobile `home-mobile.png` (390px)

## Ranked backlog (triaged 2026-08-07)

| # | Cluster | Incidence | Class | Status |
|---|---------|-----------|-------|--------|
| A | fix-blocks drops authored `has-text-align-*` (not mirrored in comment JSON) → off-axis eyebrows/prices | 5/7 | deterministic | **merged #231** (BIGR-779) |
| B | No root padding synthesized when model omits it → zero mobile gutters (normalizeRootPadding early-return) | 3/7 P0 | deterministic | **merged #233** (BIGR-780) |
| F | Contrast repair misses captions/kickers/preset-pairs/double-classes/mobile scrim (1.2–2.1:1 shipped) | 4/7 | deterministic | caption half **merged #235**; remainder split to BIGR-786 |
| C | Painted-in fake signage/text in images (wrong-brand storefront etc.) — recurrence after BIGR-768 | 6/7, 4×P0 | prompt+pipeline | **merged #236** (BIGR-781) |
| D | Screenshot races motion reveal (blank images in captures) + motion.js lacks user-facing fallback | 1/7 + instrument | deterministic | filed BIGR-782 |
| E | Same copy line/quote repeats across sections page-wide | 3/7 | pipeline | **pr-open #237** (BIGR-783) |
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
