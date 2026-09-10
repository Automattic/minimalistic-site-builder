# BIGR-1000 image policy verification

Captured from the running WordPress Studio site `bigr-1000-clay-studio` (Kiln & Field), built from implementation commit `42c32b1` on the blocks graph. Screenshots show the actual browser-rendered homepage and Workshops page; all homepage images were loaded before capture.

- `homepage.png`: generated photography across the homepage.
- `workshops.png`: generated hero image with neutral local placeholders in the remaining image slots.

The three-page build collected 45 image specs: 20 completed generation and 25 received local placeholders, with no failed image specs. This avoids generating 25 of 45 assets (55.6%). The 20 completed entries include the generated site-logo asset.

Text generation used the Codex subscription with `gpt-5.5`; the configured `gpt-5.4-mini` small model was unavailable for the account, so `LLM_MODEL_SMALL=gpt-5.5` was a build-only override. Images used the checkout's provisioned image service. All 26 graph steps and the full post-image phase completed. No additional site build was run for these screenshots.

Validation: 80 focused tests passed, 5 optional imaging tests skipped; 36 integration tests passed. The full workspace unit run had 4197 passes, 29 failures, and 5 skips. All 29 failures reproduced against unchanged HEAD; 24 come from pre-existing untracked hero/layout experiments excluded from the feature commit. The other five concern pattern rollback, Studio, and transport-environment behavior. A separate read-only review found no defects.
