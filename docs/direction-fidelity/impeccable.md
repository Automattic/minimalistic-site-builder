# Impeccable: what to import

Read of [pbakaus/impeccable](https://github.com/pbakaus/impeccable)
(Apache-2.0, skill v4.1.1). A large agent skill for frontend craft:
modes (Persuade / Operate / Read / Experience), a concept-seed
tournament, a mechanical detector, and a finish-reviewer loop.

This is **not** a dump of their command table, `DESIGN.md` /
`PRODUCT.md` ceremony, or `detect.mjs` into the build. We take the
mechanisms that close *say it → ship it* and leave the interactive
agent workflow.

Taste-skill and impeccable overlap on the same tells (Fraunces,
cream ground, motion claimed but not shown). Where they disagree
we keep our taste lock.

## What it is

A design director skill for a coding agent:

1. The brief wins. Pinned materials beat a saturated-pattern warning.
2. Pick a visitor mode, then a visual world, then build.
3. A craft floor of countable checks (contrast, measure, motion,
   browser chrome) plus a refuse list of category defaults.
4. Bounded inspect-and-fix, not an open polish loop.

Their new-work flow is a concept tournament with dice. Ours is
already a three-seed pick. Do not vendor their script.

## Already ours

Do not re-import these. We already have the equivalent.

| Impeccable | Here |
|---|---|
| Brief wins | `design-direction.md` + seed honor rule |
| Three divergent proposals | `design-direction-seeds.md` |
| Contrast floor | contrast-fix + theme.json WCAG lines |
| Motion profiles + reduced motion | `motion` field + motion kit |
| One authored motion moment | motion budget + `motion_note` map |
| Shared image grade | `image_grade` + ImagePromptComposer |
| Never abort on generated defects | AGENTS.md ladder |
| Mechanical pre-flight | `direction-fidelity` |
| Grain as a fixed overlay | `surface` kit |

## Take (maps onto 01–10)

### The default-face cluster is larger than Fraunces

Taste-skill named Fraunces / Instrument Serif. Impeccable's
training-data list is the rest of the same pile: Playfair Display,
Cormorant, Lora, Crimson, Newsreader, Syne, Space Grotesk, Space
Mono, IBM Plex as display, Inter-as-display, DM Sans, DM Serif,
Outfit, Plus Jakarta Sans, Instrument Sans. A subject association
is never the reason ("books want a serif"). Ban them as
*unjustified* defaults. The brief naming the face still wins.

Goes into [01](01-third-font.md) and
[03](03-bind-hexes-and-fonts.md), as a seed-level rotation, not a
theme.json overwrite.

### Three aesthetic clusters, not one cream family

Where the brief leaves the world free, landing in one of these
means the self-check failed:

- warm cream ground + high-contrast serif + terracotta / signal-red
- near-black + one neon accent + glowing edges
- broadsheet hairlines + italic display serif + small tracked mono
  labels

We already rotate cream/brass/espresso. Add the other two clusters
to `design-direction-seeds.md`. Keep any cluster the brief names.

### Light or dark from the use scene

"Pick it from who, where, under what ambient light." Category
habit (bakery = cream, tech = dark) is the same tell as an
unjustified Fraunces. A one-line scene in the direction prompt
is enough. No new field.

### Browser surfaces the model never themes

Craft-floor's cheapest tell: selection, caret, scrollbar, focus
ring, underline offset, tabular numerals. Core and the browser
own those until we paint them from the palette. Always-on
scaffold CSS, same shape as the hamburger overlay. Not a prompt.

### Prose measure and shadow anatomy

- Running copy stays in a 45–75ch measure. `contentSize` 800–900px
  already aims there; HTML-first CSS must not let a paragraph
  span the viewport.
- A shadow has an offset and a soft blur. A zero-offset colored
  halo is decoration. Theme.json already owns shadow presets.

### Gradient text and the side-tab border

Detector slop IDs `gradient-text` and `side-tab`. Neither is a
direction field. Ban them in the HTML-first design prompts so
convert cannot be asked to preserve a tell.

## Might take (later, not this folder)

- **Visitor mode** (`persuade | operate | read | experience`) as a
  direction field that gates motion, color commitment, and hero
  energy. Useful, but a new field. Only promote it when a step
  can execute it.
- **Color strategy** (`restrained | committed | full | drenched`).
  Same bar.
- **More space above a heading than below it** as a rhythm pass.
  SectionRhythm already owns seams; do not fight it from a prompt.
- **Copy self-audit** (no "elevate / seamless / unleash", no
  fake-precise specs). Fits validate-theme. Not a new field.
- Their detector as a local review tool on a finished theme.
  Not a build step. `detect.mjs` reads HTML/CSS and would fire
  on block-comment markup.

## Do not take

- `PRODUCT.md` / `DESIGN.md` / surface briefs / `context.mjs`.
  `designDirection.json` is our contract.
- The concept-seed tournament, QUALITY BAR boards, and
  serve-question UI. We already pick one of three seeds.
- Comp-led pixel matching against generated comps. Wrong
  product; we generate the site, not a mock to chase.
- Eyebrow as an absolute ban. We ration. The quality loop
  already paid for "safer and duller."
- Section numerals as a ban. `device: section-numeral` is the
  one catalogued mark that *is* a promise.
- Dual light/dark, official design-system packages, WebGL /
  view-transitions as a default technique.
- The finish-reviewer / documenter subagent loop. Our inspect
  path is `direction-fidelity` + `warnings.json`.
- Dumping craft-floor into `section.md`.

## License

Apache-2.0. Ideas and catalogs can be adapted. Do not vendor
their `SKILL.md`. Cite the repo in the PR that lands any import.

## References

- Repo: <https://github.com/pbakaus/impeccable>
- Skill: [`.agent/skills/impeccable/SKILL.md`](https://github.com/pbakaus/impeccable/blob/main/.agent/skills/impeccable/SKILL.md)
- Craft floor: [`reference/craft-floor.md`](https://github.com/pbakaus/impeccable/blob/main/.agent/skills/impeccable/reference/craft-floor.md)
- New work: [`reference/new-work.md`](https://github.com/pbakaus/impeccable/blob/main/.agent/skills/impeccable/reference/new-work.md)
- Typeset: [`reference/typeset.md`](https://github.com/pbakaus/impeccable/blob/main/.agent/skills/impeccable/reference/typeset.md)
- Animate: [`reference/animate.md`](https://github.com/pbakaus/impeccable/blob/main/.agent/skills/impeccable/reference/animate.md)
