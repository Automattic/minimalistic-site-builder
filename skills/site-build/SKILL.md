---
name: site-build
description: Orchestrate generated WordPress site builds step by step through the current coding-agent subscription. Use when an agent needs to create or resume a site, show build progress between tool calls, run a bounded range of steps, or confirm the subscription-backed LLM transport before spending.
---

# Build a site through the current harness

Resolve the site-builder repository before running any command. Copy and run this snippet from the directory where you want to work:

```bash
if [ -n "${SITE_BUILD_HOME:-}" ] && [ -r "$SITE_BUILD_HOME/bin/build.php" ]; then
    :
elif [ -n "${CLAUDE_PLUGIN_ROOT:-}" ] && [ -r "$CLAUDE_PLUGIN_ROOT/bin/build.php" ]; then
    SITE_BUILD_HOME=$CLAUDE_PLUGIN_ROOT
elif [ -n "${GROK_PLUGIN_ROOT:-}" ] && [ -r "$GROK_PLUGIN_ROOT/bin/build.php" ]; then
    SITE_BUILD_HOME=$GROK_PLUGIN_ROOT
elif [ -n "${CODEX_PLUGIN_ROOT:-}" ] && [ -r "$CODEX_PLUGIN_ROOT/bin/build.php" ]; then
    SITE_BUILD_HOME=$CODEX_PLUGIN_ROOT
else
    SITE_BUILD_HOME=
    site_build_dir=$PWD
    while :; do
        if [ -r "$site_build_dir/bin/build.php" ]; then
            SITE_BUILD_HOME=$site_build_dir
            break
        fi
        [ "$site_build_dir" = "/" ] && break
        site_build_dir=$(dirname "$site_build_dir")
    done
    unset site_build_dir
fi

if [ -z "${SITE_BUILD_HOME:-}" ] || [ ! -r "$SITE_BUILD_HOME/bin/build.php" ]; then
    echo "Could not find site-builder. Set SITE_BUILD_HOME to its repository root." >&2
    return 1 2>/dev/null || exit 1
fi
export SITE_BUILD_HOME
```

`SITE_BUILD_HOME` is this Skill's explicit override. Anthropic documents `CLAUDE_PLUGIN_ROOT`. xAI documents `GROK_PLUGIN_ROOT` for plugin hooks, but does not explicitly promise it to skills. `CODEX_PLUGIN_ROOT` is a speculative compatibility probe because Codex's plugin documentation does not publish a root variable. Every candidate is accepted only when it contains `bin/build.php`, so unset or incorrect values safely fall through.

The build CLI also requires this repository's `config/` directory and Composer-installed `vendor/` directory. If `vendor/autoload.php` is missing from the resolved plugin checkout, run `composer install --working-dir="$SITE_BUILD_HOME"` before building. A plugin installation without those runtime files cannot build a site.

Always declare the transport that matches the launcher. Never rely on environment or process detection.

| Launcher | Required declaration |
| --- | --- |
| Claude Code | `SITE_BUILD_LLM=claude-cli` |
| Codex | `SITE_BUILD_LLM=codex-cli` |
| Grok | `SITE_BUILD_LLM=grok-cli` |

## Confirm the transport before spending

Run the matching command before every build:

```bash
# Claude Code
SITE_BUILD_LLM=claude-cli php "$SITE_BUILD_HOME/bin/build.php" --transport

# Codex
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" --transport

# Grok
SITE_BUILD_LLM=grok-cli php "$SITE_BUILD_HOME/bin/build.php" --transport
```

Use only the command for the current launcher. Confirm that it exits successfully and that its audit line names the intended `*-cli` transport as a subscription. If it exits non-zero or reports a different transport or billing mode, stop before spending and report the mismatch.

## Image behavior in harness builds

Harness and plugin transports do not provide WPCOM proxy credentials. Absent an independently provisioned `GOOGLE_VERTEX_API_TOKEN`, harness builds ship image placeholders, and this Skill never passes `--with-images`. The Skill cannot assume that a checkout has been provisioned with that token.

Neither the Codex nor the Grok CLI exposes image generation, so there is no subscription-billed alternative today. This is a current limitation, not a defect.

## Orchestrate a build step by step

Keep the same matching `SITE_BUILD_LLM` declaration on every command. The examples below use Codex; replace only that declaration with the exact Claude Code or Grok mapping above when that is the current launcher.

Choose the project slug and graph before enumeration. Use `--blocks-first` unless the request explicitly calls for the HTML-first graph. Keep the same graph flag on the enumeration and create commands.

### 1. Enumerate the selected graph

Run enumeration as its own tool call. It requires no prompt, makes no model call, and returns JSON on stdout:

```bash
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" --list-steps --slug=PROJECT_SLUG --blocks-first
```

Parse the JSON object. Its `graph` field identifies the selected graph. Its ordered `steps` array contains objects with `id`, `label`, and `members` fields. Let `M` be the number of entries.

A concurrent group is one top-level step. Its composite `id` is the value to run. Its `members` array is informational only; never turn those members into separate calls. Calling `--step` once per member runs the whole group once per member, repeats the complete batched model spend, and overwrites the first run's artifacts.

### 2. Create the project through the first step

Take the first object in `steps`. Run the create command as one tool call, using its `id` as the inclusive stop:

```bash
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" "<site prompt>" --slug=PROJECT_SLUG --blocks-first --multi-page --until=FIRST_STEP_ID
```

`--multi-page` is a creation setting. The create call records it in `meta.json`, and every later `--step` call opens that project and inherits the recorded value. Do not repeat `--multi-page` on per-step calls, and do not add `--pages`; page selection remains the caller's choice.

`--from` and `--until` are inclusive. Matching values therefore run exactly one ordinary step or one complete concurrent group.

After the command exits successfully, report:

```text
step 1 of M: <first step label> — succeeded
```

### 3. Run every remaining top-level step

For each remaining object in the ordered `steps` array, run one new tool call. Do not put these commands inside one shell loop; separate calls are what make progress visible.

```bash
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" --slug=PROJECT_SLUG --step=STEP_ID
```

After each successful call, report its position and label:

```text
step N of M: <step label> — succeeded
```

The transport audit line appears once per CLI invocation. That repetition is intentional: every step call states its transport and billing mode before it spends. After the step completes, its result row reports timing, token use, and the configured model.

### 4. Stop on the first failure

If any create or step command exits non-zero, do not run later steps. Report `step N of M: <label> — failed`, include the failed command's output, and preserve the project for resumption.

The project can resume from the failed top-level ID because `--from` is inclusive:

```bash
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" --slug=PROJECT_SLUG --from=FAILED_STEP_ID
```

For a progress-visible resume, enumerate the recorded project again, find `FAILED_STEP_ID`, and restart the separate `--step` calls at that entry. Never silently continue after a failure or switch transports.

## Run the whole build in one call

Use the single-shot form only when the caller does not need progress between steps:

```bash
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" "<site prompt>" --slug=PROJECT_SLUG --blocks-first --multi-page
```

The ordinary resume and bounded-range forms remain available for non-interactive use:

```bash
# Resume an existing project from a selected step through the end.
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" --slug=PROJECT_SLUG --from=STEP_ID

# Resume only an inclusive bounded range.
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" --slug=PROJECT_SLUG --from=START_STEP_ID --until=STOP_STEP_ID
```

Replace every uppercase placeholder with the chosen slug or an exact ID from `--list-steps`. Combine these forms with other documented `bin/build.php` flags as needed. Keep invoking the CLI through `"$SITE_BUILD_HOME/bin/build.php"`.

## Respect harness capabilities

The harness model matrix is authoritative for default and per-step model IDs. Each harness resolves its own provider's model matrix; do not substitute the interactive session model or allow an unpinned harness default.

Every harness transport treats `temperature` and `max_tokens` as unsupported. Their use is disclosed and recorded as a degradation.

Claude honors the `system` option. Codex and Grok cannot honor it, so they disclose and record that degradation.
