<?php
declare(strict_types=1);

/**
 * One site-creation step. Reads artifacts written by earlier steps from the
 * project and writes its own output. Each step is individually runnable and
 * idempotent enough to re-run (it overwrites its own outputs).
 */
interface Step
{
    /** Stable identifier, e.g. "scaffold-theme". */
    public function id(): string;

    /** Human-readable label for logs. */
    public function label(): string;

    public function run(Project $project): void;
}
