<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Public execution contract shared by fixed and runtime-rerouting pipelines. */
interface BuildPipeline
{
    /** @return string[] ordered top-level step ids */
    public function stepIds(): array;

    /** @return string[] step ids accepted by runThrough() */
    public function stopIds(): array;

    /**
     * Run every step up to and including $untilId, or the complete build when null.
     *
     * $fromId resumes mid-graph: every step that order-precedes it is skipped
     * (its artifacts are assumed already on disk), symmetric to $untilId. Null
     * runs from the first step.
     *
     * @param callable(Step,float):void|null $reporter
     * @param callable(Step):void|null       $onStart
     */
    public function runThrough(
        Project $project,
        ?string $untilId = null,
        ?callable $reporter = null,
        ?callable $onStart = null,
        ?string $fromId = null,
    ): void;
}
