# PR #86 — building on OpenAI GPT-5.x

Testing evidence for the OpenAI GPT-5 support added in this PR: the `portfolio`
demo built end-to-end on `--provider=openai`.

## Command

```bash
php bin/build-demos.php --with-images --only=portfolio --provider=openai
```

## Result (`portfolio7`)

- **16/16 LLM steps succeeded** — 12 on `gpt-5.5` (large tier), 4 on
  `gpt-5.4-mini` (small tier), exactly as `config/models.json` assigns them.
- **15/15 images generated** (Google Imagen via the WPCOM proxy, unchanged).
- Total ~231s, 139k tokens. See [`portfolio7-project.log`](portfolio7-project.log).

Full-page screenshot: [`portfolio7-home.png`](portfolio7-home.png) (1366×9388).
