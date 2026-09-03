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
     * The runner the caller asked for by name, or null when the choice is
     * ours. Callers read this to tell a forced backend (whose failure is an
     * error) from an automatic one (whose failure falls back to Playground).
     */
    public static function requestedName(?string $flag): ?string
    {
        if ($flag !== null && $flag !== '') {
            return $flag;
        }
        $fromEnv = Env::get('SITE_BUILD_RUNNER');
        return $fromEnv !== null && $fromEnv !== '' ? $fromEnv : null;
    }

    /**
     * @param \Closure(string):void $warn
     */
    public static function resolve(?string $flag, StudioCli $cli, \Closure $warn): SiteRunner
    {
        $name = self::requestedName($flag);

        if ($name === 'studio') {
            if ($cli->available()) {
                return new StudioAppRunner($cli, StudioAppRunner::defaultRoot(), dirname(__DIR__));
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
            return new StudioAppRunner($cli, StudioAppRunner::defaultRoot(), dirname(__DIR__));
        }
        $warn('Studio is not available; falling back to Playground.');
        return new PlaygroundRunner();
    }
}
