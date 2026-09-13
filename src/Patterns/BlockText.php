<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\BlockMarkup;

/**
 * Where a block keeps its text, as bytes in the block's own markup.
 *
 * Both things that write copy into a pattern need this: the model fills slots,
 * and a binding writes a contact detail. They have to agree on the range down
 * to the byte, because two answers would mean one of them writing over the
 * other's tag, so the answer lives here and neither owns a copy of it.
 *
 * Text is found in the markup rather than in the parsed attributes because
 * these are rich-text attributes: WordPress saves them as element content, not
 * in the block comment. Writing them back through the attributes is what
 * obliges Big Sky to rebuild each block's saved HTML afterwards.
 */
final class BlockText
{
    /**
     * Blocks whose text may be rewritten, and the tag that holds it.
     *
     * A button's text lives in its inner anchor, not in the wrapper div, which
     * is why this is a tag per block rather than "the first element".
     */
    private const TEXT_TAG = [
        'core/paragraph' => 'p',
        'core/heading' => 'h[1-6]',
        'core/button' => 'a',
        'core/list-item' => 'li',
    ];

    /** Whether this block keeps text somewhere this can find it. */
    public static function carriesText(string $block): bool
    {
        return isset(self::TEXT_TAG[self::coreName($block)]);
    }

    /**
     * The byte range inside a block's own HTML that holds its text, relative
     * to `ownHtml()` so it can be handed straight to `spliceOwnHtml()`.
     *
     * Null when the block keeps no reachable text: an unknown block type, a
     * void block, one saved in a shape this does not recognise, or one whose
     * delimiters do not close. Every caller treats that as "leave it alone",
     * which is why this narrows rather than guesses.
     *
     * @return array{int, int}|null
     */
    public static function spanIn(BlockMarkup $document, int $index): ?array
    {
        $tag = self::TEXT_TAG[self::coreName($document->name($index))] ?? null;
        if ($tag === null || !$document->isStructurallySafe($index)) {
            return null;
        }

        $own = $document->ownHtml($index);
        $span = self::spanFor($own, $tag);

        return $span === null ? null : self::insideWrapper($own, $span[0], $span[1]);
    }

    /**
     * The span narrowed past any element that wraps the whole of it.
     *
     * A paragraph whose entire text is a link — `<p><a href="#">RSVP</a></p>`,
     * which is how a theme writes a text link — would otherwise have the link
     * replaced along with the words, and the page would carry the word RSVP
     * where it used to carry a way to do it. Writing inside the wrapper keeps
     * the element, its href and its classes, and changes only the words.
     *
     * Measured over ten live runs of the conference fixture: nine of the
     * fourteen slots holding inline markup are this shape. The other five mix
     * text with markup — "Lecture by <a>Prof. Presley</a>" — and one string
     * cannot preserve those, so they stay flattened and stay reported.
     *
     * @return array{int, int}
     */
    private static function insideWrapper(string $own, int $start, int $length): array
    {
        while (true) {
            $raw = substr($own, $start, $length);
            $trimmed = trim($raw);

            if (!preg_match('~^<([a-z][a-z0-9]*)~i', $trimmed, $open)) {
                return [$start, $length];
            }

            $inner = self::spanFor($trimmed, $open[1]);
            if ($inner === null || $inner[1] === 0) {
                return [$start, $length];
            }

            // The element has to close at the end, or it is the first of
            // several siblings and its "inner" text runs through the ones
            // after it.
            $after = substr($trimmed, $inner[0] + $inner[1]);
            if (!preg_match('~^</' . $open[1] . '\s*>$~i', $after)) {
                return [$start, $length];
            }

            $start += strlen($raw) - strlen(ltrim($raw)) + $inner[0];
            $length = $inner[1];
        }
    }

    /** The text a reader sees, which is what bounds a replacement's length. */
    public static function plainText(string $raw): string
    {
        return trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Core blocks are stored unqualified, as the block comment writes them. */
    public static function coreName(string $name): string
    {
        return str_contains($name, '/') ? $name : 'core/' . $name;
    }

    /**
     * Counts depth so a tag nested inside itself cannot close the span early.
     *
     * @return array{int, int}|null
     */
    private static function spanFor(string $own, string $tag): ?array
    {
        if (!preg_match('~<' . $tag . '(\s[^>]*)?>~i', $own, $open, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $open[0][1] + strlen($open[0][0]);
        $depth = 1;
        $at = $start;

        while (preg_match('~<(/?)' . $tag . '(\s[^>]*)?>~i', $own, $next, PREG_OFFSET_CAPTURE, $at)) {
            $at = $next[0][1] + strlen($next[0][0]);
            $depth += $next[1][0] === '/' ? -1 : 1;

            if ($depth === 0) {
                return [$start, $next[0][1] - $start];
            }
        }

        return null;
    }
}
