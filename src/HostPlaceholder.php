<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;

/**
 * The block mechanics every host placeholder contract shares.
 *
 * A placeholder is a paragraph block carrying a known class, whose only text
 * is a spec opening with a known marker. Locating one, counting the markers
 * that are not one, and deleting those is the same work whether the spec
 * describes a form or a map. Only the grammar differs, and that stays in the
 * contract classes — which the host reads too, so a pattern that drifted
 * between them would fail silently.
 *
 * @see FormPlaceholder
 * @see MapPlaceholder
 */
final class HostPlaceholder
{
    /**
     * Every placeholder block of one kind, whole block and spec text.
     *
     * The class counts whether it is on the `<p>` or only in the block
     * comment's `className`. The two are the same authored intent, and the
     * re-serializer turns the second into the first — but it runs after the
     * pass that deletes markers no placeholder claims, so reading only the
     * `<p>` would throw the block away one step before it was repaired.
     *
     * A block with no safe end offset is skipped rather than guessed at. Its
     * `block` string is what the host hands to substr_replace, so a span that
     * ran past a dropped closing delimiter would take the next section's
     * blocks out with it.
     *
     * @param string $markerName The bare marker, e.g. `JP_FORM`.
     * @param string $className  The class the host locates the block by.
     * @return list<array{block:string, spec:string}>
     */
    public static function find(string $markup, string $markerName, string $className): array
    {
        $doc = BlockMarkup::parse($markup);
        $found = [];

        foreach ($doc->indices() as $i) {
            if (
                $doc->name($i) !== 'paragraph'
                || !$doc->isStructurallySafe($i)
                || !self::isPlaceholder($doc, $i, $markerName, $className)
            ) {
                continue;
            }

            $start = $doc->openingOffset($i);
            $found[] = [
                'block' => substr($markup, $start, ((int) $doc->endOffset($i)) - $start),
                'spec'  => self::spec($doc, $i),
            ];
        }

        return $found;
    }

    /** Whether this paragraph claims the class and its text opens with the marker. */
    private static function isPlaceholder(
        BlockMarkup $doc,
        int $i,
        string $markerName,
        string $className,
    ): bool {
        if (
            !in_array($className, self::commentClasses($doc, $i), true)
            && !self::wrapperHasClass($doc->ownHtml($i), $className)
        ) {
            return false;
        }

        return str_starts_with(self::spec($doc, $i), $markerName . ':');
    }

    /** The readable text of a paragraph's inner HTML. */
    private static function spec(BlockMarkup $doc, int $i): string
    {
        return trim(PlainText::fromMarkup($doc->innerHtml($i)));
    }

    /**
     * How many times the marker appears at all, placeholder or not.
     *
     * The name alone counts, without the colon a spec opens with: a paragraph
     * reading `JP_FORM` is machine text a visitor can read, and it is the
     * shape a marker takes when the model drops the spec it was supposed to
     * carry. Counting only well-formed prefixes would call that page clean.
     */
    public static function markerCount(string $markup, string $markerName): int
    {
        return substr_count($markup, $markerName);
    }

    /**
     * Drop the marker paragraphs no host will ever substitute.
     *
     * A marker only means something inside a placeholder block: that is the
     * class the host looks the block up by. Anywhere else the paragraph is
     * ordinary body copy that happens to read like a marker, and it ships to
     * the visitor as grey text. Removing it costs nothing the section had,
     * because nothing downstream could have built anything from it.
     *
     * @return array{markup:string, removed:int}
     */
    public static function stripLooseMarkers(string $markup, string $markerName, string $className): array
    {
        // Classified by the same walk find() uses, so the two cannot disagree
        // about what a placeholder is. They did when this matched with its own
        // pattern: a block find() skipped as structurally unsafe was one this
        // deleted, taking a real placeholder with it and reporting the removal
        // as machine text no host would have read.
        $doc = BlockMarkup::parse($markup);
        $cuts = [];
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'paragraph' || !$doc->isStructurallySafe($i)) {
                continue;
            }
            $start = $doc->openingOffset($i);
            $end = (int) $doc->endOffset($i);
            $block = substr($markup, $start, $end - $start);
            if (!str_contains($block, $markerName) || self::isPlaceholder($doc, $i, $markerName, $className)) {
                continue;
            }
            $cuts[] = [$start, $end];
        }

        if ($cuts === []) {
            return ['markup' => $markup, 'removed' => 0];
        }

        // Back to front, so an earlier cut cannot move a later one's offsets.
        $stripped = $markup;
        foreach (array_reverse($cuts) as [$start, $end]) {
            $stripped = substr($stripped, 0, $start) . substr($stripped, $end);
        }

        return ['markup' => trim($stripped), 'removed' => count($cuts)];
    }

    /**
     * The block comment's `className` tokens, decoded rather than pattern-matched.
     *
     * @return list<string>
     */
    private static function commentClasses(BlockMarkup $doc, int $i): array
    {
        return preg_split(
            '/\s+/',
            trim((string) (($doc->attrs($i) ?? [])['className'] ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
    }

    /**
     * Whether the block's own `<p>` carries the class as a whole token.
     *
     * A substring test is not enough: `-` does not end a word for `\b`, so a
     * regex boundary matches `wp-block-jetpack-map-placeholder-legacy` as
     * though it were the class. The HTML parser splits real class tokens and
     * accepts either quote style.
     */
    private static function wrapperHasClass(string $ownHtml, string $className): bool
    {
        foreach (HtmlFragment::parse($ownHtml)->querySelectorAll('p') as $p) {
            $tokens = preg_split('/\s+/', trim((string) $p->attribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array($className, $tokens, true)) {
                return true;
            }
        }

        return false;
    }
}
