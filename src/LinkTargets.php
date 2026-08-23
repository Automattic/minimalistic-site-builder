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
        $out = [];
        foreach (preg_match_all('/"url"\s*:\s*"([^"]*)"/', $markup, $m) ? $m[1] : [] as $url) {
            $out[] = str_replace('\\/', '/', $url);
        }
        return $out;
    }

    /** @return list<string> */
    public static function allTargets(string $markup): array
    {
        return array_merge(self::hrefsIn($markup), self::urlAttrsIn($markup));
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

    /** javascript:/data:/vbscript: — rewrite these destinations to `#`. */
    public static function isDangerousScheme(string $target): bool
    {
        return preg_match('/^(?:javascript|data|vbscript):/i', $target) === 1;
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
            static fn (array $match): string => $match[2] !== null ? $match[2] : (string) $match[3],
            $matches
        );
    }
}
