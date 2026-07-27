<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * HTML lexical context used while locating Gutenberg block comments.
 *
 * The WordPress block tokenizer is intentionally HTML-agnostic, but recovery
 * must not activate block-looking examples from raw-text/inert elements,
 * ordinary comments, declarations, or tag attributes. delimiterView() masks
 * those ranges byte-for-byte (preserving line endings), so parser offsets
 * still address the original source.
 */
final class HtmlBlockContext
{
    /** Elements whose text model ends at the first matching end tag. */
    private const RAW_TEXT_ELEMENTS = [
        'script', 'style', 'textarea', 'title', 'xmp',
        'iframe', 'noembed', 'noframes', 'noscript',
    ];

    private const OPAQUE_ELEMENTS = [
        'script', 'style', 'textarea', 'title', 'xmp',
        'iframe', 'object', 'applet', 'noembed', 'noframes', 'noscript',
        'template', 'code', 'pre', 'plaintext',
    ];

    public static function delimiterView(string $html): string
    {
        $view = $html;
        $length = strlen($html);
        $offset = 0;

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            if (substr($html, $start, 4) === '<!--') {
                $close = strpos($html, '-->', $start + 4);
                $end = $close === false ? $length : $close + 3;
                $comment = substr($html, $start, $end - $start);
                if (preg_match('/\A<!--\s*\/?wp:/', $comment) !== 1) {
                    self::mask($view, $start, $end);
                }
                $offset = $end;
                continue;
            }

            if (substr($html, $start, 9) === '<![CDATA[') {
                $close = strpos($html, ']]>', $start + 9);
                $end = $close === false ? $length : $close + 3;
                self::mask($view, $start, $end);
                $offset = $end;
                continue;
            }

            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                $end = self::declarationEnd($html, $start);
                self::mask($view, $start, $end);
                $offset = $end;
                continue;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }

            $end = $tag['end'];
            if (!$tag['closer']
                && in_array($tag['name'], self::OPAQUE_ELEMENTS, true)
            ) {
                $end = self::opaqueElementEnd($html, $tag['name'], $end);
            }
            self::mask($view, $start, $end);
            $offset = $end;
        }

        return $view;
    }

    /**
     * @return list<int> Gutenberg-looking marker offsets hidden by HTML context
     */
    public static function hiddenDelimiterOffsets(string $html, ?string $view = null): array
    {
        $view ??= self::delimiterView($html);
        $hidden = [];
        if (preg_match_all('/<!--\s*\/?wp:/', $html, $markers, PREG_OFFSET_CAPTURE)) {
            foreach ($markers[0] as $marker) {
                $offset = $marker[1];
                if (substr($view, $offset, strlen($marker[0])) !== $marker[0]) {
                    $hidden[] = $offset;
                }
            }
        }
        return $hidden;
    }

    /**
     * Remove complete (or EOF-truncated) elements, including their content.
     *
     * This uses the same quote/comment-aware boundaries as delimiterView();
     * it exists so sanitization cannot expose a block-looking string by
     * stopping at a fake closer in an attribute, comment, or nested element.
     *
     * @param list<string> $names lowercase element names
     */
    public static function removeElements(string $html, array $names): string
    {
        $targets = array_fill_keys($names, true);
        $length = strlen($html);
        $offset = 0;
        $keptFrom = 0;
        $out = '';

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            $specialEnd = self::specialMarkupEnd($html, $start);
            if ($specialEnd !== null) {
                $offset = $specialEnd;
                continue;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }

            if (!$tag['closer'] && array_key_exists($tag['name'], $targets)) {
                // In HTML, a slash does not self-close these non-void
                // elements (`<script/>` is still an opening script tag).
                $end = self::opaqueElementEnd($html, $tag['name'], $tag['end']);
                $out .= substr($html, $keptFrom, $start - $keptFrom);
                $keptFrom = $end;
                $offset = $end;
                continue;
            }

            // Text inside a non-target raw-text element cannot contain real
            // child elements. Skipping it prevents tag-shaped strings there
            // from being mistaken for sanitizer targets.
            if (!$tag['closer']
                && (in_array($tag['name'], self::RAW_TEXT_ELEMENTS, true)
                    || $tag['name'] === 'plaintext')
            ) {
                $offset = self::opaqueElementEnd($html, $tag['name'], $tag['end']);
                continue;
            }

            $offset = $tag['end'];
        }

        return $out . substr($html, $keptFrom);
    }

    /**
     * Strip only tags with the listed names, preserving their surrounding
     * text. Comments and quoted `>` characters are skipped correctly.
     *
     * @param list<string> $names lowercase element names
     */
    public static function removeTags(string $html, array $names): string
    {
        $targets = array_fill_keys($names, true);
        $length = strlen($html);
        $offset = 0;
        $keptFrom = 0;
        $out = '';

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            $specialEnd = self::specialMarkupEnd($html, $start);
            if ($specialEnd !== null) {
                $offset = $specialEnd;
                continue;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }
            if (array_key_exists($tag['name'], $targets)) {
                $out .= substr($html, $keptFrom, $start - $keptFrom);
                $keptFrom = $tag['end'];
            }
            $offset = $tag['end'];
        }

        return $out . substr($html, $keptFrom);
    }

    /**
     * Rewrite each real opening tag using stateful HTML boundaries.
     *
     * @param callable(string):string $rewrite
     */
    public static function rewriteOpeningTags(string $html, callable $rewrite): string
    {
        $length = strlen($html);
        $offset = 0;
        $keptFrom = 0;
        $out = '';

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            $specialEnd = self::specialMarkupEnd($html, $start);
            if ($specialEnd !== null) {
                $offset = $specialEnd;
                continue;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }
            if (!$tag['closer']) {
                $out .= substr($html, $keptFrom, $start - $keptFrom);
                $out .= $rewrite(substr($html, $start, $tag['end'] - $start));
                $keptFrom = $tag['end'];
            }

            // Tag-shaped text in raw-text bodies is not a child tag.
            if (!$tag['closer']
                && (in_array($tag['name'], self::RAW_TEXT_ELEMENTS, true)
                    || $tag['name'] === 'plaintext')
            ) {
                $offset = self::opaqueElementEnd($html, $tag['name'], $tag['end']);
            } else {
                $offset = $tag['end'];
            }
        }

        return $out . substr($html, $keptFrom);
    }

    /**
     * Tokenize a wrapper-only fragment. Returns null for visible text,
     * declarations, malformed attributes, or an unfinished tag/comment.
     *
     * Comments are recognized only between tags; comment-looking text inside
     * an attribute remains part of that tag. Quote state begins only after an
     * equals sign, matching HTML attribute tokenization closely enough that a
     * stray quote in an unquoted value cannot swallow visible content.
     *
     * @return list<array{name:string,closer:bool}>|null
     */
    public static function wrapperTags(string $html): ?array
    {
        $length = strlen($html);
        $offset = 0;
        $tags = [];

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                return self::isWhitespace(substr($html, $offset)) ? $tags : null;
            }
            if (!self::isWhitespace(substr($html, $offset, $start - $offset))) {
                return null;
            }

            if (substr($html, $start, 4) === '<!--') {
                $close = strpos($html, '-->', $start + 4);
                if ($close === false) {
                    return null;
                }
                $offset = $close + 3;
                continue;
            }
            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                return null;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null || !$tag['valid']) {
                return null;
            }
            $tags[] = ['name' => $tag['name'], 'closer' => $tag['closer']];
            $offset = $tag['end'];

            // Raw-text bodies may contain arbitrary text and tag-looking
            // bytes. Skip them as one lexical region, but retain the real
            // opening/closing pair in the wrapper token stream.
            if (!$tag['closer']
                && in_array($tag['name'], self::RAW_TEXT_ELEMENTS, true)
            ) {
                $close = self::rawTextClosingTagAt(
                    $html,
                    $tag['name'],
                    $tag['end'],
                );
                if ($close === null || !$close['valid']) {
                    return null;
                }
                $tags[] = ['name' => $close['name'], 'closer' => true];
                $offset = $close['end'];
            } elseif (!$tag['closer'] && $tag['name'] === 'plaintext') {
                return null;
            }
        }

        return $tags;
    }

    /** HTML inter-block whitespace, excluding NUL and vertical tab. */
    public static function isWhitespace(string $text): bool
    {
        return preg_match('/\A[\x09\x0A\x0C\x0D\x20]*\z/D', $text) === 1;
    }

    /**
     * @return array{name:string,closer:bool,end:int,valid:bool}|null
     */
    private static function tagAt(string $html, int $start): ?array
    {
        if (preg_match(
            '/\A<[\x09\x0A\x0C\x0D\x20]*(\/?)[\x09\x0A\x0C\x0D\x20]*'
                . '([a-zA-Z][^\x09\x0A\x0C\x0D\x20\/>]*)'
                . '(?=[\x09\x0A\x0C\x0D\x20\/>])/',
            substr($html, $start),
            $tag,
        ) !== 1) {
            return null;
        }

        $boundary = self::tagBoundary(
            $html,
            $start + strlen($tag[0]),
            $tag[1] === '/',
        );
        return [
            'name'   => strtolower($tag[2]),
            'closer' => $tag[1] === '/',
            'end'    => $boundary['end'],
            'valid'  => $boundary['valid']
                && preg_match('/\A[a-zA-Z][a-zA-Z0-9:-]*\z/D', $tag[2]) === 1,
        ];
    }

    /**
     * Locate a tag's browser-visible end with attribute-state-aware quoting.
     *
     * @return array{end:int,valid:bool}
     */
    private static function tagBoundary(string $html, int $offset, bool $closer): array
    {
        $length = strlen($html);
        $state = 'before_attribute';
        $quote = '';
        $valid = true;

        while ($offset < $length) {
            $char = $html[$offset];

            if ($state === 'quoted_value') {
                if ($char === $quote) {
                    $state = 'after_quoted_value';
                }
                $offset++;
                continue;
            }

            if ($state === 'unquoted_value') {
                if (self::isSpaceByte($char)) {
                    $state = 'before_attribute';
                } elseif ($char === '>') {
                    return ['end' => $offset + 1, 'valid' => $valid];
                } elseif (str_contains("\"'<=`", $char)) {
                    // HTML keeps these bytes in the unquoted value (with a
                    // parse error); critically, a quote does not open one.
                    $valid = false;
                }
                $offset++;
                continue;
            }

            if ($state === 'before_value') {
                if (self::isSpaceByte($char)) {
                    $offset++;
                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    $state = 'quoted_value';
                    $offset++;
                    continue;
                }
                if ($char === '>') {
                    return ['end' => $offset + 1, 'valid' => false];
                }
                $state = 'unquoted_value';
                continue;
            }

            if ($state === 'attribute_name') {
                if (self::isSpaceByte($char)) {
                    $state = 'after_attribute_name';
                } elseif ($char === '=') {
                    $state = 'before_value';
                } elseif ($char === '>') {
                    return ['end' => $offset + 1, 'valid' => $valid];
                } elseif ($char === '/') {
                    $state = 'self_closing';
                } elseif (str_contains("\"'<", $char)) {
                    $valid = false;
                }
                $offset++;
                continue;
            }

            if ($state === 'after_attribute_name') {
                if (self::isSpaceByte($char)) {
                    $offset++;
                    continue;
                }
                if ($char === '=') {
                    $state = 'before_value';
                    $offset++;
                    continue;
                }
                if ($char === '>') {
                    return ['end' => $offset + 1, 'valid' => $valid];
                }
                if ($char === '/') {
                    $state = 'self_closing';
                    $offset++;
                    continue;
                }
                $state = 'attribute_name';
                continue;
            }

            if ($state === 'after_quoted_value') {
                if (self::isSpaceByte($char)) {
                    $state = 'before_attribute';
                    $offset++;
                    continue;
                }
                if ($char === '/') {
                    $state = 'self_closing';
                    $offset++;
                    continue;
                }
                if ($char === '>') {
                    return ['end' => $offset + 1, 'valid' => $valid];
                }
                $valid = false;
                $state = 'before_attribute';
                continue;
            }

            if ($state === 'self_closing') {
                if ($char === '>') {
                    return ['end' => $offset + 1, 'valid' => $valid];
                }
                $valid = false;
                $state = 'before_attribute';
                continue;
            }

            // before_attribute
            if (self::isSpaceByte($char)) {
                $offset++;
                continue;
            }
            if ($char === '>') {
                return ['end' => $offset + 1, 'valid' => $valid];
            }
            if ($char === '/') {
                $state = 'self_closing';
                $offset++;
                continue;
            }
            if ($closer) {
                // End-tag attributes are tolerated by browsers but cannot be
                // part of a strict generated wrapper shell.
                $valid = false;
            }
            if (str_contains("\"'<=`", $char)) {
                $valid = false;
            }
            $state = 'attribute_name';
            if ($char === '=') {
                // With no preceding attribute name, HTML keeps `=` as the
                // first byte of a malformed name; it is not a value separator.
                $offset++;
            }
        }

        return ['end' => $length, 'valid' => false];
    }

    private static function isSpaceByte(string $char): bool
    {
        return $char === ' '
            || $char === "\t"
            || $char === "\n"
            || $char === "\f"
            || $char === "\r";
    }

    /** Quote-aware conservative end for declarations/processing instructions. */
    private static function declarationEnd(string $html, int $start): int
    {
        $length = strlen($html);
        $quote = null;
        for ($i = $start + 2; $i < $length; $i++) {
            $char = $html[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i + 1;
            }
        }
        return $length;
    }

    /**
     * End of a comment/declaration beginning at $start, or null for a tag or
     * ordinary less-than character.
     */
    private static function specialMarkupEnd(string $html, int $start): ?int
    {
        $length = strlen($html);
        if (substr($html, $start, 4) === '<!--') {
            $close = strpos($html, '-->', $start + 4);
            return $close === false ? $length : $close + 3;
        }
        if (substr($html, $start, 9) === '<![CDATA[') {
            $close = strpos($html, ']]>', $start + 9);
            return $close === false ? $length : $close + 3;
        }
        if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
            return self::declarationEnd($html, $start);
        }
        return null;
    }

    private static function opaqueElementEnd(string $html, string $name, int $contentStart): int
    {
        if ($name === 'plaintext') {
            return strlen($html);
        }

        if (!in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
            return self::nestedOpaqueElementEnd($html, $name, $contentStart);
        }

        $tag = self::rawTextClosingTagAt($html, $name, $contentStart);
        return $tag === null ? strlen($html) : $tag['end'];
    }

    /**
     * @return array{name:string,closer:bool,end:int,valid:bool}|null
     */
    private static function rawTextClosingTagAt(
        string $html,
        string $name,
        int $contentStart
    ): ?array {
        if ($name === 'script') {
            return self::scriptClosingTagAt($html, $contentStart);
        }
        if (preg_match(
            '#</[\x09\x0A\x0C\x0D\x20]*' . preg_quote($name, '#')
                . '(?=[\x09\x0A\x0C\x0D\x20/>])#i',
            $html,
            $close,
            PREG_OFFSET_CAPTURE,
            $contentStart,
        ) !== 1) {
            return null;
        }
        return self::tagAt($html, $close[0][1]);
    }

    /**
     * Locate the end tag the HTML script-data tokenizer will actually emit.
     *
     * Script is not ordinary raw text: `<!--` enters an escaped state, and a
     * nested `<script>` there enters a double-escaped state where the first
     * `</script>` only returns to escaped text. Treating that first spelling
     * as the boundary would expose inert script bytes as live markup.
     *
     * @return array{name:string,closer:bool,end:int,valid:bool}|null
     */
    private static function scriptClosingTagAt(
        string $html,
        int $contentStart
    ): ?array {
        $length = strlen($html);
        $offset = $contentStart;
        $state = 'data';

        while ($offset < $length) {
            if ($state !== 'data' && substr($html, $offset, 3) === '-->') {
                // Escaped and double-escaped dash-dash states both return to
                // ordinary script data when `>` arrives.
                $state = 'data';
                $offset += 3;
                continue;
            }

            if ($html[$offset] !== '<') {
                $offset++;
                continue;
            }

            if ($state === 'data') {
                if (substr($html, $offset, 4) === '<!--') {
                    $state = 'escaped';
                    $offset += 4;
                    continue;
                }
                if (self::scriptKeywordAt($html, $offset, true)) {
                    return self::tagAt($html, $offset);
                }
            } elseif ($state === 'escaped') {
                if (self::scriptKeywordAt($html, $offset, true)) {
                    return self::tagAt($html, $offset);
                }
                if (self::scriptKeywordAt($html, $offset, false)) {
                    $state = 'double_escaped';
                    $offset += 8; // `<script` plus its state-changing delimiter
                    continue;
                }
            } elseif (self::scriptKeywordAt($html, $offset, true)) {
                // In double-escaped text this spelling is emitted as text and
                // only changes the tokenizer back to the escaped state.
                $state = 'escaped';
                $offset += 9; // `</script` plus its delimiter
                continue;
            }

            $offset++;
        }

        return null;
    }

    /**
     * Whether $offset starts `<scriptX` or `</scriptX`, where X is one of the
     * delimiters that completes the script tokenizer's temporary buffer.
     */
    private static function scriptKeywordAt(
        string $html,
        int $offset,
        bool $closer
    ): bool {
        $keyword = $closer ? '</script' : '<script';
        $keywordLength = strlen($keyword);
        if (strncasecmp(substr($html, $offset, $keywordLength), $keyword, $keywordLength) !== 0) {
            return false;
        }
        $delimiter = $html[$offset + $keywordLength] ?? '';
        return self::isSpaceByte($delimiter)
            || $delimiter === '/'
            || $delimiter === '>';
    }

    /**
     * Find the matching closer for a normal/inert opaque element. Unlike raw
     * text, these elements can nest; comments, declarations, quoted tag
     * attributes, and other opaque descendants must not supply a fake closer.
     */
    private static function nestedOpaqueElementEnd(
        string $html,
        string $name,
        int $contentStart
    ): int {
        $length = strlen($html);
        $offset = $contentStart;
        $depth = 1;

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                return $length;
            }

            $specialEnd = self::specialMarkupEnd($html, $start);
            if ($specialEnd !== null) {
                $offset = $specialEnd;
                continue;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }

            if ($tag['name'] === $name) {
                if ($tag['closer']) {
                    $depth--;
                    if ($depth === 0) {
                        return $tag['end'];
                    }
                } else {
                    $depth++;
                }
                $offset = $tag['end'];
                continue;
            }

            if (!$tag['closer']
                && in_array($tag['name'], self::OPAQUE_ELEMENTS, true)
            ) {
                $offset = self::opaqueElementEnd($html, $tag['name'], $tag['end']);
                continue;
            }

            $offset = $tag['end'];
        }

        return $length;
    }

    /** Replace a range with spaces while retaining CR/LF and byte offsets. */
    private static function mask(string &$view, int $start, int $end): void
    {
        $source = substr($view, $start, $end - $start);
        $masked = (string) preg_replace('/[^\r\n]/', ' ', $source);
        $view = substr_replace($view, $masked, $start, $end - $start);
    }
}
