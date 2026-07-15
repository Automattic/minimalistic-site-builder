<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\MarkupSanitizer;

/** Project-free normalization shared by every generated markup unit. */
final class GeneratedMarkup
{
    /**
     * Strip an accidental code fence, require block markup, repair common
     * malformed preset references, and strip script-capable markup. Every
     * part is untrusted model output headed for templates and stored post
     * content, so this is the one intake it all passes through.
     */
    public static function normalize(string $text, string $key): string
    {
        $markup = self::stripFences(trim($text));
        if ($markup === '' || !str_contains($markup, 'wp:')) {
            throw new \RuntimeException("part '{$key}' is not block markup");
        }
        return MarkupSanitizer::sanitize(self::normalizePresetRefs(rtrim($markup)));
    }

    /**
     * Ensure a header/footer top-level wp:group declares a constrained layout.
     * An explicit layout is preserved.
     */
    public static function constrainedPart(string $markup): string
    {
        if (preg_match('/^<!--\s*wp:group\s*(\{.*?\})?\s*-->/s', $markup, $m) !== 1) {
            return $markup;
        }
        $attrs = isset($m[1]) && $m[1] !== '' ? json_decode($m[1], true) : [];
        if (!is_array($attrs) || isset($attrs['layout'])) {
            return $markup;
        }
        $attrs['layout'] = ['type' => 'constrained'];
        $json = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return '<!-- wp:group ' . $json . ' -->' . substr($markup, strlen($m[0]));
    }

    /**
     * Canonicalize preset references to `var:preset|type|slug` in block markup.
     * Models commonly use CSS-style `--` or colon delimiters, sometimes in only
     * one of the two positions. Gutenberg's comment serializer can additionally
     * spell `--` as `\u002d\u002d`. WordPress resolves only the pipe form, so
     * any of those malformed refs produces no style.
     *
     * Match complete prefixes for the fixed preset-type vocabulary instead of
     * replacing dashes globally. This keeps CSS custom properties such as
     * `var(--wp--preset--spacing--xl)` byte-for-byte intact. Pure — testable.
     */
    public static function normalizePresetRefs(string $markup): string
    {
        $types = 'color|gradient|shadow|spacing|font-size|font-family|aspect-ratio|duotone';

        // Each delimiter position independently accepts the pipe, the two
        // model typos (`--` and `:`), and the serializer-escaped spellings
        // `\u002d\u002d` (dash-dash) and `\u007c` (pipe) in either hex case,
        // since JSON permits both. Type names stay case-sensitive.
        $delimiter = '(?:\||:|--|(?:\\\\u002[dD]){2}|\\\\u007[cC])';

        return (string) preg_replace(
            "/var:preset{$delimiter}({$types}){$delimiter}/",
            'var:preset|$1|',
            $markup
        );
    }

    private static function stripFences(string $text): string
    {
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = preg_replace('/\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }
}
