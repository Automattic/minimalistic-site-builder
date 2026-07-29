<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/**
 * Wrap raw <li> children of a wp:list into wp:list-item blocks.
 *
 * Models sometimes emit a wp:list whose <ul>/<ol> holds plain <li> HTML with
 * no wp:list-item inner-block comments. The save renderer rebuilds a list's
 * body exclusively from its parsed innerBlocks, so those raw items used to be
 * silently discarded and the list shipped as an EMPTY <ul> (BIGR-738/atlas3).
 * This mechanical pre-parse repair inserts the missing wp:list-item comment
 * delimiters so the items survive re-serialization as real blocks.
 *
 * The repair is deliberately conservative: it only touches a wp:list whose
 * body contains <li> elements and NO block comments at all — the unambiguous
 * all-raw shape. A body that mixes wrapped and raw items, or nests further
 * lists, is left for the serializer (and the empty-container oracle) to
 * judge, because wrapping the wrong span there could corrupt valid markup.
 */
final class ListItemFixer
{
    public function fix(string $html): ListItemFixResult
    {
        if (!str_contains($html, '<!-- wp:list')) {
            return new ListItemFixResult($html, 0);
        }

        $total = 0;
        $listOrdinal = -1;
        $repairedListOrdinals = [];
        // "wp:list" followed by whitespace (attrs or none) — never "wp:list-item".
        $fixed = preg_replace_callback(
            '/(<!-- wp:list(?:\s[^>]*?)?-->)([\s\S]*?)(<!-- \/wp:list -->)/',
            function (array $block) use (&$total, &$listOrdinal, &$repairedListOrdinals): string {
                $listOrdinal++;
                $body = $block[2];
                if (!preg_match('/<li\b/i', $body)
                    || str_contains($body, '<!-- wp:')
                    // More than the list's own wrapper element means nested
                    // lists, where a non-greedy item match could cut wrong.
                    || preg_match_all('/<[ou]l\b/i', $body) > 1
                ) {
                    return $block[0];
                }
                $wrapped = preg_replace_callback(
                    '/<li\b[\s\S]*?<\/li>/i',
                    function (array $item) use (&$total): string {
                        $total++;
                        return "<!-- wp:list-item -->\n" . $item[0] . "\n<!-- /wp:list-item -->";
                    },
                    $body,
                );
                if ($wrapped === null || $wrapped === $body) {
                    return $block[0];
                }
                $repairedListOrdinals[] = $listOrdinal;
                return $block[1] . $wrapped . $block[3];
            },
            $html,
        );

        return new ListItemFixResult($fixed ?? $html, $total, $repairedListOrdinals);
    }
}
