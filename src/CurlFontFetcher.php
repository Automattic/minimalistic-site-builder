<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The production fetcher. Strict on purpose: the catalog guarantees every src
 * is https on fonts.gstatic.com, and this re-checks per request so a corrupted
 * catalog (or a future caller with a hand-built URL) cannot turn the bundler
 * into a generic downloader.
 */
final class CurlFontFetcher implements FontFetcher
{
    /** Fonts are tens of KB; a response this large is not a font. */
    private const MAX_BYTES = 5_000_000;

    private const TIMEOUT_SECONDS = 15;

    public function fetch(string $url): string
    {
        if (
            parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'fonts.gstatic.com'
        ) {
            throw new \RuntimeException("Refusing non-gstatic font URL: {$url}");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_MAXFILESIZE    => self::MAX_BYTES,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $status !== 200) {
            throw new \RuntimeException(
                "Font download failed ({$status}" . ($error !== '' ? ", {$error}" : '') . "): {$url}"
            );
        }
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('Font download returned ' . strlen($body) . " bytes: {$url}");
        }
        return $body;
    }
}
