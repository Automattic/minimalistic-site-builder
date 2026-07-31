<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Signals that generated design markup cannot enter the HTML-first pipeline.
 */
final class MalformedDesignException extends \RuntimeException
{
}
