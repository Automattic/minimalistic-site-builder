# PR 633 eval evidence — BIGR-996

Build commit: `73e4c818c235cdadfc8f102af27d99f06b1448aa`. Evaluated 2026-09-09. Screenshots are review evidence hosted on the user-requested `playground-artifacts` branch; the feature branch contains no screenshots.

Two fresh three-page blocks-first sites were built with `php bin/build.php`, one invocation per top-level step. All 27 steps completed. The full image and post-image phase ran through `php bin/images.php`, including cover contrast and final validation. Studio served the finished sites. Screenshots use the repository Playwright harness with `--motion`, desktop width 1366 and mobile width 390, with full-page scrolling and lazy images loaded.

## Transport and limits

Text: `SITE_BUILD_LLM=codex-cli`, confirmed subscription transport. The configured small model `gpt-5.4-mini` was rejected by the ChatGPT-account CLI at site-spec. Both builds explicitly resumed with runtime `LLM_MODEL_SMALL=gpt-5.5`; the large tier was already gpt-5.5. No repository model configuration was changed. The Codex CLI cannot honor the system preamble, temperature, or max_tokens options; these transport degradations were recorded. Images used the independently provisioned image transport. All 29 bakery and 33 portfolio image specs completed. Both generated logo roles were dropped when background keying did not yield usable marks; text titles remain.

This is a single live run per design, not a paired baseline generation or proof of unchanged output quality. Request measurements compare the exact same persisted site facts/plans using pre-change and changed request renderers. They measure bytes, not tokenizer counts or billed savings. Shared card guidance stays in the build cache prefix.

## Request sizes

| Site | Changed ordinary section requests | Before bytes | After bytes | Per-request reduction |
| --- | ---: | ---: | ---: | --- |
| Linden Bread: framed, no motion | 16 | 1,292,630 | 1,097,059 | 14.50–15.61% |
| Fieldlight: borderless, dramatic | 18 | 1,457,779 | 1,313,600 | 9.25–10.19% |

Header, footer, and dedicated homepage hero requests are unchanged. `request-summary.json` and the request-size comparisons contain the complete measurements. The section-generation CLI reported 652,845 input / 19,588 output tokens for the bakery and 741,050 / 18,115 for the portfolio, including chrome and hero calls and harness overhead; no baseline billing comparison was run.

## Rendered findings

All eight screenshot views returned HTTP 200, had no failed image loads or page JavaScript errors, and had document width equal to viewport width. Both mobile navigation menus opened and exposed the intended page links. No motion classes were found on the bakery views; portfolio views retain reveal classes. Deterministic design-floor checks produced no findings.

- Bakery: framed cards, thumbnail rows, and mobile stacking render. Two homepage featured-card images were removed by collect-images because the authored media had no AI_IMAGE generation specification. Their text cards survive, leaving uneven imagery. Validation also reports a broken `/#enquiry` link on About and one heading-scale paragraph. The custom-motion warning incorrectly interprets the request for no animation as an unimplemented animation request.
- Portfolio: borderless images and staggered galleries render, and the inner page stacks on mobile. The mobile homepage hero heading clips: its scroll width is 431px inside a 310px heading box. Document-wide overflow checks alone do not catch this. The header site title and page title both use H1. Validation flags oversized paragraphs. Motion sanity removed four classes to enforce custom-motion ownership and entrance limits.
- Warnings include residual card/item-pattern contracts, transport capability notes, pattern-library cap removals, and tolerated block serializer shapes. See the complete per-site warning JSON. These findings are disclosed; there is no paired baseline render establishing which are regressions.

## Code validation

347 focused tests passed. Full local suite: 4,027 passed, 5 failed, 5 skipped; all five failures were reproduced on untouched pre-change HEAD. Changed PHP syntax and diff whitespace checks passed. Independent read-only review returned no findings. PR CI passed on PHP 8.1 and PHP 8.4.
