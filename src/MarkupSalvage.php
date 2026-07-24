<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

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
    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Repair $markup when it is truncated; a complete document is returned
     * byte-for-byte untouched with no notes.
     *
     * @return array{markup:string,notes:list<string>}
     * @throws \RuntimeException when nothing complete remains to keep
     */
    public static function repair(string $markup): array
    {
        $doc = BlockMarkup::parse($markup);
        $open = $doc->unclosedIndices();
        $dangling = self::danglingDelimiterOffset($markup);
        if ($open === [] && $dangling === null && !$doc->hasMismatchedDelimiters()) {
            return ['markup' => $markup, 'notes' => []];
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
            $end = self::lastCompleteChildEnd($doc, $markup, $open[$i], $cut);
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
            $salvaged .= self::closers($doc, $open[$i]);
        }

        $reparsed = BlockMarkup::parse($salvaged);
        if (trim($salvaged) === ''
            || $reparsed->indices() === []
            || $reparsed->unclosedIndices() !== []
            || $reparsed->hasMismatchedDelimiters()
        ) {
            throw new \RuntimeException('markup is truncated beyond salvage (no complete block to keep)');
        }

        $notes = [];
        if ($dropped > 0) {
            $notes[] = "dropped {$dropped} incomplete trailing block(s)";
        }
        if ($keep >= 0) {
            $notes[] = 'closed ' . ($keep + 1) . ' unclosed block(s)';
        }
        if ($notes === []) {
            $notes[] = 'trimmed an incomplete trailing delimiter';
        }
        return [
            'markup' => $salvaged,
            'notes'  => ['salvaged truncated markup: ' . implode(', ', $notes)],
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
    private static function lastCompleteChildEnd(BlockMarkup $doc, string $markup, int $idx, int $cut): ?int
    {
        $open = $doc->unclosedIndices();
        foreach (array_reverse($doc->children($idx)) as $child) {
            if (in_array($child, $open, true)) {
                continue;
            }
            $end = self::blockEnd($doc, $markup, $child);
            if ($end !== null && $end <= $cut) {
                return $end;
            }
        }
        return null;
    }

    /**
     * End offset of a closed block including its closing comment. Null when
     * the closer is not literally where the parse says the inner HTML ends
     * (a stray-closer artifact) — not a safe cut point.
     */
    private static function blockEnd(BlockMarkup $doc, string $markup, int $idx): ?int
    {
        if ($doc->isVoid($idx)) {
            // Void block: complete at its own delimiter.
            return $doc->openingOffset($idx) + $doc->openingLength($idx);
        }
        $innerEnd = $doc->openingOffset($idx) + $doc->openingLength($idx) + strlen($doc->innerHtml($idx));
        $name = preg_quote($doc->name($idx), '/');
        if (preg_match('/\A<!--\s+\/wp:' . $name . '\s+-->/s', substr($markup, $innerEnd), $m) !== 1) {
            return null;
        }
        return $innerEnd + strlen($m[0]);
    }

    /**
     * The synthesized closers for one unclosed block: the closing tags for
     * whatever HTML elements its own HTML (opening comment up to its first
     * child) left open, then its block closer comment.
     */
    private static function closers(BlockMarkup $doc, int $idx): string
    {
        $stack = [];
        preg_match_all(
            '/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(\/?)>/s',
            $doc->ownHtml($idx),
            $tags,
            PREG_SET_ORDER,
        );
        foreach ($tags as $tag) {
            $name = strtolower($tag[2]);
            if ($tag[1] === '/') {
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i] === $name) {
                        array_splice($stack, $i, 1);
                        break;
                    }
                }
            } elseif ($tag[4] !== '/' && !in_array($name, self::VOID_TAGS, true)) {
                $stack[] = $name;
            }
        }

        $out = '';
        foreach (array_reverse($stack) as $name) {
            $out .= "</{$name}>";
        }
        return ($out !== '' ? "\n" . $out : '') . "\n<!-- /wp:" . $doc->name($idx) . ' -->';
    }
}
