# Plan — Move Telex's Design Intelligence into builder2

Why we're doing this, what moves, and how — at the plan level. The line-by-line
execution guide lives in [`../docs/telex-design-intelligence-port.md`](../docs/telex-design-intelligence-port.md);
this document is the rationale and strategy behind it.

Based on the Telex revision checked out at `/home/matias/dev/a8c/telex/`
(trunk @ `624366e7`); its prompts are in `server/prompts/`.

---

## Why

**The problem.** builder2's pipeline is sound and its prompts are clean, but the
themes it produces trend generic — safe palettes, default heroes, timid
typography. This is the "AI slop" failure mode: the model, left with thin
guidance, converges on its distribution mean.

**The opportunity.** Telex already solved this. Its one-shot assistant produces
distinctive, topic-grounded themes because its prompts carry a large, battle-
tested body of *design intelligence*: anti-slop rules, typographic scales, hero-
composition theory, color strategy, layout-width discipline, and concrete layout
recipes. That intelligence is portable knowledge, not Telex-specific plumbing.

**Why it transfers cleanly.** builder2 and Telex already run the **same
conceptual pipeline**:

> spec → commit a design direction → theme.json tokens → plan sections →
> generate markup → assemble → images.

Because the *shape* already matches, we don't need Telex's architecture — only
the guidance that rides inside its prompts. This is a **leverage move, not a
rewrite**: enrich builder2's existing prompt files with Telex's design
intelligence, adapted to builder2's constraints. The PHP pipeline barely changes.

**Why now / why prompts.** The cheapest, highest-leverage quality gain available
is prompt density. The same models, the same steps, the same outputs — but far
better instructions at the two or three points where design quality is actually
decided (the design direction, the tokens, and the section markup).

---

## Guiding principles

- **Prose, not plumbing.** We move *guidance text* into existing prompts. We do
  **not** copy Telex's task queue, streaming, artefact XML, or two-request pick.
- **Enrich, don't replace.** builder2's structural rules (fixed color/font
  slugs, core-blocks-only, "output starts with `<!-- wp:`") are invariants. The
  design intelligence steers *values and choices* within those rules.
- **Translate to builder's vocabulary.** Telex assumes Tailwind, custom blocks,
  multi-page, and a `<style>`-friendly artefact. builder2 is core blocks +
  theme.json tokens + one landing page. Every ported rule is re-expressed in
  that vocabulary or dropped.
- **Concentrate energy at the decision points.** Most quality is won in three
  files: `design-direction.md`, `theme-json.md`, `section.md`. Spend effort
  there first.
- **Keep it re-runnable and verifiable.** Every step still reads/writes files
  and can run in isolation; success is judged by rebuilding the demo set and
  looking.

---

## What moves, and why

| Telex source (design intelligence) | Moves into | Why |
|---|---|---|
| `style-directions.md` — design grounding, anti-patterns, hero theory *(prose only)* | `design-direction.md` | The creative seed every later step reads; grounding + anti-slop rules are what stop convergence. |
| `create-project-theme.md` — "Design Thinking", "Frontend Aesthetics" | `design-direction.md` + `theme-json.md` | Commitment to a bold, specific concept and its translation into real tokens. |
| `create-project-theme.md` — type scale, line-height, layout widths, color strategy | `theme-json.md` | Encodes taste as concrete, reusable tokens downstream markup relies on. |
| `create-project-theme.md` "Generating pages" + "Card layouts" + `design-previews.md` hero-variety & visual-richness | `section.md` | Where most quality is won or lost: hero composition, section discipline, equal cards, atmosphere. |
| `create-project-theme.md` — nav/page-list, sticky nav, footer credit | `header.md` / `footer.md` | Small, high-visibility polish. |
| `create-project-theme.md` — "home is the centerpiece", type-specific sections | `section-plan.md` | Focuses builder's single page's energy and section choice. |
| `image-generation-instructions.md` | `image-generation.md` | Consistency pass only — builder's version already exceeds Telex's. |

## What stays out, and why

- **The 4-design fan-out + human pick** (`style-directions.md`'s 4-way
  generation, `design-previews.md` HTML previews, `design-selection.md`,
  `design-acknowledgment.md`, `GenerateDesignPreviewsTask`, `selectDesign`).
  *Explicitly excluded by the request* — and builder2 never had it. builder2's
  `DesignDirectionStep` already commits to **one** direction, which is the
  single-design outcome we want. We keep the *prose* from those files (hero
  theory, anti-patterns) and drop the mechanism.
- **Artefact transport** (`--- artefact.xml ---`, `spec/artefact*.md`,
  `artefact.xsd`, stream processor) — Telex-specific; builder writes files.
- **Guide selection** (`guide-selection.md`, `guides/*.md`,
  `ContextGuideService`) — targets multi-feature builds; out of scope.
- **Multi-page / content-loader / CPTs / data-persistence / custom blocks /
  Interactivity API** — builder builds one landing page from core blocks.
- **Project-type classification, cover/description generation, the Telex
  persona** — not part of builder's job.

Reason in one line: **keep the knowledge, drop the architecture.**

---

## How — phased execution

Ordered by leverage, so quality shows up early and each phase is verifiable
before the next.

1. **Design direction** (`design-direction.md`). Fold in topic grounding,
   anti-patterns, and hero-composition theory; require the brief to name a
   signature device, a hero strategy, and a cliché-to-avoid. *Highest leverage —
   everything downstream reads this file.*
2. **Tokens** (`theme-json.md`). Encode the type scale, line-heights, container-
   width ranges, color strategy, and gradient/shadow presets — while preserving
   the fixed slug contract.
3. **Section markup** (`section.md`). Section discipline (margin reset, width
   rules, no decorative comments, no emojis), hero-layout variety, the equal-
   cards recipe, and visual-richness-via-tokens. The richest port.
4. **Header / footer** (`header.md`, `footer.md`). Page-list nav default,
   optional sticky, styled credit line.
5. **Section plan** (`section-plan.md`). "Centerpiece" energy and type-specific
   section menus for the single page.
6. **Image instructions** (`image-generation.md`). Consistency pass only.

Two supporting changes fall out of the port (both code, both small):

- **CSS home for effects.** Core blocks can't carry `<style>`, so the equal-
  cards CSS (and any CSS-only effect) is appended to the scaffolded
  `theme/style.css` (`ScaffoldThemeStep`).
- **Fonts must load.** builder has no `functions.php`, so distinctive fonts are
  referenced but not enqueued. Resolve with either a generated `functions.php`
  enqueue (Telex's approach) or `fontFace` `src` URLs in theme.json. **Open
  decision — confirm with the user.**

---

## Adaptation strategy (Telex assumption → builder reality)

| Telex assumes | builder reality | Resolution |
|---|---|---|
| Tailwind utility classes / motion | Core blocks only | Express via theme.json presets, `wp:cover` (parallax/dim/gradient), inline `style`, spacing rhythm; CSS-only effects go in `style.css`. |
| Arbitrary hex / named fonts in markup | Fixed slugs (`base/contrast/primary/secondary/accent`, `heading/body`) | Concrete hex + font names chosen once in `theme-json.md`; markup references **slugs** only. |
| `<style>` blocks in the artefact | No place for them in parts | Append shared CSS to `theme/style.css`. |
| Google Fonts enqueued via `functions.php` | No `functions.php` | Add enqueue step or theme.json `fontFace` src (open decision). |
| Many pages, CPTs, custom blocks | One landing page, core blocks | Concentrate "centerpiece" energy into the hero + a richer section set. |

---

## Risks & mitigations

- **Prompt bloat / instruction dilution.** Adding lots of prose can bury the
  hard rules. *Mitigation:* keep builder's terse "Output ONLY …" contracts
  intact and at the end; add guidance as tight bullets, not walls of text.
- **Fonts referenced but not rendered.** Cosmetic-only typography if not
  enqueued. *Mitigation:* the fonts-loading decision is a first-class task, not
  an afterthought.
- **Slug-contract drift.** Injecting "use specific hex/fonts" language could
  tempt the model off the slug system. *Mitigation:* explicitly redirect concrete
  values to `theme-json.md`; keep `ThemeValidator` as the gate.
- **Over-porting.** Pulling in multi-page/CPT rules that don't apply. *Mitigation:*
  the "stays out" list is explicit; scope every rule to a single core-block page.
- **Placeholder breakage.** `PromptRenderer` fails loud on any unresolved
  `{{placeholder}}`. *Mitigation:* the port adds prose, not new placeholders;
  don't introduce a placeholder without wiring it in the step.

---

## Success measure

Rebuild the demo set and judge by eye and by validator:

```bash
php bin/build-demos.php --with-images   # writes projects/<slug>/logs/home.png
php tests/run.php
```

We've succeeded when:

- Two different prompts yield **visibly different** directions (no convergence).
- Heroes are **not** the default image-right/centered-sans layout.
- Typography is distinctive and sanely scaled; palettes are cohesive with accent
  reserved for CTAs; no purple-on-white / generic blue-gray.
- Cards in rows are equal-height/equal-width; no emojis; no decorative comments;
  widths in the 800 / 1200–1400 ranges; chosen fonts actually render.
- `ThemeValidator` reports no problems and the test suite is green.

The bar is simple: builder2 output should be indistinguishable in quality from
Telex's one-shot themes — same design intelligence, builder2's architecture.
</content>
