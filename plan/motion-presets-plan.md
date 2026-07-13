# Plan — Motion presets: transitions & animations in generated sites

Add tasteful, coherent motion (scroll reveals, hover micro-interactions,
ambient/hero effects, page-load entrances) to generated sites **without
spending inference time or tokens writing animation code**. All animation CSS
and JS is hand-written once in this repo and shipped verbatim; the LLM only
makes cheap, bounded choices. The single exception is an escape hatch for
explicitly animation-specific user requests.

---

## Why

**The problem.** Generated sites are static. The only motion today is the
`hover-lift` / `hover-reveal` transitions the page-styles step emits, and the
generated-CSS validator explicitly forbids `@keyframes` — so richer motion
can't emerge from the current contracts even by accident.

**The constraint.** Motion code is a bad fit for per-site LLM generation:
it's expensive to produce, hard to validate, easy to get subtly wrong
(accessibility, content hidden when JS fails), and most sites need the same
handful of well-crafted effects anyway.

**The approach.** Treat motion like the design system treats color and type:
a fixed, hand-authored vocabulary that per-site decisions merely *select
from*. Variation between sites comes from two cheap knobs — a **motion
profile** chosen by the design direction, and a small set of **`--motion-*`
CSS custom properties** the existing page-styles call may tune within
validated numeric bounds. The LLM never writes keyframes.

---

## Decisions already made

- **Motion scope:** all four kinds — scroll-entrance reveals, hover
  micro-interactions, ambient/hero motion, page-load entrances.
- **JS policy:** a small static, hand-written JS file (IntersectionObserver)
  is acceptable in generated themes. It is written once here, never by the
  LLM.
- **Who decides:** the design-direction step picks a motion profile from a
  fixed list; downstream application is deterministic (with a possible later
  hybrid where section prompts place ambient effects).
- **CSS source:** static presets, with light LLM tuning of timing variables
  inside validated bounds, riding the existing page-styles call.

---

## Architecture

### 1. Static motion kit (zero tokens, written once)

`assets/motion/motion.css` — all `@keyframes` and utility classes, driven
entirely by custom properties:

- **Scroll reveals:** `reveal`, `reveal-up`, `reveal-fade`, `reveal-scale`,
  plus `stagger-children` (cascading `transition-delay` on direct children).
- **Page-load:** `hero-entrance` — fires once on first paint, pure CSS, no
  JS dependency.
- **Ambient:** `ambient-drift`, `ken-burns`, `gradient-shift`.
- **Hover:** fold the existing `hover-lift` / `hover-reveal` contracts in so
  their timing also derives from the profile variables.
- Everything wrapped in `@media (prefers-reduced-motion: no-preference)`.

`assets/motion/profiles/{calm,energetic,dramatic,minimal}.css` — each is a
single `:root` block setting `--motion-duration`, `--motion-ease`,
`--motion-distance`, `--motion-stagger`. Tiny, and all four are testable
once, here, not per-site.

`assets/motion/motion.js` — ~20 hand-written lines: IntersectionObserver
adds `.is-visible`; checks `prefers-reduced-motion`. Elements are only
initially hidden under an `html.js` scope that the script itself sets, so if
JS never loads everything stays visible. This preserves the spirit of the
existing "never `opacity:0`" validator rule while allowing real entrance
reveals (progressive enhancement).

Wiring: `ScaffoldThemeStep` copies the kit into the theme;
`FinalizeThemeStep` enqueues the script and the chosen profile only when the
motion profile isn't `none`.

### 2. Design direction picks the profile (~10 extra tokens)

Add a `motion` field to the design-direction expansion output
(`DesignDirectionStep::normalize()` + `format()`), constrained to the fixed
profile list (`calm`, `energetic`, `dramatic`, `minimal`, `none`), plus an
optional one-line motion note ("slow, gallery-like reveals"). Rides the
existing call — no new inference. The seed prompt may mention motion mood so
seeds diverge on it too.

### 3. Applying classes to sections

**Option A — deterministic post-processor (MVP default).** A new
`ApplyMotionStep` runs before `fix-blocks` (so re-serialization syncs HTML
with the edited block JSON, same ordering rationale as contrast-fix). It
walks sections and assigns classes by rule: hero → `hero-entrance`,
card/column grids → `reveal-up stagger-children`, other sections →
`reveal-fade` — profile permitting (e.g. `minimal` gets hover-only). Zero
tokens, fully predictable, testable with fixtures.

**Option B — section LLM places them.** Extend the documented class
vocabulary in `prompts/section.md`. More intentional placement, but costs
~80–150 prompt tokens × every section call and risks inconsistent usage.

**Option C — hybrid (likely end state).** Deterministic defaults from A; the
section prompt only mentions the *ambient* classes (`ken-burns`,
`gradient-shift`), since those are genuinely creative placement decisions
rules can't make well.

Ship A first; C is a follow-up.

### 4. Bounded LLM tuning (rides the existing page-styles call)

Extend the page-styles contract: the model may additionally emit one
`:root { --motion-* }` override block. `PageStylesStep::validate()` gains
numeric range checks — duration 150–1200 ms, distance 8–48 px, stagger
40–150 ms, easing from an allowlist of cubic-beziers. Out-of-bounds values
fall back to the profile defaults silently. Cost: ~100 prompt tokens,
~40 output tokens, no new call.

### 5. Escape hatch for explicit animation requests

`refine-prompt` / `site-spec` (existing calls) flag whether the user's
prompt contains a specific animation request, capturing it verbatim (e.g.
"the logo should spin on hover"). Only when flagged does a new optional
`CustomMotionStep` run: one LLM call generating CSS scoped to a dedicated
`.custom-motion-*` class, validated with a relaxed rule set (`@keyframes`
allowed; still no `display:none`, no `url()`, no hidden-content violations,
capped length). The step simply doesn't exist in the common path, so tokens
are spent only when the user actually asked for animation.

---

## Cost profile

| Path | Extra inference |
| --- | --- |
| Default site | ~10 tokens (motion field) + ~140 tokens (page-styles tuning); **zero new LLM calls** |
| Site with explicit animation request | + one scoped CSS-generation call |

---

## Build order

1. **Motion kit.** Write `motion.css`, the four profile files, and
   `motion.js`; wire `ScaffoldThemeStep` / `FinalizeThemeStep` to ship and
   enqueue them. Works standalone — hardcode `calm` and rebuild a project to
   see it immediately.
2. **Profile selection.** Add the `motion` field to the design-direction
   step (normalize, format, seeds).
3. **Deterministic application.** Add `ApplyMotionStep` (Option A) before
   `fix-blocks`, with fixture tests per archetype × profile.
4. **Bounded tuning.** Extend the page-styles prompt and validator for
   `--motion-*` overrides.
5. **Escape hatch.** Add the flag to refine-prompt/site-spec and the
   optional `CustomMotionStep`. Independent of everything else — last.

Open fork: step 3's end state (pure A vs hybrid C — whether section prompts
get to place ambient effects). Decide after seeing A's output on a few
rebuilt projects.
