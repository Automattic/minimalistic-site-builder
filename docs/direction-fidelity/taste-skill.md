# Taste-skill: what to import

Read of [Leonxlnx/taste-skill](https://github.com/Leonxlnx/taste-skill)
(MIT, 2026). Portable agent skills that push AI frontends off the
default template. The default install is **v2 experimental**
(`design-taste-frontend`). v1 is pinned as
`design-taste-frontend-v1`. Sibling skills cover image comps,
redesign audits, a "soft" luxury look, and GPT-stricter rules.

This is **not** a dump of their 87 KB `SKILL.md` into our prompts.
Their own changelog says v1 was easy for agents to skim past. We
take the mechanisms that close *say it → ship it*. We leave the
React / Tailwind / GSAP stack and the rules that fight our taste
lock.

## What it is

A one-file design operating system for a coding agent:

1. Infer a one-line design read from the brief.
2. Set three dials: `DESIGN_VARIANCE`, `MOTION_INTENSITY`,
   `VISUAL_DENSITY`.
3. Apply hard "AI Tells" bans found in production tests.
4. Run a mechanical pre-flight checklist before shipping.

The [Floria example](https://github.com/Leonxlnx/taste-skill/tree/main/examples)
is an editorial flower site: big type, one photo, lots of air.
That is the ceiling they aim at. Our demos (Hearth, Naturaleza,
Jujubas) sit on the other side: cream ground, split hero, brown
button.

## Already ours

Do not re-import these. We already have the equivalent.

| Taste-skill | Here |
|---|---|
| Color / shape consistency lock | `palette` + `shape` + contrast-fix |
| Hero = H1 + short standfirst + CTA | `hero.md` + BIGR-791 |
| Eyebrow ration | `section.md` ("eyebrows are rationed") |
| No em dash in headings | BIGR-790 |
| No 3-equal-card default as the only recipe | page-plan archetypes + card recipes |
| Motion profiles + reduced motion | `motion` field + motion kit |
| Image grade shared across a site | `image_grade` + ImagePromptComposer |
| Block catalog with named recipes | `prompts/hero-compositions/`, footers, section archetypes |
| "Never abort on generated defects" | AGENTS.md ladder |

## Take (maps onto 01–10)

Each row is something they do that we still lose between direction
and the shipped page.

### Bind type and palette so they cannot silently default

Their production tests named two tells we still ship:

- **Fraunces / Instrument Serif as the creative default.**
  `clever-ember` (Jujubas) committed Fraunces. Ban it as an
  *unjustified* default, not as a serif. Heritage / editorial /
  the brief naming the face still wins.
- **Warm cream + brass + espresso on every bakery / craft
  brief.** Exact hex families they ban as the default reach:
  `#f5f1ea`, `#faf7f1`, `#b08947`, `#1a1714`. Hearth, Naturaleza,
  and Jujubas are this family. Rotate palette *families* in
  `design-direction-seeds.md`. Keep the family when the brief
  names those colors.

Goes into [01](01-third-font.md) and
[03](03-bind-hexes-and-fonts.md). Also a seed-level change, not
only a theme.json overwrite.

### Surface as a fixed overlay, not a sentence

They implement grain as a `position: fixed; pointer-events: none`
pseudo-element. Never on a scrolling container (GPU cost). That
is the exact CSS shape for [02](02-surface.md). Steal the
implementation, not the "ethereal glass" vibe.

### Motion claimed = motion shown

If `MOTION_INTENSITY > 4` and the page does not move, drop the
dial and ship static. Never leave a half-built animation. That is
[05](05-motion-note.md) in one sentence. Their extra useful bit:
every motion class must answer "hierarchy, story, feedback, or
state." If it cannot, strip it.

### Mechanical fidelity pre-flight

Section 14 is a checkbox matrix the agent must run. Several
checks are countable, not taste:

- one accent used on the whole page
- one corner-radius system
- CTA contrast + no wrap at desktop
- one label per CTA intent ("Get in touch" and "Let's talk" fail)
- eyebrow count ≤ ceil(sections / 3)
- no 3+ consecutive image/text zigzags
- if motion > 4, something actually animates

That is the walker in [08](08-fidelity-step.md) and the table in
[04](04-audit-tokens.md). Import the *mechanical* boxes. Leave
the React/GSAP boxes.

### Block-library schema, not their blocks

Section 12 is a contract we should put on every catalog entry
(hero, footer, section, and later `device` / `surface`):

- `when_to_use` / `not_for`
- dial or field compatibility
- mobile fallback
- anti-patterns
- one real reference

Our fragments already have assigned-recipe framing. They do not
all have `not_for` and anti-patterns. That is how a mediocre
catalog entry pollutes every future site.

### Tinted shadows, `100dvh`, CTA intent

Cheap and already in range of theme.json / hero CSS:

- Shadow presets tinted to the page hue, not `rgba(0,0,0,…)`.
- Hero height `min-height: 100dvh`, never `100vh`.
- One visitor-facing label per intent across header, hero, and
  closing CTA (we already retarget destinations; labels still
  drift).

## Might take (later, not this folder)

These raise the ceiling. They are not direction-fidelity.

- **A `variance` field** (1–10 or `predictable | offset |
  asymmetric`) that drives hero + archetype selection instead of
  the stable hash in `HeroComposition`. Hash is why a candy shop
  and a state library share a skeleton. Taste-skill's anti-center
  bias **fights our taste lock** (centered cinematic is
  preferred). If we add variance, centered stays legal at the
  low end.
- **Composition-anchor list** from `imagegen-frontend-web`:
  bottom-left over image, stacked center, image-as-canvas,
  inverted split. Use it to grow the hero catalog, not to replace
  it.
- **One image per section** as an image-gen rule (never compress
  a page into one frame). Useful if we ever do image-to-code
  comps. Our pipeline already generates per-placeholder photos.
- **Copy self-audit**: no "elevate / seamless / unleash", no
  fake-precise specs (`92%`, `4.1×`) unless the site spec has
  the number. Fits `section.md` + validate-theme. Not a new
  field.
- **Second-read moment** (one motif, once). Same idea as
  [07](07-signature-device.md). Do not add a second field.

## Do not take

- The React / Next / Tailwind / Motion / GSAP stack. Frozen
  block domain. Custom JS beyond the motion kit gets stripped.
- Official design-system packages (Fluent, Carbon, Polaris,
  shadcn). Wrong product.
- Dual light/dark by default. We pick one canvas and lock it.
  Contrast bands inside that canvas stay.
- Hard anti-center hero. Conflicts with standing taste.
- Hard beige ban on bakeries. Their own override: keep the
  family when the brief names it. Rotate only the *default
  reach*.
- Magnetic cursors, sticky-stack, horizontal pan, double-bezel
  cards, floating glass pill nav, button-in-button icons. Agency
  tells, and most cannot survive the fixer.
- Dumping the 87 KB skill, or the high-end "soft-skill"
  checklist, into `section.md`. That is how you get a safer,
  duller generator.
- gpt-taste's fake Python RNG. We already hash-select. The
  problem is the pool, not the dice.

## License

MIT. Ideas and catalogs can be adapted. Do not vendor their
`SKILL.md`. Cite the repo in the PR that lands any import.

## References

- Repo: <https://github.com/Leonxlnx/taste-skill>
- Default skill (v2): [`skills/taste-skill/SKILL.md`](https://github.com/Leonxlnx/taste-skill/blob/main/skills/taste-skill/SKILL.md)
- Changelog (v1 → v2): [`CHANGELOG.md`](https://github.com/Leonxlnx/taste-skill/blob/main/CHANGELOG.md)
- Image comps: [`skills/imagegen-frontend-web/SKILL.md`](https://github.com/Leonxlnx/taste-skill/blob/main/skills/imagegen-frontend-web/SKILL.md)
- Soft / luxury variant: [`skills/soft-skill/SKILL.md`](https://github.com/Leonxlnx/taste-skill/blob/main/skills/soft-skill/SKILL.md)
- GPT-stricter variant: [`skills/gpt-tasteskill/SKILL.md`](https://github.com/Leonxlnx/taste-skill/blob/main/skills/gpt-tasteskill/SKILL.md)
- Examples: [`examples/`](https://github.com/Leonxlnx/taste-skill/tree/main/examples)
- Site: <https://tasteskill.dev>
