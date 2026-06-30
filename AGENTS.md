# AGENTS.md

## Backwards compatibility

We don't need to plan for backwards compatibility. This is a green field project in an early dev stage — there are no external consumers or stored data to preserve, so prefer the cleanest design and feel free to make breaking changes without migration paths or compatibility shims.

## Posting screenshots / images in PR & issue comments

Verification screenshots and other throwaway proof belong in **PR/issue comments, not committed to trunk or the PR branch** — they bloat git history. Only commit lightweight, reproducible fixtures (the HTML/script that regenerates the evidence), never the generated PNGs.

GitHub has **no API or `gh` command to upload true comment attachments** — the `github.com/user-attachments/...` URLs are only produced by drag-and-drop in the web UI. To embed an image programmatically:

1. Push the image(s) to a dedicated branch that is **never merged**, e.g. `evidence/<issue-or-pr-number>`. Use an orphan branch so it carries only the images, not the codebase:
   ```bash
   git checkout --orphan evidence/31
   git reset -q --hard           # clear the index/tree
   cp /path/to/before.png /path/to/after.png .
   git add -f before.png after.png
   git commit -m "Evidence for #31 (not for merge)"
   git push -u origin evidence/31
   git checkout -                # back to your working branch
   ```
2. Reference the images by raw URL in the comment or description markdown (this repo is public, so the URLs render via GitHub's image proxy):
   ```markdown
   ![Before](https://raw.githubusercontent.com/<owner>/<repo>/evidence/31/before.png)
   ```
3. Post with `gh pr comment <n> --body "…"`, `gh issue comment <n> --body "…"`, or `gh pr edit <n> --body "…"`.

If the user prefers genuine GitHub-hosted attachments, ask them to drag the images into the comment via the web UI, then delete the `evidence/*` branch.
