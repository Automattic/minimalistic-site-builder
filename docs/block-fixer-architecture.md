# Block fixer architecture: frozen Gutenberg references and the PHP pipeline

Current-state reference for how the pure-PHP block fixer relates to Gutenberg.
The design in one sentence: **nothing touches Gutenberg at runtime — every fact
about block behaviour was extracted once from the real `@wordpress` packages by
a pinned Node generator, frozen into committed artifacts, and PHP consumes only
those artifacts.** For where the (now removed) extraction tooling lives and how
to regenerate the artifacts, see `docs/block-fixer-oracle.md`. For the original
design rationale and milestones, see `plan/php-block-fixer-plan.md`.

## The frozen artifacts

All produced by `bin/generate-block-registry.js` /
`bin/generate-block-fixer-fixtures.js` (in git history at `abcf523`) running
the real Gutenberg runtime — JSDOM plus `@wordpress/block-library@9.42.0`,
which registers all core blocks exactly as the editor would — inside the
container pinned by digest in `oracle-manifest.json`:

| Artifact | Contents |
| --- | --- |
| `src/BlockSerializer/Registry/generated-registry.php` | One opcache-immutable `require`. Per registered block: `apiVersion`, `attributes` schema, `attributeOrder` (schema insertion order — drives comment-JSON key order), `supports`, `sourceInventory` (which attributes are sourced from HTML vs stored in the comment, with selectors), and `saveProbes` — the exact bytes returned by the real `getSaveContent()`/`serialize()` for default attributes, an inner-blocks sentinel, and per-block probe attribute sets. Plus a `hookTrace` of every registered `blocks.*` filter. |
| `src/BlockSerializer/Registry/supported-blocks.php` | The hand-reviewed manifest mapping each admitted block to a `SaveStrategy`. The generator asserted it equal to the JS-side manifest at generation time. |
| `tests/fixtures/block-fixer/registered-runtime.json` | JSON twin of the registry snapshot, consumed by tests. |
| `tests/fixtures/block-fixer/renderer-probes.json` | Save probes replayed byte-for-byte against the PHP renderers by `block_serializer_renderer_snapshot_test.php`. |
| `tests/fixtures/block-fixer/coverage.json` | Inventory/coverage bookkeeping from generation time. |
| `tests/fixtures/block-fixer/oracle-manifest.json` | Provenance: environment fingerprint (container digest, package pins, lockfile SHA), re-frozen hashes of the registry artifacts (enforced by `oracle_manifest_consistency_test.php`), and the post-oracle `amendments` record. |
| `tests/fixtures/block-fixer/cases/*/` | End-to-end golden cases (see below). |

Three block sets structure the compatibility domain:

- **Registered universe** — every block the pinned runtime registers (106).
- **Supported subset** — blocks admitted by `supported-blocks.php`; everything
  else fails closed.
- **Observed set** — block names actually appearing in fixture inputs; must be
  a subset of the supported set.

## The PHP processing pipeline

`PhpBlockFixer::fixReport()` (`src/PhpBlockFixer.php`) is the transaction
shell: it discovers `parts/*.html` and `templates/*.html`, runs each file
through `Serializer::transform()` to a **fixed point** (at most 5 passes;
non-convergence throws), and only then writes — every changed file is staged
beside its target by `NativeStagedFileWriter` and committed as a sequence of
atomic renames. Any failure before commit leaves every input untouched.

One `Serializer::transform()` pass (`src/BlockSerializer/Serializer.php`):

1. **Paragraph pre/post pass** — `ParagraphFixer` repairs nested-paragraph
   markup before parsing and re-checks after serialization (`nested-paragraph`
   repair rows).
2. **Parse** — `Parser/DefaultParser` is a port of
   `@wordpress/block-serialization-default-parser`; it produces block nodes and
   freeform nodes. Freeform bytes are trimmed JS-style and preserved (the
   pinned runtime registers no freeform handler).
3. **Per block, recursively** (`serializeBlock()`):
   - Unregistered names take the pinned `serializeRawBlock()`/`core/missing`
     path: original bytes are preserved, but registered *children* are still
     strategy-checked so the fallback is not a tunnel around the guard.
   - `BlockRegistry::strategy()` fails closed on anything registered but
     unsupported.
   - `Attributes/AttributeSourcer` extracts sourced attributes from the block's
     HTML using the registry `sourceInventory` (selectors, rich-text, HTML
     attributes); comment-stored attributes come from the delimiter JSON.
   - `Attributes/AttributeNormalizer` produces the canonical attribute set:
     `Validation/Validator` token-compares the block's actual HTML against the
     canonical render for the current attributes, and on mismatch the reviewed
     `DeprecationAdapters` / `CompatibilityRepairs` are consulted (each
     acceptance emits a typed `Repair` row). Unknown legacy paragraph
     signatures throw; the paragraph-only scope of that guard is a recorded
     decision (plan addendum).
   - `Save/SaveStrategyRegistry` dispatches on the block's `SaveStrategy`:
     `DYNAMIC_NULL` → empty save, `INNER_BLOCKS` → inner content only,
     `RAW_CONTENT` (core/html) → attribute bytes, `CONDITIONAL`
     (core/navigation) → ref-dependent, `MISSING_BLOCK` → original content,
     `STATIC_RENDERER` → `Renderers/CoreBlockRenderer` builds the save tree.
     Note the pipeline **always re-renders canonically**; already-canonical
     input reproduces its own bytes, which is what makes the fixed point work.
   - `CoreBlockRenderer` mirrors each block's `save.js`; `Supports/*` computes
     what `useBlockProps.save()` would add — `SupportEngine` and `StyleEngine`
     derive the preset classes and `style` declarations from the registry
     `supports`, and `SupportDomainGuard` fails closed on any style path
     outside the reviewed tree. `Html/HtmlSerializer` emits the bytes.
   - `CommentSerializer` writes the delimiter: attributes ordered by the
     registry `attributeOrder`, encoded by `Json/JsJsonEncoder` +
     `JsStringCodec`, which reproduce Gutenberg's exact escaping (including
     replacement order) and preserve the `{}`-vs-`[]` distinction via typed
     `Json/*` values.
4. **Report** — `DroppedContentDetector` diffs original vs fixed content for
   lost values; `FixerReport` normalizes to the frozen grammar: totals
   `N` (changed files), `M` (eligible files), `D` (drops), `T` (themes),
   per-file status `ok`/`FIXED`/`skip`, plus `K` reviewed repair rows.

## Golden fixture cases

Each directory under `tests/fixtures/block-fixer/cases/<name>/` is one
end-to-end contract, executed by `php_block_fixer_golden_test.php`:

- `input/` and `expected/` — theme trees with identical file inventories;
  `expected/` holds the pinned fixed-point bytes. The test runs the fixer on a
  copy of `input/`, requires byte equality, then runs it **again** and requires
  a complete no-op.
- `report.json` — the expected normalized report.
- `repairs.json` — reviewed repair rows (`reviewed: true` is asserted), the
  expected `k`, and `secondInvocation` expectations (always `k: 0`).
- `case.json` — provenance metadata, not consumed by the harness: `name`,
  `milestone`, `capabilities` tags, and `provenance.kind`:
  - `committed-dirty-seed` — oracle-era case; carries full oracle
    instrumentation (per-pass validation/deprecation traces, input/output
    hashes).
  - `advisory-corpus-import` — oracle-era case imported from a real project.
  - `post-oracle-authored` — authored after the oracle's removal; no
    instrumentation, and `provenance.notes` must cite the certification
    evidence (registry `saveProbes` and/or upstream Gutenberg source at the
    pinned tag). `cases/details-static-renderer` is the template.

Post-oracle changes to the frozen registry artifacts are governed by the
amendment rule in `docs/block-fixer-oracle.md`: manifest amendment + re-frozen
hashes + an end-to-end golden, enforced by
`tests/unit/oracle_manifest_consistency_test.php`.
