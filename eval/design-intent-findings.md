# Design intent evaluation — 2026-09-04

The selection and prompt changes are implemented. Visual acceptance remains open:
the experiment demonstrated a clearer distinction between brutalist and organic
styling, but did not establish strong diversity between two brutalist runs.

## Changes under test

- Explicit `siteSpec.visual_vibe` constrains seed eligibility. Conflicting seeds
  are excluded with actionable warnings; an empty pool expands the requested
  style directly, without an additional model call.
- The requested style travels separately from the generated narrative to
  downstream authors. Candidate variety and industry conventions cannot
  override it.
- The direction model chooses a compatible hero and media proportions. Explicit
  operator and batch assignments retain their existing precedence; malformed
  generated choices receive a warned deterministic fallback.
- Valid short pages are no longer padded with generic sections. The homepage
  prompt no longer requires three image-rich content sections or five to eight
  sections, and the last section need not be a dedicated CTA.

## Experiment

Three new blocks builds, using `eval/design-intent-prompts.json`: two identical
brutalist coaching briefs and the same business with an organic-style request.
No existing projects were modified. Codex subscription transport used
`gpt-5.4-mini` for planning/seeds and `gpt-5.5` for design/markup, not the
Haiku/Opus models recorded in `fleet-willow` and not GLM Flash.
There were 44 model requests across the three builds, with no paid image calls.

| Project | Hero | Heading / body | Page sections | Studio URL |
| --- | --- | --- | --- | --- |
| intent-brutalist-a | layered-poster | Chivo / IBM Plex Sans | 5 | http://localhost:8886/ |
| intent-brutalist-b | layered-poster | Chivo / IBM Plex Sans | 5 | http://localhost:8887/ |
| intent-organic-a | foreground-split | Literata / Source Sans 3 | 4 | http://localhost:8888/ |

The brutalist outputs use heavy uppercase sans-serif hierarchy and hard-edged,
compressed sections. The organic output uses a green palette, serif hierarchy,
softer surfaces and a split opening. Within brutalism, typography and hero
structure still converge despite differences in light/dark ground, footer and
section sequence. The results do not establish that relaxing constraints alone
will produce beautiful or consistently distinct compositions.

## Important experiment limitation

All three builds exposed a false rejection in the first implementation of the
style guard. Their specs said `brutalist styled`, `bold brutalist`, and
`professionally, organically styled`, while candidates used the shorter labels
`brutalist` and `organic`. The builds therefore exercised the direct-style
fallback rather than the compatible-seed selection path.

That comparison bug was subsequently fixed and covered by a failing-then-passing
regression. Replaying the saved candidates through the corrected guard retained
all three candidates in each brutalist round and the organic candidate in the
organic round; its editorial and archival alternatives were excluded. This
replay made no model calls. The full builds were not repeated after that fix, so
these screenshots are not end-to-end acceptance evidence for the final selector.

## Rendering checks

Desktop (1440px) and mobile (390px) screenshots were inspected. No desktop
horizontal overflow was detected. The initial mobile capture of brutalist A
reported horizontal overflow; the closing heading needs a fresh check with fonts
settled. Both other mobile captures reported no horizontal overflow.

Images were deliberately not generated. Broken-image/alt-text placeholders
significantly distort thumbnail rows, especially on mobile. These captures can
inform typography and broad composition review, not finished photographic
balance or beauty. Evidence is outside the repository at
`/tmp/fleet-willow-review.wIwhIW/intent-*-desktop.png` and
`/tmp/fleet-willow-review.wIwhIW/intent-*-mobile.png`.

## Verification and next gate

- Focused suite: 232 passed, zero failed.
- Full suite: 3,937 passed, five failed, five skipped. The failures are the same
  pre-existing manifest rollback, Studio/PHP-deprecation and billing ancestry
  failures recorded before these changes.
- Changed PHP syntax checks and `git diff --check` passed.
- Regression evidence: `/tmp/msb-design-intent-red.log`,
  `/tmp/msb-design-intent-grammar-red.log`,
  `/tmp/msb-design-intent-focused4.log`,
  `/tmp/msb-design-intent-full-final.log`.

Next acceptance check: fresh repeated-brief builds on the final selector, ideally
using the user's actual target model and image setup. Judge requested-style
fidelity, within-style composition diversity, content completeness and mobile
layout independently. Do not use different labels, colors or fonts as an
automatic aesthetic pass. Keep safety, parseability and caller constraints intact.

Read-only comparison command:

```sh
php eval/design-intent-report.php fleet-willow intent-brutalist-a intent-brutalist-b intent-organic-a
```

The generated projects and Studio sites remain available. No commit or push was
made, and no review screenshots were added to the repository.
