<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Header and footer chrome must not include a Home item on either graph.
 *
 * `wp:site-title` and `wp:site-logo` already link to the front page. A Home
 * entry — `wp:home-link`, a `wp:navigation-link` whose label is the front
 * page title, a `wp:page-list` (which always renders every page including
 * Home), or an HTML `<a>` with that label in the chrome part — is redundant.
 * HTML-first designs often put that item in a flex row rather than a `<nav>`
 * landmark; the strip covers both.
 *
 * Callers pass the site's pages so the front page title is language-accurate
 * and a replaced page-list can keep the inner pages. The site name is never
 * treated as a Home label (the wordmark may share the front page's title).
 * Idempotent.
 */
final class HeaderNav
{
    /**
     * @param list<array<string,mixed>> $pages
     * @param 'header'|'footer' $part
     * @return array{markup:string,notes:list<string>,warnings:list<string>}
     */
    public static function withoutHomeItems(
        string $markup,
        array $pages,
        string $part = 'header',
        string $siteName = '',
    ): array {
        $front = self::frontPage($pages);
        $notes = [];
        $warnings = [];

        [$markup, $blockNotes, $blockWarnings] = self::withoutHomeBlocks(
            $markup,
            $front,
            $pages,
            $part,
            $siteName,
        );
        array_push($notes, ...$blockNotes);
        array_push($warnings, ...$blockWarnings);

        [$markup, $htmlNotes] = self::withoutHtmlHomeAnchors($markup, $front, $part, $siteName);
        array_push($notes, ...$htmlNotes);

        return [
            'markup' => $markup,
            'notes' => $notes,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @return array{title:string,path:string,slug:string}
     */
    private static function frontPage(array $pages): array
    {
        foreach ($pages as $page) {
            if (!empty($page['front'])) {
                return self::pageIdentity($page);
            }
        }
        foreach ($pages as $page) {
            if (self::pagePath($page) === '/') {
                return self::pageIdentity($page);
            }
        }
        return ['title' => 'Home', 'path' => '/', 'slug' => 'home'];
    }

    /** @param array<string,mixed> $page */
    private static function pageIdentity(array $page): array
    {
        $title = trim((string) ($page['title'] ?? $page['label'] ?? ''));
        $slug = trim((string) ($page['slug'] ?? ''));
        $path = self::pagePath($page);
        return [
            'title' => $title !== '' ? $title : 'Home',
            'path' => $path !== '' ? $path : '/',
            'slug' => $slug !== '' ? $slug : 'home',
        ];
    }

    /** @param array<string,mixed> $page */
    private static function pagePath(array $page): string
    {
        $path = trim((string) ($page['path'] ?? ''));
        return $path === '' ? '' : ($path === '/' ? '/' : rtrim($path, '/'));
    }

    /**
     * @param array{title:string,path:string,slug:string} $front
     * @param list<array<string,mixed>> $pages
     * @param 'header'|'footer' $part
     * @return array{0:string,1:list<string>,2:list<string>}
     */
    private static function withoutHomeBlocks(
        string $markup,
        array $front,
        array $pages,
        string $part,
        string $siteName,
    ): array {
        $document = BlockMarkup::parse($markup);
        $edits = [];
        $pageLists = [];
        $notes = [];
        $warnings = [];
        $nav = self::navPhrase($part);

        foreach ($document->indices() as $index) {
            $name = self::canonicalName($document->name($index));
            if ($name === 'home-link') {
                $edit = self::blockSpan($document, $index, $markup);
                if ($edit === null) {
                    $warnings[] = self::unprovenWarning($part, 'wp:home-link', $document->openingComment($index));
                    continue;
                }
                $edits[] = $edit + [
                    'replacement' => '',
                    'note' => "removed wp:home-link from {$nav} (the site title already links home)",
                ];
                continue;
            }
            if (($name === 'navigation-link' || $name === 'navigation-submenu')
                && self::isHomeNavigationLink($document->attrs($index) ?? [], $front, $siteName)
            ) {
                $edit = self::blockSpan($document, $index, $markup);
                if ($edit === null) {
                    $warnings[] = self::unprovenWarning($part, 'wp:' . $name, $document->openingComment($index));
                    continue;
                }
                $children = $document->children($index);
                if ($children !== [] && !$document->isVoid($index)) {
                    $edits[] = $edit + [
                        'replacement' => $document->innerHtml($index),
                        'note' => "unwrapped Home {$name} in {$nav}; kept nested destinations",
                    ];
                    continue;
                }
                $edits[] = $edit + [
                    'replacement' => '',
                    'note' => 'removed navigation-link "' . $front['title']
                        . "\" from {$nav} (the site title already links home)",
                ];
                continue;
            }
            if ($name === 'page-list' && $pages !== []) {
                $edit = self::blockSpan($document, $index, $markup);
                if ($edit === null) {
                    $warnings[] = self::unprovenWarning($part, 'wp:page-list', $document->openingComment($index));
                    continue;
                }
                $pageLists[] = $edit;
            }
        }

        $inner = self::innerPageLinkComments($pages, $front);
        $chunks = self::partitionLinks($inner, max(1, count($pageLists)));
        foreach ($pageLists as $i => $edit) {
            $replacement = implode('', $chunks[$i] ?? []);
            $edits[] = $edit + [
                'replacement' => $replacement,
                'note' => $replacement === ''
                    ? "removed wp:page-list from {$nav} (it would render a self-referential Home link)"
                    : "replaced wp:page-list in {$nav} with inner-page links (page-list always includes the front page)",
            ];
        }

        $edits = self::outermostEdits($edits);
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($edits as $edit) {
            $markup = substr_replace(
                $markup,
                $edit['replacement'],
                $edit['start'],
                $edit['end'] - $edit['start'],
            );
            $notes[] = $edit['note'];
        }

        return [$markup, $notes, $warnings];
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @param array{title:string,path:string,slug:string} $front
     * @return list<string>
     */
    private static function innerPageLinkComments(array $pages, array $front): array
    {
        $links = [];
        foreach ($pages as $page) {
            if (!empty($page['front']) || self::pagePath($page) === '/') {
                continue;
            }
            $title = trim((string) ($page['title'] ?? $page['label'] ?? ''));
            $path = trim((string) ($page['path'] ?? ''));
            if ($title === '' || $path === '' || self::labelsMatch($title, $front['title'])) {
                continue;
            }
            $links[] = BlockMarkup::serializeComment(
                'navigation-link',
                ['label' => $title, 'url' => $path, 'kind' => 'custom'],
                true,
            );
        }
        return $links;
    }

    /**
     * Split inner-page links across N page-list slots in document order
     * (split-nav's two navs each get a slice, not a full copy).
     *
     * @param list<string> $links
     * @return list<list<string>>
     */
    private static function partitionLinks(array $links, int $slots): array
    {
        if ($slots <= 1) {
            return [$links];
        }
        if ($links === []) {
            return array_fill(0, $slots, []);
        }
        $size = (int) ceil(count($links) / $slots);
        $chunks = array_values(array_chunk($links, max(1, $size)));
        while (count($chunks) < $slots) {
            $chunks[] = [];
        }
        return $chunks;
    }

    /**
     * Keep the outermost splice when a parent and a nested child both matched.
     * Inner edits run first (higher start) and would stale the parent's end.
     *
     * @param list<array{start:int,end:int,replacement:string,note:string}> $edits
     * @return list<array{start:int,end:int,replacement:string,note:string}>
     */
    private static function outermostEdits(array $edits): array
    {
        $kept = [];
        foreach ($edits as $edit) {
            foreach ($edits as $other) {
                if ($other === $edit) {
                    continue;
                }
                $contains = $other['start'] <= $edit['start'] && $other['end'] >= $edit['end'];
                $strict = $other['start'] < $edit['start'] || $other['end'] > $edit['end'];
                if ($contains && $strict) {
                    continue 2;
                }
            }
            $kept[] = $edit;
        }
        return $kept;
    }

    /** @return array{start:int,end:int}|null */
    private static function blockSpan(BlockMarkup $document, int $index, string $markup): ?array
    {
        if (!$document->isStructurallySafe($index)) {
            return null;
        }
        $start = $document->openingOffset($index);
        $end = $document->endOffset($index);
        if ($end === null) {
            return null;
        }
        while ($end < strlen($markup) && str_contains(" \t\n\r", $markup[$end])) {
            $next = $end + 1;
            if ($next < strlen($markup) && $markup[$next] === '<') {
                break;
            }
            $end++;
        }
        return ['start' => $start, 'end' => $end];
    }

    /** @param array<string,mixed> $attrs @param array{title:string,path:string,slug:string} $front */
    private static function isHomeNavigationLink(array $attrs, array $front, string $siteName): bool
    {
        $label = is_string($attrs['label'] ?? null) ? $attrs['label'] : '';
        if ($siteName !== '' && self::labelsMatch($label, $siteName)) {
            return false;
        }
        if (!self::labelsMatch($label, $front['title'])) {
            return false;
        }
        $url = is_string($attrs['url'] ?? null) ? $attrs['url'] : '';
        return self::isHomeUrl($url, $front);
    }

    /**
     * @param array{title:string,path:string,slug:string} $front
     * @param 'header'|'footer' $part
     * @return array{0:string,1:list<string>}
     */
    private static function withoutHtmlHomeAnchors(
        string $markup,
        array $front,
        string $part,
        string $siteName,
    ): array {
        $notes = [];
        $edits = [];
        $nav = self::navPhrase($part);
        $protected = self::identityRanges($markup);
        foreach (self::anchorsIn($markup, 0, strlen($markup)) as $anchor) {
            if (self::offsetIsProtected($anchor['start'], $protected)) {
                continue;
            }
            $label = self::visibleLabel(substr(
                $markup,
                $anchor['innerStart'],
                $anchor['innerEnd'] - $anchor['innerStart'],
            ));
            if ($siteName !== '' && self::labelsMatch($label, $siteName)) {
                continue;
            }
            if (!self::labelsMatch($label, $front['title'])) {
                continue;
            }
            if (!self::isHomeUrl($anchor['href'], $front)) {
                continue;
            }
            $edits[] = self::expandWrapper($markup, $anchor['start'], $anchor['end']);
        }
        if ($edits === []) {
            return [$markup, $notes];
        }
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        $kept = [];
        foreach ($edits as $edit) {
            foreach ($kept as $prior) {
                if ($edit['start'] < $prior['end'] && $edit['end'] > $prior['start']) {
                    continue 2;
                }
            }
            $kept[] = $edit;
            $markup = substr_replace($markup, '', $edit['start'], $edit['end'] - $edit['start']);
            $notes[] = "removed HTML Home link from {$nav} (the site title already links home)";
        }
        return [$markup, $notes];
    }

    /**
     * Byte ranges of dynamic identity blocks whose homepage link must survive.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function identityRanges(string $markup): array
    {
        $document = BlockMarkup::parse($markup);
        $ranges = [];
        foreach ($document->indices() as $index) {
            $name = self::canonicalName($document->name($index));
            if (!in_array($name, ['site-title', 'site-logo'], true)) {
                continue;
            }
            $end = $document->endOffset($index);
            if ($end === null) {
                continue;
            }
            $ranges[] = [$document->openingOffset($index), $end];
        }
        return $ranges;
    }

    /** @param list<array{0:int,1:int}> $ranges */
    private static function offsetIsProtected(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{start:int,end:int,innerStart:int,innerEnd:int,href:string}>
     */
    private static function anchorsIn(string $markup, int $from, int $to): array
    {
        $slice = substr($markup, $from, $to - $from);
        if (preg_match_all('/<a\b[^>]*>/i', $slice, $opens, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $anchors = [];
        foreach ($opens[0] as [$open, $relative]) {
            $start = $from + $relative;
            $innerStart = $start + strlen($open);
            $selfClosing = preg_match('/\/\s*>\z/', $open) === 1;
            if ($selfClosing) {
                continue;
            }
            $close = self::matchingClose($markup, $innerStart, 'a');
            if ($close === null || $close > $to) {
                continue;
            }
            $closeEnd = strpos($markup, '>', $close);
            if ($closeEnd === false || $closeEnd + 1 > $to) {
                continue;
            }
            $anchors[] = [
                'start' => $start,
                'end' => $closeEnd + 1,
                'innerStart' => $innerStart,
                'innerEnd' => $close,
                'href' => self::anchorHref($open),
            ];
        }
        return $anchors;
    }

    private static function matchingClose(string $markup, int $from, string $name): ?int
    {
        $needle = '</' . $name;
        $offset = $from;
        $depth = 1;
        $length = strlen($markup);
        while ($offset < $length) {
            $nextOpen = stripos($markup, '<' . $name, $offset);
            $nextClose = stripos($markup, $needle, $offset);
            if ($nextClose === false) {
                return null;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $after = $nextOpen + 1 + strlen($name);
                if ($after < $length && preg_match('/\A[\s\/>]/', $markup[$after]) !== 1) {
                    $offset = $after;
                    continue;
                }
                $depth++;
                $offset = $after;
                continue;
            }
            $after = $nextClose + strlen($needle);
            if ($after < $length && preg_match('/\A[\s>]/', $markup[$after]) !== 1) {
                $offset = $after;
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $nextClose;
            }
            $offset = $after;
        }
        return null;
    }

    /** @return array{start:int,end:int} */
    private static function expandWrapper(string $markup, int $start, int $end): array
    {
        foreach (['li', 'p'] as $tag) {
            $prefix = substr($markup, 0, $start);
            if (preg_match('/<' . $tag . '\b[^>]*>\s*\z/i', $prefix, $open) !== 1) {
                continue;
            }
            $suffix = substr($markup, $end);
            if (preg_match('/\A\s*<\/' . $tag . '>/i', $suffix, $close) !== 1) {
                continue;
            }
            return [
                'start' => strlen($prefix) - strlen($open[0]),
                'end' => $end + strlen($close[0]),
            ];
        }
        return ['start' => $start, 'end' => $end];
    }

    private static function visibleLabel(string $html): string
    {
        $text = PlainText::fromMarkup($html);
        $collapsed = preg_replace('/\s+/u', ' ', trim($text));
        return $collapsed ?? trim($text);
    }

    private static function anchorHref(string $openTag): string
    {
        if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\\1/i', $openTag, $match) === 1) {
            return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/\bhref\s*=\s*([^\s>]+)/i', $openTag, $match) === 1) {
            return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /** @param array{title:string,path:string,slug:string} $front */
    private static function isHomeUrl(string $url, array $front): bool
    {
        $url = trim($url);
        if ($url === '' || $url === '#' || $url === '/') {
            return true;
        }
        if (str_starts_with($url, '#')) {
            return false;
        }
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        if (is_string($fragment) && $fragment !== '') {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : strtok($url, '?#');
        $normalized = trim((string) $path);
        $normalized = $normalized === '' || $normalized === '/' ? '/' : rtrim($normalized, '/');
        if ($normalized === '/') {
            return true;
        }
        $frontPath = $front['path'] === '/' ? '/' : rtrim($front['path'], '/');
        if ($normalized === $frontPath) {
            return true;
        }
        $slug = $front['slug'];
        return $slug !== '' && $normalized === '/' . $slug;
    }

    private static function labelsMatch(string $left, string $right): bool
    {
        return self::normalizedLabel($left) !== ''
            && self::normalizedLabel($left) === self::normalizedLabel($right);
    }

    private static function normalizedLabel(string $label): string
    {
        $decoded = PlainText::fromMarkup($label);
        $words = preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim($decoded));
        $collapsed = preg_replace('/\s+/u', ' ', trim($words ?? $decoded));
        return mb_strtolower($collapsed ?? trim($decoded), 'UTF-8');
    }

    private static function canonicalName(string $name): string
    {
        return str_starts_with($name, 'core/') ? substr($name, 5) : $name;
    }

    /** @param 'header'|'footer' $part */
    private static function navPhrase(string $part): string
    {
        return $part === 'footer' ? 'footer navigation' : 'header navigation';
    }

    /** @param 'header'|'footer' $part */
    private static function unprovenWarning(string $part, string $block, string $authored): string
    {
        return "file='theme/parts/{$part}.html'; block='{$block}'; authored="
            . Warnings::value($authored)
            . '; delivered=unchanged; disposition=left the block untouched because its delimiter '
            . 'boundary could not be proven; removing it would risk an unmatched closing comment';
    }
}
