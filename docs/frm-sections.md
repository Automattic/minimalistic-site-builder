# Section variety from frm_experiment

`frm_sections` starts at trunk commit `e5023c1d`.
The source experiment is `origin/frm_experiment` at `08c9faf0`.

The extraction expands the section catalog from six compositions to sixteen.
It uses the existing page-plan and section steps. It adds no LLM calls or pipeline steps.

| Composition | Content and structure |
| --- | --- |
| `bento-grid` | Two unequal card rows with one highlight |
| `faq-split` | An introduction beside a native details accordion |
| `cta-panel` | One contained final invitation with one action |
| `pricing-tiers` | Two or three plan cards with one recommended plan |
| `stat-ledger` | Three or four supplied figures with short labels |
| `feature-row-hairlines` | Three or four text columns with borders |
| `zigzag-steps` | Three to five steps with alternate text and image positions |
| `statement-lines` | Three to six large statement lines |
| `project-grid-2x2` | Two or four cover tiles with project text |
| `logo-strip` | Four to eight names as text wordmarks |

The page plan selects compositions from content. A type that names a composition takes that composition.
An explicit card highlight reaches the relevant section through the plan and section prompt.
Repeated lists move from asymmetric splits to card grids or thumbnail rows.
Recipes without cards release the item pattern. Bento and price recipes keep their card structure under a ruled site pattern.

`centered-stack` serves one short message. Mechanical repairs no longer choose it for content of unknown complexity.
The extraction also includes the styles, shape scale, image limits, and card text repairs that these recipes require.
Repairs preserve safe content and report removals in `warnings.json`.
Project tile color repairs reach a fixed point.
A closing panel with no image centers its heading, its lead line, and its action.
A closing panel with an image bleeds that image to the panel edges under every card style except `framed`.

The hero, header, footer, image-generation, motion, and site-identity experiments remain outside this extraction.
Section badges, side labels, and step numeral tokens also remain outside it.
The HTML-first graph keeps its existing design process. Its blocks fallback can use these section recipes.

Tests cover composition structure, prompt variables, plan corrections, responsive CSS, repair boundaries, and durable warnings.
No live site build or visual comparison was run. The tests verify behavior; they do not measure design quality.

Validation: 307 focused tests passed. The full suite reported the same 61 failures as the trunk baseline, with no new failures.
