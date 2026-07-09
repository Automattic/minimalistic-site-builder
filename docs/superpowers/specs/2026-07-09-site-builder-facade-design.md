# SiteBuilder facade — design

**Date:** 2026-07-09  
**Status:** approved for implementation planning  
**Scope:** Milestone 1 Task 4 public API only  
**Related:** [`docs/superpowers/plans/2026-07-01-site-build-package-milestone-1.md`](../plans/2026-07-01-site-build-package-milestone-1.md) (Task 4), Linear ticket *WPCOM: Create the WpcomAiLlm transport adapter* (depends on this facade; lives in the internal repo)

## Goal

Replace the procedural `build_pipeline()` assembler (and the host-side project-seeding that lived next to it) with a single injectable facade: **`SiteBuilder`**. A consumer constructs it with its own `Llm` (and path/fixer deps), then runs the default CLI pipeline or a partial flow via `pipeline()->runThrough(...)`.

`make_llm()` stays as a **CLI-only** helper (env → `AnthropicClient`). Hosts never call it; they inject their own `Llm`.

This is a **repackaging** change. It does not change prompt templates, step logic, model/temperature defaults, or output artifacts. The CLI keeps the same flags and behavior.

## Non-goals

- Host-composed / alternate step graphs (`docs/composition-and-extension.md` remains a later concern)
- Injectable temperatures on the constructor
- A thick `build()` that owns reporting, images, or Playground serve
- `WpcomAiLlm` implementation (Task 5 / internal repo)
- `Package`, `BlockFixer`, `NodeBlockFixer` (Tasks 2–3; assumed present when Task 4 lands)
- Stateless `Unit` extraction (prerequisite after Task 4)
- Studio HTTP transport / multi-harness resolution

## Role

`SiteBuilder` is the **consumer-facing entry point** for the default site-creation composition.

| Responsibility | Owner |
|---|---|
| Inject `Llm`, prompts dir, output root, `BlockFixer`, model overrides | `SiteBuilder` constructor |
| Assemble the default step sequence | `SiteBuilder::pipeline()` |
| Create / locate project directories under the output root | `SiteBuilder::store()` / `createProject()` |
| Run steps, stop at `--until`, report timing/tokens | Host (`Pipeline::runThrough` + host callbacks) |
| Optional images, Playground serve, flag parsing | CLI only |

There is **no** `build()` method. Hosts call `createProject` then `pipeline()->runThrough(...)`.

## Public API

```php
namespace Automattic\SiteBuild;

/**
 * Consumer-facing entry point for the default site-creation pipeline.
 *
 * @param array<string,string> $models step id => model id overrides
 */
final class SiteBuilder
{
    /** @param array<string,string> $models */
    public function __construct(
        private Llm $llm,
        private string $promptsDir,
        private string $outputRoot,
        private BlockFixer $blockFixer,
        private array $models = [],
    ) {}

    public function pipeline(): Pipeline;

    public function store(): ProjectStore;

    public function createProject(string $prompt, ?string $slug = null): Project;
}
```

### Constructor parameters

| Param | Meaning |
|---|---|
| `Llm $llm` | Transport shared by every LLM step and `ConcurrentGroup` |
| `string $promptsDir` | Directory of `*.md` prompt templates (typically `Package::promptsDir()`) |
| `string $outputRoot` | Root for project folders (CLI: `repo_path('projects')`; wpcom: host-chosen dir) |
| `BlockFixer $blockFixer` | Injected into `FixBlocksStep` (CLI: `NodeBlockFixer::default()`) |
| `array $models` | Partial map of step id → model id; merged over package defaults |

No factory helpers (`withDefaults`, etc.) in this design. Callers pass paths and fixer explicitly.

### `pipeline(): Pipeline`

Relocates the **exact** step assembly currently in `build_pipeline()` (`src/bootstrap.php`), with these substitutions only:

| Today | Inside `SiteBuilder::pipeline()` |
|---|---|
| `$llm` | `$this->llm` |
| `new PromptRenderer(repo_path('prompts'))` | `new PromptRenderer($this->promptsDir)` |
| `step_models()` | package default map **merged with** `$this->models` (consumer wins per key) |
| `step_temperatures()` | same helper / same defaults as today (not constructor-injected) |
| `new FixBlocksStep()` | `new FixBlocksStep($this->blockFixer)` |

Constraints:

- Step order, ConcurrentGroup membership, prompts, and validators are unchanged.
- `GenerateImagesStep` stays **out** of the default pipeline (CLI opt-in after a full run).
- `pipeline()` returns a **fresh** `Pipeline` on every call (no memoization).
- Model merge must apply defaults first, then overlay `$this->models`. Do not pass bare `$models['site-spec'] ?? null` without the default layer — that would drop intentional model selection.
- Temperatures remain assembly-internal via the existing `step_temperatures()` / env-override behavior for CLI parity. Injectable temperatures are out of scope for M1.

After this task, **`build_pipeline()` is removed**. `SiteBuilder::pipeline()` is the only assembler of the default sequence.

### `store(): ProjectStore`

```php
return new ProjectStore($this->outputRoot);
```

### `createProject(string $prompt, ?string $slug = null): Project`

Matches `bin/build.php` project seeding (not the plan’s simplified sketch that slugified the prompt):

1. Resolve slug:
   - `$slug === null` → `$this->store()->freeSlug(ProjectStore::randomSlug())`
   - explicit `$slug` → use as given (no `freeSlug`; re-runs can target the same directory)
2. `$project = $this->store()->create($resolvedSlug)`
3. Seed `meta.json` with merge over any existing meta (so demo orchestrators can pre-seed fields):

```php
$meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
$project->writeJson('meta.json', array_merge($meta, [
    'prompt'           => $prompt,
    'provisional_slug' => $project->slug(),
    'created_at'       => gmdate('c'),
]));
```

4. Return `$project`

## CLI wiring

Every entry point that currently calls `build_pipeline()` must switch to the facade: at minimum `bin/build.php`, and also `bin/create.php`, `bin/eval.php`, and `tests/integration/pipeline_test.php` (grep for callers at implement time).

`bin/build.php` constructs the facade; reporting, images, and serve stay in the CLI:

```php
$llm = make_llm();
$builder = new SiteBuilder(
    llm: $llm,
    promptsDir: Package::promptsDir(),
    outputRoot: repo_path('projects'),
    blockFixer: NodeBlockFixer::default(),
    models: step_models(),
);
$pipeline = $builder->pipeline();

// Validate --until BEFORE createProject (unknown id must not leave a project dir)
if ($until !== null && !in_array($until, $pipeline->stopIds(), true)) {
    // error + exit (unchanged messaging)
}

$project = $builder->createProject($prompt, $slug);
// existing runThrough + BuildReport + optional GenerateImagesStep + playground serve
```

**Bootstrap after Task 4:**

| Keep | Remove |
|---|---|
| `make_llm()`, `make_image_client()` | `build_pipeline()` |
| `step_models()`, `step_temperatures()`, `default_llm_model()`, `llm_temperature()` | |
| `repo_path()` (CLI path helper until fully superseded by `Package` for package assets) | |

`step_models()` / `step_temperatures()` remain shared helpers so CLI env overrides and `SiteBuilder` assembly stay one map.

## wpcom consumption (informational)

Not implemented in the public package. Documents the intended use of this API:

```php
require_lib( 'a8c/site-build' );

$builder = new \Automattic\SiteBuild\SiteBuilder(
    llm:        new \WPCOM\SiteBuild\WpcomAiLlm(feature: 'block-theme-generator'),
    promptsDir: \Automattic\SiteBuild\Package::promptsDir(),
    outputRoot: $themes_output_dir,
    blockFixer: /* host BlockFixer, or NodeBlockFixer::default() if Node is available */,
    models:     ['sections' => 'claude-opus-4-8'],
);

$project = $builder->createProject($user_prompt);
$builder->pipeline()->runThrough($project);
// partial: $builder->pipeline()->runThrough($project, 'theme-json');
```

Notes for the adapter ticket (separate design/implementation):

- `WpcomAiLlm` implements the frozen `Llm` interface over wpcom’s provider factory; batch methods run **sequentially**.
- JSON path matches the library Anthropic client (instruct JSON-only + decode), not schema structured-output.
- Adapter lives in the **internal** repo; the public package only exposes the `Llm` port + this facade.

## Error handling

- The facade does **not** catch step/LLM exceptions.
- Unknown `--until` validation remains in the CLI, against `pipeline()->stopIds()`, before `createProject`.
- CLI continues to attribute failures to the in-flight step via the existing `onStart` callback.

## Testing

**New:** `tests/unit/site_builder_test.php`

- Inject `FakeLlm` + a noop `BlockFixer`, temp `outputRoot`, real `Package::promptsDir()`.
- Assert known stop ids (e.g. `site-spec`) appear on `pipeline()->stopIds()`.
- `createProject` + `runThrough($project, 'site-spec')` writes `siteSpec.json`.
- Queue FakeLlm responses from the existing integration canned set for the slice under test.

**Update:**

- Integration / unit callers of `build_pipeline($llm)` switch to constructing `SiteBuilder` (or `->pipeline()`).
- Full suite remains green at the task boundary.
- CLI smoke (optional with live key): `php bin/build.php "…" --until=site-spec --no-serve`.

## Data flow

```
Host constructs SiteBuilder(llm, promptsDir, outputRoot, blockFixer, models?)
        │
        ├─ createProject(prompt, slug?)
        │     → ProjectStore under outputRoot
        │     → seeds meta.json
        │
        └─ pipeline()
              → PromptRenderer(promptsDir)
              → models = defaults ⊕ overrides
              → temperatures = step_temperatures()
              → Pipeline([ ScaffoldThemeStep, RefinePromptStep, … FixBlocksStep(blockFixer), … ])
                    │
                    └─ runThrough(project, until?, reporter?, onStart?)
                          steps read/write Project on disk
```

## Relation to composition-and-extension

`docs/composition-and-extension.md` argues hosts will eventually compose **different** graphs. This design intentionally hardcodes the **default / CLI composition** inside `SiteBuilder::pipeline()` for Milestone 1. That matches Task 4 and unblocks wpcom’s simple in-process path. Alternate graphs are a follow-on; they should not block this facade.

## Implementation notes for Task 4

1. Depends on Tasks 1–3 (namespace/autoload, `Package`, `BlockFixer` / `NodeBlockFixer`, `FixBlocksStep` constructor injection).
2. Move assembly body carefully; only the substitutions listed above.
3. Preserve meta merge and random free-slug behavior in `createProject`.
4. Remove `build_pipeline()` once CLI and tests are on the facade.
5. Do not invent `build()` or temperature constructor params “for completeness.”

## Decisions log

| Question | Decision |
|---|---|
| Brainstorm focus | Lock Task 4 SiteBuilder API (not full composition redesign) |
| Temperatures | Models only on constructor; temps stay assembly-internal |
| Null slug | `freeSlug(randomSlug())` (match CLI) |
| Facade thickness | Thin: `pipeline` / `store` / `createProject` only |
| Shape | Plan-literal (Approach A); no `withDefaults`, no pipeline memoization |
