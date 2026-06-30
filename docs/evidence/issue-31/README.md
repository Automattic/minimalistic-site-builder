# Issue #31 — full-page screenshots miss lazy-loaded images

Full-page headless screenshots used to capture a tall page in one shot without
ever scrolling, so images far down the page that lazy-load (native
`loading="lazy"` or a JS `IntersectionObserver` keyed off scroll) were never
fetched and rendered as empty boxes — even though the asset existed on disk.

`bin/screenshot/screenshot.js` now scrolls the page top-to-bottom to trip every
lazy-load trigger, promotes any remaining lazy images to eager, and waits for
all `document.images` to finish decoding before the `fullPage` capture.

Before/after screenshots proving the fix are attached to
[PR #32](https://github.com/matiasbenedetto/minimalistic-site-builder/pull/32),
not committed here. This folder keeps only the reproducible fixture so the
regression can be re-checked on demand.

## Reproduce

`fixture/index.html` is a tall page with seven `IntersectionObserver`-gated
images (only `Section 1` is in the initial viewport). Generate the images with
`fixture/make-images.sh` (needs ImageMagick), then:

```bash
cd docs/evidence/issue-31/fixture
./make-images.sh
php -S 127.0.0.1:8199 &

# Reproduce the bug — sections 2-7 come out as empty boxes:
node ../../../bin/screenshot/screenshot.js http://127.0.0.1:8199/index.html before.png --no-scroll

# With the fix — all seven images render:
node ../../../bin/screenshot/screenshot.js http://127.0.0.1:8199/index.html after.png
```
