<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Convert Gutenberg-shaped markup whose block openers carry TOON attributes
 * into standard WordPress block comments with JSON attributes.
 *
 * Model output form (mandatory for attributed openers):
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
 * After expand() (pipeline-internal, WordPress-native):
 *
 *   <!-- wp:paragraph {"align":"center","textColor":"base","style":{…}} -->
 *
 * Pure PHP — no Node. Closers and HTML bodies are untouched.
 * Attr-less openers (`<!-- wp:paragraph -->`) are allowed.
 * JSON attrs on openers are rejected when $requireToon is true (default).
 */
final class ToonBlockAttrs
{
    /**
     * Expand every TOON-attributed block opener in $markup to JSON attrs.
     *
     * @param list<string> $notes out-param: human-readable conversion notes
     * @param bool $requireToon when true (default), openers with JSON `{…}`
     *        attributes are a hard error — models must emit TOON only.
     */
    public static function expand(string $markup, array &$notes = [], bool $requireToon = true): string
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
                $out .= substr($markup, $start);
                break;
            }
            $commentInner = substr($markup, $start + 4, $end - ($start + 4));
            $fullComment = substr($markup, $start, $end + 3 - $start);

            $converted = self::convertComment($commentInner, $fullComment, $notes, $requireToon);
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
        $parsed = self::parseOpener($commentInner);
        if ($parsed === null) {
            return false;
        }
        $rest = $parsed['rest'];
        if ($rest === '') {
            return false;
        }
        if ($rest[0] === '{') {
            return false;
        }
        return true;
    }

    /**
     * Whether the opener carries forbidden JSON attributes.
     */
    public static function commentHasJsonAttrs(string $commentInner): bool
    {
        $parsed = self::parseOpener($commentInner);
        if ($parsed === null) {
            return false;
        }
        $rest = $parsed['rest'];
        return $rest !== '' && $rest[0] === '{';
    }

    /**
     * @return array{name:string,rest:string,void:bool}|null
     */
    private static function parseOpener(string $commentInner): ?array
    {
        $trimmed = trim($commentInner);
        if (preg_match('/^\/?\s*wp:/', $trimmed) !== 1) {
            return null;
        }
        if (preg_match('/^\/\s*wp:/', $trimmed) === 1) {
            return null;
        }
        if (!preg_match(
            '/^wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*(.*)$/s',
            $trimmed,
            $m
        )) {
            return null;
        }
        $name = $m[1];
        $rest = $m[2];
        $void = false;
        if (preg_match('/\/\s*$/', $rest) === 1) {
            $void = true;
            $rest = preg_replace('/\/\s*$/', '', $rest) ?? $rest;
        }
        $rest = trim($rest);
        // Optional leading "toon" keyword (case-insensitive).
        if ($rest !== '' && preg_match('/^toon\b\s*/i', $rest, $tm) === 1) {
            $rest = trim(substr($rest, strlen($tm[0])));
        }
        return ['name' => $name, 'rest' => $rest, 'void' => $void];
    }

    /**
     * @param list<string> $notes
     */
    private static function convertComment(
        string $inner,
        string $original,
        array &$notes,
        bool $requireToon,
    ): string {
        $parsed = self::parseOpener($inner);
        if ($parsed === null) {
            return $original;
        }

        $name = $parsed['name'];
        $rest = $parsed['rest'];
        $void = $parsed['void'];

        if ($rest === '') {
            return $original;
        }

        // JSON object attrs on the opener.
        if ($rest[0] === '{') {
            if ($requireToon) {
                $snippet = self::snippet($rest, 100);
                throw new \RuntimeException(
                    "wp:{$name}: JSON block attributes are forbidden — emit TOON attrs "
                    . "(multi-line key: value inside the <!-- wp:… --> comment). Got: {$snippet}"
                );
            }
            // Non-mandatory path: leave JSON (valid or recovery-fixture edge cases)
            // for BlockDocumentRecovery / the fixer to handle.
            return $original;
        }

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
        return json_encode(
            $attrs,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private static function snippet(string $text, int $max): string
    {
        $one = (string) preg_replace('/\s+/', ' ', $text);
        return strlen($one) > $max ? substr($one, 0, $max) . '…' : $one;
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
