# BIGR-957 — review gate and findings

> **Historical.** BIGR-986 (2026-09-04) removed the `BusinessSite` and `PhotographySite` matchers this plan describes. The mark now ships for every site without a `persona_name`. See `docs/business-logo-and-site-icon.md`, section "Who gets a mark".

Companion to `plan/business-logo-and-site-icon.md`. Written by a reviewing
session; the implementing agent should read this **before Task 8**. Sections 6-7
audit the commits that have landed (Tasks 1-6) and carry four confirmed defects,
one of them high severity (7a).

Spec is authoritative (`docs/business-logo-and-site-icon.md`). The plan is
derived from it. This file records what the plan gets wrong, what the
environment does that the plan does not expect, and the gate to run per task.

---

## 0. Open items — read this first

**Corrected 2026-09-01 against CI.** PR
[#412](https://github.com/Automattic/minimalistic-site-builder/pull/412) is open
and green on PHP 8.1 and 8.4: **unit 3649 passed / 0 failed / 3 skipped,
integration 34 passed / 0 failed** (run 33444881550).

That falsifies two findings this review raised and corrects the premise of
section 1. See section 20. Sections below are chronological and left as written;
where CI contradicts them, section 20 wins.

**Nothing blocks merge.**

- **12a — the favicon. RESOLVED.** `site_icon` now comes from its own opaque
  `site-icon.png`, the recolored mark flattened onto the header background;
  `custom_logo` keeps the transparent one. See section 21.

**Withdrawn — CI proved them wrong**

- ~~16 — the epsilon pin can skip itself.~~ It runs and passes on both PHP
  versions.
- ~~17 — the G4 logo assertions never execute.~~ G4 passes on CI; they execute.

**Nits, safe to merge without**

- **13a** — comment at `HeaderHeroStep.php:670` on why the post-contract write
  cannot move the fields `ThemeValidator` compares.
- **13b** — hoist the three `BusinessSite::matches()` calls in `run()`.
- **6d** — the re-pin message still describes chrome landmark de-duplication.

---

## 1. The baseline is NOT green. Do not chase these.

> **Correction (section 20):** these nine failures are specific to this
> workstation, not to trunk. All nine pass on CI. The operational advice below
> — do not chase them, gate on the delta — was right; the stated cause was not.

Verified by checking out `trunk` into a clean worktree (with `vendor/` copied,
not symlinked) and running both suites there. Identical failures with and
without the BIGR-957 work, so every one of these is pre-existing and unrelated.

**`php tests/run.php` on trunk: 3600 passed, 9 failed, 1 skipped.**

```
FAIL  first-run manifest failure does not leave new pattern files
FAIL  a failed rollback keeps staging so the previous tree is not deleted
FAIL  homepage-design removes a DOM-body style after adjacent doctypes
FAIL  keyOutBackground unmattes anti-aliased edge pixels instead of keeping them opaque
FAIL  transform-site writes exact legacy part names and AssemblePagesStep accepts pages.json unchanged
FAIL  G1 engine-support families reach final theme CSS after transform-site and page-styles
FAIL  G2 CSS-owned flex link row items beat WordPress flow margins without important
FAIL  G3 sibling core/buttons blocks in one authored action row beat WordPress flow margins without important
FAIL  transform-site is byte-identical and same-tag sibling reorder cannot mis-target carrier CSS
```

**`php tests/run-integration.php` on trunk: 33 passed, 1 failed.**

```
FAIL  G4 HTML-first output gives every transformer marker class matching final theme CSS
```

Two consequences the plan does not account for:

- **Task 9 Step 1 says "all unit tests PASS." That is unreachable.** The
  correct exit condition is *baseline+N passed, still exactly these 9 failed,
  by name*. Rewrite the step that way rather than trying to reach zero.
- **Task 2 edits `tests/unit/image_transparency_test.php`, which already
  contains one of the 9** (`keyOutBackground unmattes anti-aliased edge
  pixels…`). You will open a red file. Do not repair it, do not weaken it, do
  not fold it into your commit. It is trunk's problem, not BIGR-957's.

---

## 2. The gate — run after every task, before that task's commit

1. `php tests/run.php` → passed count rose by exactly the number of tests you
   added; failed is still 9, same names. Any tenth failure is yours.
2. `php tests/run-integration.php` → 33 passed / 1 failed (G4) until Task 4,
   which changes `AssemblePagesStep` and must re-pin the sha256 at
   `tests/integration/dp_slice3_section_mode_test.php:154`. That pin is the
   only one covering step sources — `hash_file` appears five times in `tests/`
   and the other three are font-catalog and oracle-manifest pins you will not
   touch.
3. **Read source raw when judging code quality.** A shell hook rewrites `cat`
   and `grep` through a token filter that silently strips docblocks and
   comments. `cat -n src/BusinessSite.php` shows a bare class; `rtk proxy cat
   -n src/BusinessSite.php` shows the real file. Use `rtk proxy` for any read
   where comments matter — otherwise you will "add" a docblock that is
   already there, or judge a well-documented file as undocumented.
4. New `src/` file conventions, checked against `PhotographySite`:
   `declare(strict_types=1)`, `final class`, a class docblock that says *why*
   the boundary exists and what it deliberately excludes, `@param array<mixed>`
   on array parameters.
5. New test files need no registration — `tests/run.php` globs
   `tests/unit/*_test.php`. `src/` needs no registration either; `autoload.php`
   is PSR-4 over `Automattic\SiteBuild\` → `src/`.
6. Assertions available in `tests/lib.php` are `assert_true`, `assert_eq`,
   `assert_contains`, `assert_throws`. **There is no `assert_false`** — the
   house idiom for a negative is `assert_true(!…)`.
7. Any new artifact write needs the path in that step's
   `StepDeclaration::writes`; any new read needs an earlier step that writes
   it. `tests/unit/step_composition_test.php` asserts this over both graphs,
   so a mistake here fails loudly rather than at runtime.
8. Re-read the matching section of the spec before the task's commit. The plan
   paraphrases; where they disagree the spec wins.
9. **Audit commits, not the working tree.** The tree is a moving target while
   another agent works in it. A snapshot taken mid-task showed 12 failures;
   minutes later the same tree showed 10, then 9, as the agent moved through
   TDD red -> green. Judge a task at its commit, where the suite is meant to be
   at baseline. A red working tree usually means someone is between steps.

---

## 3. Task 1 — accept, with three small adjustments

`src/BusinessSite.php` and `tests/unit/business_site_test.php` are correct and
idiomatic: the docblock mirrors `PhotographySite` and explains the `$prompt`
asymmetry, the guard order is right, and the suite went 3600 → 3607 with the 9
baseline failures unchanged. All eight rows of the spec's pinned table are
covered, including the prompt-only photographer and the dropped `services?`.

**1a. `strtolower` is byte-based; the allowlist has a non-ASCII token.**
`src/BusinessSite.php:30`. `strtolower('CAFÉ MODERNE')` returns
`cafÉ moderne`, and `\bcafés?\b` does not match it. An all-caps `title` is
ordinary in a site spec. Verified:

```
CAFÉ MODERNE  strtolower → cafÉ moderne  match=N
              mb_strtolower → café moderne  match=Y
```

Use `mb_strtolower(…, 'UTF-8')`. `PhotographySite` shares the byte-based call
but its tokens are pure ASCII, so it is unaffected — this is a `BusinessSite`
fix, not a repo-wide change, and it does not belong in `PhotographySite`.

**1b. Add the empty-spec case.** `tests/unit/photography_site_test.php:87` pins
`matches([])` and `matches(['name' => 'Solo'])`. `BusinessSite` should pin the
same two — it is the cheapest guard against a future refactor making an absent
field truthy.

**1c. Six of twenty alternation branches are exercised.** The loop covers
restaurant, cafe, café, salon, firm, hotel. Untested and therefore free to
break silently: `storefront`, `shop`, `store`, `retail`, `bakery` (matched via
`area` in test 1 but not as a token), `bar`, `spa`, `clinic`, `gym`, `studio`,
`agency`, `consultancy`, `saas`, `boutique`. Extend the existing loop — it is
one array literal, not new tests.

---

## 4. Plan defects to fix before implementing

**4a. `isKeyed`'s threshold contradicts the plan's own tests. Task 2.**
The implementation sketch rejects a corner on
`getColorValue(\Imagick::COLOR_ALPHA) > 0.0`, but every existing assertion in
`tests/unit/image_transparency_test.php` treats transparent as `< 0.01` and
opaque as `> 0.99` — because PNG quantisation does not guarantee exact zero.
The plan's own `padToSquare` test uses `< 0.01` two lines above. With `> 0.0` a
legitimately keyed mark can read as un-keyed, which drops the role and silently
turns the whole feature off. Use the same `0.01` epsilon the file already uses.

**4b. Without Imagick the feature silently no-ops, and nothing says so.**
`ext-imagick` is `suggest`, not `require`, in `composer.json`. The sketched
`isKeyed` returns `false` when Imagick is missing, so on such a host every
business site drops the role and ships no mark. That is the *right* outcome —
`keyOutBackground` would also be a no-op, so the alternative is importing an
opaque white square as the logo — but it is currently an accident rather than a
decision. Add the row to the spec's Failures ladder and emit the warning:

| Situation | Delivery |
|---|---|
| Imagick unavailable | warn; `role` dropped; no mark shipped or imported; no mods; title visible |

Right now a host with no Imagick gets no logo *and no warning explaining why*,
which is exactly the silent failure the spec's "actionable warnings" rule
exists to prevent.

**4c. Task 9's visual check needs a real command.** It offers
`php bin/build.php … --with-images --no-serve` hedged with "or the repo's
current flags". Confirm the flags against `bin/` before that step, and paste
the command that actually ran into the PR. A hedged command in a plan becomes a
skipped step.

---

## 5. Cleared — do not spend time here

- **Adding `plugin/images.json` to generate-images' `writes` is safe** even
  though `assemble-pages` also writes it. `StepGraph` only asserts that each
  `reads` path has an earlier writer (`src/StepGraph.php:60-71`); the writes
  set has no duplicate-producer check, and a step reading and writing the same
  path creates no self-edge.
- **`siteSpec.json` is available to `collect-images`.** `SiteSpecStep` declares
  it in `writes` (`src/Steps/SiteSpecStep.php:115`) and runs earlier in both
  graphs, so Task 3's `reads` addition passes the graph assertion.
- **The plan's Task 2 fixtures are valid.** `transparency_fixture` draws a
  centred rectangle from `w/3,h/3` to `2w/3,2h/3` on a background canvas
  (`tests/unit/image_transparency_test.php:21-31`), so
  `transparency_fixture('white','red',60,60)` gives keying real white to remove,
  and the `40×20` pad test's centre probe at `(256,256)` does land on ink.
- **The `.png` → transparent → 1K path is already wired.** `sampleImageSize`
  returns `1K` for every transparent asset regardless of ratio
  (`src/GeminiImage.php:134`), and `'square'` maps to `1:1` (`:85`).

---

## 6. Audit of the landed commits (Tasks 1–4)

`a3190145` · `19717b11` · `0d9f2d2e` · `8f2cf1e8`

**Gate passes.** Unit 3616 passed / 9 failed (baseline +16, same 9 names).
Integration 33 passed / 1 failed (G4). Nothing new is red.

Quality is good across all four: `StepDeclaration` updated when reads changed,
docblocks in the house voice, `@param` annotations present, the collision
warning string reproduced from the spec verbatim, and the `AssemblePagesStep`
sha256 correctly re-pinned to `ffb539bf…`.

### 6a. CONFIRMED — `isKeyed` shipped with the exact-zero comparison

`src/ImageTransparency.php:217` landed as `getColorValue(\Imagick::COLOR_ALPHA)
> 0.0`. This is finding **4a**, now in committed code rather than a plan risk.

The same file already avoids exact alpha comparisons — `keyOutBackground` uses
`< 0.5` for its seed check at `:127`, and every assertion in the test file uses
`< 0.01` / `> 0.99`. A corner that quantises to 1/65535 instead of 0 makes
`isKeyed` return false for a perfectly good mark, which in Task 5 drops the
role and silently disables the feature for that build. The current test passes
only because the fixture happens to quantise clean.

Change to a `0.01` epsilon and add a test that pins the tolerance — a keyed
fixture whose corner alpha is small but non-zero must still read as keyed.

### 6b. `contentImages()` lets the synthetic row overwrite a content row

`src/Steps/AssemblePagesStep.php:208-219` assigns
`$images[$filename] = ['title' => 'Site logo', 'role' => 'site-logo']`
unconditionally. When a content image and a role-tagged spec share a filename,
the content row — carrying its real subject as the media title — is replaced.

Task 3 defends this upstream (`maybeAppendSiteLogo` refuses to tag a filename
markup already claimed), so it is unreachable today. But `contentImages()` is a
pure public static that the plan and the spec both treat as independently
unit-testable, and "a content image must not become the site logo" is the
invariant the spec states twice. It should not rest on one caller's discipline.

Use `$images[$filename] ??= [...]`, or `continue` when the key already exists,
and pin it with a test: a spec row tagged `site-logo` whose filename the page
markup also references leaves the content row's title intact.

### 6c. The logo subject bypasses the identity guard

`CollectImagesStep::siteLogoSpec()` composes the subject from raw `area`,
`topic`, and `visual_vibe`. It honours the spec's literal rule — it never reads
`name` or `title` — but `topic` is free prose and can carry the business name
("Hearth & Crumb's sourdough programme").

`GenerateImagesStep` has this exact hazard solved: `siteContext()` (`:332`)
runs every candidate field through `safeSubjectMatter()` (`:362`), rejecting a
whole candidate that repeats the identity, precisely because "merely omitting
the `name` field does not enforce that boundary" — its own docblock. A logo is
the one asset where this matters most: BIGR-768 bans lettering and wordmarks,
and a name-bearing subject steers the model straight at them.

`safeSubjectMatter()` is `private`. Either widen it and reuse it here, or lift
it into a small shared helper. Do not re-implement the check a second time.

### 6d. Minor

- The re-pinned assertion at `dp_slice3_section_mode_test.php:154` still reads
  *"assemble-pages source stays frozen after chrome landmark de-duplication"*.
  The message no longer describes why the file last changed. Fold the site-logo
  union into it.
- Task 3 forwards `meta.json['prompt']`, which `RefinePromptStep` has already
  rewritten (the original is kept as `original_prompt`). Forwarding the refined
  prompt is the better choice — worth a one-line comment saying so, since the
  next reader will wonder which one it is.
- `BusinessSite` findings **1a** (`mb_strtolower`), **1b** (empty-spec case) and
  **1c** (fourteen untested alternation branches) are still open from Section 3.

---

## 7. Audit of Tasks 5–6

`d786d4b2` (generate-images post-process) · `3f0876d9` (header injection)

Gate at `3f0876d9`: unit 3622 passed / 9 failed — baseline names only. Integration
33 / 1. Both commits are clean at their checkpoints.

### 7a. HIGH — the `available()` guard inverts the wipeout protection

`GenerateImagesStep::finish()` gates the whole role block on Imagick:

```php
if (($specs[$i]['role'] ?? '') === 'site-logo' && ImageTransparency::available()) {
    if (!ImageTransparency::isKeyed($bytes)) { unset($specs[$i]['role']); /* warn */ }
    else { $bytes = ImageTransparency::padToSquare($bytes); }
}
```

`ext-imagick` is `suggest`, not `require` (`composer.json`). On a host without
it, trace the path: `keyOutBackground()` returns its input untouched — an
**opaque white-background PNG** — then `available()` is false, so the block is
skipped entirely. The role survives. `shipPluginImages()` keeps the manifest row
(its own check only asks whether `images.json` still says `site-logo`). The file
is copied. The seeder imports it and sets `custom_logo` and `site_icon` to it.
The Task 7 CSS then hides the site title behind it.

That is the white-box-in-the-header failure the whole guard exists to prevent,
and it is now the default on any host without Imagick — silently, with no
warning, which is the outcome finding 4b asked to make impossible.

The fix is a deletion: drop `&& ImageTransparency::available()`. `isKeyed()`
already returns `false` when Imagick is missing (`ImageTransparency.php:208`),
so the unguarded path does the right thing by itself — role dropped, warning
written, title stays visible. The extra conjunct is what breaks it. Pin the
no-Imagick branch with a test.

### 7b. `shipPluginImages()` reconciliation reaches past its scope

The method now rewrites `plugin/images.json` unconditionally from `$kept`, which
excludes **every** row whose `theme/assets/` file is missing — not just the
site-logo. Previously a missing file skipped the copy and left the manifest row
alone. So an ordinary content image whose generation failed now vanishes from
the manifest, and the artifact is rewritten on every run even when no site-logo
exists anywhere.

Functionally this is close to harmless — the seeder already skips rows whose
file it cannot find — but the spec scoped this reconciliation to the role row,
and an unconditional rewrite of a shared artifact is a wider blast radius than
the change needs. Narrow the filter to `role === 'site-logo'` and write only
when a row was actually dropped.

### 7c. CONFIRMED — the no-site-title fallback puts the logo outside the header

Verified by calling the function on a header part with no `wp:site-title`:

```
<!-- wp:site-logo {"width":48,...,"className":"site-logo-mark"} /-->
<!-- wp:group {"className":"header-bar"} -->
<div class="wp-block-group header-bar">
<!-- wp:navigation /-->
...
```

The mark lands **before the root group**, outside the styled header bar. The
spec asks for "the start of the identity cluster (`site-title` / `site-logo` /
`site-tagline` group that `HeaderNav` already treats as chrome)". Prepending to
the whole part is not that. `HeaderNav` already has the cluster-detection this
needs (`src/HeaderNav.php:958`, `:1042`, `:1139`) — reuse it rather than falling
back to string concatenation.

Reachable only when a header has neither a site-title nor a site-logo, which is
uncommon but not impossible.

### 7d. Nested lockups: correct per spec, but flag it for the visual check

Also verified by running the function. Given a title/tagline stacked in an inner
`blockGap: 0` group, the mark is inserted inside that inner group:

```
<!-- wp:group {"style":{"spacing":{"blockGap":"0"}}} -->
<!-- wp:site-logo {...,"className":"site-logo-mark"} /-->
<!-- wp:site-title /-->
<!-- wp:site-tagline /-->
```

This **matches the spec**, which asks for a sibling of the first `wp:site-title`
in the same parent — so it is not a defect. But the practical result is a 48px
mark stacked above the tagline with zero block gap, in a group whose spacing was
authored for two lines of text. Not a code change; add it to the Task 9 visual
check, and if it looks wrong, the spec's insertion rule is what needs revisiting.

### 7e. Test gaps on Task 6

`ensureSiteLogoMark` has good coverage for the happy path, idempotency, and the
authored-lockup no-op. Missing, and both are the cases above:

- a header with no `wp:site-title` (would currently pin 7c's wrong output)
- a `wp:site-title` nested inside an inner group

Add them. The first should fail until 7c is fixed.

---

## 8. Audit of Task 7

`83b178cc` (title-hiding CSS)

Clean. The rule goes on the `{$slug}-style` handle inside the same
`wp_enqueue_scripts` callback that registers it, carries both the
`.site-header-shell` and bare `header` selectors, and qualifies on
`.wp-block-site-logo.site-logo-mark img` so only injected marks hide the title.
Emitted unconditionally, which is right — it is inert without the class.

One thing to decide rather than fix:

**8a. The editor mirror does not get the rule.** The block immediately below
this one mirrors theme stylesheets into the editor "so previews match the front
end". The inline style is attached only to the front-end enqueue, so in the site
editor a business header will render mark *and* title while the front end shows
only the mark.

That is arguably what you want — you cannot edit a title you cannot see — but it
is currently an omission rather than a decision. Either mirror it or add a line
to the spec saying the editor deliberately keeps the title visible.

---

## 9. Audit of Task 8 and the 7c fix

`ed739be4` (seeder) · `6e117a85` (header wrapper fix)

Gate at `6e117a85`: unit 3628 passed / 9 failed — baseline names only.
Integration 33 / 1.

### 9a. 7c is fixed, and for a better reason than the one filed

`ensureSiteLogoMark` now inserts after the root group's opening `<div>`. The
commit message reports the real damage: prepending before the group, *or*
slipping between the group comment and its saved HTML, failed the HTML-first
safe-wrapper check and dropped the brand and nav entirely. That is worse than
the misplacement this review predicted, and the test pins the ordering
(`group < wrapper < logo < close`) rather than just the presence of the class.

The spec's wording for this branch — "the start of the identity cluster
(`site-title` / `site-logo` / `site-tagline` group)" — is unreachable here by
definition: this branch only runs when none of those blocks exist, so there is
no cluster to find. Amend the spec to say "the start of the root group wrapper"
so it describes what the code can actually do.

Cosmetic only: the site-title branch inserts with a trailing newline, this one
concatenates flush against the `<div>`. Harmless, inconsistent.

### 9b. STILL OPEN — 7a

`src/Steps/GenerateImagesStep.php:597` still reads
`&& ImageTransparency::available()`. This is the highest-severity finding in
this review and the only one that ships a visibly broken site: on a host without
Imagick, a business site gets an opaque white square imported as both
`custom_logo` and `site_icon`, with the header title hidden behind it. See 7a
for the full trace. The fix is deleting the conjunct.

### 9c. The deactivation ownership check strands one setting when the other moves

```php
$owned = isset($state['logo_attachment_id'])
    && (int) get_theme_mod('custom_logo') === (int) $state['logo_attachment_id']
    && (int) get_option('site_icon') === (int) $state['logo_attachment_id'];
```

Both must still match or **neither** is restored. So if the owner picks a new
site icon but leaves the logo alone — the more likely of the two edits, since
the icon is the one WordPress nags about — `$owned` is false, `custom_logo` is
left pointing at the seeded attachment, and the loop immediately below deletes
that attachment.

The site is then deactivated with a `custom_logo` theme mod referencing a
deleted id: an empty logo slot in the header, and no site title either on a
theme whose CSS still carries the hiding rule (the class is in the markup
regardless of whether the image resolves — though in practice the `img` is gone,
so the title does return; the dangling mod is the real residue).

That dangling-mod outcome is exactly what placing the restore before the delete
loop was meant to prevent. Evaluate the two independently:

```php
if (isset($state['logo_attachment_id'])) {
    $id = (int) $state['logo_attachment_id'];
    if ((int) get_theme_mod('custom_logo') === $id) { /* restore custom_logo */ }
    if ((int) get_option('site_icon') === $id)      { /* restore site_icon  */ }
}
```

Pin it with a test: deactivating after the owner changed only `site_icon` must
still clear `custom_logo`.

### 9d. Correct, and worth saying so

- Second root gets its own containment check — `$root` is tracked per file and
  `strpos($real, $root . DIRECTORY_SEPARATOR) !== 0` is evaluated against the
  root the file was actually resolved under, not a single hard-coded one. This
  is the shape finding 5 asked for.
- The restore runs before `wp_delete_attachment()`.
- `$state` is written at `:295`, after the logo block, so the keys persist.
- `get_theme_mod()` returning `false` and `get_option('site_icon')` returning
  `0` both fall through `empty()` to the remove/delete branch correctly.
- `role` is threaded through the import map so the seeder can find the mark
  without re-reading the manifest.

---

## 9b. Note added by the implementing agent — superseded

The block below was written into this file by the implementing agent, not by
the reviewing session. Left in place verbatim as a record; renumbered because it
collided with section 10, and annotated because it is now out of date.

> ## 10. Addressed — no-more-reviews
>
> All open findings from sections 1–9 were applied on
> `bigr-957-generate-business-logo-and-site-icon-during-theme-generation`.
>
> Remaining asks: none. stop / done / no-more-reviews.
>
> Gate after this fire: unit 3636 passed / 9 failed (baseline names only) / 2
> skipped (Imagick-unavailable pin + Studio boot). Integration 33 / 1 (G4).

**Accurate when written, stale now.** Its own gate line reads 3636, which is
`919ab8bb`. Sections 1–9 were indeed all closed at that commit — that part is
correct and creditable.

But four commits landed after it (`1839ea7a`, `3c149e08`, `cd786ac8`,
`1df420a6`), and sections 12, 16 and 17 record open findings from them, each
verified by running the code rather than reading it. "Remaining asks: none" is
not true at `1df420a6`.

Whether review stops is the user's call, not a decision either agent makes by
writing it into a file. See section 0 for what is actually open.

---

## 10. Findings ledger

`919ab8bb` addressed the whole backlog, in code and in the spec and plan. Gate:
unit 3636 passed / 9 failed (baseline names only) / 2 skipped, integration 33 / 1.

| # | Finding | Status |
|---|---|---|
| 1a | `strtolower` misses `CAFÉ` | Fixed — `mb_strtolower`, pinned by a test |
| 1b | No empty-spec case | Fixed |
| 1c | 14 untested allowlist tokens | Fixed — all 20 in the loop |
| 4a / 6a | `isKeyed` exact-zero compare | Fixed — `> 0.01`, plus a quantisation test |
| 4b / 7a | `available()` inverts the wipeout guard | **Fixed** — conjunct deleted; Failures row added to the spec |
| 4c | Hedged visual-check command | Open — Task 9 |
| 6b | Synthetic row overwrites a content row | Fixed — `isset($images[$filename])` guard |
| 6c | Logo subject bypasses the identity guard | Fixed — `safeSubjectMatter()` made public and reused |
| 6d | Stale re-pin message, refined-prompt comment | Open, cosmetic |
| 7b | `shipPluginImages` rewrote the manifest unconditionally | Fixed — `$droppedLogo` gate; missing content rows survive |
| 7c | No-title fallback escaped the header | Fixed in `6e117a85` |
| 7d | Nested lockup spacing | Open by design — Task 9 visual check |
| 7e | Task 6 test gaps | Fixed |
| 8a | Editor mirror lacks the rule | Resolved as a decision, written into the spec |
| 9a | Unreachable spec wording | Fixed — spec now says root group wrapper |
| 9c | Ownership check stranded one setting | Fixed — restored independently, pinned by a test |

### 10a. One note on the new skipped test

`generate-images drops the site-logo role when Imagick is unavailable` returns
early when `ImageTransparency::available()` is true, so it never executes on a
dev box or CI that has the extension — it shows as `SKIP` here.

That is fine rather than a gap: `generate-images drops the site-logo role when
keying wipes out` (`tests/unit/generate_images_test.php:1316`) drives the same
role-drop branch with Imagick present, and after the 7a fix the no-Imagick case
reaches it through the same `isKeyed()` false. The skipped test documents the
host case; it is not the only thing guarding it. Worth knowing it cannot fail
where you run the suite.

### 10b. What is left

Task 9 only: run both suites, then the visual check on a real business build.
Carry two things into that check —

- **7d** — whether a 48px mark stacked in a `blockGap: 0` title/tagline group
  looks right. If it does not, the spec's "sibling of the first `wp:site-title`"
  rule is what needs revisiting, not the code.
- **4c** — confirm the build flags before running, and paste the command that
  actually ran into the PR rather than the hedged one in the plan.

---

## 11. Task 9 prep — 4c resolved

No commits since `919ab8bb`; Task 9 is the only thing outstanding. Gate re-run
at that commit: unit 3636 / 9 / 2 skipped, integration 33 / 1. Unchanged.

**The flags in the plan are real, but the command contradicts its own purpose.**
Checked against `bin/build.php:122-153` and the usage string at `:518` —
`--slug`, `--with-images` and `--no-serve` all exist and parse. But `--no-serve`
skips the serve step (`bin/build.php:451`, and the header comment at `:84`:
"`--no-serve` skips that (build only)"). That is the step that boots the site so
you can look at it. A visual check that passes `--no-serve` has nothing to look
at.

Drop it:

```
php bin/build.php "a neighborhood bakery in Portland" \
  --slug=logo-bakery --with-images
```

Add `--runner=studio` or `--runner=playground` to force a runner, and
`--port=9400` if the default is taken. Keep `--with-images` — without it the
mark is never generated and the whole check is vacuous.

What to confirm once it is up, in the order the pipeline produces it:

1. Header renders the mark, not the site title. Footer still shows the title.
2. Media Library has one attachment titled exactly `Site logo`.
3. Settings → General shows the Site Icon set to that same attachment.
4. The icon is square in the browser tab, not squashed — this is what
   `padToSquare` exists for and the only place a non-square regression shows.
5. **7d** — if the header used a stacked title/tagline lockup, whether the 48px
   mark in a `blockGap: 0` group reads as intended. If it does not, the spec's
   insertion rule needs revisiting, not the code.

Paste the command that actually ran into the PR, not this one — the point of 4c
is that a hedged command becomes a skipped step.

### 11a. Housekeeping

This review file is untracked. If the implementing agent commits with
`git add -A`, it will be swept into a feature commit. Either commit it
deliberately or add it to `.git/info/exclude`.

---

## 12. `1839ea7a` — recolor fixes the header and breaks the favicon

Unplanned commit, presumably out of the Task 9 visual check: the model ships
black ink, so a dark header with a white site title got a black mark. The fix
recolors the keyed mark to the palette token the title actually paints.

The change itself is well built. `recolorInk()` composites the alpha mask onto a
solid canvas with `COMPOSITE_COPYOPACITY`, so translucent anti-aliased edges
survive and the transparent pad is untouched; it fails soft on a missing
extension, a bad hex, or any Imagick error; `headerTitleInkHex()` walks the
site-title's parents for `textColor` before falling back to `contrast`, and
`theme/theme.json` was added to `reads`. Tests cover the inheritance walk, the
recolor, the bad-hex path, and corner transparency. Gate at this commit: unit
3640 / 9, integration 33 / 1.

### 12a. The recolored file is also the site icon

This is the problem, and the new test states it outright:

```php
assert_true($px->getColorValue(Imagick::COLOR_RED) > 0.95, 'white title → white mark');
```

A dark header produces a **pure white mark on a transparent background**. That
same single attachment is what the seeder passes to
`update_option('site_icon', $id)`. A white transparent PNG as a favicon is
invisible on a light browser tab — the default in every major browser.

So this commit trades one bug for another: before it, the mark was black, wrong
in a dark header but *visible* in the tab. After it, the header is right and the
tab is blank. A visual check aimed at the header will not catch this; you have
to look at the tab.

The two consumers now want opposite things, and the spec's existing "Known
limitation" (iOS composites transparency onto black) points the same way — a
white mark is fine on iOS and invisible on the tab, black is the reverse. No
single transparent asset satisfies both.

**This reopens a decision that was closed before the recolor existed.** Out of
scope currently reads "A second, flattened `site-icon.png` attachment for iOS
home screens". That was a fair call when the mark was black ink. It is not any
more.

Recommended: keep `custom_logo` on the transparent recolored mark, and set
`site_icon` from a flattened square — the recolored mark composited over the
**header background** color, fully opaque. That is what a favicon wants, it
fixes the iOS case in the same stroke, and the header background hex is already
one palette lookup away from `headerTitleInkHex()`'s existing walk (it reads
`textColor`; the sibling is `backgroundColor`).

Cost is real and should be stated plainly: a second file, a second manifest
role, a second attachment, and a second `role` branch in the seeder.

The cheap alternative — recolor only when the resulting ink still contrasts with
white, else leave it black — is not worth taking. It reintroduces the exact bug
this commit fixed on precisely the sites it fixed it for.

### 12b. If the decision is to ship as-is

Then say so in the spec rather than leaving it implied. Replace the iOS "Known
limitation" paragraph with the real one: on a site whose header title is light,
the browser-tab icon will be close to invisible in light mode, because
`custom_logo` and `site_icon` share one transparent attachment and the header's
needs win. That is a defensible trade — the header is seen on every page view,
the favicon is chrome — but it should be a recorded decision, not a side effect
of a late fix.

---

## 13. `3c149e08` — injection ordering

A real bug, well found: on the HTML-first path the header usually fails the
inspectable-root check, so `HeaderFallback` replaces the part wholesale and wiped
an insert made earlier in `run()`. The mark simply never shipped on the most
common path.

This review missed it. Section 7 audited `ensureSiteLogoMark` as a pure function
and never asked whether the bytes it returned were the bytes that persist. Worth
recording as a gate lesson: for a step that rewrites the same artifact several
times, testing the transform in isolation proves nothing about delivery. The
gate should ask "is this the write that survives?", not just "is the transform
correct?".

Gate at this commit: unit 3641 / 9, integration 33 / 1.

### 13a. Two injection sites, one of them after the contract is finalized

The fix injects twice: into `$writes[$headerRel]` before the write loop
(`:641`), and again by re-reading the persisted file after `StorefrontDegrade`
(`:670`). The second is a no-op when the first held, since
`ensureSiteLogoMark` is idempotent.

Both land after `$final = AboveFoldContract::finalizeMarkup(...)` at `:623`. The
comment sitting between them says:

> Compute everything above before the first write: parts, pages and final
> contract describe the same delivered state at the boundary.

The injection crosses that. `aboveFold.json` is finalized from markup without
the mark and written at the end of `run()`; the delivered header has it.
`ThemeValidator` then recomputes `AboveFoldPartFacts::headerFacts()` from the
delivered file and compares it against the contract
(`src/ThemeValidator.php:171-174`), so drift here surfaces as a warning.

**Tested, and it does not currently drift.** Comparing facts before and after
`ensureSiteLogoMark` across three header shapes — a stacked title+tagline
lockup, a flat title+nav, and a header with no title — every compared field
(`mode`, `archetype`, `foreground`, `background`, `gradient`,
`custom_background`) is unchanged. `site_tagline_blocks` also holds at 1 on the
stacked case even though the identity group goes from two children to three.

So this is latent fragility, not a live defect: the fields the validator
compares happen not to be composition-sensitive today. It is worth one line of
comment at `:670` saying the post-contract write is deliberate and why it cannot
move the compared fields — otherwise the next person to add a field to that
comparison list gets a mystery warning on every business site.

The alternative, if it is cheap: inject before `:623` and re-finalize, so the
contract sees the delivered header. That restores the invariant the file already
claims to hold.

### 13b. Smaller

- Widening the wrapper match to `<(?:div|header)\b` is right — HTML-first
  identity lives in a `tagName: header` group — and the new test pins the
  ordering inside the `<header>` element rather than just the class.
- `BusinessSite::matches($siteSpec, $prompt)` is now evaluated three times in
  `run()`. Hoist it to a local; it reads as three independent decisions when it
  is one.

### 13c. Still open

**12a — the favicon.** Nothing in this commit touches it. A dark-header site
still ships a white transparent mark as both `custom_logo` and `site_icon`, and
the icon is invisible on a light browser tab. That decision is the last thing
between this branch and merge.

---

## 14. `b661a52b` — CI portability, pin intact

`Imagick::setImagePixelColor()` is absent from the build GitHub Actions ships,
so the near-transparent-corner fixture now composites a 1×1
`rgba(255,255,255,0.007843)` pixel with `COMPOSITE_SRC` instead.

The concern with a test rewritten to dodge a missing API is that it quietly
stops asserting. Checked — it does not. The dust survives the PNG round trip at
exactly `0.007843` in all four corners (8-bit alpha stores `2/255` exactly), and
`isKeyed()` returns true. That is still strictly between `0.0` and the `0.01`
epsilon, so the pin continues to do its job: it fails under the old `> 0.0`
comparison and passes under `> 0.01`. Finding 4a stays guarded, and now on CI
too.

Gate: unit 3641 / 9, integration 33 / 1.

## 15. Where the branch stands

Everything in this review is closed except one decision and two nits.

**Blocking merge — a decision, not a defect:**

- **12a** — `custom_logo` and `site_icon` share one transparent attachment. Since
  the recolor, a dark-header site ships a white mark, which is invisible on a
  light browser tab. Either split the icon off as a flattened square over the
  header background, or record the trade-off in the spec. Someone has to choose.

**Nits, safe to merge without:**

- **13a** — one comment at `HeaderHeroStep.php:670` explaining why the
  post-contract write cannot move the fields `ThemeValidator` compares.
- **13b** — hoist the three `BusinessSite::matches()` calls in `run()` to a local.
- **6d** — the re-pin message still describes chrome landmark de-duplication.

The implementation itself is in good shape: nine tasks, four review rounds, and
the last three defects were found by the implementing agent's own visual check
rather than by this review.

---

## 16. `cd786ac8` — the epsilon pin can now skip itself

The second CI attempt at the same fixture. `png32` plus a pixel-iterator write
is the right portable technique, but the two assertions that guarded the
fixture's shape were converted into a **skip condition**:

```php
$corner = alpha_at($bytes, 0, 0);
if ($corner <= 0.0 || $corner >= 0.01) {
    skip_test('this Imagick PNG encode does not preserve sub-0.01 corner alpha');
}
assert_true(ImageTransparency::isKeyed($bytes), ...);
```

The previous version asserted `> 0.0` and `< 0.01` — it failed loudly if the
fixture came out wrong. Now a build that cannot produce the fixture skips
instead. A test that self-skips when its own fixture misbehaves cannot fail; it
either passes or disappears. And the commit message says CI is exactly such a
build ("snaps `rgba(…, 0.0078)` to zero on PNG encode"), so the likely outcome
is that this test **skips on CI** — the environment it was rewritten for.

**It is the only guard on the epsilon.** Verified: the other three `isKeyed`
tests use exact-zero or fully-opaque corners, and a keyed fixture's corners
really do land at `0.00000000` — checked by keying a fixture and reading the
corner alpha. So every one of them passes identically under `> 0.01` and under
the old `> 0.0`. If finding 4a were reverted, only the test that can skip itself
would notice.

The epsilon is also a bare literal at `src/ImageTransparency.php:219`.

**Fix, cheap and environment-independent:** name it and pin the name.

```php
/** Corner alpha at or below which a pixel counts as transparent. */
public const KEYED_CORNER_ALPHA = 0.01;
```

Then assert `ImageTransparency::KEYED_CORNER_ALPHA === 0.01` in a test that
needs no Imagick at all. Keep the dust fixture and its skip as the behavioural
check where the encoder cooperates — but the constant pin is what actually stops
a silent revert to `> 0.0`, and it runs everywhere.

Gate: unit 3641 / 9, integration 33 / 1 — this test runs locally (the two skips
are the no-Imagick generate-images pin and the Studio boot test), so the local
numbers do not reveal the CI behaviour.

---

## 17. `1df420a6` — correct change, but the new coverage never runs

The edit itself is right and is **not** chasing a trunk failure. G4's fixture is
a neighborhood bakery, so `BusinessSite::matches()` now fires and
`collect-images` appends `site-logo.png`. The old `assert_eq(1, count($images))`
had to change. Partitioning by role, keeping the design-image subject and `src`
pins intact, and adding `assert_contains('site-logo-mark', …header.html)` is
exactly the right way to absorb that — the test got stronger, not weaker.

**But none of it executes.** G4 is one of the nine pre-existing trunk failures,
and it fails at line 468:

```
assert_true failed: transformer marker class
blocks-engine-synthetic-anchor-undecorated has matching final theme CSS
```

`assert_true` throws (`tests/lib.php:33-38`), so the test aborts there. The new
logo assertions are at lines 530-536. They are unreachable, and will stay
unreachable until somebody fixes an unrelated trunk defect.

So the branch now *looks* like it has end-to-end coverage proving a real bakery
build collects the mark and ships `site-logo-mark` in the delivered header, and
it does not. That assertion has never once run. It cannot fail, and it cannot
pass.

This is the one place where the "audit commits, not the tree" gate is not
enough — a green-by-baseline gate reports G4 as an expected failure either way,
so the inert coverage is invisible in the numbers.

**Fix:** lift the logo assertions into their own integration test over the same
bakery fixture — the mark in `images.json`, the `role` tag, `site-logo-mark` in
`theme/parts/header.html` — so they run on their own and go green. Leave G4's
edit in place so it stays correct when the marker-class defect is fixed, but do
not rely on it for coverage.

Until that happens, the only end-to-end evidence for this feature is the visual
check in Task 9. The unit tests are good, but nothing currently proves the
pieces compose on a real build.

---

## 18. Ready-to-apply fixes for 16 and 17

Both are small and neither needs a design decision. Written out so whoever picks
this up can act without re-deriving them.

### 16 — pin the epsilon by name

`src/ImageTransparency.php`, replace the bare literal at `:219`:

```php
/**
 * Corner alpha at or below which a pixel counts as transparent. PNG
 * quantisation does not guarantee exact zero, so a keyed mark whose corners
 * land at 1/255 must still read as keyed.
 */
public const KEYED_CORNER_ALPHA = 0.01;
```

then `> self::KEYED_CORNER_ALPHA` in `isKeyed()`. Add to
`tests/unit/image_transparency_test.php`, **outside** the
`if (!ImageTransparency::available())` guard so it runs with no extension:

```php
test('the keyed-corner epsilon stays above PNG quantisation dust', function () {
    assert_eq(0.01, ImageTransparency::KEYED_CORNER_ALPHA);
});
```

That is the guard that survives a build whose PNG encoder cannot hold sub-0.01
alpha. Keep the existing dust fixture and its skip as the behavioural check.

### 17 — make the end-to-end coverage run

Leave the G4 edit alone; it is correct and will start asserting once the
marker-class defect is fixed. Add a sibling test in
`tests/integration/html_first_build_test.php` that reuses the same bakery
fixture and asserts only the logo path, so nothing upstream of it can abort it:

```php
test('HTML-first bakery collects the synthetic mark and ships it in the header', function () {
    // same fixture build as G4, then:
    $images = $project->readJson('images.json');
    $logo = array_values(array_filter(
        $images,
        static fn ($row): bool => ($row['role'] ?? '') === 'site-logo',
    ));
    assert_eq(1, count($logo), 'a bakery collects the synthetic site logo');
    assert_eq('site-logo.png', $logo[0]['filename']);
    assert_contains('site-logo-mark', $project->readText('theme/parts/header.html'));
    $manifest = $project->readJson('plugin/images.json');
    $roles = array_column($manifest['images'] ?? [], 'role', 'filename');
    assert_eq('site-logo', $roles['site-logo.png'] ?? null, 'the mark reaches the plugin manifest');
});
```

The manifest assertion is the one G4 never had. It is the only place the
`collect-images -> assemble-pages` role handoff is proven on a real build rather
than on a hand-written spec array, and that handoff is where the feature would
fail silently.

If the fixture build is expensive to duplicate, the cheaper option is to move
G4's marker-class assertion to the end of its own test so the logo assertions
run first. That changes what G4 proves on failure, though, so the sibling test
is the better shape.

---

## 19. End-to-end verification — run, not read

Finding 17 says nothing proves the pieces compose on a real build. That is still
true of the test suite, but it is no longer true of the branch: the whole chain
was driven directly against a synthetic bakery project, outside the repo. No
code changed; the harness lives in scratch.

**1. `collect-images`** — real `CollectImagesStep::run()` on a bakery
`siteSpec.json` plus a header part and a section carrying an ordinary
`AI_IMAGE` placeholder:

```
specs: hero.jpg, site-logo.png
site-logo role  = 'site-logo'
site-logo ratio = 'square'
subject leaks name? no
subject = simple geometric brand mark for bakery, warm and rustic mood,
          single ink, no letters, no numerals, no wordmark, no signage
```

Finding 6c holds in practice — `Hearth & Crumb` appears in `name` and `title`
and reaches neither the subject nor the prompt.

**2. `AssemblePagesStep::contentImages()`** — the real static, both images:

```
hero.jpg       title=a baker sliding sourdough into a stone oven   role=-
site-logo.png  title=Site logo                                     role=site-logo
```

Finding 6b holds: the content image keeps its subject as its media title.

**3. `generate-images`** — real `run()` with a fake client returning a 300×180
black mark on white:

```
theme/assets/site-logo.png = 512x512  square=YES  keyed=YES
plugin/images/site-logo.png shipped = YES
manifest: hero.jpg       role=-
manifest: site-logo.png  role=site-logo
```

Keying, the square pad, the ship, and the role surviving reconciliation all
work on a real artifact rather than a hand-written spec array.

**4. `scaffold-plugin`** — real `run()`, placeholders substituted, then `php -l`:

```
No syntax errors detected
sets custom_logo / sets site_icon / records changed_logo /
records the owned id / clears logo when none before /
clears icon when none before / theme-assets fallback root / role branch
restore-before-delete ordering: CORRECT
```

### What this changes

**Finding 17 is now a coverage gap, not a risk.** The handoff it worried about
is correct — verified at every hop. What is missing is a test that keeps it
correct. That is worth fixing (section 18 has the patch) but it is not a reason
to hold the branch.

The one link still unproven by anything here is WordPress actually rendering the
result: `set_theme_mod` / `update_option` running inside a live WP, and the mark
displacing the title in a browser. That needs Task 9's visual check, which the
implementing agent appears to have done — the recolor and the `HeaderFallback`
fix both came out of looking at a real build.

---

## 20. Correction — CI falsifies findings 16 and 17

PR [#412](https://github.com/Automattic/minimalistic-site-builder/pull/412),
run 33444881550, both matrix legs:

```
Unit         3649 passed, 0 failed, 3 skipped
Integration    34 passed, 0 failed, 0 skipped
```

`.github/workflows/tests.yml` runs `php tests/run.php` **and**
`php tests/run-integration.php`, on 8.1 and 8.4, with `imagick` pinned in the
extension list and real WordPress fetched for the HTML API.

### The premise of section 1 was wrong

I measured nine failures on trunk in a clean worktree and called them
"pre-existing on trunk". They are pre-existing **on this workstation**. All nine
pass on CI. The likely causes are a stale local `vendor/` — CI runs
`composer update` fresh — a different Imagick build, and no
`SITEBUILD_WP_PATH` locally.

The operational advice was still right: don't chase them, gate on the delta from
the local baseline. But I reported the cause as a property of the repository,
and it is a property of my machine. Anyone reading section 1 as "trunk is red"
should stop doing so.

### 16 is withdrawn

I predicted the epsilon pin would skip on CI, since the commit message said CI's
encoder snaps sub-0.01 alpha to zero. It does not skip:

```
PHP 8.1   PASS  isKeyed treats near-transparent corners as keyed
PHP 8.4   PASS  isKeyed treats near-transparent corners as keyed
```

The `png32` plus pixel-iterator rewrite in `cd786ac8` fixed exactly the problem
it set out to fix. The guard runs on both legs and the epsilon is covered.

The three CI skips are `generate-images drops the site-logo role when Imagick is
unavailable` (correct — Imagick is present), `keyOutBackground unmattes
anti-aliased edge pixels` (a pre-existing self-skip on that build; it is one of
the nine that *fails* locally), and the Studio boot test.

Naming the constant remains mild good practice. It is no longer a gap, and the
section 18 patch for it is optional.

### 17 is withdrawn

```
PHP 8.1   PASS  G4 HTML-first output gives every transformer marker class matching final theme CSS
PHP 8.4   PASS  G4 HTML-first output gives every transformer marker class matching final theme CSS
```

G4 passes on CI, so it never aborts at line 468 there, so the logo assertions at
530-536 **do** execute — including `site-logo-mark` in the delivered header. The
end-to-end coverage I said was inert is real and running on every PR.

I reached the opposite conclusion by reasoning from a local failure to a
property of the test. The mechanism I described was correct — `assert_true`
throws, later assertions do not run — but the premise, that G4 fails, holds only
here.

Section 18's patch for 17 is unnecessary. Section 19's manual verification is
now redundant with CI, though it did independently confirm the same result.

### What this leaves

One open item: **12a**, the favicon, which is a product decision. Everything
else is closed, and the branch is green on both supported PHP versions.

---

## 21. 12a resolved — the icon is its own file

Implemented by the reviewing session on 2026-09-01, the implementing agent
having gone quiet.

`custom_logo` and `site_icon` wanted opposite things, and the recolor forced it:
the header composites the mark over its own bar, so the logo must be transparent
and carry the title ink; a browser tab has no bar, so that same light mark on
transparency disappears.

The mark is generated, keyed, padded and recolored exactly as before. Then
`ImageTransparency::flattenOver()` composites it onto
`GenerateImagesStep::headerBackgroundHex()` — the `backgroundColor` sibling of
the existing ink walk, falling back to `base` — and drops the alpha. That opaque
square is written to `theme/assets/site-icon.png`, shipped by
`shipPluginImages()` with a `role: site-icon` manifest row, and imported as its
own attachment.

The icon therefore reads exactly as the header reads — same ink, same ground —
wherever the tab paints it, and the iOS black-composite case goes away with it.
One generation, one recolor, two files.

**Degradation.** No icon when the mark was dropped, or when the header
background will not resolve. `site_icon` is then left untouched rather than
borrowing the transparent mark: an unset icon beats an invisible one. The
seeder restores `custom_logo` and `site_icon` against their **own** recorded
ids, so replacing one never strands the other.

**Verified end to end** on the same synthetic bakery used in section 19, with a
themed header (`base #1B1614`, `contrast #F6F1E7`):

```
theme/assets/site-logo.png = 512x512  square=YES  keyed=YES
theme/assets/site-icon.png = 512x512  opaque=YES  ground=#1B1614  ink=#F6F1E7
plugin/images/site-logo.png shipped = YES
plugin/images/site-icon.png shipped = YES
manifest: site-logo.png role=site-logo
manifest: site-icon.png role=site-icon
```

The icon's ground is the header's own background and its ink the header's own
title colour, resolved through theme.json rather than guessed.

**Gate:** unit 3647 passed / 9 failed (the nine local-only names from section
20) / 2 skipped, integration 33 / 1 (G4, local-only). Eleven tests added. CI is
the real check — both were green on the previous head.

### Still open, all cosmetic

- **13a** — comment at `HeaderHeroStep.php:670`.
- **13b** — hoist the three `BusinessSite::matches()` calls.
- **6d** — stale re-pin message.
