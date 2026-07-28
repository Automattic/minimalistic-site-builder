<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * A concurrent JSON step that can deterministically replace terminally
 * malformed generated responses while valid batch siblings are retained.
 */
interface GeneratedJsonFallbackStep extends ConcurrentStep
{
    /**
     * Consume any successfully decoded local results and replace the failed
     * local requests with deterministic output.
     *
     * @param array<string,array<mixed>> $results  decoded local siblings
     * @param array<string,string>       $failures local request key => diagnostic
     */
    public function consumeGeneratedJsonFailure(
        Project $project,
        array $results,
        array $failures,
    ): void;
}
