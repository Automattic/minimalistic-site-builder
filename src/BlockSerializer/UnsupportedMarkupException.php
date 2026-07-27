<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/**
 * The authored markup is something this pipeline deliberately does not accept.
 *
 * This is the only failure the block and file fallbacks degrade on. The
 * distinction it draws is between *the input is outside our domain* and *we are
 * broken*, and it exists because `\RuntimeException` cannot tell those apart:
 * it is this codebase's general error type, thrown equally by the support-domain
 * guards, by a defect in the frozen registry snapshot, by a missing renderer for
 * a block we declared supported, and by an environment where `ini_set` is
 * disabled.
 *
 * Catching the general type meant a broken registry or a hardened PHP host would
 * degrade every block in silence and exit zero — a whole theme shipped
 * unprocessed with a clean-looking run. Only the guards that judge authored
 * markup throw this; everything else stays a plain `\RuntimeException` and
 * crashes, which is what a defect should do.
 */
final class UnsupportedMarkupException extends \RuntimeException
{
}
