# Builder — Progress / Final Summary

A minimalistic, no-agentic-loop WordPress site builder. Each site element is one
isolated LLM call driven by a dynamic prompt template; all output is saved as
local files; steps run as a fixed deterministic sequence.

## Status: COMPLETE — all phases done

---

## Phase 0 — proxy access (resolved)

Verified empirically (not assumed) that the **wpcom AI proxy cannot reach Claude**
with the available credentials:
- The Anthropic key is not a valid proxy bearer (proxy → 404 `not_found_error`).
- The only working proxy token (`GOOGLE_VERTEX_API_TOKEN`) is scoped to Google
  Vertex only — Claude/Anthropic models return 404 through it.
- telex confirms the pattern: it calls Anthropic **directly** and uses the proxy
  only for Google-Vertex image generation.

**User decision: use Anthropic-direct.** `api.anthropic.com/v1/messages`,
`x-api-key`, model `claude-opus-4-8`. Confirmed round-trip ("pong"). Transport is
behind the `Llm` interface so a proxy transport can be swapped in later.

---

## Phase 1 — implementation (complete)

8 steps, each implemented, unit-tested, and committed to trunk one per commit.

| # | Step | Type | Input → Output |
|---|------|------|----------------|
| 1 | scaffold-theme   | det | — → theme/style.css, readme.txt (placeholders) |
| 2 | site-spec        | LLM | meta.json prompt → siteSpec.json |
| 3 | apply-identity   | det | siteSpec → filled style.css/readme.txt |
| 4 | design-direction | LLM | siteSpec → designDirection.json |
| 5 | design-doc       | LLM | siteSpec + direction → design.md |
| 6 | theme-json       | LLM | design.md + direction → theme/theme.json (v3) |
| 7 | landing-page     | LLM | theme.json + design.md + siteSpec → parts/ + templates/ |
| 8 | finalize-theme   | det | theme.json → theme/functions.php (Google Fonts loading) |

**Architecture** (zero dependencies — plain PHP + cURL):
`Env`, `Llm` interface + `AnthropicClient`, `Project`, `ProjectStore`,
`PromptRenderer`, `Step` + 8 steps, `Pipeline`, `ThemeValidator`.
Prompts: `prompts/*.txt`. Runners: `bin/build.php`, `bin/eval.php`, `bin/inspect.php`.

**Tests: 30 unit + 2 integration = 32 passing.** Run with
`php tests/run.php` and `php tests/run-integration.php`. The integration test
runs the real `Pipeline` with a `FakeLlm` and asserts the output passes
`ThemeValidator` (files present, theme.json v3, balanced block grammar, no
leftover placeholders, fonts enqueued) — this is the full-sequence integration test.

Per-step real verification was done cumulatively: each new LLM step's first real
run re-runs all prior steps, exercising the whole chain up to that point.

---

## Phase 2 — end-to-end validation (5 sites)

Generated all 5 sites with `bin/eval.php`. Full data: `eval/report.md`,
`eval/results.json`. All **structurally valid**.

### Speed (model: claude-opus-4-8, sequential)
| Site | site-spec | design-dir | design-doc | theme-json | landing | Total |
|---|---|---|---|---|---|---|
| climate-care-blog | 7.4 | 37.9 | 32.6 | 21.9 | 113.6 | **213s** |
| photo-portfolio   | 7.5 | 36.1 | 33.4 | 18.7 | 92.5  | **188s** |
| pizza-menu        | 8.2 | 32.8 | 34.0 | 22.9 | 137.5 | **235s** |
| bakery-catalog    | 8.5 | 33.8 | 29.0 | 18.5 | 149.3 | **239s** |
| bicycle-store     | 7.7 | 33.1 | 32.6 | 21.9 | 132.0 | **227s** |

(scaffold/apply-identity/finalize are deterministic, ~0s.) Mean ≈ **220s/site**.
**landing-page is the bottleneck** (~50–60% of total): large block-markup output.

### Quality
| Site | Brand | Fonts (loaded) | Sections | Front-page blocks |
|---|---|---|---|---|
| climate-care-blog | Greener Nest | Fraunces + Work Sans | 6 | 83 |
| photo-portfolio | Stillrange | Cormorant Garamond + Inter | 7 | 58 |
| pizza-menu | Forno Vero | Playfair Display + Lora | 7 | 103 |
| bakery-catalog | Hearth & Crumb | Playfair Display + Source Serif Pro | 6 | 114 |
| bicycle-store | Verge Cycles | Archivo + Inter | 6 | 91 |

Observed quality (via `bin/inspect.php`): distinct, fitting brand identities;
section structure matches `key_sections`; real CTAs; descriptive image alts;
**100% design-token discipline** — every color/font used in markup is a declared
theme.json preset (zero undeclared tokens across all 5 sites).

### Adjustment made from eval findings
The eval surfaced one real gap: **theme.json named Google fonts but nothing loaded
them**, so every site fell back to system fonts. Added **step 8 (finalize-theme)**:
generates `theme/functions.php` enqueuing the heading+body families from Google
Fonts (skips generic/system families). Verified all 5 font URLs return HTTP 200
and each `functions.php` is valid PHP. Re-ran the report (`bin/eval.php --report`)
— all 5 now load real webfonts.

---

## What you get per site (`projects/<slug>/`)
`meta.json`, `siteSpec.json`, `designDirection.json`, `design.md`, and a complete
block theme under `theme/`: `style.css`, `readme.txt`, `theme.json`,
`functions.php`, `parts/{header,footer}.html`, `templates/{index,front-page}.html`.

## Remaining issues / recommendations
1. **No live WordPress render check.** Validation is structural (block grammar,
   theme.json, font URLs) — not yet loaded in a real WP instance. Recommend a
   `wp-env`/Playground smoke test as the next verification layer.
2. **landing-page latency** dominates (~2 min). Options: split into per-section
   calls (parallelizable), lower effort, or a faster model for this step only.
3. **Single page only.** Only the front page + index fallback are generated.
   Natural extensions (same one-shot pattern): per-page templates (about, contact),
   block patterns, a richer index/query for blog/catalog sites.
4. **Images are placeholders** (descriptive alt text, no real assets). Could wire
   the Vertex image path (the one proxy route that works) to generate real imagery.
5. **Font weights** assume 400/600/700 exist (true for all fonts picked so far).
   A font lacking one would 400 the combined request; per-family enqueue would
   isolate that if it ever happens.

## How to run
```
cp .env.example .env   # set ANTHROPIC_API_KEY
php tests/run.php && php tests/run-integration.php
php bin/build.php "A cozy neighborhood bakery" --slug=my-bakery
php bin/inspect.php my-bakery
php bin/eval.php          # regenerate the 5 eval sites
```
