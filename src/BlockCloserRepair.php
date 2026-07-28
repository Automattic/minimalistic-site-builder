<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Insert missing structural block closers / wrapper tags when model markup is
 * almost balanced but forgets a delimiter or a shell </div>.
 *
 * Cases:
 *
 * 1. Lumen: group still open when /wp:column appears → insert <!-- /wp:group -->
 *    (and a column </div> when the model used one div for the group only).
 *
 * 2. Saltline: wp:buttons opens <div class="wp-block-buttons"> then closes
 *    <!-- /wp:buttons --> without </div> → child-zone validation fails on the
 *    blank/non-closed wrapper. Insert </div> before the buttons closer.
 */
final class BlockCloserRepair
{
    /** Containers we will auto-close when a later closer is for an ancestor. */
    private const AUTO_CLOSE = [
        'group' => true,
        'columns' => true,
        'column' => true,
        'buttons' => true,
    ];

    /**
     * @param list<string> $notes out-param
     */
    public static function repair(string $markup, array &$notes = []): string
    {
        $notes = [];
        $out = '';
        $offset = 0;
        $len = strlen($markup);
        /** @var list<string> $stack block names currently open */
        $stack = [];

        while ($offset < $len) {
            $start = strpos($markup, '<!--', $offset);
            if ($start === false) {
                $out .= substr($markup, $offset);
                break;
            }
            // Skip bare "<!--" inside attribute values (not a block delimiter).
            if (!ToonBlockAttrs::isBlockCommentStart($markup, $start)) {
                $out .= substr($markup, $offset, $start + 4 - $offset);
                $offset = $start + 4;
                continue;
            }
            $out .= substr($markup, $offset, $start - $offset);

            $end = strpos($markup, '-->', $start + 4);
            if ($end === false) {
                $out .= substr($markup, $start);
                break;
            }
            $full = substr($markup, $start, $end + 3 - $start);
            $inner = trim(substr($markup, $start + 4, $end - ($start + 4)));

            // Closer: <!-- /wp:name -->
            if (preg_match('/^\/\s*wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*$/', $inner, $cm) === 1) {
                $name = $cm[1];
                // Insert auto-closers for structural containers above the target.
                while ($stack !== [] && end($stack) !== $name) {
                    $top = (string) end($stack);
                    if (!isset(self::AUTO_CLOSE[$top])) {
                        break;
                    }
                    $out .= "<!-- /wp:{$top} -->";
                    $notes[] = "inserted missing <!-- /wp:{$top} --> before <!-- /wp:{$name} -->";
                    // Models often close the group DIV then jump to /wp:column,
                    // leaving the column's wrapper </div> unwritten.
                    if ($top === 'group' && $name === 'column') {
                        $out .= '</div>';
                        $notes[] = 'inserted missing </div> for column wrapper after auto-closed group';
                    }
                    array_pop($stack);
                }
                if ($stack !== [] && end($stack) === $name) {
                    // Saltline: wp:buttons opens <div class="wp-block-buttons">
                    // then omits </div> before <!-- /wp:buttons -->. Only buttons
                    // always use a div shell (group may be <section> via tagName).
                    if ($name === 'buttons' && !str_ends_with(rtrim($out), '</div>')) {
                        $out .= '</div>';
                        $notes[] = 'inserted missing </div> before <!-- /wp:buttons -->';
                    }
                    array_pop($stack);
                }
                $out .= $full;
                $offset = $end + 3;
                continue;
            }

            // Opener: <!-- wp:name ... --> or void <!-- wp:name /-->
            if (preg_match(
                '/^wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\b(.*)$/s',
                $inner,
                $om
            ) === 1) {
                $name = $om[1];
                $rest = rtrim($om[2]);
                $void = str_ends_with($rest, '/');
                if (!$void) {
                    $stack[] = $name;
                }
            }

            $out .= $full;
            $offset = $end + 3;
        }

        return $out;
    }
}
