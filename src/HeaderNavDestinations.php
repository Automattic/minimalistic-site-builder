<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * HTML-first headers often convert a designed <nav> into links that all
 * point at #hero. Map those labels onto the real pages.json paths.
 */
final class HeaderNavDestinations
{
    /**
     * @param list<array<string,mixed>> $pages
     * @return array{0:string,1:list<string>}
     */
    public static function rewrite(string $markup, array $pages): array
    {
        $byKey = self::destinations($pages);
        if ($byKey === []) {
            return [$markup, []];
        }

        $repairs = [];
        $rewritten = preg_replace_callback(
            '/<!-- wp:navigation-link\s+(\{.*?\})\s+\/-->/s',
            static function (array $match) use ($byKey, &$repairs): string {
                try {
                    $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return $match[0];
                }
                if (!is_array($attrs)) {
                    return $match[0];
                }
                $label = is_string($attrs['label'] ?? null) ? $attrs['label'] : '';
                $url = is_string($attrs['url'] ?? null) ? $attrs['url'] : '';
                $path = self::pathFor($label, $url, $byKey);
                if ($path === null || $path === $url) {
                    return $match[0];
                }
                $attrs['url'] = $path;
                $repairs[] = "header nav '{$label}' authored {$url} delivered {$path}";
                $json = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return is_string($json)
                    ? '<!-- wp:navigation-link ' . $json . ' /-->'
                    : $match[0];
            },
            $markup,
        );
        if (!is_string($rewritten)) {
            $rewritten = $markup;
        }

        $rewritten = preg_replace_callback(
            '/<a\b([^>]*\bhref=")([^"]*)("[^>]*)>(.*?)<\/a>/is',
            static function (array $match) use ($byKey, &$repairs): string {
                $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
                if (self::leaveAlone($url) && !self::isDummy($url)) {
                    return $match[0];
                }
                $label = trim(html_entity_decode(strip_tags($match[4]), ENT_QUOTES | ENT_HTML5));
                $path = self::pathFor($label, $url, $byKey);
                if ($path === null || $path === $url) {
                    return $match[0];
                }
                $repairs[] = "header link '{$label}' authored {$url} delivered {$path}";
                return '<a' . $match[1] . htmlspecialchars($path, ENT_QUOTES) . $match[3] . '>'
                    . $match[4] . '</a>';
            },
            $rewritten,
        );

        $rewritten = is_string($rewritten) ? $rewritten : $markup;
        [$rewritten, $classRepairs] = self::foldAnchorClassName($rewritten);
        array_push($repairs, ...$classRepairs);
        [$rewritten, $brandRepairs] = self::ensureBrandHomeLink($rewritten, $byKey);
        array_push($repairs, ...$brandRepairs);

        return [$rewritten, $repairs];
    }

    /**
     * Convert-authored `anchorClassName` is not a registered navigation-link
     * attribute; fold it into `className` so fix-blocks can serialize.
     *
     * @return array{0:string,1:list<string>}
     */
    public static function foldAnchorClassName(string $markup): array
    {
        $repairs = [];
        $rewritten = preg_replace_callback(
            '/<!-- wp:navigation-link\s+(\{.*?\})\s+\/-->/s',
            static function (array $match) use (&$repairs): string {
                try {
                    $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return $match[0];
                }
                if (!is_array($attrs)) {
                    return $match[0];
                }
                $hadAnchor = isset($attrs['anchorClassName']);
                $extra = $hadAnchor && is_string($attrs['anchorClassName'])
                    ? trim($attrs['anchorClassName'])
                    : '';
                unset($attrs['anchorClassName']);
                $existing = is_string($attrs['className'] ?? null) ? trim($attrs['className']) : '';
                $merged = trim($existing . ' ' . $extra);
                // A convert snapshot of the homepage current item must not
                // paint Início current on every inner page. WordPress marks
                // the live item with current-menu-item at render.
                $tokens = preg_split('/\s+/', $merged, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $tokens = array_values(array_filter(
                    $tokens,
                    static fn (string $token): bool => $token !== 'is-current',
                ));
                $classChanged = $tokens !== (preg_split('/\s+/', $existing, -1, PREG_SPLIT_NO_EMPTY) ?: []);
                if ($tokens === []) {
                    unset($attrs['className']);
                } else {
                    $attrs['className'] = implode(' ', $tokens);
                }
                if (!$hadAnchor && !$classChanged) {
                    return $match[0];
                }
                $json = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($json)) {
                    return $match[0];
                }
                $repairs[] = $hadAnchor
                    ? 'header nav folded anchorClassName into className'
                    : 'header nav stripped hardcoded is-current';
                return '<!-- wp:navigation-link ' . $json . ' /-->';
            },
            $markup,
        );
        return [is_string($rewritten) ? $rewritten : $markup, $repairs];
    }

    /**
     * @param array<string,string> $byKey
     * @return array{0:string,1:list<string>}
     */
    public static function ensureBrandHomeLink(string $markup, array $byKey): array
    {
        $home = $byKey['inicio'] ?? $byKey['home'] ?? '/';
        $href = htmlspecialchars($home, ENT_QUOTES);
        if (preg_match('/<a\b[^>]*\bclass="[^"]*\bbrand\b/', $markup) === 1
            || preg_match('/<a\b[^>]*>[^<]*class="[^"]*\bbrand\b/', $markup) === 1
        ) {
            return [$markup, []];
        }

        $rewritten = preg_replace(
            '/<span class="brand">((?:<span class="brand-(?:mark|full)">.*?<\/span>)*)<\/span>/s',
            '<a class="brand" href="' . $href . '">$1</a>',
            $markup,
            1,
            $nested,
        );
        if (is_string($rewritten) && $nested > 0) {
            return [$rewritten, ["header brand wrapped as a home link to {$home}"]];
        }

        $rewritten = preg_replace(
            '/<span class="brand">([^<]*)<\/span>/',
            '<a class="brand" href="' . $href . '">$1</a>',
            $markup,
            1,
            $plain,
        );
        if (is_string($rewritten) && $plain > 0) {
            return [$rewritten, ["header brand wrapped as a home link to {$home}"]];
        }

        return [$markup, []];
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @return array<string,string> normalized key => path
     */
    public static function destinations(array $pages): array
    {
        $map = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $path = self::pagePath($page);
            if ($path === null) {
                continue;
            }
            $title = self::normalize((string) ($page['title'] ?? ''));
            $slug = self::normalize((string) ($page['slug'] ?? ''));
            if ($title !== '') {
                $map[$title] = $path;
            }
            if ($slug !== '') {
                $map[$slug] = $path;
            }
            if (!empty($page['front'])) {
                $map['inicio'] = $path;
                $map['home'] = $path;
                $map['start'] = $path;
            }
        }
        foreach ([
            'visita' => ['visit', 'visite', 'horarios', 'hours'],
            'visit' => ['visita', 'visite', 'horarios', 'hours', 'carteirinha'],
            'acervos' => ['acervo', 'collections', 'collection'],
            'collections' => ['acervo', 'acervos', 'collection'],
            'infantil' => ['kids', 'children', 'criancas'],
            'kids' => ['infantil', 'children', 'criancas'],
            'agenda' => ['events', 'programacao', 'programação'],
            'sobre' => ['about'],
            'about' => ['sobre'],
        ] as $have => $aliases) {
            if (!isset($map[$have])) {
                continue;
            }
            foreach ($aliases as $alias) {
                $map[self::normalize($alias)] ??= $map[$have];
            }
        }
        return $map;
    }

    /**
     * @param array<string,string> $byKey
     */
    public static function pathFor(string $label, string $url, array $byKey): ?string
    {
        if (self::leaveAlone($url) && !self::isDummy($url)) {
            return null;
        }
        $key = self::normalize($label);
        if ($key !== '' && isset($byKey[$key])) {
            return $byKey[$key];
        }
        foreach ($byKey as $candidate => $path) {
            if ($candidate !== '' && ($key === $candidate || str_contains($key, $candidate) || str_contains($candidate, $key))) {
                return $path;
            }
        }
        if (self::isDummy($url) && isset($byKey['inicio'])) {
            return $byKey['inicio'];
        }
        return null;
    }

    public static function isDummy(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return true;
        }
        return preg_match('/^#hero(?:[-_].*)?$/i', $url) === 1
            || preg_match('/^\/(?:index\.html)?#hero(?:[-_].*)?$/i', $url) === 1;
    }

    private static function leaveAlone(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        return preg_match('/^(?:mailto:|tel:|https?:|\/\/)/i', $url) === 1;
    }

    /** @param array<string,mixed> $page */
    private static function pagePath(array $page): ?string
    {
        $path = is_string($page['path'] ?? null) ? trim($page['path']) : '';
        if ($path !== '') {
            return str_starts_with($path, '/') ? $path : '/' . $path;
        }
        $slug = is_string($page['slug'] ?? null) ? trim($page['slug']) : '';
        if ($slug === '' || $slug === 'home') {
            return !empty($page['front']) || $slug === 'home' ? '/' : null;
        }
        return '/' . trim($slug, '/') . '/';
    }

    private static function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = preg_replace('/[^a-z0-9]+/i', '', $value) ?? $value;
        return $value;
    }
}
