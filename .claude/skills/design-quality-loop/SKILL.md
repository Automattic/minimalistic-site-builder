---
name: design-quality-loop
description: Long-running autonomous loop — build the demo cohort, visually critique every section of every site against the design rubric, root-cause each defect in the pipeline, propose trend-aware new section archetypes/recipes to widen variety, and ship one small PR per problem with before/after screenshot evidence.
---

# Design-quality improvement loop

You are improving the **generator** (prompts/, src/ pipeline steps, fixers, theme CSS), not any individual
site. Demo sites are the measuring instrument: every visual defect you find must be traced to a root cause
in the pipeline before you fix anything. A defect you can't root-cause gets filed as a Linear issue with
evidence, not "fixed" by hand-editing a generated project.

## Standing constraints (do not re-litigate)

- Taste, from the maintainer: no eyebrows/kickers or decorative separators in heroes; hero copy is exactly
  one H1 plus at most one supporting paragraph and at most one planned CTA; centered, cinematic hero copy
  is preferred; no em dashes in headlines; visual "signature devices"/motif ornaments are noise, not
  personality.
- Conventions from AGENTS.md (read it if you haven't): issues live in Linear (`BIGR-…`), branch names carry
  the key, PR titles end with `(BIGR-XXX)`, PR bodies carry `Fixes BIGR-XXX` + the Linear URL. Screenshots
  go in **gists referenced from PR comments/description** — never committed to the repo.
- Never commit generated `projects/` output. Only commit pipeline/prompt/test changes.
- One problem per PR. Branch from **fresh trunk** every time; never stack an unmerged branch on another.

## Iteration structure

Treat `plan/design-quality-loop.md` as a cached working snapshot, not the authority for remote status. At
the start of every invocation:

1. Read the state file.
2. Reconcile every row carrying a BIGR key or PR number against Linear and GitHub
   (`gh pr list --state all --search "BIGR-XXX"`), and correct stale statuses in the working copy. A
   `pr-open` update committed only on its feature branch is not visible from fresh trunk until merge;
   GitHub and Linear win when they disagree with the file.
3. Apply the stop conditions below using the reconciled remote state.

Then do exactly ONE of these (in priority order) and stop:

1. **If this is a variety-cadence turn** (see below) and no unfixed P0/craft finding is open → do a
   Variety iteration (phase V).
2. **If a validated finding is ready to fix** → do one Fix iteration (phases 4–6).
3. **If a critique backlog exists but is unvalidated/stale** → do a Triage iteration (phase 3).
4. **If no craft/P0 findings remain and no variety PR is open** → do a Variety iteration (phase V).
5. **Otherwise** → do a Cohort iteration (phases 1–2) to produce a fresh backlog.

**Variety cadence.** Keep an iteration counter in the state file. Every 4th iteration is a variety-cadence
turn: variety work runs even while hierarchy/spacing/polish findings remain in the backlog — only an
unfixed P0/craft finding (broken, invisible, overflowing, collapsed) defers it to the next iteration. The
generator's range must widen on a schedule, not only when the defect backlog happens to be empty; a loop
that ships only restrictions converges on sterile output. If a variety PR is already open awaiting review,
the cadence turn still runs phase V through its render-evidence step but **parks** the finished proposal on
its Linear issue (renders + go/no-go recommendation attached) instead of opening a second PR; when the open
slot frees, ship the best parked proposal before researching a new one.

Keep the current backlog in that state file with per-finding status
(`new / filed BIGR-XXX / pr-open #NN / merged / rejected`), which cohort it came from, and what the next
iteration should do. Update it last thing, every iteration. Commit a fix/variety update on that iteration's
PR branch; never push a state-only commit directly to trunk and never assume an unmerged branch's update is
already present there. When a Cohort or Triage iteration opens no PR, keep its update in the working tree
for the next local invocation, carry it onto the next related PR branch, and do not discard it during the
fresh-trunk transition.

### Phase 1 — Build the cohort

```bash
git checkout trunk && git pull --ff-only origin trunk
php bin/build-demos.php --with-images        # all 7 demos, screenshots auto-captured
```

Output: `projects/<slug>/logs/home.png` (desktop, 1366px). Also capture a mobile pass for each site without
overwriting the desktop evidence:

```bash
SHOT_WIDTH=390 php bin/screenshot.php <slug> --out=projects/<slug>/logs/home-mobile.png
```

`screenshot.php` boots and tears down Playground itself; do not use `build-demos.php --serve` for this pass.
If a build fails or a screenshot is blank, reproduce it and inspect the logs before classifying it. A defect
in generated output or the builder is a P0 finding; auth/API/rate-limit, browser/Playground, environment, or
I/O failure is operational — stop and report it without filing a false design finding, then retry when the
dependency is healthy.

Cohorts are expensive (7 sites × full LLM graph × image generation). Reuse the current cohort as the
"before" evidence for as many findings as possible; only build a fresh cohort when the backlog is exhausted
or so many fixes have merged that the old cohort no longer represents trunk.

### Phase 2 — Critique every section of every site

For each site: read the generated front page markup (`projects/<slug>/theme/…`) to enumerate its sections
(header, hero, each content band, footer), and read `projects/<slug>/designDirection.json` — the site's
committed creative contract — so every judgment below can compare what shipped against what was promised.
Crop both `home.png` and `home-mobile.png` into per-section images
(Python/PIL or ImageMagick, into the scratchpad) so each judgment looks at one section at actual size — plus
one full-page pass at each viewport for overall composition. Look at every crop; do not critique from
markup alone.

Score each section AND the whole page against this rubric. For every failure, record: site, section,
dimension, one plain sentence describing what a visitor sees wrong, the crop path, and a first guess at
root cause (which prompt file / pipeline step / fixer / CSS).

1. **Craft errors (P0 class)** — overlapping or clipped text, overflow, invisible text on dark sections,
   broken/missing/blank images, images with painted-in fake text or wordmarks, layout collapse at 390px,
   duplicated lines (tagline echoed by eyebrow, H1 echoing the site name), console errors.
2. **Hierarchy** — is the eye pulled to the right thing first? Competing focal points; hero text at body
   weight; CTA not visually dominant; everything the same size.
3. **Spacing & rhythm** — gaps not from a consistent scale; cramped sections; uniform padding everywhere so
   nothing groups; elements off-grid; section padding that doesn't scale down on mobile.
4. **Typography** — too many families/weights; body measure over ~75ch; leading too tight on body or too
   loose on headings; no meaningful jump between adjacent type sizes; font weights used but never loaded;
   default-looking type where the brand needs character.
5. **Color & contrast** — body text under 4.5:1 (measure it from the crop, don't eyeball); accent color so
   frequent it stops meaning "action"; pure #000-on-#fff on a site that did not commit to `color_economy:
   monochrome` (a committed neutral monochrome product register is a look, not a defect); purposeless
   gradients/shadows; link color
   unreadable on dark sections.
6. **Content fit** — lorem-ipsum-shaped copy; headline that names the product category instead of the
   value; CTAs labeled "Submit"/"Learn more"; sections that exist only because a template had them.
7. **Conviction / creativity** — reads as a generic AI template (centered hero, three icon cards,
   testimonial row, footer) with no idea driving the visual choices; nothing that could only belong to
   *this* brand; identical composition recycled across multiple demo sites (compare across the cohort —
   sameness across sites is a generator defect even when each site looks fine alone).
8. **Impact & tidiness** — first-screen impression is flat (tiny hero, dead space, weak imagery); ragged
   alignments; inconsistent corner radii/borders/shadows across components.
9. **Direction fidelity** — audit the render against `designDirection.json` field by field: is the
   `image_grade` visible and consistent across every image? Does the committed `motion` profile actually
   move (check `motion_note` too)? Are `card_style`, `shape`, and `canvas` executed literally? Do the
   heading/body faces render at the committed weights? Does anything on the page speak the
   `concept_seed`'s world beyond the palette — or would the render look the same under a generic
   direction? Every broken promise is a **generator defect with a root cause** (a fixer stripping the
   styling, a step not receiving the field, a missing capability) — the pipeline paid for that ambition
   and then lost it, and recovering it is the cheapest creativity win available. Record lost commitments
   with the same rigor as craft errors: field, committed value, what shipped instead, crop path.

**Graded scores, not just findings.** Besides listing failures, score each site 1–5 on two axes:
**conviction** (rubric 7 — could this page only belong to this brand?) and **impact** (rubric 8 —
first-screen memorability). Anchor the scale: 1 = interchangeable AI template, 3 = competent but
forgettable, 5 = a human designer would claim it. Record the per-site scores and the cohort means in a
small table in `plan/design-quality-loop.md` next to the cohort's trunk commit, so successive cohorts form
a trend line. A defect-free cohort with flat scores is not a healthy cohort — it is the loop's signal that
the next priority is variety/ceiling work, not more polish.

### Phase 3 — Triage into a ranked backlog

- **Cluster across sites.** A defect appearing on 3+ of 7 sites is systemic — rank it above any
  single-site issue. Note the incidence ("5/7 heroes …") — it becomes the PR's evidence baseline.
- **Check the score trend.** Compare this cohort's mean conviction/impact scores against the previous
  cohort's table. A drop of ≥0.5 on either mean after a batch of restrictive prompt merges is itself a
  **P1 systemic finding** ("the generator got safer and duller"): root-cause it to the specific merged
  restrictions, and the fix is a Variety/ceiling iteration or a loosening PR — never more restrictions.
- **Root-cause each cluster** by reading the responsible prompt/step/fixer code and the project's
  `logs/` + `warnings.json`. Classify: (a) deterministic bug (CSS, fixer stripping styles, theme.json,
  font loading), (b) prompt-quality issue (stochastic), (c) missing capability (needs design + a Linear
  issue, not a drive-by fix).
- **Dedupe against reality**: check open PRs (`gh pr list`) and the Linear project for existing BIGR issues
  before filing. Update `docs/design-quality-improvements.md` only if you find a new systemic theme.
- File/refresh a Linear issue per validated finding (key goes in the eventual branch/PR). Discard findings
  that are pure taste with no rubric backing — the rubric is the contract.
- Rank by: incidence × severity (craft errors > hierarchy/contrast > polish) ÷ fix size. Record the ranked
  backlog in `plan/design-quality-loop.md`.

### Phase 4 — Fix (one finding)

Refresh GitHub and Linear once more to ensure nobody claimed the finding after this invocation started.
Before editing or creating the branch, move its Linear issue to **In Progress**, as required by AGENTS.md.

```bash
git checkout trunk && git pull --ff-only origin trunk
git checkout -b fix/bigr-XXX-<short-slug>
```

Smallest change that removes the defect class. Prompt changes: change the instruction, don't append rule
piles — look for the instruction that *caused* the behavior. Deterministic changes get a regression test
(the repo's fixer/step tests show the pattern). Respect the escalation ladder in AGENTS.md: never make a
generated-content defect fatal.

### Phase 5 — Evidence (the gate for opening a PR)

Run the automated checks appropriate to the touched files after the final change. Use targeted tests while
iterating, run `php tests/run.php` for PHP/pipeline changes, and run relevant integration or asset checks
when their code paths changed. Rerun the gate after any evidence-driven edit. Screenshots do not replace
automated tests. Record the exact commands and results for the PR.

The change must also be **evident in the screenshots**, and the evidence protocol depends on the fix class:

- **Deterministic fix** (CSS/fixer/theme step): hold generated content constant. Copy ONE affected cohort
  project byte-for-byte, replay only the changed fixer/step or replace only the changed code-owned asset,
  then capture the same site and section. Use a fresh `--only=<slug>` build as secondary regression coverage
  when useful, never as the sole before/after proof because new LLM output changes the comparison.
- **Prompt fix** (stochastic): one before/after pair proves nothing. Rebuild **at least 3 affected demos**
  on the branch and report incidence: "before: defect in 5/7 cohort sites (crops attached); after: 0/3
  rebuilds". If the defect still appears in any rebuild, the fix isn't done — iterate before opening a PR.
- **Restrictive prompt fix** (a ban, cap, or "never X" rule): additionally prove the rule didn't
  over-reach. Name the nearest *legitimate* behavior adjacent to the banned one (banning centered body
  paragraphs → centered display type; banning heading dashes → dashes in semantic ranges) and include one
  after-crop showing it still occurring — a "still-alive" crop under its own heading in the PR. If no
  rebuild exercises the adjacent behavior, that absence is itself evidence of over-reach: narrow the rule
  until it reappears. And never counterweight a ban by adding positive examples at creative decision
  points — listed examples become the new default and trade one kind of sameness for another; the
  counterweight is the narrower rule plus the still-alive evidence.
- Capture the failing viewport. For a 390px finding, use phase 1's full mobile command and distinct
  `home-mobile.png` output for every after-project.
- Crop both images to the affected section so the diff is unmissable; place them side by side or stacked
  under **Before** / **After** headings. If the change is subtle at full-page zoom, the crop is the
  evidence and the full pages go in a collapsed `<details>`.
- Verify the after-shot has no *new* defects (quick pass over the full page and the other rubric rows —
  don't ship a contrast fix that breaks hierarchy).

Host images per AGENTS.md (gist seeded with a text file, push PNGs, use raw URLs). Never commit PNGs.

### Phase 6 — PR

- Title: plain description + `(BIGR-XXX)`. Body in **simple language** — write for someone looking at
  their own website, not a pipeline engineer: what looked wrong, what it looks like now, one sentence on
  why it happened. Then the Before/After images, then a short technical note (root cause, files touched,
  tests), then `Fixes BIGR-XXX` + Linear URL and the generated-with footer.
- After the PR has a number, mark the finding `pr-open #NN` in the state file, commit and push that update
  on the same PR branch, and stop the iteration. Do not start the next fix on top of this branch.

### Phase V — Variety iteration: propose a new archetype or recipe

The generator's variety comes from small reviewed catalogs, and this track grows them:

- **Hero recipes**: `prompts/hero-compositions/*.md`, selection code-owned in `src/HeroComposition.php`.
- **Footer recipes**: `prompts/footer-compositions/*.md`.
- **Section layout archetypes**: enumerated and described in `prompts/page-plan.md`, executed via
  `prompts/section-composition.md`.
- **Header behaviors**: `prompts/header.md` + `src/HeaderBehavior.php`.

One proposal per iteration, one PR per proposal. The bar is high: a catalog entry ships on every future
site, so a mediocre addition pollutes the generator permanently. Steps:

1. **Name the gap from evidence.** Start from the latest cohort's conviction/sameness findings: which
   compositions repeat across brands, which catalog is thinnest for its slot, what kind of brand is
   currently underserved (e.g. "all 5 hero recipes are image-led; a type-led brand has nowhere to go").
   A proposal that doesn't cite a concrete observed gap gets skipped, not invented.
2. **Claim the work before researching or implementing.** Dedupe the gap against GitHub and Linear and
   re-check that no variety PR opened after this invocation started. File or refresh its BIGR issue, move
   it to **In Progress**, update fresh trunk as in phase 4, and create an appropriate BIGR-keyed branch.
3. **Research current trends before designing.** Use WebSearch/WebFetch on curated galleries and current
   roundups — awwwards, siteinspire, godly.website, land-book, minimal.gallery, plus "web design trends
   <current year>" articles — and extract **structural, buildable ideas** (composition, grid usage, type
   scale treatment, image cropping, band rhythm), never vibe words. Note 2–3 reference sites per idea;
   they go in the PR as one line of inspiration credit. Then filter hard through feasibility: must be
   expressible in the frozen WordPress block domain (no custom JS, nothing the block fixer strips — check
   `docs/` and existing fragments for known limits), must work with AI-generated imagery, must degrade
   gracefully with no image at all and at 390px, and must respect the standing taste constraints above.
4. **Write the fragment in the house style.** Read at least two existing fragments in the target catalog
   first and match their structure exactly — assigned-recipe framing, bounded values, blueprint defaults,
   seam/handoff language. Register the new entry wherever selection happens (PHP selector list,
   page-plan.md enumeration + description + variety rules). Distinctness check: if the new entry's
   one-line description could describe an existing entry, it's a variation, not an archetype — reject it.
5. **Evidence: render it, don't describe it.** Run phase 5's automated-test gate, then force-select the new
   entry (find the selection mechanism's override, or pin it temporarily — never commit the pin) and build
   **at least 2 demos with different brand personalities** (e.g. one editorial like lumen, one loud like
   pulso), `--with-images`, desktop, and mobile. For every mobile render, reuse phase 1's complete
   `SHOT_WIDTH=390` command, including its distinct `--out=projects/<slug>/logs/home-mobile.png` path. Judge
   every render against the full rubric: the proposal must score strongly on conviction/impact AND pass
   craft, hierarchy, and contrast — a stylish composition that clips text on mobile is a rejected proposal,
   not a caveat. Also render the nearest existing entry on one of the same brands and include it side by
   side, so the PR shows the new entry earns its slot.
6. **Open the PR** using phase 6's title/body/state conventions. Describe in plain language: the gap
   ("every generated hero currently looks like X"), what the new archetype looks like (the renders carry
   this), the inspiration credit, and confirmation of the mobile/no-image checks. Label the renders by demo
   brand. At most ONE variety PR may be open at a time — these need human taste review more than defect
   fixes do, and defect fixes always take precedence while craft/P0 findings exist.

## Stop conditions

Stop the loop (report, don't keep spinning) when: the backlog has no findings above "polish" severity AND
no variety work is actionable (no parked proposal waiting on a free slot, and the cohort score trend is
flat-or-rising — a defect-free backlog with declining scores routes to a Variety iteration, not to a stop);
3+ PRs are open and unreviewed (evidence rebuilds would no longer reflect what merges); or two consecutive
cohort iterations produce no new systemic findings. When self-pacing with /loop, fix iterations can run
back-to-back; after opening a PR that blocks others, schedule a long delay rather than polling.
