# Transport resolution — choosing the `Llm` per session

## The problem

A build can be triggered many ways — the CLI, Studio, or a request *from inside a coding agent* (Claude Code, Codex, OpenCode, pi.dev). Something has to decide **which `Llm` to inject** into the pipeline, and therefore into every step. A step never chooses its transport; it calls the injected `$this->llm`. So correctness reduces to one thing: **the entry point resolves the right transport once and injects it everywhere.** `SiteBuilder` takes that single `Llm` in its constructor and hands it to every step and the `ConcurrentGroup`, so there is no per-step drift — the whole build shares one transport (only the *model* varies per step, via `StepDefaults` / model overrides).

## Principle: declared, not detected — but context-aware

The transport is always **declared** (constructed by the entry point, injected via `SiteBuilder`); the core never sniffs for it. When a build is launched from inside an agent, the entry point may **resolve** that declaration from the environment. Detection serves declaration: it picks *which* transport to construct; injection still delivers it. See `teach/lessons/0012` (declared, not detected) and `0013` (this resolution step).

## How we know the context: the trigger leaves a fingerprint

An agent triggers a build by spawning the entry point **as a child process**, and the child inherits the agent's environment. That inherited environment *is* the signal — there is no other reliable channel. (If the build were a long-lived MCP server / daemon instead of a subprocess, it would inherit nothing, and intent would have to arrive as an explicit argument.)

## The resolution ladder

Resolved once, at the entry point, in this order:

```
1. SITE_BUILD_LLM=api|claude-cli|codex-cli|…   explicit intent      → validate binary/creds, else fail
2. ANTHROPIC_API_KEY / proxy creds present     fast + metered        → AnthropicClient
3. env fingerprint (CLAUDECODE=1 → Claude Code) "inside harness X"   → that harness's CLI transport
4. process ancestry (walk parent PIDs)          "spawned by harness X" → that harness's CLI transport
5. exactly one harness CLI on PATH              last-resort selector  → use it (log it); 2+ → refuse
6. else                                          nothing usable        → hard error, name what's missing
```

- Steps 3–5 answer **which** harness; binary-existence (step 5, and validation at every tier) answers **can I run it, and where**.
- **Fail loud. Never guess a billing path.** If a tier selects a transport whose binary or credentials are absent, error immediately — do not silently fall through to a different billing boundary.
- **Echo the resolved transport before any spend** (`Transport: claude -p via $CLAUDE_CODE_EXECPATH (subscription)`); because it is resolved once and injected everywhere, that line *is* what every step uses.

```php
function resolve_transport(array $models): Llm
{
    $override = Env::get('SITE_BUILD_LLM', '');
    if ($override === 'api')        return api_transport($models);
    if ($override === 'claude-cli') return claude_cli_transport($models);
    if ($override === 'codex-cli')  return codex_cli_transport($models);

    if (getenv('ANTHROPIC_API_KEY')) return api_transport($models);          // fast, metered

    // env fingerprints — EXACT-VALUE comparison (the tools disagree on the value)
    if (getenv('CLAUDECODE') === '1')         return claude_cli_transport($models);  // scrubs the key
    if (getenv('OPENCODE') === '1')           return opencode_transport($models);
    if (getenv('PI_CODING_AGENT') === 'true') return pi_transport($models);          // string "true", not 1
    if (getenv('CODEX_SANDBOX_NETWORK_DISABLED') !== false
        || getenv('CODEX_THREAD_ID') !== false) return codex_cli_transport($models); // sandbox-gated

    // then process-ancestry (match argv against codex|opencode|pi|claude — the ONLY
    // reliable Codex signal under danger-full-access), then single-binary-on-PATH …

    throw new \RuntimeException(
        "No LLM transport. Set ANTHROPIC_API_KEY, or SITE_BUILD_LLM=claude-cli (inside Claude Code) / codex-cli."
    );
}

/** Absolute path to an executable on PATH, or null. */
function binary_path(string $name): ?string {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        $p = rtrim($dir, '/') . '/' . $name;
        if (is_file($p) && is_executable($p)) return $p;
    }
    return null;
}
```

## The billing policy knob

Inside Claude Code **with** a key present is ambiguous intent: fast-metered vs slow-subscription. The default above is **fast** (step 2 beats step 3); subscription is opt-in via `SITE_BUILD_LLM=claude-cli`. Rationale: performance is a priority, and — per the landmine below — `claude -p` bills the key anyway when one is present, so subscription *requires* scrubbing the key, which is inherently an explicit act. Flip step 2/3 precedence if you'd rather default to subscription.

## The auth landmine (do not skip)

In Claude Code's non-interactive `-p` mode, **`ANTHROPIC_API_KEY` overrides the subscription** — "the key is always used when present" ([auth docs](https://code.claude.com/docs/en/authentication.md)). So shelling out to `claude -p` **silently bills the API key** if one is in the environment. To actually spend the subscription, the shell-out transport must **remove `ANTHROPIC_API_KEY` / `ANTHROPIC_AUTH_TOKEN` from the child process env** (or inject `CLAUDE_CODE_OAUTH_TOKEN`). This is the single most error-prone part of the whole design.

## The shell-out transport, constrained

`claude -p` is a full agent; as a transport it must be pinned to a dumb completion:

| Need | Claude Code | Codex |
| --- | --- | --- |
| No local config bleed | `--bare` | — |
| One completion, no loop | `--max-turns 1` | (no equivalent) |
| No tool use / sandbox | `--tools ""` | `--sandbox read-only --ask-for-approval never` |
| Structured output | `--output-format json` / `--json-schema` | `--json` / `--output-schema` |
| Pin the step's model | `--model <id>` (preserves `step_models()`) | *(verify)* |
| **Force subscription billing** | **strip `ANTHROPIC_API_KEY` from child env** | auth from `codex login`; `CODEX_API_KEY` to override |

Nesting `claude -p` inside an interactive Claude Code session is supported (`CLAUDE_CODE_CHILD_SESSION=1`, excluded from `--resume`/history); auth inherits the logged-in session once the keys are scrubbed.

## Detection signals per harness

| Harness | Env marker (exact) | Always-on? | Process name | Binary |
| --- | --- | --- | --- | --- |
| **Claude Code** | `CLAUDECODE=1` (+ `CLAUDE_CODE_EXECPATH` = the exact binary) | yes | `claude` | `claude` |
| **Codex** | `CODEX_SANDBOX_NETWORK_DISABLED=1`, `CODEX_THREAD_ID`, `CODEX_SANDBOX=seatbelt` (macOS) | **no — sandbox-gated; none under `danger-full-access`** | `codex` (native Rust binary) | `codex` |
| **OpenCode** | `OPENCODE=1` (+ `OPENCODE_PID`) | yes (current HEAD; historically toggled) | `opencode` | `opencode` |
| **pi.dev** | `PI_CODING_AGENT=true` (string `"true"`, **not** `1`) | yes | `pi` (Linux) / `node` (macOS) | `pi` |

Confirmed against each tool's source at HEAD (2026-07-02): Codex `codex-rs/core/src/spawn.rs` + `sandboxing/mod.rs`; OpenCode `packages/opencode/src/index.ts:75-77` (github.com/anomalyco/opencode — `sst/opencode` redirects there); pi `packages/coding-agent/src/cli.ts:13` (github.com/earendil-works/pi); Claude Code from the live subprocess env + [env-vars docs](https://code.claude.com/docs/en/env-vars.md).

**Two facts that shape the code:** (1) **Exact-value comparison** — the tools disagree on the value (`1`, `1`, `1`, and the string `"true"`), so `getenv(...) === '1'` is right for three and wrong for pi. (2) **Codex is not reliably env-detectable** — its markers are sandbox-gated and vanish under `danger-full-access`, so an ancestor process named **`codex`** (its native binary, present in every install path) is Codex's real primary signal. Ancestry (step 4) isn't just the exotic-harness fallback; it's load-bearing for Codex.

**Ceiling:** auto-detection is best-effort with a guaranteed fallback. A harness with no env marker *and* an unrecognizable process name is not auto-detectable and must use `SITE_BUILD_LLM=`.

## MCP sampling: evaluated and rejected

The alternative topology — the builder runs as an MCP *server* and borrows the harness's model via MCP `sampling/createMessage` — was considered and **rejected**:

- **No target harness implements it** today: Claude Code ([#1785](https://github.com/anthropics/claude-code/issues/1785), open), Codex ([#4929](https://github.com/openai/codex/issues/4929)), OpenCode all lack it; pi.dev is text-only.
- **Human-in-the-loop by spec** ("there SHOULD always be a human in the loop") — fatal for a 10+ section fan-out.
- **Server gets model *hints* only** — the client picks the model, so `step_models()` tuning is lost.
- **Reportedly being deprecated** in favor of multi round-trip requests (directional).

Revisit only if #1785 ships — and bench it before adopting. See `teach/learning-records/0005`.

## Performance framing

- **Fast tiers:** direct API (`curl_multi`; Anthropic rolls 10 requests in flight, while OpenAI-compatible transports use provider-specific windows) and **wpcom-native** (25 server promises + SSE). Full per-step model control.
- **Cost / convenience tier:** harness shell-out. Each `claude -p` boots the *full agent* per call — structurally slower than a socket, even with `--bare`. Chosen to spend a subscription, not for speed. Set expectations accordingly.

## Proving correctness

Because the `Llm` is injected: (a) the startup echo line names the transport + endpoint for production audit; (b) tests inject `FakeLlm` and assert. The guarantee is structural (resolve once, inject everywhere) plus logged — not runtime magic.
