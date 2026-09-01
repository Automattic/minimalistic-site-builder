<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * No usable transport could be resolved, or the resolved one cannot run.
 *
 * Always fatal. AGENTS.md classes "misconfigured environment overrides" as our
 * bug rather than the model's, so this never degrades — degrading here would
 * mean silently guessing a billing path, which is the one thing the ladder
 * exists to prevent.
 */
final class TransportUnavailable extends \RuntimeException
{
}
