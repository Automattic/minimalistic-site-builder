# Site-Build Package — Milestone 1 (CLI + wpcom) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repackage the existing block-theme builder as an installable Composer package with PSR-4 autoloading and an injectable facade, so the same core serves the standalone CLI *and* can be vendored and driven by wpcom's `wp-content/lib/ai` framework.

**Architecture:** The current `Step` / `Project` / `Pipeline` / `ConcurrentGroup` design is already the right shape — steps read/write the `Project` directory (disk is the source of truth between steps), each step is individually runnable and idempotent, the `Llm` transport is already a constructor-injected interface, and concurrency is expressed as `ConcurrentGroup` (one batched `completeJsonBatch`). This milestone does **not** restructure any of that. It (1) adds Composer/PSR-4 so the code is autoloadable and vendorable, (2) decouples asset/output paths from the repo root so a vendored copy resolves its own prompts/scripts while writing output wherever the consumer chooses, (3) extracts the one Node-shelling step behind a `BlockFixer` port, and (4) replaces the global `make_llm()`/`build_pipeline()` procedural bootstrap with a `SiteBuilder` facade that a consumer constructs with its own `Llm` adapter. wpcom then writes a ~30-line `Llm` adapter over its `ProviderFactory` (reference implementation included) and drives the pipeline with its ambient AI credentials.

**Tech Stack:** PHP 8.1+, Composer (PSR-4), the repo's existing zero-dependency test harness (`tests/lib.php`: `test()`, `assert_*`, `run_tests()`; run with `php tests/run.php`). Node 18+ only for the CLI's default block-fixer.

## Global Constraints

- **PHP `>=8.1`.** No other runtime Composer dependencies — the package must vendor into wpcom (`wp-content/lib/`) and Studio with a minimal footprint.
- **Package name:** `automattic/site-build`. **Root namespace:** `Automattic\SiteBuild\`. **Steps namespace:** `Automattic\SiteBuild\Steps\`.
- **Behavior-preserving.** This is a repackaging milestone: do not change prompt templates (`prompts/*.md`), step logic, model defaults, or output artifacts. The full existing test suite must stay green at every task boundary.
- **The `Llm` interface signature is frozen.** wpcom's adapter depends on it: `complete`, `completeJson`, `completeBatch`, `completeJsonBatch` keep their exact signatures. Do not add required parameters.
- **The CLI keeps working identically:** `php bin/build.php "<prompt>" [--slug=…] [--until=…] [--with-images] [--port=…] [--no-serve]` — same flags, same output.
- **Out of scope (follow-on plans):** the generalized HTTP transport for Studio's WPCOM proxy; the Studio `packages/site-build` relocation + `defineTool` hook; the multi-harness transport + resolver (`claude -p` / `codex exec` / OpenCode / pi.dev — the shell-out transports plus the env/ancestry/binary resolver that selects them; design specified in [`docs/transport-resolution.md`](../../transport-resolution.md)). This plan produces working software without them.
- **Open-source boundary:** the canonical package is public (Studio's public monorepo + a public Composer package), so it must carry **no** Automattic-internal specifics — no sandbox hosts/paths, internal P2 links, WPCOM AI-proxy endpoints/feature slugs, or `require_lib`/wpcom wiring. All wpcom-internal glue lives in a private repo — see *Repository boundaries* below.

---

## Repository boundaries (open-source hygiene)

The canonical package is open source (it lives in Studio's public monorepo and ships as a public Composer package), so wpcom-internal detail must not leak into it. The wpcom glue lives in a **private/internal repo** — recommended home: **wpcom itself**, alongside existing sandbox tooling (`bin/allow-sandbox-production-writes`, `bin/sandbox-vscode/`); a dedicated Automattic dev-tools repo is the alternative. **Confirm the target repo before implementing Tasks 5–6.**

| Artifact | Home | Why |
| --- | --- | --- |
| `src/`, `prompts/`, `tests/`, `Llm`, `SiteBuilder`, `BlockFixer`, `Package`, `NodeBlockFixer` | **Public package** | Provider-agnostic core |
| Generic "implement `Llm` for your provider" guide (README) | **Public package** | No internal detail |
| `WpcomAiLlm` adapter + `0-load.php` / `require_lib` wiring | **Internal repo** | Names wpcom-internal classes; couples to the proxy + feature slugs |
| `sandbox-sync` tool (rsync to `sandbox:~/public_html/...`) | **Internal repo** | Encodes non-public sandbox hosts/paths |
| wpcom integration doc (proxy endpoint, feature slugs, sandbox loop, P2 links) | **Internal repo** | Internal infrastructure detail |

Tasks 5 and 6 author the internal-repo artifacts; the public package's share of that work is only the generic `Llm` extension point already delivered by Task 4 (`SiteBuilder` + the interface).

---

## File Structure

**Created:**
- `composer.json` — package manifest, PSR-4 autoload.
- `src/Package.php` — resolves the package's own base dir (prompts, bundled scripts), stable when vendored.
- `src/BlockFixer.php` — port interface for block-markup repair.
- `src/NodeBlockFixer.php` — default adapter (the existing `proc_open` Node logic).
- `src/SiteBuilder.php` — consumer-facing facade: assembles the pipeline from injected dependencies; convenience `build()` / `store()` / `pipeline()`.
- `examples/WpcomAiLlm.php` — reference `Llm` adapter over wpcom `ProviderFactory` (copied into wpcom's tree in production; lives here as documented reference).
- `docs/consumers/wpcom.md` — how wpcom vendors + wires the package.
- `bin/sandbox-sync.sh` — push a dev copy of the package to a wpcom sandbox (rsync-over-SSH), for local iteration.
- `tests/unit/package_paths_test.php`, `tests/unit/block_fixer_port_test.php`, `tests/unit/site_builder_test.php`, `tests/unit/example_wpcom_adapter_test.php` — new tests.

**Modified:**
- Every `src/*.php` and `src/steps/*.php` — add `namespace`; prefix global exceptions with `\`; add `use` imports. `src/steps/` renamed to `src/Steps/` (PSR-4 case match).
- `src/bootstrap.php` — require the Composer autoloader + load env; drop the manual `require_once`/`glob` loading. Global factory helpers stay in Task 1, then move onto `SiteBuilder` in Task 4.
- `src/steps/FixBlocksStep.php` → `src/Steps/FixBlocksStep.php` — depend on the injected `BlockFixer` instead of shelling out directly.
- `bin/build.php` — construct and drive `SiteBuilder` instead of the global functions.
- `tests/lib.php` and every `tests/**/*.php` — reference namespaced classes via `use`.

---

### Task 1: Composer + PSR-4 namespacing (foundation)

This is one atomic migration: the whole source set moves into a namespace and onto the autoloader in a single task, because partially-namespaced code doesn't load. Its test cycle is the full existing suite passing under autoload.

**Files:**
- Create: `composer.json`
- Modify: all of `src/*.php`, rename `src/steps/` → `src/Steps/` (all step files), `src/bootstrap.php`, `tests/lib.php`, all `tests/**/*.php`

**Interfaces:**
- Produces: the `Automattic\SiteBuild\` namespace for every existing class (`Llm`, `Project`, `ProjectStore`, `Pipeline`, `Step`, `ConcurrentStep`, `ConcurrentGroup`, `AnthropicClient`, `Env`, `PromptRenderer`, `LlmLogger`, `ModelOption`, `BuildReport`, `ThemeValidator`, `TransientApiException`, `ImageClient`, `WpcomImageClient`, `ImagePromptComposer`) and `Automattic\SiteBuild\Steps\` for every step. Later tasks import these.

- [ ] **Step 1: Write `composer.json`**

```json
{
  "name": "automattic/site-build",
  "description": "Generate a WordPress block theme from a one-line prompt.",
  "type": "library",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.1"
  },
  "autoload": {
    "psr-4": {
      "Automattic\\SiteBuild\\": "src/"
    }
  },
  "config": {
    "sort-packages": true,
    "optimize-autoloader": true
  }
}
```

- [ ] **Step 2: Install and confirm the autoloader generates**

Run: `composer install`
Expected: creates `vendor/autoload.php`, exit 0. (Add `/vendor/` to `.gitignore` if not already ignored.)

- [ ] **Step 3: Rename the steps directory for PSR-4**

Run: `git mv src/steps src/Steps`
Expected: all step files now under `src/Steps/`. (`git mv` stages the move; it is not a commit.)

- [ ] **Step 4: Namespace every source file**

Apply this exact transformation to **every** file in `src/` and `src/Steps/`. Insert the `namespace` line immediately after `declare(strict_types=1);`.

- Files directly in `src/` get: `namespace Automattic\SiteBuild;`
- Files in `src/Steps/` get: `namespace Automattic\SiteBuild\Steps;` **plus** `use` imports for every `Automattic\SiteBuild\` class they reference.

Example — `src/Llm.php` header becomes:

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

interface Llm
```

Example — `src/Steps/SiteSpecStep.php` header becomes (it references `Llm`, `PromptRenderer`, `Project`, `ProjectStore`, `ModelOption`, and the global `\RuntimeException`):

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\ModelOption;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;

final class SiteSpecStep implements Step
```

Note `SiteSpecStep` also references `Step` — since it will move to the same `Steps` namespace, keep `Step` in the root namespace and add `use Automattic\SiteBuild\Step;`. (Decide once: `Step`, `ConcurrentStep`, `ConcurrentGroup` stay in the **root** `Automattic\SiteBuild` namespace; every step file `use`s `Step` / `ConcurrentStep`.)

- [ ] **Step 5: Fix global built-in references under the new namespaces**

Inside a namespace, unqualified `RuntimeException`, `InvalidArgumentException`, `Throwable`, `Exception` resolve to the *current* namespace and will fatal. Prefix every such reference with a leading backslash (`\RuntimeException`, `\InvalidArgumentException`, `\Throwable`) across all namespaced files. (Constants like `JSON_PRETTY_PRINT` and functions like `json_encode` fall back to global automatically — leave them.)

Run: `grep -rnE '(throw new |catch \(|instanceof )(RuntimeException|InvalidArgumentException|Throwable|Exception)' src/`
Expected after fixing: no matches without a leading `\` (i.e. `throw new \RuntimeException`).

- [ ] **Step 6: Rewrite `src/bootstrap.php` to use the autoloader**

Replace the manual `require_once` block and the `glob(... '/steps/*.php')` loop with the Composer autoloader. Keep env loading and the global factory helpers (they still delegate to namespaced classes; they get retired in Task 4). Reference namespaced classes with FQNs or `use`.

```php
<?php
declare(strict_types=1);

use Automattic\SiteBuild\Env;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

/** The model used by any LLM step that isn't given a more specific one. */
function default_llm_model(): string
{
    return Env::get('LLM_MODEL', 'claude-opus-4-8');
}

// step_models(), make_llm(), make_image_client(), build_pipeline(), repo_path()
// keep their current bodies, but reference classes via FQN, e.g.:
//   return new \Automattic\SiteBuild\AnthropicClient(apiKey: Env::require('ANTHROPIC_API_KEY'), model: default_llm_model());
// These global helpers are removed in Task 4 once SiteBuilder replaces them.
```

Keep `repo_path()`, `step_models()`, `make_llm()`, `make_image_client()`, and `build_pipeline()` in this file verbatim except for FQN-qualifying the class names — do not change their behavior in this task.

- [ ] **Step 7: Point the test harness at the autoloader**

`tests/lib.php` currently does `require_once __DIR__ . '/../src/bootstrap.php';` — that now transitively loads the autoloader, so it keeps working. In every test file under `tests/` (including `tests/FakeLlm.php`, `tests/FakeImageClient.php`, and `tests/unit/*.php`), add the `use` imports for the namespaced classes each file references. Example for `tests/FakeLlm.php`:

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\Llm;

final class FakeLlm implements Llm
```

Then update its consumers to `use Automattic\SiteBuild\Tests\FakeLlm;`.

- [ ] **Step 8: Regenerate autoload and run the full suite**

Run: `composer dump-autoload && php tests/run.php`
Expected: same pass count as before the migration, `0 failed`. Fix any remaining unqualified-class or missing-`use` fatals until green.

- [ ] **Step 9: Smoke-test the CLI still builds**

Run: `php bin/build.php "A cozy neighborhood bakery" --until=site-spec --no-serve`
Expected: exits 0, writes `projects/a-cozy-neighborhood-bakery/siteSpec.json`. (Requires `ANTHROPIC_API_KEY`; if unavailable in the execution environment, note it and rely on Step 8's suite instead.)

- [ ] **Step 10: Commit**

```bash
git add composer.json src/ tests/ bin/
git commit -m "refactor: add Composer PSR-4 autoloading and namespace the source set"
```

---

### Task 2: Package-relative assets + consumer output root

`repo_path()` assumes the code lives at a repo root. Vendored into wpcom, "repo root" is wrong. Split the two concerns: **package-owned assets** (prompts, the block-fixer script) resolve relative to the package's own location; the **output root** (where projects/themes are written) becomes a value the consumer supplies.

**Files:**
- Create: `src/Package.php`, `tests/unit/package_paths_test.php`
- Modify: `src/bootstrap.php` (CLI defaults), `bin/build.php` (uses `ProjectStore` + prompts dir — already parameterized)

**Interfaces:**
- Produces: `Automattic\SiteBuild\Package::root(): string`, `Package::promptsDir(): string`, `Package::blockFixerScript(): string`. `SiteBuilder` (Task 4) and `NodeBlockFixer` (Task 3) consume these.
- Consumes: nothing new. `ProjectStore` already takes its root as a constructor argument (`new ProjectStore(repo_path('projects'))`) and `PromptRenderer` already takes its prompts dir (`new PromptRenderer(repo_path('prompts'))`) — no change to those classes.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\Package;

test('Package::root resolves to the package base dir regardless of CWD', function () {
    $expected = dirname(__DIR__, 2); // tests/unit -> package root
    assert_eq($expected, Package::root());
    assert_eq($expected . '/prompts', Package::promptsDir());
    assert_eq($expected . '/bin/block-fixer/fix-templates.js', Package::blockFixerScript());
    assert_true(is_file(Package::promptsDir() . '/site-spec.md'), 'a known prompt exists at the resolved dir');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Automattic\SiteBuild\Package" not found`.

- [ ] **Step 3: Write `src/Package.php`**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Resolves paths to assets the package OWNS (prompt templates, bundled scripts),
 * anchored to this file's location so they resolve correctly whether the package
 * is run from its own repo or vendored inside another project. This is distinct
 * from the OUTPUT root (where generated projects are written), which the consumer
 * supplies to SiteBuilder/ProjectStore.
 */
final class Package
{
    /** Absolute path to the package root (the dir containing src/, prompts/, bin/). */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function promptsDir(): string
    {
        return self::root() . '/prompts';
    }

    public static function blockFixerScript(): string
    {
        return self::root() . '/bin/block-fixer/fix-templates.js';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: PASS.

- [ ] **Step 5: Route the CLI defaults through `Package`**

In `src/bootstrap.php`, change `repo_path('prompts')` usages to `\Automattic\SiteBuild\Package::promptsDir()` and keep `repo_path('projects')` as the CLI's default output root (the CLI's output root legitimately *is* the package repo's `projects/`). Leave `repo_path()` itself for now (removed in Task 4).

- [ ] **Step 6: Commit**

```bash
git add src/Package.php tests/unit/package_paths_test.php src/bootstrap.php
git commit -m "feat: resolve package-owned assets independent of the working directory"
```

---

### Task 3: `BlockFixer` port

`FixBlocksStep` shells directly to Node + `repo_path()`. Extract the effectful part behind a port so a consumer (wpcom, later Studio) can supply its own block-repair implementation — or reuse the bundled Node one.

**Files:**
- Create: `src/BlockFixer.php`, `src/NodeBlockFixer.php`, `tests/unit/block_fixer_port_test.php`
- Modify: `src/Steps/FixBlocksStep.php`, `tests/unit/fix_blocks_test.php` (existing — keep its `summaryLine` assertions, they move to `NodeBlockFixer`)

**Interfaces:**
- Produces: `Automattic\SiteBuild\BlockFixer::fix(string $themeDir): string` (returns a human summary line). `NodeBlockFixer implements BlockFixer`.
- Consumes: `Package::blockFixerScript()` (Task 2), `Env` for `NODE_BIN`.
- Changes: `FixBlocksStep::__construct(BlockFixer $fixer)` — new required constructor dependency. `SiteBuilder` (Task 4) injects `NodeBlockFixer` by default.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\FixBlocksStep;

test('FixBlocksStep delegates repair to the injected BlockFixer', function () {
    $fake = new class implements BlockFixer {
        /** @var string[] */
        public array $calls = [];
        public function fix(string $themeDir): string
        {
            $this->calls[] = $themeDir;
            return '[fix-templates] 0/0 file(s) re-serialized';
        }
    };

    $tmp = sys_get_temp_dir() . '/sb-' . uniqid();
    mkdir($tmp . '/theme', 0775, true);
    $project = new Project($tmp);

    (new FixBlocksStep($fake))->run($project);

    assert_eq(1, count($fake->calls), 'fix() called once');
    assert_eq($project->themePath(), $fake->calls[0], 'given the theme dir');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Automattic\SiteBuild\BlockFixer" not found`.

- [ ] **Step 3: Write the port**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Repairs WordPress block-validation issues in generated markup (attribute/order
 * mismatches that trigger "unexpected or invalid content"). Consumers may supply
 * their own implementation; the package ships NodeBlockFixer as the default.
 */
interface BlockFixer
{
    /** Re-serialize every block template under $themeDir; return a one-line summary. */
    public function fix(string $themeDir): string;
}
```

- [ ] **Step 4: Write `NodeBlockFixer` (the existing proc_open logic, returning the summary)**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Default BlockFixer: shells out to the bundled Node block-fixer
 * (bin/block-fixer/fix-templates.js), which parses each file with
 * @wordpress/blocks and re-serializes it to match WordPress save() exactly.
 */
final class NodeBlockFixer implements BlockFixer
{
    public function __construct(
        private string $script,
        private string $nodeBinary = 'node',
    ) {}

    public static function default(): self
    {
        $node = Env::get('NODE_BIN', 'node');
        return new self(Package::blockFixerScript(), $node === '' ? 'node' : $node);
    }

    public function fix(string $themeDir): string
    {
        if (!is_file($this->script)) {
            throw new \RuntimeException("block-fixer script not found: {$this->script}");
        }

        $cmd = sprintf(
            '%s %s %s',
            escapeshellarg($this->nodeBinary),
            escapeshellarg($this->script),
            escapeshellarg($themeDir)
        );

        // stderr → temp FILE, not a pipe: @wordpress/blocks can emit a large
        // volume of output, and reading stdout to EOF while the child blocks on a
        // full stderr pipe buffer would deadlock. A file sink never blocks.
        $errFile = tempnam(sys_get_temp_dir(), 'blockfixer-');
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => $errFile !== false ? ['file', $errFile, 'w'] : ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            if ($errFile !== false) {
                @unlink($errFile);
            }
            throw new \RuntimeException('Could not start block-fixer (proc_open failed)');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($proc);

        $stderr = $errFile !== false ? (string) @file_get_contents($errFile) : '';
        if ($errFile !== false) {
            @unlink($errFile);
        }

        if ($exit !== 0) {
            throw new \RuntimeException("block-fixer exited with code {$exit}\n" . trim($stderr));
        }

        return self::summaryLine($stdout) . ($stderr !== '' ? "\n\n--- stderr ---\n" . rtrim($stderr) : '');
    }

    /** The single human summary line the fixer prints last. Pure — unit-testable. */
    public static function summaryLine(string $stdout): string
    {
        foreach (array_reverse(preg_split('/\r?\n/', trim($stdout)) ?: []) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[fix-templates]')) {
                return $line;
            }
        }
        return 'block-fixer: no files changed';
    }
}
```

- [ ] **Step 5: Slim `FixBlocksStep` to a port caller**

Rewrite `src/Steps/FixBlocksStep.php` so it holds a `BlockFixer`, calls `fix()`, and writes the returned summary to the log + console (preserving today's `logs/fix-blocks.log` behavior). Remove the `nodeBinary()` helper and the `repo_path()` reference (now in `NodeBlockFixer`).

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Project;

final class FixBlocksStep implements Step
{
    private const LOG_FILE = 'fix-blocks.log';

    public function __construct(private BlockFixer $fixer) {}

    public function id(): string
    {
        return 'fix-blocks';
    }

    public function label(): string
    {
        return 'Fix block validation';
    }

    public function run(Project $project): void
    {
        $summary = $this->fixer->fix($project->themePath());
        file_put_contents($project->logPath(self::LOG_FILE), $summary . "\n");
        $firstLine = strtok($summary, "\n");
        echo '  ' . $firstLine . ' (details: logs/' . self::LOG_FILE . ")\n";
    }
}
```

- [ ] **Step 6: Move the `summaryLine` unit test to `NodeBlockFixer`**

In `tests/unit/fix_blocks_test.php`, change the class under test for the `summaryLine` cases from `FixBlocksStep::summaryLine(...)` to `\Automattic\SiteBuild\NodeBlockFixer::summaryLine(...)` (identical inputs/expectations).

- [ ] **Step 7: Run tests**

Run: `php tests/run.php`
Expected: PASS (the new delegation test + the relocated `summaryLine` cases).

- [ ] **Step 8: Commit**

```bash
git add src/BlockFixer.php src/NodeBlockFixer.php src/Steps/FixBlocksStep.php tests/unit/block_fixer_port_test.php tests/unit/fix_blocks_test.php
git commit -m "feat: extract block repair behind a BlockFixer port"
```

---

### Task 4: `SiteBuilder` facade + rewire the CLI

Replace the procedural `make_llm()` / `build_pipeline()` globals with a facade a consumer constructs with its own dependencies. This is the object wpcom instantiates.

**Files:**
- Create: `src/SiteBuilder.php`, `tests/unit/site_builder_test.php`
- Modify: `src/bootstrap.php` (retire the moved globals), `bin/build.php` (drive the facade)

**Interfaces:**
- Produces:
  - `SiteBuilder::__construct(Llm $llm, string $promptsDir, string $outputRoot, BlockFixer $blockFixer, array $models = [])`
  - `SiteBuilder::pipeline(): Pipeline`
  - `SiteBuilder::store(): ProjectStore`
  - `SiteBuilder::createProject(string $prompt, ?string $slug = null): Project` (creates the project dir + seeds `meta.json`)
- Consumes: `Package::promptsDir()`, `NodeBlockFixer::default()`, the existing `ProjectStore`, `PromptRenderer`, `Pipeline`, and every step class.

- [ ] **Step 1: Write the failing test (end-to-end build through the facade with FakeLlm)**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\SiteBuilder;

test('SiteBuilder assembles a pipeline and builds through injected deps', function () {
    $llm = new FakeLlm();
    // Queue the JSON/text responses the pipeline's LLM steps consume, in order.
    // (Fill from the existing integration test's canned responses.)
    $llm->queueJson(['name' => 'Test Cafe', 'slug' => 'test-cafe', 'sections' => []]);
    // ... queue the remaining step responses per tests/integration/pipeline_test.php

    $noopFixer = new class implements BlockFixer {
        public function fix(string $themeDir): string { return '[fix-templates] noop'; }
    };

    $out = sys_get_temp_dir() . '/sb-build-' . uniqid();
    $builder = new SiteBuilder(
        llm: $llm,
        promptsDir: Package::promptsDir(),
        outputRoot: $out,
        blockFixer: $noopFixer,
    );

    $ids = $builder->pipeline()->stepIds();
    assert_true(in_array('site-spec', $builder->pipeline()->stopIds(), true), 'exposes stop ids');

    $project = $builder->createProject('a test cafe', 'test-cafe');
    $builder->pipeline()->runThrough($project, 'site-spec');
    assert_true($project->exists('siteSpec.json'), 'ran the first LLM step to disk');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Automattic\SiteBuild\SiteBuilder" not found`.

- [ ] **Step 3: Write `src/SiteBuilder.php`**

Move the **exact** step assembly currently in `build_pipeline()` (in `src/bootstrap.php`) into `SiteBuilder::pipeline()`, applying these substitutions verbatim:
- `$llm` → `$this->llm`
- `$renderer` (was `new PromptRenderer(repo_path('prompts'))`) → `new PromptRenderer($this->promptsDir)`
- `$models` (was `step_models()`) → `$this->models` (falling back to `step_models()` defaults when a key is absent)
- the `FixBlocksStep` construction → `new FixBlocksStep($this->blockFixer)`
- `ProjectStore` root → `$this->outputRoot`

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\ScaffoldThemeStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
// ... use the remaining step classes referenced by the relocated build_pipeline() body

/**
 * Consumer-facing entry point. Construct it with an Llm transport, a prompts dir,
 * an output root, and a BlockFixer; then run the whole pipeline or individual
 * steps. This replaces the procedural make_llm()/build_pipeline() bootstrap so
 * embedding hosts (wpcom, Studio) inject their own dependencies.
 *
 * @param array<string,string> $models step id => model id override
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
        $models = $this->models;
        // <<< relocated verbatim from build_pipeline(), with the substitutions above >>>
        return new Pipeline([
            new ScaffoldThemeStep(),
            new SiteSpecStep($this->llm, $renderer, $models['site-spec'] ?? null),
            // ... the rest of the existing pipeline, unchanged in order/behavior
            new FixBlocksStep($this->blockFixer),
            // ...
        ]);
    }

    public function store(): ProjectStore
    {
        return new ProjectStore($this->outputRoot);
    }

    public function createProject(string $prompt, ?string $slug = null): Project
    {
        $project = $this->store()->create($slug ?? $prompt);
        $project->writeJson('meta.json', [
            'prompt'           => $prompt,
            'provisional_slug' => $project->slug(),
            'created_at'       => gmdate('c'),
        ]);
        return $project;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: PASS. Fill the queued `FakeLlm` responses from `tests/integration/pipeline_test.php` until the `--until=site-spec` slice is green.

- [ ] **Step 5: Rewire `bin/build.php` onto the facade**

Replace the `make_llm()` + `build_pipeline($llm)` + inline `ProjectStore`/`meta.json` seeding with:

```php
$llm = make_llm(); // stays in bootstrap.php: reads ANTHROPIC_API_KEY, returns AnthropicClient
$builder = new \Automattic\SiteBuild\SiteBuilder(
    llm: $llm,
    promptsDir: \Automattic\SiteBuild\Package::promptsDir(),
    outputRoot: repo_path('projects'),
    blockFixer: \Automattic\SiteBuild\NodeBlockFixer::default(),
    models: step_models(),
);
$pipeline = $builder->pipeline();
// ... existing --until validation against $pipeline->stopIds() ...
$project = $builder->createProject($prompt, $slug);
// ... existing runThrough($project, $until, reporter) + images + serve, unchanged ...
```

Keep `make_llm()`, `step_models()`, `default_llm_model()`, `repo_path()`, and `make_image_client()` in `src/bootstrap.php` (the CLI still uses them). **Remove** `build_pipeline()` — it now lives in `SiteBuilder::pipeline()`.

- [ ] **Step 6: Run the suite + CLI smoke test**

Run: `php tests/run.php`
Expected: `0 failed`.
Run: `php bin/build.php "A cozy neighborhood bakery" --until=site-spec --no-serve`
Expected: exits 0, writes `siteSpec.json` (with a live key; otherwise rely on the suite).

- [ ] **Step 7: Commit**

```bash
git add src/SiteBuilder.php tests/unit/site_builder_test.php src/bootstrap.php bin/build.php
git commit -m "feat: add SiteBuilder facade and drive the CLI through it"
```

---

### Prerequisite Task — Extract the fan-out units as stateless `(inputs, Llm) → output` cores

**Position:** after Task 4, before any wpcom-orchestrator work. This is the hinge that lets the library `Pipeline` **and** the wpcom workflow-ability drive **one** core instead of drifting into two copies.

**Why:** wpcom's native orchestrator runs each concurrent unit as a fresh, **stateless HTTP promise** (`execute_promises` → `wpcom/promise-all`, `curl_multi`, cap 25), so a fan-out unit **cannot** read the shared `Project`. Today `SectionsStep::run(Project): void` reads `siteSpec.json` / `theme/theme.json` / `sections.json` and writes `theme/parts/*.html` — a `Project`-mutating procedure. But its `requests()` already renders **self-contained** prompts (each bakes in the site spec, theme.json, outline, design direction, and one section's brief) and `markup()` is already pure — so the per-unit work is *nearly* stateless already. This task finishes the extraction.

**Files:**
- Create: `src/Units/SectionUnit.php`, `tests/unit/section_unit_test.php`
- Modify: `src/Steps/SectionsStep.php` (becomes a thin `Project` adapter over the unit)

**Interfaces:**
- Produces: `Automattic\SiteBuild\Units\SectionUnit::generate(array $input): string` — pure of the `Project`/disk: renders `section.md`, calls `$llm->complete(...)` for one prompt, validates via the existing `markup()`/fence-strip logic, returns block markup. `$input` carries exactly what `SectionsStep::requests()` already bakes into each prompt (site spec, theme.json, outline, design direction, the section brief).
- Consumes: `Llm`, `PromptRenderer`.

- [ ] **Step 1: Write the failing test** — `SectionUnit::generate($input)` with a `FakeLlm` returns validated markup from a self-contained `$input`, touching no `Project`.
- [ ] **Step 2: Run** — verify it fails (`SectionUnit` missing).
- [ ] **Step 3: Implement `SectionUnit`** by moving the per-section prompt render + `markup()`/`stripFences()` validation out of `SectionsStep` into `generate()`.
- [ ] **Step 4: Run** — verify pass.
- [ ] **Step 5: Rewrite `SectionsStep`** so `requests()`/`run()` read the artifacts from `Project`, build the N `SectionUnit` inputs, run them (via the `Llm` batch on the in-process path), and write `theme/parts/*.html`. Behavior unchanged; the per-unit logic now lives in `SectionUnit`. Keep `tests/unit/sections_test.php` green.
- [ ] **Step 6: Run the full suite** — `php tests/run.php`, `0 failed`.
- [ ] **Step 7: Commit.**

```bash
git add src/Units/SectionUnit.php src/Steps/SectionsStep.php tests/unit/section_unit_test.php
git commit -m "refactor: extract SectionUnit as a stateless (inputs, Llm) -> output core"
```

**wpcom tie-in:** the same `SectionUnit::generate($input)` becomes the body of the wpcom `big-sky/generate-section` ability — the ability receives `$input` as its HTTP arguments (or fetches a persisted spec by id, à la site-builder's `spec_id`) and returns the markup. That's how a promise stays stateless while reusing the one core. The pattern generalizes to the serial steps too (each has a pure core plus a `Project` wrapper), but the **fan-out units are the ones that must be stateless**, so start there.

> **Architecture note:** this task, plus the two-orchestrator model (library `Pipeline` + a wpcom workflow-ability registered with WP Orchestrator via `execute_promises`/SSE), supersedes the "wpcom runs our `Pipeline` sequentially via `WpcomAiLlm`" framing for the **native** wpcom path. Task 5's `require_lib` + sequential adapter remains valid as the *simple* wpcom path; the workflow-ability orchestrator is a companion spec still to be written.

---

### Task 5: wpcom integration (adapter + docs) — **authored in the internal repo**

Per *Repository boundaries*, everything in this task lives in the **private/internal repo**, not the public package: the `WpcomAiLlm` adapter (the frozen `Llm` implemented over wpcom's `ProviderFactory`; batch methods loop sequentially — wpcom's provider layer has no concurrent completion API), the `0-load.php` / `require_lib` wiring, and the wpcom integration doc (proxy endpoint, feature slugs). The **public** package's only contribution here is a generic "implement `Llm` for your provider" note in its README — the clean extension point already delivered by Task 4. The code below is the reference to place in the internal repo (paths shown relative to that repo's chosen location for the lib, e.g. `wp-content/lib/a8c/site-build/`).

**Files:**
- Create: `examples/WpcomAiLlm.php`, `docs/consumers/wpcom.md`, `tests/unit/example_wpcom_adapter_test.php`

**Interfaces:**
- Consumes: the frozen `Automattic\SiteBuild\Llm` interface.
- Produces: a reference `Llm` implementation (not autoloaded into the library's runtime path; it references wpcom-only classes, so it lives under `examples/` and is documented, not wired in).

- [ ] **Step 1: Write the reference adapter**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Examples;

use Automattic\SiteBuild\Llm;

/**
 * REFERENCE adapter — copy into wpcom (e.g. wp-content/lib/site-build/adapter/).
 * Bridges the site-build Llm interface to wpcom's lib/ai ProviderFactory. Auth is
 * ambient (server token + internal proxy); the caller passes only a feature slug.
 *
 * Batch methods loop sequentially: wpcom's provider layer exposes no concurrent
 * completion API. (A curl_multi fan-out against the proxy HTTP endpoint is a later
 * optimization; correctness first.)
 */
final class WpcomAiLlm implements Llm
{
    public function __construct(
        private string $feature = 'block-theme-generator',
        private string $defaultModel = 'claude-sonnet-4-6',
    ) {}

    /** @param array{system?:string,model?:string,max_tokens?:int} $opts */
    public function complete(string $prompt, array $opts = []): string
    {
        $model = $opts['model'] ?? $this->defaultModel;
        $provider = \WPCOM\AI\Provider\ProviderFactory::create($model, $this->feature);
        $resp = $provider->request_chat_completion(
            $this->messages($prompt, $opts),
            $opts['max_tokens'] ?? null,
            $model,
        );
        if ($resp instanceof \WP_Error) {
            throw new \RuntimeException('wpcom AI error: ' . $resp->get_error_message());
        }
        return (string) ($resp->choices[0]->message->content ?? '');
    }

    /** @return array<mixed> */
    public function completeJson(string $prompt, array $opts = []): array
    {
        // Match the package's own AnthropicClient::completeJson: instruct JSON-only
        // and decode, rather than wpcom's schema-required request_structured_output.
        $opts['system'] = ($opts['system'] ?? '')
            . "\nRespond with a single valid JSON value and nothing else. No prose, no markdown fences.";
        $text = $this->complete($prompt, $opts);
        $data = json_decode($this->stripFences($text), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Expected JSON, got: {$text}");
        }
        return $data;
    }

    /**
     * @param array<string,array{prompt:string,system?:string,model?:string,max_tokens?:int}> $requests
     * @return array<string,string>
     */
    public function completeBatch(array $requests): array
    {
        $out = [];
        foreach ($requests as $key => $req) {
            $out[$key] = $this->complete((string) $req['prompt'], $this->optsFrom($req));
        }
        return $out;
    }

    /**
     * @param array<string,array{prompt:string,system?:string,model?:string,max_tokens?:int}> $requests
     * @return array<string,array<mixed>>
     */
    public function completeJsonBatch(array $requests): array
    {
        $out = [];
        foreach ($requests as $key => $req) {
            $out[$key] = $this->completeJson((string) $req['prompt'], $this->optsFrom($req));
        }
        return $out;
    }

    /** @param array<mixed> $opts @return list<array{role:string,content:string}> */
    private function messages(string $prompt, array $opts): array
    {
        $messages = [];
        if (!empty($opts['system'])) {
            $messages[] = ['role' => 'system', 'content' => (string) $opts['system']];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];
        return $messages;
    }

    /** @param array<mixed> $req @return array<mixed> */
    private function optsFrom(array $req): array
    {
        unset($req['prompt']);
        return $req;
    }

    private function stripFences(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = (string) preg_replace('/\n```$/', '', $text);
        }
        return trim($text);
    }
}
```

- [ ] **Step 2: Write a test that the reference adapter satisfies the interface**

Because it references wpcom-only classes (`WPCOM\AI\...`, `WP_Error`), the test asserts the *shape* without executing a call: it reflects that the class implements `Llm` and declares all four methods.

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\Llm;

test('WpcomAiLlm reference implements the frozen Llm contract', function () {
    require_once dirname(__DIR__, 2) . '/examples/WpcomAiLlm.php';
    $rc = new \ReflectionClass(\Automattic\SiteBuild\Examples\WpcomAiLlm::class);
    assert_true($rc->implementsInterface(Llm::class), 'implements Llm');
    foreach (['complete', 'completeJson', 'completeBatch', 'completeJsonBatch'] as $m) {
        assert_true($rc->hasMethod($m), "declares {$m}()");
    }
});
```

- [ ] **Step 3: Run tests**

Run: `php tests/run.php`
Expected: PASS. (Reflection loads the class without invoking wpcom classes; no wpcom runtime required.)

- [ ] **Step 4: Write `docs/consumers/wpcom.md`**

Document the consumption path, exactly (verified against wpcom's dependency conventions — there is **no** central `composer require`/registry; first-party packages are committed in-tree under `wp-content/lib/` with a committed `vendor/`, loaded via `require_lib()`, following the `wp-content/lib/a8c/customer360/` precedent):

1. **Vendor the built package in-tree** at `wp-content/lib/a8c/site-build/` — the package source **plus its committed `vendor/` and `composer.lock`** (wpcom commits `vendor/`; it is not built at deploy). Source it from the canonical package in Studio's monorepo via the sync in Task 6; there is no registry pull.
2. **Add `wp-content/lib/a8c/site-build/0-load.php`**, mirroring `a8c/customer360`'s `0-load.php`:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
```

The package autoloads its own classes via its committed Composer PSR-4 autoloader, so **no `wp-content/lib/wpcom-classmap.php` entries are required** (the classmap is only for classes reached cross-tree without `require_lib`).
3. **Load it** with `require_lib( 'a8c/site-build' )` (`wp-content/mu-plugins/0-lib.php` resolves the slug to `lib/a8c/site-build/0-load.php`).
4. **Copy `examples/WpcomAiLlm.php`** into wpcom's tree (e.g. `wp-content/lib/a8c/site-build/adapter/`), adjusting the namespace to wpcom's convention.
   > **Deploy-safety (wpcom deploys are non-atomic):** if you ever add `wpcom-classmap.php` entries, land the entry and its first caller in **separate deploys** (`wp-content/lib/wpcom-classmap.php:18-23`). Loading through `require_lib` + the package's own autoloader (above) sidesteps this entirely.
5. Drive a build:

```php
require_lib( 'a8c/site-build' );
$builder = new \Automattic\SiteBuild\SiteBuilder(
    llm:        new \WPCOM\SiteBuild\WpcomAiLlm(feature: 'block-theme-generator'),
    promptsDir: \Automattic\SiteBuild\Package::promptsDir(),
    outputRoot: $themes_output_dir,           // wherever wpcom wants the theme written
    blockFixer: /* wpcom BlockFixer, or NodeBlockFixer::default() if node is available */,
    models:     ['sections' => 'claude-opus-4-8'],
);
$project = $builder->createProject($user_prompt);
$builder->pipeline()->runThrough($project);   // or ->runThrough($project, 'theme-json') for a partial flow
```

Note the two design facts wpcom needs: completions run **sequentially** through the adapter (`ConcurrentGroup` still calls `completeJsonBatch`, which loops), and wpcom must supply a `BlockFixer` (or ensure Node + the bundled script are reachable) since block repair is a Node step.

- [ ] **Step 5: Commit**

```bash
git add examples/WpcomAiLlm.php docs/consumers/wpcom.md tests/unit/example_wpcom_adapter_test.php
git commit -m "docs: add wpcom Llm adapter reference and integration guide"
```

---

### Task 6: wpcom sandbox dev-deploy workflow

**Files:**
- Create: `bin/sandbox-sync.sh`
- Modify: `docs/consumers/wpcom.md` (add a "Local dev on a wpcom sandbox" section)

**Interfaces:**
- Produces: `bin/sandbox-sync.sh [--watch] [--dest=<ssh:path>]` — regenerate the Composer autoloader, then rsync the vendorable file set to a wpcom sandbox's `wp-content/lib/a8c/site-build/`.
- Consumes: an SSH host for the developer's sandbox (default alias `sandbox`); `composer` for the pre-sync autoloader build.

**Verified background (do not re-derive):** A wpcom sandbox is a **live checkout of the wpcom repo** at `~/public_html/` on the sandbox host, edited over Remote-SSH as user `wpdev`, running against production data and **write-limited by default** (toggle via `bin/allow-sandbox-production-writes`). wpcom has **no** central `composer require`/registry and **no** installed-vs-dev indirection (unlike Jetpack's `sun`/`moon`/`dev` + `JETPACK_AUTOLOAD_DEV`) — the checkout *is* the deployed code. Two dev loops follow:

- **In-wpcom loop (no script):** once the package is vendored + committed into wpcom (Task 5), iterate on the sandbox with `gh pr checkout <branch>` or by editing `wp-content/lib/a8c/site-build/` in place over SSH; re-run `composer dump-autoload` in that dir only if the autoloader map changes.
- **Cross-repo loop (this task's script):** the **canonical** package lives in Studio's monorepo, not in wpcom — so to test wpcom integration *before* committing a vendored copy, push your working copy into a sandbox's lib path. `bin/sandbox-sync.sh` does this, replicating Jetpack's reusable rsync-over-SSH primitive (not `jetpack rsync` itself, which is hard-locked to `projects/plugins/<plugin>/` and its custom autoloader).

- [ ] **Step 1: Write `bin/sandbox-sync.sh`**

```bash
#!/usr/bin/env bash
# Push a DEV copy of this package into a wpcom sandbox's wp-content/lib for
# iteration BEFORE vendoring/committing it into wpcom. Replicates Jetpack's
# rsync-over-SSH primitive (minus its plugin/autoloader specifics).
#
# Prereqs:
#   - SSH access to your sandbox (default host alias `sandbox`, user wpdev) in ~/.ssh/config.
#   - Real rsync on macOS: `brew install rsync` (openrsync mishandles symlinks).
#   - Sandbox writes may be limited by default — see `bin/allow-sandbox-production-writes` in wpcom.
#
# Usage:
#   bin/sandbox-sync.sh
#   bin/sandbox-sync.sh --dest=sandbox:~/public_html/wp-content/lib/a8c/site-build/
#   bin/sandbox-sync.sh --watch     # continuous re-sync on change (requires fswatch)
set -euo pipefail

DEST="sandbox:~/public_html/wp-content/lib/a8c/site-build/"
WATCH=0
for arg in "$@"; do
  case "$arg" in
    --dest=*) DEST="${arg#--dest=}" ;;
    --watch)  WATCH=1 ;;
    *) echo "Unknown arg: $arg" >&2; exit 1 ;;
  esac
done

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Regenerate the committed autoloader so vendor/autoload.php is present on the
# sandbox (the package autoloads via Composer PSR-4). rsync only ships disk state.
composer install --no-dev --optimize-autoloader

sync() {
  rsync -azKP --delete --copy-links \
    --exclude '.git' \
    --exclude 'node_modules' \
    --exclude 'tests' \
    --exclude 'docs' \
    --exclude 'projects' \
    --exclude '.env' \
    ./ "$DEST"
}

sync
echo "Synced dev copy → $DEST"

if [[ "$WATCH" == "1" ]]; then
  command -v fswatch >/dev/null || { echo "fswatch not found (brew install fswatch)"; exit 1; }
  echo "Watching for changes… (Ctrl-C to stop)"
  fswatch -o src prompts bin composer.json | while read -r _; do sync; echo "re-synced $(date +%T)"; done
fi
```

- [ ] **Step 2: Make it executable**

Run: `chmod +x bin/sandbox-sync.sh`

- [ ] **Step 3: Dry-run the file set (no sandbox needed)**

Run: `composer install --no-dev --optimize-autoloader && rsync -azKPn --delete --exclude '.git' --exclude node_modules --exclude tests --exclude docs --exclude projects --exclude .env ./ /tmp/site-build-dryrun/`
Expected: the printed set includes `src/`, `prompts/`, `bin/`, `composer.json`, and `vendor/`, and excludes `tests/`, `projects/`, `docs/`, `.git/`. Confirms the include/exclude set before touching a sandbox.

- [ ] **Step 4: Document the sandbox loop in `docs/consumers/wpcom.md`**

Add a "Local dev on a wpcom sandbox" section capturing: the two loops above; that the sandbox checkout *is* the deployed copy (no override indirection); `bin/sandbox-sync.sh` for the cross-repo loop; the `composer dump-autoload` note when the autoloader map changes; and the prereqs (sandbox SSH as `wpdev`, real rsync on macOS, writes limited by default). Point at the internal sandbox-provisioning P2 for host/alias setup.

- [ ] **Step 5: Commit**

```bash
git add bin/sandbox-sync.sh docs/consumers/wpcom.md
git commit -m "feat: add wpcom sandbox dev-sync for cross-repo iteration"
```

---

## Self-Review

**Spec coverage (milestone 1 = CLI + wpcom):**
- Composer package wpcom can vendor + autoload → Task 1. ✓
- Package resolves its own assets when vendored, writes output where the consumer chooses → Task 2. ✓
- Effectful Node step swappable by a consumer → Task 3 (`BlockFixer` port). ✓
- Consumer constructs the pipeline with its own `Llm` (wpcom's ambient AI) and runs whole-flow or individual steps → Task 4 (`SiteBuilder`) + wpcom's use of `pipeline()->runThrough(..., $untilId)`. ✓
- CLI mode (direct API) unchanged → Task 4 Step 5 keeps `make_llm()` + `AnthropicClient`. ✓
- Fan-out units are stateless `(inputs, Llm) → output` cores — the hinge that lets the library `Pipeline` and the wpcom workflow-ability share one core → Prerequisite Task. ✓
- wpcom adapter reference (frozen `Llm` over `ProviderFactory`, sequential batch) → Task 5. ✓
- wpcom consumption verified as **committed-vendor `require_lib`**, not registry `composer require` (no central composer.json; `vendor/` committed; `a8c/customer360` precedent) → Task 5 (`0-load.php` + committed `vendor/`). ✓
- wpcom local-dev loop (sandbox = live checkout; cross-repo rsync from the canonical Studio-monorepo copy) → Task 6, replicating Jetpack's rsync-over-SSH primitive. ✓
- Not covered by *implementation* (deferred to follow-on plans): Studio HTTP transport, Studio relocation/`defineTool`, multi-harness transport. The multi-harness **resolution is now designed** in [`docs/transport-resolution.md`](../../transport-resolution.md) (env/ancestry/binary → a declared `Llm`; MCP-sampling evaluated and rejected); only its implementation is deferred.

**Placeholder scan:** the only intentionally non-verbatim spot is Task 4 Step 3's relocation of the existing `build_pipeline()` body — that is a precise "move this existing, working code with these substitutions," not a TODO. The implementer has the source in front of them.

**Type consistency:** `BlockFixer::fix(string): string`, `FixBlocksStep::__construct(BlockFixer)`, `SiteBuilder::__construct(Llm, string, string, BlockFixer, array)`, `Package::promptsDir()/blockFixerScript()`, `NodeBlockFixer::default()` are used identically wherever referenced. `Llm`'s four method signatures are unchanged from the current interface.

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-07-01-site-build-package-milestone-1.md`.** No commits or code changes have been made — this is the plan document only. Two execution options:

1. **Subagent-Driven (recommended)** — a fresh subagent per task, two-stage review between tasks, fast iteration.
2. **Inline Execution** — execute the tasks in this session with checkpoints for review.

Which approach — and shall I proceed (this is where the first code changes and commits would begin, on your go-ahead)?
