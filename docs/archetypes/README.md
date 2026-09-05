# Archetype gallery

What the generator can draw today, and what it could draw next.

```bash
php bin/archetypes.php            # render the gallery and serve it
php bin/archetypes.php list       # the same coverage, in the terminal
```

The gallery has two halves.

**What we can build** — one card per archetype in the four code-owned catalogs
(`AboveFoldContract` headers, `HeroComposition` heroes, `SectionComposition`
sections, `FooterComposition` footers), each with its metadata, the opening line
of its prompt fragment, and **several** screenshots of the archetype as it
shipped on real generated sites. Several, not one, on purpose: one image proves
the archetype exists, and only a set of them shows how much it varies from brief
to brief — which is the question a variety review actually asks. A card with one
example says so; a card with none is an archetype no built site has drawn yet.

**What we could build** — mockups of archetypes nobody has implemented. Each one
argues for itself: the idea, why the current catalog cannot express it, what it
would be built from, and the risk it carries. Tick the ones worth building, add
notes, and the page hands you the prompt that implements them.

## How to use it to improve the designs

The gallery is a review instrument, not a picture book. Open it when you want to
answer one of three questions about the output of the generator.

### 1. Do our sites look the same as each other?

Open **What we can build** and read one card at a time. Each card shows the same
archetype as it shipped on two or three different sites. Compare the examples
inside one card, not the cards against each other.

- The examples look alike. The recipe under-varies. Read its `facts` row first:
  a recipe with one canvas, one media aspect and one surface can only draw one
  picture, so widen those axes in `src/HeroComposition.php` or
  `src/SectionComposition.php`. Then read its prompt fragment, one disclosure
  below the brief. A fragment that lists example answers at a creative decision
  herds every site onto the first one; state the constraint instead.
- The examples look different but wrong. That is a pipeline defect, not a
  variety defect. Open a Linear issue for the step that made it, and use the
  screenshot as the evidence.
- One archetype fills most of your sites. The selection is concentrated. Look at
  the seed and the compatibility rules that choose it, not at the archetype.

### 2. What can we not draw at all?

Read the `facts` rows across one family and name the shape nobody offers. Then
open **What we could build**. Every card there argues for itself: the idea, why
the catalog cannot express it, what it would be built from, and the risk.

- Nothing fits. Press **Add variety** and let the model find the widest gap, or
  describe the composition you want and press **Draw it**.
- Something fits. Tick it, write your notes in its box, and press **Copy prompt
  for Claude Code**. The prompt names your picks, their mockup files, your
  notes, and the archetypes you did *not* choose.

Paste that prompt into a new session. It carries the whole decision, so the
session that builds the archetype needs nothing else from you.

### 3. Did the last change help?

Rebuild the evidence and look again:

```bash
php bin/archetypes.php fill      # build sites that draw the archetypes you changed
php bin/archetypes.php capture   # photograph what they drew
php bin/archetypes.php           # look
```

Then record where each proposal ended up with `status`, so the queue shows real
work and the model stops offering an idea you already settled.

An empty card and a one-example card are both findings, not gaps in the tool. An
empty card means the generator will not deliver that archetype from any brief
you have. A one-example card means you cannot yet judge whether it varies.

### After a merge that changes a catalog

The catalogs are code, and the shots and briefs are data that names them. When
trunk retires or merges a recipe, three things go stale:

```bash
php bin/archetypes.php list      # reports shots of archetypes nobody owns now
php bin/archetypes.php prune     # drops them, no builds needed
```

Then check `eval/catalog-fill-prompts.json` for a brief that pins the gone id —
`fill` refuses the whole cohort on one unknown archetype — and read the
proposals whose `why_new` argues against it, because an argument naming a recipe
nobody can find sends the next reviewer looking for it.

## Commands

| Command | What it does |
| --- | --- |
| `serve [--port=9310]` | Renders the page and serves it, with composing enabled. |
| `build` | Writes `index.html` and stops. Opening it from disk works; composing does not. |
| `list` | Prints the catalog, how many examples each archetype has, and what is stale. |
| `capture [--only=slug,…] [--width=1366] [--per-archetype=3]` | Boots every built project under `projects/`, screenshots each part it delivered, and files up to `--per-archetype` images per archetype, preferring different sites. Also drops shots of archetypes the catalogs no longer own. |
| `prune` | Drops shots of archetypes the catalogs no longer own, without booting anything. Run it after a merge that retires a recipe. |
| `fill [--only=brief,…] [--parallel=3]` | Builds the cohort in `eval/catalog-fill-prompts.json`, which pins a header, a hero and a footer per brief so the archetypes no demo selects get drawn at all. Slow, and it costs model calls. |
| `status <family/id> <waiting\|built\|dropped> [--note="…"]` | Records where a proposal ended up. |
| `propose "<what you want>" [--family=…] [--count=1]` | Asks the model to draw one archetype from your description. |
| `propose --auto [--family=…]` | Asks the model to find the widest gap in the catalog and fill it. |

Composing is also available from the served page, which is the pleasant way to
use it: describe a composition, or press **Add variety** and let the model pick
the gap.

## What lives here

- `shots/` — a few WebP per archetype, plus `index.json` naming the site each
  came from. **These are committed.** They are the tool's own assets, not review
  evidence, which is why the AGENTS.md rule about keeping screenshots out of the
  repository does not apply to them. They are capped at 1100px and re-encoded,
  so the whole set stays small.
- `proposals/` — one JSON record per proposed archetype, including its mockup.
  **Committed**, so a drawing the model produced is reviewable in a diff and
  editable by hand afterwards.
- `index.html` — generated on every run. **Not committed.**

## Filling an empty card, and thickening a thin one

`capture` photographs what a site drew, so a card with no screenshot needs a
build before it needs a screenshot. Selection is deterministic per brief, which
is why more demo sites do not help: the same briefs keep choosing the same
archetypes. Pin the assignment instead.

```bash
php bin/archetypes.php fill              # the whole cohort, three builds at a time
php bin/archetypes.php fill --only=cat-tidal
php bin/archetypes.php capture           # then photograph what they drew
```

`fill` runs `bin/build-catalog-cohort.php` over
`eval/catalog-fill-prompts.json`. Each brief pins the three steerable
assignments: the header (`HEADER_ARCHETYPE`), the hero (`HERO_RECIPE`), and the
footer — which has no env override and is patched into `pages.json` between the
plan and the sections step. Add a brief there to reach an archetype the cohort
does not cover yet; the builder refuses a combination the contract would degrade
rather than paying for a build that silently lands on `standard-row`.

One brief at a time works too, when that is all you need:

```bash
HERO_RECIPE=type-manifesto php bin/build.php --slug=demo --with-images --no-serve "a brief"
php bin/archetypes.php capture --only=demo
```

## Where a proposal ends up

A proposal is `waiting`, `built` or `dropped`. Record the move rather than
deleting the file:

```bash
php bin/archetypes.php status hero/knockout-type dropped --note="merged in #393, reverted in #399"
```

The record stays on disk on purpose. A settled proposal keeps its card, out of
the queue and out of the export, and the composer is told its status — so the
variety pass does not draw the same idea again next week, which is exactly what
deleting the file would cause.

## Safety of a generated mockup

A mockup renders inside the gallery, so `ArchetypeProposals::validate()` refuses
one that carries script, event handlers, embedded elements, a `<style>` element,
`@import`, or any URL, and requires every CSS selector to *start* at that
proposal's own class.

Start at, not merely mention: `body:has(.mock-hero-x)` names the class and
matches the whole document, and `.mock-hero-x-wide` merely shares its first
characters. Both are refused. `.mock-hero-x .row`, `.mock-hero-x:hover` and a
scoped rule inside `@media` are accepted, which is everything a drawing needs.

Those rules apply to hand-written records too: the tool cannot tell, and should
not care, who typed it.
