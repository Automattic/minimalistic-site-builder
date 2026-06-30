# builder2

Generates a WordPress block theme (theme.json + landing page + template parts)
from a one-line prompt, then optionally turns the `AI_IMAGE` placeholders it
emits into real assets via Google Imagen (through the WPCOM AI proxy).

## Setup

```bash
cp .env.example .env
# then fill in ANTHROPIC_API_KEY (and GOOGLE_VERTEX_API_TOKEN for image generation)
```

Requires PHP 8.1+ (no Composer — the source set is loaded explicitly by
`src/bootstrap.php`).

## Build a site

```bash
php bin/build.php "A cozy neighborhood bakery"
php bin/build.php "A cozy neighborhood bakery" --with-images   # also generate images
```

Output lands in `projects/<slug>/`. Each build also writes a run overview —
per-step times and token spend, totals, and the image tally — to
`projects/<slug>/logs/project.log` (the same summary printed to the terminal).
Run the unit tests with `php tests/run.php`.

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
cd bin/screenshot && npm install   # once; uses your system Chrome, no download
node bin/screenshot/screenshot.js http://localhost:9400/ shot.png
```

Pass `--width=<px>`, `--chrome=<path>` (or set `CHROME`), and `--no-scroll` to
reproduce the old un-scrolled behaviour.
