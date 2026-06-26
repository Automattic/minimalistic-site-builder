# Builder — Progress

_Source of truth for resuming after interruption. Update after every meaningful action._

## Current status: Phase 0 (proxy access) — BLOCKED, awaiting user decision

## Phase 0 findings (verified empirically, 2026-06-25)

Goal: confirm a successful LLM round-trip. Hard gate before Phase 1.

| Attempt | Auth token | Endpoint | Result |
|---|---|---|---|
| Proxy chat/completions, `claude-sonnet-4-20250514` | `ANTHROPIC_API_KEY` | wpcom ai-api-proxy | **404** `not_found_error` (key is not a valid wpcom bearer) |
| Proxy chat/completions, claude | `GOOGLE_VERTEX_API_TOKEN` | wpcom ai-api-proxy | **404** empty |
| Proxy `/v1/models` | `GOOGLE_VERTEX_API_TOKEN` | wpcom ai-api-proxy | **422** `ai_services_error` (token valid, routes to Google Vertex only) |
| Proxy `/v1/messages` native, claude | `GOOGLE_VERTEX_API_TOKEN` | wpcom ai-api-proxy | **404** (forwards to Google backend) |
| Proxy chat/completions, `gemini-2.0-flash` | `GOOGLE_VERTEX_API_TOKEN` | wpcom ai-api-proxy | **404** empty |
| **Anthropic DIRECT** `/v1/messages`, `claude-opus-4-8` | `ANTHROPIC_API_KEY` | api.anthropic.com | ✅ **200**, text="pong", usage returned |

### Conclusions
- The wpcom AI proxy does **not** authenticate with the Anthropic API key (user's belief disproved).
- The only proxy token available (`GOOGLE_VERTEX_API_TOKEN`) is **scoped to Google Vertex** — it cannot reach Claude/Anthropic models through the proxy.
- telex confirms this: it calls **Anthropic directly** (`api.anthropic.com`, `x-api-key`) and uses the proxy **only** for google-vertex image gen. It never routes Claude through the proxy.
- Anthropic-direct works today with the provisioned `ANTHROPIC_API_KEY`. Available models include `claude-opus-4-8`, `claude-opus-4-7`, `claude-sonnet-4-6`.

### Decision needed from user
Proxy-for-Claude is not reachable with current credentials. Options:
1. **Use Anthropic-direct** (telex's own pattern for text). Unblocks immediately.
2. User supplies a wpcom AI-proxy token **entitled for Anthropic** models.

## Env keys present
`ANTHROPIC_API_KEY` (valid, direct), `GOOGLE_VERTEX_API_TOKEN` (valid, Vertex-only proxy), `OPENAI_API_KEY`.

## Code so far (from prior session, pre-Phase-0)
- `src/Env.php`, `src/LlmClient.php` (currently points at the proxy — needs rework per decision), `src/bootstrap.php`, `bin/test-llm.php`.
- `plan/site-builder-plan.md`, `plan/steps.md`.
- NOTE: `LlmClient` + `.env.example` reference `WPCOM_AI_TOKEN`, which does not exist / does not work. Must be reworked.

## Next (once unblocked)
Phase 1: implement steps one at a time (scaffold theme → siteSpec → identity → design direction → design.md → theme.json → landing page), test+commit each.
