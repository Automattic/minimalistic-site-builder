# Approved-pattern composition

`StepComposition::patterns()` is the content-only graph for an existing theme.
Its first extraction slice proves the difficult boundary: deterministic facts
and generated copy can populate declared slots without changing approved
structure or styling.

The graph is:

`prepare-pattern-content -> personalize-pattern-content -> serialize-pattern-content -> export-content-bundle`

An embedding host creates the project with
`SiteBuilder::createPatternProject()`, runs `SiteBuilder::patternPipeline()`,
and delivers `content-bundle.json` plus the media files named by its `media`
rows. The bundle is owned by this library. A destination host translates it to
its importer rather than making the composition depend on that importer.

## Version 1 input

`pattern-inputs.json` contains:

- `version: 1`.
- `facts`: explicit values available to deterministic bindings and model copy.
- `brand`: fixed Brand values carried unchanged into the bundle.
- `delivery`: the required installed theme slug and site title.
- `layouts`: approved block markup, destination placement and declared slots.
- `media`: portable source files, upload paths, URLs and attachment metadata.

The package publishes the machine-readable contract and a complete example at
[`schemas/pattern-inputs.schema.json`](../schemas/pattern-inputs.schema.json)
and [`examples/pattern-inputs.json`](../examples/pattern-inputs.json). Vendored
hosts can locate them with `Package::patternInputsSchemaPath()` and
`Package::patternInputsExamplePath()`.

The exported artifact follows
[`schemas/content-bundle.schema.json`](../schemas/content-bundle.schema.json),
available to embedding hosts through `Package::contentBundleSchemaPath()`.
Each layout carries hashes of its approved source and delivered content; each
media row carries its byte size and SHA-256 digest.

A page layout declares `page: {title, slug}`. A shared part declares
`shared_part: {title, slug, area}`. Every layout has a stable lowercase `id`, an
explicit `page|shared-part` role, approved `markup`, and a list of slots.

A slot declares a stable `id`, a numeric `block_path`, an admitted `field`, and
an approved `fallback`. Factual and URL slots also declare a `binding`; they do
not enter model requests. Generated text slots may declare `instruction` and
`max_words`.

The first slice admits plain text in core paragraph, heading, list-item and
button blocks; button URLs; and portable image URL/alt attributes. A whole rich
text field containing inline markup is refused because replacing it would lose
authored structure. Blocks carrying `ai-ignore`, and all descendants of such a
block, are protected.

## Preservation and degradation

The serializer compiles slots to source spans and replaces only those spans.
It does not parse and reserialize surrounding blocks. Undeclared blocks,
classes, styles, attributes, whitespace and protected content therefore retain
their approved bytes.

Model results map by stable slot ID. Successful siblings survive partial
responses. Missing, duplicate, malformed, over-limit or unsafe values receive
one targeted retry, followed by their reviewed fallback and an actionable
warning. Retained artifacts carry an input fingerprint; resuming after input
changes is refused before another model call.

## Current boundary

This slice intentionally starts with supplied layouts. It does not yet perform
page planning, catalogue selection, image generation, navigation resolution,
or a final bundle-wide validator. Those belong in later steps after the
extraction and destination-import proof is stable. Media listed in the input
must already exist under the project `media/` directory when export runs.

Run the offline fixture proof with:

```sh
php tests/pattern-proof.php /tmp/msb-pattern-proof
node tests/integration/pattern-content-oracle.js /tmp/msb-pattern-proof/pattern-output.json
```

The importer integration harness is an optional consumer smoke test. Site
Foundry remains the source of the selected Brand, Blueprint, resolved deployed
theme, site creation, and WordPress application. It maps those inputs into this
contract and adapts the returned portable bundle at its destination boundary.

## Local WordPress E2E

With the Site Foundry checkout next to this repository and its Composer and
pnpm dependencies installed, run:

```sh
tests/run-pattern-site-foundry-e2e.sh --keep-running --headed
```

The command starts Site Foundry's Docker WordPress multisite and drives the real
Network Admin product flow in Playwright. It selects the curated Customer
Microsite Blueprint and a saved Northstar Brand, asks the mounted MSB checkout
to personalize only the Blueprint's declared slots, creates a fresh subsite,
and applies the bundle through Site Foundry's existing interpreter. The checks
cover the deployed theme, four pages, inner-page copy, portable links, front
page, and the Brand palette rendered from WordPress global styles. Every run
saves a browser video and result metadata under a temporary directory printed
at the end. `--headed` shows the browser while it records; `--keep-running`
leaves WordPress at `http://localhost:8888` for inspection.

The runner updates Site Foundry's gitignored `.wp-env.override.json` with the
local MSB checkout mount. Local mode bypasses the Jetpack connection check and
the Site Spec CDN widget because it uses recorded fixture responses. Production
keeps the existing WordPress.com transport.

Override the sibling checkout convention with `SITE_FOUNDRY_ROOT=/path/to/site-foundry`.
The E2E uses fixture providers; production authorization, quotas, credentials,
and result delivery remain tests of the WPCOM host integration rather than this
local importer loop.
