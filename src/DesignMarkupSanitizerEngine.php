<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Internal implementation for the shared untrusted design HTML boundary.
 */
final class DesignMarkupSanitizerEngine
{
    private const UNSAFE_ELEMENTS = [
        'script',
        'input',
        'select',
        'option',
        'optgroup',
        'textarea',
        'fieldset',
        'legend',
        'datalist',
        'output',
        'svg',
        'math',
        'iframe',
        'object',
        'applet',
        'embed',
        'frame',
        'portal',
        'noembed',
        'noframes',
        'noscript',
        'base',
        'link',
    ];
    private const VOID_ELEMENTS = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];
    private const RAW_TEXT_ELEMENTS = [
        'script',
        'style',
        'title',
        'textarea',
    ];
    private const SAFE_HEAD_ELEMENTS = [
        'title',
        'style',
    ];
    private const SAFE_BODY_ELEMENTS = [
        'header',
        'nav',
        'main',
        'section',
        'article',
        'aside',
        'footer',
        'address',
        'div',
        'span',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'p',
        'ul',
        'ol',
        'li',
        'dl',
        'dt',
        'dd',
        'blockquote',
        'q',
        'pre',
        'code',
        'table',
        'caption',
        'colgroup',
        'col',
        'thead',
        'tbody',
        'tfoot',
        'tr',
        'th',
        'td',
        'figure',
        'figcaption',
        'picture',
        'source',
        'img',
        'a',
        'button',
        'br',
        'hr',
        'strong',
        'em',
        'b',
        'i',
        'u',
        's',
        'small',
        'sub',
        'sup',
        'time',
        'mark',
    ];
    private const URL_ATTRIBUTES = [
        'href',
        'src',
        'srcset',
        'xlink:href',
        'action',
        'formaction',
        'poster',
        'cite',
    ];

    /**
     * @return array{start:int,end:int,name:string,closing:bool,self_closing:bool}|null
     */
    private static function nextTag(string $html, int $offset): ?array
    {
        while (($candidate = self::nextTagCandidate($html, $offset)) !== null) {
            $offset = $candidate['end'];
            if ($candidate['malformed']) {
                continue;
            }
            return [
                'start'        => $candidate['start'],
                'end'          => $candidate['end'],
                'name'         => $candidate['name'],
                'closing'      => $candidate['closing'],
                'self_closing' => $candidate['self_closing'],
            ];
        }
        return null;
    }

    /**
     * @return array{
     *   start:int,
     *   end:int,
     *   malformed:bool,
     *   name?:string,
     *   closing?:bool,
     *   self_closing?:bool
     * }|null
     */
    private static function nextTagCandidate(string $html, int $offset): ?array
    {
        $length = strlen($html);
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                return null;
            }
            if (substr($html, $start, 4) === '<!--') {
                $offset = self::commentSpan($html, $start)['end'];
                continue;
            }

            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                $declarationEnd = self::ignoredPreHeadMarkupEnd($html, $start, $length);
                if ($declarationEnd !== null) {
                    $offset = $declarationEnd;
                    continue;
                }
                return [
                    'start'     => $start,
                    'end'       => $length,
                    'malformed' => true,
                ];
            }

            $cursor = $start + 1;
            while ($cursor < $length && str_contains(" \t\n\f\r", $html[$cursor])) {
                $cursor++;
            }
            $closing = ($html[$cursor] ?? '') === '/';
            if ($closing) {
                $cursor++;
                while ($cursor < $length && str_contains(" \t\n\f\r", $html[$cursor])) {
                    $cursor++;
                }
            }
            if (
                $cursor >= $length
                || preg_match('/^[A-Za-z]$/D', $html[$cursor]) !== 1
            ) {
                $offset = $start + 1;
                continue;
            }

            $nameStart = $cursor;
            while (
                $cursor < $length
                && !self::isTagNameDelimiter($html[$cursor])
            ) {
                $cursor++;
            }
            $rawName = substr($html, $nameStart, $cursor - $nameStart);
            $validName = preg_match('/^[A-Za-z][A-Za-z0-9:-]*$/D', $rawName) === 1;

            $quote = null;
            $end = $cursor;
            for (; $end < $length; $end++) {
                $char = $html[$end];
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    continue;
                }
                if ($char === '>') {
                    break;
                }
            }
            if ($end >= $length) {
                return [
                    'start'     => $start,
                    'end'       => $length,
                    'malformed' => true,
                ];
            }

            if (!$validName) {
                return [
                    'start'     => $start,
                    'end'       => $end + 1,
                    'malformed' => true,
                ];
            }
            $raw = substr($html, $start, $end - $start + 1);
            $name = strtolower($rawName);
            return [
                'start'        => $start,
                'end'          => $end + 1,
                'malformed'    => false,
                'name'         => $name,
                'closing'      => $closing,
                'self_closing' => !in_array($name, self::RAW_TEXT_ELEMENTS, true)
                    && preg_match('/\/\s*>$/s', $raw) === 1,
            ];
        }
        return null;
    }

    private static function isTagNameDelimiter(string $char): bool
    {
        return $char === '/'
            || $char === '>'
            || str_contains(" \t\n\f\r", $char);
    }

    /**
     * @return array{start:int,end:int,name:string,closing:bool,self_closing:bool}|null
     */
    private static function rawTextCloseToken(
        string $html,
        string $name,
        int $offset,
    ): ?array {
        $needle = "</{$name}";
        while (($start = stripos($html, $needle, $offset)) !== false) {
            $afterName = $start + strlen($needle);
            $delimiter = $html[$afterName] ?? '';
            if (
                $delimiter === '>'
                || $delimiter === '/'
                || ($delimiter !== '' && str_contains(" \t\n\f\r", $delimiter))
            ) {
                $close = self::nextTag($html, $start);
                if (
                    $close !== null
                    && $close['closing']
                    && $close['name'] === $name
                ) {
                    return $close;
                }
            }
            $offset = $afterName;
        }
        return null;
    }

    /**
     * @return array{start:int,end:int,malformed:bool}
     */
    private static function commentSpan(string $html, int $start): array
    {
        $length = strlen($html);
        if (substr($html, $start, 5) === '<!-->') {
            return ['start' => $start, 'end' => $start + 5, 'malformed' => true];
        }
        if (substr($html, $start, 6) === '<!--->') {
            return ['start' => $start, 'end' => $start + 6, 'malformed' => true];
        }

        $standardEnd = strpos($html, '-->', $start + 4);
        $bangEnd = strpos($html, '--!>', $start + 4);
        if ($standardEnd === false && $bangEnd === false) {
            return ['start' => $start, 'end' => $length, 'malformed' => true];
        }
        if ($bangEnd !== false && ($standardEnd === false || $bangEnd < $standardEnd)) {
            return ['start' => $start, 'end' => $bangEnd + 4, 'malformed' => true];
        }
        return ['start' => $start, 'end' => $standardEnd + 3, 'malformed' => false];
    }

    /**
     * @return list<array{start:int,length:int,authored:string}>
     */
    private static function malformedCommentRemovals(string $html): array
    {
        $removals = [];
        $offset = 0;
        while ($offset < strlen($html)) {
            $commentStart = strpos($html, '<!--', $offset);
            $token = self::nextTag($html, $offset);
            if (
                $commentStart !== false
                && ($token === null || $commentStart < $token['start'])
            ) {
                $comment = self::commentSpan($html, $commentStart);
                if ($comment['malformed']) {
                    $removals[] = [
                        'start' => $commentStart,
                        'length' => $comment['end'] - $commentStart,
                        'authored' => substr(
                            $html,
                            $commentStart,
                            $comment['end'] - $commentStart,
                        ),
                    ];
                }
                $offset = $comment['end'];
                continue;
            }
            if ($token === null) {
                break;
            }

            $offset = $token['end'];
            if (
                !$token['closing']
                && !$token['self_closing']
                && in_array($token['name'], self::RAW_TEXT_ELEMENTS, true)
            ) {
                $close = self::matchingCloseToken($html, $token);
                if ($close === null) {
                    break;
                }
                $offset = $close['end'];
            }
        }
        return $removals;
    }

    /**
     * @return list<array{start:int,length:int,authored:string}>
     */
    private static function malformedTagRemovals(string $html): array
    {
        $removals = [];
        $offset = 0;
        while (($candidate = self::nextTagCandidate($html, $offset)) !== null) {
            $offset = $candidate['end'];
            if ($candidate['malformed']) {
                $removals[] = [
                    'start'    => $candidate['start'],
                    'length'   => $candidate['end'] - $candidate['start'],
                    'authored' => substr(
                        $html,
                        $candidate['start'],
                        $candidate['end'] - $candidate['start'],
                    ),
                ];
                continue;
            }
            if (
                !$candidate['closing']
                && !$candidate['self_closing']
                && in_array($candidate['name'], self::RAW_TEXT_ELEMENTS, true)
            ) {
                $close = self::matchingCloseToken($html, $candidate);
                if ($close === null) {
                    break;
                }
                $offset = $close['end'];
            }
        }
        return $removals;
    }

    /**
     * @param list<string> $warnings
     */
    public static function sanitize(
        string $html,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        $removals = [
            ...self::malformedTagRemovals($html),
            ...self::malformedCommentRemovals($html),
        ];
        $head = self::headContentRange($html);
        $headStyleStarts = self::domHeadStyleStarts($html);
        $offset = 0;
        while (($token = self::nextTag($html, $offset)) !== null) {
            $offset = $token['end'];
            if ($token['closing']) {
                continue;
            }

            $name = $token['name'];
            $inHead = $name === 'style'
                ? isset($headStyleStarts[$token['start']])
                : (
                    $head !== null
                    && $token['start'] >= $head[0]
                    && $token['start'] < $head[1]
                );
            if ($name === 'meta') {
                if (!$inHead || !self::isSafeHeadMeta($html, $token)) {
                    $removals[] = self::tokenRemoval($html, $token);
                    continue;
                }
            } elseif (
                in_array($name, self::UNSAFE_ELEMENTS, true)
                || ($name === 'style' && !$inHead)
            ) {
                $end = self::unsafeElementEnd($html, $token);
                $removals[] = [
                    'start'    => $token['start'],
                    'length'   => $end - $token['start'],
                    'authored' => substr($html, $token['start'], $end - $token['start']),
                ];
                $offset = $end;
                continue;
            } elseif (!self::isAllowedElement($name, $inHead)) {
                $removals[] = self::tokenRemoval($html, $token);
                $close = self::matchingCloseToken($html, $token);
                if ($close !== null) {
                    $removals[] = self::tokenRemoval($html, $close);
                }
                continue;
            }

            foreach (self::unsafeAttributeRanges($html, $token) as $removal) {
                $removals[] = $removal;
            }
            if (in_array($name, self::RAW_TEXT_ELEMENTS, true) && !$token['self_closing']) {
                $close = self::matchingCloseToken($html, $token);
                if ($close !== null) {
                    $offset = $close['end'];
                }
            }
        }

        if ($removals === []) {
            return $html;
        }
        $removalSpans = self::nonOverlappingRemovalSpans($removals);
        usort(
            $removals,
            static fn (array $left, array $right): int => $right['start'] <=> $left['start'],
        );
        foreach (array_reverse($removalSpans) as $removalSpan) {
            $html = substr($html, 0, $removalSpan['start'])
                . substr($html, $removalSpan['start'] + $removalSpan['length']);
        }
        foreach ($removals as $removal) {
            $authored = self::warningValue($removal['authored']);
            $disposition = $removal['disposition'] ?? 'removed';
            $warnings[] = "malformed_design: {$path} context {$context}; authored {$authored}; "
                . "delivered removed; disposition {$disposition}";
        }
        return $html;
    }

    /**
     * @param list<array{start:int,length:int,authored:string}> $removals
     * @return list<array{start:int,length:int}>
     */
    private static function nonOverlappingRemovalSpans(array $removals): array
    {
        usort(
            $removals,
            static function (array $left, array $right): int {
                $byStart = $left['start'] <=> $right['start'];
                return $byStart !== 0
                    ? $byStart
                    : $right['length'] <=> $left['length'];
            },
        );

        $spans = [];
        $coveredUntil = null;
        foreach ($removals as $removal) {
            if ($removal['length'] <= 0) {
                continue;
            }
            $end = $removal['start'] + $removal['length'];
            if ($coveredUntil !== null && $end <= $coveredUntil) {
                continue;
            }
            $start = $coveredUntil === null
                ? $removal['start']
                : max($removal['start'], $coveredUntil);
            $spans[] = [
                'start'  => $start,
                'length' => $end - $start,
            ];
            $coveredUntil = $end;
        }
        return $spans;
    }

    /**
     * @return array{int,int}|null
     */
    private static function headContentRange(string $html): ?array
    {
        $offset = 0;
        $sawHeadContent = false;
        $explicitHead = false;
        while (true) {
            $token = self::nextTag($html, $offset);
            $textStart = self::bodyTextOffset(
                $html,
                $offset,
                $token['start'] ?? strlen($html),
            );
            if ($textStart !== null) {
                return [0, $textStart];
            }
            if ($token === null) {
                break;
            }

            $offset = $token['end'];
            if ($token['closing']) {
                if ($token['name'] === 'head' && $explicitHead) {
                    return [0, $token['start']];
                }
                continue;
            }
            if ($token['name'] === 'html') {
                continue;
            }
            if ($token['name'] === 'head') {
                $explicitHead = true;
                continue;
            }
            if ($token['name'] === 'body') {
                return [0, $token['start']];
            }
            if (!in_array(
                $token['name'],
                ['base', 'link', 'meta', 'noscript', 'script', 'style', 'template', 'title'],
                true,
            )) {
                return [0, $token['start']];
            }
            $sawHeadContent = true;
            if (
                !$token['self_closing']
                && in_array($token['name'], self::RAW_TEXT_ELEMENTS, true)
            ) {
                $close = self::matchingCloseToken($html, $token);
                if ($close === null) {
                    return [0, strlen($html)];
                }
                $offset = $close['end'];
            }
        }
        return ($sawHeadContent || $explicitHead) ? [0, strlen($html)] : null;
    }

    private static function bodyTextOffset(string $html, int $start, int $end): ?int
    {
        while ($start < $end) {
            $char = $html[$start];
            if (str_contains(" \t\n\f\r", $char)) {
                $start++;
                continue;
            }
            $ignoredEnd = self::ignoredPreHeadMarkupEnd($html, $start, $end);
            if ($ignoredEnd !== null) {
                $start = $ignoredEnd;
                continue;
            }
            return $start;
        }
        return null;
    }

    private static function ignoredPreHeadMarkupEnd(
        string $html,
        int $start,
        int $end,
    ): ?int {
        if (substr($html, $start, 4) === '<!--') {
            return min(self::commentSpan($html, $start)['end'], $end);
        }
        $prefix = substr($html, $start, 2);
        if ($prefix !== '<!' && $prefix !== '<?') {
            return null;
        }

        for ($offset = $start + 2; $offset < $end; $offset++) {
            if ($html[$offset] === '>') {
                return $offset + 1;
            }
        }
        return null;
    }

    /**
     * @return array<int,true>
     */
    private static function domHeadStyleStarts(string $html): array
    {
        $sourceStyles = [];
        $offset = 0;
        while (($token = self::nextTag($html, $offset)) !== null) {
            $offset = $token['end'];
            if ($token['closing']) {
                continue;
            }
            if ($token['name'] === 'style') {
                $sourceStyles[] = $token;
            }
            if (
                !$token['self_closing']
                && in_array($token['name'], self::RAW_TEXT_ELEMENTS, true)
            ) {
                $close = self::matchingCloseToken($html, $token);
                if ($close === null) {
                    break;
                }
                $offset = $close['end'];
            }
        }
        if ($sourceStyles === []) {
            return [];
        }

        $markerName = self::unusedStyleMarkerName($html);
        $markedHtml = $html;
        for ($ordinal = count($sourceStyles) - 1; $ordinal >= 0; $ordinal--) {
            $sourceStyle = $sourceStyles[$ordinal];
            $raw = substr(
                $html,
                $sourceStyle['start'],
                $sourceStyle['end'] - $sourceStyle['start'],
            );
            if (
                preg_match(
                    '/^<\s*style(?=[ \t\n\f\r\/>])/i',
                    $raw,
                    $match,
                ) !== 1
            ) {
                continue;
            }
            $insertAt = $sourceStyle['start'] + strlen($match[0]);
            $marker = " {$markerName}=\"{$ordinal}\"";
            $markedHtml = substr($markedHtml, 0, $insertAt)
                . $marker
                . substr($markedHtml, $insertAt);
        }

        $dom = self::loadDocument($markedHtml);
        if ($dom === null) {
            return [];
        }

        $markedNodes = array_fill(0, count($sourceStyles), []);
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (
                !$element instanceof \DOMElement
                || !$element->hasAttribute($markerName)
            ) {
                continue;
            }
            $value = $element->getAttribute($markerName);
            if (
                preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
                || (string) (int) $value !== $value
                || !array_key_exists((int) $value, $markedNodes)
            ) {
                return [];
            }
            $markedNodes[(int) $value][] = $element;
        }

        $headStarts = [];
        foreach ($sourceStyles as $ordinal => $sourceStyle) {
            if (count($markedNodes[$ordinal]) !== 1) {
                continue;
            }
            $domStyle = $markedNodes[$ordinal][0];
            if (strtolower($domStyle->tagName) !== 'style') {
                continue;
            }
            for (
                $ancestor = $domStyle->parentNode;
                $ancestor !== null;
                $ancestor = $ancestor->parentNode
            ) {
                if (
                    $ancestor instanceof \DOMElement
                    && strtolower($ancestor->tagName) === 'head'
                ) {
                    $headStarts[$sourceStyle['start']] = true;
                    break;
                }
            }
        }
        return $headStarts;
    }

    private static function unusedStyleMarkerName(string $html): string
    {
        $suffix = 0;
        do {
            $name = 'data-msb-style-source' . ($suffix === 0 ? '' : "-{$suffix}");
            $suffix++;
        } while (stripos($html, $name) !== false);
        return $name;
    }

    private static function isAllowedElement(string $name, bool $inHead): bool
    {
        if (in_array($name, ['html', 'head', 'body'], true)) {
            return true;
        }
        return $inHead
            ? in_array($name, self::SAFE_HEAD_ELEMENTS, true) || $name === 'meta'
            : in_array($name, self::SAFE_BODY_ELEMENTS, true);
    }

    /**
     * @param array{start:int,end:int,name:string,closing:bool,self_closing:bool} $token
     */
    private static function isSafeHeadMeta(string $html, array $token): bool
    {
        $attributes = self::attributeValues(
            substr($html, $token['start'], $token['end'] - $token['start'])
        );
        if (array_key_exists('http-equiv', $attributes)) {
            return false;
        }
        if (array_key_exists('charset', $attributes)) {
            return trim($attributes['charset']) !== '';
        }
        return strtolower(trim($attributes['name'] ?? '')) === 'viewport';
    }

    /**
     * @param array{start:int,end:int,name:string,closing:bool,self_closing:bool} $token
     * @return array{start:int,length:int,authored:string}
     */
    private static function tokenRemoval(string $html, array $token): array
    {
        return [
            'start'    => $token['start'],
            'length'   => $token['end'] - $token['start'],
            'authored' => substr($html, $token['start'], $token['end'] - $token['start']),
        ];
    }

    /**
     * @param array{start:int,end:int,name:string,closing:bool,self_closing:bool} $opening
     */
    private static function unsafeElementEnd(string $html, array $opening): int
    {
        $name = $opening['name'];
        if ($opening['self_closing'] || in_array($name, self::VOID_ELEMENTS, true)) {
            return $opening['end'];
        }
        return self::matchingCloseToken($html, $opening)['end'] ?? strlen($html);
    }

    /**
     * @param array{start:int,end:int,name:string,closing:bool,self_closing:bool} $opening
     * @return array{start:int,end:int,name:string,closing:bool,self_closing:bool}|null
     */
    private static function matchingCloseToken(string $html, array $opening): ?array
    {
        $name = $opening['name'];
        if ($opening['self_closing'] || in_array($name, self::VOID_ELEMENTS, true)) {
            return null;
        }
        if (in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
            return self::rawTextCloseToken($html, $name, $opening['end']);
        }
        $depth = 1;
        $offset = $opening['end'];
        while (($token = self::nextTag($html, $offset)) !== null) {
            $offset = $token['end'];
            if (
                !$token['closing']
                && !$token['self_closing']
                && in_array($token['name'], self::RAW_TEXT_ELEMENTS, true)
                && $token['name'] !== $name
            ) {
                $rawClose = self::matchingCloseToken($html, $token);
                if ($rawClose === null) {
                    return null;
                }
                $offset = $rawClose['end'];
                continue;
            }
            if ($token['name'] !== $name || $token['self_closing']) {
                continue;
            }
            $depth += $token['closing'] ? -1 : 1;
            if ($depth === 0) {
                return $token;
            }
        }
        return null;
    }

    /**
     * @param array{start:int,end:int,name:string,closing:bool,self_closing:bool} $token
     * @return list<array{start:int,length:int,authored:string}>
     */
    private static function unsafeAttributeRanges(string $html, array $token): array
    {
        $raw = substr($html, $token['start'], $token['end'] - $token['start']);
        $removals = [];
        foreach (self::rawAttributes($raw) as $attribute) {
            $name = $attribute['name'];
            $unsafe = str_starts_with($name, 'on')
                || in_array($name, ['srcdoc', 'ping'], true);
            $disposition = 'removed';
            if (!$unsafe && in_array($name, self::URL_ATTRIBUTES, true)) {
                $unsafe = !self::isSafeUrlAttribute($name, $attribute['value']);
            }
            if (!$unsafe && $name === 'style') {
                // An inline style is a fetch sink: `background:url(https://…)`
                // calls a model-chosen host on every view of the design, the
                // screenshot pass included. This sanitizer only deletes
                // spans, so the whole attribute goes (BIGR-970).
                $unsafe = CssChecks::scrubInlineStyle(
                    html_entity_decode(
                        self::decodedAttributeValue(self::unquoted($attribute['value'])),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8',
                    ),
                ) !== null;
                $disposition = 'removed inline style that loads a resource';
            }
            if (!$unsafe) {
                continue;
            }

            $removals[] = [
                'start'       => $token['start'] + $attribute['offset'],
                'length'      => strlen($attribute['raw']),
                'authored'    => $attribute['raw'],
                'disposition' => $disposition,
            ];
        }
        return $removals;
    }

    /**
     * @return list<array{name:string,value:string,raw:string,offset:int}>
     */
    private static function rawAttributes(string $rawTag): array
    {
        if (
            preg_match('/^<\s*[A-Za-z][A-Za-z0-9:-]*/', $rawTag, $prefix) !== 1
            || preg_match_all(
                '/(?:\s*\/+\s*|\s+|(?<=["\']))'
                    . '([^\s=\/>"\']+)'
                    . '(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/s',
                $rawTag,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
                strlen($prefix[0]),
            ) === false
        ) {
            return [];
        }

        $attributes = [];
        foreach ($matches as $match) {
            $attributes[] = [
                'name'   => strtolower($match[1][0]),
                'value'  => $match[2][0] ?? '',
                'raw'    => $match[0][0],
                'offset' => $match[0][1],
            ];
        }
        return $attributes;
    }

    /** The attribute value without the quotes rawAttributes() keeps on it. */
    private static function unquoted(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0]) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    /**
     * @return array<string,string>
     */
    private static function attributeValues(string $rawTag): array
    {
        $values = [];
        foreach (self::rawAttributes($rawTag) as $attribute) {
            $values[$attribute['name']] = self::decodedAttributeValue($attribute['value']);
        }
        return $values;
    }

    private static function decodedAttributeValue(string $value): string
    {
        $numericDecoded = (string) preg_replace_callback(
            '/&#(?:[xX]([0-9A-Fa-f]+)|([0-9]+));?/',
            static function (array $match): string {
                $hex = ($match[1] ?? '') !== '';
                $digits = $hex ? $match[1] : $match[2];
                $significant = ltrim($digits, '0');
                if ($significant === '') {
                    return $match[0];
                }

                $maxDigits = $hex ? 2 : 3;
                if (strlen($significant) > $maxDigits) {
                    return $match[0];
                }
                $codepoint = intval($significant, $hex ? 16 : 10);
                return $codepoint >= 1 && $codepoint <= 0x7f
                    ? chr($codepoint)
                    : $match[0];
            },
            trim($value, " \t\n\r\0\x0B\"'"),
        );
        return html_entity_decode(
            $numericDecoded,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
    }

    private static function isSafeUrlAttribute(string $name, string $authored): bool
    {
        $value = self::decodedAttributeValue($authored);
        if ($name === 'srcset') {
            foreach (explode(',', $value) as $candidate) {
                $url = preg_split('/\s+/', trim($candidate), 2)[0] ?? '';
                if (!self::isSafeUrl('src', $url)) {
                    return false;
                }
            }
            return true;
        }
        return self::isSafeUrl($name, $value);
    }

    private static function isSafeUrl(string $attribute, string $value): bool
    {
        $normalized = preg_replace('/[\x00-\x20\x7f]+/u', '', trim($value));
        if ($normalized === null) {
            return false;
        }
        if ($normalized === '') {
            return true;
        }
        if (str_starts_with($normalized, '#')) {
            return in_array($attribute, ['href', 'cite'], true);
        }
        if (str_starts_with($normalized, '//')) {
            return true;
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $normalized, $match) !== 1) {
            return true;
        }

        $scheme = strtolower($match[1]);
        if (in_array($scheme, ['http', 'https'], true)) {
            return true;
        }
        return $attribute === 'href' && in_array($scheme, ['mailto', 'tel'], true);
    }

    private static function warningValue(string $authored): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $authored);
        $value = trim($normalized ?? $authored);
        if (mb_strlen($value) > 160) {
            $value = mb_substr($value, 0, 157) . '...';
        }
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?: '"(unprintable)"';
    }

    private static function loadDocument(string $html): ?\DOMDocument
    {
        // UTF-8 hint so libxml doesn't guess ISO-8859-1 and double-encode.
        return Html::loadUtf8Html($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    }
}
