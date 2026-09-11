`PreparedImageBatch` freezes raw image requests after final page assembly. The CLI uses it through `EarlyImageBuild`. See [the early image stage contract](early-image-stage.md) for the active schedule.

The blocks graph prepares requests after `assemble-pages`. The HTML-first graph waits for `fix-pages`. Both boundaries follow the block repairs that can remove images. Keep `collect-images` before `fix-blocks`, because block serialization clears cover descriptions.

Preparation reads final templates, listed plugin pages, and their referenced parts and patterns. It excludes unreferenced images, completed images, and images that the initial image policy defers. It retains the synthetic site logo. The optional `providerEligible` predicate excludes local render requests. The CLI renders supported native UI images in the normal image phase.

Preparation calls `GenerateImagesStep::generationSpec()` to preserve one request format. It stores immutable requests, source specifications, image indices, and input hashes. The `stage()` method writes raw results into an empty directory outside the project. It checks MIME types and records file checksums. It does not change project assets, markup, manifests, warnings, or completion artifacts.

The CLI uses this sequence:

1. Finish page assembly and the required block repairs.
2. Prepare requests and start raw transfers through `ImageTransportScheduler`.
3. Run independent CSS and font steps while the scheduler advances raw transfers.
4. Recheck final references and request keys through `EarlyImageBuild::applicationClient()`.
5. Apply ready results through `GenerateImagesStep`, including image QA, repairs, and asset transforms.
6. Run the final screenshot, cover contrast, pattern extraction, and theme validation steps.
7. Join remaining transfers and publish attempt logs to the project.
8. Remove complete raw stages only after every post-image step succeeds and the log flush succeeds.

Ready results can reach QA while other raw transfers remain active. Each asset consumes its staged result once. A QA replacement makes a new provider request. Project changes stay inside the normal image phase. Other hosts must preserve the same phase boundaries and serial project writes.

The `isCurrent()` method detects source changes. The application client prepares new requests when necessary. It accepts an earlier result only when its project, provider, model, prompt, ratio, size, and MIME match the current request. A removed image receives no result. A changed request needs new pixels. The client checks the file checksum before use.

A failed graph or post-image step retains raw files for resume. A successful run removes its complete stage and earlier complete stages from its initial directory snapshot. Partial stages and newer sibling stages remain intact. Cleanup never follows symlinks or recursively removes unknown files. Attempt logs remain in the project after raw files disappear. A log publication failure prevents cleanup.

An invalid or unreadable older log preserves that stage without failing the new build. Valid older records still reach the project log. Run one build per project. Concurrent builds of the same project are unsupported because they share artifacts without a project lock.
