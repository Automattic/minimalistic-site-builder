# theme.json scaffold — design

**Date:** 2026-07-28
**Branch:** `fix/scaffold-theme-json`
**Status:** approved, ready for implementation

## Problem

The `theme-json` step is the long pole of the `theme-json+page-plan` concurrent group. Measured across the 8 most recent builds it runs 79–103s, while the page plans beside it finish in 13–28s and then sit idle waiting for it.

The step is **output-bound**, not thinking-bound. From `projects/teal-valley/logs/llms/06-0-theme-json.log`:

```
Model        : claude-opus-5
Time         : 102.81s
Tokens       : 6,927 in + 10,276 out = 17,203 total
Stop reason  : end_turn
```

Extended thinking is explicitly disabled for Opus 5 (`AnthropicClient::thinksByDefault()`), so that time is pure token emission at roughly 100 tok/s. The only lever on latency is making the model emit fewer tokens.

## What the model is spending output on

Compact-JSON byte split, averaged over the 8 most recent builds (total ~8,811 B):

| part | share |
|---|---|
| `settings` (palette, gradients, shadows, typography, layout) | ~33% |
| `styles.elements` | ~35% |
| `styles.blocks` | ~27% |
| `styles.color` / `.typography` / `.spacing` | ~5% |

Three observations drive the design:

1. **`styles.blocks` is never requested.** `prompts/theme-json.md` does not mention it; the model volunteers it. It is also inconsistent — across 32 builds no single block appears in more than 10, and 22 builds have no `styles.blocks` at all. Nothing in the build reads it (verified: no consumer in `src/`); it only reaches WordPress at render time.

2. **Much of what *is* requested is dictated verbatim.** The prompt states exact values the model then transcribes back: `styles.color.background` = base var, `styles.color.text` = contrast var, `styles.typography.fontFamily` = body var, and the h1/h2/h3 family+size mapping (`h1` = display, `h2` = section-title, `h3` = heading). Because the prompt also mandates exact preset slugs, every one of these is a fixed `var:preset|…` string that does not vary by site.

3. **Some of it is genuinely load-bearing and must stay authored.** `ContrastFixStep` reads `styles.elements.link`, `.button` and `.heading` (`src/Steps/ContrastFixStep.php:84-324`) and rewrites colors that fail WCAG. Those must remain real model choices, not scaffold output.

Note that the "dictated" boundary is per-key, not per-element: `styles.elements.h2` mixes dictated wiring (fontFamily, fontSize) with genuine model taste (fontWeight, lineHeight, letterSpacing). The scaffold takes only the former.

## Approach

The build supplies the mechanical parts outright; the model never writes them. The file that lands on disk is unchanged in shape — WordPress still receives a complete theme.json. Only authorship moves.

```
TODAY                              AFTER
  model response ──► theme.json      model response ──┐
  (8,811 B, everything)              (~5,300–6,800 B) ├─ merge ─► theme.json
                                     scaffold file ───┘          (same shape)
                                     (static, checked in)
```

The scaffold is a static file requiring no templating, because every value it carries is a fixed `var:preset|…` reference that resolves against whatever palette and fonts the model chose.

### Ownership

**Build-supplied and schema-blocked** (the model structurally cannot emit these):

- `styles.color` — background, text
- `styles.typography` — body family, body size, line-height
- `styles.elements.h1` / `h2` / `h3` — `typography.fontFamily`, `typography.fontSize`, `color` only
- `styles.elements.h4` / `h5` / `h6` / `caption`

**Build-supplied but open to model additions** (per the approved precedence decision):

- `styles.blocks` — base set covering `core/quote`, `core/pullquote`, `core/table`, `core/separator`, `core/list`, `core/image`, `core/site-title`, `core/navigation`

**Model-authored, untouched:**

- all of `settings` (palette, gradients, shadow presets, typography scale, fontFamilies, layout)
- `styles.elements.button` — background, text, padding, borderRadius, `:hover`
- `styles.elements.link` — color, `:hover`
- `styles.elements.heading` — lineHeight
- `styles.elements.h1` / `h2` / `h3` — fontWeight, lineHeight, letterSpacing

### Precedence

Deep merge, **model wins at the leaf**. If the model emits `core/quote` with its own border treatment, its keys win and scaffold keys it did not mention survive. Schema-blocked paths cannot conflict, since the model cannot emit them.

## Components

**1. `config/theme-scaffold.json`** — new. Hand-written, checked in. Pure `var:preset|…` references. Sits beside `config/models.json`, read the same way (`ModelConfig` is the pattern to follow: read once, cache for the process, fail loud if unreadable or malformed).

**2. `ThemeJsonStep::applyScaffold()`** — new pure static, unit-testable, applied in `consume()` alongside the three post-processors already there (`disableCoreDefaultPresets`, `normalizeSpacingSettings`, `normalizeRootPadding`). Runs **before** `assertColors`/`assertFonts` so validation sees the final document.

**3. A JSON schema on the request** — `ThemeJsonStep::requests()` currently sends no `json_schema`, which is why the model volunteers content freely. Add one that omits the schema-blocked paths. `PagePlanStep::jsonSchema()` is the pattern. `settings` and `styles.blocks` stay permissive; the blocked paths simply have no slot.

**4. `prompts/theme-json.md` rewrite** — delete the lines dictating the build-supplied values (currently lines 65-69 and part of 25). Add a short section naming what the build supplies, listing the blocks already styled, and asking for `styles.blocks` additions only where the design genuinely calls for one.

## Expected outcome

| | model writes | est. step time | cut |
|---|---|---|---|
| today | 8,811 B | ~90s | — |
| model keeps emitting blocks as today | 6,771 B | ~69s | 23% |
| scaffold base displaces most blocks | 5,318 B | ~54s | 40% |

The spread depends on how well the base set displaces what the model would have written. A strong base plus a prompt that says "these are handled" lands near 40%; a thin base lands near 23%.

These are arithmetic projections from byte counts, assuming emission time scales linearly with output length. They need a real build to confirm.

## Risks and integration points

- **`ContrastFixStep`** reads `styles.elements.link/button/heading`. Those stay model-authored, so its inputs are unchanged. The scaffold must not write those paths.
- **`ThemeValidator`** compares spacing against the theme profile and emits drift warnings. The scaffold does not touch `settings.spacing` (already force-normalized today), so this is unchanged — but the validator must stay green on scaffolded output.
- **`PresetReferences`** validates that markup only references declared slugs. Every scaffold value is a `var:preset|…` reference to a slug the prompt mandates, so all referenced slugs are always declared. Worth an explicit test.
- **Schema rejection risk.** If the schema is too strict the API may 400 or the model may fail to produce required fields. `settings` must stay permissive — the model needs freedom for gradients and shadow presets, whose slugs it invents.
- **Vendored copy.** wpcom vendors this library as `a8c/site-builder`. A new `config/` file must be picked up by their sync (`bin/site-builder/sync-paths.sh`) — flag it in the PR, do not change wpcom here.

## Testing

- `applyScaffold()` fills every build-supplied path on a minimal model document.
- `applyScaffold()` deep-merges: a model-emitted `core/quote` key wins; sibling scaffold keys survive.
- `applyScaffold()` does not write `styles.elements.button` / `.link` / `.heading`.
- The request schema has no slot for the blocked paths, and leaves `settings` and `styles.blocks` open.
- End-to-end through `FakeLlm`: a model response omitting the build-supplied paths still produces a theme.json that passes `assertColors`, `assertFonts` and `ThemeValidator`.
- `PresetReferences::problems()` reports nothing for a scaffolded theme.
- The existing theme-json suite stays green.

## Out of scope

- Any change to the wpcom port. It vendors this library; the sync is a separate task.
- Batching or parallelising anything — that was `fix/batch-page-plan-repairs` (PR #153).
- Reducing `settings` output. The palette, gradients, shadow presets and type scale are the model's actual design decisions and stay whole.
