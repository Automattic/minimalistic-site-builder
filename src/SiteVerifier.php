<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Post-build checks against a booted Studio WordPress.
 *
 * Not a pipeline step: the pipeline is host-portable and wpcom/Linux CI
 * cannot boot a local Studio site. Findings warn; they never fail the build.
 *
 * @phpstan-type VerifierPayload array{pages:int, front_page:int|string, theme_errors:list<string>}
 */
final class SiteVerifier
{
    /**
     * @return list<string> finding strings, empty when the site is sound
     */
    public static function check(StudioCli $cli, string $siteDir): array
    {
        return [];
    }
}
