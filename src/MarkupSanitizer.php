<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Deterministic strip of script-capable markup from generated block content.
 *
 * Every part is raw LLM output rendered verbatim on the site, and the page
 * markup is later stored as post content with the kses content filter
 * suspended (the seeder plugin — kses would mangle block comments), so
 * nothing between the model and the visitor's browser would otherwise stop a
 * <script> tag, an inline event handler, or a javascript: URL — a valid
 * core/html block can carry all three. Runs at markup intake (SectionsStep),
 * one choke point for the header, the footer, and every section; the seeder
 * plugin applies the same rules again at activation (ScaffoldPluginStep) in
 * case a page file was edited between build and seed. Keep the two in sync.
 *
 * Pure — unit-testable.
 */
final class MarkupSanitizer
{
    private const URL_ATTRIBUTES = [
        'href', 'src', 'xlink:href', 'formaction', 'action', 'poster',
    ];

    /**
     * @param list<string> $notes out-param: one line per class of removal, so
     *        callers can record the delivered-content change durably
     *        (warnings.json) instead of the strip staying silent.
     */
    public static function sanitize(string $markup, array &$notes = []): string
    {
        // Container bodies go entirely. Even inert/fallback content cannot be
        // left behind: it may contain block-looking comments that recovery
        // would otherwise activate after their enclosing tags are removed.
        // The scanner is quote/comment aware and follows nested
        // object/applet boundaries; a first-closer regex can expose exactly
        // the content this pass is meant to remove.
        // `style` is not script-capable, but a single model-authored rule
        // (`.site-header-shell{position:fixed}`) overrides the trusted theme
        // shell's positioning contract in the delivered site. Generated
        // markup never legitimately ships a stylesheet element — delivered
        // CSS belongs to the trusted asset kit — so the whole element goes.
        $containers = [
            'script', 'style', 'iframe', 'object', 'applet',
            'noembed', 'noframes', 'noscript',
        ];
        // SVG SMIL animation elements set the LIVE value of a sibling's
        // attribute: `<a href="#"><animate attributeName="href"
        // values="javascript:..."/>` makes the link execute on click even
        // though its own href is inert. The dangerous string rides `values`/
        // `to`/`from`/`by` — neither an on* handler nor a URL sink — so the
        // attribute passes below untouched. These elements have no role in
        // generated static markup, so drop them whole (DOMPurify forbids them
        // for the same reason).
        $animation = ['animate', 'animatetransform', 'animatemotion', 'set'];
        // `meta` carries no content but http-equiv="refresh" redirects every
        // visitor to a model-chosen URL, which no later pass would catch.
        $tags = array_merge($containers, $animation, ['embed', 'base', 'meta']);

        // Removal splices the bytes on either side of a deleted tag together,
        // and that seam can spell a new tag: `<<base>script>` becomes a live
        // `<script>` a browser would never have parsed from the input. Repeat
        // until stable. This terminates because a pass that changes anything
        // strictly shortens the markup.
        $removedBytes = 0;
        do {
            $before = $markup;
            $markup = HtmlBlockContext::removeElements($markup, $containers);
            $markup = HtmlBlockContext::removeTags($markup, $tags);
            $removedBytes += strlen($before) - strlen($markup);
        } while ($markup !== $before);
        if ($removedBytes > 0) {
            $notes[] = "removed script-capable element markup ({$removedBytes} byte(s))";
        }
        // Attribute tokens follow browser-like quote, whitespace, and slash
        // states. This matters for malformed-but-active forms such as
        // `<svg/onload=...>` and for `/` inside an unquoted ordinary value.
        $handlers = 0;
        $urls = 0;
        $markup = HtmlBlockContext::rewriteOpeningTags(
            $markup,
            static function (string $tag) use (&$handlers, &$urls): string {
                return self::sanitizeOpeningTag($tag, $handlers, $urls);
            },
        );
        if ($handlers > 0) {
            $notes[] = "removed {$handlers} inline event handler attribute(s)";
        }
        if ($urls > 0) {
            $notes[] = "neutralized {$urls} executable URL value(s)";
        }
        return $markup;
    }

    private static function sanitizeOpeningTag(string $tag, int &$handlers = 0, int &$urls = 0): string
    {
        $attributes = self::attributes($tag);
        $eventStarts = [];
        foreach ($attributes as $attribute) {
            if (self::isEventAttribute($attribute['name'])) {
                $eventStarts[$attribute['start']] = true;
            }
        }

        $edits = [];
        foreach ($attributes as $attribute) {
            if (self::isEventAttribute($attribute['name'])) {
                $next = $tag[$attribute['end']] ?? '>';
                $needsSeparator = !self::isSpaceByte($next)
                    && $next !== '/'
                    && $next !== '>'
                    && !array_key_exists($attribute['end'], $eventStarts);
                $edits[] = [
                    'start' => $attribute['start'],
                    'end' => $attribute['end'],
                    // A quoted value may be followed immediately by another
                    // attribute. Keep that next token from merging into the
                    // preceding name or unquoted value after deletion.
                    'replacement' => $needsSeparator ? ' ' : '',
                ];
                $handlers++;
                continue;
            }

            if ($attribute['valueStart'] !== null
                && in_array($attribute['name'], self::URL_ATTRIBUTES, true)
                && self::hasExecutableScheme(substr(
                    $tag,
                    $attribute['valueStart'],
                    $attribute['valueEnd'] - $attribute['valueStart'],
                ))
            ) {
                // Keep the attribute and its quote style so the surrounding
                // block grammar remains intact; only its destination changes.
                $edits[] = [
                    'start' => $attribute['valueStart'],
                    'end' => $attribute['valueEnd'],
                    'replacement' => '#',
                ];
                $urls++;
            }
        }

        usort(
            $edits,
            static fn (array $a, array $b): int => $b['start'] <=> $a['start'],
        );
        foreach ($edits as $edit) {
            $tag = substr_replace(
                $tag,
                $edit['replacement'],
                $edit['start'],
                $edit['end'] - $edit['start'],
            );
        }
        return $tag;
    }

    private static function isEventAttribute(string $name): bool
    {
        return strlen($name) > 2 && str_starts_with($name, 'on');
    }

    /**
     * Share the sanitizer's browser-like attribute tokenization with
     * deterministic generated-markup repairs. Callers receive source spans
     * into the supplied opening tag, so edits never mistake attribute-shaped
     * text inside a quoted value for a real attribute.
     *
     * @return list<array{
     *   name:string,start:int,end:int,valueStart:?int,valueEnd:?int
     * }>
     */
    public static function openingTagAttributes(string $tag): array
    {
        return self::attributes($tag);
    }

    /**
     * Tokenize attributes from one already-bounded opening tag.
     *
     * The spans follow the HTML tokenizer closely enough for security
     * decisions: quotes start values only after `=`, a slash after a tag name
     * is a parse-error separator, and a slash inside an unquoted value stays
     * in that value.
     *
     * @return list<array{
     *   name:string,start:int,end:int,valueStart:?int,valueEnd:?int
     * }>
     */
    private static function attributes(string $tag): array
    {
        // The name follows `<` with no space, matching HtmlBlockContext's tag
        // boundary. The two must agree on where attributes begin.
        if (preg_match(
            '/\A<[a-zA-Z][^\x09\x0A\x0C\x0D\x20\/>]*'
                . '(?=[\x09\x0A\x0C\x0D\x20\/>])/',
            $tag,
            $opening,
        ) !== 1) {
            return [];
        }

        $length = strlen($tag);
        $offset = strlen($opening[0]);
        $state = 'before_attribute';
        $quote = '';
        $pendingStart = null;
        $afterNameWhitespace = null;
        $attribute = null;
        $attributes = [];

        $begin = static function (int $at) use (&$attribute, &$pendingStart): void {
            $attribute = [
                'start' => $pendingStart ?? $at,
                'nameStart' => $at,
                'nameEnd' => null,
                'valueStart' => null,
            ];
            $pendingStart = null;
        };
        $commit = static function (
            int $end,
            ?int $valueEnd = null
        ) use (&$attribute, &$attributes, $tag): void {
            if ($attribute === null) {
                return;
            }
            $nameEnd = $attribute['nameEnd'] ?? $end;
            $attributes[] = [
                'name' => strtolower(substr(
                    $tag,
                    $attribute['nameStart'],
                    $nameEnd - $attribute['nameStart'],
                )),
                'start' => $attribute['start'],
                'end' => $end,
                'valueStart' => $attribute['valueStart'],
                'valueEnd' => $valueEnd,
            ];
            $attribute = null;
        };

        while ($offset < $length) {
            $char = $tag[$offset];

            if ($state === 'quoted_value') {
                if ($char === $quote) {
                    $commit($offset + 1, $offset);
                    $state = 'after_quoted_value';
                }
                $offset++;
                continue;
            }

            if ($state === 'unquoted_value') {
                if (self::isSpaceByte($char)) {
                    $commit($offset, $offset);
                    $pendingStart = $offset;
                    $state = 'before_attribute';
                    $offset++;
                } elseif ($char === '>') {
                    $commit($offset, $offset);
                    break;
                } else {
                    $offset++;
                }
                continue;
            }

            if ($state === 'before_value') {
                if (self::isSpaceByte($char)) {
                    $offset++;
                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    $attribute['valueStart'] = $offset + 1;
                    $state = 'quoted_value';
                    $offset++;
                    continue;
                }
                if ($char === '>') {
                    $attribute['valueStart'] = $offset;
                    $commit($offset, $offset);
                    break;
                }
                $attribute['valueStart'] = $offset;
                $state = 'unquoted_value';
                continue;
            }

            if ($state === 'attribute_name') {
                if ($char === '=') {
                    $attribute['nameEnd'] = $offset;
                    $state = 'before_value';
                    $offset++;
                    continue;
                }
                if (self::isSpaceByte($char)) {
                    $attribute['nameEnd'] = $offset;
                    $afterNameWhitespace = $offset;
                    $state = 'after_attribute_name';
                    $offset++;
                    continue;
                }
                if ($char === '/' || $char === '>') {
                    $attribute['nameEnd'] = $offset;
                    $state = 'after_attribute_name';
                    continue;
                }
                $offset++;
                continue;
            }

            if ($state === 'after_attribute_name') {
                if (self::isSpaceByte($char)) {
                    $afterNameWhitespace ??= $offset;
                    $offset++;
                    continue;
                }
                if ($char === '=') {
                    $afterNameWhitespace = null;
                    $state = 'before_value';
                    $offset++;
                    continue;
                }

                $commit((int) $attribute['nameEnd']);
                if ($char === '>') {
                    break;
                }
                $pendingStart = $afterNameWhitespace;
                $afterNameWhitespace = null;
                if ($char === '/') {
                    $pendingStart ??= $offset;
                    $state = 'self_closing';
                    $offset++;
                } else {
                    $state = 'before_attribute';
                }
                continue;
            }

            if ($state === 'after_quoted_value') {
                if (self::isSpaceByte($char)) {
                    $pendingStart = $offset;
                    $state = 'before_attribute';
                    $offset++;
                    continue;
                }
                if ($char === '/') {
                    $pendingStart = $offset;
                    $state = 'self_closing';
                    $offset++;
                    continue;
                }
                if ($char === '>') {
                    break;
                }
                $state = 'before_attribute';
                continue;
            }

            if ($state === 'self_closing') {
                if ($char === '>') {
                    break;
                }
                $state = 'before_attribute';
                continue;
            }

            // before_attribute
            if (self::isSpaceByte($char)) {
                $pendingStart ??= $offset;
                $offset++;
                continue;
            }
            if ($char === '>') {
                break;
            }
            if ($char === '/') {
                $pendingStart ??= $offset;
                $state = 'self_closing';
                $offset++;
                continue;
            }
            $begin($offset);
            $afterNameWhitespace = null;
            $state = 'attribute_name';
            if ($char === '=') {
                // With no preceding name, HTML keeps this first equals sign
                // in a malformed attribute name; it does not start a value.
                $offset++;
            }
        }

        if ($attribute !== null) {
            if ($state === 'attribute_name') {
                $attribute['nameEnd'] = $length;
                $commit($length);
            } elseif ($state === 'after_attribute_name') {
                $commit((int) $attribute['nameEnd']);
            } elseif ($state === 'before_value') {
                $attribute['valueStart'] = $length;
                $commit($length, $length);
            } elseif ($state === 'quoted_value' || $state === 'unquoted_value') {
                $commit($length, $length);
            }
        }

        return $attributes;
    }

    private static function hasExecutableScheme(string $value): bool
    {
        // PHP requires semicolons on numeric references; HTML does not. Decode
        // the ASCII subset explicitly first, then named/terminated references.
        // Every step fails closed: casting a PCRE error to '' would report an
        // empty scheme and keep the URL, so the one check standing between a
        // javascript: href and the visitor would silently pass it through.
        $decoded = preg_replace_callback(
            '/&#(?:(?:x|X)([0-9a-fA-F]+)|([0-9]+));?/',
            static function (array $match): string {
                $hex = ($match[1] ?? '') !== '';
                $digits = $hex ? $match[1] : $match[2];
                $significant = ltrim($digits, '0');
                if ($significant === '') {
                    return "\u{FFFD}";
                }
                if (strlen($significant) > ($hex ? 2 : 3)) {
                    return $match[0];
                }
                $codepoint = $hex ? hexdec($significant) : (int) $significant;
                return $codepoint > 0 && $codepoint <= 0x7f
                    ? chr($codepoint)
                    : $match[0];
            },
            $value,
        );
        if ($decoded === null) {
            return true;
        }
        $decoded = html_entity_decode(
            $decoded,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $stripped = preg_replace('/[\x00-\x20\x7F]+/', '', $decoded);
        if ($stripped === null) {
            return true;
        }
        return preg_match('/\A(?:javascript|vbscript|data):/i', $stripped) !== 0;
    }

    private static function isSpaceByte(string $char): bool
    {
        return $char === ' '
            || $char === "\t"
            || $char === "\n"
            || $char === "\f"
            || $char === "\r";
    }
}
