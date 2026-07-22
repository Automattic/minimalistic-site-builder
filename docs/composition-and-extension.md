# Composition & extension — the step library, per-host graphs, and extending a build

> Design note (2026-07-02), from a DX/extensibility review. **Supersedes** the "the host workflow encodes the *same* DAG as the library's default composition" framing in `site-build-portable-pipeline.md`: hosts compose *different* graphs on purpose.

## The shift: a library, not a fixed pipeline

The shared surface of the toolset is **not a fixed graph**. It is a **library of reusable site-creation steps and units** — the deterministic operations, the prompts, the fan-out units, the `Llm` interface. Each host **composes its own graph** over that library. `SiteBuilder::pipeline()` is the **default / CLI composition**, one among several — not "the pipeline." Host workflows and harness flows are each their own composition of the same library.

Different hosts legitimately want different steps. That divergence is a feature, not drift to be prevented.

## Three tiers of extension

| Tier | Who | How |
|------|-----|-----|
| **Selection** | first-party hosts (WPCom, Studio) | compose a subset of library steps |
| **Authoring** | first-party (e.g. future Studio Web) | write new steps in the host's own tree |
| **Injection** | third parties (agencies, via harness surfaces) | drive a build and interleave their own steps |

Concrete divergences:
- **WPCom** — meant to stay lean: base site creation, first-party only.
- **Studio** — *could* go richer; it might, for example, add WordPress-plugin install/config steps (hypothetical — not built).
- **Harness users (agencies)** — drive a build and splice in their own steps to tailor it.

## Files are the interface (why extension works across codebases)

The property that makes all of this possible is already in the core, and it is the part that can look primitive: **the `Project` is state on disk, and every step is individually runnable and resumable.**

Because the boundary between steps is **files, not in-memory objects**, anything — another codebase, another language, an agent — can drive a build and do its own work between steps without linking into the PHP. **Do not trade this for in-memory state to look cleaner; it is what makes extension work across processes, languages, and agents.**

## Two building-block decisions (resolved)

1. **Declarative steps.** Each step declares `id` + `reads[]` + `writes[]` + concurrency. The ordered array becomes *derived* data. Any host's composition is then **validated** — a step whose inputs aren't yet produced is a construction-time error, not a runtime one. The graph is data, not hand-assembled code.

   As of BIGR-645, steps implement `declaration(): StepDeclaration`, lists are validated by `StepGraph` at assembly time (default seed `meta.json`), the CLI default graph lives in `StepComposition::default()`, and hosts can export order via `StepGraph::describe()` (portable data only — no host tool names). See `docs/superpowers/specs/2026-07-16-bigr-645-declarative-steps-design.md`.

2. **Portable `Unit` type.** Fan-out units are `generate(array $input): output` with **no `Project`**. `ConcurrentStep` becomes a thin `Project`-adapter over units. Statelessness is *structural* (the type can't touch disk), units are reusable across a lean composition and a richer one, and the wpcom ability body *is* `Unit::generate($input)`.

Together these make host composition safe and step reuse provable.

## Extending a build: drive its steps

There is **no plugin API to register against, and no hook system.** The pipeline is **step-addressable** — stop after a step, resume from a step — and customization is just an **orchestrator** that runs the steps and does its own work between them:

- **In-process host (PHP: WPCom, Studio)** — compose the graph in code.
- **Harness (Claude / Codex)** — a **Skill** orchestrates. The Skill runs steps and edits the project directory between them; it never imports the builder. An agency ships their customization *as a Skill* — that is the harness-native "plugin."
- **Headless / CI** — a shell script does the same, driving the step commands.

> A formal hook/manifest system was considered and **dropped** — unnecessary machinery. Step-addressability plus an orchestrator covers every case, and the disk-state design already provides the checkpoints.

Illustrative (a Skill adding a WooCommerce shop):

```
site-build create "a bakery" --until scaffold-theme   # build to a checkpoint
# the Skill's own step: the agent writes plugins.json + drops a shop pattern into the project dir
site-build resume --slug a-bakery                     # resume; later steps pick up the pattern
```

**What this asks of the design:** clean, step-addressable CLI commands (`create --until <step>`, `resume --from <step>`). The declarative graph supplies the step ids to target.

## The CLI surface

- **`site-build` as a Composer `bin`** (a shebang'd `bin/site-build`, `"bin"` in `composer.json`) — not `php bin/build.php`. In-repo: `./bin/site-build`; installed: `site-build`. Users are developers who already have Composer/PHP.
- **Subcommands** as the tool grows: `site-build create`, `site-build resume` (rather than more flags on one script).
- **No `.phar`.** The Node block-fixer can't run from inside a phar (needs extraction) and PHP + Node + an API key are required regardless, so a phar isn't a clean single-binary. Deferred as possible future `brew`-style distribution only.

## What stays invariant (the core)

The core is the base site-creation steps + the units + the prompts + the `Llm` interface + the composition/validation machinery. **The graph is a host concern.** The core is WordPress-*aware* (it builds WP sites) but host-*agnostic* — it offers steps; the host decides which to run.

## Open questions (deferred, not precluded)

- **The step-addressable CLI shape** — the exact `create` / `resume` / `--until` / `--from` surface.
- **A reference Skill** — one that demonstrates the harness orchestration pattern end-to-end.
- **Composition ergonomics** — does a host hand `SiteBuilder` a step list, or extend a base sequence? ("Base + host extensions" is the likely shape.)

## Consequences for the roadmap

- **Nail declarative steps + the `Unit` type before Step 3 (wpcom) hardens a surface.** Steps 2 (Telex parity — *site* creation) and 3 (wpcom) run in parallel; site-creation may add or reshape steps, and the wpcom surface shouldn't harden around a theme-only shape.
- **The extension model is mostly free** — no hook subsystem to build. The only new surface it needs is the step-addressable CLI (`create`/`resume`).
