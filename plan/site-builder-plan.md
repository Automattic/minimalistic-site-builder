# Minimalistic WordPress Site Builder — Plan

A no-agentic-loop builder. Each website element is produced by a single,
isolated LLM call driven by a **dynamic prompt template** (placeholders filled
at call time). All output is persisted as local files. The flow is incremental:
one shot per element, re-runnable independently.

## Core principles

- **One call = one element.** No loops, no tool use, no back-and-forth.
- **Dynamic prompts.** Each step has a prompt template with `{{placeholders}}`
  resolved from prior steps' saved output + the initial user prompt.
- **File-first storage.** Every LLM response is saved as JSON under the project
  folder. Files are the single source of truth between steps.
- **PHP for all API calls.** A thin client that takes a resolved prompt and
  returns parsed JSON.

## Directory layout (target)

```
builder/
  src/
    LlmClient.php          # POST to LLM endpoint, return decoded JSON
    PromptRenderer.php     # load template + fill {{placeholders}}
    ProjectStore.php       # read/write project files, slug handling
    Steps.php              # registry of build steps (name -> template + output file)
  prompts/
    project-meta.txt       # produces { name, slug, description }
    site-structure.txt     # produces pages/sections list
    page-content.txt       # produces content for one page
    ...
  projects/
    <slug>/
      project.json         # name, slug, source prompt, created date
      structure.json
      pages/<page-slug>.json
  run.php                  # CLI/entry: run a given step for a given project
```

## Steps to build

### 1. Project skeleton & storage
1. Create the folder structure above.
2. `ProjectStore.php`: create a project from the initial prompt — generate a
   filesystem-safe **slug**, create `projects/<slug>/`, write `project.json`.
3. Helpers to read/write JSON files by step name.

### 2. LLM client (PHP)
4. `LlmClient.php`: single `complete(string $prompt): array` method.
   - Reads endpoint URL + API key from env/config.
   - Sends the prompt, expects a JSON response, decodes and returns it.
   - Minimal error handling (HTTP error, invalid JSON) — fail loud, no retry loop.

### 3. Dynamic prompt rendering
5. `PromptRenderer.php`: load a `prompts/<step>.md` template and replace
   `{{placeholder}}` tokens from a context array.
   - Context = initial prompt + already-saved step outputs.
   - Enforce that every required placeholder resolves (else error).

### 4. Step registry
6. `Steps.php`: declare each step as `{ id, promptFile, inputs[], outputFile }`.
   - `inputs[]` lists which saved files/fields feed the placeholders.
   - Keeps steps decoupled and individually runnable.

### 5. First step — project meta from the user prompt
7. Prompt `project-meta.txt`: input `{{user_prompt}}` → output JSON
   `{ name, slug, description }`.
8. `run.php meta "<user prompt>"` → creates the project and saves `project.json`.

### 6. Subsequent site elements (each its own one-shot step)
9. **Site structure** — input project meta → output list of pages/sections.
10. **Per-page content** — input structure + one page → output page content JSON.
11. (Later) theme/colors, navigation, copy blocks — each a new prompt + step.

### 7. Entry point / runner
12. `run.php`: `php run.php <step> <slug|prompt>` — resolves context, renders the
    prompt, calls the LLM, saves the output file. Re-runnable per step.

### 8. (Deferred) WordPress output
13. Map saved JSON → WordPress (REST API / WP-CLI / block markup). Out of scope
    for the first pass; files stay format-agnostic so this stays a clean later step.

## Build order (suggested)

1. Folder structure + `ProjectStore` (slug + project.json)
2. `LlmClient`
3. `PromptRenderer`
4. `Steps` registry + `run.php`
5. Step 1: project meta (proves the whole loop end-to-end)
6. Add structure + page-content steps incrementally
