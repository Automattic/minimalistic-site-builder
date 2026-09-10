<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The block mechanics every host placeholder contract shares.
 *
 * A placeholder is a paragraph block carrying a known class, whose only text
 * is a spec opening with a known marker. Locating one, counting the markers
 * that are not one, and deleting those is the same work whether the spec
 * behind the marker describes a form or a map. Only the grammar differs, and
 * that stays in the contract classes.
 *
 * It lives in one place for the same reason the grammars do: the library and
 * the host both locate these blocks, and a pattern that drifts between them
 * fails silently — the spec reaches the visitor as grey body copy with
 * nothing else in the pipeline noticing.
 *
 * @see FormPlaceholder
 * @see MapPlaceholder
 */
final class HostPlaceholder
{
    /**
     * Every placeholder block of one kind, whole block and spec text.
     *
     * @param string $markerName The bare marker, e.g. `JP_FORM`.
     * @param string $className  The class the host locates the block by.
     * @return list<array{block:string, spec:string}>
     */
    public static function find(string $markup, string $markerName, string $className): array
    {
        $pattern = '/<!--\s*wp:paragraph\b[^>]*?-->\s*<p[^>]*class="[^"]*\b'
            . preg_quote($className, '/')
            . '\b[^"]*"[^>]*>(.*?)<\/p>\s*<!--\s*\/wp:paragraph\s*-->/is';
        if (preg_match_all($pattern, $markup, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        $found = [];
        foreach ($matches as $match) {
            $spec = trim(PlainText::fromMarkup($match[1]));
            if (str_starts_with($spec, $markerName . ':')) {
                $found[] = ['block' => $match[0], 'spec' => $spec];
            }
        }

        return $found;
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
        $placeholders = [];
        foreach (self::find($markup, $markerName, $className) as $found) {
            $placeholders[$found['block']] = true;
        }

        $pattern = '/<!--\s*wp:paragraph\b[^>]*?-->\s*<p[^>]*>.*?<\/p>\s*<!--\s*\/wp:paragraph\s*-->/is';
        $removed = 0;
        $stripped = preg_replace_callback(
            $pattern,
            static function (array $match) use ($placeholders, $markerName, &$removed): string {
                if (isset($placeholders[$match[0]]) || !str_contains($match[0], $markerName)) {
                    return $match[0];
                }
                ++$removed;
                return '';
            },
            $markup,
        );

        return [
            'markup' => $removed > 0 ? trim((string) $stripped) : $markup,
            'removed' => $removed,
        ];
    }
}
