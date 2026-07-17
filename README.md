# builder2

Generates a WordPress block theme (theme.json + landing page + template parts)
from a one-line prompt, then optionally turns the `AI_IMAGE` placeholders it
emits into real assets via Google Imagen (through the WPCOM AI proxy).

## Setup

```bash
cp .env.example .env
# Text/code LLM (default Anthropic): ANTHROPIC_API_KEY
# Or xAI Grok: LLM_PROVIDER=xai, XAI_API_KEY, LLM_MODEL=grok-4.5 (and per-step models)
# Images (optional): GOOGLE_VERTEX_API_TOKEN

npm ci   # optional; installs the development oracle and screenshot helpers
```

Theme generation and block fixing require PHP 8.1+ only. No Composer is needed:
the source set autoloads through the dependency-free PSR-4 loader
`autoload.php`, loaded by `src/bootstrap.php`.

The production block fixer is implemented in PHP and needs neither Node nor
`node_modules` at runtime. Set `BLOCK_FIXER=node` to select the pinned legacy
Node implementation during development. The fixed-point tooling around that
implementation remains the parity oracle, not a production dependency. It is
pinned to Node 22.19.0 and must be installed from the lockfile with `npm ci`;
CI uses the immutable image recorded in
`bin/block-fixer/lib/oracleFingerprint.js`. WordPress Playground previews and
screenshot tooling also use Node. Use
`php bin/build.php "…" --no-serve` for a PHP-only build.

## Build a site

```bash
php bin/build.php "A cozy neighborhood bakery"
php bin/build.php "A cozy neighborhood bakery" --with-images   # also generate images
php bin/build.php "A cozy neighborhood bakery" --provider=openai   # build on GPT-5.x instead of Claude
```

### Choosing the model / provider

`--provider=<anthropic|openai|xai>` (or the `LLM_PROVIDER` env var) picks a whole
model set at once. Each provider defines a **large** (quality-critical steps) and
**small** (fast/cheap structural steps) model in
[`config/models.json`](config/models.json), and each pipeline step is mapped to a
tier there — so switching providers needs no per-step configuration. Defaults:

| Provider | large | small |
|----------|-------|-------|
| `anthropic` (default) | `claude-opus-4-8` | `claude-haiku-4-5` |
| `openai` | `gpt-5.5` | `gpt-5.4-mini` |
| `xai` | `grok-4.5` | `grok-4.5` |

Edit `config/models.json` to change those model ids. To override just one run or
one step (any model id, wins over the config):

- `LLM_MODEL` / `LLM_MODEL_SMALL` — the run-wide large / small tier
- `LLM_MODEL_<STEP>` — a single step, e.g. `LLM_MODEL_SITE_SPEC=gpt-5.5`

Output lands in `projects/<slug>/`. Each build also writes a run overview —
per-step times and token spend, totals, and the image tally — to
`projects/<slug>/logs/project.log` (the same summary printed to the terminal).
Run the unit tests with `php tests/run.php`.

## Build the demo set

`eval/theme-prompts.json` holds a persisted set of demo prompts. Build them all
in one command — useful as testing evidence for pipeline/theme changes:

```bash
php bin/build-demos.php --with-images   # build every demo, with generated images
```

The demos build **in parallel** — one `bin/build.php` child process per entry,
output streamed with a `[slug]` prefix. After the builds, each home page is
booted headless in WordPress Playground and a full-page screenshot is saved to
`projects/<slug>/logs/home.png`. Re-runs never overwrite prior output — each
build goes to the next free slug (`tbilisi` → `tbilisi2` → …).

Needs a text LLM key (`ANTHROPIC_API_KEY`, or `XAI_API_KEY` with `LLM_PROVIDER=xai`)
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

`--serve` boots every built site in Playground simultaneously after the batch —
each on its own port — and prints all the URLs, so the whole demo set can be
inspected side by side. A single Ctrl-C stops all the servers.

Each build fires up to ~10 concurrent Claude requests, so a full parallel batch
is ~30 concurrent API requests; use `--parallel=<n>` if rate limits bite.

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
prompt composition, the same site-context grounding, the same Imagen call.

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
