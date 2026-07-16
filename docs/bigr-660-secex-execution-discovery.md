# BIGR-660 — Should SecEx own running site generation instead of wpcom itself?

**Status:** Discovery draft · **Author:** Miguel Lezama · **Issue:** [BIGR-660](https://linear.app/a8c/issue/BIGR-660)
**Related:** [BIGR-648](https://linear.app/a8c/issue/BIGR-648) (wpcom-native path), [BIGR-656](https://linear.app/a8c/issue/BIGR-656) (Studio Code wiring), [BIGR-641](https://linear.app/a8c/issue/BIGR-641) (Studio proxy transport), [BIGR-604](https://linear.app/a8c/issue/BIGR-604) (SecEx + Studio Code endpoint — the existing prototype), [BIGR-661](https://linear.app/a8c/issue/BIGR-661) (project-data persistence)

---

## TL;DR — recommendation

**Do not replace the WP Orchestrator path (BIGR-648) with SecEx. Build SecEx+Studio as a *second, detached mode* of the same core, and ship the wpcom-native path first.**

The two are not competitors; they are two orchestrators over one shared core (the `Llm` interface + the step graph), exactly as [the portable-pipeline design](site-build-portable-pipeline.md) already lays out. They answer different product questions:

- **wpcom-native (BIGR-648)** → the *tab-open, watch-it-build* experience (BIGR-653/654/655). Ambient credentials, server-side promise fan-out to 25, SSE streaming. This is the A/B-test flow and should ship first — it reuses primitives that already work in Big Sky.
- **SecEx+Studio (this issue)** → the *close-the-tab, email-me-when-done* experience. A detached job for builds too long or too heavy to hold a connection open, or that need a real WordPress (not Playground) to build against.

The key finding: **the SecEx execution substrate already exists** — it's Miguel's `wpcom/v2/studio-code/run` endpoint (BIGR-604 / [wpcom#212555](https://github.a8c.com/Automattic/wpcom/pull/212555)). What's missing for BIGR-660 is not the sandbox plumbing; it's (a) the *detached* job lifecycle (queue/poll/email) on top of the existing synchronous turn, (b) wiring Studio Code to drive the **site-builder library** (BIGR-656/641, not yet built), and (c) landing the built theme on the user's atomic site (BIGR-662).

---

## The stack under evaluation

Per the issue's "likely shape", SecEx would **not** run the `site-build` library directly. It would run **Studio, headless, with Studio Code driving it**:

```
wpcom (start job) → SecEx sandbox → Studio Code (headless) → site-build library → theme → back onto the user's wpcom site
```

This matters: it means SecEx reuses the *harness / library-orchestrator* path from the portable-pipeline design (Studio Code shells the build), **not** a fourth bespoke integration.

---

## What already exists (the evidence)

Miguel's **[wpcom#212555](https://github.a8c.com/Automattic/wpcom/pull/212555)** (BIGR-604) already ships the hard 80% of the SecEx substrate:

| Capability BIGR-660 asks about | Already built in wpcom#212555 |
|---|---|
| Studio running headless in a SecEx sandbox | `POST /studio-code/run` runs `studio code --json` inside the user's sandbox, streams NDJSON back as SSE |
| One durable sandbox per user | `wp_usermeta.studio_code_session` = `{sandbox_id, snapshot_id, template_version}`, anchored by a rolling SecEx snapshot |
| Fast resume vs cold restore | 3-tier resolve: **warm** (`Sandbox::connect`, sub-second) → **cold** (`Sandbox::create($snapshot_id)`) → **fresh** (`Sandbox::create('studio-code')`) |
| How the job talks to the model | Bearer forwarded into the sandbox as `STUDIO_WPCOM_TOKEN`; the CLI calls `/wpcom/v2/ai-api-proxy` — **the user's own wpcom login pays, no key provisioned** |
| Detached execution primitive | `fastcgi_finish_request()` cuts the client, then the server snapshots + pauses — work continues after the connection closes |
| Concurrency safety | per-user `wp_cache_add` lock (480s TTL), `HTTP 429 session_busy` on contention |
| Template drift / migration | `GET/POST /studio-code/update` opt-in migration onto the latest template bake, preserving on-disk state |

**Implication:** the security model, the credential path, and the sandbox lifecycle questions in BIGR-660 are largely *answered by an existing, reviewed PR*. The discovery is not "can SecEx host this" — it demonstrably can — but "what do we add to turn a synchronous turn into a detached build, and is that worth it over the wpcom-native path."

---

## Question-by-question

### 1. Can SecEx host the stack?
**Yes — demonstrated.** wpcom#212555 already runs Studio Code headless in a SecEx sandbox for the duration of an agent turn. A full theme build is ~3 minutes (measured: a local CLI build of *"a cozy neighborhood bakery"* ran 177s / 16 LLM requests / 155k tokens). That is well within a sandbox's warm session; the open question is holding it *detached* rather than streaming, which the `fastcgi_finish_request` + snapshot pattern already models.

### 2. How does the job talk to the model?
**Through the wpcom AI proxy, on the user's token — already wired.** The bearer is forwarded as `STUDIO_WPCOM_TOKEN`; the CLI hits `/wpcom/v2/ai-api-proxy` with `X-WPCOM-AI-Feature`. This is exactly BIGR-641's "Studio proxy transport" target, and it's the *right* security posture: no API key provisioned into SecEx, no new secret scattered, spend attributed to the user. **Note:** BIGR-641 (the `Llm` proxy transport in the site-build core) is still Todo — the endpoint forwards the token, but the site-builder library needs the proxy-backed `Llm` adapter to actually consume it.

### 3. Job lifecycle (queue / retry / poll / completion hook)
**This is the actual gap.** Today `/run` is a *synchronous SSE turn*. To deliver "close the tab, get an email" we need:
- A **queue + job record** (status: queued/running/done/failed) the client can poll — the `wp_usermeta.studio_code_session` handle is a starting point but is single-slot, not a job log.
- **Retry on failure** — SecEx retention keeps snapshots, so a failed build can cold-restore and resume (this composes with BIGR-646 resumability).
- A **completion hook** that fires the "your site is ready" email — naturally lands in the post-`fastcgi_finish_request` tail where the snapshot already happens.
- What SecEx gives for free vs. build: sandbox create/connect/snapshot/pause and retention are free; the **job queue, status API, and email hook are net-new** and belong in wpcom, not SecEx.

### 4. Getting the result back onto the user's site
**Not yet solved and the biggest true unknown.** Studio builds against a *local* WordPress in the sandbox; the user's site is a separate atomic/wpcom site. Two options:
- **Push:** the job exports the finished theme (the `bin/publish-playground` ZIP path already produces a portable bundle) and installs+activates it via a wpcom API onto the user's site. Composes with **BIGR-662** (install AND activate on atomic).
- **Pull:** wpcom pulls the theme artifact from the sandbox after the completion hook.
Recommendation: **push**, reusing the existing project-ZIP artifact, because the job already knows when it's done and holds the credentials.

### 5. How it fits the current plan (streaming vs detached)
**Two modes of the same build, not a replacement.** The streaming UX (BIGR-653/654/655) genuinely wants a live connection and the wpcom-native promise fan-out (25-wide, SSE). The detached flow wants a job. Forcing one to serve both hurts both:
- Detached-only loses the "watch it build" moment that makes the AI feel real (explicitly the point of BIGR-654).
- Streaming-only breaks the moment a build outlives the connection, or needs a real WP instead of Playground.

So: **keep BIGR-648 as the default/first-ship path; add SecEx+Studio as the detached mode** selected when the build is heavy or the product wants fire-and-forget. Same core, second orchestrator — the design already supports exactly this ("one core, two orchestrators").

### 6. Cost of the switch
What wpcom-in-process gives that SecEx+Studio doesn't:
- **Ambient server credentials** (no per-user token dance) and **server-side promise fan-out to 25** — faster wall-clock than the library orchestrator's 5-wide `curl_multi`.
- **SSE hooks that already stream** (`ai_agent_update`) with zero new infra.
What SecEx+Studio adds that wpcom can't:
- **Detachment** (survive tab close), **a real WordPress** to build against (not Playground), and **isolation** for heavier/longer/agentic builds.
- Reuses Studio Code as the driver, so improvements to the coding-agent path benefit both.

---

## Recommendation & follow-ups

1. **Ship BIGR-648 (wpcom-native) first** for the A/B test. It's the shorter path to the streaming experience the product wants and reuses shipping Big Sky primitives.
2. **Land BIGR-660 as "yes, but additive":** SecEx+Studio is the **detached mode**, built on the existing wpcom#212555 substrate, not a rewrite of the execution model.
3. **Unblock the driver leg:** BIGR-641 (proxy `Llm` transport in the core) then BIGR-656 (Studio Code drives the site-builder) are the real prerequisites — without them "Studio Code drives the build" is aspirational.
4. **File follow-ups if accepted:**
   - Detached job lifecycle: queue + status API + poll endpoint (extends `/studio-code/run`).
   - Completion hook → "site ready" email (in the post-`fastcgi_finish_request` tail).
   - Theme push-back onto the user's atomic site (composes with BIGR-662).
   - Project-data persistence for a detached job (owned by BIGR-661).

## Open risks
- **BIGR-604 is still In Review, not merged.** The substrate this recommendation leans on isn't landed. Getting wpcom#212555 in is a prerequisite to any SecEx build path — and is itself stale (last activity 2026-06-10).
- **Studio-in-SecEx for detached minutes** is proven for interactive turns, not yet for long unattended jobs — idle reaping vs a 3-minute detached build needs a deliberate test.
- **BIGR-641/656 are the true critical path.** If they slip, SecEx has no site-builder to drive and this mode can't exist regardless of the substrate.
