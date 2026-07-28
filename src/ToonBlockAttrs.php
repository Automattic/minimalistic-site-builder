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
        // Models sometimes emit HTML-tag-shaped block comments:
        //   <!-- wp:paragraph>
        //   </wp:paragraph -->
        // Normalize those before TOON attr conversion (Fuego signature-drinks).
        $markup = self::repairHtmlShapedBlockComments($markup, $notes);

        $out = '';
        $offset = 0;
        $len = strlen($markup);

        while ($offset < $len) {
            $start = strpos($markup, '<!--', $offset);
            if ($start === false) {
                $out .= substr($markup, $offset);
                break;
            }
            // Only Gutenberg block comments (<!-- wp:… / <!-- /wp:…). Bare
            // "<!--" inside attribute values must stay literal text.
            if (!self::isBlockCommentStart($markup, $start)) {
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
            $commentInner = substr($markup, $start + 4, $end - ($start + 4));
            $fullComment = substr($markup, $start, $end + 3 - $start);

            $converted = self::convertComment($commentInner, $fullComment, $notes, $requireToon);
            $out .= $converted;
            $offset = $end + 3;
        }

        return $out;
    }

    /** Whether $offset points at a Gutenberg block comment opener (`<!-- wp:` / `<!-- /wp:`). */
    public static function isBlockCommentStart(string $markup, int $offset): bool
    {
        if (!str_starts_with(substr($markup, $offset), '<!--')) {
            return false;
        }
        return preg_match('/\A<!--\s*\/?\s*wp:/', substr($markup, $offset, 24)) === 1;
    }

    /**
     * Repair HTML-tag-shaped block delimiters into real Gutenberg comments.
     *
     * @param list<string> $notes
     */
    public static function repairHtmlShapedBlockComments(string $markup, array &$notes = []): string
    {
        // <!-- wp:name>  →  <!-- wp:name -->
        $markup = (string) preg_replace_callback(
            '/<!--\s*(wp:[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*>/i',
            static function (array $m) use (&$notes): string {
                $notes[] = "repaired HTML-shaped opener <!-- {$m[1]} -->";
                return "<!-- {$m[1]} -->";
            },
            $markup,
        );

        // </wp:name -->  →  <!-- /wp:name -->
        $markup = (string) preg_replace_callback(
            '/<\/wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*-->/i',
            static function (array $m) use (&$notes): string {
                $notes[] = "repaired HTML-shaped closer <!-- /wp:{$m[1]} -->";
                return "<!-- /wp:{$m[1]} -->";
            },
            $markup,
        );

        return $markup;
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

        // Garbage left after a broken HTML-shaped comment (e.g. ">\n</wp:paragraph")
        // must not be forced through the TOON decoder.
        if (self::looksLikeHtmlGarbage($rest)) {
            $notes[] = "wp:{$name}: dropped non-TOON garbage attrs, using attr-less opener";
            return $void ? "<!-- wp:{$name} /-->" : "<!-- wp:{$name} -->";
        }

        try {
            $attrs = Toon::decode($rest);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "TOON block attrs for wp:{$name}: " . $e->getMessage()
            );
        }
        // Empty PHP array is both empty object and empty list in our model —
        // treat as attr-less. Non-empty lists are never valid block attrs.
        if (is_array($attrs) && $attrs === []) {
            $notes[] = "wp:{$name}: empty TOON attrs → attr-less opener";
            return $void ? "<!-- wp:{$name} /-->" : "<!-- wp:{$name} -->";
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

    /** Rest text that is clearly not TOON attrs (leftover HTML-shaped noise). */
    private static function looksLikeHtmlGarbage(string $rest): bool
    {
        $trim = ltrim($rest);
        if ($trim === '') {
            return false;
        }
        if ($trim[0] === '>') {
            return true;
        }
        if (str_contains($rest, '</wp:') || str_contains($rest, '</ wp:')) {
            return true;
        }
        return false;
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
