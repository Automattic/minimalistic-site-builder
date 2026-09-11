The CLI starts raw image requests after `assemble-pages` in the blocks graph. In the HTML-first graph, it starts after `fix-pages`. A resume from a later step uses the same boundary. Only a full run with `--with-images` enables this stage. The existing restrictions on `--until` and `--step` remain in force.

Most design and section work finishes before this boundary. The available overlap covers only later graph work. Native UI images stay in the normal image phase. The prepared batch includes only final referenced assets that the initial image policy permits. Exact raw requests share one transfer.

Raw files, request keys, checksums, and provider attempt logs stay in a private directory under the system temporary directory. The normal `generate-images` phase rechecks final references and request keys before asset writes. A metadata change can preserve an exact raw request. A removed reference receives no result. A changed prompt, model, provider, ratio, size, or MIME type requires a new result.

Normal image apply consumes each current asset once. Unused results remain available across partial batches. QA can start for a fast result while other raw requests remain active. QA replacements make new provider requests. The existing image and vision limits still apply.

A graph failure retains raw results and prints their directory. A resume can reuse successful results. A failure during serial apply closes the scheduler and preserves the raw attempt log. Cancellation records active provider attempts without asset delivery. Queued requests receive no attempt row.

The build report retains the provider totals from the original client. `images.prepared_results_consumed` counts raw results consumed without a new request. The image phase includes the wait for incomplete raw results. Imported attempt rows have stable `stage_attempt_id` values, so repeated publication adds no duplicate rows. Logs from an earlier failed process remain separate from the current process totals.
