# Design-quality loop state

## Iteration counter
- **18** (2026-08-07, Triage iteration — backlog validated, root-caused, filed BIGR-797…802, ranked below). Variety cadence: every 4th iteration → next variety-cadence turn is iteration **20**. Variety PR slot currently OCCUPIED by #228 (BIGR-776, open unreviewed) — a cadence turn while it stays open parks its proposal on the Linear issue instead of opening a second PR.

## Next iteration (20 — VARIETY CADENCE TURN)
Iteration 20 is the 4th-iteration variety turn. BIGR-798 (H1 mid-word snap) is an unfixed P0/craft finding → per the cadence rule it defers variety to iteration 21: **fix BIGR-798 first** (deterministic text-fit where H1 + hero recipe are both known: HeroUnit/HeaderHeroStep; evidence replay on pulso2 hero). Then iteration 21 runs the variety turn (gap from cohort: centered-cinematic underused, no type-led/no-image hero; #228 still occupies the slot → park proposal on its Linear issue if unreviewed). Queue after: BIGR-799 (one-line validator fix), BIGR-800, BIGR-801, BIGR-802.

## Fresh cohort (iteration 17) — trunk 1d27e76, built 2026-08-07
- Slugs (build-demos auto-suffixed): **portfolio6, tbilisi4, naturaleza6, lumen4, atlas3, pulso2, hearth2**. 7/7 built, 0 image-generation failures. Desktop `logs/home.png` (1366px) + mobile `logs/home-mobile.png` (390px) captured for all 7.
- Critique reports: scratchpad `reports/<slug>.md` + `reports/_cohort-synthesis.md` (session-scoped; projects/ + this table are the durable record).

### Graded scores (BASELINE — first scored cohort, anchor 1=template / 3=competent / 5=designer-claimed)
| site | conviction | impact |
|---|---|---|
| portfolio6 | 4 | 3.5 |
| tbilisi4 | 4.5 | 4 |
| naturaleza6 | 3 | 2.5 |
| lumen4 | 4 | 3.5 |
| atlas3 | 3.5 | 3.5 |
| pulso2 | 4.5 | 4.5 |
| hearth2 | 4 | 3 |
| **mean** | **4.0** | **3.5** |

### Ranked backlog (TRIAGED iteration 18, 2026-08-07 — all root-caused in code/logs, filed in Linear)
| rank | BIGR | Cluster | Incidence | Class | Status |
|---|---|---|---|---|---|
| 1 | **BIGR-797** | bare `<li>` items dropped at re-serialization → empty `<ul>` ships (pulso2 3 ticket-tier lists, atlas3 schedule bullets; LLM logs prove authored content). Fixed by BareListItemLift pre-fixer pass in FixBlocksStep. Evidence gist 57df7f9d224cb14b796df55524cfbd42; replays pulso21/atlas31 in projects/. | 2/7, 4 lists | deterministic | **pr-open #249** |
| 2 | **BIGR-798** | pulso2 desktop H1 "ELECTRONI/C" mid-word snap; display clamp max 104px vs ~610px layered-poster column; style.css:257 guard violated its dormancy contract. Fix: text-fit where H1 + recipe are both known (HeroUnit/HeaderHeroStep). | 1/7 P0 | deterministic | filed |
| 3 | **BIGR-799** | validator misreads EVERY overlay header as "stacked" — AboveFoldPartFacts.php:456 checks literal `header-overlay` class HeaderBehavior never emits; 4/7 false drift warnings; masks real downgrades. One-line + test. (Header markup verified overlay-correct on all 4.) | 4/7 warnings | deterministic | filed |
| 4 | **BIGR-800** | primary-action retarget keeps stale label ("EXPLORE OUR MENU" → reservations band, naturaleza6). Fix in page-plan repair step. | 1/7 | deterministic | filed |
| 5 | **BIGR-801** | page-plan authored a ONE-section front page for a restaurant (padding saved it; 3150px page, no menu). Coverage-obligation wording in page-plan.md; ≥3-rebuild evidence. | 1/7 | prompt | filed |
| 6 | **BIGR-802** | wrong-locale imagery under location captions (Florence as Plaza de Mayo etc.) + legible ghost-sign wordmarks "GROCERY SMITH & CO" (portfolio6). Locale plumbing in ImagePromptComposer + ghost-sign clause; ≥3 image rebuilds. | 1-2/7 | prompt/image | filed |
| — | unfiled | Polish: lumen4 masonry dead zone; portfolio6 gate-picket dead space; atlas3 floating accent line + dark-band caption contrast; hearth2 orphan label; contrast-fix site-title 1.00 noise (fold into BIGR-799 investigation). | 1/7 each | mixed | recorded only |

Score trend: no previous scored cohort — this table is the baseline (conviction 4.0 / impact 3.5). No restriction-driven dullness signal applicable.

### Original critique clusters (iteration 17, superseded by ranked table above)
| # | Cluster | Incidence | Class | Status |
|---|---------|-----------|-------|--------|
| A | Authored `<li>` items without wp:list-item comments dropped at re-serialization → empty `<ul>` ships (atlas3 schedule bullets ×1; pulso2 ticket-tier inclusions ×3 — tiers read undifferentiated). LLM logs prove real content authored. Fix: synthesize wp:list-item from bare li (BIGR-779 pattern). | 2/7, 4 lists | deterministic | new — TOP |
| B | pulso2 desktop hero H1 snaps mid-word "ELECTRONI/C", no hyphen, first screen. Display preset too large for hero column; style.css word-break guard engaged against its stated dormancy contract. Mobile fine. | 1/7 P0 | deterministic/CSS+sizing | new |
| C | header.mode authored "overlay" → delivered "stacked" (portfolio6, tbilisi4, atlas3, pulso2). Renders acceptably but overlay intent lost on majority; validate-theme flags it for repair. Root cause not yet dug. | 4/7 | pipeline | new — investigate |
| D | CTA retarget keeps stale label: naturaleza6 "EXPLORE OUR MENU" retargeted #menu-signature→#closing (reservations) but label kept promising a menu. | 1/7 | deterministic | new |
| E | page-plan authored ONE section for a restaurant; padded to 3; page 3150px, no menu section at all. | 1/7 | prompt (plan) | new |
| F | portfolio6 wrong-locale imagery (Florence captioned Plaza de Mayo; NYC/UK streets as BA) + legible fake ghost-sign "GROCERY SMITH & CO"; baked-in frame on About photo. Residual BIGR-781 class. | 1-2/7 | image prompt | new (moderate) |
| G | Polish: lumen4 collection masonry ~350×760 dead zone; portfolio6 gate-picket dead space; atlas3 floating accent line + borderline dark-band captions; hearth2 orphan label "Since fermentation begins..." | 4×1/7 | mixed | new (polish) |
| H | warnings noise: contrast-fix "site-title base on base 1.00" on 3 sites whose headers render fine — false-positive rows pollute warnings.json. | 3/7 | deterministic | new (polish) |

### Positive regressions-held checks (evidence the recent PRs work)
Centered body paragraphs: none observed (789 ✓). Heading em-dashes: none (790 ✓). Standfirsts short (791 ✓). Emails body-scale (792 ✓). No photo reuse collisions (793 ✓). Headline registers strong everywhere (794 ✓). No stranded media-row quadrants (795 ✓). Mobile hero H1s legible on all 7 (788 ✓). Gutters present on all 7 mobiles (780 ✓). hearth storefront clean of signage (781 ✓ on former worst offender; residual class only on portfolio6).

### Variety observation (for iteration-20 cadence turn)
Hero families: 3× left-copy-over-photo, 3× split copy|media, 1× centered cinematic (tbilisi4) — centered-cinematic underused despite stated taste preference; no type-led/no-image hero exists in cohort.

## Current position (history)
- **2026-08-07 (iters 14-16, DIRECTIVE COMPLETE)**: 10 PRs opened since the maintainer's 10-PR directive: #237 (BIGR-783 copy dedupe), #238 (BIGR-787 broken placeholder), #240 (BIGR-788 mobile hero color), #241 (BIGR-789 centered paragraphs), #242 (BIGR-790 heading dashes), #243 (BIGR-791 standfirst length), #244 (BIGR-792 display email), #245 (BIGR-793 filename collision), #246 (BIGR-794 headline register, gist 38d40d083d01e5edf5e76e3ba76567ac), #247 (BIGR-795 column balance, gist c1a442588bd854829f6e8bb4a1f62abd). Plus #236 (BIGR-781) just before the directive. Loop STOPPED after this iteration; restart with /loop /design-quality-loop. Next when resumed: reconcile merges, fresh cohort (2 consecutive-quiet-cohorts stop check), BIGR-786 watch item, variety track blocked on #228 review.
- Rebuild projects kept in projects/ as evidence: portfolio2-5, naturaleza2-5, lumen2-3, tbilisi2-3, atlas2; _old-* dirs are the pre-cohort archive (deletable).
- **2026-08-07 (iter 9)**: Fix BIGR-789 ✓ — **PR #241 merged to trunk** (095dfc6): alignment discipline in section.md + centered-stack rewording (section-composition.md, page-plan.md). Incidence 5/7 sites before → 0/3 branch rebuilds (portfolio2/naturaleza2/lumen2 in projects/, kept as rebuild evidence); centered display blocks retained. Gist 6b2f2577583cb1f6b1052731f5b0b57a. PR count since 10-PR directive: 4 (#237, #238, #240, #241).
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
- Reconciled 2026-08-07 (iter 17): #229–#248 design-quality PRs all MERGED including #247 (BIGR-795) and #248 (BIGR-796). Open unreviewed: **#228 only** (BIGR-776 variety). Linear issues BIGR-779/780/781/782/783/784/787/788/789/790/791/792/793/794/795 moved to Done (had merged PRs but sat In Progress).
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
