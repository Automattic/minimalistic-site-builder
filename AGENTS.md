# AGENTS.md

## Issue tracking: Linear, not GitHub Issues

Issues for this repo are tracked in the Linear project **[Generated themes: replace assembler in Big Sky](https://linear.app/a8c/project/generated-themes-replace-assembler-in-big-sky-ea75fac8fa1f/overview)** (a8c workspace, issue keys like `BIGR-644`). GitHub Issues on this repo are deprecated — do not open new ones.

- **Creating issues:** create them in that Linear project (via the Linear MCP tools or the web UI), never as GitHub issues.
- **Branches:** include the Linear issue key in the branch name, e.g. `feat/bigr-644-stateless-generation-units`.
- **PRs:** reference the issue so Linear auto-links the PR — put the key in the PR title (e.g. `... (BIGR-644)`) and include a `Fixes BIGR-XXX` line plus the full Linear issue URL in the PR description.
- **Status:** move the Linear issue to *In Progress* when work starts; the PR merge closes it via the `Fixes` magic word.
- **Linear MCP setup (one-time):** Linear's official MCP server is `https://mcp.linear.app/mcp` (HTTP transport, OAuth). In Claude Code: `claude mcp add --transport http --scope user linear https://mcp.linear.app/mcp`, then run `/mcp` in a fresh session and pick **Authenticate** — the OAuth step is interactive, so ask the user to complete it in the browser.

## Backwards compatibility

We don't need to plan for backwards compatibility. This is a green field project in an early dev stage — there are no external consumers or stored data to preserve, so prefer the cleanest design and feel free to make breaking changes without migration paths or compatibility shims.

## Generated-content validation: repair, record, and always deliver

`warnings.json` is the central issue list for every generated-content defect or processing failure that the build delivered through. Site generation must not fail solely because generated content is malformed, unsupported, non-converging, or cannot be repaired safely. A future AI fixer may consume this list, so every entry must be structured and actionable.

1. Attempt a bounded, semantics-safe deterministic repair first. Successful repairs must be represented in the step/fixer report and covered by a regression test.
2. If a safe repair is not available — including for an unknown or unreviewed signature, malformed block structure or CSS, an unsupported registered block or support shape, possible content loss, non-convergence, or a validator exception — restore the smallest affected block or file to its exact bytes from before the step, record the problem, and continue the build. Never ship a partial or unchecked transformation.
3. Isolate mutating work transactionally. Snapshot a file before any repair or normalization in the step, stage complete replacements, and commit only successful files. If one file cannot be processed, keep its snapshot and still deliver every other file.
4. Emit a typed repair/report row, call `Project::addWarnings(<step-id>, ...)`, and write detailed evidence to the step log. Each warning must include enough context for an automated fixer: file, block path when known, relevant value or signature, error category and message, and the final disposition such as `repaired`, `kept-as-generated`, or `file-rollback`.
5. Any step that can add durable warnings must declare `warnings.json` in `StepDeclaration::writes`. Repeated passes must deduplicate the same unresolved issue and must not describe it as fixed.
6. A programming exception is not automatically build-fatal when the step can prove that it restored the affected content and can still deliver a coherent artifact; record it as an internal processing issue and continue. Fail the build only when safe delivery is impossible, such as an unreadable required input, corrupt project state, failed write/stage/commit, or failed rollback.
7. Advisory validators that inspect an already usable final artifact without mutating it (for example, `ValidateThemeStep`) should record residual problems in `warnings.json` and deliver the artifact.
8. Tests for mutating steps must cover repaired and unresolved paths. An unresolved case must retain the affected content exactly, continue the build, avoid partial writes, and add actionable context to `warnings.json`. Representative near misses and unsupported inputs must exercise this rollback-and-warning path rather than crash the site generation.

## Posting screenshots / images in PR & issue comments

Verification screenshots and other throwaway proof belong in **PR/issue comments, not committed to trunk or the PR branch** — they bloat git history. Only commit lightweight, reproducible fixtures (the HTML/script that regenerates the evidence), never the generated PNGs.

GitHub has **no API or `gh` command to upload true comment attachments** — the `github.com/user-attachments/...` URLs are only produced by drag-and-drop in the web UI. To embed an image programmatically, host it in a **gist** (keeps the evidence entirely out of the repo) and reference its raw URL:

1. `gh gist create` rejects binary files, so seed the gist with a text file, then `git push` the image into it (gists are git repos). Gist raw URLs serve the correct `image/png` content-type, so they render inline in comments:
   ```bash
   echo "# Evidence for #31" > README.md
   id=$(basename "$(gh gist create README.md --desc 'Evidence for #31')")
   git clone "https://gist.github.com/$id.git" /tmp/evgist
   cp before.png after.png /tmp/evgist/
   git -C /tmp/evgist add -A && git -C /tmp/evgist commit -qm "screenshots" && git -C /tmp/evgist push -q
   login=$(gh api user -q .login)
   echo "https://gist.githubusercontent.com/$login/$id/raw/before.png"
   ```
2. Reference the raw URL(s) in the comment or description markdown:
   ```markdown
   ![Before](https://gist.githubusercontent.com/<login>/<id>/raw/before.png)
   ```
3. Post with `gh pr comment <n> --body "…"` (add `--edit-last` to update it), `gh issue comment <n> --body "…"`, or `gh pr edit <n> --body "…"`.

If the user prefers genuine GitHub-hosted attachments, ask them to drag the images into the comment via the web UI instead.
