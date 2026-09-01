<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * One harness subprocess call failed: spawn error, non-zero exit, timeout,
 * OOM kill, unparseable output, or an error envelope.
 *
 * Distinct from TransportUnavailable because it is handled at a different rung:
 * retried transiently, and on exhaustion degraded with a warning rather than
 * aborting a build whose earlier sections are already paid for.
 */
final class HarnessCallFailed extends \RuntimeException
{
}
