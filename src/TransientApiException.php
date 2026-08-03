<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * A retryable API failure: connection timeout/stall, HTTP 429, 5xx, or an
 * overloaded/api stream error. The retry loop catches this specifically;
 * everything else (4xx, bad request) is a permanent RuntimeException.
 */
final class TransientApiException extends \RuntimeException
{
    /**
     * Connection-level cURL errnos worth retrying with backoff. 6 = could not
     * resolve host (the DNS blip that used to abort builds), 7 = could not
     * connect, 28 = timeout, 35 = SSL connect, 52 = empty reply, 55 = send
     * error, 56 = recv error. Any other cURL error (bad URL, cert, etc.) is
     * permanent. Shared by every transport client so the list cannot drift.
     */
    public const TRANSIENT_CURL_ERRNOS = [6, 7, 28, 35, 52, 55, 56];
}
