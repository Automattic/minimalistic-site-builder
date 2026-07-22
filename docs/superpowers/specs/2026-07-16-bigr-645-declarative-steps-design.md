# Design: Declarative steps + validated compositions (BIGR-645)

**Linear:** [BIGR-645](https://linear.app/a8c/issue/BIGR-645/site-builder-make-each-step-declare-what-it-reads-and-writes)<br>
**Branch:** `bigr-645-site-builder-make-each-step-declare-what-it-reads-and-writes`<br>
**Status:** approved for implementation planning<br>
**Related:** `docs/composition-and-extension.md` (“Two building-block decisions”); unblocks BIGR-648 workflow generation on wpcom without hand-maintained step order.

## Problem

Every host (CLI, wpcom, Studio) composes its own list of library steps. Today the CLI default order is a hard-coded PHP array in `SiteBuilder::pipeline()`, and nothing checks that a given list is coherent. If a host builds a list where a step runs before the step that produces a file it needs, the failure appears mid-build — often after LLM spend.

Because step order and concurrency live only as imperative code, the wpcom workflow definition cannot be derived from the same source of truth as the CLI pipeline; the two will drift.

## Goals

1. Each step **declares** what it is: `id`, `label`, project files it **reads**, files it **writes**, and whether it **fans out concurrent work**.
2. When a step list is **assembled**, validate immediately: every read must be covered by an earlier write or a seed. Throw a clear error before any `run()` / LLM call.
3. The CLI **default** step list is assembled via a composition helper built from those steps (single place for the default graph).
4. The same declaration data can be **exported as a portable graph** so hosts (e.g. wpcom BIGR-648) can generate their own workflow definitions without maintaining a second ordered list.
5. Hosts supply lists by **starting from the default and adding/removing** (not only full replace).

## Non-goals

- No wpcom ability names, YAML, or orchestrator code in this package.
- No change to step *behavior*, prompts, or the semantic order of the default CLI graph (only how order is assembled and checked).
- No hook/plugin registration system (composition is explicit; see `composition-and-extension.md`).
- No in-memory step state — files remain the interface between steps.

## Decisions (locked in brainstorm)

| Topic | Decision |
|-------|----------|
| Declaration shape | Value object: `Step::declaration(): StepDeclaration` |
| Path shape | Exact project-relative paths + directory globs ending in `/*` |
| Seeds | Default `['meta.json']`; callers may pass extra seeds |
| `concurrent` | `true` only for real fan-out: `SectionsStep`, `ConcurrentGroup` — not solo `ConcurrentStep` members |
| Host list API | `StepComposition` (default + without / insertAfter / replace) |
| Export | Portable `StepGraph::describe()` only — no host-specific mapping |
| Implementation style | Thin core types + each concrete step implements `declaration()` (approach A) |

## Architecture

```text
Step::declaration() → StepDeclaration
        │
        ▼
StepComposition::default(deps) ──► ordered Step[]
        │  without / insertAfter / replace / withSeeds
        ▼
StepGraph::validate(steps, seeds) ── throw if bad
        │
        ├──► Pipeline (constructor validates with the composition's seeds)
        └──► StepGraph::describe(steps) ── portable list for hosts
```

`createProject()` still seeds `meta.json` before any step runs. Validation assumes that seed by default so the default graph is legal.

## Core types

### `StepDeclaration`

Immutable value object in `Automattic\SiteBuild`:

| Field | Type | Notes |
|-------|------|--------|
| `id` | `string` | Stable id, e.g. `site-spec` |
| `label` | `string` | Human label for logs / export |
| `reads` | `list<string>` | Project-relative paths; may end with `/*` |
| `writes` | `list<string>` | Same |
| `concurrent` | `bool` | Fan-out only (see below) |

Constructor rejects empty `id` and any non-canonical path. Paths use `/`
separators, contain no empty, `.` or `..` segments, and may use `*` only as a
terminal directory glob (`path/*`). Seeds follow the same grammar.

### `Step` interface

Add:

```php
public function declaration(): StepDeclaration;
```

Keep existing `id()`, `label()`, `run(Project): void`. `StepGraph` rejects a step when `declaration()->id` / `label` do not match `id()` / `label()`, so pipeline controls and portable exports always use one identity.

### `ConcurrentGroup`

Implements `Step` as today. Its `declaration()`:

- `id` / `label` unchanged (`member1+member2`, “Concurrently: …”)
- `reads` = union of members’ reads
- `writes` = union of members’ writes
- `concurrent` = `true`

Construction rejects duplicate member ids, overlapping writes, and any member read that overlaps another member’s write. Members may only share upstream inputs.

### Meaning of `concurrent`

| Step | `concurrent` |
|------|----------------|
| `ConcurrentGroup` | `true` |
| `SectionsStep` (header/footer/N sections via batch) | `true` |
| `GenerateImagesStep` (image batches) | `true` |
| `ThemeJsonStep` / `SectionPlanStep` alone | `false` |
| Deterministic steps | `false` |

## Validation (`StepGraph`)

```php
StepGraph::validate(array $steps, array $seeds = ['meta.json']): void
StepGraph::describe(array $steps): array
```

Pure (no disk, no LLM). Called when:

- `StepComposition` materializes `steps()`
- `Pipeline` is constructed

### Algorithm

1. `available` = set of seed paths (default includes `meta.json`).
2. For each step in order:
   - For each path in `declaration()->reads`: if not **covered** by `available`, throw `InvalidArgumentException` with step id, missing path, and a short list of available paths.
   - Add `declaration()->writes` to `available`.
3. Duplicate addressable ids → throw. Top-level ids and every
   `ConcurrentGroup` member id share one namespace, including across groups.
4. Empty step list → throw (or allow empty only if we never need it; prefer reject empty for clarity).

### Coverage rules

| Available | Needed (read) | Covered? |
|-----------|---------------|----------|
| exact `a/b.json` | exact `a/b.json` | yes |
| `theme/parts/*` | `theme/parts/header.html` | yes |
| `theme/parts/*` | `theme/parts/*` | yes |
| any path under `theme/parts/` | `theme/parts/*` | yes (directory read once anything under the dir was written) |
| `theme/parts/header.html` | `theme/theme.json` | no |

Path rules: canonical project-relative `/`-separated paths only; no empty, `.`
or `..` segments, backslashes, or wildcard forms other than a terminal `/*`.
Internal path sets preserve numeric-string filenames as strings.

### ConcurrentGroup in validation

One top-level node: check union of reads against prior `available`, then add union of writes. Do not interleave member writes mid-group.

## Composition (`StepComposition`)

Host-facing builder over an ordered `Step[]`.

```php
$composition = StepComposition::default(
    llm: $llm,
    renderer: $renderer,
    models: $models,
    temperatures: $temps,
    blockFixer: $blockFixer,
);

$composition = $composition
    // Configure seeds before a mutation that introduces a step reading them:
    // every mutation returns a fully validated composition.
    ->withSeeds('meta.json', 'plugins.json') // optional
    ->without('custom-motion')
    ->insertAfter('site-spec', $hostStep)
    ->replace('fix-blocks', $otherFixerStep);

$steps = $composition->steps(); // already validated on construction

// Seeds travel with the composition — hand BOTH to Pipeline (or let
// SiteBuilder::pipeline($composition) do it for you):
$pipeline = new Pipeline($composition->steps(), $composition->seeds());
```

- Mutations return a **new** instance (immutable style).
- Every mutation validates immediately. Call `withSeeds(...)` **before** inserting or replacing a step that reads a new seed.
- `without` / `insertAfter` / `replace` operate on **top-level** entries only (a `ConcurrentGroup` is one entry; removing only `theme-json` requires dissolving/rebuilding the group).
- Default seeds: `['meta.json']`.
- `StepComposition::default(...)` is the **single** place that constructs the CLI full graph (today’s `SiteBuilder::pipeline()` body moves here).

## SiteBuilder + Pipeline

**`SiteBuilder::pipeline()`**

- Builds deps (`PromptRenderer`, merged models/temps) as today.
- Returns `new Pipeline($composition->steps(), $composition->seeds())`.
- Optional follow-up ergonomics (same PR if cheap): `pipeline(?StepComposition $composition = null)` so hosts pass a customized composition without re-constructing deps.

**`Pipeline`**

- Constructor runs `StepGraph::validate($steps, $seeds)` (seeds default: `StepGraph::DEFAULT_SEEDS`).
- `stepIds()`, `stopIds()`, `runThrough()` unchanged in behavior.

**`createProject()`**

- Unchanged: writes/merges `meta.json` with prompt, slug, `created_at`.

## Portable export

```php
/**
 * @return list<array{
 *   id: string,
 *   label: string,
 *   reads: list<string>,
 *   writes: list<string>,
 *   concurrent: bool,
 *   members?: list<string>
 * }>
 */
StepGraph::describe(array $steps): array
```

- One row per top-level step.
- `members` present only for `ConcurrentGroup` (ordered member ids).
- No YAML, no ability names, no wpcom types. Hosts map `id` → their tools (BIGR-648).

## Default graph declarations (intent)

Order must remain exactly the current default `stepIds()` list. Declarations must match real `read*` / `write*` usage; log-only files are **omitted** from the graph unless a later step reads them (today: omit).

| id | reads (primary) | writes (primary) | concurrent |
|----|-----------------|------------------|------------|
| scaffold-theme | — | `theme/style.css`, `theme/readme.txt`, `theme/assets/motion/*` | no |
| refine-prompt | `meta.json` | `meta.json` | no |
| site-spec | `meta.json` | `siteSpec.json` | no |
| apply-identity | `siteSpec.json`, `theme/style.css`, `theme/readme.txt` | `theme/style.css`, `theme/readme.txt` | no |
| design-direction | `meta.json`, `siteSpec.json` | `designDirection.json` | no |
| theme-json | `meta.json`, `siteSpec.json`, `designDirection.json` | `theme/theme.json` | no |
| section-plan | `meta.json`, `siteSpec.json`, `designDirection.json` | `sections.json` | no |
| theme-json+section-plan | union of members | union of members | **yes** |
| sections | `siteSpec.json`, `theme/theme.json`, `sections.json`, `designDirection.json` | `theme/parts/*` | **yes** |
| section-rhythm | `sections.json`, `theme/parts/*` | `theme/parts/*` | no |
| assemble-landing-page | `sections.json`, `theme/parts/header.html`, `theme/parts/footer.html`, `theme/parts/*`, `theme/theme.json` | `theme/templates/front-page.html`, `theme/templates/index.html`, `theme/theme.json` | no |
| collect-images | `theme/parts/*`, `theme/templates/*` | `images.json` | no |
| normalize-layout | `theme/theme.json`, `theme/parts/*`, `theme/templates/*` | `theme/parts/*`, `theme/templates/*` | no |
| contrast-fix | `theme/theme.json`, `theme/parts/*`, `theme/templates/*` | same | no |
| motion-sanity | `designDirection.json`, `sections.json`, `theme/parts/*`, `theme/templates/*` | `theme/parts/*`, `theme/templates/*` | no |
| fix-blocks | `theme/theme.json`, `theme/parts/*`, `theme/templates/*` | `theme/parts/*`, `theme/templates/*` | no |
| page-styles | `theme/theme.json`, `theme/style.css`, `designDirection.json`, `theme/parts/*`, `theme/templates/*` | `theme/style.css` | no |
| custom-motion | `siteSpec.json`, `designDirection.json`, `theme/style.css`, `theme/parts/*`, `theme/templates/*` | `theme/style.css` | no |
| fonts-php | `theme/theme.json`, `designDirection.json`, `theme/parts/*`, `theme/templates/*` | `theme/fonts.php` | no |
| finalize-theme | `designDirection.json`, `theme/assets/motion/*` | `theme/functions.php`, `theme/assets/motion/*` | no |
| validate-theme | `sections.json`, `theme/style.css`, `theme/theme.json`, required template/part files, `theme/parts/*`, `theme/templates/*` | — | no |

Opt-in post-build steps use a distinct completion artifact so image-backed
contrast cannot be scheduled before generation:

| id | reads (primary) | writes (primary) | concurrent |
|----|-----------------|------------------|------------|
| generate-images | `images.json`, `siteSpec.json`, `designDirection.json`, `theme/parts/*`, `theme/templates/*` | `images.json`, `images.generated.json`, `theme/assets/*`, `theme/parts/*`, `theme/templates/*` | **yes** |
| cover-contrast | `images.generated.json`, `theme/theme.json`, `theme/assets/*`, `theme/parts/*`, `theme/templates/*` | `theme/parts/*`, `theme/templates/*` | no |

`theme/*` means “the theme tree” for validation coverage: available `theme/*` covers any `theme/...` read; writing `theme/*` marks the whole tree available. Exact path strings will be confirmed against each step’s code during implementation; this table is the intent.

Coverage for `theme/*`: treat like a directory glob at the `theme/` prefix (same rules as `theme/parts/*` but one level higher).

## Error handling

- All graph errors: `InvalidArgumentException` with actionable messages (step id + missing path + available summary).
- No soft-fail / warnings for missing inputs — construction fails hard.
- Runtime file missing errors inside `run()` remain as today (resume/partial project cases); graph validation is necessary but not sufficient for every on-disk state.

## Testing

Unit tests under `tests/unit/` (existing harness):

1. **Default validates** — `StepComposition::default(...)->steps()` does not throw; `Pipeline` accepts it.
2. **Bad order throws** — e.g. `sections` before `section-plan` / without prior writes for its reads.
3. **Seeds** — without `meta.json` in seeds, a list starting with `refine-prompt` fails; with seed, passes.
4. **Glob coverage** — write `theme/parts/*` satisfies later reads of `theme/parts/*` and concrete paths under it; write concrete under `parts/` satisfies a later `theme/parts/*` read.
5. **Describe snapshot** — ordered ids + `concurrent` flags for the default graph match expectations (drift alarm).
6. **SiteBuilder stepIds** — existing expected list in `site_builder_test.php` remains green.
7. **Test doubles** — `RecorderStep`, `RecordingConcurrentStep` implement `declaration()`.
8. **Identity namespace** — member ids cannot collide with top-level ids or members of another group.
9. **Canonical paths** — aliases and unsupported globs are rejected for declarations and seeds; numeric-string paths remain strings.
10. **Post-image ordering** — `cover-contrast` requires the completion artifact written last by `generate-images`.

## File / touch list (implementation)

| Area | Action |
|------|--------|
| `src/StepDeclaration.php` | new |
| `src/StepGraph.php` | new (validate + describe + coverage) |
| `src/StepComposition.php` | new (default graph + mutations) |
| `src/Step.php` | add `declaration()` |
| `src/ConcurrentGroup.php` | implement `declaration()` |
| `src/Pipeline.php` | validate on construct |
| `src/SiteBuilder.php` | use `StepComposition::default` |
| `src/Steps/*.php` | each step: `declaration()` |
| `tests/unit/step_graph_test.php` | new |
| `tests/unit/step_composition_test.php` | new |
| `tests/unit/pipeline_test.php` / concurrent / site_builder | update fakes |
| `docs/composition-and-extension.md` | short pointer that declarative steps landed (optional, small) |

## Out of scope (follow-ups)

- BIGR-648: map `describe()` → wpcom workflow + promise fan-out for `concurrent` steps.
- Host-authored steps outside this package (they only need to implement `Step` + valid declarations).
- Step-addressable CLI `create` / `resume` surface (separate design note items).

## Success criteria

- CLI default list is built through `StepComposition::default` and validates at assembly time.
- A deliberately broken list throws a clear error at assembly, with no LLM calls.
- `StepGraph::describe(default steps)` exposes order, reads/writes, and concurrent flags for hosts.
- Existing unit suite green; default `stepIds()` unchanged.

## Implementation approach

Approach A (approved): thin core types; every concrete step returns its own `StepDeclaration`; no central registry of IO that can drift from classes.
