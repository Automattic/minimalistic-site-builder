<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Picks the SiteRunner for one run.
 *
 * Order: explicit $flag, then Env::get('SITE_BUILD_RUNNER'), then Studio
 * when $cli->available(), else Playground via $warn. A forced studio that
 * is unavailable is an error, never a silent downgrade. $warn fires at
 * most once per resolve() call.
 */
final class RunnerResolver
{
    /**
     * @param \Closure(string):void $warn
     */
    public static function resolve(?string $flag, StudioCli $cli, \Closure $warn): SiteRunner
    {
        throw new \RuntimeException('RunnerResolver::resolve is not implemented');
    }
}
