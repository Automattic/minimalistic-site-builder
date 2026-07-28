<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Convert Gutenberg-shaped markup whose block openers carry TOON attributes
 * into standard WordPress block comments with JSON attributes.
 *
 * Intermediate form (model may emit):
 *
 *   <!-- wp:paragraph
 *   align: center
 *   textColor: base
 *   style:
 *     spacing:
 *       margin:
 *         top: "var:preset|spacing|md"
 *   -->
 *   <p>…</p>
 *   <!-- /wp:paragraph -->
 *
 * After expand():
 *
 *   <!-- wp:paragraph {"align":"center","textColor":"base","style":{…}} -->
 *   <p>…</p>
 *   <!-- /wp:paragraph -->
 *
 * Pure PHP — no Node. Openers that already use JSON attrs are left unchanged.
 * Closers and HTML bodies are untouched.
 */
final class ToonBlockAttrs
{
    /**
     * Expand every TOON-attributed block opener in $markup to JSON attrs.
     *
     * @param list<string> $notes out-param: human-readable conversion notes
     */
    public static function expand(string $markup, array &$notes = []): string
    {
        $notes = [];
        $out = '';
        $offset = 0;
        $len = strlen($markup);

        while ($offset < $len) {
            $start = strpos($markup, '<!--', $offset);
            if ($start === false) {
                $out .= substr($markup, $offset);
                break;
            }
            $out .= substr($markup, $offset, $start - $offset);

            $end = strpos($markup, '-->', $start + 4);
            if ($end === false) {
                // Unterminated comment — pass through remainder.
                $out .= substr($markup, $start);
                break;
            }
            $commentInner = substr($markup, $start + 4, $end - ($start + 4));
            $fullComment = substr($markup, $start, $end + 3 - $start);

            $converted = self::convertComment($commentInner, $fullComment, $notes);
            $out .= $converted;
            $offset = $end + 3;
        }

        return $out;
    }

    /**
     * Whether a block-comment body (between <!-- and -->) uses TOON attrs
     * rather than JSON or empty attrs.
     */
    public static function commentHasToonAttrs(string $commentInner): bool
    {
        $body = trim($commentInner);
        if (!preg_match('/^\/?\s*wp:/', $body)) {
            return false;
        }
        // Closers never carry attrs we convert.
        if (preg_match('/^\/\s*wp:/', $body) === 1) {
            return false;
        }
        if (!preg_match(
            '/^wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*(.*)$/s',
            $body,
            $m
        )) {
            return false;
        }
        $rest = rtrim($m[2]);
        // Void slash only.
        if ($rest === '/' || $rest === '') {
            return false;
        }
        if (str_ends_with($rest, '/')) {
            $rest = rtrim(substr($rest, 0, -1));
        }
        $rest = trim($rest);
        if ($rest === '') {
            return false;
        }
        // JSON object attrs.
        if ($rest[0] === '{') {
            return false;
        }
        // Explicit "toon" marker (optional dialect tag).
        if (preg_match('/^toon\b/i', $rest) === 1) {
            return true;
        }
        // Non-JSON remainder → TOON candidate.
        return true;
    }

    /**
     * @param list<string> $notes
     */
    private static function convertComment(string $inner, string $original, array &$notes): string
    {
        $trimmed = trim($inner);

        // Not a block opener.
        if (preg_match('/^\/?\s*wp:/', $trimmed) !== 1) {
            return $original;
        }
        // Closer.
        if (preg_match('/^\/\s*wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*$/', $trimmed, $cm) === 1) {
            return $original;
        }

        if (!preg_match(
            '/^wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*(.*)$/s',
            $trimmed,
            $m
        )) {
            return $original;
        }

        $name = $m[1];
        $rest = $m[2];
        $void = false;
        if (preg_match('/\/\s*$/', $rest) === 1) {
            $void = true;
            $rest = preg_replace('/\/\s*$/', '', $rest) ?? $rest;
        }
        $rest = trim($rest);

        if ($rest === '') {
            return $original;
        }

        // Already JSON.
        if ($rest[0] === '{') {
            $decoded = json_decode($rest, true);
            if (is_array($decoded)) {
                return $original;
            }
            // Invalid JSON that starts with `{` — do not try TOON on it.
            return $original;
        }

        // Optional leading "toon" keyword (case-insensitive).
        if (preg_match('/^toon\b\s*/i', $rest, $tm) === 1) {
            $rest = substr($rest, strlen($tm[0]));
            $rest = trim($rest);
        }

        if ($rest === '') {
            $json = '';
        } else {
            try {
                $attrs = Toon::decode($rest);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "TOON block attrs for wp:{$name}: " . $e->getMessage()
                );
            }
            if (!is_array($attrs) || self::isList($attrs)) {
                throw new \RuntimeException(
                    "TOON block attrs for wp:{$name}: expected an object of attributes"
                );
            }
            $json = self::encodeBlockJson($attrs);
            $notes[] = "wp:{$name}: converted TOON attrs → JSON (" . strlen($rest) . ' → ' . strlen($json) . ' bytes)';
        }

        // Gutenberg void form is ` /-->` (slash inside the comment, no space
        // before the closing -->).
        if ($json === '') {
            return $void ? "<!-- wp:{$name} /-->" : "<!-- wp:{$name} -->";
        }
        return $void
            ? "<!-- wp:{$name} {$json} /-->"
            : "<!-- wp:{$name} {$json} -->";
    }

    /**
     * Gutenberg-style attribute JSON: no escaped slashes/unicode, compact.
     *
     * @param array<string,mixed> $attrs
     */
    public static function encodeBlockJson(array $attrs): string
    {
        $json = json_encode(
            $attrs,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        // WP serialize_block_attributes also escapes -- sequences; keep simple
        // for the prototype (block fixer will re-serialize later if needed).
        return $json;
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }
        $i = 0;
        foreach ($value as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }
}
