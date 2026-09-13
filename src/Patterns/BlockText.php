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

        return self::spanFor($document->ownHtml($index), $tag);
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
