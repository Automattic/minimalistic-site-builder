# Overnight prompt — frm_experiment

You are running an autonomous, long-running improvement loop on the site builder in this repository. The goal is to make the generator able to produce sites that look like these five Framer references when the brief asks for that register:

1. https://cohesion.framer.ai/
2. https://dreammotion.framer.website/
3. https://zova-saas.framer.ai/
4. https://luzia.framer.website/
5. https://spector.framer.website/

The analysis, gap table, workstreams, PR backlog, cohort briefs, and tracking tables are in `plan/frm-experiment.md`. Read it first, every turn. It is the only tracker for this work. Do NOT create, update, or reference Linear issues. Do NOT use BIGR keys in branch names or PR titles.

## Setup (first turn only)

1. Run `git fetch origin`. If `origin/frm_experiment` does not exist, create it from `origin/trunk` and push it: `git branch frm_experiment origin/trunk && git push -u origin frm_experiment`.
2. Work in a dedicated worktree so another session cannot switch your branch: `git worktree add ../builder-frm frm_experiment`, then `cp -r vendor ../builder-frm/vendor` and `cp -r node_modules ../builder-frm/node_modules` (a symlink makes tests run the main checkout's `src`). Copy `.env`. Run every command below from `../builder-frm`.
3. Commit `plan/frm-experiment.md` and `plan/frm-experiment-prompt.md` on `frm_experiment` as the first commit if they are not there yet.
4. Record the unit-test baseline: `php tests/run.php 2>&1 | grep '^  FAIL' | sort > /tmp/frm-baseline-failures.txt`. Trunk has about 61 known failures. Compare sorted failure NAME lists against this baseline, never counts.
5. Build the cohort: `php bin/build-demos.php --file=eval/frm-prompts.json --with-images`. The briefs file does not exist yet, so PR-0a (create it) is the first PR. After it merges, build the cohort, capture desktop (`projects/<slug>/logs/home.png`) and mobile (`SHOT_WIDTH=390 php bin/screenshot.php <slug> --out=projects/<slug>/logs/home-mobile.png`), score each site on conviction, impact, and reference fidelity, and fill the baseline rows in section 7 of the plan.
6. Capture the five reference sites once with the Chrome tools (full scroll, 2 s waits between scrolls because the sites animate in) into the scratchpad, so later fidelity judgments compare against real pixels, not memory. If the browser is unavailable, use the token sheet in section 1 of the plan.

## Every turn

1. Read `plan/frm-experiment.md`. Reconcile the PR log with `gh pr list --base frm_experiment --state all`. Fix stale rows.
2. Pick exactly ONE backlog item, in this priority order:
   - a P0 craft defect found in the last cohort or in the last PR's evidence build (broken layout, invisible text, overflow, blank image), traced to a root cause in the pipeline;
   - the next unfinished PR in the workstream order of section 4 (W0, then interleave W1–W8: take the highest-ranked item whose dependencies are merged);
   - when all backlog items are merged or parked, a fresh cohort build, a fidelity critique against the references, and new backlog items derived from the gaps you see.
3. Branch from fresh `frm_experiment`: `git checkout frm_experiment && git pull --ff-only origin frm_experiment && git checkout -b frm/<short-slug>`. Never stack on an unmerged branch.
4. Implement the one item. Keep the existing architecture: bounded vocabularies in `src/*.php` enums, prompt fragments in `prompts/**`, deterministic execution in steps and theme CSS, build-owned tokens executed after the model answers. A new archetype or device needs: the enum value, the prompt fragment, the page-plan or direction wiring, the theme CSS, a fixer or sanity rule for the objective failure, and unit tests. Follow the escalation ladder in `AGENTS.md` (fix, ignore harmless, remove the smallest part, warn). Never crash a build on generated content.
5. Verify:
   - `php tests/run.php 2>&1 | grep '^  FAIL' | sort > /tmp/frm-now.txt; diff /tmp/frm-baseline-failures.txt /tmp/frm-now.txt` must show no NEW failures. Add tests for the new behavior.
   - Build evidence: at most 2 sites per PR with `--with-images`, chosen from `eval/frm-prompts.json` with `--only=<slug>`, using the brief that exercises the change. Prefer replaying the pure pass over a copied project when the change is deterministic (see the replay trick in `plan/design-quality-loop.md`). Screenshot desktop and mobile. Look at the crops. A change that does not visibly move the render toward the reference is not done.
   - Read `warnings.json` of every evidence build. A new warning class caused by your change is a defect to fix before opening the PR.
6. Open the PR against the integration branch: `gh pr create --base frm_experiment --title "<what changed> (frm)" --body "<why, what, evidence, risks>"`. Put before/after screenshots in a gist and link them from the PR body (never commit screenshots or `projects/`). Include the reference site the change targets and the fidelity score before and after.
7. Self-review the diff as a strict reviewer: correctness, the escalation ladder, no `!important` fights with core, no motion class hidden at rest, mobile at 390px, RTL safety where text placement changes, contrast on new surfaces. Fix what you find.
8. Merge when the test diff is clean and the review found no P0: `gh pr merge <n> --squash --delete-branch`. Then `git checkout frm_experiment && git pull --ff-only origin frm_experiment`.
9. Append a row to the PR log and one line to the iteration log in `plan/frm-experiment.md`. Commit that update on the NEXT PR branch (or push a plan-only commit to `frm_experiment` directly when no PR follows this turn). Never let the tracker drift more than one turn behind.
10. Repeat. Do not stop because the session is long. Stop only on the conditions below.

## Budget and cost rules

- Transport: Anthropic API key from `.env` (default provider in `config/models.json`). Image generation on.
- Per PR: at most 2 evidence builds with images. Per night: one full 5-site cohort at the start (baseline) and one at the end (final scores). If a build fails for an operational reason (auth, rate limit, Playground, network), wait and retry once, then continue with replay evidence and note it in the iteration log. Never file an operational failure as a design defect.
- Keep every evidence project under `projects/` (they are git-ignored). Name replays `<slug>NNN`.

## Standing taste rules (from the maintainer; apply unless a committed opt-in device says otherwise)

- Hero copy: one H1 plus at most one supporting paragraph and at most one planned CTA. The H1 is the first text line of the hero. Centered cinematic copy is preferred. No em dashes in any heading.
- Eyebrows and decorative numerals stay banned by default. On this branch they may return ONLY as bounded, committed devices: `section-badge` (pill label with dot, one per section, never in the hero), `side-label` (split label column), `step-numeral` (process and index sections only). A direction that does not commit the device gets the ban.
- No decorative motifs, ornaments, illustrated borders, or signature devices. Personality comes from type, color, radius, imagery kind, layout, and motion.
- Motion is vertical-first and settles without overshoot. `marquee` is the one documented lateral exception. Everything must be static and fully visible under `prefers-reduced-motion`.
- Contrast floors from `prompts/theme-json.md` hold on every new surface, including glass and gradient text (test the gradient's darkest and lightest stop).
- Accent stays rare. A highlighted card or an active nav pill is the accent's job; do not let it spread to body text or large fills.

## Definition of done for the night

- `frm_experiment` contains merged PRs covering at least W0, W1a, W2a, W3a, W3b, W3d, W4a, W5a, W6a, W7a, and W8a, each with evidence.
- The final cohort table in section 7 is filled, with fidelity scores that improved for every site versus baseline. If a site did not improve, the iteration log says why and what the next PR should be.
- `frm_experiment` merges cleanly with `origin/trunk` at the end (`git merge origin/trunk` on a throwaway branch; resolve nothing, only report conflicts in the plan).

## Hard rules

- Never push to `trunk`. Never force-push. Never rebase. Never delete or rewrite published history.
- Never commit generated `projects/` output, screenshots, or `.env`.
- Never create Linear issues, comments, or links.
- Never widen scope inside one PR. A discovered second problem becomes a backlog row, not a second change.
- Check `git branch --show-current` before every commit; you are in the worktree `../builder-frm`.
- Stop and report only when: the API key is rejected twice in a row, GitHub refuses pushes, disk is full, or the plan's backlog and the definition of done are both complete.
