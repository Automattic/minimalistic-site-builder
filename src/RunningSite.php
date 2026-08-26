<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * $persistent distinguishes the two process models: a Playground server dies
 * with the caller, a Studio site outlives it. Callers branch on this to decide
 * whether to block on Ctrl-C or print the URL and return.
 */
final class RunningSite
{
    public function __construct(
        public readonly string $url,
        public readonly string $adminUrl,
        public readonly bool $persistent,
        public readonly \Closure $stop,
    ) {}
}
