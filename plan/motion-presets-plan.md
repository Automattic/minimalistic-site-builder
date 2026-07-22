# Plan — Motion presets: transitions & animations in generated sites

Add tasteful, coherent motion (scroll reveals, hover micro-interactions,
ambient/hero effects, page-load entrances) to generated sites **without
spending inference time or tokens writing animation code**. All animation CSS
and JS is hand-written once in this repo and shipped verbatim; the LLM only
makes cheap, bounded choices. The single exception is an escape hatch for
explicitly animation-specific user requests.

---

## Why

**The problem.** Before this work, generated sites were static. Their only
motion came from `hover-lift` / `hover-reveal` transitions emitted by the
page-styles step, and the generated-CSS validator explicitly forbade
`@keyframes` — so richer motion could not emerge from the old contracts.

**The constraint.** Motion code is a bad fit for per-site LLM generation:
it's expensive to produce, hard to validate, easy to get subtly wrong
(accessibility, content hidden when JS fails), and most sites need the same
handful of well-crafted effects anyway.

**The approach.** Treat motion like the design system treats color and type:
a fixed, hand-authored vocabulary that per-site decisions merely *select
from*. Variation between sites comes from a **motion profile** chosen by the
design direction plus the motion classes selected for each composition. Each
profile owns its CSS custom properties and choreography; generated page CSS
cannot override them. The LLM never writes keyframes.

---

## Decisions already made

- **Motion scope:** all four kinds — scroll-entrance reveals, hover
  micro-interactions, ambient/hero motion, page-load entrances.
- **JS policy:** a static, hand-written JS file (IntersectionObserver)
  is acceptable in generated themes. It is written once here, never by the
  LLM.
- **Who decides:** the design-direction step picks a motion profile from a
  fixed list; the per-section LLM calls place the classes themselves
  (Option B in §3), backstopped by a deterministic sanity pass.
- **CSS source:** static presets with profile-owned entrance, hero, hover, and
  ambient clocks. Generated page CSS cannot tune motion.

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
- A `:focus-within` escape immediately reveals any delayed target that receives
  keyboard focus, so stagger timing can never create an invisible focus stop.

`assets/motion/profiles/{calm,energetic,dramatic,minimal}.css` — each is a
single `:root` block selecting a profile-specific keyframe family and setting
separate entrance, hero, hover, and ambient durations plus easing, distance,
stagger, scale, and hover-character tokens. Calm uses soft settling, energetic
uses diagonal spring/overshoot, and dramatic uses directional masks plus a
focused hero reveal, so the profiles are not one animation at different speeds.
Keeping those clocks separate prevents an energetic entrance from making an
ambient zoom frantic, or a dramatic reveal from making a hover feel sluggish.
All four are testable once, here, not per-site.

`assets/motion/motion.js` — a hand-written IntersectionObserver driver
adds `.is-visible` after a target reaches the main 75% of the viewport and
checks `prefers-reduced-motion`. The vertical inset is calculated in pixels
from viewport height because IntersectionObserver percentage margins are
width-relative; an effectively-zero positive threshold rejects zero-area edge
contact while remaining reachable by extremely tall targets. Cards entering
in the same visual row cascade together, while each
direct child of a responsive `stagger-children` grid is observed independently
so stacked rows do not finish offscreen or inherit an absolute child-index
delay. Initial/restored-scroll targets are made immediately static (the hero
owns load motion), short final-page targets are rechecked after scroll/load/
layout changes, keyboard focus persistently skips its owning entrance, and
observer errors fail open. Elements are only initially hidden
under a driver-owned `html.motion-js` bootstrap scope. After DOM setup, only
the snapshotted `.motion-target` elements remain eligible to hide, so blocks
inserted later fail open. If JS never loads or observer setup fails, everything
stays visible. This preserves the spirit of the existing "never `opacity:0`"
validator rule while allowing real entrance reveals (progressive enhancement).

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

**DECIDED: Option B — the per-section LLM places the classes itself.**
(Options A and C below are kept for the record; neither is built.)

The documented utility-class vocabulary in `prompts/section.md` now includes
the motion classes (one-line contract + usage guidance each); each section's
concurrent call may attach them via `"className"` in block JSON — exactly the
mechanism `hover-lift` uses today, so they survive the `fix-blocks`
re-serialization. Placement is intentional (the model knows which block is
the section's focal grid, which cover is the hero) at a cost of ~250 prompt
tokens per section call.

Option B's known weakness — independent concurrent calls can't coordinate
site-level coherence — is mitigated on two fronts:

1. **Prompt-side budget rules** in `section.md`: at most one motion class per
   block; one or two entrances per section; `stagger-children` only on
   containers whose direct children are cards/columns; the ambient classes
   are signature effects (at most ONE per page, focal moment only — each
   section sees its neighbors' assignments for that rhythm call);
   `hero-entrance` once, first section only; never `is-visible`, never
   invented names.
2. **A deterministic sanity pass** (`MotionSanityStep`) runs BEFORE
   `fix-blocks` (so re-serialization syncs HTML with the edited block JSON —
   same ordering rationale as contrast-fix). It only ever REMOVES classes:
   unknown motion variants and `is-visible`, classes the committed profile
   disallows (`minimal` → hover effects only, `none` → strip all), ambient
   effects and `hero-entrance` beyond the first on the page (sections visited
   in plan order, so the hero wins), entrances beyond two per section, extra
   motion classes beyond one per block, transform-conflicting ambient/hover
   pairs on the same block, and `stagger-children` on containers with fewer
   than two children.

**Option A — deterministic post-processor (not built).** An
`ApplyMotionStep` assigning classes by rule: hero → `hero-entrance`,
card/column grids → `reveal-up stagger-children`, other sections →
`reveal-fade`. Zero tokens and fully predictable, but placement is blind to
content.

**Option C — hybrid (not built).** Deterministic defaults from A; the
section prompt only mentions the *ambient* classes.

### 4. Profile-owned choreography

The static kit implements hover as well as entrance and ambient effects. The
profile stylesheet is the only source of `--motion-*` values, including the
animation-name tokens that select deliberately different keyframe families as
well as distinct pacing and magnitude for calm, energetic, dramatic, and minimal.
`PageStylesStep` handles structural layout utilities only and rejects `:root`,
so model output cannot collapse two profiles onto the same timing or silently
disable hover motion when its CSS appendix is rejected.

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
| Default site | ~10 tokens (motion field) + ~250 prompt tokens × each section call (Option B vocabulary); **zero new LLM calls** |
| Site with explicit animation request | + one scoped CSS-generation call |

---

## Build order

1. **Motion kit.** Write `motion.css`, the four profile files, and
   `motion.js`; wire `ScaffoldThemeStep` / `FinalizeThemeStep` to ship and
   enqueue them. Works standalone — hardcode `calm` and rebuild a project to
   see it immediately.
2. **Profile selection.** Add the `motion` field to the design-direction
   step (normalize, format, seeds).
3. **Application (Option B, decided).** Motion vocabulary + budget rules in
   `prompts/section.md`; `MotionSanityStep` before `fix-blocks` with fixture
   tests (over-use trimmed, profile gating, unknown classes stripped).
4. **Profile choreography.** Split timing by motion category, tune each static
   profile distinctly, and keep hover behavior in the reduced-motion-safe kit.
5. **Escape hatch.** Add the flag to refine-prompt/site-spec and the
   optional `CustomMotionStep`. Independent of everything else — last.

The step-3 fork is resolved: Option B (section LLM placement, budget rules in
the prompt, deterministic sanity pass as backstop) shipped; A/C were not
built.
