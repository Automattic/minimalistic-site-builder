# Plan: content-only section output compiled by PHP

> **Status (2026-09-15):** proposed. Not started. The numbers in *Why* come
> from `projects/*/logs/llms/*.log` on the eight demo builds in `projects/`.

Replace the block markup that the sections step asks the model to write with a
small JSON content document per section. A PHP compiler turns that document
into the same block markup the pipeline delivers today, from the assigned
composition recipe, item pattern, card style, background, and text placement.
The model writes copy and image subjects. The build writes structure.

Scope of this plan: the page sections that `SectionUnit` authors in the blocks
graph. The hero, header, and footer keep their markup path until milestone M4.

---

## Why

The sections step is the largest spend in a build, and its output sets the
wall time.

| Class, per build | Today | Note |
|---|---|---|
| Fresh input | 34K to 42K tokens | full price |
| Cache write | 20K to 22K tokens | build + page layer, once per build |
| Cache read | 57K to 96K tokens | 10 percent price |
| Output | 10K to 17K tokens | the most expensive class, sets wall time |

The output is almost all structure. One `equal-card-grid` section with the
`rule-row` item pattern in `projects/tbilisi2` holds about 150 words of copy
in 4,981 output tokens and took 43 seconds. One third of its bytes are
indentation. 140 lines are container `<div>` tags. Nine ledger rows repeat the
same `wp:columns` scaffold with identical spacing and border attributes.

The pipeline already treats that structure as untrusted. `SectionUnit::finish()`
runs more than twenty repair passes, and `src/Units/GeneratedMarkup.php` and
`src/Units/CardStyleContract.php` hold 470 KB of code that rewrites what the
model wrote. The prompt build layer spends about 13.7K tokens on rules that
exist only to steer that markup: width discipline, the contrast link recipe,
the attribute-light save contract, card anatomy, utility classes, and the line
ration.

A content document removes the structure from the output and removes those
rules from the prompt. The estimate for a section is 200 to 600 output tokens
instead of 1,000 to 5,000, and a build layer of 3K to 4K tokens instead of
13.7K.

## What stays the same

- The step graph, the step id `sections`, the part files under
  `theme/parts/`, and every downstream step. The compiler emits the exact
  markers and hooks the downstream steps read: `section-composition--*`,
  `item-pattern--*`, `item-pattern__item`, `card-style--*`, `card-body`,
  `card-media`, `card-media-tall`, `feature-media`, `copy-flush`, `copy-end`,
  `text-action`, `cta-bottom`, `equal-cards`, `card-highlight`, `cta-panel`,
  `faq-list`, `logo-strip`, `project-meta`, `price-figure`, `step-plate`,
  `device--*`, `surface--*`, `custom-motion`, `hover-lift`, `hover-reveal`,
  `sticky-side`, `masonry-3`, the `anchor`, and the `AI_IMAGE` alt format.
- The three cache layers (site, build, page) and the priming batch in
  `SectionsStep::run()`. The site layer stays byte-identical so the header,
  footer, and hero requests keep their cache hits.
- The page plan. `pages.json` already carries every structural decision the
  compiler needs: `layout_archetype`, `background`, `vertical_density`,
  `item_pattern`, `text_placement`, `primary_action`, `handoff`, and the
  section `role`, `type`, `purpose`, and `content_notes`.
- The per-part fallbacks. A section whose document cannot be compiled takes
  the same path as an unusable markup response today.

## Design

### 1. The content document

One JSON object per section. The shape is shared across archetypes, and a
per-archetype schema restricts it. Example for the `tbilisi2` essentials
section (`equal-card-grid`, `rule-row`, `borderless`, `tinted`, `centered`):

```json
{
  "heading": "Visit us",
  "heading_emphasis": "us",
  "lead": "Everything you need to find the door, choose your hour, and hold a table on the stone lane below Betlemi.",
  "groups": [
    {
      "heading": "Where we are",
      "rows": [
        {"label": "Quarter", "value": "Tbilisi Old Town, Tbilisi"},
        {"label": "Finding us", "value": "A cellar room off one of the carved-balcony lanes, a short walk from the sulphur baths."}
      ]
    }
  ]
}
```

The compiler produces the same 62-block markup the model wrote for this
section today. The document is about 250 tokens.

Common fields:

| Field | Type | Rule |
|---|---|---|
| `heading` | string | required, the section heading |
| `heading_emphasis` | string | optional, one to three words that occur in `heading`; the compiler wraps them in `<span class="emph">` only when the direction carries a Heading emphasis fact |
| `lead` | string | optional, one sentence |
| `paragraphs` | list of string | body copy, count bounded by the archetype's `copy_capacity` |
| `items` | list of item | the repeated content; shape and count bounded by the archetype and item pattern |
| `actions` | object | `primary` is filled only when the plan assigned a `primary_action`; `text_links` is a bounded list of `{label, href}` |
| `images` | list of image | `{slot, subject, page_context, style}`; slots are named by the archetype; the compiler adds the ratio, the filename, and the alt format |
| `choices` | object | the few bounded layout choices the archetype leaves open, as enums |

Item shapes by item pattern:

- `card`: `{heading, text, list?, link?, image?}`
- `rule-row` and `spec-table`: `{label, value}`
- `tag-cluster`: `string`

Archetype-specific fields, from the recipes in `prompts/section-compositions/`:

| Archetype | Items | Choices | Image slots |
|---|---|---|---|
| asymmetric-split | support list or facts | `regions: 2|3`, `widths: 34/66|40/60|50/25/25|60/20/20` | `lead`, `note-1`, `note-2` |
| bento-grid | five cards | `rows: 2+3|3+2`, `highlight: index` | one per card |
| cta-panel | none | none | `panel` |
| equal-card-grid | two to four cards | `count` | one per card |
| faq-split | three to seven `{question, answer}` | `widths: 40/60|34/66` | `intro` |
| feature-row-hairlines | three or four `{heading, text}` | `count` | none |
| full-bleed-cover | none | none | `cover` |
| logo-strip | four to eight names | none | none |
| offset-grid | two to twelve `{caption, image}` | `stagger: 3rem|4rem` | one per item |
| pricing-tiers | two or three `{plan, price?, period?, features[], label}` | `recommended: index` | none |
| project-grid-2x2 | two or four `{name, meta, image}` | none | one per tile |
| stat-ledger | three or four `{figure, label}` | `count` | none |
| zigzag-steps | three to five `{heading, text, image?}` | none | one per step |

The counts come from the constants that already exist in
`src/SectionComposition.php` (`FEATURE_ROW_COUNTS`, `STAT_LEDGER_COUNTS`,
`PRICING_TIER_COUNTS`, `ZIGZAG_STEP_COUNTS`, `PROJECT_TILE_COUNTS`,
`LOGO_STRIP_COUNTS`, `FAQ_MIN_ITEMS`, `min_images`, `max_images`).

Inline markup in strings: the compiler accepts `<em>`, `<strong>`, and `<a href>`
whose `href` is one of the site's page paths or a mailto to an exact spec
email. It removes everything else. The model does not write `<span class="emph">`;
it fills `heading_emphasis`.

### 2. The schema

`SectionContentSchema::for(archetype, itemPattern, cardStyle, background,
placement, planFields)` returns a JSON schema. The request carries it as
`json_schema`, which `AnthropicClient::bodyFor()` already maps to
`output_config.format`, and which `ClaudeCliLlm`, `CodexCliLlm`, and
`HarnessCliLlm` already forward. Every list gets `minItems` and `maxItems` from
the catalog. Every enum choice is closed. `additionalProperties` is false.

Required fields are the minimum the recipe needs. A model that omits an
optional field gets the compiler's default, not a repair warning.

### 3. The compiler

New namespace `Automattic\SiteBuild\SectionCompile`:

- `SectionCompiler::compile(SectionContent $doc, SectionAssignment $a): string`
  returns block markup. `SectionAssignment` is a value object read from the
  plan section plus the design direction facts the compiler executes (canvas,
  card style, item pattern, image crop, heading emphasis, device, motion
  profile, writing direction, language).
- One recipe class per archetype under `SectionCompile/Recipes/`, each with
  `compile()` and `schema()`. Thirteen classes.
- Shared builders: `Band` (top-level group with anchor, root markers, surface,
  text color, link colors, margin reset), `CopyStack` (heading, lead,
  paragraphs, placement class, alignment by writing direction), `Card` (the
  four card styles from `prompts/section-guidance/card-*.md`), `ItemRows`
  (rule-row, spec-table, tag-cluster), `ImageSlot` (filename, alt, ratio
  from the crop fact and the slot), `Action` (button or text link by budget).
- Serialization goes through `BlockMarkup::serializeComment()` so the comment
  JSON matches what `BlockMarkup::parse()` round-trips. Output is minified.
  Nothing downstream reads indentation.

The compiler owns every value the prompt today calls "build-owned": surface by
background, text color and link colors on non-base bands, `align` on rows and
copy stacks, `copy-flush` and `copy-end` by placement, the pinned lead from the
catalog's pin directive, the stagger margin on offset-grid, the concentric
radii on framed cards, the `wp:cover` wrapper for an `image` background, the
device class when the plan assigns the carrier, `custom-motion` when the spec
carries an animation request and the model names the slot, and `align:left`
or `align:right` on wrapping paragraphs in centered stacks.

### 4. The prompt

New template `prompts/section-content.md` with the same four cache markers as
`prompts/section.md`:

- Site layer: unchanged, rendered by `AbstractMarkupUnit::commonVars()`.
- Build layer: writing rules only. Language, identity, hard facts, copy
  budgets, sentence case under `sentence` and `tight` treatments, heading
  punctuation, the eyebrow and decorative-number bans, no emojis, image
  subject rules, page-context rules, and the form rule. Target: 3K to 4K
  tokens. Everything about block grammar, attributes, classes, widths,
  contrast, CTA construction, and the save contract is deleted.
- Page layer: unchanged.
- Brief: the section fields, the assignment line, a three to eight line
  "content shape" for the archetype (what the slots are, what a good item
  is), and the item pattern in one line. Target: under 800 tokens. The
  generic background, placement, and rhythm lists move out; the compiler
  executes them.

The composition recipes in `prompts/section-compositions/` stay as the source
of truth for the compiler authors, and each gets a short sibling
`prompts/section-content-shapes/<archetype>.md` for the model.

### 5. Wiring

- `SectionUnit::request()` returns the content request when the content mode
  is on: the new template, `json_schema`, and the same three `cached_prefixes`.
- `SectionUnit::finish()` decodes the document, validates it against the
  schema, compiles it, and then runs the existing repair chain on the compiled
  markup. In milestone M2 that chain stays as a safety net and as a
  measurement: every repair it fires on compiled markup is a compiler bug.
- The decoded document is written to `content/<part>.json` in the project.
  A new tool `bin/compile-sections.php <project>` recompiles every part from
  those documents with no LLM call. This gives the evidence-replay ability the
  parts-based steps lack today.
- Mode selection: `SITE_BUILD_SECTION_OUTPUT=content|markup`, read in one
  place (`StepComposition`), with `--section-output` on `bin/build.php` and
  `bin/build-demos.php`. Default stays `markup` until the M3 gate passes.
  `createProject()` records the mode in `meta.json` beside `graph`, so a
  `--from=sections` resume runs the mode that planned the build.
- Failure path: a document that fails schema validation or compilation goes
  through the existing per-part fallback branch in `SectionsStep::run()`.
  Truncated JSON gets one repair pass through `JsonBatchRecovery`, as the
  page plan does today.
- The cache primer (`warmMarkupCache`, `PrefixPrimingLlm`) needs no change.
  The deepest layered request is still a section.

### 6. Tests

- Unit: one test file per recipe with golden markup fixtures under
  `tests/fixtures/section-compile/`. Each fixture pair is a document plus the
  expected markup.
- Property: for every archetype × item pattern × card style × background ×
  placement that the catalog allows, compile a maximal and a minimal document
  and assert that `SectionComposition::markupWarnings()`,
  `ItemPattern::markupWarnings()`, `CardStyleContract::enforce()`,
  `CardTextContract::enforce()`, and `BandSurfaceContract::enforce()` report
  zero warnings and zero repairs. This is the contract that the compiler
  replaces the fixers.
- Round trip: `BlockMarkup::parse()` of compiled output has no malformed,
  mismatched, or unclosed delimiters.
- Schema: every generated schema is valid JSON schema and every golden
  document validates against it.
- Existing tests to update: `section_unit_test.php`,
  `section_cache_contract_test.php`, `sections_context_loss_guard_test.php`,
  `section_prompt_specifics_test.php`, `sections_test.php`.
- Compare the sorted failure list against trunk, not the count. Trunk carries
  a known baseline of failures.

## Milestones

### M0: spike, go or no-go

1. Hand-write the content document for the `tbilisi2` essentials section and
   the about section.
2. Write a throwaway compiler for `equal-card-grid` with `rule-row` and
   `borderless`, and for `feature-row-hairlines`.
3. Run the compiled markup through `SectionUnit::finish()` and count repairs.
4. Send the two sections through a draft `section-content.md` with a draft
   schema against the live API. Record input, output, and time from the logs.
5. Render both pages with `bin/screenshot.php` and compare to the shipped
   `projects/tbilisi2/logs/home.png`.

Gate: output tokens under 20 percent of today's for both sections, zero
repairs from the existing chain, and a screenshot that a reviewer cannot tell
from the original. If the spike fails the gate, stop and report.

### M1: compiler and schemas

1. `SectionAssignment`, `SectionContent`, and the shared builders.
2. Thirteen recipe classes with `compile()` and `schema()`.
3. Golden fixtures and the property test over the full catalog product.
4. `bin/compile-sections.php`.

No prompt or wiring change. The compiler ships dark.

### M2: prompt and wiring behind the flag

1. `prompts/section-content.md` and the thirteen content-shape fragments.
2. `SectionUnit` content mode, `content/<part>.json` artifacts, mode in
   `meta.json`, the CLI flag.
3. The repair chain stays on and its repair counts land in `warnings.json`
   under a `section-compile` key.
4. Build three demos in content mode and fix every repair the chain reports.

### M3: cohort A/B and default flip

1. Build the full `eval/theme-prompts.json` cohort twice from the same trunk
   commit, markup and content mode, with images.
2. Compare per build: sections fresh input, cache write, output, sections
   wall time, warning count, repair count, and desktop plus mobile
   screenshots.
3. Critique both cohorts with the design-quality-loop rubric, blind to mode.

Gate: output tokens down at least 70 percent, fresh input down at least 50
percent, sections wall time down at least 40 percent, warnings not up, and
no rubric score down by more than half a point on any site. On pass, flip the
default to `content`.

### M4: prune and extend

1. Delete the prompt prose and the fixers that never fired across M2 and M3
   in content mode. Each deletion is one PR with the fixer's last-fired
   evidence.
2. Hero: `HeroBlueprint` already carries structured creative parameters and
   `HeroFallback` already renders markup. Move the hero to a content document
   plus compiler, then the footer, then the header.
3. Remove markup mode once one release cycle passes with no fallback to it.

## Risks

- **Layout choices the model made for free.** Column ratios, internal
  spacing, the two- or three-region reading of a split, and the ordering of
  card content were the model's. The `choices` enums keep the ones the
  recipes name. Anything else becomes a compiler default derived from the
  density and the direction. The M3 rubric is the check that this does not
  flatten the sites.
- **Underspecified recipes.** `asymmetric-split` and `offset-grid` describe
  intent more than structure. The compiler has to pick one construction per
  choice, and the M0 spike does not cover them. Schedule them first in M1 and
  render them early.
- **Inline links in copy.** The model writes `<a>` inside paragraphs today.
  The allowlist keeps internal and mailto links and strips the rest. Broken
  link markup inside a JSON string is more likely than inside HTML; the
  sanitizer must be tolerant.
- **Structured output on non-Anthropic transports.** The CLI harnesses
  forward `json_schema`, but their conformance is not measured for a schema
  this size. Run `bin/llm-conformance.php` against each transport in M2.
- **Two output modes in the tree.** Until M4 the unit carries two request
  builders and two finish paths. Keep them in separate methods with one
  switch, and keep the flag in one reader.
- **The repair chain hides compiler bugs.** In M2 the chain repairs what the
  compiler got wrong and the site still ships. That is why the repair counts
  go to `warnings.json` and why M3 gates on them.

## Out of scope

- The hero, header, and footer before M4.
- The HTML-first graph. Its failed-page reroute uses `SectionUnit` and gains
  the mode for free; it is not a test target.
- Model tier changes. The A/B runs on the same model in both modes.
- Trimming the site layer or the header, footer, and hero unit layers. Those
  are separate levers with their own plans.

## Open questions

1. Should the model keep any spacing choice inside a composition, or does
   density alone decide it? The plan assumes density alone.
2. Should `text_links` allow external URLs from the site spec? The plan
   assumes internal paths and mailto only, as the current rules state.
3. Is the `content/<part>.json` artifact committed to the project as a
   build output, or logged only? The plan assumes a build output, because the
   replay tool depends on it.
