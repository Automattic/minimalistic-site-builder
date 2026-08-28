# Archetype gallery

What the generator can draw today, and what it could draw next.

```bash
php bin/archetypes.php            # render the gallery and serve it
php bin/archetypes.php list       # the same coverage, in the terminal
```

The gallery has two halves.

**What we can build** — one card per archetype in the four code-owned catalogs
(`AboveFoldContract` headers, `HeroComposition` heroes, `SectionComposition`
sections, `FooterComposition` footers), each with its metadata, its prompt
fragment's opening paragraph, and a screenshot of the archetype as it shipped on
a real generated site. A card with no screenshot is an archetype no built site
has drawn yet.

**What we could build** — mockups of archetypes nobody has implemented. Each one
argues for itself: the idea, why the current catalog cannot express it, what it
would be built from, and the risk it carries. Tick the ones worth building, add
notes, and the page hands you the prompt that implements them.

## Commands

| Command | What it does |
| --- | --- |
| `serve [--port=9310]` | Renders the page and serves it, with composing enabled. |
| `build` | Writes `index.html` and stops. Opening it from disk works; composing does not. |
| `list` | Prints the catalog and its coverage. |
| `capture [--only=slug,…] [--width=1366]` | Boots every built project under `projects/`, screenshots each part it delivered, and files one image per archetype. |
| `propose "<what you want>" [--family=…] [--count=1]` | Asks the model to draw one archetype from your description. |
| `propose --auto [--family=…]` | Asks the model to find the widest gap in the catalog and fill it. |

Composing is also available from the served page, which is the pleasant way to
use it: describe a composition, or press **Add variety** and let the model pick
the gap.

## What lives here

- `shots/` — one WebP per archetype, plus `index.json` naming the site each came
  from. **These are committed.** They are the tool's own assets, not review
  evidence, which is why the AGENTS.md rule about keeping screenshots out of the
  repository does not apply to them. They are capped at 1100px and re-encoded,
  so the whole set costs the repository under a megabyte.
- `proposals/` — one JSON record per proposed archetype, including its mockup.
  **Committed**, so a drawing the model produced is reviewable in a diff and
  editable by hand afterwards. Delete the file to drop the proposal.
- `index.html` — generated on every run. **Not committed.**

## Filling an empty card

An archetype with no screenshot has not been drawn by any site under
`projects/`. Selection is deterministic per brief, so the way to reach a
specific one is to pin it:

```bash
HERO_RECIPE=type-manifesto php bin/build.php --slug=demo --with-images --no-serve "a brief"
HEADER_ARCHETYPE=split-nav  php bin/build.php --slug=demo --multi-page …
php bin/archetypes.php capture --only=demo
```

`bin/build-catalog-cohort.php` does this in bulk from `eval/catalog-fill-prompts.json`,
including the footer archetype, which has no env override and has to be patched
into `pages.json` between the plan and the sections step.

## Safety of a generated mockup

A mockup renders inside the gallery, so `ArchetypeProposals::validate()` refuses
one that carries script, event handlers, embedded elements, `@import`, or any
URL, and requires every CSS selector to be scoped to that proposal's own class.
Those rules apply to hand-written records too: the tool cannot tell, and should
not care, who typed it.
