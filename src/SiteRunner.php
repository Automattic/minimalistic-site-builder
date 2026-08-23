<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

interface SiteRunner
{
    /** Boot $project and return the running site. Throws on failure. */
    public function start(Project $project): RunningSite;

    /** Short name for logs and build-stats.json: "studio" or "playground". */
    public function name(): string;
}
