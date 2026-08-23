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
        $name = null;
        if ($flag !== null && $flag !== '') {
            $name = $flag;
        } else {
            $fromEnv = Env::get('SITE_BUILD_RUNNER');
            if ($fromEnv !== null && $fromEnv !== '') {
                $name = $fromEnv;
            }
        }

        if ($name === 'studio') {
            if ($cli->available()) {
                return new StudioAppRunner($cli, StudioAppRunner::defaultRoot(), repo_path());
            }
            throw new \RuntimeException('Studio is not available');
        }
        if ($name === 'playground') {
            return new PlaygroundRunner();
        }
        if ($name !== null) {
            throw new \RuntimeException("Unknown runner: {$name}");
        }

        if ($cli->available()) {
            return new StudioAppRunner($cli, StudioAppRunner::defaultRoot(), repo_path());
        }
        $warn('Studio is not available; falling back to Playground.');
        return new PlaygroundRunner();
    }
}
