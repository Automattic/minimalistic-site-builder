<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Transport-agnostic page capture. Implementations turn URLs into on-disk
 * screenshot slices. Mirrors the Llm and ImageClient interfaces:
 * VisionUrlAnalyzer depends on the contract, tests inject a stub, and each host
 * injects whatever it can actually run.
 *
 * That last part is the point of this being an interface. Everything else in
 * VisionUrlAnalyzer — the design-brief prompt, the schema, the containment
 * rule, the positive-evidence gate, carrying slices through to the design steps
 * — is host-independent. Capture is the only piece that is not: a CLI build
 * drives headless Chrome (ChromeScreenshotCapture), while a host without a
 * browser supplies whatever screenshot service it has. Splitting capture out
 * means those hosts share one analyzer and one quality bar instead of each
 * settling for whatever its own transport can describe.
 */
interface ScreenshotCapture
{
    /**
     * Capture every URL, concurrently where the implementation can.
     *
     * MUST NOT throw. Inspiration is best-effort: a URL that cannot be captured
     * comes back with empty slices and an error string so the build degrades to
     * a warning. Duplicate URLs are captured once, and every input URL appears
     * exactly once in the result.
     *
     * Slices are paths to PNG files, ordered top of page first. They must be
     * written somewhere durable for the rest of the build — under
     * InspirationLogger::dir() when it is set — because the design steps reload
     * them by basename long after analysis finishes. An implementation that
     * writes to a temp directory it later cleans up will produce a working
     * brief and then silently lose the screenshots downstream, which is the one
     * way to satisfy this contract and still get half the feature.
     *
     * @param  list<string> $urls
     * @return array<string,array{slices:list<string>,error:string|null}> keyed by URL
     */
    public function capture(array $urls): array;
}
