# LLM request events

The Anthropic client appends local events to `logs/llms/events.jsonl`. The existing `*.log` transcripts and usage totals retain their format and meaning.

Each logical batch has a `batch_id`. Each request has a stable `request_id`, label, and model. The `attempt` number increases on a transport retry. A held request has an attempt record but no `transport_started` event. It does not prove a provider charge.

| Event | Measured boundary |
|---|---|
| `batch_admitted` | The client accepts the logical batch after it prepares request bodies. |
| `request_admitted` | The batch recorder accepts a request. |
| `attempt_admitted` | The transport accepts a request for this attempt. |
| `cache_gate_blocked` | The pool first observes a closed cache gate. |
| `cache_gate_ready` | The pool first observes an open cache gate. |
| `transport_started` | cURL accepts the handle, or the client calls `curl_exec`. |
| `first_response_body` | The first non-empty body callback runs. This can contain a ping. |
| `message_start` | The body contains a complete Anthropic `message_start` event. |
| `attempt_finished` | cURL completes, the scheduler cancels, or the request scope exits. |
| `batch_finished` | The batch returns, aborts, or receives a scheduler cancellation. |

`at` is an epoch timestamp. The recorder anchors a monotonic clock to wall time at batch admission. `elapsed_seconds` and duration fields use that monotonic clock. Each attempt lists the request IDs that own its cache dependencies.

`local_wait_seconds` measures attempt admission to cURL admission. It includes cache dependencies, capacity limits, and local work. `gate_wait_observed_seconds` measures the interval between the closed and open predicate observations. Pool capacity and poll intervals can affect these observations. Thus this interval does not isolate the exact delay caused by the cache policy. A null value means the recorder did not observe the required boundaries. A zero value means the first observed gate was open.

`transport_seconds` comes from cURL. The start boundary includes DNS, connection, and transfer work. It does not measure a provider queue. `first_response_body` differs from receipt of HTTP headers. The recorder does not measure the first output token separately from `message_start`.

The completion record names its boundary. Real transfers use `curl_completion` or `curl_exec_return`. Held or refused requests can use `batch_return`, because they have no transfer completion. Cancellation records distinguish active requests from `cancelled_before_start`. A scope abort leaves unmeasured durations null.

The ledger records transport outcomes. For a single request, `transport_completed` means that cURL returned; the normal response parser can still reject that response. The existing transcript records the final LLM result. Event records contain no prompt, response text, image bytes, or credentials. They do not change cost totals or infer missing usage.

The event writer uses the directory from batch admission and appends across resumes. A write failure cannot abort the build. A process kill or a disk failure can leave incomplete records. Other LLM adapters and injected test transports do not emit these Anthropic events.
