# AGENTS.md

## Backwards compatibility

We don't need to plan for backwards compatibility. This is a green field project in an early dev stage — there are no external consumers or stored data to preserve, so prefer the cleanest design and feel free to make breaking changes without migration paths or compatibility shims.

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
