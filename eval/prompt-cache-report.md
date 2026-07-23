# Prompt Caching — Measurement Report

Measured 2026-07-23 on `silver-lantern` (`php bin/build.php "A punk rock hairstyling studio for squirrels" --multi-page --with-images`, claude-sonnet-5, this branch @ b817d58). Baselines are pre-caching builds of the same prompt from 2026-07-22: `olive-harvest` (sections: 470,754 in / 160,427 out over 24 requests) and `vivid-island` (sections total 763,912 tok; sections wall-clock range that day 374–546s).

## How it works

Section requests send their shared content as two cached prefix layers — build-level (rules + theme.json + design direction) and page-level (page outline + site pages) — with the per-section brief as the only varying tail. Before the concurrent fan-out, a **warm-up probe** (`section-cache-warm`, `max_tokens: 1`) writes the cache once so the first window reads instead of paying cold-write rates on every parallel request. A probe failure is non-fatal (build proceeds uncached).

## Results

| Metric | Value |
|---|---|
| Probe | `17,350 in (17,339 cache-write) + 1 out` — ~$0.02 |
| Requests with cache reads | 28 of 43 (the 15 others are pre-section steps, out of scope) |
| Input tokens served from cache | **475,270 of 617,386 (77%)** |
| Cache writes | 27,101 tok |
| Billed-equivalent input (reads 0.1x, writes 1.25x) | **196,418 vs 617,386 uncached → 68% input-cost reduction** |
| Build input cost @ $3/M | ~$1.85 → ~$0.59 |
| Sections wall-clock | 328.8s (pre-caching range: 374–546s) |

Verification on any build: `grep -h "^Tokens" projects/<slug>/logs/llms/*.log | grep cache-read` — absent cache-read lines on section requests means a prefix layer went byte-unstable and the build silently fell back to full price.
