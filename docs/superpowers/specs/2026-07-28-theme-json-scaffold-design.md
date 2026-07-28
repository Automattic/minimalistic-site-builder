# theme.json scaffold — design

**Date:** 2026-07-28
**Branch:** `fix/scaffold-theme-json`
**Status:** approved after eng plan review; ready for implementation

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

**Build-supplied, schema-blocked** (no slot in the request schema):
- `styles.color` — background, text
- `styles.typography` — body family, body size, line-height
- `styles.elements.h4` / `h5` / `h6` / `caption`

**Build-supplied, model may override** (deep merge, model wins at the leaf):
- `styles.elements.h1` / `h2` / `h3` — `typography.fontFamily`, `typography.fontSize`, `color`
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

**3. A coarse JSON schema** on the request. `requests()` currently sends none, which is why the model volunteers content freely. The schema blocks at the `styles` / `styles.elements` level only — `settings`, `styles.blocks`, and the h1/h2/h3 internals stay open, so nothing creative is constrained and legitimate keys like `textTransform` remain available. `PagePlanStep::jsonSchema()` is the pattern.

**4. `prompts/theme-json.md` rewrite** — delete the lines dictating build-supplied values; add a short section naming what the build supplies and the blocks already wired, inviting `styles.blocks` decoration only where the design calls for it.

## theme-json MUST NOT fail the build

A hard requirement from review. Today the step throws in five places (`:94, :238, :243, :253, :258`). All five become degrade-with-warning:

| omission | default | note |
|---|---|---|
| a `fontSizes` slug | the prompt's published scale (`0.875 / 1.125 / 1.375 / 1.75 / clamp(2.25rem,3vw,3rem) / clamp(3rem,7vw,6rem)`) | same move `SPACING_PROFILE` already makes |
| a color slug | fallback chain reusing the model's own colors: `accent`→`primary`, `secondary`→`contrast`, `primary`→`contrast` | `ContrastFixStep` re-verifies and rewrites downstream |
| a font slug | `heading`↔`body`; both missing → a documented pair | |
| whole palette / fontFamilies absent | documented default profile | |
| model output unusable | complete documented default theme | build proceeds; site is bland, not broken |

Defaults fire **only on omission**, so a normal build never executes them and they cannot flatten a design. Every fill emits a **visible warning** (build report + stderr) naming what was missing and what was substituted — this is what keeps a degraded build from shipping silently, and it replaces the loudness the exceptions used to provide.

## Expected outcome

| | model writes | est. step | cut |
|---|---|---|---|
| today | 8,811 B | ~90s | — |
| after (wiring-only scaffold) | ~6,771 B | ~69s | ~23% |

Revised down from an earlier ~40% estimate: that figure assumed a decorated scaffold displacing the model's block styling. Wiring-only deliberately gives that up, and since `styles.blocks` stays schema-open the model may still decorate. The saving now comes from `styles.color`, `styles.typography`, the h1/h2/h3 wiring, h4–h6 and caption.

These are arithmetic projections from byte counts assuming emission time scales with length. A real build is needed to confirm.

## Risks and integration points

- **`ContrastFixStep`** reads `styles.elements.link/button/heading`; the scaffold must never write those paths. Test it.
- **`ThemeValidator`** must stay green on scaffolded output; `settings.spacing` is untouched.
- **`PresetReferences`** scans markup, not theme.json, so it will not catch a scaffold reference to an undeclared slug — the fontSizes default above is what closes that hole.
- **Schema strictness** — `settings` must stay permissive (the model invents gradient and shadow slugs). Coarse-only, per review.
- **Vendored copy** — wpcom vendors this as `a8c/site-builder`. Choosing a const over a config file means no sync change; still worth a PR note.

## Testing

- `applyScaffold()` fills every build-supplied path on a minimal document.
- Deep merge: a model-emitted `core/quote` key wins; sibling scaffold keys survive.
- `applyScaffold()` never writes `styles.elements.button` / `.link` / `.heading`.
- The recursive merge helper: nested maps, scalars, list-vs-map values.
- Schema shape: blocked paths have no slot; `settings`, `styles.blocks`, h1/h2/h3 internals stay open.
- **A completely empty model response yields a theme passing `ThemeValidator` and `PresetReferences`** — the never-fail guarantee.
- Each default-fill emits its warning.
- End-to-end via `FakeLlm` with a slim response.
- The existing theme-json suite stays green.

## Out of scope

- wpcom port changes — vendored separately.
- Parallelism — that was `fix/batch-page-plan-repairs` (PR #153).
- Reducing `settings` output — those are the real design decisions.
- A re-ask loop for missing presets — adds latency to the step being optimised.
