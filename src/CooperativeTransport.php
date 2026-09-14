<?php
declare(strict_types=1);
namespace Automattic\SiteBuild;

/** A transport whose batch requests and retry waits can yield to the shared scheduler. */
interface CooperativeTransport
{
    public function supportsCooperativeRequests(array $opts = []): bool;
}
