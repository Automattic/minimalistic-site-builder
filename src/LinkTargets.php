<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Link targets and anchors exposed by serialized block markup. */
final class LinkTargets
{
    private const HTML_HREF_PATTERN =
        '/\bhref\s*=\s*(?:(["\'])(.*?)\1|([^\s"\'=<>`]+))/is';

    private const HTML_POSTER_PATTERN =
        '/\bposter\s*=\s*(?:(["\'])(.*?)\1|([^\s"\'=<>`]+))/is';

    /** @return list<string> */
    public static function hrefsIn(string $markup): array
    {
        return self::htmlAttributeValues(self::HTML_HREF_PATTERN, $markup);
    }

    /** Rendered video poster attributes. Distinct from JSON "poster". */
    /** @return list<string> */
    public static function postersIn(string $markup): array
    {
        return self::htmlAttributeValues(self::HTML_POSTER_PATTERN, $markup);
    }

    /** @return list<string> */
    public static function urlAttrsIn(string $markup): array
    {
        return self::jsonStringAttrs('url', $markup);
    }

    /**
     * Click targets stored as block-JSON "href" (core/image, core/file,
     * core/media-text). Distinct from rendered HTML hrefs.
     *
     * @return list<string>
     */
    public static function hrefAttrsIn(string $markup): array
    {
        return self::jsonStringAttrs('href', $markup);
    }

    /**
     * Download-text click targets stored as block-JSON "textLinkHref"
     * (core/file). Distinct from rendered HTML hrefs and JSON "href".
     *
     * @return list<string>
     */
    public static function textLinkHrefAttrsIn(string $markup): array
    {
        return self::jsonStringAttrs('textLinkHref', $markup);
    }

    /**
     * Media sources stored as block-JSON "src" (core/video, core/audio,
     * core/embed). Distinct from JSON "url" on images.
     *
     * @return list<string>
     */
    public static function srcAttrsIn(string $markup): array
    {
        return self::jsonStringAttrs('src', $markup);
    }

    /**
     * Video poster frames stored as block-JSON "poster" (core/video).
     *
     * @return list<string>
     */
    public static function posterAttrsIn(string $markup): array
    {
        return self::jsonStringAttrs('poster', $markup);
    }

    /** @return list<string> */
    public static function allTargets(string $markup): array
    {
        return array_merge(
            self::hrefsIn($markup),
            self::postersIn($markup),
            self::urlAttrsIn($markup),
            self::hrefAttrsIn($markup),
            self::textLinkHrefAttrsIn($markup),
            self::srcAttrsIn($markup),
            self::posterAttrsIn($markup),
        );
    }

    /**
     * Anchor names the markup exposes: HTML id attributes plus block-JSON
     * "anchor" attributes (they mirror each other in serialized markup, but
     * either alone still resolves).
     *
     * @return array<string,true>
     */
    public static function anchorsIn(string $markup): array
    {
        $set = [];
        foreach (preg_match_all('/\bid="([^"]+)"/', $markup, $m) ? $m[1] : [] as $id) {
            $set[$id] = true;
        }
        foreach (preg_match_all('/"anchor"\s*:\s*"([^"]+)"/', $markup, $m) ? $m[1] : [] as $id) {
            $set[$id] = true;
        }
        return $set;
    }

    /**
     * Root-relative URLs that are theme static files (not page routes).
     * GenerateImagesStep rewrites theme:./assets/* to
     * /wp-content/themes/{slug}/assets/*; cover/image block "url" attrs and
     * img src then look like paths and must not be judged as page links.
     */
    public static function isThemeAssetPath(string $path): bool
    {
        if (str_contains($path, '/wp-content/themes/') && str_contains($path, '/assets/')) {
            return true;
        }
        return (bool) preg_match('/\.(?:jpe?g|png|gif|webp|svg|css|js|woff2?|ttf|eot|ico)(?:$|\?)/i', $path);
    }

    /** http/https/mailto/tel/sms — the schemes a generated pattern may keep. */
    public static function isSafeAbsoluteTarget(string $target): bool
    {
        return preg_match('/^(?:https?:|mailto:|tel:|sms:)/i', $target) === 1;
    }

    /**
     * Decode JSON string escapes and HTML entities so collectors and rewriters
     * judge the same destination Gutenberg will.
     */
    public static function normalizeTarget(string $target): string
    {
        $trimmed = trim($target);
        $fromJson = json_decode('"' . $trimmed . '"');
        if (is_string($fromJson)) {
            $trimmed = $fromJson;
        }
        $decoded = self::decodeNumericEntities($trimmed);
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = self::decodeUnterminatedNamedEntities($decoded);
        $colon = strpos($decoded, ':');
        if ($colon === false) {
            return $decoded;
        }
        $scheme = preg_replace('/[\x00-\x20\x7F]+/', '', substr($decoded, 0, $colon)) ?? '';
        return $scheme . substr($decoded, $colon);
    }

    /**
     * PHP html_entity_decode(ENT_HTML5) refuses CR and other C0 controls.
     * Walk numerics with chr() like MarkupSanitizer. Take the longest
     * prefix of leading zeros plus 1-2 hex / 1-3 decimal digits so
     * &#x0073cript: becomes javascript: and the next scheme letter stays.
     */
    private static function decodeNumericEntities(string $value): string
    {
        $out = '';
        $length = strlen($value);
        $i = 0;
        while ($i < $length) {
            if ($value[$i] === '&' && $i + 2 < $length && $value[$i + 1] === '#') {
                $hex = $value[$i + 2] === 'x' || $value[$i + 2] === 'X';
                $digitStart = $i + ($hex ? 3 : 2);
                $digits = '';
                $j = $digitStart;
                while ($j < $length && ($hex ? ctype_xdigit($value[$j]) : ctype_digit($value[$j]))) {
                    $digits .= $value[$j];
                    $j++;
                }
                $codepoint = null;
                $used = 0;
                $maxSignificant = $hex ? 2 : 3;
                for ($n = 1, $digitCount = strlen($digits); $n <= $digitCount; $n++) {
                    $prefix = substr($digits, 0, $n);
                    $significant = ltrim($prefix, '0');
                    if ($significant === '') {
                        continue;
                    }
                    if (strlen($significant) > $maxSignificant) {
                        break;
                    }
                    $candidate = $hex ? hexdec($significant) : (int) $significant;
                    if ($candidate > 0 && $candidate <= 0x7f) {
                        $codepoint = $candidate;
                        $used = $n;
                    }
                }
                if ($codepoint !== null) {
                    $out .= chr($codepoint);
                    $i = $digitStart + $used;
                    if ($i < $length && $value[$i] === ';') {
                        $i++;
                    }
                    continue;
                }
            }
            $out .= $value[$i];
            $i++;
        }
        return $out;
    }

    private static function decodeUnterminatedNamedEntities(string $value): string
    {
        $value = preg_replace('/&colon(?!;)/i', ':', $value) ?? $value;
        $value = preg_replace('/&tab(?!;)/i', "\t", $value) ?? $value;
        return $value;
    }

    /** javascript:/data:/vbscript: — rewrite these destinations to `#`. */
    public static function isDangerousScheme(string $target): bool
    {
        return preg_match('/^(?:javascript|data|vbscript):/i', self::normalizeTarget($target)) === 1;
    }

    /** @return list<string> */
    private static function jsonStringAttrs(string $key, string $markup): array
    {
        $out = [];
        $wanted = strtolower($key);
        foreach (self::jsonStringPairs($markup) as [$rawKey, $rawValue]) {
            $decodedKey = json_decode('"' . $rawKey . '"');
            if (!is_string($decodedKey) || strtolower($decodedKey) !== $wanted) {
                continue;
            }
            $out[] = self::normalizeTarget($rawValue);
        }
        return $out;
    }

    /** @return list<array{0:string,1:string}> */
    private static function jsonStringPairs(string $markup): array
    {
        $pairs = [];
        $pattern = '/"((?:\\\\.|[^"\\\\])*)"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/';
        if (!preg_match_all($pattern, $markup, $m)) {
            return $pairs;
        }
        foreach ($m[1] as $i => $rawKey) {
            $pairs[] = [$rawKey, $m[2][$i]];
        }
        return $pairs;
    }

    /** @return list<string> */
    private static function htmlAttributeValues(string $pattern, string $markup): array
    {
        if (!preg_match_all(
            $pattern,
            $markup,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        )) {
            return [];
        }
        return array_map(
            static fn (array $match): string => self::normalizeTarget(
                $match[2] !== null ? $match[2] : (string) $match[3]
            ),
            $matches
        );
    }
}
