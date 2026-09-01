# builder2

Generates a complete multi-page WordPress site from a one-line prompt: a block
theme (theme.json + templates + header/footer parts + the preview screenshot
WordPress shows on the theme card) plus a companion content plugin that seeds
every generated page on activation and removes them on deactivation. The site
spec carries a page tree (home, menu, about, …); every page gets its own
planned and generated sections. Optionally turns the
`AI_IMAGE` placeholders it emits into real assets via Google Gemini (through
the WPCOM AI proxy).

The split is deliberate: design lives in the theme, content lives in
`projects/<slug>/plugin/` (static seeder code + `pages.json` manifest +
`pages/<slug>.html` block markup + `images/` content images, which the
seeder imports into the media library on activation). The homepage is a seeded page too —
`page_on_front` points at it; there is no front-page.html template.

## Setup

```bash
cp .env.example .env
# Text/code LLM (default Anthropic): ANTHROPIC_API_KEY
# Or xAI Grok: LLM_PROVIDER=xai, XAI_API_KEY, LLM_MODEL=grok-4.6 (and per-step models)
# Or OpenRouter: LLM_PROVIDER=openrouter, OPENROUTER_API_KEY (models come from config/models.json)
# Or Baseten (Kimi/GLM/DeepSeek via the wpcom AI proxy): LLM_PROVIDER=baseten, BASETEN_API_KEY
# Images (optional): GOOGLE_VERTEX_API_TOKEN

composer install
npm ci   # optional; installs Playground, screenshot helpers, and block-fixer oracle tooling
```

Standalone theme generation and block fixing require PHP 8.1+ and the Composer
dependencies installed in `vendor/`. Embedding hosts may provide those
dependencies through their own autoloader instead.

The block fixer is implemented entirely in PHP and needs neither Node nor
`node_modules`. WordPress Playground previews and screenshot tooling still use
Node. Use `php bin/build.php "…" --no-serve` for a PHP-only build.

## Install as a plugin

Use [`.claude-plugin/marketplace.json`](.claude-plugin/marketplace.json) as the
marketplace entry point and install the `site-builder` plugin. The plugin ships
the [`site-build` Skill](skills/site-build/SKILL.md) for driving this repository
from a supported coding-agent harness.

> **Breaking change for downstream consumers:** the Node block fixer is gone —
> `NodeBlockFixer` and `Package::blockFixerScript()` no longer exist. Any host
> that vendors this package and wrapped the Node script in a sandbox or adapter
> must delete or rewire that adapter in the same change as the re-vendor.
> `PhpBlockFixer` runs in-process with zero runtime dependencies; the frozen
> compatibility artifacts and their regeneration path are documented in
> `docs/block-fixer-oracle.md`.

## Build a site

```bash
php bin/build.php "A cozy neighborhood bakery"
php bin/build.php "A cozy neighborhood bakery" --with-images   # also generate images
php bin/build.php "A cozy neighborhood bakery" --provider=openai   # build on GPT-5.x instead of Claude
php bin/build.php "A cozy neighborhood bakery" --html-first     # author an HTML+CSS design, then convert it to blocks
php bin/build.php "A cozy neighborhood bakery" --blocks-first   # author block markup directly (the default)
php bin/build.php "A cozy neighborhood bakery" --multi-page    # let the site plan inner pages beyond the homepage
php bin/build.php "A cozy neighborhood bakery" --multi-page --pages="Home, Menu, About, Visit"   # fix the page list yourself (first = homepage)
php bin/build.php "A Persian poetry archive" --writing-direction=rtl --hero-canvas=framed --hero-media-modes=none,foreground-image --max-hero-images=1
```

Hero composition is selected from a reviewed code-owned catalog after filtering
the optional caller constraints `--hero-canvas`, `--hero-media-modes`,
`--max-hero-images`, and `--hero-copy-capacity`.
`--use-jetpack-placeholders` is for hosts that own a form backend: a section
that needs a form reserves its place with a `JP_FORM` placeholder block the
host substitutes after the build, instead of the default of emitting no form
markup at all. `--writing-direction=ltr|rtl`
is an explicit caller override; otherwise the site language determines logical
direction. The selected recipe and normalized blueprint are persisted in
`designDirection.json`, while `aboveFold.json` records the two-phase shared
header/hero/page-opening contract.

### Embedding with an existing site spec

An embedding host that already owns the factual site specification can pass
the package-canonical decoded object to `SiteBuilder::createProject()` instead
of paying for the `site-spec` LLM call:

```php
$project = $builder->createProject(
    prompt: $userPrompt,
    slug: $projectSlug,
    siteSpec: $canonicalSiteSpec,
);
$builder->pipeline()->runThrough($project);
```

The consumer contract ships with the package in two forms:

- [`schemas/site-spec.schema.json`](schemas/site-spec.schema.json) — JSON
  Schema Draft 2020-12 for the complete canonical object.
- [`examples/site-spec.json`](examples/site-spec.json) — a complete payload,
  including nested pages and host-defined factual fields.

Vendored consumers can resolve those files without assuming an installation
path through `Package::siteSpecSchemaPath()` and
`Package::siteSpecExamplePath()`. The schema describes the recommended input
and normalized `siteSpec.json` artifact. Runtime intake remains deliberately
forgiving: missing or malformed candidate fields are normalized, repaired, or
warned about rather than becoming a new build-stopping validation gate.

The value crosses the portable project boundary as `meta.json.site_spec`; the
normal `site-spec` step still canonicalizes it and writes `siteSpec.json`, but
makes no LLM request. With `multiPage` omitted, a supplied spec keeps its page
tree. Pass `multiPage: false` to deliberately force one homepage. A non-empty
`pages:` list implies multi-page scope and replaces the supplied tree with an
exact caller-owned list. The user prompt is still required because the design
and content steps consume both inputs.

The fixed properties use this package's canonical snake-case fields. Additional
top-level properties may carry grounded facts such as hours, location, or
services; page objects have the exact recursive `title` / `slug` / `purpose` /
`children` shape shown in the schema. A host with its own metadata shape
(including WordPress.com) maps that payload in its adapter rather than adding
host-specific aliases to this package.

### Choosing the model / provider

`--provider=<anthropic|openai|xai|openrouter|baseten>` (or the `LLM_PROVIDER` env var) picks a whole
model set at once. Each provider defines a **large** (quality-critical steps) and
**small** (fast/cheap structural steps) model in
[`config/models.json`](config/models.json), and each pipeline step is mapped to a
tier there — so switching providers needs no per-step configuration. Defaults:

| Provider | large | small |
|----------|-------|-------|
| `anthropic` (default) | `claude-opus-5` | `claude-haiku-4-5` |
| `openai` | `gpt-5.5` | `gpt-5.4-mini` |
| `xai` | `grok-4.6` | `grok-4.6` |
| `openrouter` | `moonshotai/kimi-k3` | `moonshotai/kimi-k2.5:nitro` |
| `baseten` | `moonshotai/Kimi-K3` | `zai-org/GLM-5.2-Fast` |

`baseten` reaches Baseten's open-weight models through the WordPress.com AI
proxy (`https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1`, feature slug
`site-builder`) — the route Studio's "hosted" models use. Set
`BASETEN_BASE_URL=https://inference.baseten.co/v1` to go direct with a real
Baseten key. Besides the two tier defaults, `LLM_MODEL` / `LLM_MODEL_<STEP>`
accept `deepseek-ai/DeepSeek-V4-Pro`, `deepseek-ai/DeepSeek-V4-Flash-0731`,
`zai-org/GLM-5.2` and `zai-org/GLM-5.3-Flash`. Model ids are case-sensitive.

Most of these models reason by default, and those tokens come out of the same
completion budget as the answer — asked for 24 tokens, Kimi K3 and both DeepSeek
V4 models spend every one on hidden thinking and return an empty answer with
`finish_reason: length`. The client therefore sends `reasoning_effort: none` for
every Baseten model, including K3 on the large tier, and `low` for GLM 5.3 Flash,
which cannot be switched off. This differs from the `openrouter` profile, which
keeps K3's max effort and leans on a large token floor — a floor any caller
pinning its own smaller budget defeats. To reason on a step instead, remove that
model from `BASETEN_REASONING_EFFORT` in `src/OpenAiCompatibleClient.php` and
give the step a budget that fits the thinking.

Edit `config/models.json` to change those model ids. To override just one run or
one step (any model id, wins over the config):

- `LLM_MODEL` / `LLM_MODEL_SMALL` — the run-wide large / small tier
- `LLM_MODEL_<STEP>` — a single step, e.g. `LLM_MODEL_SITE_SPEC=gpt-5.5`

The OpenRouter profile uses K3 for every quality-critical large-tier step:
`design-direction`, `theme-json`, `sections`, `page-styles`, and
`custom-motion`. Fast K2.5 `:nitro`, with optional reasoning disabled, is
reserved for the small structural steps. K3's maximum-effort reasoning shares
its completion budget with the visible answer, so the transport gives it a
larger token budget and timeout. OpenRouter demo batches run up to three sites
in parallel and bound each site's internal request fan-out at four; pass
`--parallel=<n>` to override the site cap.

Output lands in `projects/<slug>/`. Each build also writes a run overview —
per-step times and token spend, totals, and the image tally — to
`projects/<slug>/logs/project.log` (the same summary printed to the terminal).
A successful build can also contain `projects/<slug>/warnings.json`. This
machine-readable artifact groups non-fatal defects by step id for output the
build still delivered:

```json
{
  "fix-blocks": [
    "parts/example.html block 0: core/paragraph style \"opacity\" could not be preserved"
  ],
  "validate-theme": [
    "plugin/pages/home.html: a button link has no href"
  ]
}
```

Warnings do not make the build fail; inspect the corresponding file under
`logs/` for full evidence. Mutating repair/serialization steps only warn through
an exact reviewed, deterministic safe degradation; malformed or unsupported
input, unreviewed content loss, and non-convergence remain fatal there. An
advisory final validator may warn about residual problems without rewriting the
already usable artifact. Operational failures such as unreadable inputs or
failed writes remain fatal everywhere.
Run the unit tests with `php tests/run.php`.

## Preview a built site

`php bin/serve.php <slug>` boots a built project. Studio is the default when
the WordPress Studio desktop app is available (macOS and Windows only).
Playground is the failover for Linux and CI. Generated Studio sites live
under `~/Studio` and stay running after the command returns.

```bash
php bin/serve.php bakery
php bin/serve.php bakery --runner=studio       # force Studio
php bin/serve.php bakery --runner=playground   # force Playground
php bin/serve.php bakery --stop                # stop one persistent site
php bin/serve.php --stop-all                   # stop every site this checkout created
php bin/serve.php --prune                      # remove those sites from ~/Studio
```

`--runner=studio|playground` (or `SITE_BUILD_RUNNER`) picks the runner: flag,
then env, then Studio if available, else Playground with a warning. A Studio
we picked ourselves also falls back when it fails to boot, so a finished build
still gets a preview; the downgrade is recorded under `site-runner` in
`warnings.json` and in `build-stats.json`. Naming a runner turns both cases
into errors: `--runner=studio` never silently serves something else. `--port`
and `--workers` apply to Playground only; on Studio one note is printed. Override the Studio workspace with `SITE_BUILD_STUDIO_ROOT`.
`--prune` removes sites this checkout created (the ones whose marker records
this repo path), not hand-made directories under `~/Studio`.

## Build the demo set

`eval/theme-prompts.json` holds a persisted set of demo prompts. Build them all
in one command — useful as testing evidence for pipeline/theme changes:

```bash
php bin/build-demos.php --with-images   # build every demo, with generated images
```

An entry may carry a canonical `site_spec` object (the `hearth` demo does): it
is pre-seeded into the project's `meta.json`, so the site-spec step normalizes
it deterministically instead of generating one via LLM — a fixed, reproducible
probe of the host-supplied-spec path described above.

The demos build **in parallel** by default (up to three at once for OpenRouter) —
one `bin/build.php` child process per entry, output streamed with a `[slug]`
prefix. After the builds, each home page is
booted headless in WordPress Playground and a full-page screenshot is saved to
`projects/<slug>/logs/home.png`. Re-runs never overwrite prior output — each
build goes to the next free slug (`tbilisi` → `tbilisi2` → …).

Needs a text LLM key (`ANTHROPIC_API_KEY`, `XAI_API_KEY`, `OPENAI_API_KEY`, or
`OPENROUTER_API_KEY`, or `BASETEN_API_KEY`, with the matching `LLM_PROVIDER`)
and `GOOGLE_VERTEX_API_TOKEN` in `.env`, plus Node.js (for Playground) and a
Chrome/Chromium binary (for the screenshot).

Useful variants:

```bash
php bin/build-demos.php --with-images --only=tbilisi     # just one demo
php bin/build-demos.php --with-images --provider=openai  # build the set on GPT-5.x
php bin/build-demos.php --with-images --parallel=2       # cap concurrent builds
php bin/build-demos.php --with-images --no-screenshot    # skip the screenshots
php bin/build-demos.php --with-images --serve            # serve all sites afterward
```

`--serve` boots every built site after the batch and prints the URLs, so the
whole demo set can be inspected side by side. Studio (the default when
available) creates persistent sites under `~/Studio` and the command returns;
stop them with `php bin/serve.php --stop-all`. Playground (Linux/CI failover)
still binds each site on its own port, and a single Ctrl-C stops all the servers.

Each build normally fires up to ~10 concurrent LLM requests. The OpenRouter
profile caps that at four per site, so its default three-site batch reaches at
most 12; use `--parallel=<n>` to tune the outer site concurrency.

## Publish a shareable Playground link

Upload a built project to the Playground artifact branch and print a URL that
opens it directly in WordPress Playground:

```bash
php bin/publish-playground.php <slug>
php bin/publish-playground.php <slug> --dry-run   # build the ZIP, don't upload
php bin/publish-playground.php --list             # list uploaded artifacts
```

The uploaded ZIP is a Playground Blueprint bundle. It contains the runnable
Blueprint plus a complete archive of the project folder for debugging:

```text
blueprint.json
project.zip
```

`project.zip` contains `project/<slug>/...`, including logs, screenshots, JSON
artifacts, and the generated theme. By default assets are pushed to a
`playground-artifacts` branch in the current GitHub repo and served from
`raw.githubusercontent.com`, which WordPress Playground can fetch in the
browser. Override with `--repo=OWNER/REPO` or `--branch=<branch-name>`.
Uploaded ZIPs are browsable online at
<https://github.com/matiasbenedetto/minimalistic-site-builder/tree/playground-artifacts>.

## Image prompt debugger

A standalone page for iterating on `AI_IMAGE` prompts **without building a whole
theme**. It drives the real `GenerateImagesStep` against a throwaway temp
project, so what you see is exactly what the pipeline would produce: the same
prompt composition, the same site-context grounding, the same Gemini call.

It comes pre-filled with a site context and 10 example prompts, each with an
editable subject / page-context / style / aspect-ratio. Use **Generate** on a
card to render that one image, or **Generate all** to render every card in one
concurrent batch. Each card shows the result, its status, and the exact composed
prompt sent to the endpoint.

### Run it

```bash
php -S localhost:8080 bin/image-debug.php
```

Then open <http://localhost:8080/>.

Requires `GOOGLE_VERTEX_API_TOKEN` in `.env` (the same token the build uses for
images) — without it, the cards report a generation error.

**Notes**

- The page must be served by PHP (not opened as a `file://` page): image
  generation needs the server-side image client and the secret Vertex token.
- If the port is already in use (e.g. an SSH tunnel is holding it), pick another:
  `php -S localhost:8090 bin/image-debug.php`, and forward that port to your
  browser if you're on a remote host.

## Full-page screenshots

`bin/screenshot/screenshot.js` captures a full-page screenshot of any URL (e.g.
a generated theme served via `bin/playground.php`). It scrolls the page
top-to-bottom before capturing so lazy-loaded images far down a tall page are
actually fetched and rendered — a plain `fullPage` capture leaves them as empty
boxes (see [issue #31](docs/evidence/issue-31/README.md)).

```bash
npm ci   # once, at the repo root; uses your system Chrome, no download
node bin/screenshot/screenshot.js http://localhost:9400/ shot.png
```

Pass `--width=<px>` (or set `SHOT_WIDTH`), `--chrome=<path>` (or set
`CHROME`/`CHROME_BIN`), and `--no-scroll` to reproduce the old un-scrolled
behaviour.
