# Composition & extension — the step library, per-host graphs, and extending a build

> Design note (updated 2026-08-23). This supersedes the "the host workflow encodes the same DAG as the library's default composition" framing in `site-build-portable-pipeline.md`: hosts compose different graphs on purpose.

## The shift: a library, not a fixed pipeline

The shared surface of the toolset is not a fixed graph. It is a library of reusable site-creation steps and units: deterministic operations, prompts, fan-out units, and the `Llm` interface. Each host composes its own graph over that library. `SiteBuilder::pipeline()` is the default CLI composition, one among several, rather than the only pipeline.

Different hosts legitimately want different steps. That divergence is a feature, not drift to be prevented.

## Three tiers of extension

| Tier | Who | How |
| --- | --- | --- |
| Selection | First-party hosts such as WordPress.com and Studio | Compose a subset of library steps. |
| Authoring | First-party hosts, including a future Studio Web | Write new steps in the host's own tree. |
| Injection | Third parties such as agencies using a harness | Drive a build and interleave their own work at file checkpoints. |

Concrete divergences include:

- WordPress.com can keep a lean first-party site-creation composition.
- Studio can use a richer composition, potentially including WordPress plugin installation or configuration steps. That example is a possible extension, not a feature implemented here.
- Harness users can stop the repository build at a named step, modify the project, and resume at another named step.

## Files are the interface

The property that makes cross-host extension possible is deliberately simple: `Project` state lives on disk, and every step is individually addressable and resumable.

Because the boundary between steps is files rather than in-memory objects, another codebase, another language, or an agent can drive a build and do its own work between steps without linking into the PHP package. Do not replace this boundary with process-local state merely to make the implementation look cleaner; files are what make extension portable across processes and hosts.

`warnings.json` is part of that portable disk-state contract. It is the project-root, machine-readable record of non-fatal defects in output a step still delivered. Warning-producing steps must use `Project::addWarnings()` and declare `warnings.json` in `writes[]`, so hosts and later repair steps can discover the artifact without parsing console output or human-oriented logs.

Mutating repair steps may deliver through only an explicitly reviewed, deterministic safe degradation. Advisory final validators may report residual problems without rewriting the artifact. Generated-content failures are fixed, degraded, warned about, and allowed to continue at the smallest safe unit; missing or corrupt required artifacts and programming invariants remain fatal.

## Two building-block decisions

### Declarative steps

Each step declares an id, `reads[]`, `writes[]`, and its concurrency behavior. The ordered array is derived data. Any host composition is validated so a step whose inputs have not yet been produced is a construction-time error rather than a failure halfway through a build.

Steps implement `declaration(): StepDeclaration`; `StepGraph` validates lists at assembly time using `meta.json` as the default seed. The CLI's standard graphs live in `StepComposition`, and hosts can export portable ordering data through `StepGraph::describe()` without exposing host tool names.

### Portable units

Fan-out units expose `generate(array $input): output` and receive no `Project`. `ConcurrentStep` is a thin adapter between project files and those stateless units. The type prevents a unit from reaching into build state, so the same unit can run under a lean graph or a richer host composition.

The markup family has four units: `HeroUnit`, `SectionUnit`, `HeaderUnit`, and `FooterUnit`. Routing is structural: only the front page's first section uses `HeroUnit`; interior openings remain ordinary sections with a recipe-free header-contract subset. Each unit returns the same JSON-serializable `MarkupResult` envelope containing markup, successful repairs, and durable warnings. A host must preserve that whole envelope, including on Project-free fallback paths.

Hero topology is a code-owned extension surface rather than global prompt prose. `designDirection.json` persists one normalized structured blueprint, and `aboveFold.json` persists the shared header, hero, opening, and seam relationship. Consumers declare the artifact and reject the wrong delivery phase. General section and footer inputs remain free of recipe topology, which keeps them independently extensible.

Together, declarative steps and stateless units make host composition safe and step reuse testable.

## Supplying a host transport

An embedding host loads `autoload.php`, constructs an object that implements `Llm`, and passes it to `SiteBuilder`. It does not need `src/bootstrap.php`, CLI environment loading, or the repository's resolver:

```php
require_once $packageRoot . '/autoload.php';

$llm = new HostLlm(/* the host owns authentication and endpoints */);

$builder = new SiteBuilder(
    llm: $llm,
    promptsDir: Package::promptsDir(),
    outputRoot: $hostOutputRoot,
    blockFixer: BlockFixers::default(),
    models: $hostModels,
    temperatures: $hostTemperatures,
);
```

The host can pass its own model and temperature maps, or use `StepDefaults::models()` and `StepDefaults::temperatures()` when the process environment follows this package's conventions. The same `Llm` object is then shared by all steps and concurrent groups in that builder.

An embedding host that wants the repository's transport ladder can call `TransportResolver::decide()` and `TransportResolver::build()` directly. It must inject an API factory into `build()` because shared `src/` code cannot call the CLI-only `make_llm()` global. Harness choices also require a non-blank default model. See [Transport resolution](transport-resolution.md#resolution-disclosure-and-construction) for that seam and for the distinction between CLI, embedding, and Skill hosts.

## Conformance for a host-supplied `Llm`

Implementing the four method signatures is not enough. The pipeline depends on the behavioral contract in `src/Llm.php`:

- `complete()` returns assistant text; `completeJson()` returns a decoded JSON value.
- `completeBatch()` and `completeJsonBatch()` preserve the input keys. The text batch returns `TextBatchResult`, including keyed degradation notes.
- `cached_prefixes` must be a list of strings with no more than three non-blank layers. Blank layers are ignored. The remaining layers are prepended in order on single and batch paths; none may be silently dropped.
- A malformed or oversized `cached_prefixes` request is rejected locally with `LlmRequestRejected`, before any transport call.
- The one-token cache-warm request used by the pipeline must honor `tolerate_empty` on the text path.
- Any supported option must be honored. An adapter that cannot honor an otherwise valid option must refuse it or disclose a documented degradation; it must not silently swallow it.

Implementing `UsageReporting` is strongly recommended and is required for the conformance suite's strongest cached-prefix proof. `usageTotals()['input_tokens']` means total billed input, including cache reads and cache creation. It is not necessarily the provider's raw field of the same name. The totals also include requests, output tokens, total tokens, and, when available, separate cache read and creation figures.

Run `LlmConformance` against the actual adapter before wiring it into a host:

```php
$structural = LlmConformance::structural($llm);
$live = LlmConformance::live($llm);
$report = LlmConformance::report([...$structural, ...$live]);
```

The structural tier checks malformed prefix shapes and the three-layer limit. A conforming implementation rejects those requests before transport, so this tier makes zero model calls and belongs in ordinary CI. An implementation that fails to validate may send four small probes, which is why "zero spend" is a property to prove rather than assume.

The live tier first checks reachability, then reports five behavioral findings. It spends six completions before any adapter retries, including one request carrying about 7,500 tokens of cached layers and another carrying about 2,500. It verifies billed input, prefix delivery and order through `completeBatch`, blank-layer handling, the cache-warm path, and batch key round-tripping.

`LlmConformance::report()` returns a non-zero exit code for failures, for a wholly inconclusive run, and when every live finding was skipped. `LlmConformance::passed()` is stricter: any skipped finding makes it return false. A host should preserve those distinctions instead of treating "could not tell" as proof of conformance.

The repository command below resolves its configured transport and runs the same suite. An embedding host normally calls the PHP API above from its own tests so it exercises its own injected adapter:

```bash
php bin/llm-conformance.php --structural
php bin/llm-conformance.php
```

The second command makes live model calls and should use an explicit, intended billing configuration.

## Extending a build through the CLI

There is no plugin registration API and no hook system. The build is step-addressable: stop after a step with `--until`, perform external work against the project directory, then resume from a step with `--from` and the same `--slug`.

- An in-process PHP host composes the graph in code.
- The Claude, Codex, or Grok harness uses the repository Skill, which declares its subscription transport and drives the CLI.
- A headless host or CI job can drive the same flags from a shell script.

A formal hook or manifest system was considered and dropped. Step addressability plus an orchestrator covers this extension shape, and project files provide the checkpoints.

For example, an orchestrator can create a fixed project through `scaffold-theme`, add its own files, and resume from the next step:

```bash
SITE_BUILD_LLM=codex-cli php bin/build.php "a bakery" \
  --slug=a-bakery --until=scaffold-theme

# The orchestrator performs its own work under projects/a-bakery/ here.

SITE_BUILD_LLM=codex-cli php bin/build.php \
  --slug=a-bakery --from=scaffold-plugin
```

Keep the same explicit transport declaration on both commands. The first command stops without starting a preview because `--until` leaves the build incomplete. The second command does not require or use a prompt; `--from` reopens the named project's recorded artifacts and composition.

## The CLI surface

`php bin/build.php` remains the single repository CLI front door. Creation is the prompt form, resume is `--slug` with `--from`, and bounded execution uses `--from` and `--until`. Other flags select the provider, graph, page scope, images, preview behavior, and related build options.

There is no `bin/site-build`, Composer binary entry, or `create` / `resume` / `steps` subcommand family. That proposed second entry point was cancelled because `bin/build.php` already had the richer create, resume, and step-range surface. Duplicating it would create two front doors that drift.

The one transport-specific addition is `--transport`. It resolves and prints the transport audit line, then exits without creating a project or making a model call. The repository Skill uses it to confirm the intended subscription before invoking the ordinary build command.

A `.phar` is not the selected distribution format. Playground previews and screenshot tooling remain separate Node-based development conveniences.

## What stays invariant

The core consists of the base site-creation steps, units, prompts, `Llm` contract, and composition and validation machinery. The graph is a host concern. The core is WordPress-aware because it builds WordPress sites, but host-agnostic because it offers steps and accepts injected dependencies.

## Open questions

- What composition API is most ergonomic for an embedding host: a complete step list, a base composition plus host extensions, or another explicit builder?
- How should the repository-local Skill be distributed when a Composer consumer needs the same harness workflow outside this checkout?

Neither question requires a second CLI entry point or a hook subsystem.
