<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/** Injectable two-phase file writer used by failure-preservation tests. */
interface StagedFileWriter
{
    /** Write complete bytes beside the target and return the staged path. */
    public function stage(string $target, string $content): string;

    /** Atomically replace the target with an already-complete staged file. */
    public function replace(string $staged, string $target): void;

    /** Best-effort cleanup for an uncommitted staged path. */
    public function discard(string $staged): void;
}
