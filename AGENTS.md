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

## Generation step maps

Default blocks graph (`StepComposition::default()` → `StepComposition::blocks()`), where the model authors block markup directly:

`scaffold-theme -> scaffold-plugin -> refine-prompt -> site-spec -> apply-identity -> design-direction -> (theme-json + page-plan, concurrent) -> reconcile-palette -> sections -> section-rhythm -> copy-dedupe -> collect-images -> normalize-layout -> header-hero -> contrast-fix -> motion-sanity -> fix-blocks -> assemble-pages -> page-styles -> custom-motion -> bundle-fonts -> fonts-php -> finalize-theme -> theme-screenshot -> validate-theme`

Image generation is slow and networked, so it is in neither graph. The steps that depend on the real pixels are named once, in `StepComposition::postImages()`, and every entry point runs that list after the graph:

`generate-images -> theme-screenshot -> cover-contrast`

A host that generates images must run that phase too. Mirroring only the graph ships a theme whose cover text was checked against images that did not exist yet, and whose preview card is the palette poster `theme-screenshot` drew in-pipeline rather than the site's own hero.

Pass `--html-first` (or set `SITE_BUILD_HTML_FIRST=1`) for the HTML-first graph (`StepComposition::htmlFirst()`), where the model authors an HTML+CSS design that `transform-site` converts to blocks:

`scaffold-theme -> scaffold-plugin -> refine-prompt -> site-spec -> apply-identity -> design-direction -> design-preview -> theme-json -> inner-pages-design -> splice-home-design -> assign-image-sources -> transform-site -> resolve-nav-links -> section-rhythm -> section-layout -> collect-images -> normalize-layout -> header-hero -> contrast-fix -> motion-sanity -> fix-blocks -> assemble-pages -> fix-pages -> page-styles -> custom-motion -> fonts-php -> finalize-theme -> theme-screenshot -> validate-theme`

On that path `theme-json` reads CSS-derived design tokens. `assign-image-sources` gives every design `<img>` the theme asset path the rest of the image pipeline generates into. `contrast-fix` and `motion-sanity` stay addressable but skip only in explicit HTML-first composition mode. `normalize-layout`, `fix-blocks`, and `validate-theme` skip the width rules that assume the theme owns page width — here the carried design CSS does. `page-styles` scrubs and merges generated CSS, then runs `CssContrastCheck` and applies safe tail-only adjustments against delivered markup. Stale `design/site.css` bytes never select pipeline behavior.

`createProject()` records the chosen graph in `meta.json` as `graph: html-first|blocks`, and `--from` reads it back so a resume runs the graph that built the project. A flag contradicting the record is refused, not honored — `section-rhythm`, `collect-images` and friends exist in both graphs, so a crossed resume would otherwise read artifacts the other path never wrote and still look like it worked. A project with no recorded graph (built before this landed) falls back to the flag/env choice. A host driving a fixed `StepComposition` must pass `htmlFirst:` to `createProject()`, since the default reads the env key that host never consults.

`bin/build.php` and `bin/build-demos.php` take `--html-first` and `--blocks-first`. Either one sets `SITE_BUILD_HTML_FIRST` for the process, so an explicit flag always beats a shell export or an `.env` line; passing neither leaves that env var in charge. The flags are mutually exclusive. `StepComposition::htmlFirstSelected()` remains the single reader of the key, so nothing has to be taught the choice twice.

Only the HTML-first graph is wrapped in `FallbackBuildPipeline`; the default blocks graph generates no design document, so it is an unwrapped `Pipeline`. The wrapper's whole-build reroute is currently dormant: it only recognizes a `MalformedDesignException` from the retired `homepage-design` step, which no composition contains. Do not rely on it until that trigger is retargeted or removed.

For mixed multi-page HTML-first builds, each `design/<slug>.failed` marker routes only that slug through scoped blocks-path page planning and section generation. Other pages and shared transformed chrome stay on the HTML-first path; `page-styles` ignores failed-page source HTML.

## Generated-content validation: fix, degrade, warn — never crash the build

Every validator and fixer in this pipeline exists because an LLM produced imperfect content. **A defect in generated content must never abort the build.** The user asked for a site; shipping a site with a slightly wrong margin, a missing decorative section, or a dropped inline style is always better than shipping nothing after paying for every LLM call in the graph.

`warnings.json` (written via `Project::addWarnings(<step-id>, ...)`, read by the future repair pass, BIGR-722) is the durable, machine-readable record of every defect the build chose to deliver through.

### The escalation ladder

Apply these in order. Only fall to the next rung when the one above genuinely cannot apply.

1. **Fix it deterministically.** Best effort, bounded, semantics-safe, idempotent (a repair pass must reach a fixed point). This is the default and covers most rules: canonicalizing preset references, mirroring HTML-only declarations into comment attributes, swapping a failing `textColor`, migrating a block through a reviewed deprecation, synthesizing missing closers for a truncated response. Successful repairs go in the step/fixer report and get a regression test. No warning is needed — nothing was lost.
2. **Leave harmless defects alone.** If the defect has no effect on what the visitor sees or on WordPress's ability to parse and render the markup, do not touch it and do not warn. Inert values (an unexpanded spacing slug that never rendered), cosmetic non-canonical spellings, and extra attributes WordPress ignores are noise, not defects. Suppressing this class is a feature: `warnings.json` is only useful if every row in it is actionable.
3. **Remove the smallest non-harmless part you cannot fix.** When something is actively harmful — unsafe (`<script>`, `on*` handlers, `javascript:` URLs), over-budget, unsupported by the frozen block domain, structurally broken, or dead UI — excise it and keep the rest. Always cut at the *smallest* unit that isolates the defect, escalating only as far as needed: one CSS declaration → one attribute → one block → one section part → one page. Removing a decorative section is acceptable; removing the whole theme is not. Record the removal per rung 4.
4. **Warn and continue.** Any defect that survives rungs 1–3 — including every removal from rung 3 and every fallback that could not preserve an authored value — is recorded with `Project::addWarnings()` and the build continues. A warning row must be actionable on its own: file, block path, the authored value, the delivered value (or `removed`), and the disposition. Detailed evidence goes to the step log; the *actionable summary* goes in `warnings.json`. Narration or a step log alone is **not** sufficient for a defect that changed the delivered output.
5. **(Future) hand it to an AI fixer.** `warnings.json` is the queue for a later LLM repair pass. Write rows with that consumer in mind — enough context to locate and fix the defect without re-running the build.

### What may still be fatal

Only conditions that are not about generated content, and where no partial output is meaningful:

- I/O failures (unreadable/unwritable paths, failed atomic replace, failed staging).
- Missing or corrupt **build inputs and artifacts** the step is contractually given (`meta.json` has no prompt; a required upstream artifact is absent or is not valid JSON).
- Programming invariants: `StepGraph`/`StepDeclaration` validation, unknown step ids, misconfigured environment overrides. These are our bugs, not the model's.

Everything else — unknown or unreviewed block signatures, malformed block structure or CSS, unsupported registered blocks and support shapes, non-convergence of a fixed-point pass, a transformation that would lose content, a plan that violates its own contract — must degrade under rungs 1–4 rather than throw. When a step cannot safely transform a file, prefer delivering that file's **pre-transformation** bytes with a warning over aborting the run. A repair step may still throw *internally* to abandon one file, block, or unit, but the step boundary must catch it, isolate the loss, warn, and continue.

### Mechanics

- Any step that can add durable warnings must declare `warnings.json` in `StepDeclaration::writes`.
- Mutating repair steps stay transactional at the *unit* they isolate: never leave a half-written file or a half-normalized page. Snapshot and restore the unit rather than the whole run.
- Advisory validators that inspect an already-usable final artifact without mutating it (for example `ValidateThemeStep`) record residual problems as warnings and deliver the artifact. Make that non-mutating boundary explicit in the class docblock.
- Tests for repair steps cover both sides of every boundary: the reviewed degradation must retain the surviving content, reach a fixed point, continue the build, and write actionable file/block/value/disposition context to `warnings.json`; and the isolated-loss path must be shown to cut only the intended unit, leaving siblings byte-for-byte intact.
- Live narration goes through `Narrator::write()`, never `fwrite(STDERR, …)`. `STDERR` is a constant bound to a stream resource, and embedding hosts break both halves of that: a non-CLI SAPI never defines it, and a long-lived CLI worker may close its standard streams after startup, leaving the constant defined but holding a dead handle — which fails a `defined()` guard's check and fatals on write. `Narrator` re-resolves whenever its target stops being a valid resource, so narration cannot take a build down. A host that wants the narration somewhere specific calls `Narrator::setStream()` once.

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
