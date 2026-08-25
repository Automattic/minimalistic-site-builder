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
        $decoded = self::decodeHtmlEntities($trimmed);
        $colon = strpos($decoded, ':');
        if ($colon === false) {
            return $decoded;
        }
        $scheme = preg_replace('/[\x00-\x20\x7F]+/', '', substr($decoded, 0, $colon)) ?? '';
        return $scheme . substr($decoded, $colon);
    }

    /** Decode the entity spellings an HTML parser accepts, including semicolonless numerics. */
    public static function decodeHtmlEntities(string $value): string
    {
        $decoded = self::decodeNumericEntities($value);
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::decodeUnterminatedNamedEntities($decoded);
    }

    /** Decode complete numeric references the way an HTML parser renders visitor-visible text. */
    public static function decodeBrowserEntities(string $value): string
    {
        $sentinel = "\u{E000}";
        while (str_contains($value, $sentinel)) {
            $sentinel .= "\u{E001}";
        }
        $numeric = [];
        $protected = preg_replace_callback(
            '/&#(?:(?:x|X)([0-9a-fA-F]+)|([0-9]+));?/',
            static function (array $match) use (&$numeric, $sentinel): string {
                $hex = ($match[1] ?? '') !== '';
                $digits = $hex ? $match[1] : $match[2];
                $codepoint = intval($digits, $hex ? 16 : 10);
                $windows1252 = [
                    0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E,
                    0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6,
                    0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
                    0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
                    0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
                    0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
                    0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
                ];
                $codepoint = $windows1252[$codepoint] ?? $codepoint;
                if ($codepoint === 0 || $codepoint > 0x10FFFF
                    || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)
                ) {
                    $codepoint = 0xFFFD;
                }
                $key = $sentinel . count($numeric) . $sentinel;
                $numeric[$key] = mb_chr($codepoint, 'UTF-8');
                return $key;
            },
            $value,
        );
        $decoded = html_entity_decode($protected ?? $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return strtr($decoded, $numeric);
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
