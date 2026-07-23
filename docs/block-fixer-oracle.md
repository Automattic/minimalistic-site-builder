# Block-fixer oracle: where it lives and how to bring it back

The pure-PHP block fixer (`PhpBlockFixer`) was ported from a Node implementation
that doubled as the **oracle**: it ran the real `@wordpress/blocks` runtime in a
pinned container and generated every frozen artifact the PHP port is certified
against — `src/BlockSerializer/Registry/generated-registry.php`, the fixture
snapshots under `tests/fixtures/block-fixer/`, and the golden cases under
`tests/fixtures/block-fixer/cases/`. (What those artifacts contain and how the
PHP pipeline consumes them is documented in `docs/block-fixer-architecture.md`.)

The oracle and all regeneration tooling were removed in commit `619b8c9`
("Remove the Node block fixer"). That is safe while the compatibility domain
stays frozen, but the moment Gutenberg drifts — or the domain grows — the
artifacts must be regenerated, and the tooling only exists in git history.
This note is the map.

## Where the oracle lives

Everything needed is in commit `abcf523` ("Implement pure-PHP block fixer"),
the last commit where the oracle and the PHP port coexisted green:

| Piece | Path at `abcf523` |
| --- | --- |
| Oracle CLI + instrumentation | `bin/block-fixer/` (`oracle.js`, `fix-templates.js`, `lib/`, `test/`) |
| Registry generator | `bin/generate-block-registry.js` |
| Fixture/golden generator | `bin/generate-block-fixer-fixtures.js` |
| Save-output probe tool | `bin/block-fixer/probe-save.js` |
| CI harness (pinned container) | `.github/workflows/block-fixer-oracle.yml` |

The exact runtime environment is pinned in
`tests/fixtures/block-fixer/oracle-manifest.json` under `fingerprint`:
container image `node:22.19.0-bookworm-slim` by digest, `package-lock.json`
SHA-256, and per-package versions (`@wordpress/blocks@15.15.0`,
`@wordpress/block-library@9.42.0`, …).

## How to resurrect it

```sh
git worktree add /tmp/oracle abcf523
cd /tmp/oracle
docker run --rm -it -v "$PWD":/repo -w /repo \
  docker.io/library/node:22.19.0-bookworm-slim@sha256:4a4884e8a44826194dff92ba316264f392056cbe243dcc9fd3551e71cea02b90 \
  bash -c 'npm ci && npm test --workspace=bin/block-fixer'
```

- `npm run oracle:verify --workspace=bin/block-fixer` re-derives every frozen
  artifact and fails on drift.
- `npm run oracle:update --workspace=bin/block-fixer` regenerates them
  (`--update` on both generators). Copy regenerated artifacts into the current
  tree, then **re-freeze** `oracle-manifest.json` (see below).

To certify against a *newer* Gutenberg, bump the pins in
`bin/block-fixer/package.json` inside the worktree, regenerate, and record the
new fingerprint in the manifest.

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

Prefer resurrecting the oracle over hand-certifying whenever the change is more
than a strategy declaration — hand-written save output has already diverged
once (attribute ordering on `core/details`, caught and fixed alongside the
golden case).
