The `codex-perf` branch contains the original performance changes and the multi-page fixes. Each fix has a separate PR with `codex-perf` as its base. No Linear issues exist for these PRs.

The [original multi-page audit](codex-perf-multipage.md) records the paid `tbilisi12` build before these fixes. Its measurements remain historical evidence.

| Finding | Fix and regression evidence |
|---|---|
| Repeated page-plan context | PR #685 shares one site prefix across initial page requests and repairs. Four saved page requests share 35,319 bytes. Each page brief adds 1,450–2,027 bytes. |
| Duplicate page cache writes | PR #683 selects one useful writer per cumulative prefix, model, system message, and schema. The saved 16 markup requests now have one Visit writer before sibling requests. |
| Excess images and lost galleries | PR #684 sends image counts to section requests and removes only safe excess media. It preserves gallery content when a harmless layout rule conflicts. PR #690 preserves prose inside malformed image blocks. |
| Scene text changes crop, size, and QA | PRs #686 and #688 use explicit slots and planned hero roles. The saved dish card now selects square 1K output without QA. Wide images and heroes retain their required checks. |
| Unsupported actions and dead links | PR #687 normalizes routes, reads saved sibling anchors, repairs transaction labels, and removes dead pattern buttons. It retains valid destinations and sibling content. |
| Invalid cards and oversized body text | PR #690 repairs safe card surfaces and text wrappers. The saved response replay reduces 32 card anatomy warnings to zero and makes six text or heading repairs. Uncertain markup retains its content with an actionable warning. |
| Unused image requests and missed free reuse | PR #688 follows final page, template, part, and pattern references. It reuses equivalent eligible results, including a source with stronger QA. |
| Large QA payloads and serial request phases | PR #689 bounds QA memory. PR #691 starts QA and replacements as image results arrive. Tests cover 20 failed assets, HTTP 429, provider limits, cancellation, and host Fibers. |
| Image work starts after the full graph | PR #692 starts raw requests after final page assembly. It retains normal asset writes and QA in the image phase. Combined tests prove that a fast image reaches QA and replacement before a slow raw request completes. |

PR #692 also preserves paid results for resume, checks changed request keys, and records canceled provider attempts. It verifies raw files before use and avoids repeated hashes during each wait. It retains unused results across partial batches and prevents QA replacements from reusing consumed pixels.

The regression tests use local fixtures and local HTTP endpoints. They make no paid API requests. A saved-response replay verifies a repair against known model output; it does not prove visual quality.

The second paid demo, `tbilisi13`, used commit `2b75e776`, the original Tbilisi prompt, `--blocks-first --multi-page --with-images`, and the original API models. It completed without a resume. The model selected five pages and 19 sections, compared with four pages and 14 sections in `tbilisi12`.

| Measure | Tbilisi12 | Tbilisi13 |
|---|---:|---:|
| Active build time | 200.2 seconds | 189.4 seconds |
| Estimated Claude cost | $1.817624 | $1.796675 |
| Estimated image cost | $0.538480 | $0.437447 |
| Estimated total cost | $2.356103 | $2.234121 |
| Successful LLM requests | 30 | 34 |
| Rejected LLM requests | 4 | 0 |
| Input tokens, including cache | 426,097 | 538,016 |
| Output tokens | 43,551 | 42,018 |
| Cache read tokens | 231,145 | 376,934 |
| Cache write tokens | 27,952 | 35,594 |
| Image provider attempts | 7 | 5 |
| Generated image assets | 7 | 5 |
| Local image placeholders | 9 | 1 |
| Image QA requests | 3 | 3 |

The estimates use separate uncached input, cache read, cache write, and output rates from [Anthropic](https://platform.claude.com/docs/en/about-claude/pricing). Image estimates use reported input and output tokens at [Google rates](https://ai.google.dev/gemini-api/docs/pricing#gemini-3.1-flash-image). These are estimates, not invoices. Unrecorded partial LLM attempts and account discounts remain unknown.

The builds differ in page count, content, image policy outcomes, and provider load. Tbilisi12 includes a failed attempt and resume; its active time excludes the repair pause. These results do not establish a controlled percentage improvement.

The five initial page plans share one 36,764-byte prefix. One request writes 10,323 cache tokens. The four other plans and the Contact repair read that prefix. Each distinct section prefix has at most one writer. Visit has one writer and three readers; its earlier build had three writes of the same page prefix.

All five image attempts returned HTTP 200. Normal image apply consumed five staged results without duplicate provider calls. All three QA requests finished before the slowest raw image completed. The smallest measured margin was 1.44 seconds. This run needed no image retry or QA replacement; local tests cover those paths.

All 19 sections match their planned image counts. Every final local image reference resolves. The audit found no broken page or section links. Studio is unavailable, so no browser preview or visual site check ran.

The live build exposed additional boundaries. Separate PRs fix each one:

| PR | Final change | Evidence |
|---|---|---|
| #693 | Preserve explicit cover and full-width slots above ancestor card classes. Keep site logos square. | The saved About cover now selects 16:9 and 2K. The logo request selects 1:1. Nested card controls retain their own crop. |
| #694 | Repair an interior full-bleed hero before another model request. | Replay of all five initial plans produces 19 sections with zero repair requests. The original Contact repair took 22.16 seconds. |
| #695 | Remove borderless image-card classes from text-only groups. Repair peer hero headings and long caption-size paragraphs. | Saved fixtures cover 11 text-only groups, three About peer headings, and the Menu body paragraph. |
| #696 | Match enquiry actions and contact copy to real channels. | Replay corrects six labels, headings, or contact instructions in Home, Contact, and the footer. It preserves nearby facts and reaches a fixed point. |
| #697 | Ignore harmless raw font sizes that exactly match the theme scale. | The footer retains its lead-size value. Tests still report off-scale values and long body copy at unsuitable sizes. |
| #698 | Test image overlap with explicit event order. | A slow fake result waits for the required QA or replacement event. A bounded timeout detects a transport barrier without a 100-millisecond processor-speed assumption. |

These follow-up checks replay saved responses and markup. They do not regenerate the paid demo or change its recorded cost. The original `tbilisi13` project remains available with its historical output.

The local PHP 8.5 suite retains the known baseline failures. CI checks PHP 8.1 and PHP 8.4. Review evidence remains outside Git under `/tmp/codex-perf-multipage-audit`.

The Tbilisi13 LLM requests appear below. Concurrent request durations overlap and must not be added as build time.

| Request log | Model | Seconds | Input | Output | Cache read | Cache write | Estimated USD |
|---|---|---:|---:|---:|---:|---:|---:|
| `01-site-spec.log` | Haiku 4.5 | 6.41 | 1,892 | 596 | 0 | 0 | $0.004872 |
| `02-design-direction-seeds.log` | Haiku 4.5 | 5.28 | 3,245 | 438 | 0 | 0 | $0.005435 |
| `03-design-direction.log` | Opus 5 | 39.06 | 18,148 | 2,523 | 0 | 0 | $0.153815 |
| `04-0-theme-json.log` | Opus 5 | 2.76 | 3,667 | 110 | 0 | 0 | $0.021085 |
| `05-1-home.log` | Haiku 4.5 | 35.54 | 10,861 | 1,419 | 0 | 10,323 | $0.020537 |
| `06-1-menu.log` | Haiku 4.5 | 32.55 | 10,663 | 1,285 | 10,323 | 0 | $0.007797 |
| `07-1-about.log` | Haiku 4.5 | 32.27 | 10,677 | 1,276 | 10,323 | 0 | $0.007766 |
| `08-1-visit.log` | Haiku 4.5 | 25.69 | 10,687 | 949 | 10,323 | 0 | $0.006141 |
| `09-1-contact.log` | Haiku 4.5 | 24.23 | 10,542 | 882 | 10,323 | 0 | $0.005661 |
| `10-page-plan-contact-repair.log` | Haiku 4.5 | 22.16 | 11,832 | 852 | 10,323 | 0 | $0.006801 |
| `11-header.log` | Opus 5 | 8.09 | 12,542 | 750 | 6,582 | 0 | $0.051841 |
| `12-footer.log` | Opus 5 | 19.27 | 12,322 | 2,005 | 6,582 | 0 | $0.082116 |
| `13-page-home--hero.log` | Opus 5 | 17.72 | 15,274 | 1,472 | 6,582 | 0 | $0.083551 |
| `14-page-home--signature-dishes.log` | Opus 5 | 23.40 | 22,179 | 2,551 | 17,571 | 627 | $0.096384 |
| `15-page-home--atmosphere.log` | Opus 5 | 11.31 | 23,075 | 933 | 0 | 19,201 | $0.162701 |
| `16-page-home--visit-contact.log` | Opus 5 | 21.01 | 21,893 | 2,138 | 18,198 | 0 | $0.081024 |
| `17-page-menu--hero.log` | Opus 5 | 14.18 | 22,145 | 1,095 | 17,571 | 585 | $0.059762 |
| `18-page-menu--menu-sections.log` | Opus 5 | 30.15 | 21,480 | 2,602 | 18,156 | 0 | $0.090748 |
| `19-page-menu--chef-note.log` | Opus 5 | 12.81 | 21,994 | 954 | 18,156 | 0 | $0.052118 |
| `20-page-menu--visit-cta.log` | Opus 5 | 7.85 | 21,805 | 682 | 18,156 | 0 | $0.044373 |
| `21-page-about--hero.log` | Opus 5 | 25.88 | 23,420 | 2,293 | 17,571 | 1,593 | $0.097347 |
| `22-page-about--story.log` | Opus 5 | 22.46 | 23,013 | 1,581 | 19,164 | 0 | $0.068352 |
| `23-page-about--principles.log` | Opus 5 | 14.25 | 21,384 | 1,252 | 17,571 | 590 | $0.059888 |
| `24-page-about--closing.log` | Opus 5 | 7.40 | 21,733 | 660 | 18,161 | 0 | $0.043440 |
| `25-page-visit--hero.log` | Opus 5 | 13.56 | 22,108 | 1,146 | 17,571 | 586 | $0.060853 |
| `26-page-visit--location-and-hours.log` | Opus 5 | 21.08 | 21,898 | 2,138 | 18,157 | 0 | $0.081234 |
| `27-page-visit--tavern-character.log` | Opus 5 | 15.59 | 21,877 | 1,168 | 18,157 | 0 | $0.056878 |
| `28-page-visit--reserve.log` | Opus 5 | 7.61 | 21,659 | 675 | 18,157 | 0 | $0.043464 |
| `29-page-contact--hero.log` | Opus 5 | 12.25 | 23,189 | 1,031 | 17,571 | 1,546 | $0.064583 |
| `30-page-contact--contact-details.log` | Opus 5 | 32.69 | 21,922 | 3,689 | 17,571 | 543 | $0.123444 |
| `31-page-contact--closing-cta.log` | Opus 5 | 8.30 | 21,660 | 714 | 18,114 | 0 | $0.044637 |
| `32-image-qa-tbilisi-tavern-cellar-interior.jpg.log` | Haiku 4.5 | 1.48 | 2,317 | 53 | 0 | 0 | $0.002582 |
| `33-image-qa-tavern-cellar-candlelight-copper.jpg.log` | Haiku 4.5 | 1.35 | 2,451 | 53 | 0 | 0 | $0.002716 |
| `34-image-qa-tavern-cellar-candlelight-khachapuri.jpg.log` | Haiku 4.5 | 1.74 | 2,462 | 53 | 0 | 0 | $0.002727 |

Every image attempt returned HTTP 200 and reported usage. The table describes the paid output before the slot corrections.

| Asset | Size | Ratio | Transfer seconds | Input tokens | Output tokens |
|---|---|---|---:|---:|---:|
| `tbilisi-tavern-cellar-interior.jpg` | 1K | 3:2 | 14.27 | 287 | 1,120 |
| `site-logo.png` | 1K | 3:2 | 16.26 | 154 | 1,120 |
| `tavern-cellar-candlelight-copper.jpg` | 2K | 16:9 | 19.46 | 292 | 1,680 |
| `tavern-cellar-candlelight-khachapuri.jpg` | 2K | 16:9 | 20.33 | 285 | 1,680 |
| `tavern-cellar-interior-candlelight.jpg` | 2K | 16:9 | 23.86 | 275 | 1,680 |
