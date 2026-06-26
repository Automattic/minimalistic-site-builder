<?php
declare(strict_types=1);

/**
 * A retryable API failure: connection timeout/stall, HTTP 429, 5xx, or an
 * overloaded/api stream error. The retry loop catches this specifically;
 * everything else (4xx, bad request) is a permanent RuntimeException.
 */
final class TransientApiException extends RuntimeException
{
}
