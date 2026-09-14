This report records the four-page `tbilisi12` audit before the subsequent multi-page fixes. It preserves the original measurements and defects.

See [the fix verification](codex-perf-verification.md) for the subsequent PRs and tests.

The build exposed an API schema error. PR #668 fixes that error. Subsequent PRs address the other findings below.

The demo contains Home, Menu, About, and Visit, with 14 sections. Its theme and content plugin are complete on disk. Studio is unavailable, so no browser preview or visual site check ran.

The build used `claude-opus-5`, `claude-haiku-4-5`, and `gemini-3.1-flash-image`. It used the original Tbilisi prompt, the blocks graph, and `--multi-page --with-images`. The default image policy generates homepage images, shared images, and interior hero images. It deferred nine other interior images to local placeholders. Thus this result does not measure a site with every image generated.

The first attempt used combined commit `1d9ce7c041f35a3f74e3f9709c95604c32618c21`. The resumed attempt used `cc2510f2`, which adds the schema fix. The `codex-perf` branch now contains all 13 original implementation PRs. The tables below describe the earlier test commits.

| Measure | Result |
|---|---:|
| Estimated Claude cost | $1.817624 |
| Estimated image cost | $0.538479 |
| Estimated total cost | $2.356103 |
| First attempt, through the API error | 47.3 seconds |
| Successful resume | 152.9 seconds |
| Active time across both attempts | 200.2 seconds |
| Successful LLM requests | 30 |
| Rejected LLM requests | 4 |
| LLM input tokens, including cache | 426,097 |
| LLM output tokens | 43,551 |
| Cache read tokens | 231,145 |
| Cache write tokens | 27,952 |
| Image provider attempts | 7 successful; 0 failed |
| Image assets | 7 generated; 9 local placeholders |
| Image QA requests | 3; all passed |
| Image phase | 18.9 seconds |

The first attempt started at 15:45:31 UTC. The build completed at 15:52:47 UTC on 11 September 2026. This elapsed interval includes the pause for diagnosis, the code fix, tests, and the resume. Active time excludes that pause. The first-attempt duration uses the command log completion timestamp; the resume duration uses `build-stats.json`.

The successful resume report alone shows 26 LLM requests and 152.9 seconds. It omits the four successful requests from the first attempt. Those requests cost $0.168951. The theme request ran twice because it shares the failed group with page plans. That duplicate cost $0.019315. The four HTTP 400 rejections report no token use.

These are cost estimates, not an invoice. Claude costs use uncached input, cache reads, cache writes, and output separately. Rates come from [Anthropic](https://platform.claude.com/docs/en/about-claude/pricing). Image costs use 1,759 prompt tokens and the output sizes at [Google rates](https://ai.google.dev/gemini-api/docs/pricing#gemini-3.1-flash-image). The 8,960 reported output tokens equal five 1K outputs and two 2K outputs. The ledger does not retain modality details or account discounts. Intermediate LLM transport attempts are absent from the transcripts, so unrecorded partial-response charges remain unknown.

| Build | Pages / sections | Active seconds | Successful LLM requests | Estimated Claude cost | Logged image outputs | Estimated total |
|---|---:|---:|---:|---:|---:|---:|
| Tbilisi11 | 1 / 4 | 139.5 | 16 | $0.9260 | 5 | $1.3630 |
| Lumen10 | 1 / 4 | 184.7 | 15 | $1.0433 | 10 | $1.8153 |
| Atlas10 | 1 / 4 | 241.2 | 25 | $0.9267 | 13 | $1.9337 |
| Tbilisi12 | 4 / 14 | 200.2 | 30 | $1.8176 | 7 | $2.3561 |

The earlier totals include rounded image output prices and exclude unrecorded image prompt charges. Their image transcripts do not count all possible transport retries. The new ledger supplies that count. These builds differ in page count, content, image policy, and request outcomes. Other builds also ran on this host. This comparison does not establish a controlled percentage improvement.

The successful resume spent 65.9 seconds on theme and page plans, 63.8 seconds on markup, and 18.9 seconds on images. Other steps used about 4.3 seconds. Menu required a 30.21-second repair request after the first page-plan batch. The rejection concerned adjacent layouts and excessive layout reuse.

| PR | Result of the multi-page check |
|---|---|
| #667, page budgets | Code repaired surface choices without a separate surface repair request. A Menu layout violation still required a model repair. |
| #668, media contracts | The live API rejected unsupported numeric bounds. Integer enums and supported string descriptions now work. Planned counts still stop before section requests. |
| #669, compact context | The 16 markup requests share one site prefix. Section requests share one build prefix. Text-only compositions omit image rules, but optional-media compositions still receive them at image count zero. |
| #670, concurrent image QA | Three independent checks ran in one batch and passed. No retry occurred, so this run does not test concurrent replacements. |
| #671, image records | Seven attempt rows agree with the build report. All rows include usage. Total transfer time is 90.05 seconds; concurrent transfer span is 17.28 seconds. |
| #672, local UI images | The restaurant uses photographs. This demo does not test local UI images. |
| #673, image size | Five requests use 1K. A square dish card incorrectly uses 2K because its scene description contains the word `background`. |
| #674, theme compiler | One site-wide request produces 110 output tokens. Each call costs $0.019315; Tbilisi11 used 1,560 output tokens and cost $0.090805. The failed group caused a duplicate call. |
| #675, action contracts | The homepage action changes from `Reserve your table` to `Visit`. A Visit action changes to `Make a Reservation`, which still promises an unsupported transaction. |
| #676, useful cache request | No separate cache preparation request ran. A real Menu section wrote the common prefix. All three Visit sections wrote the same page prefix. |
| #677, exact image reuse | No exact duplicate request exists in this demo. Zero assets reused another result. Similar table scenes have different prompts and crops. |
| #678, combined design | The site uses two design requests, rather than the earlier three. Total design cost is $0.145139, versus $0.152756 in Tbilisi11. This single comparison does not isolate model variance. |
| #679, image preparation | The CLI still starts images after the main graph. The preparation API is not active in this path. All seven requested assets have final references, so this run found no unused paid asset. |

The following findings have live evidence:

1. **The original schema prevents a build.** Anthropic rejected `minimum` and `maximum` on `image_count`. PR #668 replaces them with an integer enum from zero through twelve. It also replaces unsupported string lengths with descriptions. Local normalization retains the limits. All 125 page-plan tests pass on that PR; 124 pass on the combined branch. PHP 8.1 and PHP 8.4 [CI](https://github.com/Automattic/minimalistic-site-builder/actions/runs/34618472470) pass. The resumed API accepts all four page schemas. [Anthropic documents the schema limits](https://platform.claude.com/docs/en/build-with-claude/structured-outputs).

2. **Image counts do not constrain section requests.** Menu plans six images in `mains` but collects seven, including an extra background. Visit plans zero in `reserve-visit` but collects one. All requested positive counts survive; two extra images appear. Both extras become placeholders under the default policy. They would add provider work when all images generate. Send `image_count` in the section brief, omit image rules for an explicit zero, and compare the delivered count with the plan.

3. **Scene text overrides the image slot.** The churchkhela card specifies a square crop. Its context says `background dissolving into darkness`. `ImageCrop::fullFrameSlot()` treats this word as a page background, selects a wide crop, and adds QA. The size rule then preserves 2K. Use explicit slot data for crop, size, and QA. This case offers $0.0336 in image output reduction plus a $0.002694 QA request, before any time benefit. The changed crop also needs a visual check.

4. **Page plans repeat uncached context.** The four initial prompts contain 37,957–38,184 bytes each. They share 10,892 leading bytes and at least 21,271 bytes in identical paragraphs. No request has a cache marker. The requests consume 42,403 input tokens; the Menu repair adds 12,279. Move common rules and facts into one prefix, then append each page brief. Keep separate concurrent page outputs before testing one combined plan response. Byte counts are not token savings.

5. **The Visit page writes one prefix three times.** Each of its three sections writes 1,510 tokens and reads only the common 17,238 tokens. The duplicate writes total 3,020 tokens. If two requests read that prefix instead, the rate difference is about $0.0174. A useful first request per page group could prevent this duplication. Measure any added delay before changing the gate.

6. **Action labels remain incomplete.** The Visit hero links `Make a Reservation` to a content section. The section supplies no reservation form, phone number, or verified transaction URL. The classifier does not recognize this phrase as a transaction. The extracted `contact-split.php` pattern also contains `href="#"`; the live Visit page retains a real anchor. Check action labels and destinations after pattern extraction, while preserving valid reusable links.

7. **Some generated markup remains invalid or poorly constrained.** The run records 55 warning rows, including nine deliberate placeholders and 32 section warnings. Section warnings concern card structure and unsafe class hooks. Final validation reports a dead pattern link, six paragraphs at heading sizes, and a Menu jump from h1 to h3. These counts are warning rows, not distinct visible defects. The shortened context needs broader content and visual checks before approval.

The following findings come from local boundary probes. They did not occur in this demo unless stated above:

- A four-section interior plan with two adjacent eight-photo galleries can collapse to one text section. Media repair selects a layout that violates a neighbor rule, then page recovery removes all sixteen planned photos and sibling content. Preserve content and relax a harmless layout rule, or change a compatible text neighbor.
- `/visit` and `/visit#contact` pass page-plan validation but fail the action title lookup. The canonical forms `/visit/` and `/visit/#contact` work. Normalize internal route keys before lookup.
- `runForSlugs(['home'])` skips the full action reconciliation pass. Its local probe retains an unsupported reservation action. The usual HTML-first fallback selects interior pages, so this probe does not prove a failure in that normal path.
- A planned interior hero can qualify for image generation but miss QA when its filename and context omit hero keywords. Both actual hero images received QA in this demo.
- An interior placeholder misses free reuse when its request exactly matches an eligible homepage image. The policy filter runs before the reuse group. This demo contains no exact match.
- The active CLI does not filter image requests against final references. All paid images in this demo remain referenced. The preparation API has the filter, but the CLI does not use it.
- Image QA still has a barrier between generation, checks, and replacements. This demo tests only one successful check round. A larger fixture must test queue behavior and the memory limit.

The original checks support the site-wide design changes, common cache reuse, image concurrency, and the attempt ledger. They do not establish that all multi-page contracts were correct at that time. The subsequent fixes require their own tests and comparison.

All LLM transcripts appear below. Request durations overlap in concurrent phases and must not be added as build time. Failed schema requests report zero tokens. File numbers restart on resume; timestamps and command boundaries establish order.

| Log file | Seconds | Input | Output | Cache read | Cache write | Estimated USD |
|---|---:|---:|---:|---:|---:|---:|
| `01-0-theme-json.log` | 2.74 | 3,313 | 110 | 0 | 0 | $0.019315 |
| `01-site-spec.log` | 5.28 | 1,892 | 521 | 0 | 0 | $0.004497 |
| `02-1-home.log` | 17.32 | 10,633 | 954 | 0 | 0 | $0.015403 |
| `02-design-direction-seeds.log` | 4.45 | 3,184 | 419 | 0 | 0 | $0.005279 |
| `03-1-menu.log` | 35.66 | 10,577 | 1,219 | 0 | 0 | $0.016672 |
| `03-design-direction.log` | 33.96 | 18,032 | 1,988 | 0 | 0 | $0.139860 |
| `04-0-theme-json.log` | 3.44 | 3,313 | 110 | 0 | 0 | $0.019315 |
| `04-1-about.log` | 35.05 | 10,583 | 1,187 | 0 | 0 | $0.016518 |
| `05-1-home-failed.log` | 0.51 | 0 | 0 | 0 | 0 | $0.000000 |
| `05-1-visit.log` | 25.97 | 10,610 | 780 | 0 | 0 | $0.014510 |
| `06-1-menu-failed.log` | 0.42 | 0 | 0 | 0 | 0 | $0.000000 |
| `06-page-plan-menu-repair.log` | 30.21 | 12,279 | 1,206 | 0 | 0 | $0.018309 |
| `07-1-about-failed.log` | 0.44 | 0 | 0 | 0 | 0 | $0.000000 |
| `07-page-menu--appetizers-soups.log` | 48.07 | 23,276 | 4,549 | 0 | 18,794 | $0.253597 |
| `08-1-visit-failed.log` | 0.38 | 0 | 0 | 0 | 0 | $0.000000 |
| `08-header.log` | 8.29 | 11,623 | 761 | 6,033 | 0 | $0.049992 |
| `09-footer.log` | 15.19 | 11,568 | 1,598 | 6,033 | 0 | $0.070641 |
| `10-page-home--hero.log` | 15.30 | 14,371 | 1,347 | 6,033 | 0 | $0.078382 |
| `11-page-home--signature-dishes.log` | 36.20 | 22,515 | 3,573 | 17,238 | 1,500 | $0.126204 |
| `12-page-home--location-and-hours.log` | 14.73 | 21,013 | 1,356 | 17,238 | 498 | $0.062017 |
| `13-page-menu--hero.log` | 11.63 | 21,128 | 1,047 | 17,238 | 554 | $0.054936 |
| `14-page-menu--mains.log` | 62.21 | 22,607 | 6,011 | 18,794 | 0 | $0.178737 |
| `15-page-menu--sides-breads-desserts-reservations.log` | 33.86 | 22,827 | 3,408 | 18,794 | 0 | $0.114762 |
| `16-page-about--hero.log` | 15.33 | 22,796 | 1,068 | 17,238 | 1,539 | $0.065033 |
| `17-page-about--traditions.log` | 13.61 | 20,823 | 1,256 | 17,238 | 537 | $0.058615 |
| `18-page-about--story.log` | 23.94 | 22,466 | 1,654 | 18,777 | 0 | $0.069183 |
| `19-page-about--next-step.log` | 7.54 | 22,249 | 733 | 18,777 | 0 | $0.045074 |
| `20-page-visit--hero.log` | 14.04 | 22,609 | 1,102 | 17,238 | 1,510 | $0.064911 |
| `21-page-visit--location-hours-contact.log` | 35.23 | 22,413 | 3,829 | 17,238 | 1,510 | $0.132106 |
| `22-page-visit--reserve-visit.log` | 18.34 | 22,508 | 1,529 | 17,238 | 1,510 | $0.075081 |
| `23-page-styles.log` | 2.05 | 7,766 | 77 | 0 | 0 | $0.040755 |
| `24-image-qa-tavern-dining-room-candlelight.jpg.log` | 1.14 | 2,449 | 53 | 0 | 0 | $0.002714 |
| `25-image-qa-hero-supra-table-cellar.jpg.log` | 1.32 | 2,245 | 53 | 0 | 0 | $0.002510 |
| `26-image-qa-churchkhela-walnut-grape-strings.jpg.log` | 1.54 | 2,429 | 53 | 0 | 0 | $0.002694 |

All image provider attempts appear below. Each returned HTTP 200. No native render, provider retry, or QA replacement occurred.

| Asset | Size | Ratio | Transfer seconds | Prompt tokens | Output tokens |
|---|---|---|---:|---:|---:|
| `site-logo.png` | 1K | 1:1 | 10.48 | 154 | 1120 |
| `khinkali-pleated-dumplings-plate.jpg` | 1K | 1:1 | 10.94 | 260 | 1120 |
| `pkhali-walnut-vegetable-spheres.jpg` | 1K | 1:1 | 11.06 | 254 | 1120 |
| `hero-supra-table-cellar.jpg` | 1K | 1:1 | 11.10 | 265 | 1120 |
| `khachapuri-adjaruli-clay-pan.jpg` | 1K | 1:1 | 13.16 | 267 | 1120 |
| `tavern-dining-room-candlelight.jpg` | 2K | 16:9 | 16.04 | 296 | 1680 |
| `churchkhela-walnut-grape-strings.jpg` | 2K | 16:9 | 17.28 | 263 | 1680 |

The plan and collected image counts are below. These counts exclude the site logo. The final markup references every collected image.

| Page | Section | Planned images | Collected images |
|---|---|---:|---:|
| home | `hero` | 1 | 1 |
| home | `signature-dishes` | 4 | 4 |
| home | `location-and-hours` | 0 | 0 |
| menu | `hero` | 0 | 0 |
| menu | `appetizers-soups` | 0 | 0 |
| menu | `mains` | 6 | 7 |
| menu | `sides-breads-desserts-reservations` | 0 | 0 |
| about | `hero` | 1 | 1 |
| about | `traditions` | 0 | 0 |
| about | `story` | 1 | 1 |
| about | `next-step` | 0 | 0 |
| visit | `hero` | 0 | 0 |
| visit | `location-hours-contact` | 0 | 0 |
| visit | `reserve-visit` | 0 | 1 |

Each of the four page files is non-empty and contains one h1 heading. All sixteen image files decode, and all referenced local image paths exist. These file checks do not establish visual quality.

The local evidence directory is `/tmp/codex-perf-multipage-audit`. It contains the command logs, provenance, request tables, cost calculation script, final-reference check, and boundary probes. Project logs remain in `projects/tbilisi12/logs`. No screenshots or generated image evidence entered a Git branch.
