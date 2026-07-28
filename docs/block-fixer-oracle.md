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

## Is it still alive? (CI)

`.github/workflows/block-fixer-oracle.yml` runs the resurrection above on every
PR that touches `src/BlockSerializer/**` or the fixtures, and weekly. It
materialises `abcf523` with `git worktree`, checks the runtime against the
frozen fingerprint, installs the locked dependencies, runs the oracle's own
tests, and re-derives every frozen artifact — failing on drift.

That gate protects the *recipe*, not today's artifacts: it catches the tooling
rotting unnoticed (a yanked or re-published dependency, a lockfile that stops
resolving, a Node incompatibility), so the day the domain has to grow, the
instructions above still work.

### Docker is optional

The container pins one thing the artifacts actually record: **platform and
architecture**. Everything else environment-derived — Node version, v8, ICU —
comes from the Node release, and `fingerprint.container` is a constant the
generator writes rather than something it probes, so running outside the
container does not change it.

Verified 2026-07-28 on macOS/arm64 with Node v22.19.0 from `nvm` and no
container: the oracle's own suite passes (13/13), the fixture generator
verifies all 19 cases, and both `generated-registry.php` and
`registered-runtime.json` re-derive **byte-identically apart from the recorded
`platform`/`architecture`**. `v8` and `icu` matched the frozen manifest exactly.

So: to *run or verify* the oracle, the pinned Node is enough — which is why CI
uses `actions/setup-node` on `ubuntu-24.04` (already linux/x64) instead of the
container. To *regenerate* artifacts for commit from a non-linux/x64 machine,
use the container so the fingerprint matches.

### Why this is not yet a parity gate on current artifacts

The oracle at this pin cannot certify what the tree carries today: the
compatibility domain has grown past it through the four hand-reviewed
`amendments` in `oracle-manifest.json`. Running the generators against the
current fixtures fails on the renderer-probe coverage for blocks the pinned
`@wordpress/block-library@9.42.0` does not know — `core/details` most visibly.
That is the amendment mechanism working as designed, not a defect.

Turning this into a true differential gate means bumping the Gutenberg pins in
`bin/block-fixer/package.json` inside the worktree, regenerating, re-freezing
the fingerprint, and retiring the amendments those versions absorb. Until then
the PHP-side invariants (`oracle_manifest_consistency_test.php`) remain the
enforcement, and this workflow keeps the escape hatch usable.
