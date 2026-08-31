# Transport resolution — choosing the `Llm` for a run

## Declared, not detected

A build can be triggered many ways — the CLI, Studio, or a request *from inside a coding agent* (Claude Code, Codex, OpenCode, pi.dev). "Studio" in these documents means the first-party pipeline **host**; the local desktop app that runs preview sites is `StudioAppRunner`. Something has to decide **which `Llm` to inject** into the pipeline, and therefore into every step. A step never chooses its transport; it calls the injected `$this->llm`. So correctness reduces to one thing: **the entry point resolves the right transport once and injects it everywhere.** `SiteBuilder` takes that single `Llm` in its constructor and hands it to every step and the `ConcurrentGroup`, so there is no per-step drift — the whole build shares one transport (only the *model* varies per step, via `StepDefaults` / model overrides).

Environment fingerprints and process ancestry are fallback inputs to the CLI's declaration. They do not move transport selection into the library or into individual steps. An entry point that knows the intended transport should declare it with `SITE_BUILD_LLM` instead of relying on those fallbacks.

## The three hosts

The package supports three host shapes. Their loading and ownership boundaries are deliberately different:

| Host | What it loads | Where the transport comes from |
| --- | --- | --- |
| Repository CLI | `src/bootstrap.php` | `resolve_llm()` runs the resolution ladder and constructs the selected transport. |
| Embedding host, including WordPress.com | `autoload.php` only | The host constructs its own `Llm` and injects it into `SiteBuilder`. |
| Coding-agent harness through the repository Skill | The repository CLI | The Skill explicitly sets `SITE_BUILD_LLM=claude-cli`, `codex-cli`, or `grok-cli` to match its launcher. |

This separation is load-bearing. Shared classes under `src/` cannot call bootstrap globals such as `resolve_llm()`, `make_llm()`, `default_llm_model()`, or `step_models()`. An embedding host does not load those functions.

## The six-rung ladder

`TransportResolver::decide()` receives the environment, a binary lookup callable, an ancestry callable, and the configured default API provider as data. It does not read the filesystem itself. It evaluates five selectors in order, then has a sixth fail-closed outcome:

1. **Explicit override.** `SITE_BUILD_LLM` may be `api`, `claude-cli`, `codex-cli`, or `grok-cli`. An API choice resolves `LLM_PROVIDER`, or the configured default provider when it is unset. A harness choice must have its binary on `PATH`. An invalid value or missing selected binary is an error; resolution never falls through to another billing path.
2. **Configured provider credential.** The resolver looks for the credential belonging to `LLM_PROVIDER`, or to the configured default provider. Supported API providers are `anthropic`, `openai`, `xai`, and `openrouter`; `grok` is accepted as an alias for `xai`. A present provider key selects the metered API. An explicitly selected provider without its key is an error. Keys for other providers do not silently change the configured provider.
3. **Environment fingerprint.** `CLAUDECODE=1` selects Claude. A non-blank Codex sandbox or thread marker can select Codex. If live ancestry identifies a different supported harness, it wins over an inherited supported fingerprint and the audit reason says which signal was ignored. Conflicting supported fingerprints are ambiguous and fail. `OPENCODE=1` and `PI_CODING_AGENT=true` are recognized only so the resolver can refuse them explicitly; OpenCode and pi transports are not implemented.
4. **Process ancestry.** The resolver walks at most 12 ancestors and recognizes `claude`, `codex`, or `grok`. More than one supported harness in the ancestry is ambiguous and fails. This rung is important for Codex because its environment markers disappear when its sandbox is disabled.
5. **Exactly one supported harness on `PATH`.** One of `claude`, `codex`, or `grok` selects that subscription transport. Two or more are ambiguous and fail.
6. **Nothing usable.** The resolver throws `TransportUnavailable` and names the two safe remedies: provide the configured API key, or explicitly choose a supported subscription transport with `SITE_BUILD_LLM`.

The order is also the billing policy. A configured provider key at rung 2 beats harness detection at rungs 3–5. To spend a coding-agent subscription when a key is available, declare that intent explicitly:

```bash
SITE_BUILD_LLM=claude-cli php bin/build.php --transport
SITE_BUILD_LLM=codex-cli  php bin/build.php --transport
SITE_BUILD_LLM=grok-cli   php bin/build.php --transport
```

The repository Skill at `.claude/skills/site-build/SKILL.md` makes this declaration before every confirmation or build command. Detection remains useful for a person invoking the CLI directly, but it is not the Skill's primary mechanism.

## Resolution, disclosure, and construction

The CLI helper `resolve_llm()` in `src/bootstrap.php` has three jobs:

1. Overlay credentials loaded through `Env` onto the environment data passed to the pure resolver.
2. Print `TransportResolver::describe($choice)` through `Narrator`.
3. Construct the selected transport through `TransportResolver::build()`.

`make_llm()` still owns construction of the repository's API clients. It is injected into `TransportResolver::build()` as a provider-aware factory; `TransportResolver` never calls the bootstrap global itself. An embedding host can use the same seam without loading CLI bootstrap code:

```php
$choice = TransportResolver::decide(
    $env,
    $hostBinaryLookup,
    $hostAncestryLookup,
    $hostDefaultProvider,
);

$llm = TransportResolver::build(
    $choice,
    apiFactory: fn (string $provider): Llm => $hostApiFactory($provider),
    harnessModel: $hostHarnessModel,
);
```

The API factory is responsible for credential validation and for returning the correct `Llm` for the resolved provider. Harness construction verifies that `proc_open` is available, that the resolved binary is an executable file, and that a non-blank harness model was supplied.

The repository CLI also aligns a harness with its provider model matrix before building it: Claude uses `anthropic`, Codex uses `openai`, and Grok uses `xai`. An explicit `LLM_PROVIDER` that disagrees with that mapping is rejected. `config/models.json` and `StepDefaults` still choose large or small models per step, and every harness request passes its resolved model explicitly. The interactive harness's current model is never used as an implicit default.

## What the audit line means

A resolved choice is described in one line that includes the transport kind, executable path when applicable, billing class, API provider when applicable, and the rung's reason. For example:

```text
Transport: codex-cli via /usr/local/bin/codex (subscription) — resolved by SITE_BUILD_LLM=codex-cli
Transport: api (metered; provider: anthropic) — resolved by ANTHROPIC_API_KEY present
```

Run `php bin/build.php --transport` to resolve and construct the transport, print this line, and exit without creating a project, making a model call, or spawning a harness subprocess. A resolution failure prints its `TransportUnavailable` message and exits non-zero.

Every CLI invocation that reaches transport resolution prints the audit line exactly once and before later application output. This includes a build that subsequently fails step-id validation. The line means **a billing path was resolved**; it does not mean a request was sent or money was spent.

That ordering is a known limitation, not a promise that all argument validation must follow resolution. The valid values for `--from` and `--until` come from the assembled pipeline, and the pipeline's step objects require the constructed `Llm`. Moving those checks ahead of resolution would require a separate graph-introspection refactor.

## The three harness transports

All three subscription transports extend `HarnessCliLlm`. The base validates requests, prepends normalized `cached_prefixes`, runs batches through `ProcessPool`, accumulates usage, records degradations, and owns unique scratch-directory cleanup. Each subclass supplies its actual command and response parser.

| Transport | Invocation shape | Prompt delivery | Answer and usage |
| --- | --- | --- | --- |
| Claude | `claude -p --safe-mode --output-format json --model <model> --max-turns 2 --effort low --tools "" --settings '{"env":{"MAX_THINKING_TOKENS":"0"}}'` | Standard input | `.result` and `.usage` from one JSON object |
| Codex | `codex exec --ignore-user-config --skip-git-repo-check --json -o <file> -m <model> -c model_reasoning_effort="none"` | Standard input | Final text from the `-o` file; usage from the `turn.completed` JSONL event |
| Grok | `grok --prompt-file <file> --output-format json -m <model> --reasoning-effort low` | A private per-request prompt file | `.text` and `.usage` from one JSON object |

Claude adds a non-blank `system` value through `--system-prompt` and an optional JSON schema inline through `--json-schema`. Two turns let the model think before producing structured output, while `--tools ""` prevents those additional turns from using coding-agent tools. Codex has no supported private system channel; it writes an optional JSON schema to a private per-request file and passes that file with `--output-schema`. Grok also has no supported private system channel; it passes an optional JSON schema inline with `--json-schema`.

### Thinking and token limits

The API path sends `thinking: {type: "disabled"}` and an explicit `max_tokens`, because the pipeline's latency and token budgets were tuned on non-thinking models. The harness path aims at the same behavior through whatever lever each CLI actually exposes. `HarnessCliLlm::THINKING_OFF` is the single switch; `HarnessCliLlm::REASONING_EFFORT` pins the level for anything that cannot turn thinking off outright.

| CLI | Thinking lever | Result |
| --- | --- | --- |
| Claude | `--settings '{"env":{"MAX_THINKING_TOKENS":"0"}}'` | No thinking block emitted, on every model |
| Codex | `-c model_reasoning_effort="none"` | `reasoning_output_tokens: 0` |
| Grok | none exists — pinned to `--reasoning-effort low` | Lowest level its enum offers |

`--effort` alone is not enough for Claude. It is accepted and then discarded for models older than Claude 5: a measured `claude-haiku-4-5` call passing `--effort low` recorded `effort: null` and spent 5,268 of its 6,308 output tokens on thinking, while the same call at `MAX_THINKING_TOKENS=0` spent none and returned 4.1x faster. `--effort` is still pinned because it fixes a separate problem — left unset, Claude inherits the developer's own `~/.claude/settings.json` `effortLevel`, so the same commit built at a different speed on a colleague's machine. Grok's effort enum is `xhigh|high|medium|low`; passing `none` exits non-zero with `unknown effort level`, so `low` is its floor and `THINKING_OFF` is unreachable there.

**Token limits cannot be matched, and are deliberately not attempted.** No harness CLI exposes the API path's `max_tokens`. Claude's `CLAUDE_CODE_MAX_OUTPUT_TOKENS` is not equivalent and must not be substituted: exceeding it returns `is_error: true` with an `API Error:` string in place of the result, where `max_tokens` truncates and reports `stop_reason: max_tokens`. Wiring it in would convert a recoverable truncation — which `TextBatchRecovery` handles by doubling the budget and retrying — into a failed build, and would break `SectionsStep`'s deliberate `max_tokens => 1` probe. Codex ignores `-c model_max_output_tokens`, and Grok has no equivalent flag at all. So `max_tokens` stays validated and then disclosed as a degradation, which is the honest report of what the transport can do.

The varying prompt body never appears in argv. Claude and Codex receive it on standard input. Grok receives only a path in argv, and the prompt file lives in a mode-0700 unique scratch directory. Scratch files and directories are removed after success, non-zero exit, parsing failure, or another exception.

> **Why Grok uses `--prompt-file`:** [xAI's headless-scripting documentation](https://docs.x.ai/build/cli/headless-scripting) describes `-p` / `--single`, which places the prompt in an argv element; it documents no standard-input path and does not mention `--prompt-file`. The shipped `grok --help` does include `--prompt-file`, and we deliberately use it because argv is world-readable through tools such as `ps` while prompts can contain user and site content. The human confirmed this choice. Both `--prompt-file` and `-p` were measured returning exit 0, text, and the same ten-key JSON envelope, so `-p` remains a verified fallback; if `--prompt-file` is removed, Grok fails loudly with a non-zero exit and an unknown-flag message on stderr rather than silently changing prompt delivery.

`ProcessPool` receives argv as an array, not a shell command. The child process gets a replacement allowlist environment rather than inheriting the parent environment; provider API keys are not in that allowlist. This prevents a subscription transport from silently crossing into metered API billing.

Each request accepts at most three non-blank `cached_prefixes`. `CachedPrefixes::normalize()` validates the shape and removes blank layers, then the base concatenates all remaining layers in order ahead of the prompt. Callers remain responsible for separators inside their prefix strings.

No accepted harness CLI can honor `temperature` or `max_tokens`. The adapter does not reject those otherwise valid requests, because normal pipeline steps use both options. Instead it writes one `Narrator` disclosure per option per PHP process and attaches a degradation note to each affected result. Codex and Grok handle a non-blank `system` option the same way. Claude honors `system` directly.

Harness failures are surfaced as `HarnessCallFailed` with the binary, exit code, and the captured diagnostic channel. Stderr is preferred when it contains text; otherwise stdout is included because some harnesses emit their non-zero error envelope there. A raw text batch isolates a failed member and records a keyed degradation when another member remains usable; if every member fails, the batch throws the first harness failure. JSON calls and single calls fail rather than inventing an answer.

## Usage accounting

Every harness implements `UsageReporting`. The cumulative totals include request count, output tokens, cache creation and cache read tokens, and total billed input. The convention matters because providers report caching differently:

- Claude and Grok report uncached input separately from cache creation and cache reads, so the base adds all three.
- Codex reports cached input as a subset of `input_tokens`, so its adapter does not add cached tokens a second time.

Consumers can therefore compare `usageTotals()['input_tokens']` across transports as billed input rather than as whichever raw field happened to share that name.

## Measured harness overhead

The accepted command shapes were re-measured on 2026-08-23 against Claude Code, `codex-cli 0.148.0`, and Grok `1.0.5`. These figures describe fixed input overhead for one very small completion; they are operational measurements, not provider guarantees:

| Harness | Fixed input overhead | Wall time |
| --- | ---: | ---: |
| Claude | about 18,680 tokens | 3.1 seconds |
| Codex | about 17,365 tokens | 3.8 seconds |
| Grok | about 24,073 tokens | 5.7 seconds |

Claude's overhead remained about 18,680 tokens from an empty working directory and with dynamic system-prompt sections excluded, so repository instruction files were not the cause. Concurrent Claude calls reused the cache, but every invocation still pays the latency and usage shape of a full coding-agent client.

Harness batches run up to 10 CLI processes concurrently by default. Set `SITE_BUILD_HARNESS_CONCURRENCY` to a positive integer to override that cap; absent and empty values use 10. Lower values reduce the number of simultaneous full CLI processes at the cost of additional sequential waves. The CLI host reads this setting through the repository environment loader, so a value in `.env` is honored.

Model pinning is a billing invariant, not an optimization. In the same measurement, an unpinned Claude call inherited the interactive Opus model and cost about 62 times the same small answer pinned to Haiku. That is why all four `Llm` methods resolve and pass a model on every harness call.

Harness transports trade speed and input overhead for subscription billing and convenient local authentication. Direct API transports remain the lower-overhead choice when metered credentials are intended.

## Proving a host adapter

The executable contract is `LlmConformance`; see [Composition and extension](composition-and-extension.md#conformance-for-a-host-supplied-llm) for the adapter obligations and the structural and live tiers. For the repository-configured transport, run:

```bash
php bin/llm-conformance.php --structural
php bin/llm-conformance.php
```

The structural command is intended for ordinary CI. The live command reaches the selected model and spends requests, so run it deliberately with the intended billing declaration.
