<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pull reference URLs out of a user's brief. Deterministic — no model call.
 *
 * The bare-host branch is the risky one: a narrow TLD allowlist rejects prose
 * collisions such as "it.Store". The left boundary only prevents a match from
 * starting inside a larger host or path token. Explicit http(s) URLs remain
 * available for every valid TLD.
 */
final class InspirationUrls
{
    /** Most references analyzed per build. Beyond this they stop adding signal. */
    public const MAX = 3;

    /** Extensions that mark a URL as an asset to embed, not a site to learn from. */
    private const ASSET_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'ico',
        'pdf', 'zip', 'gz', 'tar', 'mp4', 'mov', 'webm', 'mp3', 'wav',
        'css', 'js', 'json', 'xml', 'txt',
    ];

    /**
     * TLDs common enough in briefs to be worth matching without a scheme.
     * A scheme-ful URL bypasses this list entirely.
     */
    private const BARE_HOST_TLDS = [
        'com', 'org', 'net', 'io', 'dev', 'uk', 'de', 'fr', 'es', 'nl', 'ca', 'au',
        'jp', 'br', 'edu', 'gov',
    ];

    /** @return list<string> normalized HTTP(S) URLs, capped and deduplicated */
    public static function detect(string $text): array
    {
        $found = [];
        foreach (self::candidates($text) as $candidate) {
            $url = self::normalize($candidate);
            if ($url === null) {
                continue;
            }
            $key = self::dedupeKey($url);
            if (isset($found[$key])) {
                continue;
            }
            $found[$key] = $url;
            if (count($found) >= self::MAX) {
                break;
            }
        }
        return array_values($found);
    }

    /** @return list<string> raw substrings that might be URLs, in order */
    private static function candidates(string $text): array
    {
        // Repair unrelated malformed bytes before Unicode-aware filtering. A
        // raw fallback would preserve URLs but also expose domains inside emails.
        $text = mb_scrub($text, 'UTF-8');

        // Emails first: consume genuine address tokens without swallowing an
        // @handle that belongs to a URL path.
        $withoutEmails = preg_replace(
            '~(?<![\w./:])[^\s@/]+@[^\s@/]+\.[a-z]{2,}~iu',
            ' ',
            $text
        );
        if (is_string($withoutEmails)) {
            $text = $withoutEmails;
        }

        $out = [];
        $pattern = '~(?:https?://[^\s<>"\']+)'
            . '|(?:(?<![A-Za-z0-9._/-])(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}'
            . '(?::\d{1,5})?(?:/[^\s<>"\']*)?)~i';
        if (preg_match_all($pattern, $text, $matches) && isset($matches[0])) {
            foreach ($matches[0] as $match) {
                $out[] = (string) $match;
            }
        }
        return $out;
    }

    /** Normalize one candidate to an HTTP(S) URL, or null if it is not a reference. */
    private static function normalize(string $raw): ?string
    {
        $url = self::trimTrailingPunctuation(trim($raw));
        if ($url === '') {
            return null;
        }

        $hasScheme = (bool) preg_match('~^https?://~i', $url);
        if (!$hasScheme) {
            $authority = explode('/', $url, 2)[0];
            if (str_contains($authority, '@')) {
                return null;
            }
            $host = strtolower((string) preg_replace('/:\d+$/', '', $authority));
            $tld = strrchr($host, '.');
            if ($tld === false || !in_array(substr($tld, 1), self::BARE_HOST_TLDS, true)) {
                return null;
            }
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!self::isPublicHost($host)) {
            return null;
        }
        if (self::isAssetPath($parts['path'] ?? '')) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Courtesy-filter literal and obvious local hosts. This is a syntax filter,
     * NOT an SSRF boundary — it rejects IP literals and obvious local suffixes,
     * but a public DNS name resolving to a private address passes, and a
     * permitted URL may still redirect somewhere private.
     *
     * That gap used to be someone else's problem: the only analyzer posted the
     * URL to a remote endpoint that did its own DNS-aware checking. The local
     * analyzer fetches the URL from THIS host with a real browser, so on that
     * path nothing downstream re-checks. Whoever runs a build for someone else
     * owns that boundary and must add resolution-time filtering here.
     */
    private static function isPublicHost(string $host): bool
    {
        $host = trim($host, '[]');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }
        foreach (['.local', '.internal', '.test', '.example', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        $labelsAreNumericOrHex = true;
        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^(?:0x[0-9a-f]+|\d+)$/i', $label)) {
                $labelsAreNumericOrHex = false;
                break;
            }
        }
        if ($labelsAreNumericOrHex) {
            return false;
        }
        return str_contains($host, '.');
    }

    /** Drop sentence punctuation while retaining a balanced closing parenthesis in the path. */
    private static function trimTrailingPunctuation(string $url): string
    {
        $url = rtrim($url, ".,;:!?]}'\"");
        while (
            str_ends_with($url, ')')
            && substr_count($url, ')') > substr_count($url, '(')
        ) {
            $url = substr($url, 0, -1);
        }
        return $url;
    }

    private static function isAssetPath(string $path): bool
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, self::ASSET_EXTENSIONS, true);
    }

    /** Host + path without a trailing slash, so "a.com" and "a.com/" are one URL. */
    private static function dedupeKey(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return $host . $port . $path;
    }
}
