# Builder — Progress

_Source of truth for resuming after interruption. Update after every meaningful action._

## Current status: Phase 2 (end-to-end validation) — generating 5 eval sites

## Phase 0 — proxy access — DONE (resolved)
Verified empirically that the wpcom AI proxy cannot reach Claude with available
credentials (Anthropic key is not a valid proxy bearer; the only working proxy
token is Google-Vertex-scoped). telex itself calls Anthropic directly. **User
decision: use Anthropic-direct.** Confirmed round-trip: `claude-opus-4-8` → "pong".

## Phase 1 — steps — DONE
All 7 steps implemented, unit-tested, committed to trunk. Full sequence passes
both a real end-to-end run and a deterministic integration test.

| # | Step | Type | Output | Unit tests |
|---|------|------|--------|-----------|
| 1 | scaffold-theme | det | theme/style.css, readme.txt (placeholders) | 2 |
| 2 | site-spec | LLM | siteSpec.json | 3 |
| 3 | apply-identity | det | filled style.css/readme.txt | 1 |
| 4 | design-direction | LLM | designDirection.json | 2 |
| 5 | design-doc | LLM | design.md | 2 |
| 6 | theme-json | LLM | theme/theme.json (v3, 5 colors, 2 fonts) | 3 |
| 7 | landing-page | LLM | parts/header,footer + templates/index,front-page | 3 |

**Test status: 28 unit + 2 integration = 30 passing** (`php tests/run.php`,
`php tests/run-integration.php`). Validator: `ThemeValidator` checks files,
theme.json v3, balanced block grammar, no leftover placeholders.

Architecture: `Env`, `Llm` (interface) + `AnthropicClient`, `Project`,
`ProjectStore`, `PromptRenderer`, `Step` + 7 steps, `Pipeline`,
`ThemeValidator`. Prompts in `prompts/`. Runner: `bin/build.php`.

### First full real run (int-climate, "Greenstead/Greener Nest" climate blog)
scaffold 0s · site-spec 7s · apply-identity 0s · design-direction 32s ·
design-doc 36s · theme-json 21s · landing-page 143s · **TOTAL 239s**.
Structurally valid; front-page = 94 blocks / 301 lines.

### Known observations / candidate improvements (for Phase 2)
- landing-page is the bottleneck (~143s, 32k token budget). Watch its share.
- Fonts: theme.json declares font stacks but no webfont loading — Google fonts
  won't actually load unless bundled/enqueued. Candidate: generate functions.php
  to enqueue, or add fontFace. (Tracking; decide after eval.)
- Per-step real integration was done cumulatively (each new LLM step's real run
  re-runs all prior steps), which is the full-sequence-up-to-here check.

## Phase 2 — end-to-end validation — IN PROGRESS
Generate 5 sites: climate care blog, photo portfolio, pizza menu, bakery
catalog, bicycle store. Record per-step speed + quality; adjust; re-run.
Harness: `bin/eval.php` (writes eval/report.md).

## Next
- Build `bin/eval.php`, run 5 sites, collect timing + validation + quality notes.
- Review outputs, fix weak spots, re-run as needed.
- Final summary here.
