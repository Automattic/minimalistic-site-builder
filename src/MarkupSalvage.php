<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;

/**
 * Best-effort repair of truncated generated block markup.
 *
 * A response that hit the provider's output-token ceiling stops mid-stream:
 * the document ends in a half-written block comment or HTML fragment, and
 * every block still open at that point is missing its closing delimiter and
 * saved-HTML closing tags. Downstream gates (section-rhythm's root-group
 * check, the theme validator) rightly reject such a document — but by then
 * every other section, page and theme.json call has already been paid for.
 *
 * This pass instead trims the incomplete tail back to the last fully-closed
 * block and synthesizes the missing closers — the tag stack still open in
 * each unclosed block's own HTML, then its block closer comment — so one
 * truncated part degrades to a shorter, well-formed part instead of failing
 * the whole build. An open block with no complete child to keep is dropped
 * whole rather than closed around a cut-off sentence.
 *
 * Pure — unit-testable.
 */
final class MarkupSalvage
{
    /** HTML void elements: nothing to close when rebuilding a tag stack. */
    private const VOID_TAGS = HtmlNode::VOID_TAGS;

    /**
     * Repair $markup when it is truncated; a complete document is returned
     * byte-for-byte untouched with no notes.
     *
     * @return array{markup:string,repairs:list<string>,warnings:list<string>}
     * @throws \RuntimeException when nothing complete remains to keep
     */
    public static function repair(string $markup): array
    {
        $delimiterView = HtmlBlockContext::delimiterView($markup);
        $doc = BlockMarkup::parse($markup, $delimiterView);
        $open = $doc->unclosedIndices();
        $dangling = self::danglingDelimiterOffset($delimiterView);
        $unsafeMalformed = array_values(array_filter(
            $doc->malformedDelimiterOffsets(),
            static fn (int $offset): bool => $offset !== $dangling,
        ));
        if ($unsafeMalformed !== []) {
            throw new \RuntimeException('markup has malformed block delimiters and cannot be safely salvaged');
        }
        if ($open === [] && $dangling === null && !$doc->hasMismatchedDelimiters()) {
            return ['markup' => $markup, 'repairs' => [], 'warnings' => []];
        }
        if ($doc->hasMismatchedDelimiters()) {
            throw new \RuntimeException('markup has mismatched block delimiters and cannot be safely salvaged');
        }

        // Walk the open chain from the innermost block outward. The first
        // block with a fully-closed direct child before the cut is kept: the
        // cut moves to just after that child, and the block plus every open
        // ancestor is closed below. Blocks deeper than that have nothing
        // complete to keep and are dropped whole.
        $cut = $dangling ?? strlen($markup);
        $dropped = 0;
        $keep = -1;
        for ($i = count($open) - 1; $i >= 0; $i--) {
            $end = self::lastCompleteChildEnd($doc, $open[$i], $cut);
            if ($end !== null) {
                $cut = $end;
                $keep = $i;
                break;
            }
            $cut = min($cut, $doc->openingOffset($open[$i]));
            $dropped++;
        }

        $salvaged = rtrim(substr($markup, 0, $cut));
        for ($i = $keep; $i >= 0; $i--) {
            $salvaged .= self::closers($doc, $open[$i], $markup, $cut);
        }

        $salvagedView = HtmlBlockContext::delimiterView($salvaged);
        $reparsed = BlockMarkup::parse($salvaged, $salvagedView);
        if (trim($salvaged) === ''
            || $reparsed->indices() === []
            || $reparsed->unclosedIndices() !== []
            || $reparsed->hasMismatchedDelimiters()
            || $reparsed->hasMalformedDelimiters()
            || HtmlBlockContext::hiddenDelimiterOffsets($salvaged, $salvagedView) !== []
        ) {
            throw new \RuntimeException('markup is truncated beyond salvage (no complete block to keep)');
        }

        $repairs = [];
        $warnings = [];
        if ($dropped > 0) {
            $warnings[] = "salvaged truncated markup: dropped {$dropped} incomplete trailing block(s)";
        } elseif ($dangling !== null) {
            $warnings[] = 'salvaged truncated markup: dropped an incomplete trailing block delimiter';
        }
        if ($keep >= 0) {
            $repairs[] = 'salvaged truncated markup: closed ' . ($keep + 1) . ' unclosed block(s)';
        }
        return [
            'markup'   => $salvaged,
            'repairs'  => $repairs,
            'warnings' => $warnings,
        ];
    }

    /**
     * Offset of a block-comment delimiter the truncation cut in half — a final
     * `<!--` that never reaches `-->` (e.g. mid-attribute-JSON) — or null.
     */
    private static function danglingDelimiterOffset(string $markup): ?int
    {
        $p = strrpos($markup, '<!--');
        if ($p === false || str_contains(substr($markup, $p), '-->')) {
            return null;
        }
        return $p;
    }

    /**
     * End offset (past the closing comment) of the last fully-closed direct
     * child of $idx that ends at or before $cut, or null when it has none.
     */
    private static function lastCompleteChildEnd(BlockMarkup $doc, int $idx, int $cut): ?int
    {
        $open = $doc->unclosedIndices();
        foreach (array_reverse($doc->children($idx)) as $child) {
            if (in_array($child, $open, true)) {
                continue;
            }
            $end = $doc->endOffset($child);
            if ($end !== null && $end <= $cut) {
                return $end;
            }
        }
        return null;
    }

    /**
     * The synthesized closers for one unclosed block: the closing tags for
     * whatever HTML elements its own HTML (opening comment up to its first
     * child) left open, then its block closer comment.
     */
    private static function closers(
        BlockMarkup $doc,
        int $idx,
        string $markup,
        int $cut
    ): string
    {
        $out = '';
        $wrapperHtml = self::wrapperHtmlThroughCut($doc, $idx, $markup, $cut);
        foreach (array_reverse(self::openElements($wrapperHtml)) as $name) {
            $out .= "</{$name}>";
        }
        return ($out !== '' ? "\n" . $out : '') . "\n<!-- /wp:" . $doc->name($idx) . ' -->';
    }

    /**
     * HTML owned directly by one open block up to the retained cut, excluding
     * each child block's complete span. This includes wrapper tags introduced
     * between children, not only the prefix before the first child.
     */
    private static function wrapperHtmlThroughCut(
        BlockMarkup $doc,
        int $idx,
        string $markup,
        int $cut
    ): string {
        $cursor = $doc->openingOffset($idx) + $doc->openingLength($idx);
        $html = '';
        $cutInsideChild = false;

        foreach ($doc->children($idx) as $child) {
            $start = $doc->openingOffset($child);
            if ($start >= $cut) {
                break;
            }
            $html .= substr($markup, $cursor, $start - $cursor);

            $end = $doc->endOffset($child);
            if ($end === null || $end > $cut) {
                $cutInsideChild = true;
                break;
            }
            $cursor = $end;
        }

        if (!$cutInsideChild && $cursor < $cut) {
            $html .= substr($markup, $cursor, $cut - $cursor);
        }
        return $html;
    }

    /**
     * The HTML element names a fragment leaves open, in opening order. An
     * empty list means the fragment builds no container — for an unclosed
     * block opener, the signal that it decorates prose (a delimiter quoted in
     * a sentence) rather than wrapping real markup: a STATIC-save container
     * block always leaves its own root tag open until its closer. (Dynamic
     * wrappers that save no HTML of their own — wp:query and friends — would
     * defeat that signal, but they are outside the static section vocabulary
     * this generator emits.)
     *
     * @return list<string>
     */
    public static function openElements(string $html): array
    {
        $tags = HtmlBlockContext::wrapperTags($html);
        if ($tags === null) {
            throw new \RuntimeException('cannot analyze a non-wrapper HTML fragment safely');
        }
        $stack = [];
        foreach ($tags as $tag) {
            $name = $tag['name'];
            if ($tag['closer']) {
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i] === $name) {
                        // Closing an ancestor implicitly closes every
                        // descendant above it. Keeping those descendants
                        // would synthesize crossed closers during salvage.
                        array_splice($stack, $i);
                        break;
                    }
                }
            } elseif (!in_array($name, self::VOID_TAGS, true)) {
                $stack[] = $name;
            }
        }
        return $stack;
    }

    /**
     * Advance a strict HTML wrapper stack through one tags-only fragment.
     *
     * Unlike openElements(), every closer must match the top of the stack.
     * The original root may not close unless $allowRootClose is true, and
     * once it closes no later tag may start a replacement root. A slash on a
     * non-void HTML tag is ignored, matching HTML (`<span/>` stays open).
     *
     * @param list<string> $stack
     * @return list<string>|null null when the fragment is not a strict shell
     */
    public static function advanceStrictWrapperStack(
        string $html,
        array $stack = [],
        bool $allowRootClose = false
    ): ?array {
        $tags = HtmlBlockContext::wrapperTags($html);
        if ($tags === null) {
            return null;
        }

        $started = $stack !== [];
        $rootClosed = false;
        foreach ($tags as $tag) {
            if ($rootClosed) {
                return null;
            }

            $name = $tag['name'];
            if ($tag['closer']) {
                if ($stack === [] || $stack[count($stack) - 1] !== $name) {
                    return null;
                }
                array_pop($stack);
                if ($stack === []) {
                    if (!$allowRootClose) {
                        return null;
                    }
                    $rootClosed = true;
                }
                continue;
            }

            if (in_array($name, self::VOID_TAGS, true)) {
                if (!$started) {
                    return null;
                }
                continue;
            }

            $stack[] = $name;
            $started = true;
        }

        return $stack;
    }

    /**
     * Whether a fragment consists only of HTML wrapper tags/comments and
     * whitespace and leaves at least one real container open.
     */
    public static function isContainerPrefix(string $html): bool
    {
        $tags = HtmlBlockContext::wrapperTags($html);
        return $tags !== null && self::openElements($html) !== [];
    }

    /** Whether a fragment contains only complete tags/comments and whitespace. */
    public static function isWrapperFragment(string $html): bool
    {
        return HtmlBlockContext::wrapperTags($html) !== null;
    }
}
