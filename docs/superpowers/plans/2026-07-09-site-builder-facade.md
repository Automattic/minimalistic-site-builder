# SiteBuilder Facade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `build_pipeline()` and host-side project seeding with an injectable `SiteBuilder` facade so the CLI and any embedding host construct one object with their own `Llm` and run the default pipeline (full or partial).

**Architecture:** Thin facade only: constructor injects `Llm`, prompts dir, output root, `BlockFixer`, and optional model overrides; `pipeline()` relocates today's step assembly; `store()` / `createProject()` own project dirs + `meta.json` seeding. Reporting, images, and Playground stay in the CLI. No `build()` method. No host-composed graphs.

**Tech Stack:** PHP 8.1+, existing zero-dependency harness (`php tests/run.php`), dependency-free PSR-4 autoload (`autoload.php`) + package namespaces from M1 Tasks 1–3.

**Spec:** [`docs/superpowers/specs/2026-07-09-site-builder-facade-design.md`](../specs/2026-07-09-site-builder-facade-design.md)

---

## Prerequisites (hard gate)

This plan **starts only after** Milestone 1 Tasks 1–3 are done. As of plan authoring the repo still lacks them (`autoload.php`, `Package`, `BlockFixer` / `NodeBlockFixer`, namespaces).

| Required | Source |
|---|---|
| Dependency-free PSR-4 `Automattic\SiteBuild\` via `autoload.php` | [M1 plan Task 1](2026-07-01-site-build-package-milestone-1.md) (no Composer) |
| `Package::root()` / `promptsDir()` / `blockFixerScript()` | M1 plan Task 2 |
| `BlockFixer`, `NodeBlockFixer`, `FixBlocksStep(BlockFixer)` | M1 plan Task 3 |

**Before any Task below:**

```bash
test -f autoload.php && test -f src/Package.php && test -f src/BlockFixer.php && test -f src/NodeBlockFixer.php && test -d src/Steps && php tests/run.php
```

Expected: suite green, classes under `Automattic\SiteBuild\`. If missing, **stop** and execute M1 Tasks 1–3 first (keep the post-fix `constrainedPart` repair on header/footer inside `FixBlocksStep` when extracting `NodeBlockFixer` — do not drop it).

---

## File structure

**Created:**

| File | Responsibility |
|---|---|
| `src/SiteBuilder.php` | Facade: ctor deps, `pipeline()`, `store()`, `createProject()` |
| `tests/unit/site_builder_test.php` | Facade unit tests (FakeLlm, noop BlockFixer, temp output) |

**Modified:**

| File | Change |
|---|---|
| `src/bootstrap.php` | Remove `build_pipeline()`; keep CLI helpers |
| `bin/build.php` | Construct `SiteBuilder`; use `createProject` + `pipeline()` |
| `bin/create.php` | Same |
| `bin/eval.php` | Same |
| `tests/integration/pipeline_test.php` | Call `SiteBuilder` instead of `build_pipeline` |
| Any other `build_pipeline` callers (grep at implement time) | Same |

**Not modified by this plan:** prompts, step logic (except already-done FixBlocks injection), `make_llm()`, host-specific `Llm` adapters.

---

### Task 1: Failing unit tests for SiteBuilder

**Files:**
- Create: `tests/unit/site_builder_test.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\SiteBuilder;

/**
 * Minimal SiteBuilder for tests: real prompts, temp output, noop fixer.
 *
 * @param array<string,string> $models
 */
function make_test_builder(
    FakeLlm $llm,
    string $outputRoot,
    ?BlockFixer $fixer = null,
    array $models = [],
): SiteBuilder {
    $fixer ??= new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return '[fix-templates] noop';
        }
    };

    return new SiteBuilder(
        llm: $llm,
        promptsDir: Package::promptsDir(),
        outputRoot: $outputRoot,
        blockFixer: $fixer,
        models: $models,
    );
}

test('SiteBuilder::pipeline exposes the default step order and stop ids', function () {
    $out = sys_get_temp_dir() . '/sb-facade-' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $out);

    assert_eq([
        'scaffold-theme', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
        'theme-json+section-plan', 'sections', 'assemble-landing-page',
        'collect-images', 'fix-blocks', 'page-styles', 'fonts-php', 'finalize-theme',
    ], $builder->pipeline()->stepIds());

    assert_true(in_array('site-spec', $builder->pipeline()->stopIds(), true));
    assert_true(in_array('theme-json', $builder->pipeline()->stopIds(), true), 'group member is a valid stop');
});

test('SiteBuilder::createProject uses a free random slug when slug is null', function () {
    $out = sys_get_temp_dir() . '/sb-facade-' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $out);

    $project = $builder->createProject('a test cafe');
    $meta = $project->readJson('meta.json');

    assert_eq('a test cafe', $meta['prompt']);
    assert_eq($project->slug(), $meta['provisional_slug']);
    assert_true(isset($meta['created_at']) && $meta['created_at'] !== '');
    // Random slugs are adjective-noun, not a slugify of the full prompt.
    assert_true($project->slug() !== 'a-test-cafe', 'must not slugify the prompt');
    assert_true(is_dir($project->path()));
});

test('SiteBuilder::createProject respects an explicit slug and merges meta', function () {
    $out = sys_get_temp_dir() . '/sb-facade-' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $out);

    $store = $builder->store();
    $pre = $store->create('fixed-slug');
    $pre->writeJson('meta.json', ['demo_source' => 'unit-test']);

    $project = $builder->createProject('prompt text', 'fixed-slug');
    $meta = $project->readJson('meta.json');

    assert_eq('fixed-slug', $project->slug());
    assert_eq('prompt text', $meta['prompt']);
    assert_eq('unit-test', $meta['demo_source'], 'pre-seeded meta must survive merge');
    assert_eq('fixed-slug', $meta['provisional_slug']);
});

test('SiteBuilder runs through site-spec via injected FakeLlm', function () {
    $llm = new FakeLlm();
    // refine-prompt (text), then site-spec (json) — same order as integration harness
    $llm->queueText('A cozy neighborhood bakery selling artisan bread and pastries.');
    $llm->queueJson([
        'name' => 'Test Cafe', 'slug' => 'test-cafe',
        'title' => 'Test Cafe', 'description' => 'A test cafe',
        'site_type' => 'cafe', 'topic' => 'coffee', 'area' => 'cafe',
        'audience' => 'locals', 'visual_vibe' => 'warm',
        'language' => 'en', 'persona_name' => '',
        'email_domain' => 'testcafe.example', 'invented' => ['name', 'email_domain'],
        'sections' => ['Hero', 'About'],
    ]);

    $out = sys_get_temp_dir() . '/sb-facade-' . uniqid();
    $builder = make_test_builder($llm, $out);

    $project = $builder->createProject('a test cafe', 'test-cafe');
    $builder->pipeline()->runThrough($project, 'site-spec');

    assert_true($project->exists('siteSpec.json'), 'ran through site-spec to disk');
    assert_eq('Test Cafe', $project->readJson('siteSpec.json')['name']);
    assert_true($project->exists('theme/style.css'), 'scaffold-theme ran first');
});

test('SiteBuilder accepts partial model overrides without fatalling', function () {
    $out = sys_get_temp_dir() . '/sb-facade-' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $out, models: ['sections' => 'claude-haiku-4-5']);
    assert_true(in_array('sections', $builder->pipeline()->stepIds(), true));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL — `Class "Automattic\SiteBuild\SiteBuilder" not found` (or autoload miss).

- [ ] **Step 3: Commit the failing tests**

```bash
git add tests/unit/site_builder_test.php
git commit -m "test: add failing SiteBuilder facade unit tests"
```

---

### Task 2: Implement `SiteBuilder`

**Files:**
- Create: `src/SiteBuilder.php`
- Modify: none yet (callers stay on `build_pipeline` until Task 3)

- [ ] **Step 1: Write `src/SiteBuilder.php`**

Copy the step list and comments from the current `build_pipeline()` body in `src/bootstrap.php`. Apply only these substitutions:

| From `build_pipeline` | In `SiteBuilder::pipeline()` |
|---|---|
| `$llm` | `$this->llm` |
| `new PromptRenderer(repo_path('prompts'))` | `new PromptRenderer($this->promptsDir)` |
| `$models = step_models()` | `$models = array_merge(\step_models(), $this->models)` |
| `$temps = step_temperatures()` | `$temps = \step_temperatures()` |
| `new FixBlocksStep()` | `new FixBlocksStep($this->blockFixer)` |

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\AssembleLandingPageStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Steps\FontsPhpStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Steps\RefinePromptStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;
use Automattic\SiteBuild\Steps\SectionPlanStep;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;

/**
 * Consumer-facing entry point for the default site-creation pipeline.
 * Hosts inject Llm + paths + BlockFixer; then createProject + pipeline()->runThrough.
 *
 * @param array<string,string> $models step id => model id overrides (merged over package defaults)
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

    public function pipeline(): Pipeline
    {
        $renderer = new PromptRenderer($this->promptsDir);
        // Consumer overrides win; missing keys keep package / env defaults.
        $models = array_merge(\step_models(), $this->models);
        $temps = \step_temperatures();

        return new Pipeline([
            new ScaffoldThemeStep(),
            // Cheap, fast first pass on a small model: expand short/vague prompts and
            // normalize the brief before any expensive step reads it. Rewrites the
            // `prompt` in meta.json (original kept as `original_prompt`), so every
            // step below benefits with no further wiring.
            new RefinePromptStep($this->llm, $renderer, $models['refine-prompt'], $temps['refine-prompt']),
            new SiteSpecStep($this->llm, $renderer, $models['site-spec'], $temps['site-spec']),
            new ApplyIdentityStep(),
            // Commit to ONE creative concept BEFORE theme.json / the section plan, so
            // both derive from a strong, specific direction instead of converging on
            // safe defaults. Writes designDirection.json, read by the steps below.
            // Tradeoff: this is an extra serial LLM round-trip on the critical path
            // (the concurrent group now depends on its output) — a deliberate cost
            // we pay for design variety; tune via LLM_MODEL_DESIGN_DIRECTION.
            new DesignDirectionStep(
                $this->llm,
                $renderer,
                $models['design-direction'],
                $temps['design-direction'],
                $models['design-direction-seeds'],
            ),
            // theme.json and the section plan both derive from the prompt + siteSpec +
            // the design direction, so run them concurrently. Design decisions are
            // made inline, steered by designDirection.json.
            new ConcurrentGroup($this->llm, [
                new ThemeJsonStep($this->llm, $renderer, $models['theme-json'], $temps['theme-json']),
                new SectionPlanStep($this->llm, $renderer, $models['section-plan'], $temps['section-plan']),
            ]),
            // Generate the header, footer, and every section part in one concurrent
            // batch, then stitch them into the page deterministically.
            new SectionsStep($this->llm, $renderer, $models['sections'], $temps['sections']),
            new AssembleLandingPageStep(),
            // Collect image placeholders BEFORE fix-blocks: the block re-serializer
            // strips the alt from wp:cover background images (core cover save()
            // resets it to ""), which would lose every hero's AI_IMAGE spec.
            new CollectImagesStep(),
            new FixBlocksStep($this->blockFixer),
            // AFTER fix-blocks: reads the final (re-serialized) markup for which
            // layout utility classes survived, and appends their CSS to style.css —
            // a file the fixer never touches, so nothing here can be stripped.
            new PageStylesStep($this->llm, $renderer, $models['page-styles'], $temps['page-styles']),
            // Also after fix-blocks: writes fonts.php from the design direction,
            // validated against a deterministic scan of the final theme.json +
            // markup (every family/weight/italic the build uses MUST be requested;
            // scan-built fallback otherwise).
            new FontsPhpStep($this->llm, $renderer, $models['fonts-php'], $temps['fonts-php']),
            // Sole owner of functions.php: the deterministic loader that enqueues
            // style.css and require_once's the generated fonts.php.
            new FinalizeThemeStep(),
        ]);
    }

    public function store(): ProjectStore
    {
        return new ProjectStore($this->outputRoot);
    }

    public function createProject(string $prompt, ?string $slug = null): Project
    {
        $store = $this->store();
        if ($slug === null) {
            $slug = $store->freeSlug(ProjectStore::randomSlug());
        }
        $project = $store->create($slug);

        $meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
        $project->writeJson('meta.json', array_merge($meta, [
            'prompt'           => $prompt,
            'provisional_slug' => $project->slug(),
            'created_at'       => gmdate('c'),
        ]));

        return $project;
    }
}
```

Notes for the implementer:

- If `step_models` / `step_temperatures` live as namespaced static helpers after Task 1, call those instead of `\step_models()` — use whatever the green suite uses after Tasks 1–3. The merge rule is fixed: **defaults first, then `$this->models`**.
- Do **not** add `build()`, temperature ctor params, or pipeline memoization.
- `pipeline()` must return a **new** `Pipeline` each call.

- [ ] **Step 2: Regenerate autoload if needed and run unit tests**

Run: `php tests/run.php`
Expected: new `site_builder_test` cases PASS; full suite still green (callers still use `build_pipeline`).

If `createProject` random-slug assertion is flaky (astronomically unlikely collision with `a-test-cafe`), keep the assertion as written.

- [ ] **Step 3: Commit**

```bash
git add src/SiteBuilder.php tests/unit/site_builder_test.php
git commit -m "feat: add SiteBuilder facade assembling the default pipeline"
```

---

### Task 3: Rewire all `build_pipeline` callers and remove the global

**Files:**
- Modify: `bin/build.php`, `bin/create.php`, `bin/eval.php`, `tests/integration/pipeline_test.php`, `src/bootstrap.php`
- Grep: `rg -n build_pipeline` and fix any remaining hits in PHP (not docs)

- [ ] **Step 1: Confirm callers**

Run: `rg -n 'build_pipeline' --glob '*.php'`
Expected at least: `src/bootstrap.php`, `bin/build.php`, `bin/create.php`, `bin/eval.php`, `tests/integration/pipeline_test.php`.

- [ ] **Step 2: Rewire `bin/build.php`**

Replace the block that does `make_llm()` + `build_pipeline` + manual `ProjectStore` / `meta.json` / free random slug with:

```php
$llm = make_llm();
$builder = new \Automattic\SiteBuild\SiteBuilder(
    llm: $llm,
    promptsDir: \Automattic\SiteBuild\Package::promptsDir(),
    outputRoot: repo_path('projects'),
    blockFixer: \Automattic\SiteBuild\NodeBlockFixer::default(),
    models: step_models(),
);
$pipeline = $builder->pipeline();

// Validate --until BEFORE creating the project, so an unknown id fails loud
// without leaving a stray project directory behind.
if ($until !== null && !in_array($until, $pipeline->stopIds(), true)) {
    fwrite(STDERR, "Unknown --until step '{$until}'. Valid steps:\n  "
        . implode("\n  ", $pipeline->stopIds()) . "\n");
    exit(1);
}

$project = $builder->createProject($prompt, $slug);

echo "Building '{$project->slug()}'\n";
// ... rest unchanged: BuildReport, runThrough, optional images, serve ...
```

Delete the old:

```php
$store = new ProjectStore(repo_path('projects'));
$slug ??= $store->freeSlug(ProjectStore::randomSlug());
$project = $store->create($slug);
// meta write...
```

Keep reporting, `GenerateImagesStep`, playground serve, and failure handling exactly as today.

- [ ] **Step 3: Rewire `bin/create.php`**

```php
$llm = make_llm();
$builder = new \Automattic\SiteBuild\SiteBuilder(
    llm: $llm,
    promptsDir: \Automattic\SiteBuild\Package::promptsDir(),
    outputRoot: repo_path('projects'),
    blockFixer: \Automattic\SiteBuild\NodeBlockFixer::default(),
    models: step_models(),
);
$project = $builder->createProject($prompt, $slug);
$pipeline = $builder->pipeline();
$models = step_models();
// ... existing table output + runThrough + optional images + serve unchanged ...
```

Remove the manual `ProjectStore` / `writeJson('meta.json')` block at the top (now inside `createProject`). Keep `$slug` CLI parsing — pass the nullable `$slug` into `createProject` so random free slug still applies when omitted.

- [ ] **Step 4: Rewire `bin/eval.php`**

Eval uses **fixed slugs** and its own meta write. Prefer:

```php
$llm = make_llm();
$builder = new \Automattic\SiteBuild\SiteBuilder(
    llm: $llm,
    promptsDir: \Automattic\SiteBuild\Package::promptsDir(),
    outputRoot: repo_path('projects'),
    blockFixer: \Automattic\SiteBuild\NodeBlockFixer::default(),
    models: step_models(),
);

// inside the loop:
$project = $builder->createProject($prompt, $slug);
// createProject overwrites/merges prompt fields; fixed $slug is preserved
$builder->pipeline()->runThrough($project, null, function (/* ... */) { /* existing */ });
```

Remove the separate `$store = new ProjectStore(...)` if unused after the loop change. Keep `--report` path and metrics collection unchanged.

- [ ] **Step 5: Rewire `tests/integration/pipeline_test.php`**

```php
function make_integration_builder(FakeLlm $llm, string $outputRoot): \Automattic\SiteBuild\SiteBuilder
{
    // Real Node fixer so hover-lift re-serialization assertions still hold.
    return new \Automattic\SiteBuild\SiteBuilder(
        llm: $llm,
        promptsDir: \Automattic\SiteBuild\Package::promptsDir(),
        outputRoot: $outputRoot,
        blockFixer: \Automattic\SiteBuild\NodeBlockFixer::default(),
        models: [],
    );
}

// In the full-pipeline test, after creating $tmp and $llm queues:
$builder = make_integration_builder($llm, $tmp);
$project = $builder->createProject('A cozy neighborhood bakery', 'demo');
$builder->pipeline()->runThrough($project);

// In the step-order test:
$ids = make_integration_builder(new FakeLlm(), sys_get_temp_dir() . '/sb-order-' . uniqid())
    ->pipeline()
    ->stepIds();
```

Update the file header comment: "Pipeline via SiteBuilder" instead of `build_pipeline`.

- [ ] **Step 6: Remove `build_pipeline()` from `src/bootstrap.php`**

Delete the entire `function build_pipeline(Llm $llm): Pipeline { ... }` function and its docblock.

Keep: `default_llm_model()`, `step_models()`, `step_temperatures()`, `llm_temperature()`, `make_llm()`, `make_image_client()`, `repo_path()`, env load, autoload require.

- [ ] **Step 7: Grep clean**

Run: `rg -n 'build_pipeline' --glob '*.php'`
Expected: no matches (docs may still mention it).

- [ ] **Step 8: Run full suite**

Run: `php tests/run.php`
Expected: `0 failed`. Integration test requires Node for the real block-fixer (same as today).

- [ ] **Step 9: CLI smoke (optional with live key)**

Run: `php bin/build.php "A cozy neighborhood bakery" --until=site-spec --no-serve`
Expected: exit 0, `projects/<slug>/siteSpec.json` present. If no `ANTHROPIC_API_KEY`, skip and rely on the suite.

- [ ] **Step 10: Commit**

```bash
git add src/SiteBuilder.php src/bootstrap.php bin/build.php bin/create.php bin/eval.php tests/integration/pipeline_test.php tests/unit/site_builder_test.php
git commit -m "feat: drive CLI and tests through SiteBuilder; remove build_pipeline"
```

---

### Task 4: Host consumption note (no package code)

Hosts wire their own `Llm` and call the facade. No host-specific adapters or wiring land in this public package — only the generic extension point:

```php
$builder = new \Automattic\SiteBuild\SiteBuilder(
    llm: $hostLlm,
    promptsDir: \Automattic\SiteBuild\Package::promptsDir(),
    outputRoot: $outputRoot,
    blockFixer: \Automattic\SiteBuild\NodeBlockFixer::default(),
    models: [],
);
$project = $builder->createProject($prompt);
$builder->pipeline()->runThrough($project, $until);
```

---

## Self-review

**Spec coverage**

| Spec requirement | Task |
|---|---|
| Thin facade: `pipeline` / `store` / `createProject` only | Task 2 |
| Ctor: Llm, promptsDir, outputRoot, BlockFixer, models | Task 2 |
| Models merge: defaults ⊕ overrides | Task 2 |
| Temperatures assembly-internal via `step_temperatures` | Task 2 |
| `createProject` random free slug + meta merge | Task 2 + Task 1 tests |
| Relocate `build_pipeline` body; remove global | Tasks 2–3 |
| CLI rewire build/create/eval | Task 3 |
| Integration tests off `build_pipeline` | Task 3 |
| No `build()`, no injectable temps | Task 2 (explicit non-features) |
| Unit tests with FakeLlm + noop/real fixer | Task 1 |
| Host consumption sketch (generic, no host wiring) | Task 4 |
| Prerequisites Package / BlockFixer / namespaces | Hard gate |

**Placeholder scan:** no TBD/TODO steps; full class body and rewire snippets included.

**Type consistency:** `SiteBuilder::__construct(Llm, string, string, BlockFixer, array)`, `pipeline(): Pipeline`, `store(): ProjectStore`, `createProject(string, ?string): Project`, `BlockFixer::fix(string): string`, `NodeBlockFixer::default()`, `Package::promptsDir()` — same names as the design doc.

**Behavior:** Step order and comments match current `build_pipeline()`; images stay out of the pipeline; `--until` validated before `createProject` in CLI.

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-07-09-site-builder-facade.md`.**

**Prerequisite:** M1 Tasks 1–3 must be green before starting Task 1 of this plan.

**Two execution options:**

1. **Subagent-Driven (recommended)** — fresh subagent per task, two-stage review between tasks.
2. **Inline Execution** — run tasks in this session with checkpoints.

Which approach — and shall I proceed (first action is the prerequisite gate, then Task 1 failing tests)?
