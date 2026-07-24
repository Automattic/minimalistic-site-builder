<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Internal signal carrying a recoverable API parameter rejection.
 *
 * @internal
 */
final class RejectedApiParameterException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $parameter)
    {
        parent::__construct($message);
    }
}
