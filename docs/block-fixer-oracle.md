# Block-fixer oracle: what certifies the PHP port

The pure-PHP block fixer (`PhpBlockFixer`) was ported from a Node implementation
that doubled as the **oracle**: it runs the real `@wordpress/blocks` runtime in a
pinned environment and generates the registry, runtime snapshot, coverage
metadata, and generated golden cases the PHP port is certified against. Four
reviewed cases deliberately remain outside generation, and `renderer-probes.json`
is still pinned rather than re-derived; both boundaries are documented below.
(How PHP consumes these artifacts is documented in
`docs/block-fixer-architecture.md`.)

The oracle and all regeneration tooling were removed in commit `619b8c9`
("Remove the Node block fixer") and lived only in git history for a while. They
are **back in the tree** as development tooling: `bin/block-fixer/` plus the two
generators, an npm workspace beside `bin/screenshot`. Nothing in the PHP
pipeline calls them and production still runs pure PHP — but regenerating the
artifacts is now an ordinary repo operation rather than an archaeology exercise,
which is what let the domain drift into hand-certification before.

```sh
node bin/block-fixer/check-fingerprint.js           # is this the pinned runtime?
npm ci
npm run oracle:verify --workspace=bin/block-fixer   # re-derive, fail on drift
npm run oracle:update --workspace=bin/block-fixer   # regenerate
npm run test:gates --workspace=bin/block-fixer      # structural oracle gates
npm run test:fixed-point --workspace=bin/block-fixer # every committed case
```

Verifying or regenerating from a machine that is not linux/x64 needs the
container because both operations reproduce the frozen environment fingerprint.
The oracle's unit suites can run directly with the pinned Node. See
[Which environment to verify or regenerate from](#which-environment-to-verify-or-regenerate-from).

## Where the oracle lives

| Piece | Path |
| --- | --- |
| Oracle CLI + instrumentation | `bin/block-fixer/` (`oracle.js`, `fix-templates.js`, `lib/`, `test/`) |
| Registry generator | `bin/generate-block-registry.js` |
| Fixture/golden generator | `bin/generate-block-fixer-fixtures.js` |
| Save-output probe tool | `bin/block-fixer/probe-save.js` |
| CI harness | `.github/workflows/block-fixer-oracle.yml` |

The version that predates the removal is still pinned by the
**`block-fixer-oracle`** tag (commit `abcf523`, "Implement pure-PHP block
fixer" — the last commit where the oracle and the PHP port coexisted green).
Before that tag it was reachable only from a feature branch, so deleting the
branch would have taken the oracle with it.

The exact runtime environment is pinned in
`tests/fixtures/block-fixer/oracle-manifest.json` under `fingerprint`: container
image `node:22.19.0-bookworm-slim` by digest, `package-lock.json` SHA-256, and
per-package versions (`@wordpress/blocks@15.15.0`,
`@wordpress/block-library@9.42.0`, …).

## What the generators derive

- `bin/generate-block-registry.js` → `generated-registry.php`,
  `registered-runtime.json`, and the `fingerprint` plus `registry` hashes in
  `oracle-manifest.json`. Its inputs are `bin/block-fixer/lib/supportManifest.js`
  (the reviewed support decisions) and the runtime itself.
- `bin/generate-block-fixer-fixtures.js` → the golden cases under
  `tests/fixtures/block-fixer/cases/`, from the definitions in
  `bin/block-fixer/lib/fixtureCases.js`, plus `coverage.json`.

Both refuse to write when a case exercises a deprecation that is not listed in
`REVIEWED_DEPRECATIONS`, or a block that is not in the reviewed support
manifest. Those gates are the point: adding to the compatibility domain is a
review step, not a regeneration side effect.

### What is *not* re-derived: `renderer-probes.json`

`renderer-probes.json` is a frozen snapshot of the runtime's `save()` output per
block, captured before the oracle was removed and never rewritten since. No
generator in the tree regenerates it: the fixture generator only reads it, checks
its schema and coverage, and records its SHA-256 in `coverage.json`.

It is still enforced — `tests/unit/block_serializer_renderer_snapshot_test.php`
replays every probe against the PHP renderers as a blocking gate, and that is the
test that caught `core/button`'s attribute order. But it is pinned rather than
re-derived, so if Gutenberg's save output moved, nothing here would notice.
Closing that would mean adding a probe generator alongside the other two;
`bin/block-fixer/probe-save.js` prints one block's save output ad hoc and is the
starting point, not a snapshot writer.

So "every committed artifact is oracle-derived" is true of the registry, the
fixtures and the goldens — and not yet of the probe snapshot.

To certify against a *newer* Gutenberg, bump the pins in
`bin/block-fixer/package.json`, regenerate, and re-freeze the fingerprint.

## Which environment to verify or regenerate from

The artifacts record `platform` and `architecture`, and CI checks the running
runtime against them before it verifies anything — so **regenerating from the
wrong architecture silently rewrites provenance and then fails the gate**.
The frozen fingerprint is linux/x64.

Everything else environment-derived — Node version, v8, ICU — comes from the
Node release, and `fingerprint.container` is a constant the generator writes
rather than something it probes, so running outside the container does not
change it. Confirmed twice: regenerating on macOS/arm64 with `nvm`, and on
linux/arm64 in the container, both re-derived `generated-registry.php` and
`registered-runtime.json` **byte-identically apart from the recorded
`platform`/`architecture`** (and the hashes over them). `v8` and `icu` matched
exactly in both.

The unit suites can run anywhere with the pinned Node. `oracle:verify` and
`oracle:update` both reproduce the recorded fingerprint, so they must run in
the frozen linux/x64 environment. CI gets that environment from
`actions/setup-node` on `ubuntu-24.04`; from any other platform, use the
container and pin the **linux/amd64 image digest** rather than the index digest:

```sh
docker run --rm -it -v "$PWD":/repo -w /repo \
  docker.io/library/node:22.19.0-bookworm-slim@sha256:cff78eb5aa1cf27dc2b6aeea9d31366415a43e9a9ea0ddec00d780b2b66fad0f \
  bash -c 'npm ci && npm run oracle:update --workspace=bin/block-fixer'
```

That digest is recorded as `fingerprint.container.linuxAmd64Digest`; the index
digest beside it resolves to the host's architecture, which on Apple Silicon is
arm64. The platform-specific digest makes the intended architecture explicit;
there is no need to combine it with `--platform`.

## The amendment rule

`tests/unit/oracle_manifest_consistency_test.php` fails whenever
`generated-registry.php` or `registered-runtime.json` change without a manifest
re-freeze. Post-oracle changes to those artifacts are allowed only when:

1. the edit is listed under `amendments` in `oracle-manifest.json` with its
   certification evidence (upstream Gutenberg source at the pinned tag, or the
   oracle-generated `saveProbes` already in the registry), and
2. an end-to-end golden case under `tests/fixtures/block-fixer/cases/`
   exercises the new behaviour (see `cases/details-static-renderer` for the
   post-oracle template), and
3. the `registry.*Sha256` hashes are updated to the new file contents.

**The ledger is currently empty, and that is the healthy state**: every
committed artifact is oracle-derived. The four amendments it used to carry were
retired by folding their subjects back into the oracle's inputs — most visibly
`core/details`, whose hand-written save output had already diverged from
Gutenberg on attribute ordering. Hand-certifying is the escape hatch for when
regenerating is impossible, not the default; now that the tooling is in the
tree, it should almost never be reached for.

## Is it still alive? (CI)

`.github/workflows/block-fixer-oracle.yml` runs on every PR that touches
`src/BlockSerializer/**`, the oracle, or the fixtures, and on every push to
trunk. It checks the runtime against the frozen fingerprint, installs the locked
dependencies, and re-derives every frozen artifact — failing on drift. So a
change to the serializer, the support manifest or the case definitions cannot
land unless the real Gutenberg runtime agrees with it.

The oracle's own suite runs too, split in two. Both `test:gates` and
`test:fixed-point` are blocking. The latter replays every committed case,
including the reviewed exclusions below, before reporting any failures. Known
differences are pinned to their exact runtime-output hashes, so a new difference
or a change to an existing one fails CI.

### What the oracle gate does and does not cover

`oracle:verify` only regenerates the 25 cases listed in `fixtureCases.js`. Four
committed case directories are deliberately not generated:

| case | why it is held back | what still gates it |
| --- | --- | --- |
| `carried-elements-signatures` | documented PHP/Gutenberg runtime divergence — see below | PHP golden test + exact oracle-output hash |
| `paragraph-conflicting-text-align` | reviewed *degradation* policy, which Gutenberg validation does not express | PHP golden test + exact oracle-output hash |
| `paragraph-opacity-reviewed-drop` | reviewed opacity-removal policy (BIGR-728), which Gutenberg reserialization does not implement | PHP golden test + exact oracle-output hashes |
| `tbilisi60-traditional-offerings-fixed-point` | documented cosmetic attribute-order divergence — see below | PHP golden test + exact oracle-output hash |

This is narrower than it looks. All four are replayed byte-for-byte by
`php_block_fixer_golden_test.php`, which runs in the blocking `Tests` workflow,
so a **port** regression in them cannot land green. The blocking fixed-point
suite separately replays all four against Gutenberg and requires exactly the
five reviewed mismatching files and output hashes. The committed-case inventory
gate also rejects any case that is neither generated nor explicitly excluded.

### The two runtime divergences, precisely

In `carried-elements-signatures`, a `core/heading` with a legacy top-level
`textAlign` loses its centering in the port and keeps it in the real runtime.
A/B against the runtime, holding the comment attributes fixed (legacy
`textAlign` plus a `style` object) and varying only the authored `<h2>`:

| authored `<h2>` | runtime | port |
| --- | --- | --- |
| matches the deprecated save exactly | deprecation matches and migrates to `style.typography.textAlign`; the shallow raw-comment overlay then restores the authored `style`, so the alignment is **dropped** | agrees |
| carries an inline `color:` no attribute backs | the deprecated save mismatches too, so **no** deprecation runs and the recovered class stays in `className` — **kept** | **drops it** |
| carries an unrelated unbacked class | deprecation still matches, unknown classes being absorbed first — **dropped** | agrees |

`DeprecationAdapters::heading()` matches on the weaker predicate *"a
`has-text-align-*` class was recovered"* and consumes it, where the runtime
first requires the deprecated save to validate against the authored HTML.

Two things this is **not**, both of which looked plausible and were disproved by
the A/B above: it is not about carried style families — `style.spacing`, fully
reviewed and not carried, behaves identically — and it is not a missing
`fixCustomClassname`. `CompatibilityRepairs::apply()` is a faithful port of that
rescue and does run; the adapter then consumes what it rescued.

In `tbilisi60-traditional-offerings-fixed-point`, both implementations preserve
the same group attributes and rendered meaning. Gutenberg writes the outer
group's `class` before its `id`; the reviewed PHP canonical output writes `id`
before `class`. The exact Gutenberg output hash is pinned so even this cosmetic
difference cannot change unnoticed.
