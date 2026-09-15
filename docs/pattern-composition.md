# Pattern composition

`StepComposition::patterns()` builds content for a theme the destination
already has. It exports pages, navigation, a Brand and a media manifest, plus generated
image files when an image transport is supplied. It does not ship a theme or plugin.

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
generate-media → resolve-media → export-bundle`

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

## Generated images

Pass an `ImageClient` as the fourth argument to `StepComposition::patterns()`,
or use `bin/pattern-build.php --images` for a live run. The CLI requires its
configured image transport; `--images` with `--responses` is refused so recorded
runs stay offline. Without a client, original images remain unchanged.

`generate-media` replaces raster photographs belonging to the selected theme
on composed pages. Supplied pages, `ai-ignore` blocks and descendants, external
customer images, logos, icons and vectors retain their originals. Shared sources
generate once. Prompts use the brief, Brand, page and surrounding section copy.

Pixels live at `bundle/media/<hash>.jpg`; `patterns/generated-media.json` records
the generated source, original fallback and alt text for resumable generation.
Each generated `media` row in `bundle/content.json` carries `source`, `file`,
`fallback`, `alt`, `pages` and `role: generated`. `file` is relative to `bundle/`.
A remote host must publish each file before discarding the workspace, replace
`file` with a downloadable `url`, and preserve `source` as the replacement key.
The destination imports attachments and rewrites image URLs and attachment IDs.
If generation, publication or importing fails, retain or restore the original
image and record the affected page/source and delivered fallback in warnings.
