<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure string scanners over generated block markup, shared by SectionRhythm
 * and LayoutFixer. Both passes must keep working on markup another pass
 * rejects, so nothing here throws, normalizes, or assumes well-formed HTML.
 */
final class MarkupScan
{
    /** The first HTML element immediately following a block opener. */
    public static function wrapperTag(string $markup, int $searchOffset): ?string
    {
        $rest = substr($markup, $searchOffset);
        if (preg_match('/\A\s*<[a-zA-Z][a-zA-Z0-9-]*(?=[\x20\t\r\n\f\/>])/', $rest, $start) !== 1) {
            return null;
        }

        $quote = null;
        $length = strlen($rest);
        for ($i = strlen($start[0]); $i < $length; $i++) {
            $char = $rest[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return substr($rest, 0, $i + 1);
            }
        }
        return null;
    }

    /** @return array{string,int}|null attribute value and its byte offset inside the tag */
    public static function tagAttribute(string $tagHtml, string $name): ?array
    {
        preg_match_all('/[\x20\t\r\n\f]+([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/',
            $tagHtml, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            if (strtolower($match[1][0]) !== strtolower($name)) {
                continue;
            }
            foreach ([2, 3] as $quoted) {
                if (($match[$quoted][1] ?? -1) >= 0) {
                    return $match[$quoted];
                }
            }
            return null;
        }
        return null;
    }

    /** Convert a rendered preset variable back to block-attribute syntax. */
    public static function blockSpacingValue(string $value): string
    {
        return preg_match('/^var\(--wp--preset--spacing--([a-z0-9_-]+)\)$/', $value, $match) === 1
            ? "var:preset|spacing|{$match[1]}"
            : $value;
    }

    /**
     * Declarations of one inline style value, in order with duplicates intact
     * so callers apply their own cascade rules. property is the lowercased,
     * trimmed text before the colon (the whole segment when no colon exists —
     * value is then null); value and segment stay raw.
     *
     * @return list<array{property:string, value:?string, segment:string}>
     */
    public static function parseInlineStyle(string $style): array
    {
        $declarations = [];
        foreach (explode(';', $style) as $segment) {
            $colon = strpos($segment, ':');
            $declarations[] = [
                'property' => strtolower(trim($colon === false ? $segment : substr($segment, 0, $colon))),
                'value' => $colon === false ? null : substr($segment, $colon + 1),
                'segment' => $segment,
            ];
        }
        return $declarations;
    }
}
