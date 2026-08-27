<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * Structured failure for the tree graph, ported from x-pipeline's
 * PipelineError: a machine-readable code, a human message, an optional
 * hint naming the fix, and free-form extra data (gate diagnostics, budget
 * numbers) for the report.
 *
 * The code lives on $errorCode (not Exception::getCode(), which is an int).
 */
final class TreeGraphException extends \RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $hint = '',
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
