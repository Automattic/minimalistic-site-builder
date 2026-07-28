# theme.json scaffold — design

**Date:** 2026-07-28
**Branch:** `fix/scaffold-theme-json`
**Status:** implemented. Amended after merging `origin/trunk` — see "What changed during
implementation" at the end for the two places the shipped code departs from the plan
below.

## Problem

The `theme-json` step is the long pole of the `theme-json+page-plan` concurrent group. Across the 8 most recent builds it runs 79–103s, while the page plans beside it finish in 13–28s and then idle.

The step is **output-bound**. From `projects/teal-valley/logs/llms/06-0-theme-json.log`:

```
Model : claude-opus-5
Time  : 102.81s
Tokens: 6,927 in + 10,276 out = 17,203 total
```

Extended thinking is disabled for Opus 5 (`AnthropicClient::thinksByDefault()`), so that time is token emission at roughly 100 tok/s. The only lever on latency is emitting fewer tokens.

## What the output is spent on

Compact-JSON split, averaged over the 8 most recent builds (~8,811 B):

| part | share |
|---|---|
| `settings` (palette, gradients, shadows, typography, layout) | ~33% |
| `styles.elements` | ~35% |
| `styles.blocks` | ~27% |
| `styles.color` / `.typography` / `.spacing` | ~5% |

1. **`styles.blocks` is never requested.** The prompt doesn't mention it; the model volunteers it, inconsistently (no block appears in more than 10 of 32 builds; 22 builds have none). Nothing in `src/` reads it.
2. **Much of what *is* requested is dictated verbatim** — `styles.color.background` = base var, `styles.typography.fontFamily` = body var, the h1/h2/h3 family+size mapping. Because the prompt mandates exact slugs, each is a fixed `var:preset|…` string identical across sites.
3. **Some is load-bearing.** `ContrastFixStep` reads `styles.elements.link`, `.button`, `.heading` (`src/Steps/ContrastFixStep.php:84-324`) and rewrites failing colors. Those stay model-authored.

The dictated boundary is per-key, not per-element: `styles.elements.h2` mixes dictated wiring (fontFamily, fontSize) with model taste (fontWeight, lineHeight, letterSpacing). Only the former moves.

## Approach

The build supplies the mechanical parts; the model never writes them. The file on disk keeps its shape — WordPress receives a complete theme.json. Only authorship moves.

```
TODAY                            AFTER
 model ──► theme.json             model ──┐
 (8,811 B, everything)            (~6,800 B) ├─ merge ─► theme.json
                                  scaffold ──┘          (same shape)
                                  (const, static)
```

### Governing principle — the scaffold wires, it does not decorate

The scaffold makes **zero aesthetic choices**. It maps presets to roles and nothing else:

```
  SCAFFOLD (identical structure everywhere)   MODEL (free, per site)
  ─────────────────────────────────────────   ──────────────────────────
  h2      → heading family, section-title     quote border / rule / indent
  h4/h5/h6→ heading family, heading size      table striping, cell padding
  caption → caption size, secondary color     separator weight, treatment
  quote   → body family, lead size            every color, font, size VALUE
```

No borders, radii, striping, shadows or decorative treatments. Sites stay visually distinct because every value the scaffold references is a `var:preset|…` token the model chose. Anything requiring taste remains the model's call, and the model may override any scaffold path it wishes.

### Ownership

**Build-supplied, prompt-suppressed** (the prompt no longer asks for these):
- `styles.color` — background, text
- `styles.typography` — body family, body size, line-height
- `styles.elements.h4` / `h5` / `h6` / `caption`

**Build-supplied, model may override** (deep merge, model wins at the leaf):
- `styles.elements.h1` / `h2` / `h3` — `typography.fontFamily`, `typography.fontSize` only. **Not `color`:** `ContrastFixStep` models heading color as `elements.heading.color.text ?? styles.color.text` and never reads `h1`/`h2`/`h3`, so a scaffold color there would render one thing while the contrast pass reasoned about another. Headings inherit the global text color instead.
- `styles.blocks` — wiring only, for `core/quote`, `core/pullquote`, `core/table`, `core/separator`, `core/list`, `core/image`, `core/site-title`, `core/navigation`

**Model-authored, untouched:**
- all of `settings`
- `styles.elements.button` (background, text, padding, borderRadius, `:hover`)
- `styles.elements.link` (color, `:hover`)
- `styles.elements.heading` (lineHeight)
- `styles.elements.h1` / `h2` / `h3` — fontWeight, lineHeight, letterSpacing, textTransform, fontStyle

## Components

**1. `ThemeJsonStep::SCAFFOLD`** — a `private const` beside `SPACING_PROFILE`, following the precedent already in the file. No new file, no loader, no error paths, and nothing for wpcom's `bin/site-builder/sync-paths.sh` to pick up.

**2. `ThemeJsonStep::applyScaffold()`** — pure static, applied in `consume()` as a fourth post-processor beside `disableCoreDefaultPresets`, `normalizeSpacingSettings`, `normalizeRootPadding`. Needs a small named recursive-merge helper; no general deep-merge utility exists in `src/` to reuse (the four that exist are domain-specific).

**3. No request schema.** An earlier draft called for a "coarse" JSON schema that left `settings`, `styles.blocks` and the h1/h2/h3 internals open. That is not expressible: Anthropic structured outputs require `additionalProperties: false` on every object, and any other value is rejected. A schema permissive enough to keep the creative surface open cannot be written, so `requests()` sends none and the prompt alone carries the contract. (`additionalProperties: false` constrains which *keys* may appear, not their values — the constraint is on shape, not taste.)

**4. `prompts/theme-json.md` rewrite** — delete the lines dictating build-supplied values; add a short section naming what the build supplies and the blocks already wired, inviting `styles.blocks` decoration only where the design calls for it.

## theme-json MUST NOT fail the build

A hard requirement from review: no generated-content defect may abort the build.

This is delivered by trunk's BIGR-731 repair layer (see the amendment below), extended
with one repair this branch adds:

| omission | repair | note |
|---|---|---|
| a color slug | `designDirection.json`'s committed hex for the role, then a neutral readable default | `ContrastFixStep` re-verifies and rewrites downstream |
| a font slug | a system stack | |
| a `fontSizes` slug | the published scale (`0.875 / 1.125 / 1.375 / 1.75 / clamp(2.25rem,3vw,3rem) / clamp(3rem,7vw,6rem)`) | added here; the scaffold references these slugs |
| a malformed preset row | the row is dropped, with its authored value quoted in the warning | |
| a row missing only `name` | the authored value is kept; the name is synthesized from the slug | never discard a usable preset over a cosmetic field |
| the whole response unusable | `consumeGeneratedJsonFailure` writes a deterministic base theme | build proceeds; site is bland, not broken |

Repairs fire **only on a defect**, so a normal build never executes them and they cannot
flatten a design. Every repair is recorded in `warnings.json` naming what was wrong and
what was substituted — that record is what keeps a degraded build from shipping silently,
and it is what the future AI repair pass consumes.

Fatals are **not** eliminated outright. Two remain, both outside the generated-content
boundary: a missing model output (routed through `GeneratedJsonException` to the fallback
above) and a failure-routing invariant. Narrowing fatals to I/O, corrupt build inputs and
programming invariants is trunk's documented escalation ladder; a zero-throw rule would
contradict it.

## Expected outcome

Projected ~23% from byte counts. **Measured on `lucid-cedar` (2026-07-28), the first
build on the finished branch:**

| | output tokens | step time |
|---|---|---|
| baseline, mean of 32 builds | 8,192 | 72.8s |
| `lucid-cedar` | 3,712 | 57.5s |
| cut | **55%** | 21% |

Token count is the honest measure — emission is the bottleneck, and wall time carries
network and load noise. The 55% beat the projection because the saving did not come from
where the estimate assumed. The model still emits `h1`–`h6` entries despite the prompt
asking it not to; what it stopped emitting is the *contents* — `fontFamily` and `fontSize`
are gone, and only taste keys (`fontWeight`, `letterSpacing`, `lineHeight`) remain. The
suppression works per key, not per element, which turns out to be worth more than
displacing whole elements would have been.

One caveat on the run: `warm-maple`, built minutes earlier, is **not** valid evidence for
this branch — its theme-json step executed 17 seconds before the review-fix commit, so it
carries the pre-fix scaffold (h1–h3 colored `primary`). Only `lucid-cedar` exercises the
shipped code.

## Risks and integration points

- **`ContrastFixStep`** reads `styles.elements.link/button/heading`; the scaffold must never write those paths. Test it.
- **`ThemeValidator`** must stay green on scaffolded output; `settings.spacing` is untouched.
- **`PresetReferences`** scans theme.json's own strings as well as markup (`src/PresetReferences.php:88-92`), so it *would* catch a scaffold reference to an undeclared slug — but as a build-time failure. Repairing the fontSizes scale keeps the references valid so that never fires.
- **No schema means the prompt is the only contract.** `settings` had to stay permissive (the model invents gradient and shadow slugs), and no expressible schema does that — so drift shows up as a repair warning rather than a rejected response. That is the trade the repair layer above absorbs.
- **Vendored copy** — wpcom vendors this as `a8c/site-builder`. Choosing a const over a config file means no sync change; still worth a PR note.

## Testing

- `applyScaffold()` fills every build-supplied path on a minimal document.
- Deep merge: a model-emitted `core/quote` key wins; sibling scaffold keys survive.
- `applyScaffold()` never writes `styles.elements.button` / `.link` / `.heading`.
- The recursive merge helper: nested maps, scalars, list-vs-map values.
- `requests()` sends no `json_schema` — the request carries a prompt and nothing else.
- **A completely empty model response yields a theme passing `ThemeValidator` and `PresetReferences`** — the never-fail guarantee.
- Each default-fill emits its warning.
- End-to-end via `FakeLlm` with a slim response.
- The existing theme-json suite stays green.

## What changed during implementation

Two departures from the plan above, both recorded here rather than edited away.

**1. The coarse schema was impossible.** Component 3 originally specified a schema that
constrained `styles` while leaving `settings`, `styles.blocks` and the h1/h2/h3 internals
open. Anthropic structured outputs require `additionalProperties: false` on every object
and reject any other value, so no such schema exists. The request now sends none and the
prompt carries the whole contract. This also retired the eng-review decision that rested
on the schema being able to block paths.

**2. Trunk shipped the never-fail layer first.** `origin/trunk`'s BIGR-731 (#152) landed
`repairColors` / `repairFonts` / `consumeGeneratedJsonFailure` while this branch was
building its own `fillColors` / `fillFonts`. Trunk's is better on two counts this branch
did not plan for: it seeds a missing palette slug from `designDirection.json`'s committed
hex before reaching for a neutral default — so a repaired site still looks like the design
it asked for — and it strips malformed rows rather than only filling omissions.

The merge kept trunk's layer and dropped this branch's duplicate, including the
`DEFAULT_PALETTE` / `DEFAULT_FONT_FAMILIES` constants and the separate `theme-json.log`
(warnings now land in `warnings.json` only, which is what the future repair pass reads).
What this branch uniquely contributes survived: `FONT_SIZE_PROFILE` and `repairFontSizes`
— trunk repairs colors and fonts but not sizes, and the scaffold references font-size
slugs — plus the scaffold itself and the prompt rewrite.

`repairFontSizes` was rewritten into trunk's pure `[$theme, $warnings]` shape so all three
repairs read alike and none depends on `Project`. The scaffold is applied **after** them,
so the presets it references are guaranteed to exist.

## Out of scope

- wpcom port changes — vendored separately.
- Parallelism — that was `fix/batch-page-plan-repairs` (PR #153).
- Reducing `settings` output — those are the real design decisions.
- A re-ask loop for missing presets — adds latency to the step being optimised.
