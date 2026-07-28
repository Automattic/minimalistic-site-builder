<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Terminal generated-content failure from JsonBatchRecovery.
 *
 * This exception is deliberately narrower than RuntimeException: callers may
 * degrade only when the provider returned content that was still malformed,
 * truncated, or refused after the bounded repair attempt. Transport failures
 * and broken sender contracts remain ordinary exceptions and stay fatal.
 */
final class GeneratedJsonException extends \RuntimeException
{
    /**
     * @param array<array-key,string>       $failures request key => concise diagnostic
     * @param array<array-key,array<mixed>> $partialResults successfully decoded siblings
     */
    public function __construct(
        public readonly array $failures,
        public readonly array $partialResults = [],
    ) {
        if ($failures === []) {
            throw new \InvalidArgumentException('GeneratedJsonException needs at least one failed request');
        }
        parent::__construct(implode("\n", array_values($failures)));
    }
}
