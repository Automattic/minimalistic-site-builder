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

## Generated-content validation: repair, warn, and deliver

`warnings.json` is the shared record of non-fatal defects in output the build still delivered. For mutating deterministic repair or serialization steps, validation is repair-first: an explicitly reviewed content incompatibility must not withhold the entire generated site when a deterministic, semantics-safe usable fallback exists.

1. Attempt a bounded, semantics-safe deterministic repair first. Successful repairs should be represented in the step/fixer report and covered by a regression test.
2. If the exact defect signature and fallback have been reviewed, but the fallback cannot preserve every authored value, deliver the usable fallback, emit a typed repair/report row, record an actionable defect with `Project::addWarnings(<step-id>, ...)` in the project-root `warnings.json`, write the detailed evidence to the step log, and continue the build.
3. Any step that can add durable warnings must declare `warnings.json` in `StepDeclaration::writes`.
4. Do not treat an arbitrary validator exception as permission for a mutating step to ship unchecked transformations. Unknown or unreviewed signatures, malformed block structure or CSS, unsupported registered blocks/support shapes, transformations with possible content loss, and non-convergence remain fatal, as do I/O failures, missing required inputs, corrupt artifacts, and programming invariants.
5. Advisory validators that inspect an already usable final artifact without mutating it (for example, `ValidateThemeStep`) may record residual problems as warnings and deliver the artifact; make that non-mutating boundary explicit.
6. Tests for mutating repair steps must cover both sides of the boundary: the exact reviewed degradation must retain content, reach a fixed point, continue the build, and write actionable file/block/value/disposition context to `warnings.json`; representative near misses and unsupported inputs must still throw without partial writes.

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
