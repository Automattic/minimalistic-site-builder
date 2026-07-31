# Hero composition system: coding-agent handoff

**Audience:** a coding agent working in `/home/matias/dev/a8c/builder4`.

**Architectural baseline:** footer merge commit
`59a83e710d7b731df58b7fdcc738fba0af650ed1`
([`Generate distinctive, coordinated footer compositions (BIGR-740) (#166)`](https://github.com/Automattic/minimalistic-site-builder/pull/166)).
This handoff was finalized on trunk `3307b3d`; the intervening commit changes
only one demo prompt and does not alter the pipeline described below.

**Goal:** make generated front-page heroes consistently stronger, more varied,
more creative, and more coherent with their header, following section, design
direction, and imagery. Reuse the successful architecture from footer PR #166,
but do not reuse BIGR-738's aesthetic rewriting logic; that work produced a
visible quality regression and is explicitly out of scope.

This is an implementation brief, not a request to rediscover the problem. Read
the repository `AGENTS.md` before changing code, then treat the decisions and
acceptance criteria below as the working contract.

---

## 1. Outcome and design principle

The intended system is:

> compatibility-filtered, curated alternatives → one explicit assignment → a
> structured hero blueprint → a shared above-fold contract → deterministic
> mechanical safeguards → rendered evaluation and, only later, candidate
> selection

The footer work proved the first four useful primitives:

- A reviewed catalog owned by code.
- Selection happens outside the generation prompt.
- The model sees exactly one assigned recipe, not a menu of alternatives.
- Independently generated neighbors receive the same ownership and seam
  contract.
- Generated-content problems are fixed, degraded, and warned about without
  aborting the build.

Heroes need additional machinery because the first viewport is more sensitive
than a footer: recipe suitability, copy capacity, focal/safe regions, header
geometry, mobile transformation, media diversity, and rendered quality all
matter. Deterministic code should enforce objective contracts; it should not
flatten expressive markup in pursuit of a universal aesthetic.

The operating principle is:

> **Use determinism for assignment, contract fidelity, safety, and selection —
> not for homogenizing valid authored designs.**

---

## 2. Non-negotiable decisions

1. **Do not port or depend on BIGR-738.** Do not reuse a broad hero “repair”
   that rewrites a valid composition toward one preferred layout. This work must
   stand on its own.
2. **Do not put the entire recipe catalog in an LLM prompt.** PR #166 removed
   positive example lists after they caused convergence. Select in code and
   render only the selected recipe fragment.
3. **Do not merge header and hero generation into one LLM call.** Extend the
   existing shared-contract approach. `HeaderUnit` and the new `HeroUnit` stay
   stateless and independently executable.
4. **Do not select a hero using a bare hash over the entire catalog.** Filter
   for compatibility first; use stable selection only within the compatible
   pool. The demo harness may additionally balance assignments across a batch.
5. **Do not bind every recipe to one palette surface.** A recipe declares
   compatible surfaces; the page plan commits to one that serves the site’s
   design direction and the actual following section.
6. **Do not turn footer-local constraints into hero-global constraints.** There
   is no universal one-image, landscape-only, low-density, single-focal-point,
   or fit-text rule for heroes.
7. **Do not destructively regenerate or overwrite a valid hero to test an
   alternative.** Later candidate work must preserve all candidates and select
   a winner after rendering.
8. **Keep browser tooling outside the portable core.** Node, Chrome, and
   Playground remain development/evaluation conveniences. The default PHP
   library and stateless unit contracts must not acquire a browser dependency.
9. **Generated defects never abort the build.** Follow the repository’s
   fix/degrade/warn ladder. Only invalid code configuration such as an unknown
   forced archetype may fail loudly. Keep its warning discipline intact:
   semantics-preserving fixes go in the step report without a durable warning;
   harmless defects are left alone; removals, value-losing fallbacks, and
   residual actionable defects go to `warnings.json`.
10. **Limit the first implementation to the front-page hero.** Interior page
    openings keep the existing `SectionUnit` path until the homepage system
    passes live visual evaluation.
11. **No backwards-compatibility layer is required.** This is a green-field
    project; prefer one clean representation over legacy aliases or shims.

---

## 3. Current architecture to extend

Do not replace these working mechanisms:

- `DesignDirectionStep` persists `designDirection.json`. It currently carries
  a prose `hero_composition`, plus palette, type, image grade, canvas, motion,
  and signature device.
- `PagePlanStep` is the page-level art director. It assigns each section a
  generic `layout_archetype`, background, vertical density, and handoff, then
  repairs generated plan drift and writes `pages.json` plus durable warnings.
- `SectionsStep` adapts project state into stateless header, footer, and section
  unit inputs and sends all generation requests concurrently.
- `SectionsStep::headerMode()` and `headerContract()` already establish a
  two-sided stacked/overlay contract. `HeaderUnit` sees the hero brief; every
  hero-role `SectionUnit` sees the header contract.
- `HeaderHeroStep` is the deterministic backstop for overlay wiring, stacked
  cover height, navigation width, and header-title scale. It runs before block
  serialization and writes warnings for changed delivered values.
- `CollectImagesStep`, `GenerateImagesStep`, `CoverContrastStep`,
  `NormalizeLayoutStep`, `FixBlocksStep`, and `ValidateThemeStep` already own
  image manifests, generation, pixel contrast, mechanical layout repair,
  serialization, and advisory validation. Actual generation and cover-contrast
  sampling are opt-in in the demo flow (`--with-images`), not unconditional
  dependencies of the default portable graph.
- `bin/build-demos.php` already builds the six-prompt demo corpus with images
  and screenshots. `bin/screenshot/screenshot.js` already handles lazy-loaded
  images, but captures only one width per invocation and emits no geometry
  report.

The direct footer precedent is:

- `src/FooterComposition.php`
- `src/Units/FooterUnit.php`
- `prompts/footer-composition.md`
- `prompts/footer-compositions/*.md`
- `SectionsStep::footerArchetype()` and `footerNeighborContract()`

Reuse their catalog isolation, bilateral handoff, recipe-specific image gating,
idempotent repair, fallback, and warning patterns. Do not copy the footer’s
hash-only selector, fixed recipe-to-surface map, low-density rules, or minimal
fallback.

### What is the same as footer PR #166, and what is intentionally different

| Concern | Footer logic to retain | Hero-specific change |
|---|---|---|
| Catalog | Reviewed code-owned recipe metadata and isolated prompt fragments | Compatibility includes media count/mode, canvas adaptation, header mode, copy capacity, mobile transform, and plan projection |
| Assignment | Select outside the authoring prompt; show the model one recipe | Filter objective capabilities first; stable hash only within the valid pool; batch balancing is evaluator-only |
| Prompt isolation | Never expose the positive menu to the markup author | Blueprint also stays out of every non-front-page section/footer/style prompt |
| Neighbor coherence | Both sides receive one shared surface/ownership handoff | Hero contract is three-way: global header, front hero, and following section, and must account for every interior opening because the header is global |
| Surface | Footer composition can own a reviewed terminal surface | Hero recipe declares compatible surfaces; the delivered page plan chooses one and the contract reads it |
| Images | Gate image instructions and verify allowed counts | Support zero, one cover, one foreground, or two images; preserve orientation and physical focal/safe regions |
| Deterministic finish | Idempotent structural normalization plus warnings/fallback | Enforce only objective root/marker/header mechanics; do not normalize valid heroes toward one aesthetic |
| Fallback | Minimal deterministic chrome is acceptable at the page edge | First viewport needs topology-family and mode-aware header/hero fallbacks, with no sibling promotion or invented CTA/media |
| Evaluation | Markup tests prove the recipe wiring | Heroes additionally need rendered responsive geometry and blinded visual review; prompt tests alone cannot prove appeal |

---

## 4. Target data flow

```text
siteSpec.json + chosen design-direction seed + optional eval assignment
                                │
                                ▼
                HeroComposition compatibility filter
                                │
                 one assigned front-page recipe
                                │
                                ▼
    DesignDirectionStep expands the concept and fills hero_blueprint
                                │
                                ▼
            designDirection.json (persisted assignment/blueprint authority)
                         │                    │
                         ▼                    ▼
                  ThemeJsonStep          PagePlanStep
                                             │
                            reconciles the real first section with blueprint
                                             │
                                             ▼
                                         pages.json
                                             │
                AboveFoldContract resolves the generation contract:
             exact header mode/archetype, viewport budget, real surface,
             writing direction, seam and ownership split
                                             │
                              ┌──────────────┴──────────────┐
                              ▼                             ▼
                         HeaderUnit                     HeroUnit
                              └──────────────┬──────────────┘
                                             ▼
               SectionsStep delivery finalizer + aboveFold.json
                                             │
             SectionRhythm → CollectImages → NormalizeLayout
                                             │
                                             ▼
            HeaderHeroStep markup finalizer + objective backstop
                     + final aboveFold.json and matching parts
                                             │
                                             ▼
                         existing contrast/fix/assemble pipeline
                                             │
                                             ▼
                           external responsive evaluation harness
```

Remove the old free-form `hero_composition` field. `hero_blueprint` in
`designDirection.json` is the persisted recipe assignment and creative-axis
authority for the front-page hero. It is not the only authority in the system:
the code catalog owns each recipe's structural contract, the design-direction
narrative owns aesthetic intent, the page plan owns the content brief, real
surface, CTA availability, and neighboring sections, `HeroUnit` authors the
hero headline/body copy while preserving any plan-owned action label exactly,
and `aboveFold.json` owns facts derived after the complete page plan and
delivered markup exist.

Do not add a new LLM round-trip for the first implementation. Use the existing
two-stage design-direction flow: after `chooseSeed()` returns, select one
compatible recipe in code, render only that recipe into the expansion prompt,
and normalize the returned blueprint with the assignment as authoritative.

---

## 5. Hero composition catalog

Add `src/HeroComposition.php` as the single source of truth. Start with these eight
structural recipes; use short, isolated prompt fragments under
`prompts/hero-compositions/`.

| Recipe | Structural contract | Media | Generic plan projection | Mobile transform / fallback family |
|---|---|---|---|---|
| `cinematic-safe-zone` | Landscape cover with copy in an authored quiet region | one cover | `full-bleed-cover`; `image` (reviewed `contrast` fallback) | Initial default stacks copy below media / cover |
| `editorial-split` | Deliberately unequal copy/media columns | one foreground image | `asymmetric-split`; `base`, `tinted`, or `contrast` | Blueprint order / foreground-split |
| `framed-portrait` | Contained vertical media with negative space and offset type | one foreground image | `asymmetric-split`; `base`, `tinted`, or `contrast` | Preserve portrait then stack / foreground-split |
| `panorama-rail` | Wide visual field paired with one compact information rail | one wide foreground image | `mixed-width-editorial`; `base`, `tinted`, or `contrast` | Rail below image / foreground-split |
| `diptych-editorial` | Two related, unequal media frames and one restrained copy anchor | two images | `mixed-width-editorial`; `base`, `tinted`, or `contrast` | One ordered sequence / foreground-split |
| `typographic-poster` | Type, scale, whitespace, and token-built shape carry the stage | no image | `mixed-width-editorial`; `base`, `tinted`, or `contrast` | Reflow without generic centering / typographic |
| `focal-subject-stage` | One singular subject staged as the focal exhibit beside concise copy | one foreground image | `asymmetric-split`; `base`, `tinted`, or `contrast` | Keep subject prominent then stack / foreground-split |
| `layered-poster` | One content image plus controlled type/color layers and one clear copy zone | one cover plus block-built layers | `full-bleed-cover`; `image` or `contrast` | Flatten layers in authored order / cover |

Each catalog entry must own metadata, not just a prompt path:

- compatible `canvas` values (`full-bleed`, `framed`);
- allowed media modes and min/max image count;
- allowed backgrounds/surfaces;
- compatible header modes (`stacked`, `overlay`);
- copy capacity (`compact`, `standard`, `expanded`);
- allowed mobile transformations;
- the corresponding existing page-plan archetype/background constraints;
- a deterministic root hook `.hero-composition--<recipe>` for identity,
  auditing, and scoped responsive rules (the rule set may be empty);
- recipe prompt path.

All recipes must work with a stacked header. Overlay is an additional
capability, not a requirement. Interior pages are not routed through this
catalog in the first implementation.

To avoid circular ownership between recipe assignment and the later
design-direction expansion, every initial recipe must have reviewed
`full-bleed` and `framed` adaptations. Explicit canvas instructions can filter
the pool, but code must not rewrite the whole site's generated canvas merely to
preserve an arbitrary recipe. Future recipes that support only one canvas may
be added only when that compatibility is knowable from structured input before
selection.

Prompt fragments must describe structure, ownership, degrees of freedom,
responsive transformation, and forbidden failure modes. They must not contain
sample industries, headlines, visual subjects, CTA copy, or ornamental ideas.

---

## 6. Persisted hero blueprint

Replace `designDirection.json.hero_composition` with one structured
`hero_blueprint`. `DesignDirectionStep` receives the code-assigned recipe and
only that recipe’s fragment, then returns the creative parameters below as part
of the direction.

Do **not** include the blueprint in the general `DesignDirectionStep::format()`
or `readFor()` text. That shared text is injected into every section, footer,
page-styles, motion, and theme prompt; putting the recipe there would leak the
front-page topology into unrelated generations and make recipe isolation
untestable. Add structured/explicit accessors such as `heroBlueprintFor()` and
`formatHeroBlueprint()` instead. Pass the formatted blueprint separately only
to the front-page `PagePlanStep` request, the relevant `ThemeJsonStep` sizing
context, `HeaderUnit`, and `HeroUnit`.

The ordinary `format()`/`readFor()` **does** render the global
`signature_device` plus normalized `signature_device_slots`; header, ordinary
section, and footer authors need those bounded placement restrictions to avoid
duplicating the motif. This is global placement context, not hero-recipe
topology, and must remain available after blueprint isolation.

Persist the exact chosen seed separately as `concept_seed` for evaluation
provenance, but do not render it again into downstream prompts; the expanded
direction already embodies it.

Also replace implicit signature-device placement prose with a bounded sibling
field in `designDirection.json`:

```json
"signature_device_slots": ["hero", "body"]
```

Allowed unique slots are `header`, `hero`, `body`, `closing`, and `footer`,
with at most two selected. If `signature_device` is empty, normalize slots to
`[]`; if a non-empty device has missing/invalid slots, use `[]` and warn rather
than parsing placement from its prose or inventing one. The direction prompt
must author this list explicitly.

Label the separately formatted facts **Front-page hero blueprint (front page
only)**. Interior page-plan requests and general `SectionUnit` requests must
not receive that variable at all. They inherit the site’s visual language from
the ordinary design-direction narrative without seeing the recipe or topology.
Update the design-direction prompt so its general narrative does not restate
the selected hero topology either; removing the structured field is pointless
if the same recipe leaks back through prose.

The persisted normalized shape should be:

```json
{
  "version": 1,
  "recipe": "editorial-split",
  "media_mode": "foreground-image",
  "headline_register": "display",
  "text_anchor": "center-start",
  "headline_line_target": {
    "desktop": [1, 3],
    "mobile": [2, 5]
  },
  "focal_region": "end",
  "text_safe_region": "start",
  "height_profile": "standard",
  "cta_treatment": "prominent",
  "mobile_transformation": "stack-copy-first",
  "signature_device_use": "One bounded placement instruction, or an empty string."
}
```

Use bounded enums wherever the value drives code:

- `media_mode`: `none`, `cover-image`, `foreground-image`, `diptych`;
- `headline_register`: `restrained`, `display`, `poster`;
- `text_anchor`: `top-start`, `center-start`, `bottom-start`, `center`,
  `top-end`, `center-end`, `bottom-end`;
- `focal_region` / `text_safe_region`: `start`, `center`, `end`, `full`,
  with `none` additionally valid for `focal_region`;
- each line-target bound: integer `1..6`, with min not greater than max;
- `height_profile`: `compact`, `standard`, `immersive`;
- `cta_treatment`: `quiet`, `prominent`; this controls presentation only when
  the page plan supplies a real CTA/destination and is ignored otherwise. Do
  not use a blueprint value to suppress a valid planned action;
- `mobile_transformation`: `retain-media-overlay`, `stack-copy-first`,
  `stack-media-first`, `rail-below`, `flatten-layers`,
  `collapse-to-single-focus`.

Do **not** put `surface`, exact header mode/archetype, or the next-section
handoff in this model-authored object. The real page plan owns the surface and
neighbor; `AboveFoldContract` derives the exact relationship after all pages
are known. Persisting those values earlier would create competing sources of
truth.

Add one optional structured action to the delivered first-section plan rather
than asking the blueprint to decide whether a CTA exists:

```json
"primary_action": {
  "label": "View the menu",
  "intent": "Help visitors explore the current menu",
  "destination": "/menu/"
}
```

The value is either that object or `null`. Validate the destination against the
known page/anchor/contact destinations already available to the plan; an
invented or placeholder route degrades to `null` with a warning. `intent` is a
content brief that helps the hero author place the action. `label` is the short,
visitor-facing copy in the site's language and is authoritative for both
`HeroUnit` and deterministic fallback; neither may paraphrase it. Normalize it
as plain text, trim it, and require `1..80` Unicode grapheme clusters with no
markup or control characters. If any action field is invalid, normalize the
whole action to `null` and warn rather than synthesizing replacement copy.
`cta_treatment` only styles this action when the delivered plan contains a
valid one, so no layer is forced to fabricate a CTA.

Because `PagePlanStep::jsonSchema()` uses one section schema with
`additionalProperties:false`, add `primary_action` as a required-but-nullable
property on every generated section:

```json
{
  "anyOf": [
    { "type": "null" },
    {
      "type": "object",
      "additionalProperties": false,
      "required": ["label", "intent", "destination"],
      "properties": {
        "label": { "type": "string", "minLength": 1, "maxLength": 80 },
        "intent": { "type": "string", "minLength": 1 },
        "destination": { "type": "string", "minLength": 1 }
      }
    }
  ]
}
```

Normalize it to `null` everywhere except the front page's first section;
the prompt should require `null` there so this is a backstop rather than the
normal path. Ordinary section `content_notes` can still describe their own
links/actions.
Use two validation passes: validate known page paths and spec-backed external,
`mailto:`, or `tel:` destinations during per-page normalization, then validate
same/cross-page anchors after **all** page results and recovery paths have
produced the delivered page/slug set. Every page-plan fallback sets it to
`null`. The label and intent are validated in the first pass; the second pass
must preserve their bytes while rechecking the destination. Invalid values are
generated-content drift: warn and continue.

The recipe assignment is authoritative: if generated JSON returns a different
recipe, restore the assigned recipe and repair dependent enum fields from that
recipe’s defaults.

`signature_device_use` may only place the already selected global signature
device, and only when `signature_device_slots` contains `hero`. If the
direction has no signature device or hero slot, normalize this field to an
empty string. Never parse placement from free prose or synthesize a second
decorative concept here.

Line counts are composition targets, not permission to delete or truncate copy.
A later validator may report excess lines or prefer another preserved candidate;
it must not shorten user-authored content blindly.

Add normalized `siteSpec.json.writing_direction` (`ltr|rtl`) as the source of
truth for logical image regions. Derive it deterministically—do not ask the LLM
for a competing answer. Precedence is an optional validated caller
`meta.json.writing_direction`, then a reviewed pure
`WritingDirection::fromLanguage()` mapping for known RTL identifiers, then
`ltr` for unknown/missing languages. Expose `--writing-direction=ltr|rtl` in
the runners for explicit callers. `AboveFoldContract` copies the normalized
SiteSpec value; it does not re-infer direction from prose.

### Blueprint degradation rules

Implement normalization as a pure, fixed-point operation with tests:

1. Preserve every valid generated field semantically unchanged.
2. Replace invalid enum values with the assigned recipe’s compatible default.
3. Clamp and order numeric line-target bounds.
4. Use an empty string for a missing optional signature instruction; do not
   invent a decorative device deterministically.
5. Put semantics-preserving canonical repairs in the step/fixer report. When a
   fallback cannot preserve an authored field, record that delivered-value loss
   through `Project::addWarnings()` with artifact, field, authored value,
   delivered value, and disposition. Do not warn for harmless or fully repaired
   noise.
6. Validate cross-field compatibility, not just scalar enums: recipe ↔ media
   mode/image count; recipe ↔ mobile transformation; recipe ↔ height and
   headline register; text anchor ↔ safe region; cover focal region ↔ safe
   region; and foreground media ↔ cover-only fields. Repair the smallest
   conflicting field to the recipe default. Report a semantics-safe repair;
   warn only when the fallback discards a valid authored choice.
7. Resolve logical `start`/`end` to physical image coordinates from an explicit
   `ltr`/`rtl` writing direction in `AboveFoldContract`: for LTR, start/end map
   to left/right; for RTL they map to right/left. CSS remains logical. Image
   prompts and WordPress focal points receive the resolved physical side so
   their meaning cannot silently reverse.
8. If the generated blueprint is unusable, synthesize the assigned recipe’s
   complete default blueprint and continue. Preserve the rest of the generated
   direction.

Give `HeroComposition` a deterministic blueprint→plan projection. The homepage
first section’s generic `layout_archetype` is derived and locked from the
recipe; it is no longer an independent structural choice. The plan may choose
`background` only from that recipe’s allowed surfaces. Persist these delivered
projection fields in `pages.json`, and make later consumers such as
`imageLedHero()`, header-mode resolution, `SectionRhythm`, `HeroUnit`, and the
audit read that same projection. This is plan reconciliation before markup
exists, not a rewrite of delivered hero markup. Warnings name the page,
section, authored plan values, delivered values, and disposition.

Treat the reconciled homepage hero as locked during adjacency/variety repair.
If its recipe-compatible generic archetype now conflicts with the following
section, repair the following section, not the hero back away from its recipe.
The combined reconciliation and variety pass must reach a fixed point. Apply
that reconciliation after every plan path, including normal consumption,
generated-JSON fallback, `normalize()`, `recoverSections()`,
`acceptRepairedSections()`, and `fallbackSections()`. If adjacency repair
changes the following section, replace stale model-authored handoff prose with
a neutral factual handoff derived from the delivered assignments.

Thread the front-hero projection explicitly through those pure helpers (for
example as a small value object); do not make static recovery code read ambient
environment or `Project`. Breaking their signatures is acceptable in this
green-field repository and is safer than a hidden second reconciliation path.

An unknown `HERO_RECIPE`, or one incompatible with caller-owned structured
constraints known at preflight, is an operator/configuration error. A conflict
introduced only by later model output is generated-content drift: preserve the
assigned recipe where the catalog permits, otherwise remap/degrade with
requested/delivered warnings and continue. A fallible batch request follows the
same generated/external-content policy.

---

## 7. Selection and batch diversity

### Production selection

Do not infer explicit design constraints by parsing the user/refined prompt;
`siteSpec.json` intentionally does not own layout choices. Add an optional,
caller-owned structured input in `meta.json`:

```json
"design_constraints": {
  "hero_canvas": "framed",
  "allowed_hero_media_modes": ["none", "foreground-image"],
  "max_hero_images": 1,
  "hero_copy_capacity": "standard"
}
```

Each property is optional; canvas uses `full-bleed|framed`, media modes use the
blueprint enum, image count is `0..2`, and copy capacity uses the catalog enum.
An absent object means no explicit constraint. Invalid caller/operator values
fail preflight; model-authored site prose never populates this object. Runtime
image-generation availability is not a selector axis in the first version: the
core always authors/collects image specifications, while resolution may occur
later in a host.

Expose this through a typed/validated optional `SiteBuilder::createProject()`
argument and the explicit runner flags `--hero-canvas`,
`--hero-media-modes`, `--max-hero-images`, and `--hero-copy-capacity`;
`RefinePromptStep` must
preserve the object unchanged while rewriting only prompt prose. Hosts may also
seed the same shape directly. Do not claim free-form phrases such as “no
images” are enforced unless the caller captured them in this structure.

Add a pure selector that:

1. Runs after the design-direction seed has been selected and before that seed
   is expanded into the full direction.
2. Builds an objective compatibility context only from structured capabilities
   and explicit constraints available at this point: allowed/requested media,
   canvas, image count, copy capacity, and a caller/evaluation assignment.
3. Removes recipes whose objective requirement is absent. Initial recipes
   should be broadly applicable, so an unknown site type still leaves several
   valid choices; none may depend on code guessing an industry from prose.
4. Makes a stable choice within the compatible pool using normalized stable
   identifiers plus the chosen concept-seed bytes as a diversity seed. The
   seed may influence the hash but must not be semantically parsed in PHP.
   Identical inputs must produce the same assignment.
5. Treats an empty pool caused entirely by caller-owned constraints as a
   preflight configuration/catalog error. Only later generated/batch drift may
   use a stable compatible fallback with an actionable warning.

Do not build a subjective PHP “semantic quality” oracle from keyword scores
over open-ended `site_type`, `area`, prompt, or seed prose. That would make a
brittle selector look authoritative. If live evidence later shows capability
filtering is insufficient, design a small selector-model call explicitly; do
not silently grow heuristic keywords or expose the full recipe menu to the
markup author.

Structured caller design constraints outrank catalog distribution. Exclude a
recipe that cannot honor the supplied canvas, media treatment, or content
capacity; never normalize an explicit structured requirement away to keep a
preselected recipe. If direction/blueprint output drifts from that structure,
restore the caller value at the smallest field and record the successful repair
in the step report. If honoring it requires dropping another otherwise valid
authored field, warn with authored/delivered context for that loss.

Do not introduce cross-run mutable “recently used” state into the portable
library. Reproducibility matters more than hidden history.

Add `HERO_RECIPE` as a validated operator/eval override. The environment
override is an exact instruction: validate its name at preflight and fail on
incompatibility that is provable from caller-owned structured inputs at the
earliest boundary. Do not abort later because model-authored context drifted;
apply the generated-content policy above. Keep fallible batch requests separate, as
`meta.json.hero_assignment = {"source":"batch","requested_recipe":"..."}`.
If a coarse batch request proves incompatible with the normalized site spec,
remap it by the same stable compatible-pool selector (not a semantic “nearest”
heuristic), continue, and record requested recipe, delivered recipe, reason,
and disposition. These controls exist for
traceable assignments and input replay, not as normal creative decision-making
or a promise of byte-identical stochastic output.

### Demo-batch allocation

Batch balancing belongs in `bin/build-demos.php`, because individual builds
cannot see their siblings. Before spawning children, assign compatible
front-page recipes across the whole corpus and persist each request under
`meta.json.hero_assignment` before the child starts. The catalog must validate the
assignment again after `siteSpec.json` exists. Persist the manifest at the
explicit ignored path `projects/batches/<run-id>/manifest.json` (the whole
`projects/` tree is gitignored). The parent writes the initial roster before
spawning, each child writes only its own result file, and the parent atomically
finalizes the manifest after all children join; concurrent children must never
rewrite one shared JSON file. The final manifest contains:

- run id, start/end timestamps, git SHA, git dirty status, prompt source, and
  sanitized environment overrides (never credentials);
- provider plus effective per-step model and temperature, image model/settings,
  and actual selected design-direction seed;
- `demo_id` → actual project slug;
- requested assignment, source, delivered recipe, any remapping disposition,
  and normalized blueprint axes;
- image success/failure counts and warnings by step;
- screenshot/audit paths and status;
- for rendered evidence: Chrome, Playwright, Playground and WordPress versions,
  device scale factor, plus intended-font load/fallback status per viewport.

Expose this behavior through an explicit `--balance-heroes` option for the
first implementation. A normal single-site build continues to use production
selection; a demo batch uses the allocator only when the option is present.
The parent may use only explicit structured `design_constraints`/capability
fields on demo entries for its coarse filter; do not infer compatibility from
`site_type` prose. The child performs the authoritative check; acceptance
quotas use the actual persisted blueprints, never merely the parent’s requested
roster.

For the current six-site corpus, the allocator must guarantee from recipe
metadata:

- at least five distinct front-page recipes;
- at least three media modes;
- no recipe used more than twice.

The post-run report should additionally target at least three text-anchor
treatments, no one anchor on more than half the sites, and at least two headline
registers. These are LLM-authored blueprint axes, so they are observational
quality targets, not assertions the pre-build allocator can guarantee.
Report both stacked and overlay headers when delivered plans naturally make
both valid, but treat mode diversity as observational too: the allocator does
not own all page-opening surfaces and must never force overlay to meet a quota.

The allocator may optimize coverage within compatible choices. It must not
assign an incompatible recipe to satisfy a batch quota.

After all children finish, recompute quotas from the **delivered** assignments
in the final manifest. Child remapping can collapse a valid requested roster.
If delivered coverage misses the evaluation target, mark the run insufficient
and rebuild a compatible roster (or later add a site-spec-first two-stage
allocator); never report requested coverage as achieved.

This balanced batch demonstrates recipe execution breadth, not the natural
distribution of the production selector. Assess normal production distribution
separately with a pure, no-LLM selector simulation over a larger reviewed
fixture corpus and later unforced builds; do not claim that a forced six-site
roster proves organic variety.

Commit that lightweight corpus as `eval/hero-selector-fixtures.json`. Each row
contains only structured compatibility context and a stable id. Tests assert
eligible/ineligible recipes, deterministic repeatability, and aggregate
distribution bounds; they must **not** encode an expected aesthetic winner for
each site or smuggle prompt keywords into PHP.

---

## 8. Shared above-fold contract

Introduce one pure resolver rather than a new joint generation unit:

```php
AboveFoldContract::resolve(
    pages: $reconciledPages,
    blueprint: $normalizedBlueprint,
    canvas: $canvas,
    themeContext: $normalizedThemeTokens,
    siteContext: $stableSiteContext,
    footerContext: $assignedFooterContext,
    forcedHeaderArchetype: $validatedEnvOverride,
): array
```

It consumes **all** reconciled pages, identifies the `front` page and its first
and following sections, and returns both the mode and one exact archetype. It
must not accept a separately computed `headerMode`; that would retain two
decision paths. `siteContext` contains only stable structured facts needed by
the resolver, including page count and writing direction. Select the footer
recipe first and pass its exact archetype/surface as `footerContext`, so a
singleton hero and the footer share the same lower-edge contract.

`themeContext` supplies the resolved `base`/`contrast` tokens and hex values.
Do not equate the name `contrast` with “dark”: dark-base themes can invert the
pair. Overlay resolution must persist one exact foreground/protection token
pair with verified text contrast and require every opening to expose that
protection at the top edge; otherwise choose stacked.

Persist the normalized result as a two-phase `aboveFold.json`; this is one
contract whose delivery facts become more precise, not two competing decision
paths. `SectionsStep` writes the `delivery` phase and adds the artifact to its
declaration. After `SectionRhythmStep`, `CollectImagesStep`, and
`NormalizeLayoutStep` have touched the parts, `HeaderHeroStep` upgrades the same
artifact to `final`. Validation, inspection, assembly-time consumers, and the
browser audit read the final artifact instead of independently recomputing mode
or archetype.

`SectionsStep::run()` resolves an initial value once in memory before rendering
any unit request. After generated-result normalization, fallback, and pruning,
call the pure
`AboveFoldContract::finalizeDelivery($initial, $deliveredPages, $deliveredPartFacts)`
and atomically persist a `phase:"delivery"` value with the delivered part set.
`deliveredPartFacts` is a normalized Project-free summary of fallback,
surface, action, and part-key support, not raw ambient I/O.

`HeaderHeroStep` then builds `actualPartFacts` with a pure markup inspector and
calls
`AboveFoldContract::finalizeMarkup($delivery, $deliveredPages, $actualPartFacts)`.
Those facts include the actual header/root/mode markers, CTA label/destination,
direct cover/protection support, and SectionRhythm's persisted
`.site-build-section-rhythm-degraded-image` marker. This catches a contract
that was true when generation completed but became false after rhythm or layout
normalization. The step writes `phase:"final"` together with any matching
header/hero repair and warnings. `SectionRhythmStep` does not edit the contract
itself, and `HeaderHeroStep` does not aesthetically reselect it.

Both finalizers are fixed-point, Project-free transforms. They may only update
facts invalidated by delivered-unit loss or objective post-generation markup:

- overlay → stacked when a delivered opening no longer guarantees a readable
  overlay surface;
- `split-nav` → the documented safe `standard-row` stacked archetype when
  delivered page count can no longer support two navigation groups;
- the following-section/seam facts when a non-opening neighbor was removed;
- CTA presence/label/destination when fallback omitted an action, the model
  paraphrased its authoritative label, or pruning removed its delivered target;
- delivered page count/opening list, surface, and part-key facts changed by a
  reviewed fallback or page removal.

Each change warns with initial and delivered values and triggers the matching
mode-aware header repair/fallback. Do not pick a new “better-looking” archetype
in either finalizer. `requests()` remains read-only. A host that drives jobs
itself must perform the equivalent resolve/delivery-finalize/markup-finalize/
persist operations at the parent workflow boundary, on the same sides of its
rhythm/layout passes.

At the `SectionsStep` boundary, compute delivery pages, part bytes (including
any header fallback), delivery contract, and warnings in memory before the
first write, following the step's existing all-results-normalized commit
pattern. At the `HeaderHeroStep` boundary, compute all repaired part bytes, the
final contract, report, and warnings before the first write. Never persist a
contract phase that describes different header/page bytes.

Use a versioned machine shape, not only formatted prose. A representative
`aboveFold.json` is:

```json
{
  "version": 1,
  "phase": "final",
  "front_page": "home",
  "hero_section": "hero",
  "hero_part": "page-home--hero",
  "following_section": {
    "slug": "selected-work",
    "part": "page-home--selected-work",
    "layout_archetype": "offset-grid",
    "surface": "base"
  },
  "openings": [
    {
      "page": "home",
      "section": "hero",
      "part": "page-home--hero",
      "surface": "image",
      "top_protection_token": "contrast"
    }
  ],
  "recipe": "cinematic-safe-zone",
  "writing_direction": "ltr",
  "header": {
    "mode": "overlay",
    "archetype": "minimal-overlay",
    "foreground_token": "base",
    "protection_token": "contrast",
    "protection_orientation": "top-edge",
    "protect_top_edge": true,
    "safe_top_px": 80
  },
  "viewport": {
    "height_profile": "standard",
    "stacked_cover_max_vh": null
  },
  "regions": {
    "text_safe": { "logical": "start", "physical": "left" },
    "focal": { "logical": "end", "physical": "right" }
  },
  "mobile_transformation": "stack-copy-first",
  "primary_action": {
    "label": "View the menu",
    "intent": "Help visitors explore the current menu",
    "destination": "/menu/",
    "treatment": "prominent"
  },
  "seam": {
    "following_kind": "section",
    "surface": "base",
    "footer_archetype": "editorial-colophon",
    "footer_surface": "base"
  },
  "ownership": {
    "header": ["identity", "navigation"],
    "hero": ["proposition", "emotional-focus", "primary-action"],
    "following": ["detail", "proof"]
  },
  "degradations": []
}
```

`phase` is exactly `delivery|final`; only `HeaderHeroStep` accepts `delivery`,
and every later reader requires `final`. `following_section` and
`primary_action` are nullable. `degradations` contains
bounded rows with code, file/part, block path or JSON field path, authored
value, delivered value, and disposition; the same value-losing changes also go
to `warnings.json`. A singleton homepage uses
`following_section:null` and `seam.following_kind:"footer"` with the exact
assigned footer archetype/surface instead of inventing content below the hero.
`HeaderHeroStep` adds `aboveFold.json` to both `reads` and `writes`; every later
consumer adds it to `StepDeclaration::reads`. A malformed/missing delivery
artifact after `SectionsStep`, or malformed/missing final artifact after
`HeaderHeroStep`, is a corrupt required build artifact/programming invariant,
not model drift, and may be fatal.

The contract owns:

- exact header relation (`stacked` or `overlay`);
- exact header archetype;
- exact header foreground/protection tokens and top-edge orientation;
- first-viewport height budget;
- header-safe top region and whether image protection must reach the top edge;
- hero text anchor and logical plus physically resolved media safe/focal
  regions;
- headline scale/line targets and CTA presence/treatment (presence comes from
  the delivered page plan and a real destination, never from the blueprint);
- recipe-specific mobile transformation;
- surface/seam behavior;
- signature-device placement budget;
- content ownership:
  - header: identity and navigation;
  - hero: proposition, emotional focal point, and the primary CTA **when the
    page plan calls for one and supplies a valid destination**;
  - following section, when present: detailed proof, explanation, inventory,
    or process; otherwise the assigned footer owns only its existing persistent
    identity/utility/conversion remit;
  - do not intentionally repeat planned headline/facts/CTA/subject/signature
    material at either seam. The contract cannot prove final image-subject
    uniqueness across independent generations.

Resolve header compatibility once, preserving the current safety rules unless
live evidence justifies a deliberate change:

- overlay is available only when the front hero is image-led, canvas is not
  `framed`, the recipe permits overlay, one verified foreground/protection
  token pair works across the site, and **every** page opening has an `image`
  or matching protected solid surface that exposes that token at the top edge;
- overlay selects `minimal-overlay`; stacked mode excludes it;
- `split-nav` requires more than one page;
- `centered-masthead` remains excluded for an image-led opening;
- for the initial implementation, preserve the current conservative exclusion
  of `oversized-wordmark` whenever the front page has a hero; relaxing that is
  a later visual-evidence decision;
- otherwise choose one exact archetype stably from the compatible pool. Do not
  send a random two-archetype shortlist and ask the model to decide again.

Persist that assignment on the header root as
`.header-archetype--<id>` for observability, alongside the existing objective
overlay class. The marker proves which recipe was assigned, not that every
visual nuance was executed. Do not invent brittle DOM-count predicates for
ambiguous header compositions merely to turn fidelity into a green check;
exact archetype execution remains part of rendered human review in the first
phase, while mode, marker identity, overflow, wrapping, and usability are
automated.

`HEADER_ARCHETYPE` has highest operator precedence. An unknown value, or a
known value intrinsically incompatible with caller-owned facts already known at
preflight (for example `split-nav` on an explicitly one-page build), is an
operator configuration error. If incompatibility appears only because generated
canvas/plan output drifted, do not abort the paid-for build: apply a narrow safe
plan repair when semantics are preserved, otherwise deliver the documented
`standard-row` stacked relation with requested/delivered warning context. Make
that decision before the header/hero fan-out. Do not let `SectionsStep` honor
one mode while `HeaderHeroStep` derives another.

Implementation direction:

- `HeaderUnit` and the front-page `HeroUnit` receive the same canonical front
  contract serialization; neither derives a relation from prose.
- `HeaderUnit`, `HeaderHeroStep`, every opening prompt, and `HeaderFallback`
  use the persisted foreground/protection tokens exactly; none substitutes a
  hard-coded light/dark assumption.
- `HeroUnit` receives the homepage section/page context, normalized blueprint,
  same rendered contract, and one recipe fragment.
- `HeroUnit` receives a valid planned action as an exact label/destination pair.
  Its prompt must reproduce both exactly. The deterministic pass restores an
  unambiguously identifiable paraphrased label to the planned plain text; if it
  cannot isolate the intended control safely, it removes that action, nulls the
  delivered plan/contract fact, and warns instead of guessing.
- `HeroUnit` also retains the current `neighbors()` lower-edge input. On a
  singleton homepage, it receives the exact `footerNeighborContract()` for the
  already assigned footer recipe/surface; the contract never pretends a detail
  section exists.
- Interior page openings and all non-hero sections continue through
  `SectionUnit` and receive no front-page recipe text. They still receive the
  header-facing view of the same global contract so overlay/stacked behavior
  remains coordinated on every page.
- The full **hero** catalog must not appear in `header.md`, `section.md`,
  `page-plan.md`, or the design-direction prompt.
- Keep all inputs JSON-serializable so the wpcom stateless ability path remains
  viable.

Extend `finalSectionBrief()` to summarize a valid `primary_action` safely,
including its authoritative label and normalized destination as planning
context (never raw HTML). When the front hero is also the final section, this
lets a conversion-oriented footer know the hero already owns that action and
avoid repeating it. Preserve the footer PR's existing bilateral seam/ownership
contract.

Expose two focused renderers on the one value: a full `frontContract()` for the
global header/front hero pair and an `openingHeaderContract()` for interior
openings. Refactor `SectionsStep::headerMode()`, `headerAssignment()`, and
`headerContract()`, plus `HeaderHeroStep::expectedMode()`, into delegates or
remove them. There must be one resolver, one persisted result, and no duplicated
mode/archetype branch.

---

## 9. Recipe execution and responsive behavior

Add a dedicated stateless `HeroUnit` and `prompts/hero.md`. Do not keep growing
the general section prompt with hero-only rules: its card, grid, list, and
long-content guidance is irrelevant at the most quality-sensitive boundary and
can compete with the selected recipe.

`SectionUnit` is currently `final`, its input/cache helpers are private, and
`AbstractMarkupUnit::generate()` is final. Make the refactor explicit: extract
the genuinely shared page/section identity and input validation into an
`AbstractPageSectionUnit` or focused helper, then let `SectionUnit` and
`HeroUnit` share it. Do not duplicate `partKey()` or allow the two paths to
drift. `HeroUnit` must retain the existing
`page-<page>--<section>` request key and part filename so rhythm, images,
assembly, and portable hosts keep the same section identity.

Route using the delivered page position, not the mutable semantic role:
`page.front === true && section index === 0` goes to `HeroUnit`; every other
section goes to `SectionUnit`. Publish that pure routing helper for the wpcom
workflow so the HTTP fan-out cannot use a different rule.

The hero prompt has a different build prefix and cannot reuse
`SectionUnit`'s cached prefixes. In the first version, give `HeroUnit` no cached
prefixes and make `SectionsStep::warmSectionCache()` skip it and warm the first
ordinary `SectionUnit` request. If a site has no ordinary section, skip warming.
Add a regression test that the reusable general-section prefix is still the
one warmed.

Fix outcome delivery across stateless hosts as part of the unit refactor.
Today final `AbstractMarkupUnit::generate()` returns only markup and discards
the notes collected by `finish()`. Introduce one JSON-serializable Project-free
result envelope that keeps successful repairs separate from durable warnings,
for example:

```json
{
  "markup": "<!-- wp:group ... -->",
  "repairs": [
    { "code": "root-wrapped", "part": "page-home--hero", "disposition": "repaired" }
  ],
  "warnings": ["actionable unit warning with part/path/authored/delivered/disposition"]
}
```

Make `MarkupUnit::generate()` and finish/fallback paths return that envelope
(a small value object is fine). `SectionsStep` and the wpcom parent workflow
merge the `repairs` into the step report/narration and persist only `warnings`
through `Project::addWarnings()` or the host's equivalent. `HeroFallback`,
`PageOpeningFallback`, and `HeaderFallback` return the same shape. Do not add a
`Project` dependency to a unit, do not elevate a fully repaired issue into
`warnings.json`, and do not allow HTTP fan-out losses/removals to disappear
from it.

Each isolated recipe should define:

- required root/inner structure and allowed variations; every result is
  exactly one top-level `wp:group`;
- allowed core blocks;
- media count and aspect behavior;
- copy capacity and CTA allowance;
- surface and width behavior;
- one mobile transformation;
- exactly one stable root class `.hero-composition--<recipe>`;
- objective failure conditions that code can inspect safely.

Prefer core block responsiveness. Where a recipe needs behavior core blocks
cannot express, ship reviewed deterministic CSS for a documented recipe hook.
Do not ask `PageStylesStep` to improvise essential responsive behavior. A
missing generated CSS appendix must not be able to destroy the hero’s basic
layout.

For the initial catalog, put the small reviewed responsive rules in the static
stylesheet emitted by `ScaffoldThemeStep`, scoped under
`.hero-composition--<recipe>`. Shipping all eight bounded rule sets is simpler
and safer than adding a new step after planning; unused hooks are inert. Test
the desktop and mobile declarations in `tests/unit/scaffold_theme_test.php`.
`HeroUnit::finish()` must mechanically ensure the one assigned marker on the
single root group (for example through a focused `GeneratedMarkup` helper),
removing duplicate hero-recipe markers but preserving unrelated classes.

Choose the mobile transformation in the blueprint **before** markup generation
and give the recipe a reviewed DOM/CSS skeleton for it. CSS cannot inspect an
image's semantic quiet region and conditionally reparent cover copy. In
particular, do not implement “retain overlay if the safe region survives” as a
runtime heuristic; the initial cinematic default is a known stack transform,
while `retain-media-overlay` is permitted only when explicitly selected as a
complete authored variant.

The model still owns site-specific copy, image subject, precise ratios within
the recipe’s allowed range, typography staging, whitespace, and use of the
committed signature device. The recipe owns topology and responsive intent,
not a pixel-identical template.

Image rules are recipe-specific:

- Only recipes/media modes that require images receive image-generation
  instructions.
- Preserve portrait media in portrait-compatible recipes.
- The image prompt must carry the blueprint’s focal and text-safe regions.
- Multiple-image recipes use distinct filenames and distinct subjects; do not
  duplicate one generated image into several frames.
- `focal-subject-stage` requests an ordinary opaque content image compatible
  with the current JPG pipeline, never a transparent cutout.
- Do not add a generated-UI recipe in this phase. The pipeline has no reliable
  authentic screenshot source, and AI-generated UI commonly contains fake or
  garbled text.

The initial “media diversity” claim is about placement and count (`cover`,
foreground, diptych, none), not necessarily photography versus illustration,
3D, or abstraction. Those visual-medium choices remain governed by the
site-wide image grade. If a later phase adds `media_style`, reconcile it with
that grade explicitly rather than letting two prompts fight.

Respect `SectionRhythm`'s existing shape contract across every recipe:

- the one top-level `wp:group` is mandatory;
- a plan background of `image` must have exactly one editable direct
  `wp:cover`, or rhythm intentionally degrades it to solid-band spacing and
  warns;
- foreground-image and diptych recipes normally use a solid planned background
  unless they intentionally provide that one direct cover;
- test the full catalog through `SectionRhythm::rewrite()` so a recipe cannot
  look valid in isolation but fail at the page seam.

---

## 10. Deterministic validation boundaries

Extend `HeaderHeroStep` or replace it with an equivalently scoped deterministic
above-fold pass. Keep mutations narrow and idempotent.

Appropriate deterministic repairs include:

- safely wrapping complete, sanitized top-level blocks in the required one
  `wp:group` root while preserving their child bytes and adding the recipe
  marker; if a safe envelope cannot be built, use the family fallback;
- required overlay class and exact foreground/protection-token wiring on the
  header and every page opening to match the persisted contract;
- the existing stacked-header cover-height cap already enforced by
  `HeaderHeroStep`; do not add recipe-specific height mutation before rendered
  evidence demonstrates one exact safe boundary;
- missing or duplicate recipe hook when the generated single-root structure
  otherwise matches;
- existing mechanical navigation wrapping/title-scale safeguards.

Resolve impossible overlay combinations in `AboveFoldContract` before prompts,
not as an aesthetic post-generation rewrite. A later pass may still repair
markup that drifted from the already valid persisted mode. Keep existing
ownership boundaries: `GeneratedMarkup`, block normalization/fixing, and the
sanitizer own malformed, unsupported, or unsafe markup; existing link/safety
logic owns placeholder or dead CTAs. Do not turn `HeaderHeroStep` into a second
general validator.

A missing required media block, or an objective recipe-**internal** topology
miss inside a valid single root, should normally be preserved with an
actionable warning for later repair when it remains safe. A merely unmeasurable
mobile transformation or subjective recipe-fidelity concern belongs in the
evaluation report, not `warnings.json`. The root envelope is an objective
pipeline invariant: safely wrap it or fall back before `SectionRhythm`. Do not
convert valid internal composition into a different recipe merely because
another is easier to enforce. Likewise, do not invent focal metadata or change
a valid crop solely because a recipe has a default.

There is one objective exception: if persisted overlay mode depends on a
top-edge image/contrast surface and the delivered, parseable hero no longer
provides that readable support, use the reviewed cover-family fallback or
atomically finalize the header/contract to stacked. A parseable but absent
cover is not safe merely because block parsing succeeded. In stacked mode,
missing media can remain a recipe-fidelity warning when the rest is usable.

Do **not** deterministically “repair”:

- subjective balance, taste, whitespace, or visual drama;
- a valid asymmetric ratio into a standard ratio;
- portrait into landscape unless the assigned slot itself cannot accept it;
- a layered composition into a split merely because split is easier to test;
- headline wording or content length by deleting copy;
- one valid recipe into another after markup was authored.

When a safe repair is impossible, preserve the pre-transformation bytes only if
they remain safe, parseable, and renderable; otherwise remove the smallest
harmful unit or use the reviewed fallback, record an actionable warning, and
continue. Any step that can warn must declare `warnings.json` in `writes`. New
narration uses `Narrator::write()`, not `fwrite(STDERR, ...)` or direct `echo`;
convert the existing direct narration in `DesignDirectionStep` and
`HeaderHeroStep` while modifying those classes.

`ThemeValidator`/`ValidateThemeStep` must repeat the final above-fold checks
advisorially after later serialization and assembly, because a valid early part
can drift downstream. It reports residual contract/marker/root-shape problems
through warnings and never mutates or aborts an otherwise usable final theme.

There is one critical existing loss boundary to fix: today, if the generated
front hero is dropped, `SectionsStep` can promote the next ordinary content
section to positional `hero` after generation. That section never received the
hero recipe or header contract. Keep the original hero entry in `pages.json`
and deliver a fallback at that key; never prune it or silently relabel an
ordinary sibling.

Treat **every page opening** as contract-critical, not only the front hero. If
an interior opening fails, keep its plan entry and deliver a small Project-free
`PageOpeningFallback` (or a non-recipe branch of the same fallback helper) that
uses the real page title and the persisted global header relation. In overlay
mode it must provide the persisted protection-token surface under the header.
Do not prune an opening and promote a section that never received the
opening-header contract. Ordinary non-opening sections may still use the
existing smallest-unit prune policy;
`AboveFoldContract::finalizeDelivery()` then updates a changed front
neighbor/seam without aesthetic reselection.

Revalidate `primary_action` against those delivered pages/anchors during
delivery finalization, preserving the validated label and intent exactly. If
pruning made its destination dead, atomically set the plan and contract action
to `null` and remove only the matching CTA/link block from the hero, with
actionable authored/delivered warning context. Markup finalization repeats the
objective correspondence check after rhythm/layout repair; if the planned CTA
was omitted or removed, it atomically updates `pages.json`, the final contract,
and the matching part rather than claiming the action was delivered.
`HeaderHeroStep` therefore declares `pages.json` and `aboveFold.json` in both
its read/write transaction. Escalate to the smallest safely isolatable ancestor
or family fallback only if a dead control cannot be isolated. Do not leave a
known dead primary control for the advisory validator to discover later.

Implement two or three reviewed **topology-family** fallbacks (cover,
foreground/split, typographic), not eight second renderers. Expose a
Project-free `HeroFallback::render($input, $contract)` callable both from
`SectionsStep` and an HTTP ability wrapper; `AbstractMarkupUnit::generate()` is
final, so each orchestrator must catch transport/finish failure at its unit
boundary and invoke the same helper. The fallback:

- uses the real site/page title as safe visitor-facing text; it may use a
  clearly visitor-facing planned section title, but never prints raw `purpose`
  or `content_notes` planning prose;
- never invents a CTA, destination, image, or generated-media asset;
- may carry through an already validated `primary_action` label and destination
  exactly (escaped for markup, never paraphrased); if it omits that action,
  finalization changes delivered CTA presence to false and warns;
- keeps one top-level group and the assigned recipe marker, while the warning
  states that topology-family fallback rather than full recipe fidelity was
  delivered;
- preserves the planned entry and siblings byte-for-byte where possible;
- supplies the exact persisted overlay protection surface/foreground pair when
  the relation is overlay. If the relation cannot remain readable without the
  lost media, atomically degrade the persisted contract and header relation to
  stacked, repair or replace the header with the matching mode-aware fallback,
  and warn with authored/delivered mode and surface.

Extract a dedicated Project-free `HeaderFallback`/`fallbackHeader()` rather
than broadening the shared `fallbackChrome()` path in a way that risks footer
PR #166. An overlay fallback needs the correct class and foreground on a
guaranteed safe surface; a stacked fallback needs an opaque token surface. A
bare uncolored site-title group is not a safe overlay fallback. Leave the
tested footer fallback behavior unchanged.

---

## 11. Responsive render audit

Implement this as development/evaluation tooling after the composition system
is stable. Extend `bin/screenshot.php` and
`bin/screenshot/screenshot.js`, or add a focused `bin/evaluate-heroes.php` plus
Playwright helper.

The evaluator must be project-aware. It reads the project’s `pages.json`,
`designDirection.json`, and persisted `aboveFold.json` contract to find
the front page, opening-section anchor, normalized blueprint, CTA requirement,
recipe hook/media expectations, expected header mode, and assigned-archetype
marker. It must
also accept `--projects-root=<path>` so the candidate worktree’s evaluator can
audit control projects built in a separate baseline worktree. Extend the
Playground launcher with the same explicit root rather than assuming this
repository’s `projects/` directory.

Support two explicit input modes:

- **candidate/contract-aware:** requires the new blueprint and
  `aboveFold.json`, and runs every structural/contract check;
- **legacy control:** accepts an explicit `demo_id → project slug` roster and
  runs only facts observable in both versions (geometry, clipping, overflow,
  chrome shape, media load, and screenshots). Baseline `59a83e7` has no
  blueprint, recipe hook, structured action, concept seed, or `aboveFold.json`;
  mark those checks `not_applicable` and never fail the control merely because
  candidate-only artifacts are absent.

Capture these fixed viewports:

- `390 × 844`
- `768 × 1024`
- `1366 × 900`
- `1920 × 1080`

For every viewport:

1. Boot Playground once per site and capture the entire viewport matrix in one
   browser/server session.
2. Apply reduced-motion emulation and animation suppression before measuring;
   wait for `document.fonts.ready` with a bounded timeout.
3. Wait for image decoding while recording timeout, decode failure, and
   `naturalWidth === 0` separately; the current helper silently continues after
   these failures, which is insufficient for an audit.
4. Capture the first viewport (`fullPage:false`), the located hero element, and
   optionally the full page.
5. Write machine-readable measurements for header, hero, H1, CTA, and hero
   media bounding boxes.
6. Report, without mutating the site:
   - horizontal page overflow;
   - clipped or overflowing hero text;
   - unloaded/broken hero media;
   - header wrapping or unexpected overlap;
   - H1 and any plan-required primary CTA position relative to the declared
     viewport budget;
   - recipe/media/header-mode or assigned-marker mismatch; exact visual header
     archetype fidelity is a human-review item in this phase;
   - line-count target miss as an advisory measurement;
   - declared focal/safe metadata disagreeing with the contract's logical to
     physical coordinate mapping. Pixel-subject fidelity is not a DOM claim.

Record browser/runtime provenance with the audit: exact Chrome and Playwright
versions, Playground and WordPress versions, device scale factor, and whether
each intended font face actually loaded or fell back. `document.fonts.ready`
only says loading settled; it does not prove the requested face rendered.

Use explicit, unit-testable formulas over normalized DOM measurements:

- horizontal overflow: `documentElement.scrollWidth > clientWidth + 1`;
- element clipping: `scrollWidth > clientWidth + 1`,
  `scrollHeight > clientHeight + 1`, or a required rect outside its declared
  container/viewport budget;
- visual headline lines: unique top coordinates from text-range client rects,
  with computed `height / line-height` only as a documented fallback;
- broken image: incomplete load, decode error/timeout, or `naturalWidth === 0`;
- header wrapping: required header-row children occupy more than one visual row
  within a small pixel tolerance;
- first-viewport presence: recipe/contract-aware rect comparison, not a
  universal CTA-at-any-cost rule. A valid CTA below the fold is advisory unless
  it is clipped, obscured, unreachable, or contradicts an explicit contract
  promise.

Only clipping, horizontal overflow, broken media, duplicate/unusable chrome,
and genuinely unreachable or obscured required controls are hard audit
failures. Line-target misses, height-profile differences, safe-region fidelity,
and content appearing below the physical fold are advisory inputs to visual
review unless the element is actually clipped or inaccessible. The audit must
not become a numerical pretext for resizing or simplifying a valid expressive
hero.

DOM geometry cannot locate a photographed subject inside generated pixels, so
actual focal-subject/crop fidelity remains a human or future vision-review
criterion. Likewise, overlay-header readability over real image pixels is a
manual acceptance item unless this work adds explicit pixel sampling; do not
claim bounding-box checks prove it.

The audit may fail an evaluation command or PR quality gate. It must never abort
or erase a normal generated build, and it must not write generated screenshots
into tracked source directories. Put PNGs and raw audit JSON under each ignored
project’s `logs/` directory.

---

## 12. Candidate selection and aesthetic critique: gated follow-on

Do not begin here. First establish that the catalog, blueprint, shared contract,
and responsive audit improve the one-candidate path.

If the live batches still show meaningful execution variance, add candidate
selection as a separate host-level composition:

1. Generate two or three hero markup candidates under the **same** blueprint.
2. Generate image alternatives under distinct manifest entries and filenames;
   never regenerate the same file destructively.
3. Preserve each complete candidate through normalization and rendering.
4. Reject candidates only for objective hard failures.
5. Pairwise-rank the survivors against the blueprint for hierarchy, coherence,
   relevance, crop quality, creativity, and appeal.
6. Replace the current winner only when the alternative is demonstrably better.
7. If comparison is inconclusive or every alternative is worse, retain the
   original valid candidate and record any actionable residual defect.

A visual critic can advise selection; it must not emit a broad CSS/markup
rewrite. Limit any later revision loop to one recipe-aware attempt and compare
the revision against its preserved source before acceptance.

The current `Llm` interface is text-only. A screenshot critic therefore needs
a new optional multimodal interface or a host-owned reviewer; do not smuggle
image payloads into the existing text methods or make vision a requirement of
the portable core.

---

## 13. Implementation phases

### Phase 1 — catalog, assignment, and blueprint

- Add `HeroComposition` and isolated recipe prompt fragments.
- Add a pure blueprint normalizer/value helper.
- Replace `DesignDirectionStep`’s free-form `hero_composition` with the
  normalized `hero_blueprint`; update its prompt, JSON handling, formatter,
  fallback, warning behavior, and tests.
- Add compatibility-filtered stable selection and validated
  `HERO_RECIPE` operator override plus fallible `meta.json.hero_assignment`
  batch request.
- Add caller-owned `meta.json.design_constraints` and the structured selector
  fixture corpus; do not infer these facts from prose.
- Pass the blueprint into `ThemeJsonStep` and `PagePlanStep`; reconcile only
  the homepage first section’s generic plan fields with its recipe, and add the
  nullable/two-pass-validated `primary_action`.

### Phase 2 — shared contract and execution

- Add/refactor `AboveFoldContract`, including delivery and post-rhythm markup
  finalization, and persist the two phases of versioned `aboveFold.json`.
- Add `HeroUnit`/`prompts/hero.md` while preserving existing section keys;
  keep hero requests out of the ordinary section cache warm path.
- Route only the front-page hero through `HeroUnit`; pass the same normalized
  contract to it and `HeaderUnit`.
- Render only the assigned hero recipe.
- Gate image instructions by recipe/media mode.
- Add reviewed recipe CSS hooks only where core blocks are insufficient.
- Select one exact recipe-compatible header archetype.
- Extend the deterministic header/hero pass only with objective checks and
  warnings, plus mode-aware header/hero/interior-opening fallbacks.

### Phase 3 — batch evidence and responsive audit

- Make `build-demos.php` allocate compatible assignments across the corpus and
  write a durable batch manifest.
- Add the four-viewport hero audit and JSON metrics.
- Run the two image-enabled comparison batches described below.
- Adjust recipe contracts/metadata based on evidence; do not add aesthetic
  mutation rules to make screenshots pass.

### Phase 4 — optional multi-candidate selection

- Proceed only if Phase 3 evidence shows remaining quality variance that
  recipe/contract fixes cannot address.
- Keep this outside the default portable graph until host and cost behavior are
  explicitly agreed.

Do not put Phases 1–3 into one PR. At minimum:

- **Core composition PR:** Phases 1 and 2 (catalog, persisted blueprint,
  plan reconciliation, contract, `HeroUnit`, bounded fallback, portable-host
  routing, and evidence captured with the existing screenshot path).
- **Evaluation-tooling PR:** Phase 3 (pure batch allocator, manifests,
  project-root-aware four-viewport audit, metrics tests, and live comparison).
- **Candidate-selection PR:** Phase 4 only after a separate decision.

The core PR still needs live visual approval before merge, but it need not make
the new browser audit part of the portable implementation diff. If the core PR
becomes difficult to review, split foundation/planning from unit execution;
never hide all of this behind one oversized change.

---

## 14. Tests

Add or extend deterministic tests for:

### Catalog and selection

- Every catalog entry has a prompt, complete metadata, defaults, and a unique
  stable root hook.
- Selection is stable for identical context.
- Incompatible recipes are never selected.
- An empty pool from caller constraints fails preflight; later fallible batch/
  generated drift remaps and warns instead of aborting.
- The committed structured selector-fixture corpus exercises eligibility and
  aggregate distribution without asserting prose-derived aesthetic winners.
- The forced override is honored; an unknown override fails loudly.
- A pure `HeroBatchAllocator` meets recipe/media coverage quotas without
  violating compatibility; tests must not include the executable CLI file.
- Fallible batch requests remap incompatibilities and report requested value,
  delivered value, reason, and disposition; unknown/intrinsically incompatible
  operator values fail, while later generated-context drift degrades/warns.

### Blueprint

- Valid decoded fields are preserved exactly/semantically unchanged.
- Invalid enums and numeric targets reach a fixed point after one repair.
- Cross-field incompatibilities repair only the conflicting field and reach a
  fixed point; LTR/RTL logical regions resolve to the expected physical side.
- Signature-device slots normalize without prose parsing; hero use is empty
  when no global device exists or the bounded slots exclude `hero`.
- Generated recipe drift is restored to the assigned recipe.
- The selected recipe persists through direction normalization, formatting,
  fallback, and readback; the old prose field is gone.
- First-section layout/background fields remain compatible with the recipe.
- Invalid/unsafe/overlong `primary_action` labels and invalid or placeholder
  destinations degrade the whole action to `null` with a warning; a valid
  label survives byte-for-byte and controls delivered CTA copy while the
  action controls contract CTA presence.
- Actions on non-front/non-first sections normalize to `null`; early path/
  contact validation and later whole-plan anchor validation both reach a fixed
  point, and every plan fallback emits `primary_action:null`.
- Normal output and every `PagePlanStep` recovery/fallback path reconcile the
  homepage projection before persistence.
- Variety repair changes a conflicting following section rather than unlocking
  and rewriting the recipe-compatible homepage hero, and reaches a fixed point.
- Missing/unusable blueprints degrade to a valid default without losing the
  rest of the generated direction and write actionable warnings.
- Repair/fallback leaves unrelated pages and sections unchanged.

### Prompt isolation and contracts

- A hero request contains exactly the assigned recipe marker/fragment and no
  other recipe's unique marker/fragment; shared structural vocabulary is not a
  valid negative assertion.
- A non-hero request contains no hero recipe.
- Footer prompts contain no hero blueprint/recipe fragment, and existing
  footer fallback tests remain byte-for-byte unchanged.
- Header and hero prompts contain the same authoritative header relation,
  exact header archetype/foreground/protection tokens, viewport budget, safe
  region, ownership split, and seam facts. Resolver chooses stacked when no
  verified pair works for every opening.
- Header input remains self-contained and JSON-serializable.
- Hero input remains self-contained and JSON-serializable and retains the
  existing part key/filename.
- A valid action's label and destination appear exactly once in the hero input;
  finish/finalization preserves or restores that exact visitor-facing pair and
  never renders the planning-only intent as button copy.
- Every markup unit and fallback returns a JSON-serializable
  markup+repairs+warnings envelope; CLI batching and HTTP-style `generate()`
  preserve the same outcome rows at the parent boundary without putting
  successful repairs in `warnings.json`.
- Image instructions appear only for image-enabled modes.
- A `HEADER_ARCHETYPE` intrinsically incompatible with caller-owned preflight
  facts fails; incompatibility introduced only by generated plan/direction
  degrades to the documented safe relation with actionable warning. The
  persisted exact delivered mode/archetype is what `HeaderHeroStep` reads.
- Header finish adds exactly one assigned-archetype marker without presenting
  it as proof of visual topology; objective mode/classes remain independently
  checked.
- Interior openings get the global header-facing contract but no front-page
  blueprint or recipe fragment.
- A singleton front hero receives the exact assigned footer lower-edge
  contract, persists `following_section:null`, and exposes its valid action in
  `finalSectionBrief()` so the footer does not duplicate it.
- CLI and portable-host routing both choose `HeroUnit` only for
  `page.front && index === 0`.
- Cache warming skips the hero request and warms the first ordinary
  `SectionUnit` prefix, or safely skips when none exists.

### Markup and deterministic safeguards

- Every objective repair is idempotent.
- Each loss/degradation warning names file, block path, authored value,
  delivered value, and disposition.
- Failed unit-level transformation restores pre-transformation bytes and leaves
  siblings unchanged.
- A failed homepage hero produces a recipe-aware hero fallback; it never
  promotes an ordinary sibling that was generated without the contract.
- Every recipe result has one top-level `wp:group` and exactly one assigned
  root marker; complete safe multiple/non-group roots are wrapped idempotently
  without changing child bytes, while an unwrappable envelope uses fallback.
  Marker repair preserves unrelated classes.
- Every recipe passes `SectionRhythm::rewrite()`; image-background recipes have
  exactly one editable direct cover, while foreground/diptych solid variants
  retain their intended outer rhythm.
- Cover/foreground/typographic family fallbacks preserve the planned key, do
  not invent media/CTA/copy, and emit actionable warnings. Header fallbacks are
  readable in both stacked and overlay relations, including the atomic
  overlay-to-stacked degradation path.
- Failed interior openings also retain their key and a readable global-header
  fallback; they never promote an uncontracted sibling.
- Delivery and markup finalization update only the enumerated
  mode/archetype/page-count/CTA/neighbor facts, are independently idempotent,
  and never perform fresh aesthetic selection. `HeaderHeroStep` rejects a
  non-delivery input phase; later consumers reject a non-final phase.
- A post-generation `SectionRhythm` image degradation marker causes an unsafe
  overlay delivery contract to become stacked during markup finalization, with
  the header part, final artifact, and actionable warning committed atomically.
- When pruning invalidates `primary_action`, delivery finalization nulls
  plan/contract state and removes only the matching hero CTA block; when later
  markup omits it, markup finalization performs the equivalent transaction.
  Siblings and unrelated links remain byte-for-byte unchanged.
- Final `ThemeValidator` checks report downstream drift without mutating or
  aborting a usable theme.
- Existing header/hero, layout, contrast, image, serializer, and footer tests
  remain green.

Suggested files:

- `tests/unit/hero_composition_test.php`
- `tests/unit/hero_blueprint_test.php`
- `tests/unit/above_fold_contract_test.php`
- `tests/unit/hero_fallback_test.php`
- `tests/unit/header_fallback_test.php`
- `tests/unit/hero_batch_allocator_test.php`
- extend `tests/unit/site_spec_test.php`
- extend `tests/unit/design_direction_test.php`
- extend `tests/unit/page_plan_test.php`
- extend `tests/unit/sections_test.php`
- add `tests/unit/hero_unit_test.php`
- extend `tests/unit/section_unit_test.php`
- extend `tests/unit/header_hero_test.php`
- extend `tests/unit/step_model_test.php` for changed design-direction and
  sections fan-out fixtures/model routing
- extend `tests/unit/section_cache_contract_test.php`
- extend `tests/unit/block_markup_prompt_contract_test.php`
- extend `tests/unit/theme_json_test.php`
- extend `tests/unit/template_part_unit_test.php`
- extend `tests/unit/section_rhythm_test.php` and/or
  `tests/unit/section_rhythm_step_test.php`
- extend `tests/unit/validator_test.php`
- extend `tests/integration/pipeline_test.php`
- add Node tests for pure screenshot/audit metric functions if that tooling is
  introduced.

Run at minimum:

```bash
find src bin tests -name '*.php' -print0 \
  | xargs -0 -n1 -P4 php -l >/dev/null
php tests/run.php
php tests/run-integration.php
git diff --check
```

Keep browser smoke runs separate from portable-core tests. Put audit metric
functions in an importable JS module with no CLI side effects, add a real test
script to `bin/screenshot/package.json`, and, if it becomes a required gate,
run at least:

```bash
node --check bin/screenshot/screenshot.js
node --check bin/screenshot/hero-audit.js
npm test --workspace=bin/screenshot
```

Update CI if the Node test becomes mandatory; the current PHP job neither
installs screenshot dependencies nor runs screenshot tests.

---

## 15. Two-batch live evaluation

Use the same six prompts from `eval/theme-prompts.json`, the same provider and
model configuration, and generated images in both batches. This is a holistic
quality comparison plus recipe-coverage exercise, not a causal matched A/B
unless an exact replay fixture is added for every stochastic input.

The two locally present July 30 demo batches are exploratory evidence only:
they predate this baseline and must not be used as the matched acceptance
control.

### Batch A — control

- Build from baseline commit `59a83e7` in a separate clean worktree or use
  already captured evidence only if its commit/model/prompt metadata exactly
  matches.
- Do not include BIGR-738-derived output as the control.
- Force the same `DESIGN_DIRECTION_CHOICE` used for Batch B and record the
  actual selected seed from the LLM transcript when available; baseline does
  not persist `concept_seed`, so otherwise record it as `unknown`. This reduces
  avoidable drift but is not a perfectly deterministic A/B: seed generation,
  text generation, and images remain stochastic, and the PR must describe the
  evidence accordingly.

### Batch B — candidate

- Build from the implementation branch with compatible batch allocation
  enabled.
- Use the same prompts, provider/model, design-direction choice, and image
  settings as Batch A.
- Capture all four responsive viewports and persist the batch manifest/audit
  JSON.
- Treat this balanced roster as execution coverage across recipes. It does not
  demonstrate the natural production-selector distribution; report the pure
  unforced fixture simulation from section 7 separately.

Representative command in each worktree, adjusted for the chosen provider;
Batch B additionally enables the new allocator:

```bash
DESIGN_DIRECTION_CHOICE=1 php bin/build-demos.php \
  --with-images --parallel=2 --file=eval/theme-prompts.json

DESIGN_DIRECTION_CHOICE=1 php bin/build-demos.php \
  --with-images --parallel=2 --balance-heroes \
  --file=eval/theme-prompts.json
```

Run the **candidate worktree's** evaluator against both project roots so the
control receives the same viewport/metric logic even though baseline's
screenshot helper only supports one width. The exact CLI is an implementation
detail, but it must support an invocation equivalent to:

```bash
php bin/evaluate-heroes.php \
  --projects-root=/absolute/path/to/control-worktree/projects \
  --legacy-control \
  --roster=/absolute/path/to/control-roster.json

php bin/evaluate-heroes.php \
  --projects-root=/absolute/path/to/candidate-worktree/projects \
  --batch-id=<candidate-run-id>
```

Baseline has no batch id or manifest. Create `control-roster.json` as an
explicit `demo_id → project slug` map from the build output (or from a wrapper
that snapshots the projects directory before/after the run). The candidate
evaluator may synthesize a control manifest beside the comparison output from
that roster, project artifacts, and explicitly supplied run metadata. It must
label missing provenance/candidate-only checks as `unknown`/`not_applicable`
rather than inventing or failing them.

Do not commit generated PNGs. Keep them in project logs and post selected proof
in the PR/Linear discussion using the repository’s evidence-upload guidance.
Commit only lightweight prompt manifests, audit code, or reproducible fixtures.

### Required hard outcomes

- Every site builds successfully; generated hero defects degrade/warn rather
  than abort.
- No horizontal overflow at any audited viewport.
- No broken hero media.
- Neither batch has duplicate/unusable chrome or a clipped H1; candidate sites
  have no clipped/obscured plan-required primary CTA.
- Every candidate's objective header mode and assignment marker match
  `aboveFold.json`; exact archetype visual execution is scored by the blinded
  review, not claimed from the marker alone. Legacy controls have no equivalent
  persisted expected-value assertion.
- Candidate batch meets the recipe/media diversity targets in section 7.
- No candidate hero is replaced by a generic deterministic layout merely to
  satisfy an audit.

Human review must separately confirm overlay-header readability over the real
image, focal-subject/crop quality, and whether the H1/CTA placement serves the
declared first-viewport budget. Those are not automated hard claims unless the
implementation adds and validates a real pixel/vision measurement.

### Human/pairwise review rubric

Blind the pair labels during scoring. Score each control/candidate pair at
exactly `390 × 844` (mobile) and `1366 × 900` (desktop) for:

- composition strength and hierarchy;
- creativity and distinctiveness;
- relevance to the specific site;
- image subject, crop, and text-safe composition;
- header/hero and hero/next-section coherence;
- responsive transformation;
- typography and CTA clarity;
- overall visual appeal.

Score each blinded side independently on a `1..5` scale per dimension
(`1` clearly poor, `3` acceptable, `5` excellent). After seeing both sides at
one viewport, record a separate holistic result of `A`, `B`, or `tie` with a
reason; do not derive the preference mechanically from the average, because
one severe coherence failure can outweigh several small stylistic wins.

Persist reviewer identifier, review timestamp, blinded labels, viewport, each
dimension score, overall preference/tie, and concise rationale under the
ignored comparison run (for example
`projects/batches/<comparison-id>/review.json`); retain the unblinding map next
to it. Record ties honestly.

Preference on at least five of six sites at each review viewport is a useful
design-review target, not a single-run Definition-of-Done gate: stochastic
generation and a six-prompt balanced roster do not justify that causal claim.
The merge decision should combine absence of hard regressions, rubric results,
loss analysis, and repeated evidence where a recipe is unstable. When a
candidate loses, fix its recipe, metadata, or contract and regenerate; do not
add a universal rewrite that makes all heroes more alike.

---

## 16. Likely implementation touchpoints

Expected additions:

- `src/HeroComposition.php`
- `src/HeroBlueprint.php` or an equivalently focused pure normalizer/value
  helper
- `src/WritingDirection.php`
- `src/AboveFoldContract.php`
- `src/AboveFoldPartFacts.php` or an equivalently focused pure inspector for
  delivery/actual-markup facts
- `src/HeroFallback.php`
- `src/HeaderFallback.php` and, if not folded into `HeroFallback`, a focused
  `PageOpeningFallback.php`
- `src/HeroBatchAllocator.php` (evaluation-tooling PR)
- `src/Units/HeroUnit.php`
- `src/Units/AbstractPageSectionUnit.php` or an equivalently focused shared
  input/identity helper
- `prompts/hero.md`
- `prompts/hero-composition.md`
- `prompts/hero-compositions/*.md`
- `eval/hero-selector-fixtures.json`
- `bin/evaluate-heroes.php` and `bin/screenshot/hero-audit.js`
- focused unit tests listed above

Expected modifications:

- `src/SiteBuilder.php`, `bin/build.php`, and `bin/create.php` for validated
  caller design-constraint/writing-direction inputs
- `src/Steps/RefinePromptStep.php` to preserve those structured inputs
- `src/Steps/SiteSpecStep.php` for deterministically normalized
  `writing_direction`
- `src/Steps/DesignDirectionStep.php`, especially `fallbackDirection()`,
  `normalize()`, `format()`/`readFor()`, plus the new raw/focused blueprint
  accessor
- `prompts/design-direction.md`
- `prompts/theme-json.md`
- `src/Steps/PagePlanStep.php`, including normal result consumption,
  `normalize()`, `recoverSections()`, `acceptRepairedSections()`,
  `fallbackSections()`, generated-JSON fallback, and `repairVariety()`
- `prompts/page-plan.md`
- `src/Steps/SectionsStep.php`, including `jobs()`, the front-position routing
  helper, `heroBrief()`, `warmSectionCache()`, failure/pruning behavior,
  mode/assignment delegates, `StepDeclaration`, and the public helpers used by
  portable hosts
- `src/Units/SectionUnit.php` only as needed to extract shared page-section
  identity/input behavior; its section prompt and cache wire markers stay
  unchanged
- `src/Units/MarkupUnit.php` and `src/Units/AbstractMarkupUnit.php` for the
  JSON-serializable markup+repairs+warnings result envelope
- `src/Units/GeneratedMarkup.php` for a focused, idempotent root-marker helper
- `src/Units/HeaderUnit.php`
- `prompts/header.md`
- `src/Steps/HeaderHeroStep.php`, including post-rhythm markup finalization and
  transactional `pages.json`/`aboveFold.json`/part/warning writes
- `src/ThemeValidator.php` / `src/Steps/ValidateThemeStep.php` for final
  non-mutating residual checks
- `src/Steps/ScaffoldThemeStep.php` and its tests for reviewed recipe CSS when
  core blocks do not provide the required responsive behavior; essential
  recipe behavior must not depend on generated CSS
- `bin/build-demos.php`
- `bin/screenshot.php`
- `bin/screenshot/screenshot.js`
- `bin/inspect.php` if recipe/blueprint/contract observability is expected from
  the standard inspection report
- `README.md` for new demo/evaluation options
- `docs/site-build-portable-pipeline.md` for changed stateless unit inputs and
  artifacts
- `docs/composition-and-extension.md` for the fourth stateless markup unit and
  host routing/fallback contract

Update standalone/pipeline fixtures that provide one response per fan-out job;
they now need a `HeroUnit` response under the existing part key. Preserve and
extend `tests/unit/block_markup_prompt_contract_test.php` so the dedicated hero
still receives the shared frozen markup output contract.

While editing `DesignDirectionStep`, correct its stale docblocks that still say
four seeds; `prompts/design-direction-seeds.md` and the tests use exactly three.
Recipe assignment happens after seed selection, so the seed prompt itself must
not acquire the hero catalog or a preselected recipe.

Avoid broad changes to unrelated section recipes, footer behavior, generic
layout normalization, or the block serializer unless a narrowly demonstrated
hero invariant requires them.

---

## 17. PR and repository workflow

- Track the work in the Linear project named in `AGENTS.md`; do not create a
  GitHub issue.
- Include the Linear key in the branch and PR title.
- Put `Fixes BIGR-XXX` and the full Linear issue URL in the PR description.
- Move the issue to **In Progress** when implementation starts.
- Generated screenshots are evidence, not source; do not commit them.
- In the PR description, distinguish automated structural tests from live
  visual evidence. Do not claim screenshot-level quality from prompt-wiring or
  markup tests alone.

---

## 18. Definition of done

The work is complete when:

1. A code-owned hero catalog assigns one compatible recipe to the front-page
   hero before design-direction expansion.
2. `designDirection.json` persists a normalized, actionable
   `hero_blueprint`; the old free-form `hero_composition` source is removed.
3. `PagePlanStep` reconciles the actual homepage opening with that blueprint,
   and `aboveFold.json` derives all later-known facts and one exact compatible
   header mode/archetype from the real complete plan.
4. `HeaderUnit` and `HeroUnit` receive the same above-fold contract while
   remaining independent/stateless; interior openings retain the global
   header-facing contract without receiving the front-page recipe.
5. The model sees exactly one hero recipe and image instructions are correctly
   gated.
6. Objective repairs are bounded, idempotent, and content-preserving; successful
   fixes are reported, while only removals, value-losing fallbacks, and residual
   actionable defects produce durable warnings. Generated drift never aborts
   the build.
7. A failed generated homepage hero delivers a safe topology-family fallback,
   keeps the planned key, invents no media/CTA/planning-prose copy, and never
   silently promotes an ordinary sibling; failed interior openings receive the
   equivalent header-safe non-recipe fallback.
8. The demo harness proves balanced recipe execution breadth and records a
   finalized, provenance-complete manifest suitable for input replay; it does
   not claim to reproduce stochastic LLM/image bytes. Normal-selector
   distribution is reported separately and is not inferred from the forced
   roster.
9. Four-viewport auditing finds no hard geometry/media/header failures.
10. Blinded pairwise results, ties, losses, and provenance are recorded; the
    candidate introduces no hard regression and the merge decision explains
    the evidence without claiming a deterministic causal A/B or relying on
    BIGR-738/a universal aesthetic rewrite.
11. Unit and integration suites, syntax checks, and `git diff --check` pass.
12. Portable-pipeline documentation reflects the final artifact and unit input
    contracts.
