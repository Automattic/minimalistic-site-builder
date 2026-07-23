# One pipeline, four hosts: the portable site-build interface

## What it is

The site builder takes a one-line prompt and turns it into a WordPress block theme. The work splits into two kinds of steps. The deterministic ones scaffold the theme, apply the site's identity, assemble sections into templates, and repair the block markup - plain code that runs the same way every time. The LLM steps make the judgment calls: the site spec, the section plan, the block markup itself.

Between those two kinds of work sits one narrow interface. `Llm` has four methods - send a prompt and get text back, send a prompt and get JSON back, and a concurrent batch version of each:

```php
interface Llm {
    public function complete(string $prompt, array $opts = []): string;
    public function completeJson(string $prompt, array $opts = []): array;
    public function completeBatch(array $requests): array;      // concurrent
    public function completeJsonBatch(array $requests): array;   // concurrent
}
```

That's the whole interface. The deterministic pipeline gets written once and doesn't change from one environment to the next; the LLM steps don't know or care where their completions come from - they call the injected `Llm` and keep going. Each host that runs the builder hands in its own `Llm` implementation, wired to whatever model access that host already has.

## A step can't tell where it's running

The smallest real step in the pipeline reads a file, renders a prompt, asks the model for JSON, and writes a file back.

```php
final class SiteSpecStep implements Step {
    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
    ) {}

    public function id(): string    { return 'site-spec'; }
    public function label(): string { return 'Generate site spec'; }

    public function run(Project $project): void {
        $meta   = $project->readJson('meta.json');         // read a prior artifact from disk
        $prompt = $this->renderer->render('site-spec.md', ['user_prompt' => $meta['prompt']]);
        $spec   = $this->llm->completeJson($prompt);        // the only line that touches a model
        $project->writeJson('siteSpec.json', $spec);        // write its own artifact back to disk
    }
}
```

Look at what the step names: nothing about a provider, a host, an API key, or an endpoint. It leans on two things - the `Llm` interface and the `Project` (files on disk) - and neither one says where it's running. Read `meta.json`, render the prompt, call `completeJson`, write `siteSpec.json`. Swap the model access underneath it and the step doesn't change; it doesn't even know anything moved.

So the one line that differs per host is the `Llm` you hand in. Everything after it is identical (the code below is illustrative - the CLI and wpcom adapters exist today, the Studio and harness ones are planned):

```php
// Command line - direct Anthropic API, key from the environment
$llm = new AnthropicClient(apiKey: getenv('ANTHROPIC_API_KEY'), model: 'claude-opus-4-8');

// wpcom - thin adapter over lib/ai; ambient server credentials, no key
$llm = new WpcomAiLlm(feature: 'block-theme-generator');

// Studio - the same Anthropic API through the WordPress.com proxy + the user's login
$llm = new AnthropicClient(baseUrl: $wpcomProxy, authToken: $studioToken, feature: 'studio-assistant-anthropic');

// Coding-agent harness - shell out to headless mode, spend the subscription
$llm = new ClaudeCliClient(command: 'claude -p');

// identical from here on, in every host:
$builder->pipeline()->runThrough($project);
```

Same step, same pipeline, four different transports, and only that first line moves.

## The four hosts

- **Command line.** Talks straight to the Anthropic API with a key from the environment. It fans section generation out concurrently, so a build finishes in about the time of its slowest call.
- **wpcom.** Two ways in. The simple path drives the pipeline with a thin `lib/ai` adapter (~30 lines, no API key, ambient server credentials through the internal AI proxy), and the completions run one after another. The native path registers the core as a workflow under wpcom's WP Orchestrator agent, which fans the per-section work out as concurrent server-side promises and streams results back over SSE - the pattern the shipping Big Sky builder uses.
- **Studio.** The local-dev app reaches the same Anthropic Messages API through the WordPress.com AI proxy, authenticated with the user's existing WordPress.com login. The CLI's HTTP client works as-is - swap the base URL and the auth header, keep the concurrent fan-out.
- **A coding-agent harness.** Claude Code, Codex, OpenCode, or pi.dev. Instead of a metered API key, the builder shells out to the harness's own headless mode (`claude -p`, `codex exec`) and spends the developer's flat subscription. Which harness is in play is resolved from the environment the agent leaves in the build's subprocess, and spending that subscription has a real auth pitfall - both covered in [Transport resolution](transport-resolution.md).

> **Architecture note (transport resolution).** When a build is triggered from *inside* a coding agent, the harness in play is resolved from the environment the agent leaves in the build's subprocess (`CLAUDECODE=1`, `OPENCODE=1`, `PI_CODING_AGENT=true`; Codex is sandbox-gated, so its `codex` process ancestry is the reliable signal) — then a transport is *declared* and injected, never sniffed by the core. Spending a subscription has one landmine: in `claude -p`, `ANTHROPIC_API_KEY` overrides the plan, so the shell-out transport must strip it from the child env. The alternative topology — the builder as an MCP server borrowing the harness model via MCP *sampling* — was evaluated and rejected (no harness supports it; human-in-the-loop; model-hints-only). Full design: [`docs/transport-resolution.md`](transport-resolution.md).

## One core, two orchestrators

Everything up to here - hand in an `Llm`, run the pipeline - is one way to drive the core. Call it the library orchestrator: the `Pipeline` runs in-process, sequences the steps, and fans the independent work out through the `Llm` batch. The command line uses it, Studio uses it, the harness uses it, and it's also the simple way to run on wpcom - drive the pipeline with the `WpcomAiLlm` adapter and the completions go one at a time.

wpcom has a second way. Its WP Orchestrator agent can run the same core as a native workflow: the same steps and the same interface, but wpcom drives the loop, fans the per-section work out as concurrent server-side promises, and streams each result back over SSE as it lands. That sits one level up from the injected `Llm` - it changes who runs the loop and how the fan-out happens, while still calling the exact same steps. It's the pattern the shipping Big Sky site builder already uses.

So the shared part is the core, and only the core: the steps, the prompts, the `Llm` interface, and the step graph, with each fan-out unit written as a stateless input-to-output function so either orchestrator can drive it. Each host picks the orchestrator its environment hands it - where wpcom's agent capabilities are there, use them; everywhere else, the library orchestrator or your own. Each host keeps its native orchestration and shares the core, which is the right thing to share.

## Inside the wpcom path

The native path is built out of wpcom's own agent primitives, with the shared core doing the actual theme work. Start with the vendored core - loaded via `require_lib` - then register block-theme generation as something the WP Orchestrator can call.

Four abilities do it. The first is a workflow-ability, `generate_block_theme`: you register it like any ability, but its body builds and runs a `WorkflowAgent` internally (the same `Add_Page_Workflow_Ability` pattern wpcom already uses). That workflow encodes the same step graph as the library `Pipeline` - site spec, design direction, theme.json and the section plan, scaffold, then the part batch, then assemble and fix blocks. The other three are unit abilities — `generate_section`, `generate_header`, and `generate_footer` — whose bodies are thin wrappers over the shared core's stateless `SectionUnit`, `HeaderUnit`, and `FooterUnit`. For example:

```php
// wpcom 'generate_section' unit ability - a stateless wrapper over the shared core
'execute_callback' => function ( array $input ): string {
    require_lib( 'a8c/site-build' );
    $llm      = new WpcomAiLlm( feature: 'block-theme-generator' );
    $renderer = new PromptRenderer( Package::promptsDir() );
    return ( new SectionUnit( $llm, $renderer ) )->generate( $input );
},
```

The section input carries `site_spec` and `theme_json` (either decoded arrays or JSON text), `language`, the formatted `design_direction`, the shared `outline`, a nested `section` brief (`slug`, its required structural `role` of `hero` / `content` / `closing`, copy fields, and its assigned `layout_archetype` / `background` / `vertical_density` / `handoff`), and the precomputed `neighbors` summary. Header uses the common context plus `hero_brief`; footer uses only the common context. All values are ordinary JSON-serializable HTTP arguments. Each analogous ability constructs its matching unit and returns `generate( $input )`.

Then two moves make it live: register all four abilities on `wp_abilities_api_init`, and grant the workflow entry point to the orchestrator by adding `generate_block_theme` to the `wp-orchestrator` route's abilities list, right next to `add_page`. Nothing in wpcom's `ai-agent.php` changes - it already authenticates, streams, and dispatches any registered ability by name. The orchestrator turns each granted ability into a tool signature from its name, description, and input schema, so a clear description is what makes the capability discoverable.

## Driving a build

From the editor, driving a build is mostly opening a stream and listening. The client mints a short-lived Jetpack AI JWT - it trades a wp-admin nonce for a token, cached for about half an hour - and opens one SSE connection to wpcom's `ai/agent` endpoint with that token as a bearer. Then it sends a plain build-me-a-theme message and waits.

Everything after that is server-side. The WP Orchestrator reads the message, matches it to the `generate_block_theme` capability by that description, and invokes it; the workflow runs, and as each section's pattern comes back it streams down the same SSE connection and the editor drops it into place. The browser stays thin - it consumes the stream and applies results, while the server owns the concurrency, the retries, and the model choice.

The useful part is that it's just a registered capability. The orchestrator can fold theme generation into a bigger job, or the shipping Big Sky site builder can call it directly. Same capability, different callers.

## Two concurrency engines

Both orchestrators run the same section graph; they just fan it out differently. The library orchestrator does it in-process: `SectionsStep` asks each Project-free section/header/footer unit to prepare its request, collects the requests into one `completeBatch`, and the Anthropic client runs them with `curl_multi` against the direct API (or the proxy), windowed at ten in flight. Once every result is back, the same units normalize and validate their own output before the step writes any project files.

The wpcom workflow does it out-of-process. Inside the workflow the header, footer, and per-section steps are marked `promise: true` - emitted, not run inline - and a single `execute_promises` step hands the whole batch to wpcom's `promise-all`, which fires them concurrently with `curl_multi`, up to 25 at once. Each promise is a fresh, stateless HTTP call to its matching unit ability, and each result streams back over SSE (through the `ai_agent_update` hook for the feature) the moment it lands. Plan more than 23 sections (plus header and footer) and you split the work across more than one promise group.

So it's one section graph and two concurrency engines: in-process `curl_multi` windowed at ten, or server-side promises capped at 25. The graph doesn't know which one is driving it.

## Why the units are stateless

Both orchestrators can share one core because of one rule the fan-out units follow: they're stateless. A promise is a fresh HTTP request - a new process, no shared memory, no on-disk `Project` to read. So a section unit can't reach for state the way an in-process step can; it takes everything it needs as arguments and returns everything it produced. Inputs to output.

That single constraint is what lets the two orchestrators wrap the same code. The library `SectionsStep` is the stateful adapter: it reads the on-disk `Project`, builds self-contained unit inputs, batches the unit-built requests, and writes the finished outputs. A wpcom unit ability calls `generate()` on the same unit with HTTP arguments, where state rides in the request. The core logic in the middle doesn't change; only the wrapper around it does. Header and footer use the same unit contract because they participate in the same fan-out. Without it you'd write each generator twice - once for disk, once for HTTP - and the copies would drift. With it, there's one generator and two ways to feed it.

## Why it's worth doing

Write the hard part once. The deterministic logic that produces a good theme - all the ordering and assembly and block-markup repair - is what took real work to get right, and it lives in one place that every host shares. No forks; no parallel copies quietly drifting apart.

Each host uses what it already has. wpcom has its AI proxy; Studio has a logged-in user; a coding agent has a subscription the developer is already paying for. None of them need a fresh API key provisioned, and none of them scatter a new secret around. That's cheaper - a flat subscription or an existing proxy beats per-token billing - and it's a lot less to keep secure.

Concurrency comes along for free wherever the transport can carry it. When the transport is a real network endpoint - the CLI's direct API, Studio's proxy - the builder overlaps its section generation and the batch methods fan out for real, `curl_multi` windowed at ten. A coding-agent harness still fans out, just heavier: `completeBatch` spawns up to five `proc_open` subprocesses - real parallelism, but a process per call rather than a socket. Only wpcom's simple in-process adapter runs the batch sequentially. The pipeline code is identical in every case; only the adapter changes.

Adding a host is small. The interface is four methods, so a new environment is a new adapter and not a rewrite. Swapping model providers is the same size of change - a transport detail, not a pipeline detail.

And the open-source boundary stays clean. The package is public and knows nothing about any one provider; each host's own credentials and endpoints and internal wiring stay in that host's own repo. Nothing internal leaks into the public package.

## How it lives in each place

wpcom commits the package right in its own tree, vendored autoloader and all, and loads it with `require_lib`. There's no central Composer install from a registry, so it commits the vendored copy - the same move `a8c/customer360` already makes.

Studio bundles the package through its own build and calls it from a Studio Code tool using Studio's bundled PHP. Studio's local WordPress and its screenshot tooling stand in for the builder's helper scripts.

The wpcom developer loop: edit the package locally, rsync it into your wpcom sandbox - a live checkout that carries the real credentials - and trigger the wpcom entry point there. The sync tool lives in a private repo, since it points at non-public sandbox infrastructure.
