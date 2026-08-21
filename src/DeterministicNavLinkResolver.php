<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure byte-preserving implementation of the frozen navigation-link contract.
 */
final class DeterministicNavLinkResolver implements NavLinkResolver
{
    /** @var list<string> */
    private const RAW_TEXT_ELEMENTS = [
        'script', 'style', 'textarea', 'title', 'xmp',
        'iframe', 'noembed', 'noframes', 'noscript',
    ];

    public function resolve(
        string $markup,
        array $pages,
        string $file,
        ?string $currentPagePath,
    ): array {
        $normalizedPages = $this->normalizedPages($pages);
        $links = $this->navigationLinks($markup);
        $literalFrontPageDestinations = $currentPagePath === null
            ? $this->literalFrontPageDestinations($markup, $links, $normalizedPages)
            : [];
        $edits = [];
        $repairs = [];
        $warnings = [];

        foreach ($links as $link) {
            $opening = substr($markup, $link['start'], $link['end'] - $link['start']);
            $href = $this->hrefAttribute($opening);
            $childBytes = $link['closeStart'] === null
                ? ''
                : substr($markup, $link['end'], $link['closeStart'] - $link['end']);
            $label = $this->label($childBytes);
            $authored = $href === null
                ? null
                : html_entity_decode($href['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $ambiguousPages = [];
            $target = $this->target(
                $authored,
                $label,
                $normalizedPages,
                $currentPagePath,
                $ambiguousPages,
            );
            $inheritedFrontPageTarget = $this->inheritedFrontPageTarget(
                $link['nav'],
                $authored,
                $label,
                $normalizedPages,
                $literalFrontPageDestinations,
            );
            if ($ambiguousPages === [] && $inheritedFrontPageTarget !== null) {
                $target = $inheritedFrontPageTarget;
            }

            $block = sprintf('nav[%d]/a[%d]', $link['nav'], $link['link']);
            if ($ambiguousPages !== []) {
                $warnings[] = $this->contextRow(
                    $file,
                    $block,
                    $authored ?? 'missing',
                    $authored ?? 'missing',
                    'left ambiguous internal navigation link unchanged; '
                        . $this->candidateSummary($ambiguousPages),
                );
                continue;
            }
            if ($authored !== null && $target === $authored) {
                continue;
            }

            if ($target !== null) {
                $encodedTarget = htmlspecialchars(
                    $target,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8',
                );
                if ($href !== null) {
                    $edits[] = [
                        'start' => $link['start'] + $href['attributeStart'],
                        'end' => $link['start'] + $href['attributeEnd'],
                        'replacement' => ' href="' . $encodedTarget . '"',
                    ];
                } else {
                    $insertAt = $this->hrefInsertionOffset($opening);
                    $edits[] = [
                        'start' => $link['start'] + $insertAt,
                        'end' => $link['start'] + $insertAt,
                        'replacement' => ' href="' . $encodedTarget . '"',
                    ];
                }
                $repairs[] = $this->contextRow(
                    $file,
                    $block,
                    $authored ?? 'missing',
                    $target,
                    $href === null
                        ? 'added navigation href for resolved site destination'
                        : 'rewrote navigation href to resolved site destination',
                );
                continue;
            }

            $edits[] = [
                'start' => $link['start'],
                'end' => $link['end'],
                'replacement' => '',
            ];
            if ($link['closeStart'] !== null && $link['closeEnd'] !== null) {
                $edits[] = [
                    'start' => $link['closeStart'],
                    'end' => $link['closeEnd'],
                    'replacement' => '',
                ];
            }
            $warnings[] = $this->contextRow(
                $file,
                $block,
                $authored ?? 'missing',
                'removed',
                'unwrapped unresolvable internal navigation link; preserved child bytes',
            );
        }

        $blockLinks = $this->navigationBlockLinks($markup);
        $blockFrontPageDestinations = $currentPagePath === null
            ? $this->blockFrontPageDestinations($blockLinks, $normalizedPages)
            : [];
        foreach ($blockLinks as $link) {
            $label = $this->label($link['label']);
            $ambiguousPages = [];
            $target = $this->target(
                $link['url'],
                $label,
                $normalizedPages,
                $currentPagePath,
                $ambiguousPages,
            );
            $inheritedFrontPageTarget = $this->inheritedFrontPageTarget(
                $link['nav'],
                $link['url'],
                $label,
                $normalizedPages,
                $blockFrontPageDestinations,
            );
            if ($ambiguousPages === [] && $inheritedFrontPageTarget !== null) {
                $target = $inheritedFrontPageTarget;
            }
            $block = sprintf(
                'navigation[%d]/navigation-link[%d]',
                $link['nav'],
                $link['link'],
            );
            if ($ambiguousPages !== []) {
                $warnings[] = $this->contextRow(
                    $file,
                    $block,
                    $link['url'] ?? 'missing',
                    $link['url'] ?? 'missing',
                    'left ambiguous navigation-link block URL unchanged; '
                        . $this->candidateSummary($ambiguousPages),
                );
                continue;
            }
            if ($link['url'] !== null && $target === $link['url']) {
                continue;
            }

            if ($target !== null) {
                $encoded = json_encode(
                    $target,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
                if (!is_string($encoded)) {
                    continue;
                }
                $encodedValue = substr($encoded, 1, -1);
                if ($link['valueStart'] !== null && $link['valueEnd'] !== null) {
                    $edits[] = [
                        'start' => $link['valueStart'],
                        'end' => $link['valueEnd'],
                        'replacement' => $encodedValue,
                    ];
                } else {
                    $edits[] = [
                        'start' => $link['insertAt'],
                        'end' => $link['insertAt'],
                        'replacement' => ',"url":"' . $encodedValue . '"',
                    ];
                }
                $repairs[] = $this->contextRow(
                    $file,
                    $block,
                    $link['url'] ?? 'missing',
                    $target,
                    $link['url'] === null
                        ? 'added navigation block URL for resolved site destination'
                        : 'rewrote navigation block URL to resolved site destination',
                );
                continue;
            }

            $edits[] = [
                'start' => $link['start'],
                'end' => $link['end'],
                'replacement' => $this->safeBlockLabel($link['label']),
            ];
            $warnings[] = $this->contextRow(
                $file,
                $block,
                $link['url'] ?? 'missing',
                'removed',
                'unwrapped unresolvable navigation-link block; preserved safe decoded label bytes',
            );
        }

        usort(
            $edits,
            static fn (array $a, array $b): int => $b['start'] <=> $a['start'],
        );
        foreach ($edits as $edit) {
            $markup = substr_replace(
                $markup,
                $edit['replacement'],
                $edit['start'],
                $edit['end'] - $edit['start'],
            );
        }

        return [
            'markup' => $markup,
            'repairs' => $repairs,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param list<array{label:string,path:string,anchors:list<string>}> $pages
     * @return list<array{label:string,path:string,anchors:list<string>}>
     */
    private function normalizedPages(array $pages): array
    {
        $normalized = [];
        foreach ($pages as $page) {
            if (!isset($page['label'], $page['path'])
                || !is_string($page['label'])
                || !is_string($page['path'])
                || $page['path'] === ''
            ) {
                continue;
            }
            $anchors = [];
            foreach (($page['anchors'] ?? []) as $anchor) {
                if (is_string($anchor) && $anchor !== '') {
                    $anchors[] = ltrim($anchor, '#');
                }
            }
            $normalized[] = [
                'label' => $page['label'],
                'path' => $page['path'],
                'anchors' => array_values(array_unique($anchors)),
            ];
        }
        return $normalized;
    }

    /**
     * @param list<array{label:string,path:string,anchors:list<string>}> $pages
     * @param-out list<array{label:string,path:string,anchors:list<string>}> $ambiguousPages
     */
    private function target(
        ?string $href,
        string $label,
        array $pages,
        ?string $currentPagePath,
        array &$ambiguousPages,
    ): ?string {
        $ambiguousPages = [];
        if ($href !== null && $this->isNonSiteDestination($href)) {
            return $href;
        }

        $fragment = $this->fragment($href);
        $existingPage = $href === null ? null : $this->pageForHref($href, $pages);
        if ($existingPage !== null
            && ($fragment === null || $this->hasAnchor($existingPage, $fragment))
        ) {
            return $href;
        }

        // Page-owned content can carry a real same-page fragment whose label
        // also happens to match the page. Its valid authored destination wins;
        // shared chrome still has no current page and is rooted below.
        if ($href !== null
            && str_starts_with($href, '#')
            && $fragment !== null
            && $fragment !== ''
            && $currentPagePath !== null
        ) {
            foreach ($pages as $page) {
                if ($this->samePagePath($page['path'], $currentPagePath)
                    && $this->hasAnchor($page, $fragment)
                ) {
                    return $href;
                }
            }
        }

        $labelKey = $this->normalizedLabel($label);
        if ($labelKey !== '') {
            $matchingPages = $this->matchingPages($labelKey, $pages);
            if (count($matchingPages) > 1) {
                $ambiguousPages = $matchingPages;
                return null;
            }
            if ($matchingPages !== []) {
                $page = $matchingPages[0];
                if ($this->samePagePath($page['path'], '/')) {
                    return $page['path'];
                }
                return $fragment !== null && $this->hasAnchor($page, $fragment)
                    ? $this->deepLink($page['path'], $fragment)
                    : $page['path'];
            }
        }

        if ($fragment === null || $fragment === '') {
            return null;
        }

        // A fragment that names a page is a destination even when the same id
        // also appears as a section on other pages. sunny-ember's CTA is
        // `<a href="#contact">Book a session</a>` beside a real `contact` page,
        // and `contact` is also a section on two others: the owner search below
        // finds two owners, gives up, and the caller DELETES the item. As a raw
        // anchor that merely stayed unresolved and still rendered; inside
        // core/navigation it disappears, because the block renders its inner
        // blocks and drops the unwrapped label text.
        foreach ($pages as $page) {
            if ($fragment === $this->pageSlug($page)) {
                return $page['path'];
            }
        }

        $owners = $this->anchorOwners($pages, $fragment);
        if (count($owners) !== 1) {
            return null;
        }

        $owner = $owners[0];
        if ($currentPagePath !== null && $this->samePagePath($owner['path'], $currentPagePath)) {
            return '#' . $fragment;
        }
        return $this->deepLink($owner['path'], $fragment);
    }

    private function isNonSiteDestination(string $href): bool
    {
        $value = trim($href);
        return str_starts_with($value, '//')
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9+.-]*:/D', $value) === 1;
    }

    private function fragment(?string $href): ?string
    {
        if ($href === null) {
            return null;
        }
        $at = strpos($href, '#');
        return $at === false ? null : substr($href, $at + 1);
    }

    /**
     * @param list<array{label:string,path:string,anchors:list<string>}> $pages
     * @return array{label:string,path:string,anchors:list<string>}|null
     */
    private function pageForHref(string $href, array $pages): ?array
    {
        $at = strpos($href, '#');
        $path = $at === false ? $href : substr($href, 0, $at);
        if ($path === '') {
            return null;
        }
        foreach ($pages as $page) {
            if ($this->samePagePath($page['path'], $path)) {
                return $page;
            }
        }
        return null;
    }

    /** @param array{label:string,path:string,anchors:list<string>} $page */
    /** The page's own slug, read from its public path. */
    private function pageSlug(array $page): string
    {
        return trim((string) ($page['path'] ?? ''), '/');
    }

    private function hasAnchor(array $page, string $fragment): bool
    {
        return $fragment !== '' && in_array($fragment, $page['anchors'], true);
    }

    /**
     * @param list<array{label:string,path:string,anchors:list<string>}> $pages
     * @return list<array{label:string,path:string,anchors:list<string>}>
     */
    private function anchorOwners(array $pages, string $fragment): array
    {
        return array_values(array_filter(
            $pages,
            fn (array $page): bool => $this->hasAnchor($page, $fragment),
        ));
    }

    private function deepLink(string $path, string $fragment): string
    {
        return $path . '#' . $fragment;
    }

    private function label(string $childBytes): string
    {
        $text = html_entity_decode(strip_tags($childBytes), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', trim($text));
        return $collapsed === null ? trim($text) : $collapsed;
    }

    private function normalizedLabel(string $label): string
    {
        $decoded = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim($decoded));
        $collapsed = preg_replace('/\s+/u', ' ', trim($words ?? $decoded));
        return mb_strtolower($collapsed ?? trim($decoded), 'UTF-8');
    }

    /**
     * @param list<array{label:string,path:string,anchors:list<string>}> $pages
     * @return list<array{label:string,path:string,anchors:list<string>}>
     */
    private function matchingPages(string $labelKey, array $pages): array
    {
        $matches = [];
        foreach ($pages as $page) {
            $titleKey = $this->normalizedLabel($page['label']);
            $slugKey = $this->normalizedLabel(rawurldecode(trim($page['path'], '/')));
            if ($this->containsPhrase($titleKey, $labelKey)
                || $this->containsPhrase($slugKey, $labelKey)
            ) {
                $matches[] = $page;
            }
        }
        return $matches;
    }

    private function containsPhrase(string $candidate, string $label): bool
    {
        return $candidate !== ''
            && $label !== ''
            && str_contains(' ' . $candidate . ' ', ' ' . $label . ' ');
    }

    private function samePagePath(string $left, string $right): bool
    {
        return $this->comparablePagePath($left) === $this->comparablePagePath($right);
    }

    private function comparablePagePath(string $path): string
    {
        $path = trim($path);
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @param list<array{label:string,path:string,anchors:list<string>}> $pages */
    private function candidateSummary(array $pages): string
    {
        $candidates = array_map(
            static function (array $page): string {
                $label = json_encode($page['label'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $path = json_encode($page['path'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return (is_string($label) ? $label : $page['label'])
                    . ' (' . (is_string($path) ? $path : $page['path']) . ')';
            },
            $pages,
        );
        return 'candidates: ' . implode(', ', $candidates);
    }

    /**
     * @param list<array{start:int,end:int,closeStart:?int,closeEnd:?int,nav:int,link:int}> $links
     * @param list<array{label:string,path:string,anchors:list<string>}> $pages
     * @return array<int,array<string,string>>
     */
    private function literalFrontPageDestinations(string $markup, array $links, array $pages): array
    {
        $destinations = [];
        foreach ($links as $link) {
            $opening = substr($markup, $link['start'], $link['end'] - $link['start']);
            $href = $this->hrefAttribute($opening);
            if ($href === null) {
                continue;
            }
            $childBytes = $link['closeStart'] === null
                ? ''
                : substr($markup, $link['end'], $link['closeStart'] - $link['end']);
            $authored = html_entity_decode($href['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $target = $this->frontPageTarget($authored, $this->label($childBytes), $pages);
            if ($target !== null) {
                $destinations[$link['nav']][$authored] = $target;
            }
        }
        return $destinations;
    }

    /**
     * @param list<array{
     *   start:int,end:int,valueStart:?int,valueEnd:?int,insertAt:int,
     *   label:string,url:?string,nav:int,link:int
     * }> $links
     * @param list<array{label:string,path:string,anchors:list<string>}> $pages
     * @return array<int,array<string,string>>
     */
    private function blockFrontPageDestinations(array $links, array $pages): array
    {
        $destinations = [];
        foreach ($links as $link) {
            if ($link['url'] === null) {
                continue;
            }
            $target = $this->frontPageTarget(
                $link['url'],
                $this->label($link['label']),
                $pages,
            );
            if ($target !== null) {
                $destinations[$link['nav']][$link['url']] = $target;
            }
        }
        return $destinations;
    }

    /** @param list<array{label:string,path:string,anchors:list<string>}> $pages */
    private function frontPageTarget(string $authored, string $label, array $pages): ?string
    {
        $ambiguousPages = [];
        $target = $this->target($authored, $label, $pages, null, $ambiguousPages);
        return $ambiguousPages === []
            && $target !== null
            && $this->samePagePath($target, '/')
                ? $target
                : null;
    }

    /**
     * @param list<array{label:string,path:string,anchors:list<string>}> $pages
     * @param array<int,array<string,string>> $frontPageDestinations
     */
    private function inheritedFrontPageTarget(
        int $nav,
        ?string $authored,
        string $label,
        array $pages,
        array $frontPageDestinations,
    ): ?string {
        if ($authored === null
            || !isset($frontPageDestinations[$nav][$authored])
            || $this->matchingPages($this->normalizedLabel($label), $pages) !== []
        ) {
            return null;
        }
        return $frontPageDestinations[$nav][$authored];
    }

    /**
     * @return array{
     *   value:string,valueStart:int,valueEnd:int,attributeStart:int,attributeEnd:int
     * }|null
     */
    private function hrefAttribute(string $openingTag): ?array
    {
        foreach (MarkupSanitizer::openingTagAttributes($openingTag) as $attribute) {
            if ($attribute['name'] !== 'href'
                || $attribute['valueStart'] === null
                || $attribute['valueEnd'] === null
            ) {
                continue;
            }
            return [
                'value' => substr(
                    $openingTag,
                    $attribute['valueStart'],
                    $attribute['valueEnd'] - $attribute['valueStart'],
                ),
                'valueStart' => $attribute['valueStart'],
                'valueEnd' => $attribute['valueEnd'],
                'attributeStart' => $attribute['start'],
                'attributeEnd' => $attribute['end'],
            ];
        }
        return null;
    }

    private function hrefInsertionOffset(string $openingTag): int
    {
        $offset = max(0, strlen($openingTag) - 1);
        for ($cursor = $offset - 1; $cursor >= 0; $cursor--) {
            if (str_contains(" \t\n\f\r", $openingTag[$cursor])) {
                continue;
            }
            return $openingTag[$cursor] === '/' ? $cursor : $offset;
        }
        return $offset;
    }

    private function safeBlockLabel(string $label): string
    {
        $notes = [];
        return MarkupSanitizer::sanitize($label, $notes);
    }

    /**
     * @return array{file:string,block:string,authored:string,delivered:string,disposition:string}
     */
    private function contextRow(
        string $file,
        string $block,
        string $authored,
        string $delivered,
        string $disposition,
    ): array {
        return compact('file', 'block', 'authored', 'delivered', 'disposition');
    }

    /**
     * @return list<array{
     *   start:int,end:int,closeStart:?int,closeEnd:?int,nav:int,link:int
     * }>
     */
    private function navigationLinks(string $markup): array
    {
        $tokens = $this->tokens($markup);
        $navStack = [];
        $openLinks = [];
        $links = [];
        $navCount = 0;

        foreach ($tokens as $token) {
            if ($token['name'] === 'nav') {
                if (!$token['closer']) {
                    $navCount++;
                    $navStack[] = ['ordinal' => $navCount, 'links' => 0];
                } elseif ($navStack !== []) {
                    array_pop($navStack);
                }
                continue;
            }

            if ($token['name'] !== 'a') {
                continue;
            }
            if (!$token['closer']) {
                if ($navStack === []) {
                    continue;
                }
                $navIndex = array_key_last($navStack);
                $navStack[$navIndex]['links']++;
                $links[] = [
                    'start' => $token['start'],
                    'end' => $token['end'],
                    'closeStart' => null,
                    'closeEnd' => null,
                    'nav' => $navStack[$navIndex]['ordinal'],
                    'link' => $navStack[$navIndex]['links'],
                ];
                if (!$token['selfClosing']) {
                    $openLinks[] = array_key_last($links);
                }
                continue;
            }

            if ($openLinks === []) {
                continue;
            }
            $linkIndex = array_pop($openLinks);
            $links[$linkIndex]['closeStart'] = $token['start'];
            $links[$linkIndex]['closeEnd'] = $token['end'];
        }

        return $links;
    }

    /**
     * @return list<array{
     *   start:int,end:int,valueStart:?int,valueEnd:?int,insertAt:int,
     *   label:string,url:?string,nav:int,link:int
     * }>
     */
    private function navigationBlockLinks(string $markup): array
    {
        $found = preg_match_all('/<!--.*?-->/s', $markup, $comments, PREG_OFFSET_CAPTURE);
        if ($found === false || $found === 0) {
            return [];
        }

        $navStack = [];
        $navCount = 0;
        $links = [];
        foreach ($comments[0] as [$comment, $start]) {
            if (preg_match(
                '/\A<!--\s*(\/?)wp:(navigation-link|navigation)\b(.*?)-->\z/s',
                $comment,
                $parts,
            ) !== 1) {
                continue;
            }

            $closer = $parts[1] === '/';
            $name = $parts[2];
            $selfClosing = !$closer && preg_match('/\/\s*-->\z/s', $comment) === 1;
            if ($name === 'navigation') {
                if (!$closer && !$selfClosing) {
                    $navCount++;
                    $navStack[] = ['ordinal' => $navCount, 'links' => 0];
                } elseif ($closer && $navStack !== []) {
                    array_pop($navStack);
                }
                continue;
            }
            if ($closer || !$selfClosing || $navStack === []) {
                continue;
            }

            $jsonStart = strpos($comment, '{');
            $jsonEnd = strrpos($comment, '}');
            if ($jsonStart === false || $jsonEnd === false || $jsonEnd < $jsonStart) {
                continue;
            }
            $json = substr($comment, $jsonStart, $jsonEnd - $jsonStart + 1);
            $attributes = json_decode($json, true);
            if (!is_array($attributes)
                || !isset($attributes['label'])
                || !is_string($attributes['label'])
            ) {
                continue;
            }
            $properties = $this->topLevelJsonStringProperties($json);
            $hasUrl = array_key_exists('url', $attributes);
            if ($hasUrl
                && (!is_string($attributes['url']) || !isset($properties['url']))
            ) {
                continue;
            }
            $url = $hasUrl ? $attributes['url'] : null;

            $navIndex = array_key_last($navStack);
            $navStack[$navIndex]['links']++;
            $links[] = [
                'start' => $start,
                'end' => $start + strlen($comment),
                'valueStart' => $url === null
                    ? null
                    : $start + $jsonStart + $properties['url']['start'],
                'valueEnd' => $url === null
                    ? null
                    : $start + $jsonStart + $properties['url']['end'],
                'insertAt' => $start + $jsonEnd,
                'label' => $attributes['label'],
                'url' => $url,
                'nav' => $navStack[$navIndex]['ordinal'],
                'link' => $navStack[$navIndex]['links'],
            ];
        }
        return $links;
    }

    /**
     * @return array<string,array{start:int,end:int}>
     */
    private function topLevelJsonStringProperties(string $json): array
    {
        $properties = [];
        $length = strlen($json);
        $depth = 0;
        $offset = 0;
        while ($offset < $length) {
            $byte = $json[$offset];
            if ($byte === '{' || $byte === '[') {
                $depth++;
                $offset++;
                continue;
            }
            if ($byte === '}' || $byte === ']') {
                $depth--;
                $offset++;
                continue;
            }
            if ($byte !== '"') {
                $offset++;
                continue;
            }

            $keyEnd = $this->jsonStringEnd($json, $offset);
            if ($keyEnd === null) {
                break;
            }
            if ($depth !== 1) {
                $offset = $keyEnd + 1;
                continue;
            }
            $afterKey = $keyEnd + 1;
            while ($afterKey < $length && str_contains(" \t\n\r", $json[$afterKey])) {
                $afterKey++;
            }
            if (($json[$afterKey] ?? '') !== ':') {
                $offset = $keyEnd + 1;
                continue;
            }
            $valueStart = $afterKey + 1;
            while ($valueStart < $length && str_contains(" \t\n\r", $json[$valueStart])) {
                $valueStart++;
            }
            if (($json[$valueStart] ?? '') !== '"') {
                $offset = $valueStart;
                continue;
            }
            $valueEnd = $this->jsonStringEnd($json, $valueStart);
            if ($valueEnd === null) {
                break;
            }

            $encodedKey = substr($json, $offset, $keyEnd - $offset + 1);
            $key = json_decode($encodedKey, true);
            if (is_string($key)) {
                $properties[$key] = [
                    'start' => $valueStart + 1,
                    'end' => $valueEnd,
                ];
            }
            $offset = $valueEnd + 1;
        }
        return $properties;
    }

    private function jsonStringEnd(string $json, int $start): ?int
    {
        $length = strlen($json);
        for ($offset = $start + 1; $offset < $length; $offset++) {
            if ($json[$offset] === '\\') {
                $offset++;
                continue;
            }
            if ($json[$offset] === '"') {
                return $offset;
            }
        }
        return null;
    }

    /**
     * @return list<array{name:string,closer:bool,selfClosing:bool,start:int,end:int}>
     */
    private function tokens(string $markup): array
    {
        $tokens = [];
        $length = strlen($markup);
        $offset = 0;

        while ($offset < $length) {
            $start = strpos($markup, '<', $offset);
            if ($start === false) {
                break;
            }
            if (substr($markup, $start, 4) === '<!--') {
                $commentEnd = strpos($markup, '-->', $start + 4);
                $offset = $commentEnd === false ? $length : $commentEnd + 3;
                continue;
            }
            if (substr($markup, $start, 2) === '<!'
                || substr($markup, $start, 2) === '<?'
            ) {
                $specialEnd = strpos($markup, '>', $start + 2);
                $offset = $specialEnd === false ? $length : $specialEnd + 1;
                continue;
            }
            if (preg_match(
                '/\G<(\/?)(([a-zA-Z][a-zA-Z0-9:-]*))(?=[\x09\x0A\x0C\x0D\x20\/>])/',
                $markup,
                $match,
                0,
                $start,
            ) !== 1) {
                $offset = $start + 1;
                continue;
            }

            $end = $this->tagEnd($markup, $start + strlen($match[0]));
            $raw = substr($markup, $start, $end - $start);
            $closer = $match[1] === '/';
            $name = strtolower($match[2]);
            $tokens[] = [
                'name' => $name,
                'closer' => $closer,
                'selfClosing' => !$closer && preg_match('/\/\s*>\z/D', $raw) === 1,
                'start' => $start,
                'end' => $end,
            ];
            $offset = $end;

            if (!$closer && in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
                $closing = $this->rawTextCloseStart($markup, $name, $offset);
                if ($closing === false) {
                    break;
                }
                $offset = $closing;
            }
        }

        return $tokens;
    }

    private function rawTextCloseStart(string $markup, string $name, int $offset): int|false
    {
        $needle = '</' . $name;
        while (($closing = stripos($markup, $needle, $offset)) !== false) {
            $next = $markup[$closing + strlen($needle)] ?? '';
            if ($next === '' || str_contains("\t\n\f\r />", $next)) {
                return $closing;
            }
            $offset = $closing + strlen($needle);
        }
        return false;
    }

    private function tagEnd(string $markup, int $offset): int
    {
        $length = strlen($markup);
        $quote = null;
        while ($offset < $length) {
            $byte = $markup[$offset];
            if ($quote !== null) {
                if ($byte === $quote) {
                    $quote = null;
                }
            } elseif ($byte === '"' || $byte === "'") {
                $quote = $byte;
            } elseif ($byte === '>') {
                return $offset + 1;
            }
            $offset++;
        }
        return $length;
    }
}
