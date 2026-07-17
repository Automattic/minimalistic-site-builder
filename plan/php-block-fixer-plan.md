# Plan — Pure-PHP block fixer (replace the Node re-serializer)

Reimplement the block re-serialization step (`bin/block-fixer/`) in
dependency-free PHP as a `PhpBlockFixer implements BlockFixer`, so the whole
pipeline runs on a plain PHP web server with no Node runtime. The Node fixer
stays in the tree during the transition as the parity oracle: a differential
harness drives the PHP output to byte-equality against it over the full
`projects/` corpus before cutover.

---

## Why

**The problem.** Every build shells out to
`node bin/block-fixer/fix-templates.js` (`src/NodeBlockFixer.php`), which
needs Node 18+ plus an `npm install` of `@wordpress/blocks`,
`@wordpress/block-library` and `jsdom`. That is the only Node dependency in
the build pipeline; everything else is dependency-free PHP. Deploying to a
PHP-only host currently means bundling a Node sidecar.

**What the Node layer actually does.** Despite the "validation fixer" name,
it does not use validation results for anything but logging.
`fixBlockRecursively` (`bin/block-fixer/lib/blockFixer.js:100`)
unconditionally recreates every named block from its attributes via
`createBlock()` and re-serializes with `serialize()`, so the saved HTML
matches WordPress `save()` byte-for-byte. Half the file
(`overlayCommentAttributes`) exists only to undo `parse()`'s
deprecated-version migrations and re-assert the comment JSON as the source of
truth.

**Why a PHP port is tractable here.** This would be a bad idea for arbitrary
user content, but this pipeline generates its own markup, so the block set is
closed:

- Across the `projects/` corpus, ~30 block types appear. A third are
  *dynamic* blocks (`template-part`, `navigation`, `navigation-link`,
  `site-title`, `site-tagline`, `site-logo`, `post-content`, `post-title`,
  `page-list`, `social-links`, `social-link`) whose `save()` is `null` — they
  serialize as a bare comment and need no HTML regeneration.
- The static surface is ~18 blocks, and six of them (paragraph, group,
  column, heading, image, separator) account for ~85% of all instances.
- The comment JSON is authored in the current format and is the single
  source of truth, so the PHP implementation needs **no** validation, no
  deprecation machinery, and no overlay pass: parse comments → source the few
  HTML-only attributes → render `save()` output from the merged attributes.
- `@wordpress/block-library` ships machine-readable `block.json` schemas
  (attribute `source`/`selector`/`attribute` definitions), so attribute
  sourcing can be data-driven rather than hand-coded per block.
- Parity is measurable, not hoped for: dozens of generated themes exist on
  disk, and the Node fixer is a runnable oracle to diff against.

**Parity target.** The pinned `@wordpress/blocks` 15.15.0 snapshot. Core
saved markup is deliberately stable (changing it invalidates existing
content; that is what the deprecation system exists for), so drift is slow;
when we do bump Gutenberg intentionally, the differential harness is the
regression net.

---

## Contracts that must not change

`FixBlocksStep`, `CoverContrastStep` and the tests depend on the fixer's
observable behavior, not its implementation:

1. **Interface** — `BlockFixer::fix(string $themeDir): string`
   (`src/BlockFixer.php`): fix every `templates/*.html` and `parts/*.html`
   in place, return `summary line + "\n" + full report`.
2. **Summary line** — last report line starting with `[fix-templates]`
   (`NodeBlockFixer::summaryLine`, `FixBlocksStep::run` echoes the first
   line). Keep the exact shape:
   `[fix-templates] N/M file(s) re-serialized, K issue(s) fixed, D style/class value(s) dropped across T theme(s).`
3. **Dropped-content lines** — `FixBlocksStep::droppedVerticalRhythmStyles`
   regexes `` DROPPED style `prop:value` `` out of the report and fails the
   build on rhythm properties. The PHP fixer must produce the identical
   pre/post diff lines (`fix-templates.js:96-162` — style declarations
   normalized around the colon, class tokens counted per occurrence).
4. **Per-file report lines** — `FIXED/ok/skip` rows, `! DROPPED …` and
   `- issue` sublines, files without `<!-- wp:` skipped.
5. **Fixing semantics** — nested-`<p>` repair before parsing and again after
   serialization (`lib/paragraphFixer.js`); `core/media-text` mediaType
   inference when `mediaUrl` is present without `mediaType`
   (`blockFixer.js:48-63`); unnamed/freeform content passed through
   untouched; a hard error in one file must not corrupt it (current behavior:
   fall back to original content).
6. **Idempotence** — running the fixer on its own output changes nothing
   (existing rhythm-idempotence tests in `tests/unit/fix_blocks_test.php`).

---

## Architecture

New namespace `src/BlockSerializer/`, all dependency-free PHP 8.1, plus the
`BlockFixer` implementation:

```
src/
  PhpBlockFixer.php              BlockFixer impl: file discovery, report,
                                 summary line (mirrors fix-templates.js)
  BlockSerializer/
    Serializer.php               tree walk: comment + rendered HTML, \n\n
                                 joins, serialize_block_attributes escaping
                                 (reuse BlockMarkup::serializeComment)
    AttributeSourcer.php         HTML-sourced attrs (content, url/alt/id,
                                 text …) driven by generated schema data
    schema.php                   checked-in, generated from block-library
                                 block.json: attribute definitions + schema
                                 key order per block
    StyleEngine.php              style attr + preset classes from
                                 attributes.style (port of the WP PHP style
                                 engine subset: spacing, color, typography,
                                 border, shadow; var:preset|x|y expansion)
    Supports.php                 class generation outside style engine:
                                 align*, has-*-color, has-background,
                                 has-*-font-size, layout-related classes
    ParagraphFixer.php           port of lib/paragraphFixer.js (regex HTML
                                 surgery, 159 lines)
    DroppedContentDetector.php   pre/post style/class diff, report lines
    Renderers/
      GroupRenderer.php          … one per static block (see inventory)
```

Key design decisions:

- **Parsing** — reuse `src/BlockMarkup.php`. It already builds the tree with
  parent/children indices, `attrs()`, `innerHtml()`, `ownHtml()` and
  `serializeComment()` with the `--` escaping. It needs one
  extension: per-block `innerContent` segmentation (the interleaving of HTML
  chunks and child-block placeholders, as in `WP_Block_Parser`), which the
  serializer needs to know where children sit inside a wrapper's HTML.
- **Attribute sourcing is data, not code** — a small dev-time generator
  (`bin/generate-block-schema.php`, reads
  `node_modules/@wordpress/block-library/build/*/block.json`) emits
  `schema.php` with, per block: attribute names in schema order, and for
  HTML-sourced attributes the `source`/`selector`/`attribute` triple. The
  generated file is checked in, so runtime needs no Node and no block.json.
  Extraction runs on `WP_HTML_Tag_Processor`-style scanning; vendor the
  class from WordPress core (single file, dependency-free, GPL — same
  license family as the rest of the WP code this repo builds on) rather than
  `DOMDocument`, which mangles HTML5.
- **Renderers are the only hand-written per-block code.** Each takes merged
  attributes + rendered inner HTML and returns the `save()` markup. Dynamic
  blocks need no renderer: any block without one and without sourced HTML
  serializes as comment-only (matching `save() === null`), and unknown
  *static* markup falls back to passing the original inner HTML through
  unchanged plus a report line — never a crash.
- **JSON parity** — the comment JSON must match JS output: attribute key
  order (createBlock emits block-type schema order — use `schema.php` order),
  `JSON_UNESCAPED_SLASHES`-style formatting matched to
  `serialize_block_attributes`, and numeric formatting (JS `0.5`/`10` vs PHP
  float rendering). Centralize in one `JsonEncoder` with fixture tests
  captured from Node output.

### Cutover wiring

`NodeBlockFixer::default()` is constructed at 6 call sites (`bin/build.php`,
`bin/create.php`, `bin/eval.php`, `bin/images.php`). Replace with a factory:

```php
BlockFixers::default();   // PhpBlockFixer, or NodeBlockFixer when
                          // BLOCK_FIXER=node (Env), during transition
```

Flag default stays `node` until milestone M3 exit criteria are met, then
flips to `php`. `CoverContrastStep` takes the same interface and needs no
changes.

---

## Static-block inventory (corpus-ranked)

| Tier | Blocks | Notes |
|------|--------|-------|
| M1 core (~85% of instances) | paragraph, group, column, heading, image, separator | group: tagName variants; image: link wrapper, resize, aspect-ratio, rounded |
| M2 structural | columns, buttons, button, spacer, list, list-item | button: link + width classes; spacer: height style + aria-hidden |
| M2 rich | cover, media-text, quote, pullquote | cover is the hardest save() in core (overlay span, dim classes, object-position, video, parallax) |
| M2 tail | gallery, table, embed, html | html/embed: mostly pass-through/wrapper classes; gallery: nested images + layout classes |

Dynamic (comment-only, free): template-part, navigation, navigation-link,
page-list, post-content, post-title, site-title, site-tagline, site-logo,
social-links, social-link.

---

## Milestones

**M0 — Parity harness first (≈1 day).**
`bin/diff-block-fixer.php`: for every `projects/*/theme`, copy to two temp
dirs, run `NodeBlockFixer` on one and `PhpBlockFixer` on the other, diff
byte-for-byte; report per-file and per-block-type mismatch counts and the
first diff hunk for each. Also compare the two report strings (summary +
DROPPED lines), since `FixBlocksStep`'s gate consumes them. This lands
before any renderer so every subsequent PR moves a visible number.

**M1 — Spike: core six + engine (≈3–4 days).**
Schema generator + `AttributeSourcer` + `StyleEngine`/`Supports` + renderers
for paragraph, group, column, heading, image, separator + comment-only
handling for all dynamic blocks + `ParagraphFixer` port. Exit: harness
reports ≥85% of block instances byte-identical, and a written list of every
remaining mismatch class. **Decision gate: if the mismatch tail looks deeper
than estimated (e.g. systematic RichText or JSON-formatting divergence with
no clean fix), stop and reassess before M2.**

**M2 — Long tail (≈1 week).**
Remaining renderers in corpus-volume order, ending with cover/media-text/
gallery. Exit: 100% byte parity on files, reports, and summary lines across
the corpus; fixer idempotent on its own output.

**M3 — Cutover (≈2–3 days).**
- `BlockFixers::default()` factory + `BLOCK_FIXER` env flag; flip default to
  PHP.
- Unit tests: per-renderer fixtures (attrs → expected HTML captured from
  Node), JSON encoder fixtures, sourcing fixtures, idempotence.
- Port the Node-shelling integration tests in
  `tests/unit/fix_blocks_test.php` to run against `PhpBlockFixer`; keep the
  differential corpus test as an integration test that auto-skips when Node
  is absent.
- Docs: README/AGENTS note that Node is no longer required at runtime.

**M4 — Decommission (later, separate decision).**
Once PHP has been the default through a few real build cycles, demote
`bin/block-fixer/` to a dev-only oracle used by the differential test, and
drop it from deployment artifacts.

---

## Risks and mitigations

- **JSON formatting divergence** (key order, floats, escaping) — highest
  parity risk; isolate in one encoder, fixture-test against captured Node
  output, harness catches the rest. Mitigated by M1 decision gate.
- **RichText content normalization** — `parse()` sources `content` from HTML
  largely verbatim, but any normalization it applies must be replicated;
  corpus diffing will surface cases immediately.
- **Cover/gallery complexity** — budgeted as the M2 bulk; if a block proves
  disproportionate, its renderer can temporarily fall back to pass-through +
  report line without blocking cutover for the other 95%.
- **Serializer whitespace** — `serialize()`'s `\n\n` block joins and
  innerContent whitespace handling must match exactly; covered by the
  harness from M0 day one.
- **Gutenberg upgrades later** — parity is pinned to the 15.15.0 snapshot;
  bumping `@wordpress/*` requires re-running the differential harness and
  updating renderers. Documented in the plan and the factory docblock.
- **`content/*.html` / `patterns/*.php`** — `lib/blockFixer.js` supports
  them via `fixArtefactTemplates`, but the CLI (and therefore the pipeline)
  only processes `templates/` and `parts/`. `PhpBlockFixer` scopes to the
  CLI behavior; pattern/content support is out of scope until something
  invokes it.

## Out of scope

- Porting `LayoutFixer`, `FixBlocksStep`, dropped-rhythm gating — already
  PHP and unchanged.
- Supporting arbitrary third-party blocks or deprecated-version migrations —
  the comment JSON is authored in the current format by this pipeline.
- Any behavior change to generated markup: the definition of done is
  byte-equality with today's Node output.
