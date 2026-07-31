<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\FontFetcher;

/**
 * Test double for FontFetcher. Returns canned bytes derived from the URL and
 * records every fetch. URLs containing any of the failing substrings throw, to
 * exercise the per-family degradation paths.
 */
final class FakeFontFetcher implements FontFetcher
{
    /** @var string[] */
    public array $calls = [];

    /** @param string[] $failing substrings of URLs that must throw */
    public function __construct(private array $failing = [])
    {
    }

    public function fetch(string $url): string
    {
        foreach ($this->failing as $needle) {
            if (str_contains($url, $needle)) {
                throw new \RuntimeException('simulated download failure');
            }
        }
        $this->calls[] = $url;
        return 'FONTBYTES:' . basename((string) parse_url($url, PHP_URL_PATH));
    }
}
