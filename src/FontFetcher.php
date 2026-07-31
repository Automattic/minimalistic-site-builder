<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Downloads one font face. Implementations throw on any failure. */
interface FontFetcher
{
    public function fetch(string $url): string;
}
