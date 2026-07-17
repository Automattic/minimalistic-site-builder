# Declarative Steps + Validated Compositions (BIGR-645) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each step declares id/label/reads/writes/concurrent; assembling a step list validates read-after-write immediately; the CLI default list is built via `StepComposition`; hosts get a portable `StepGraph::describe()` export (no wpcom code in this repo).

**Architecture:** Thin core types (`StepDeclaration`, `StepGraph`, `StepComposition`). Every `Step` implements `declaration()`. `Pipeline` and `StepComposition` call `StepGraph::validate()` at assembly time (default seed `meta.json`). Default CLI graph moves from `SiteBuilder::pipeline()` into `StepComposition::default()`. Export is host-agnostic ordered arrays only.

**Tech Stack:** PHP 8.1+, dependency-free PSR-4 (`autoload.php` → `src/`), existing harness `php tests/run.php`.

**Spec:** [`docs/superpowers/specs/2026-07-16-bigr-645-declarative-steps-design.md`](../specs/2026-07-16-bigr-645-declarative-steps-design.md)

**Branch:** `bigr-645-site-builder-make-each-step-declare-what-it-reads-and-writes`

---

## File structure

**Create:**

| File | Responsibility |
|------|----------------|
| `src/StepDeclaration.php` | Immutable value object: id, label, reads, writes, concurrent |
| `src/StepGraph.php` | `validate()`, `describe()`, path coverage helpers |
| `src/StepComposition.php` | Default graph assembly + without/insertAfter/replace/withSeeds |
| `tests/unit/step_declaration_test.php` | Constructor guards |
| `tests/unit/step_graph_test.php` | Coverage, validate, describe |
| `tests/unit/step_composition_test.php` | Default order, mutations, seeds |

**Modify:**

| File | Change |
|------|--------|
| `src/Step.php` | Add `declaration(): StepDeclaration` |
| `src/ConcurrentGroup.php` | Union declaration, concurrent true |
| `src/Pipeline.php` | Validate steps in constructor |
| `src/SiteBuilder.php` | `pipeline()` via `StepComposition::default` |
| `src/Steps/*.php` (all 22 step classes) | Implement `declaration()` |
| `tests/unit/pipeline_test.php` | `RecorderStep::declaration()` |
| `tests/unit/concurrent_group_test.php` | `RecordingConcurrentStep::declaration()` |
| `tests/unit/site_builder_test.php` | Keep stepIds assertion; add describe smoke if useful |
| `docs/composition-and-extension.md` | One short note that declarative validation landed |

**Not modified:** prompts, unit generation logic, CLI drivers (except they keep working via SiteBuilder), wpcom.

**Default pipeline step order (must not change):**

```
scaffold-theme, refine-prompt, site-spec, apply-identity, design-direction,
theme-json+section-plan, sections, section-rhythm, assemble-landing-page,
collect-images, normalize-layout, contrast-fix, motion-sanity, fix-blocks,
page-styles, custom-motion, fonts-php, finalize-theme, validate-theme
```

**Steps not in the default pipeline** (still need `declaration()` because they implement `Step`): `CoverContrastStep`, `GenerateImagesStep`.

---

### Task 1: `StepDeclaration` value object

**Files:**
- Create: `src/StepDeclaration.php`
- Create: `tests/unit/step_declaration_test.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

use Automattic\SiteBuild\StepDeclaration;

test('StepDeclaration stores id label reads writes concurrent', function () {
    $d = new StepDeclaration(
        id: 'site-spec',
        label: 'Generate site spec',
        reads: ['meta.json'],
        writes: ['siteSpec.json'],
        concurrent: false,
    );
    assert_eq('site-spec', $d->id);
    assert_eq('Generate site spec', $d->label);
    assert_eq(['meta.json'], $d->reads);
    assert_eq(['siteSpec.json'], $d->writes);
    assert_eq(false, $d->concurrent);
});

test('StepDeclaration rejects empty id', function () {
    assert_throws(fn () => new StepDeclaration('', 'x', [], [], false));
});

test('StepDeclaration rejects empty path strings', function () {
    assert_throws(fn () => new StepDeclaration('a', 'A', [''], [], false));
    assert_throws(fn () => new StepDeclaration('a', 'A', [], [''], false));
});

test('StepDeclaration rejects absolute or parent paths', function () {
    assert_throws(fn () => new StepDeclaration('a', 'A', ['/tmp/x'], [], false));
    assert_throws(fn () => new StepDeclaration('a', 'A', ['foo/../bar'], [], false));
    assert_throws(fn () => new StepDeclaration('a', 'A', ['../bar'], [], false));
});

test('StepDeclaration allows directory globs', function () {
    $d = new StepDeclaration('s', 'S', ['theme/parts/*'], ['theme/*'], true);
    assert_eq(['theme/parts/*'], $d->reads);
    assert_eq(true, $d->concurrent);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php 2>&1 | tail -20`

Expected: FAIL — class `StepDeclaration` not found (or similar).

- [ ] **Step 3: Implement `StepDeclaration`**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Self-description of one Step: identity, project files read/written, and
 * whether the step fans out concurrent work. Pure data for validation and
 * host-side graph export.
 */
final class StepDeclaration
{
    /**
     * @param list<string> $reads  Project-relative paths; may end with /*
     * @param list<string> $writes Project-relative paths; may end with /*
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $reads,
        public readonly array $writes,
        public readonly bool $concurrent = false,
    ) {
        if ($this->id === '') {
            throw new \InvalidArgumentException('StepDeclaration id must be non-empty');
        }
        foreach (['reads' => $this->reads, 'writes' => $this->writes] as $kind => $paths) {
            foreach ($paths as $path) {
                if (!is_string($path) || $path === '') {
                    throw new \InvalidArgumentException("StepDeclaration {$kind} path must be a non-empty string");
                }
                if (str_starts_with($path, '/') || str_contains($path, '..')) {
                    throw new \InvalidArgumentException(
                        "StepDeclaration {$kind} path must be project-relative without '..': {$path}"
                    );
                }
            }
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php 2>&1 | rg 'step_declaration|passed|failed'`

Expected: step_declaration tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/StepDeclaration.php tests/unit/step_declaration_test.php
git commit -m "feat: add StepDeclaration value object (BIGR-645)"
```

---

### Task 2: `StepGraph` validate + describe + coverage

**Files:**
- Create: `src/StepGraph.php`
- Create: `tests/unit/step_graph_test.php`

Use a tiny fake step **inside the test file** that implements the full `Step` interface. That requires Task 3 first if `declaration()` is not on `Step` yet — **do Task 3 Steps 1–3 (interface + stubs) before running these tests**, or implement Tasks 2 and 3 in this order:

1. Task 3 partial: add `declaration()` to interface + stubs on all classes (empty reads/writes is OK for stubs).
2. Then Task 2 tests use a custom FakeStep with real reads/writes.

Recommended: complete Task 3 stub pass first, then Task 2. If you prefer isolation, keep FakeStep only in tests and complete interface change in Task 3 before Task 2 Step 2.

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\StepGraph;

/** @param list<string> $reads @param list<string> $writes */
function graph_fake_step(
    string $id,
    array $reads = [],
    array $writes = [],
    bool $concurrent = false,
): Step {
    return new class ($id, $reads, $writes, $concurrent) implements Step {
        /** @param list<string> $reads @param list<string> $writes */
        public function __construct(
            private string $id,
            private array $reads,
            private array $writes,
            private bool $concurrent,
        ) {}
        public function id(): string { return $this->id; }
        public function label(): string { return $this->id; }
        public function run(Project $project): void {}
        public function declaration(): StepDeclaration
        {
            return new StepDeclaration($this->id, $this->id, $this->reads, $this->writes, $this->concurrent);
        }
    };
}

test('StepGraph validates a legal chain with default meta.json seed', function () {
    StepGraph::validate([
        graph_fake_step('refine-prompt', ['meta.json'], ['meta.json']),
        graph_fake_step('site-spec', ['meta.json'], ['siteSpec.json']),
    ]);
    assert_true(true);
});

test('StepGraph rejects a step whose read was never written', function () {
    assert_throws(function () {
        StepGraph::validate([
            graph_fake_step('sections', ['sections.json'], ['theme/parts/*']),
        ]);
    });
});

test('StepGraph empty seeds fails refine-prompt without meta.json', function () {
    assert_throws(function () {
        StepGraph::validate(
            [graph_fake_step('refine-prompt', ['meta.json'], ['meta.json'])],
            seeds: [],
        );
    });
});

test('StepGraph directory write covers later directory and concrete reads', function () {
    StepGraph::validate([
        graph_fake_step('sections', [], ['theme/parts/*']),
        graph_fake_step('rhythm', ['theme/parts/*'], ['theme/parts/*']),
        graph_fake_step('header-reader', ['theme/parts/header.html'], []),
    ], seeds: []);
    assert_true(true);
});

test('StepGraph concrete write under dir covers later directory read', function () {
    StepGraph::validate([
        graph_fake_step('a', [], ['theme/parts/header.html']),
        graph_fake_step('b', ['theme/parts/*'], []),
    ], seeds: []);
    assert_true(true);
});

test('StepGraph theme/* covers any theme path', function () {
    StepGraph::validate([
        graph_fake_step('fix', [], ['theme/*']),
        graph_fake_step('val', ['theme/theme.json'], []),
    ], seeds: []);
    assert_true(true);
});

test('StepGraph rejects duplicate top-level ids', function () {
    assert_throws(function () {
        StepGraph::validate([
            graph_fake_step('site-spec', ['meta.json'], ['siteSpec.json']),
            graph_fake_step('site-spec', ['meta.json'], ['siteSpec.json']),
        ]);
    });
});

test('StepGraph rejects empty step list', function () {
    assert_throws(fn () => StepGraph::validate([]));
});

test('StepGraph describe exports portable rows', function () {
    $rows = StepGraph::describe([
        graph_fake_step('site-spec', ['meta.json'], ['siteSpec.json'], false),
        graph_fake_step('sections', ['siteSpec.json'], ['theme/parts/*'], true),
    ]);
    assert_eq('site-spec', $rows[0]['id']);
    assert_eq(['meta.json'], $rows[0]['reads']);
    assert_eq(['siteSpec.json'], $rows[0]['writes']);
    assert_eq(false, $rows[0]['concurrent']);
    assert_eq(true, $rows[1]['concurrent']);
    assert_true(!array_key_exists('members', $rows[0]));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php 2>&1 | rg 'StepGraph|FAIL|passed'`

Expected: FAIL — `StepGraph` not found and/or `declaration()` missing on `Step`.

- [ ] **Step 3: Implement `StepGraph`**

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure operations over an ordered list of Steps: assembly-time validation
 * (every read covered by an earlier write or seed) and portable describe().
 */
final class StepGraph
{
    /**
     * @param Step[]   $steps
     * @param string[] $seeds Project paths available before any step (default meta.json).
     */
    public static function validate(array $steps, array $seeds = ['meta.json']): void
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('StepGraph: step list must not be empty');
        }

        $available = [];
        foreach ($seeds as $seed) {
            if (!is_string($seed) || $seed === '') {
                throw new \InvalidArgumentException('StepGraph: seed paths must be non-empty strings');
            }
            $available[$seed] = true;
        }

        $seenIds = [];
        foreach ($steps as $i => $step) {
            if (!$step instanceof Step) {
                throw new \InvalidArgumentException("StepGraph: entry {$i} is not a Step");
            }
            $decl = $step->declaration();
            $id = $decl->id;
            if (isset($seenIds[$id])) {
                throw new \InvalidArgumentException("StepGraph: duplicate step id '{$id}'");
            }
            $seenIds[$id] = true;

            foreach ($decl->reads as $path) {
                if (!self::covers($available, $path)) {
                    $have = $available === [] ? '(none)' : implode(', ', array_keys($available));
                    throw new \InvalidArgumentException(
                        "step \"{$id}\" reads \"{$path}\" but nothing earlier writes it (available: {$have})"
                    );
                }
            }
            foreach ($decl->writes as $path) {
                $available[$path] = true;
            }
        }
    }

    /**
     * @param Step[] $steps
     * @return list<array{id: string, label: string, reads: list<string>, writes: list<string>, concurrent: bool, members?: list<string>}>
     */
    public static function describe(array $steps): array
    {
        $rows = [];
        foreach ($steps as $step) {
            $decl = $step->declaration();
            $row = [
                'id' => $decl->id,
                'label' => $decl->label,
                'reads' => $decl->reads,
                'writes' => $decl->writes,
                'concurrent' => $decl->concurrent,
            ];
            if ($step instanceof ConcurrentGroup) {
                $row['members'] = array_map(
                    static fn (Step $s) => $s->declaration()->id,
                    $step->members(),
                );
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string, true> $available
     */
    public static function covers(array $available, string $needed): bool
    {
        foreach (array_keys($available) as $have) {
            if (self::pathCovers($have, $needed) || self::pathCovers($needed, $have)) {
                // pathCovers(needed, have) alone is wrong for "need exact, have unrelated glob".
                // Use explicit rules below instead of symmetric pathCovers.
            }
            if ($have === $needed) {
                return true;
            }
            // available directory glob covers concrete or same-dir glob reads
            if (str_ends_with($have, '/*')) {
                $prefix = substr($have, 0, -1); // keep trailing slash intent: "theme/parts/"
                if ($needed === $have || str_starts_with($needed, $prefix)) {
                    return true;
                }
            }
            // concrete available under a directory covers a later directory read
            if (str_ends_with($needed, '/*')) {
                $prefix = substr($needed, 0, -1);
                if (str_starts_with($have, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @deprecated internal — prefer covers() rules; kept out of public API if unused */
    private static function pathCovers(string $have, string $needed): bool
    {
        return false;
    }
}
```

**Clean up `covers()` for production** — implement only the rules in the spec (no dead `pathCovers` stub). Final `covers` body:

```php
public static function covers(array $available, string $needed): bool
{
    foreach (array_keys($available) as $have) {
        if ($have === $needed) {
            return true;
        }
        if (str_ends_with($have, '/*')) {
            $prefix = substr($have, 0, -1); // e.g. "theme/parts/"
            if ($needed === $have || str_starts_with($needed, $prefix)) {
                return true;
            }
        }
        if (str_ends_with($needed, '/*')) {
            $prefix = substr($needed, 0, -1);
            if (str_starts_with($have, $prefix)) {
                return true;
            }
        }
    }
    return false;
}
```

**ConcurrentGroup `members()`:** Task 4 adds a public `members(): array` accessor if not present — for now, if `describe` needs members before Task 4, gate with `method_exists` or implement accessor in Task 4 and assert `members` in a follow-up test there.

For Task 2, omit the ConcurrentGroup branch in `describe` (only export declaration fields). Add members in Task 4.

Simplified `describe` for Task 2:

```php
public static function describe(array $steps): array
{
    $rows = [];
    foreach ($steps as $step) {
        $decl = $step->declaration();
        $rows[] = [
            'id' => $decl->id,
            'label' => $decl->label,
            'reads' => $decl->reads,
            'writes' => $decl->writes,
            'concurrent' => $decl->concurrent,
        ];
    }
    return $rows;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php 2>&1 | tail -5`

Expected: all step_graph tests PASS (after Task 3 interface+stubs if needed).

- [ ] **Step 5: Commit**

```bash
git add src/StepGraph.php tests/unit/step_graph_test.php
git commit -m "feat: add StepGraph validate and describe (BIGR-645)"
```

---

### Task 3: `Step::declaration()` + stubs on every implementor

**Files:**
- Modify: `src/Step.php`
- Modify: every class that implements `Step` / `ConcurrentStep` (see list below)
- Modify: `tests/unit/pipeline_test.php` (`RecorderStep`)
- Modify: `tests/unit/concurrent_group_test.php` (`RecordingConcurrentStep`)

**Classes that must gain `declaration()`:**

- `src/ConcurrentGroup.php`
- All under `src/Steps/`: ApplyIdentity, AssembleLandingPage, CollectImages, ContrastFix, CoverContrast, CustomMotion, DesignDirection, FinalizeTheme, FixBlocks, FontsPhp, GenerateImages, MotionSanity, NormalizeLayout, PageStyles, RefinePrompt, ScaffoldTheme, SectionPlan, SectionRhythm, Sections, SiteSpec, ThemeJson, ValidateTheme

- [ ] **Step 1: Extend the interface**

```php
// src/Step.php — add:
public function declaration(): StepDeclaration;
```

- [ ] **Step 2: Stub every implementor so the suite loads**

Pattern for a non-concurrent step (temporary stub OK only until Task 5 fills real IO — prefer **real declarations immediately** from the table in Task 5; if stubbing, use at least correct `id`/`label`):

```php
public function declaration(): StepDeclaration
{
    return new StepDeclaration(
        id: $this->id(),
        label: $this->label(),
        reads: [],
        writes: [],
        concurrent: false,
    );
}
```

For `ConcurrentGroup`, temporary:

```php
public function declaration(): StepDeclaration
{
    return new StepDeclaration($this->id(), $this->label(), [], [], true);
}
```

Test doubles:

```php
// RecorderStep + RecordingConcurrentStep
public function declaration(): StepDeclaration
{
    return new StepDeclaration($this->id(), $this->label(), [], [], false);
}
```

Add `use Automattic\SiteBuild\StepDeclaration;` where needed.

- [ ] **Step 3: Run full suite**

Run: `php tests/run.php`

Expected: PASS (Pipeline does not validate yet; empty declarations are fine).

- [ ] **Step 4: Commit**

```bash
git add src/Step.php src/ConcurrentGroup.php src/Steps tests/unit/pipeline_test.php tests/unit/concurrent_group_test.php
git commit -m "feat: require Step::declaration() on all steps (BIGR-645)"
```

---

### Task 4: Real `ConcurrentGroup` declaration + `members()` + describe members

**Files:**
- Modify: `src/ConcurrentGroup.php`
- Modify: `src/StepGraph.php` (`describe` members key)
- Modify: `tests/unit/step_graph_test.php` or `tests/unit/concurrent_group_test.php`

- [ ] **Step 1: Write failing test for group declaration**

```php
test('ConcurrentGroup declaration unions member reads/writes and is concurrent', function () {
    $llm = new \Automattic\SiteBuild\Tests\FakeLlm();
    $a = new RecordingConcurrentStep('theme-json', ['out' => ['prompt' => 'P']]);
    // Give RecordingConcurrentStep a way to set reads/writes OR use graph_fake_step members.
    // Prefer: extend RecordingConcurrentStep constructor with optional reads/writes for declaration.
});
```

Cleaner approach — update `RecordingConcurrentStep`:

```php
final class RecordingConcurrentStep implements ConcurrentStep
{
    public array $consumed = [];

    /** @param array<string,array<string,mixed>> $requests @param list<string> $reads @param list<string> $writes */
    public function __construct(
        private string $id,
        private array $requests,
        private array $reads = [],
        private array $writes = [],
    ) {}

    public function id(): string { return $this->id; }
    public function label(): string { return $this->id; }
    public function requests(Project $project): array { return $this->requests; }
    public function consume(Project $project, array $results): void { $this->consumed = $results; }
    public function run(Project $project): void {}
    public function declaration(): StepDeclaration
    {
        return new StepDeclaration($this->id, $this->id, $this->reads, $this->writes, false);
    }
}

test('ConcurrentGroup declaration unions members and marks concurrent', function () {
    $llm = new \Automattic\SiteBuild\Tests\FakeLlm();
    $a = new RecordingConcurrentStep('theme-json', ['out' => ['prompt' => 'P']], ['meta.json'], ['theme/theme.json']);
    $b = new RecordingConcurrentStep('section-plan', ['out' => ['prompt' => 'P']], ['meta.json'], ['sections.json']);
    $g = new \Automattic\SiteBuild\ConcurrentGroup($llm, [$a, $b]);
    $d = $g->declaration();
    assert_eq('theme-json+section-plan', $d->id);
    assert_eq(true, $d->concurrent);
    assert_eq(['meta.json'], $d->reads); // unique union
    $writes = $d->writes;
    sort($writes);
    assert_eq(['sections.json', 'theme/theme.json'], $writes);
    assert_eq(['theme-json', 'section-plan'], $g->members());
});
```

- [ ] **Step 2: Run test — expect FAIL** (union not implemented / no `members()`)

- [ ] **Step 3: Implement on `ConcurrentGroup`**

```php
/** @return ConcurrentStep[] */
public function members(): array
{
    return $this->steps;
}

public function declaration(): StepDeclaration
{
    $reads = [];
    $writes = [];
    foreach ($this->steps as $step) {
        $d = $step->declaration();
        foreach ($d->reads as $p) {
            $reads[$p] = true;
        }
        foreach ($d->writes as $p) {
            $writes[$p] = true;
        }
    }
    return new StepDeclaration(
        id: $this->id(),
        label: $this->label(),
        reads: array_keys($reads),
        writes: array_keys($writes),
        concurrent: true,
    );
}
```

Update `StepGraph::describe` to attach `members` when `$step instanceof ConcurrentGroup`.

- [ ] **Step 4: Run tests — PASS**

- [ ] **Step 5: Commit**

```bash
git add src/ConcurrentGroup.php src/StepGraph.php tests/unit/concurrent_group_test.php tests/unit/step_graph_test.php
git commit -m "feat: ConcurrentGroup unions declarations for StepGraph (BIGR-645)"
```

---

### Task 5: Real `declaration()` IO on every production step

**Files:** all `src/Steps/*.php` (and ensure `ConcurrentGroup` already done).

Use the design table. Implement `declaration()` returning real reads/writes. **Do not change `run()`.**

Reference declarations (copy into each class):

| Class | reads | writes | concurrent |
|-------|-------|--------|------------|
| ScaffoldThemeStep | `[]` | `theme/style.css`, `theme/readme.txt`, `theme/assets/motion/*` | false |
| RefinePromptStep | `meta.json` | `meta.json` | false |
| SiteSpecStep | `meta.json` | `siteSpec.json` | false |
| ApplyIdentityStep | `siteSpec.json`, `theme/style.css`, `theme/readme.txt` | `theme/style.css`, `theme/readme.txt` | false |
| DesignDirectionStep | `meta.json`, `siteSpec.json` | `designDirection.json` | false |
| ThemeJsonStep | `meta.json`, `siteSpec.json`, `designDirection.json` | `theme/theme.json` | false |
| SectionPlanStep | `meta.json`, `siteSpec.json`, `designDirection.json` | `sections.json` | false |
| SectionsStep | `siteSpec.json`, `theme/theme.json`, `sections.json`, `designDirection.json` | `theme/parts/*` | **true** |
| SectionRhythmStep | `sections.json`, `theme/parts/*` | `theme/parts/*` | false |
| AssembleLandingPageStep | `sections.json`, `theme/parts/header.html`, `theme/parts/footer.html`, `theme/parts/*`, `theme/theme.json` | `theme/templates/front-page.html`, `theme/templates/index.html`, `theme/theme.json` | false |
| CollectImagesStep | `theme/parts/*`, `theme/templates/*` | `images.json` | false |
| NormalizeLayoutStep | `theme/theme.json`, `theme/parts/*`, `theme/templates/*` | `theme/parts/*`, `theme/templates/*` | false |
| ContrastFixStep | `theme/theme.json`, `theme/parts/*`, `theme/templates/*` | same three | false |
| MotionSanityStep | `designDirection.json`, `sections.json`, `theme/parts/*`, `theme/templates/*` | `theme/parts/*`, `theme/templates/*` | false |
| FixBlocksStep | `theme/theme.json`, `theme/parts/*`, `theme/templates/*` | `theme/parts/*`, `theme/templates/*` | false |
| PageStylesStep | `theme/theme.json`, `theme/style.css`, `designDirection.json`, `theme/parts/*`, `theme/templates/*` | `theme/style.css` | false |
| CustomMotionStep | `siteSpec.json`, `designDirection.json`, `theme/style.css`, `theme/parts/*`, `theme/templates/*` | `theme/style.css` | false |
| FontsPhpStep | `theme/theme.json`, `designDirection.json`, `theme/parts/*`, `theme/templates/*` | `theme/fonts.php` | false |
| FinalizeThemeStep | `designDirection.json`, `theme/assets/motion/*` | `theme/functions.php`, `theme/assets/motion/*` | false |
| ValidateThemeStep | `sections.json`, `theme/style.css`, `theme/theme.json`, required template/part files, `theme/parts/*`, `theme/templates/*` | `[]` | false |
| CoverContrastStep | `images.generated.json`, `theme/theme.json`, `theme/assets/*`, `theme/parts/*`, `theme/templates/*` | `theme/parts/*`, `theme/templates/*` | false |
| GenerateImagesStep | `images.json`, `siteSpec.json`, `designDirection.json`, `theme/parts/*`, `theme/templates/*` | `images.json`, `images.generated.json`, `theme/assets/*`, `theme/parts/*`, `theme/templates/*` | **true** |

Final hardening after implementation: declaration and seed paths use canonical
`/`-separated project-relative syntax with only terminal `/*` globs; internal
sets preserve numeric-string paths; and top-level plus concurrent-member ids
share one global addressable namespace. `images.generated.json` is cleared at
the start of generation and written only after every successful completion
path, so `CoverContrastStep` has an unambiguous ordering dependency.

Example for `ScaffoldThemeStep`:

```php
public function declaration(): StepDeclaration
{
    return new StepDeclaration(
        id: $this->id(),
        label: $this->label(),
        reads: [],
        writes: [
            'theme/style.css',
            'theme/readme.txt',
            'theme/assets/motion/*',
        ],
        concurrent: false,
    );
}
```

Example for `SectionsStep` (concurrent true):

```php
public function declaration(): StepDeclaration
{
    return new StepDeclaration(
        id: $this->id(),
        label: $this->label(),
        reads: [
            'siteSpec.json',
            'theme/theme.json',
            'sections.json',
            'designDirection.json',
        ],
        writes: ['theme/parts/*'],
        concurrent: true,
    );
}
```

- [ ] **Step 1: Apply declarations to all step classes**

- [ ] **Step 2: Run unit suite**

Run: `php tests/run.php`

Expected: PASS (Pipeline still not validating full graph unless steps are used together).

- [ ] **Step 3: Commit**

```bash
git add src/Steps
git commit -m "feat: declare reads/writes/concurrent on every step (BIGR-645)"
```

---

### Task 6: `Pipeline` validates on construct

**Files:**
- Modify: `src/Pipeline.php`
- Modify: `tests/unit/pipeline_test.php` (RecorderSteps need seeds or empty reads)

- [ ] **Step 1: Write failing test**

```php
test('Pipeline rejects a step list with unmet reads', function () {
    assert_throws(function () {
        new Pipeline([
            new class implements Step {
                public function id(): string { return 'sections'; }
                public function label(): string { return 'sections'; }
                public function run(Project $project): void {}
                public function declaration(): StepDeclaration
                {
                    return new StepDeclaration('sections', 'sections', ['sections.json'], ['theme/parts/*'], true);
                }
            },
        ]);
    });
});
```

- [ ] **Step 2: Run — FAIL** (no validation yet)

- [ ] **Step 3: Validate in constructor**

```php
/** @param Step[] $steps */
public function __construct(private array $steps)
{
    StepGraph::validate($this->steps);
}
```

Update `RecorderStep` usages: either give them empty reads (valid with any seeds) or pass seeds — empty reads/writes already validate with default seed.

Existing pipeline tests that build `[RecorderStep, ConcurrentGroup, RecorderStep]` must keep working — empty reads OK.

- [ ] **Step 4: Run suite — PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Pipeline.php tests/unit/pipeline_test.php
git commit -m "feat: validate step graph when constructing Pipeline (BIGR-645)"
```

---

### Task 7: `StepComposition` (default + mutations)

**Files:**
- Create: `src/StepComposition.php`
- Create: `tests/unit/step_composition_test.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\StepGraph;
use Automattic\SiteBuild\Tests\FakeLlm;

function composition_deps(): array
{
    $fixer = new class implements BlockFixer {
        public function fix(string $themeDir): string { return '[fix-templates] noop'; }
    };
    return [
        'llm' => new FakeLlm(),
        'renderer' => new PromptRenderer(Package::promptsDir()),
        'models' => \Automattic\SiteBuild\StepDefaults::models(),
        'temperatures' => \Automattic\SiteBuild\StepDefaults::temperatures(),
        'blockFixer' => $fixer,
    ];
}

test('StepComposition default matches CLI step order and validates', function () {
    $d = composition_deps();
    $c = StepComposition::default(
        llm: $d['llm'],
        renderer: $d['renderer'],
        models: $d['models'],
        temperatures: $d['temperatures'],
        blockFixer: $d['blockFixer'],
    );
    $steps = $c->steps();
    assert_eq([
        'scaffold-theme', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
        'theme-json+section-plan', 'sections', 'section-rhythm', 'assemble-landing-page',
        'collect-images', 'normalize-layout', 'contrast-fix', 'motion-sanity', 'fix-blocks',
        'page-styles', 'custom-motion', 'fonts-php', 'finalize-theme', 'validate-theme',
    ], array_map(fn ($s) => $s->id(), $steps));
});

test('StepComposition without removes a top-level step and still validates', function () {
    $d = composition_deps();
    $c = StepComposition::default(...$d)->without('custom-motion');
    $ids = array_map(fn ($s) => $s->id(), $c->steps());
    assert_true(!in_array('custom-motion', $ids, true));
});

test('StepComposition insertAfter inserts a host step', function () {
    $d = composition_deps();
    $extra = new class implements \Automattic\SiteBuild\Step {
        public function id(): string { return 'host-marker'; }
        public function label(): string { return 'Host'; }
        public function run(\Automattic\SiteBuild\Project $project): void {}
        public function declaration(): \Automattic\SiteBuild\StepDeclaration
        {
            // reads nothing new; writes a path nothing else needs
            return new \Automattic\SiteBuild\StepDeclaration('host-marker', 'Host', ['meta.json'], ['host-marker.json'], false);
        }
    };
    $ids = array_map(
        fn ($s) => $s->id(),
        StepComposition::default(...$d)->insertAfter('site-spec', $extra)->steps()
    );
    $i = array_search('site-spec', $ids, true);
    assert_eq('host-marker', $ids[$i + 1]);
});

test('StepComposition describe concurrent flags on sections and group', function () {
    $d = composition_deps();
    $rows = StepGraph::describe(StepComposition::default(...$d)->steps());
    $byId = [];
    foreach ($rows as $row) {
        $byId[$row['id']] = $row;
    }
    assert_eq(true, $byId['sections']['concurrent']);
    assert_eq(true, $byId['theme-json+section-plan']['concurrent']);
    assert_eq(false, $byId['site-spec']['concurrent']);
    assert_eq(['theme-json', 'section-plan'], $byId['theme-json+section-plan']['members']);
});
```

Note: `StepComposition::default(...$d)` needs named args matching the method signature — unpack carefully:

```php
StepComposition::default(
    llm: $d['llm'],
    renderer: $d['renderer'],
    models: $d['models'],
    temperatures: $d['temperatures'],
    blockFixer: $d['blockFixer'],
);
```

- [ ] **Step 2: Run — FAIL** (`StepComposition` missing)

- [ ] **Step 3: Implement `StepComposition`**

Move the step list from current `SiteBuilder::pipeline()` into `default()`:

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\AssembleLandingPageStep;
// ... all step imports used today in SiteBuilder ...

/**
 * Ordered Step list with validation. Hosts start from default() and without/insertAfter/replace.
 */
final class StepComposition
{
    /** @param Step[] $steps @param list<string> $seeds */
    private function __construct(
        private array $steps,
        private array $seeds = ['meta.json'],
    ) {
        StepGraph::validate($this->steps, $this->seeds);
    }

    /**
     * @param array<string, string>  $models
     * @param array<string, ?float>  $temperatures
     */
    public static function default(
        Llm $llm,
        PromptRenderer $renderer,
        array $models,
        array $temperatures,
        BlockFixer $blockFixer,
    ): self {
        $models = array_merge(StepDefaults::models(), $models);
        $temps = array_merge(StepDefaults::temperatures(), $temperatures);

        return new self([
            new ScaffoldThemeStep(),
            new RefinePromptStep($llm, $renderer, $models['refine-prompt'], $temps['refine-prompt']),
            new SiteSpecStep($llm, $renderer, $models['site-spec'], $temps['site-spec']),
            new ApplyIdentityStep(),
            new DesignDirectionStep($llm, $renderer, $models['design-direction'], $temps['design-direction'], $models['design-direction-seeds']),
            new ConcurrentGroup($llm, [
                new ThemeJsonStep($llm, $renderer, $models['theme-json'], $temps['theme-json']),
                new SectionPlanStep($llm, $renderer, $models['section-plan'], $temps['section-plan']),
            ]),
            new SectionsStep($llm, $renderer, $models['sections'], $temps['sections']),
            new SectionRhythmStep(),
            new AssembleLandingPageStep(),
            new CollectImagesStep(),
            new NormalizeLayoutStep(),
            new ContrastFixStep(),
            new MotionSanityStep(),
            new FixBlocksStep($blockFixer),
            new PageStylesStep($llm, $renderer, $models['page-styles'], $temps['page-styles']),
            new CustomMotionStep($llm, $renderer, $models['custom-motion'], $temps['custom-motion']),
            new FontsPhpStep($llm, $renderer, $models['fonts-php'], $temps['fonts-php']),
            new FinalizeThemeStep(),
            new ValidateThemeStep(),
        ]);
    }

    /** @return Step[] */
    public function steps(): array
    {
        return $this->steps;
    }

    public function without(string ...$ids): self
    {
        $drop = array_fill_keys($ids, true);
        $next = array_values(array_filter(
            $this->steps,
            static fn (Step $s) => !isset($drop[$s->id()])
        ));
        return new self($next, $this->seeds);
    }

    public function insertAfter(string $afterId, Step $step): self
    {
        $next = [];
        $found = false;
        foreach ($this->steps as $s) {
            $next[] = $s;
            if ($s->id() === $afterId) {
                $next[] = $step;
                $found = true;
            }
        }
        if (!$found) {
            throw new \InvalidArgumentException("StepComposition::insertAfter: unknown id '{$afterId}'");
        }
        return new self($next, $this->seeds);
    }

    public function replace(string $id, Step $step): self
    {
        $next = [];
        $found = false;
        foreach ($this->steps as $s) {
            if ($s->id() === $id) {
                $next[] = $step;
                $found = true;
            } else {
                $next[] = $s;
            }
        }
        if (!$found) {
            throw new \InvalidArgumentException("StepComposition::replace: unknown id '{$id}'");
        }
        return new self($next, $this->seeds);
    }

    public function withSeeds(string ...$seeds): self
    {
        return new self($this->steps, array_values($seeds));
    }
}
```

Copy the exact step constructors from current `SiteBuilder.php` so behavior stays identical.

- [ ] **Step 4: Run composition tests — PASS**

If `without('custom-motion')` breaks validation for a later step that only custom-motion wrote — custom-motion only writes `theme/style.css` which scaffold already wrote; should be fine.

- [ ] **Step 5: Commit**

```bash
git add src/StepComposition.php tests/unit/step_composition_test.php
git commit -m "feat: StepComposition default graph and host mutations (BIGR-645)"
```

---

### Task 8: Wire `SiteBuilder` to `StepComposition`

**Files:**
- Modify: `src/SiteBuilder.php`
- Modify: `tests/unit/site_builder_test.php` (existing stepIds test must stay green)

- [ ] **Step 1: Replace `pipeline()` body**

```php
public function pipeline(?StepComposition $composition = null): Pipeline
{
    $renderer = new PromptRenderer($this->promptsDir);
    $models = array_merge(StepDefaults::models(), $this->models);
    $temps = array_merge(StepDefaults::temperatures(), $this->temperatures);

    $composition ??= StepComposition::default(
        llm: $this->llm,
        renderer: $renderer,
        models: $this->models, // composition merges defaults again — pass overrides only OR pre-merged
        temperatures: $this->temperatures,
        blockFixer: $this->blockFixer,
    );

    return new Pipeline($composition->steps());
}
```

**Avoid double-merge bugs:** either `StepComposition::default` merges `StepDefaults` (as in Task 7) and SiteBuilder passes **only overrides** (`$this->models`, `$this->temperatures`), or composition expects already-merged arrays. Pick one:

- **Chosen:** `StepComposition::default` merges defaults (Task 7 code). SiteBuilder passes `$this->models` / `$this->temperatures` as overrides only (may be empty).

Remove the inline `new Step...` list from SiteBuilder; delete unused step imports from SiteBuilder if no longer referenced.

- [ ] **Step 2: Run suite**

Run: `php tests/run.php`

Expected: PASS, including `SiteBuilder pipeline exposes the default step order and stop ids`.

- [ ] **Step 3: Commit**

```bash
git add src/SiteBuilder.php
git commit -m "feat: build SiteBuilder pipeline from StepComposition (BIGR-645)"
```

---

### Task 9: Docs pointer + final verification

**Files:**
- Modify: `docs/composition-and-extension.md` (short note under “Two building-block decisions”)

- [ ] **Step 1: Add a brief landing note**

After the declarative steps bullet, add something like:

```markdown
As of BIGR-645, steps implement `declaration(): StepDeclaration`, lists are
validated by `StepGraph` at assembly time, the CLI default graph lives in
`StepComposition::default()`, and hosts can export order via `StepGraph::describe()`.
See `docs/superpowers/specs/2026-07-16-bigr-645-declarative-steps-design.md`.
```

- [ ] **Step 2: Full test run**

Run: `php tests/run.php`

Expected: `N passed, 0 failed` (N ≥ previous count + new tests).

- [ ] **Step 3: Commit**

```bash
git add docs/composition-and-extension.md
git commit -m "docs: point composition guide at declarative steps (BIGR-645)"
```

---

## Self-review (plan vs spec)

| Spec requirement | Task |
|------------------|------|
| `StepDeclaration` value object | Task 1 |
| `Step::declaration()` | Task 3 |
| Exact paths + `/*` globs + coverage rules | Task 2 |
| Seeds default `meta.json` + extra seeds | Tasks 2, 7 (`withSeeds`) |
| `concurrent` fan-out only | Tasks 4–5 |
| Validate at assembly (`Pipeline` + composition) | Tasks 6–7 |
| `StepComposition` default + without/insertAfter/replace | Task 7 |
| Portable `describe()` no wpcom | Tasks 2, 4 |
| SiteBuilder uses composition; step order unchanged | Task 8 |
| Tests listed in spec | Tasks 1–2, 4, 6–8 |
| CoverContrast / GenerateImages declarations | Task 5 |

No TBD placeholders. Types consistent: `StepDeclaration`, `StepGraph::validate/describe/covers`, `StepComposition::default/without/insertAfter/replace/withSeeds/steps`.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-16-bigr-645-declarative-steps.md`.

**Two execution options:**

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks
2. **Inline Execution** — execute tasks in this session with checkpoints

Which approach?
