---
name: site-build
description: Build or resume generated WordPress sites through the current coding-agent subscription using the repository build CLI. Use when an agent needs to create a site, resume an existing project, run a bounded range of build steps, or confirm the subscription-backed LLM transport before spending.
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

## Run the ordinary build CLI

Keep the same matching `SITE_BUILD_LLM` declaration on every build command. The examples below use Codex; replace only the declaration with the exact Claude Code or Grok mapping above when that is the current launcher.

```bash
# Create a project from a prompt.
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" "<site prompt>"

# Resume an existing project from a selected step.
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" --slug=PROJECT_SLUG --from=STEP_ID

# Resume only a bounded step range.
SITE_BUILD_LLM=codex-cli php "$SITE_BUILD_HOME/bin/build.php" --slug=PROJECT_SLUG --from=START_STEP_ID --until=STOP_STEP_ID
```

Replace the uppercase placeholders with the project slug and step IDs for the requested build.

Combine the ordinary create, resume, `--from`, and `--until` forms with other documented `bin/build.php` flags as needed. Keep invoking the CLI through `"$SITE_BUILD_HOME/bin/build.php"`. Stop and report any non-zero build exit; do not silently switch transports.

## Respect harness capabilities

The harness model matrix is authoritative for default and per-step model IDs. Each harness resolves its own provider's model matrix; do not substitute the interactive session model or allow an unpinned harness default.

Every harness transport treats `temperature` and `max_tokens` as unsupported. Their use is disclosed and recorded as a degradation.

Claude honors the `system` option. Codex and Grok cannot honor it, so they disclose and record that degradation.
