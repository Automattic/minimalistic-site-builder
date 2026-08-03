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

    public const OPAQUE_ELEMENTS = [
        'script', 'style', 'textarea', 'title', 'xmp',
        'iframe', 'object', 'applet', 'noembed', 'noframes', 'noscript',
        'template', 'code', 'pre', 'plaintext',
    ];

    /**
     * HTML tags that end an SVG/MathML subtree wherever they appear inside it,
     * per the "in foreign content" tree-construction rules.
     */
    private const FOREIGN_BREAKOUT = [
        'b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div',
        'dl', 'dt', 'em', 'embed', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head',
        'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p',
        'pre', 'ruby', 's', 'small', 'span', 'strong', 'strike', 'sub', 'sup',
        'table', 'tt', 'u', 'ul', 'var',
    ];

    public static function delimiterView(string $html): string
    {
        $view = $html;
        $length = strlen($html);
        $offset = 0;
        $foreign = [];
        $memo = [];

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            if (substr($html, $start, 4) === '<!--') {
                $end = self::commentEnd($html, $start) ?? $length;
                $comment = substr($html, $start, $end - $start);
                if (preg_match('/\A<!--\s*\/?wp:/', $comment) !== 1) {
                    self::mask($view, $start, $end);
                }
                $offset = $end;
                continue;
            }

            $specialEnd = self::specialMarkupEnd($html, $start, $foreign !== []);
            if ($specialEnd !== null) {
                self::mask($view, $start, $specialEnd);
                $offset = max($specialEnd, $start + 1);
                continue;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }

            $end = $tag['end'];
            // Foreign content holds real child elements, so nothing in an
            // SVG/MathML subtree is an opaque text region.
            if (!$tag['closer']
                && $foreign === []
                && in_array($tag['name'], self::OPAQUE_ELEMENTS, true)
            ) {
                // An unclosed inert element must not mask the rest of the
                // response: hiding every later delimiter turns an ordinary
                // stray <code> into an unrecoverable document.
                $end = self::opaqueElementEnd($html, $tag['name'], $end, false, $memo);
            }
            self::trackForeign($foreign, $tag);
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
        $found = preg_match_all('/<!--\s*\/?wp:/', $html, $markers, PREG_OFFSET_CAPTURE);
        if ($found === false) {
            // Fail closed: an empty list reads as "nothing hidden" and passes
            // the document through assertComplete().
            throw new \RuntimeException('could not scan for hidden block delimiters');
        }
        if ($found > 0) {
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
        $foreign = [];

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            $specialEnd = self::specialMarkupEnd($html, $start, $foreign !== []);
            if ($specialEnd !== null) {
                $offset = max($specialEnd, $start + 1);
                continue;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }

            if (!$tag['closer'] && array_key_exists($tag['name'], $targets)) {
                // In HTML, a slash does not self-close these non-void
                // elements (`<script/>` is still an opening script tag) —
                // but in foreign content it does, and the element has no
                // body to remove.
                $end = $tag['selfClosing'] && $foreign !== []
                    ? $tag['end']
                    : self::opaqueElementEnd($html, $tag['name'], $tag['end']);
                $out .= substr($html, $keptFrom, $start - $keptFrom);
                $keptFrom = $end;
                $offset = $end;
                continue;
            }

            self::trackForeign($foreign, $tag);

            // Text inside a non-target raw-text element cannot contain real
            // child elements. Skipping it prevents tag-shaped strings there
            // from being mistaken for sanitizer targets.
            if (!$tag['closer'] && self::isRawText($tag['name'], $foreign !== [])) {
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
        $foreign = [];

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            $specialEnd = self::specialMarkupEnd($html, $start, $foreign !== []);
            if ($specialEnd !== null) {
                $offset = max($specialEnd, $start + 1);
                continue;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }
            self::trackForeign($foreign, $tag);
            if (array_key_exists($tag['name'], $targets)) {
                $out .= substr($html, $keptFrom, $start - $keptFrom);
                $keptFrom = $tag['end'];
            }

            // A tag-shaped string in a raw-text body is text, not a tag.
            // Without this skip a `<!--` in a <style> body reads as a comment
            // start, runs to EOF, and every later <base>/<embed> survives.
            if (!$tag['closer'] && self::isRawText($tag['name'], $foreign !== [])) {
                $offset = self::opaqueElementEnd($html, $tag['name'], $tag['end']);
                continue;
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
        $foreign = [];

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            $specialEnd = self::specialMarkupEnd($html, $start, $foreign !== []);
            if ($specialEnd !== null) {
                $offset = max($specialEnd, $start + 1);
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

            self::trackForeign($foreign, $tag);

            // Tag-shaped text in raw-text bodies is not a child tag. Inside
            // foreign content there is no raw text, so those bodies are
            // scanned and their event handlers still stripped.
            if (!$tag['closer'] && self::isRawText($tag['name'], $foreign !== [])) {
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
        $foreign = [];

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                return self::isInsignificant(substr($html, $offset)) ? $tags : null;
            }
            if (!self::isInsignificant(substr($html, $offset, $start - $offset))) {
                return null;
            }

            if (substr($html, $start, 4) === '<!--') {
                $end = self::commentEnd($html, $start);
                if ($end === null) {
                    return null;
                }
                $offset = $end;
                continue;
            }
            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                return null;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null || !$tag['valid']) {
                return null;
            }
            // A slash self-closes a foreign element, so `<path/>` inside an
            // <svg> leaves nothing open and never needs a closer. In HTML the
            // same slash is only a parse-error separator (`<span/>` stays
            // open), so this cannot be applied to the document at large.
            $selfCloses = $tag['selfClosing']
                && !$tag['closer']
                && ($foreign !== []
                    || $tag['name'] === 'svg'
                    || $tag['name'] === 'math');

            if (!$selfCloses) {
                $tags[] = ['name' => $tag['name'], 'closer' => $tag['closer']];
            }
            self::trackForeign($foreign, $tag);
            $offset = $tag['end'];

            // Raw-text bodies may contain arbitrary text and tag-looking
            // bytes. Skip them as one lexical region, but retain the real
            // opening/closing pair in the wrapper token stream.
            if (!$tag['closer']
                && $foreign === []
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
     * Whitespace plus the invisible characters models sprinkle between blocks:
     * NBSP, zero-width space, and a stray BOM. None of them is HTML
     * inter-element whitespace, so isWhitespace() must keep rejecting them —
     * but as the gap between two sibling blocks they are not prose either, and
     * treating them as content fails an otherwise-clean document.
     */
    public static function isInsignificant(string $text): bool
    {
        return preg_match(
            '/\A(?:[\x09\x0A\x0C\x0D\x20]|\xC2\xA0|\xE2\x80\x8B|\xEF\xBB\xBF)*\z/D',
            $text,
        ) === 1;
    }

    /**
     * @return array{name:string,closer:bool,end:int,valid:bool,selfClosing:bool}|null
     */
    private static function tagAt(string $html, int $start): ?array
    {
        // The name must follow `<` (or `</`) with no space between. Browsers
        // emit `< b` as text; accepting it here invents a tag whose boundary
        // runs to the next `>`, swallowing the real markup in between.
        //
        // \G anchors the match at $start so the scan does not copy the rest of
        // the document at every `<`.
        if (preg_match(
            '/\G<(\/?)([a-zA-Z][^\x09\x0A\x0C\x0D\x20\/>]*)'
                . '(?=[\x09\x0A\x0C\x0D\x20\/>])/',
            $html,
            $tag,
            0,
            $start,
        ) !== 1) {
            return null;
        }

        $boundary = self::tagBoundary(
            $html,
            $start + strlen($tag[0]),
            $tag[1] === '/',
        );
        return [
            'name'        => strtolower($tag[2]),
            'closer'      => $tag[1] === '/',
            'end'         => $boundary['end'],
            'selfClosing' => $boundary['selfClosing'],
            'valid'       => $boundary['valid']
                && preg_match('/\A[a-zA-Z][a-zA-Z0-9:-]*\z/D', $tag[2]) === 1,
        ];
    }

    /**
     * Locate a tag's browser-visible end with attribute-state-aware quoting.
     *
     * @return array{end:int,valid:bool,selfClosing:bool}
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
                    return [
                        'end' => $offset + 1,
                        'valid' => $valid,
                        'selfClosing' => $state === 'self_closing',
                    ];
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
                    return [
                        'end' => $offset + 1,
                        'valid' => false,
                        'selfClosing' => false,
                    ];
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
                    return [
                        'end' => $offset + 1,
                        'valid' => $valid,
                        'selfClosing' => $state === 'self_closing',
                    ];
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
                    return [
                        'end' => $offset + 1,
                        'valid' => $valid,
                        'selfClosing' => $state === 'self_closing',
                    ];
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
                    return [
                        'end' => $offset + 1,
                        'valid' => $valid,
                        'selfClosing' => $state === 'self_closing',
                    ];
                }
                $valid = false;
                $state = 'before_attribute';
                continue;
            }

            if ($state === 'self_closing') {
                if ($char === '>') {
                    return [
                        'end' => $offset + 1,
                        'valid' => $valid,
                        'selfClosing' => $state === 'self_closing',
                    ];
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
                return [
                    'end' => $offset + 1,
                    'valid' => $valid,
                    'selfClosing' => $state === 'self_closing',
                ];
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

        return ['end' => $length, 'valid' => false, 'selfClosing' => false];
    }

    private static function isSpaceByte(string $char): bool
    {
        return $char === ' '
            || $char === "\t"
            || $char === "\n"
            || $char === "\f"
            || $char === "\r";
    }

    /**
     * End of the comment beginning at $start, or null when it never closes.
     *
     * A comment ends three ways, not one: `-->`, `--!>` (the comment-end-bang
     * state), and immediately for `<!-->` and `<!--->`, which are complete
     * empty comments. Recognizing only `-->` runs the comment to EOF and every
     * byte after the real terminator is skipped — past the sanitizer, since
     * these scans treat a comment as one inert region.
     */
    private static function commentEnd(string $html, int $start): ?int
    {
        if (substr($html, $start + 4, 1) === '>') {
            return $start + 5;
        }
        if (substr($html, $start + 4, 2) === '->') {
            return $start + 6;
        }
        if (preg_match(
            '/--!?>/',
            $html,
            $close,
            PREG_OFFSET_CAPTURE,
            $start + 4,
        ) !== 1) {
            return null;
        }
        return $close[0][1] + strlen($close[0][0]);
    }

    /**
     * End of a bogus comment — `<!` that is not a comment or (in foreign
     * content) CDATA, `<?`, and `</` not followed by a name.
     *
     * A bogus comment ends at the first `>`; quotes do not protect it, and
     * neither does a quoted `>` in a DOCTYPE identifier. Being quote-aware
     * here stretches the inert region over live markup that then never
     * reaches the sanitizer.
     *
     * The end is clamped to the next block delimiter. An unterminated `<!` in
     * model preamble prose would otherwise hide the whole document, and this
     * scanner exists to find that document; a bogus comment that reaches a
     * delimiter has already left anything a browser would treat as comment
     * text far behind.
     */
    private static function bogusCommentEnd(string $html, int $start): int
    {
        $length = strlen($html);
        $close = strpos($html, '>', $start + 2);
        $end = $close === false ? $length : $close + 1;

        if (preg_match(
            '/<!--\s*\/?wp:/',
            $html,
            $delimiter,
            PREG_OFFSET_CAPTURE,
            $start + 2,
        ) === 1 && $delimiter[0][1] < $end) {
            return $delimiter[0][1];
        }
        return $end;
    }

    /**
     * End of a comment/declaration beginning at $start, or null for a tag or
     * ordinary less-than character.
     *
     * `<![CDATA[` is only CDATA inside an SVG/MathML subtree. In HTML content
     * a browser reads it as a bogus comment ending at the first `>`, so
     * honoring `]]>` there skips over live markup — and when no `]]>` exists
     * at all, over the entire rest of the response.
     */
    private static function specialMarkupEnd(
        string $html,
        int $start,
        bool $inForeign = false
    ): ?int {
        $length = strlen($html);
        if (substr($html, $start, 4) === '<!--') {
            return self::commentEnd($html, $start) ?? $length;
        }
        if ($inForeign && substr($html, $start, 9) === '<![CDATA[') {
            $close = strpos($html, ']]>', $start + 9);
            // With no ]]> anywhere, skipping to EOF would hand the rest of the
            // response past the sanitizer. Fall back to the bogus-comment end.
            return $close === false
                ? self::bogusCommentEnd($html, $start)
                : $close + 3;
        }
        if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
            return self::bogusCommentEnd($html, $start);
        }
        // `</` with no name is a bogus comment too, not an end tag.
        if (substr($html, $start, 2) === '</'
            && preg_match('/\A<\/[a-zA-Z]/', substr($html, $start, 3)) !== 1
        ) {
            return self::bogusCommentEnd($html, $start);
        }
        return null;
    }

    /**
     * Track the SVG/MathML subtrees a scan is inside. HTML raw-text rules and
     * CDATA both hinge on this: inside foreign content `<title>` holds real
     * elements rather than text, and a self-closing slash is honored.
     *
     * @param list<string> $foreign
     * @param array{name:string,closer:bool,selfClosing:bool} $tag
     */
    private static function trackForeign(array &$foreign, array $tag): void
    {
        // A browser also leaves foreign content on these HTML tags, with no
        // closer involved. Missing that keeps the scan in foreign mode, where
        // <![CDATA[ is honored and ]]> skips over live HTML. `font` is
        // deliberately absent — it breaks out only when it carries
        // color/face/size, and staying foreign scans more, never less.
        if ($foreign !== [] && in_array($tag['name'], self::FOREIGN_BREAKOUT, true)) {
            $foreign = [];
            return;
        }
        if ($tag['name'] !== 'svg' && $tag['name'] !== 'math') {
            return;
        }
        if (!$tag['closer']) {
            if (!$tag['selfClosing']) {
                $foreign[] = $tag['name'];
            }
            return;
        }
        for ($i = count($foreign) - 1; $i >= 0; $i--) {
            if ($foreign[$i] === $tag['name']) {
                array_splice($foreign, $i);
                return;
            }
        }
    }

    /** Whether an element's body is text rather than markup at this position. */
    private static function isRawText(string $name, bool $inForeign): bool
    {
        if ($inForeign) {
            return false;
        }
        return in_array($name, self::RAW_TEXT_ELEMENTS, true)
            || $name === 'plaintext';
    }

    /**
     * @param bool $toEofWhenUnclosed End an unclosed inert element at EOF
     *        (safe when removing it) rather than at its start tag (safe when
     *        masking, where over-reach hides the rest of the document).
     *        Genuine raw text always runs to EOF — that is what a browser
     *        does with an unclosed <script> or <title>.
     */
    private static function opaqueElementEnd(
        string $html,
        string $name,
        int $contentStart,
        bool $toEofWhenUnclosed = true,
        array &$memo = []
    ): int {
        if ($name === 'plaintext') {
            return strlen($html);
        }

        if (!in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
            return self::nestedOpaqueElementEnd(
                $html,
                $name,
                $contentStart,
                $toEofWhenUnclosed,
                $memo,
            );
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
        // No whitespace after `</`: a browser reads `</ style>` as a bogus
        // comment, not a closer. Matching it here and then rejecting it in
        // tagAt() left the element running to EOF on this side only, while
        // the seeder's copy ended it — the two must agree on the boundary.
        if (preg_match(
            '#</' . preg_quote($name, '#')
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
     * Offset of the last `</name` spelling in the document, or null for none.
     *
     * Deliberately looser than tagAt(): it may report a spelling that is not a
     * real closer (one inside an attribute, say). Over-reporting only costs a
     * full scan that would have run anyway, while under-reporting would end an
     * element early — so this errs toward "a closer might exist".
     */
    private static function lastClosingTagOffset(string $html, string $name): ?int
    {
        $needle = '</' . $name;
        $found = null;
        $from = 0;
        while (($at = stripos($html, $needle, $from)) !== false) {
            $next = $html[$at + strlen($needle)] ?? '';
            if ($next === ''
                || $next === '/'
                || $next === '>'
                || self::isSpaceByte($next)
            ) {
                $found = $at;
            }
            $from = $at + 1;
        }
        return $found;
    }

    /**
     * Find the matching closer for a normal/inert opaque element. Unlike raw
     * text, these elements can nest; comments, declarations, quoted tag
     * attributes, and other opaque descendants must not supply a fake closer.
     */
    private static function nestedOpaqueElementEnd(
        string $html,
        string $name,
        int $contentStart,
        bool $toEofWhenUnclosed = true,
        array &$memo = []
    ): int {
        // Without $toEofWhenUnclosed an unclosed child returns its own content
        // start, so the outer scan resumes just behind it and re-descends into
        // every deeper opaque element — exponential in the nesting depth.
        // Memoizing (name, offset) makes each span cost at most one scan.
        $key = $name . ':' . $contentStart . ':' . ($toEofWhenUnclosed ? '1' : '0');
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        // Depth can only reach zero where a closer exists. With none left at
        // or after $contentStart the answer is "unclosed" without scanning —
        // otherwise N openers of one name each walk to EOF on their own key,
        // and the per-offset memo never hits (quadratic in the response size).
        // -1 means "no closer anywhere"; ??= would treat a cached null as a
        // miss and rescan on exactly the input this fast path exists for.
        $lastKey = "\0last:" . $name;
        $memo[$lastKey] ??= self::lastClosingTagOffset($html, $name) ?? -1;
        if ($memo[$lastKey] < $contentStart) {
            return $memo[$key] = $toEofWhenUnclosed ? strlen($html) : $contentStart;
        }
        $memo[$key] = strlen($html);

        $length = strlen($html);
        $offset = $contentStart;
        $depth = 1;
        $foreign = [];
        $unclosed = $toEofWhenUnclosed ? $length : $contentStart;

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                return $memo[$key] = $unclosed;
            }

            $specialEnd = self::specialMarkupEnd($html, $start, $foreign !== []);
            if ($specialEnd !== null) {
                $offset = max($specialEnd, $start + 1);
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
                        return $memo[$key] = $tag['end'];
                    }
                } elseif (!$tag['selfClosing'] || $foreign === []) {
                    $depth++;
                }
                self::trackForeign($foreign, $tag);
                $offset = $tag['end'];
                continue;
            }

            self::trackForeign($foreign, $tag);
            if (!$tag['closer']
                && $foreign === []
                && in_array($tag['name'], self::OPAQUE_ELEMENTS, true)
            ) {
                $offset = self::opaqueElementEnd(
                    $html,
                    $tag['name'],
                    $tag['end'],
                    $toEofWhenUnclosed,
                    $memo,
                );
                continue;
            }

            $offset = $tag['end'];
        }

        return $memo[$key] = $unclosed;
    }

    /**
     * Replace a range with spaces while retaining CR/LF and byte offsets.
     *
     * Callers address the original source by offset, so the substitution has
     * to be length-preserving even when PCRE errors: casting a null result to
     * '' would shorten the view and desynchronize every later offset.
     */
    private static function mask(string &$view, int $start, int $end): void
    {
        $source = substr($view, $start, $end - $start);
        $masked = preg_replace('/[^\r\n]/', ' ', $source);
        if ($masked === null || strlen($masked) !== strlen($source)) {
            $masked = '';
            foreach (str_split($source === '' ? ' ' : $source) as $byte) {
                $masked .= ($byte === "\r" || $byte === "\n") ? $byte : ' ';
            }
            $masked = substr($masked, 0, strlen($source));
        }
        $view = substr_replace($view, $masked, $start, $end - $start);
    }
}
