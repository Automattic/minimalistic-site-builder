<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Link targets and anchors exposed by serialized block markup. */
final class LinkTargets
{
    private const HTML_HREF_PATTERN =
        '/\bhref\s*=\s*(?:(["\'])(.*?)\1|([^\s"\'=<>`]+))/is';

    /** @return list<string> */
    public static function hrefsIn(string $markup): array
    {
        return self::htmlAttributeValues(self::HTML_HREF_PATTERN, $markup);
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

    /** @return list<string> */
    public static function allTargets(string $markup): array
    {
        return array_merge(
            self::hrefsIn($markup),
            self::urlAttrsIn($markup),
            self::hrefAttrsIn($markup),
            self::textLinkHrefAttrsIn($markup),
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
        $decoded = html_entity_decode($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $colon = strpos($decoded, ':');
        if ($colon === false) {
            return $decoded;
        }
        $scheme = preg_replace('/[\x00-\x20]+/', '', substr($decoded, 0, $colon)) ?? '';
        return $scheme . substr($decoded, $colon);
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
        $pattern = '/"' . preg_quote($key, '/') . '"\s*:\s*"([^"]*)"/';
        foreach (preg_match_all($pattern, $markup, $m) ? $m[1] : [] as $value) {
            $out[] = self::normalizeTarget($value);
        }
        return $out;
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
