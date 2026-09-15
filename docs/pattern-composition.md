# Pattern composition

`StepComposition::patterns()` builds content for a theme the destination
already has. It never ships a theme, a plugin or a file: the bundle it exports
is pages, navigation, a Brand and a list of images for the host to import.

## Inputs

Three seeds, written into the project before the first stage runs:

- `host/request.json`: the request (version 2). Theme slug, locale, site title,
  facts, capabilities, the pages, and an optional navigation.
- `host/patterns.json`: the approved inventory, `{ "patterns": [...] }`, each
  with `id`, `categories` and rendered `content`. Composition may emit nothing
  outside it.
- `host/brand.json`: the Brand record: `id`, `name`, `logo_url`, `context`,
  and `config`, a theme.json partial shaped `{ settings, styles }`.

Each page is one of two things:

- **Composed**: `{ "slug", "title", "intent" }`. The plan chooses its sections
  from the categories the inventory declares, compose picks a pattern per
  section, and personalize discovers every text-bearing block as a slot,
  with `ai-ignore` as the opt-out.
- **Supplied**: `{ "slug", "title", "markup" }`, with an optional `slots` list.
  The markup is written as it is. With `slots` declared, only those slots
  change: bindings take their value from the facts and never reach the model,
  a slot with an `instruction` is asked for at the author's `max_words`, and
  the declared `fallback` is what a slot keeps when the answer will not do.
  With no `slots` key, slots are discovered as on a composed page. With
  `"slots": []` the page is frozen: nothing is asked, nothing is written.

The inventory may be empty only when every page is supplied. `HostRequest`
checks all of this before anything runs and reports every problem together.

## Stages

`normalize-inputs → plan-site → compose-layouts → personalize-content →
resolve-media → export-bundle`

Plan and compose skip supplied pages; a site of only supplied pages makes no
planning call. Export writes `bundle/content.json` (version 2) and refuses a
bundle that is not content-only: a block outside core, a section outside the
inventory, or a class the destination cannot resolve, where a class counts as
defined when the capabilities, the inventory or a supplied page carries it.

Every page in the bundle carries its `provenance` (`composed` or
`blueprint`), a `source_hash` of the markup that was written into and a
`content_hash` of what came out. The bundle carries an `input_hash` over the
three seeds and the build's `warnings`.

## Running it

```sh
# Live, with the transport bin/build.php would pick, recording every answer:
php bin/pattern-build.php --fixtures=tests/fixtures/patterns/ollie-mixed \
    --slug=mixed --record=tests/fixtures/patterns/ollie-mixed/responses.json

# Offline, from that recording; lands the same bundle byte for byte:
php bin/pattern-build.php --fixtures=tests/fixtures/patterns/ollie-mixed \
    --slug=mixed --responses=tests/fixtures/patterns/ollie-mixed/responses.json
```

A recording is `{ "version": 2, "slots": { "<slot-id>": "text" },
"page_titles"?: { "<slug>": "title" }, "plan"?: {...} }`, the shape Site
Foundry writes. A page is answered from the slot ids its prompt names, so a
retry replays the page's final answer; `plan` answers the planning call and is
only needed when a page composes.
`tests/fixtures/patterns/ollie-mixed` is a four-page site on Ollie with one
page of each kind and its recording; the unit suite replays it end to end.
