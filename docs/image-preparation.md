`PreparedImageBatch` provides an isolated request phase for a future image scheduler. The current CLI and host defaults still use `GenerateImagesStep`. This class does not change their schedule or claim a speed improvement.

The blocks graph can prepare requests after `assemble-pages`. The HTML-first graph must also finish `fix-pages`. Both points follow the block repairs that can remove images. Keep `collect-images` before `fix-blocks`, because block serialization clears cover descriptions.

The preparation reads theme templates, pages in the final plugin manifest, and their recursively referenced template parts. It excludes orphan source parts and unlisted plugin pages. It excludes unreferenced images, completed images, and images that the initial image policy defers. It retains the synthetic site logo. It calls `GenerateImagesStep::generationSpec()`, so request composition and image size keep one source of truth.

Before preparation, the host must finish local image renders and validate the final references. The optional `providerEligible` predicate excludes specifications that must use another renderer. It receives the specification with its committed image kind. This keeps the primitive independent of a particular local renderer. Preparation itself makes no network request.

The preparation stores immutable requests, original image indices, source specifications, and input hashes. Its getters return copies. `stage()` accepts an empty directory outside the project and writes raw image results there. It checks MIME types and records checksums. It does not change project markup, manifests, warning records, or completion artifacts.

A host integration must complete these steps:

1. Finish page assembly and the required block repairs.
2. Call `PreparedImageBatch::fromProject()` before concurrent work starts.
3. Start `stage()` in a worker with an isolated output directory.
4. Run independent CSS and font steps while image requests run.
5. Join the worker and check `isCurrent()` before any result changes the project.
6. Apply results serially through the existing image QA, repair, crop, transparency, and delivery checks.
7. Write image logs, usage records, warnings, asset files, markup changes, and manifests through one owner.
8. Run the final screenshot, cover contrast, pattern extraction, and theme validation steps in their required order.

Steps 3 through 8 need scheduler and delivery work before a host can use this path by default. In particular, `GenerateImagesStep` still combines transport calls with project changes. Do not call its current `run()` method concurrently with other steps that change the project.

`isCurrent()` detects source changes. A failed check means the host must prepare a new batch or verify each stale result against the final inputs. It must retain the cost record for requests that already ran. The result manifest alone does not authorize asset delivery.

Font steps can change `theme/theme.json` and font assets without invalidating the prepared subjects. Final theme code can change the header and read `images.json`. Keep that code after the serial image phase. Image QA and delivery can remove references, so final validation must remain after image delivery.
