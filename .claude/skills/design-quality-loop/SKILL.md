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

- Taste, from the maintainer: no eyebrows/kickers or decorative separators in heroes; max 2–3 text bodies
  in a hero; centered, cinematic hero copy is preferred; no em dashes in headlines; visual "signature
  devices"/motif ornaments are noise, not personality.
- Conventions from AGENTS.md (read it if you haven't): issues live in Linear (`BIGR-…`), branch names carry
  the key, PR titles end with `(BIGR-XXX)`, PR bodies carry `Fixes BIGR-XXX` + the Linear URL. Screenshots
  go in **gists referenced from PR comments/description** — never committed to the repo.
- Never commit generated `projects/` output. Only commit pipeline/prompt/test changes.
- One problem per PR. Branch from **fresh trunk** every time; never stack an unmerged branch on another.

## Iteration structure

Each invocation, do exactly ONE of these (in priority order) and stop:

1. **If a validated finding is ready to fix** → do one Fix iteration (phases 4–6).
2. **If a critique backlog exists but is unvalidated/stale** → do a Triage iteration (phase 3).
3. **If no craft/P0 findings remain and no variety PR is open** → do a Variety iteration (phase V):
   propose one new archetype/recipe.
4. **Otherwise** → do a Cohort iteration (phases 1–2) to produce a fresh backlog.

Keep state in `plan/design-quality-loop.md` (committed on trunk is fine): the current backlog with per-finding
status (`new / filed BIGR-XXX / pr-open #NN / merged / rejected`), which cohort it came from, and what the
next iteration should do. Read it first thing, update it last thing, every iteration.

### Phase 1 — Build the cohort

```bash
git checkout trunk && git pull
php bin/build-demos.php --with-images        # all 7 demos, screenshots auto-captured
```

Output: `projects/<slug>/logs/home.png` (desktop, 1366px). Also capture a mobile pass for each site:
`SHOT_WIDTH=390 php bin/screenshot.php <slug>` (use `--serve` on build-demos or boot playground.php per
site as needed). If a build fails or a screenshot is blank, that itself is a P0 finding — file it.

Cohorts are expensive (7 sites × full LLM graph × image generation). Reuse the current cohort as the
"before" evidence for as many findings as possible; only build a fresh cohort when the backlog is exhausted
or so many fixes have merged that the old cohort no longer represents trunk.

### Phase 2 — Critique every section of every site

For each site: read the generated front page markup (`projects/<slug>/theme/…`) to enumerate its sections
(header, hero, each content band, footer). Crop `home.png` into per-section images (Python/PIL or
ImageMagick, into the scratchpad) so each judgment looks at one section at actual size — plus one pass on
the full page for overall composition. Look at the crops; do not critique from markup alone.

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
   frequent it stops meaning "action"; pure #000-on-#fff; purposeless gradients/shadows; link color
   unreadable on dark sections.
6. **Content fit** — lorem-ipsum-shaped copy; headline that names the product category instead of the
   value; CTAs labeled "Submit"/"Learn more"; sections that exist only because a template had them.
7. **Conviction / creativity** — reads as a generic AI template (centered hero, three icon cards,
   testimonial row, footer) with no idea driving the visual choices; nothing that could only belong to
   *this* brand; identical composition recycled across multiple demo sites (compare across the cohort —
   sameness across sites is a generator defect even when each site looks fine alone).
8. **Impact & tidiness** — first-screen impression is flat (tiny hero, dead space, weak imagery); ragged
   alignments; inconsistent corner radii/borders/shadows across components.

### Phase 3 — Triage into a ranked backlog

- **Cluster across sites.** A defect appearing on 3+ of 7 sites is systemic — rank it above any
  single-site issue. Note the incidence ("5/7 heroes …") — it becomes the PR's evidence baseline.
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

```bash
git checkout trunk && git pull
git checkout -b fix/bigr-XXX-<short-slug>
```

Smallest change that removes the defect class. Prompt changes: change the instruction, don't append rule
piles — look for the instruction that *caused* the behavior. Deterministic changes get a regression test
(the repo's fixer/step tests show the pattern). Respect the escalation ladder in AGENTS.md: never make a
generated-content defect fatal.

### Phase 5 — Evidence (the gate for opening a PR)

The change must be **evident in the screenshots**, and the evidence protocol depends on the fix class:

- **Deterministic fix** (CSS/fixer/theme step): rebuild ONE affected demo (`--only=<slug>`, with
  `--with-images` only if imagery is involved) on the branch; pair its section crop with the same section
  crop from the cohort build. Same site, same section, defect visibly gone.
- **Prompt fix** (stochastic): one before/after pair proves nothing. Rebuild **at least 3 affected demos**
  on the branch and report incidence: "before: defect in 5/7 cohort sites (crops attached); after: 0/3
  rebuilds". If the defect still appears in any rebuild, the fix isn't done — iterate before opening a PR.
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
- Move the Linear issue to In Progress, mark the finding `pr-open #NN` in the state file, and stop the
  iteration. Do not start the next fix on top of this branch.

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
2. **Research current trends before designing.** Use WebSearch/WebFetch on curated galleries and current
   roundups — awwwards, siteinspire, godly.website, land-book, minimal.gallery, plus "web design trends
   <current year>" articles — and extract **structural, buildable ideas** (composition, grid usage, type
   scale treatment, image cropping, band rhythm), never vibe words. Note 2–3 reference sites per idea;
   they go in the PR as one line of inspiration credit. Then filter hard through feasibility: must be
   expressible in the frozen WordPress block domain (no custom JS, nothing the block fixer strips — check
   `docs/` and existing fragments for known limits), must work with AI-generated imagery, must degrade
   gracefully with no image at all and at 390px, and must respect the standing taste constraints above.
3. **Write the fragment in the house style.** Read at least two existing fragments in the target catalog
   first and match their structure exactly — assigned-recipe framing, bounded values, blueprint defaults,
   seam/handoff language. Register the new entry wherever selection happens (PHP selector list,
   page-plan.md enumeration + description + variety rules). Distinctness check: if the new entry's
   one-line description could describe an existing entry, it's a variation, not an archetype — reject it.
4. **Evidence: render it, don't describe it.** Force-select the new entry (find the selection mechanism's
   override, or pin it temporarily — never commit the pin) and build **at least 2 demos with different
   brand personalities** (e.g. one editorial like lumen, one loud like pulso), `--with-images`, desktop +
   `SHOT_WIDTH=390`. Judge every render against the full rubric: the proposal must score strongly on
   conviction/impact AND pass craft, hierarchy, and contrast — a stylish composition that clips text on
   mobile is a rejected proposal, not a caveat. Also render the nearest existing entry on one of the same
   brands and include it side by side, so the PR shows the new entry earns its slot.
5. **PR.** File a BIGR issue, branch as usual. Description in plain language: the gap ("every generated
   hero currently looks like X"), what the new archetype looks like (the renders carry this), the
   inspiration credit, and confirmation of the mobile/no-image checks. Label the renders by demo brand.
   At most ONE variety PR open at a time — these need human taste review more than defect fixes do, and
   defect fixes always take precedence while craft/P0 findings exist.

## Stop conditions

Stop the loop (report, don't keep spinning) when: the backlog has no findings above "polish" severity;
3+ PRs are open and unreviewed (evidence rebuilds would no longer reflect what merges); or two consecutive
cohort iterations produce no new systemic findings. When self-pacing with /loop, fix iterations can run
back-to-back; after opening a PR that blocks others, schedule a long delay rather than polling.
