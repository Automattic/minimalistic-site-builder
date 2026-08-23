---
name: site-build
description: Build or resume generated WordPress sites through the current coding-agent subscription using the repository build CLI. Use when an agent needs to create a site, resume an existing project, run a bounded range of build steps, or confirm the subscription-backed LLM transport before spending.
---

# Build a site through the current harness

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
SITE_BUILD_LLM=claude-cli php bin/build.php --transport

# Codex
SITE_BUILD_LLM=codex-cli php bin/build.php --transport

# Grok
SITE_BUILD_LLM=grok-cli php bin/build.php --transport
```

Use only the command for the current launcher. Confirm that it exits successfully and that its audit line names the intended `*-cli` transport as a subscription. If it exits non-zero or reports a different transport or billing mode, stop before spending and report the mismatch.

## Run the ordinary build CLI

Keep the same matching `SITE_BUILD_LLM` declaration on every build command. The examples below use Codex; replace only the declaration with the exact Claude Code or Grok mapping above when that is the current launcher.

```bash
# Create a project from a prompt.
SITE_BUILD_LLM=codex-cli php bin/build.php "<site prompt>"

# Resume an existing project from a selected step.
SITE_BUILD_LLM=codex-cli php bin/build.php --slug=PROJECT_SLUG --from=STEP_ID

# Resume only a bounded step range.
SITE_BUILD_LLM=codex-cli php bin/build.php --slug=PROJECT_SLUG --from=START_STEP_ID --until=STOP_STEP_ID
```

Replace the uppercase placeholders with the project slug and step IDs for the requested build.

Combine the ordinary create, resume, `--from`, and `--until` forms with other documented `bin/build.php` flags as needed. Stop and report any non-zero build exit; do not silently switch transports.

## Respect harness capabilities

The harness model matrix is authoritative for default and per-step model IDs. Each harness resolves its own provider's model matrix; do not substitute the interactive session model or allow an unpinned harness default.

Every harness transport treats `temperature` and `max_tokens` as unsupported. Their use is disclosed and recorded as a degradation.

Claude honors the `system` option. Codex and Grok cannot honor it, so they disclose and record that degradation.
