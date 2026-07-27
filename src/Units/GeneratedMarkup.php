<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\MarkupSalvage;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\PresetReferences;

/** Project-free normalization shared by every generated markup unit. */
final class GeneratedMarkup
{
    /**
     * Strip an accidental code fence, require block markup, repair common
     * malformed preset references, strip script-capable markup, and salvage a
     * truncated response back to its last complete block. Every part is
     * untrusted model output headed for templates and stored post content, so
     * this is the one intake it all passes through.
     */
    public static function normalize(string $text, string $key): string
    {
        $markup = self::stripFences(trim($text));
        if ($markup === '' || !str_contains($markup, 'wp:')) {
            throw new \RuntimeException("part '{$key}' is not block markup");
        }
        $markup = self::normalizePresetRefs(rtrim($markup));
        $markup = self::normalizeSpacingPresetCssXs($markup);
        $markup = MarkupSanitizer::sanitize(self::normalizeBlockAttributes($markup));

        // A response cut off by the output-token ceiling (or otherwise left
        // structurally unclosed) is trimmed to its last complete block rather
        // than accepted broken — it would only fail the build much later, at
        // the section-rhythm root-group gate, after every other part was paid
        // for.
        try {
            $salvage = MarkupSalvage::repair($markup);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("part '{$key}': {$e->getMessage()}");
        }
        foreach ($salvage['notes'] as $note) {
            fwrite(STDERR, "    (part '{$key}': {$note})\n");
        }
        return $salvage['markup'];
    }

    /**
     * Assert the semantic contracts enforced later by fix-blocks and final
     * theme validation, while returning the normalized input bytes unchanged.
     *
     * Serializer::transform() is deliberately validation-only here. Its saved
     * output can remove intake-only evidence such as an AI_IMAGE alt on a cover
     * background; CollectImagesStep must see that evidence before fix-blocks
     * performs the real re-serialization.
     *
     * @param string|array<mixed> $themeJson
     */
    public static function validate(string $markup, string $key, string|array $themeJson): string
    {
        $theme = self::decodedTheme($themeJson, $key);
        $validationMarkup = $markup;
        $problems = [];

        try {
            // Validate preset references against the ephemeral saved form.
            // Intake attribute repairs intentionally leave authored HTML stale
            // for fix-blocks to re-sync (for example xs -> sm in a comment
            // while its old inline CSS still says xs), so scanning $markup
            // itself would reject bytes the normal pipeline deterministically
            // replaces. The returned value below remains the intake markup.
            $validationMarkup = self::serializer()->transform($markup)->html;
        } catch (\Throwable $error) {
            $problems[] = "{$key}: block compatibility failed: {$error->getMessage()}";
        }
        array_push(
            $problems,
            ...PresetReferences::problemsForMarkup($validationMarkup, $theme, $key)
        );

        if ($problems !== []) {
            throw new \RuntimeException(
                "part '{$key}' failed semantic validation:\n- " . implode("\n- ", $problems)
            );
        }

        return $markup;
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

    /**
     * Repair a deliberately narrow set of recurring model mistakes in parsed
     * block attributes. BlockMarkup rewrites only comments whose decoded
     * attributes actually change; all other comments and saved HTML retain
     * their original bytes for the later block-fixer pass.
     */
    public static function normalizeBlockAttributes(string $markup): string
    {
        $blocks = BlockMarkup::parse($markup);

        foreach ($blocks->indices() as $index) {
            $attrs = $blocks->attrs($index);
            if ($attrs === null) {
                continue;
            }
            $normalized = self::normalizeSpacingPresetXs($attrs);
            $isCoreGroup = in_array($blocks->name($index), ['group', 'core/group'], true);

            // A missing root-object closer can leave otherwise valid group
            // layout and border siblings one level too deep. Only hoist into
            // an absent destination: competing authored values are ambiguous
            // and must remain visible to fail-closed semantic validation.
            $style = $normalized['style'] ?? null;
            if ($isCoreGroup && is_array($style)) {
                if (
                    !array_key_exists('layout', $normalized)
                    && is_array($style['layout'] ?? null)
                ) {
                    $normalized['layout'] = $style['layout'];
                    unset($normalized['style']['layout']);
                }

                $spacing = $normalized['style']['spacing'] ?? null;
                if (
                    !array_key_exists('border', $normalized['style'])
                    && is_array($spacing)
                    && is_array($spacing['border'] ?? null)
                ) {
                    $normalized['style']['border'] = $spacing['border'];
                    unset($normalized['style']['spacing']['border']);
                }
            }

            $backgroundImage = $normalized['style']['background']['backgroundImage'] ?? null;
            if (is_array($backgroundImage) && array_key_exists('source', $backgroundImage)) {
                unset($normalized['style']['background']['backgroundImage']['source']);
            }

            $layout = $normalized['layout'] ?? null;
            if (is_array($layout)) {
                // Match LayoutFixer's established lossless CSS-to-Gutenberg
                // aliases before the stricter serializer gate runs. Intake
                // validation precedes NormalizeLayoutStep, so leaving these
                // common model spellings for that later pass would trigger an
                // unnecessary LLM repair (and could abort an otherwise
                // deterministically repairable batch).
                $justifyContent = $layout['justifyContent'] ?? null;
                if (
                    ($layout['type'] ?? null) === 'flex'
                    && in_array($justifyContent, ['flex-start', 'flex-end'], true)
                ) {
                    $normalized['layout']['justifyContent'] = $justifyContent === 'flex-start'
                        ? 'left'
                        : 'right';
                }
                if (
                    $isCoreGroup
                    && ($layout['justifyContent'] ?? null) === 'stretch'
                ) {
                    unset($normalized['layout']['justifyContent']);
                }
                if (($layout['verticalAlignment'] ?? null) === 'space-between') {
                    unset($normalized['layout']['verticalAlignment']);
                }
            }

            if ($normalized !== $attrs) {
                $blocks->setAttrs($index, $normalized);
            }
        }

        return $blocks->render();
    }

    /**
     * ThemeJsonStep always replaces the model's spacing scale with the
     * builder's canonical profile, whose smallest (and only small) slug is
     * `sm`. Treat the model's recurring `xs` spelling as that canonical alias.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function normalizeSpacingPresetXs(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalizeSpacingPresetXs($item);
            } elseif ($item === 'var:preset|spacing|xs') {
                $value[$key] = 'var:preset|spacing|sm';
            }
        }
        return $value;
    }

    /**
     * Apply the same canonical xs -> sm alias to CSS preset variables in
     * actual style attributes and style elements. Gutenberg normally
     * re-serializes these from the comment attributes, but some valid
     * paragraph compatibility paths retain the authored inline declaration,
     * so leaving xs there would still fail the final preset-reference gate.
     * Scope the rewrite to CSS contexts so user-facing prose or code examples
     * that mention the literal variable remain untouched.
     */
    private static function normalizeSpacingPresetCssXs(string $markup): string
    {
        $replace = static fn (string $css): string => str_replace(
            'var(--wp--preset--spacing--xs)',
            'var(--wp--preset--spacing--sm)',
            $css,
        );

        $edits = [];
        $offset = 0;
        while (($start = strpos($markup, '<', $offset)) !== false) {
            // Block/decorative comments may contain arbitrary quoted text.
            // Skip their full bytes so examples inside a comment cannot be
            // mistaken for rendered HTML attributes.
            if (substr($markup, $start, 4) === '<!--') {
                $commentEnd = strpos($markup, '-->', $start + 4);
                $offset = $commentEnd === false ? strlen($markup) : $commentEnd + 3;
                continue;
            }

            $rest = substr($markup, $start);
            if (
                preg_match(
                    '/\A<([a-z][a-z0-9:-]*)(?=[\x20\t\r\n\f\/>])/i',
                    $rest,
                    $opening,
                ) !== 1
            ) {
                $offset = $start + 1;
                continue;
            }

            $end = self::htmlTagEnd($markup, $start);
            if ($end === null) {
                break;
            }
            $tag = substr($markup, $start, $end - $start + 1);
            $normalizedTag = self::normalizeStyleAttributeCssXs($tag, $replace);
            if ($normalizedTag !== $tag) {
                $edits[] = [$start, strlen($tag), $normalizedTag];
            }

            $offset = $end + 1;
            if (strtolower($opening[1]) !== 'style') {
                continue;
            }

            if (
                preg_match(
                    '~</style\s*>~i',
                    $markup,
                    $closing,
                    PREG_OFFSET_CAPTURE,
                    $offset,
                ) !== 1
            ) {
                continue;
            }
            $bodyEnd = $closing[0][1];
            $body = substr($markup, $offset, $bodyEnd - $offset);
            $normalizedBody = $replace($body);
            if ($normalizedBody !== $body) {
                $edits[] = [$offset, strlen($body), $normalizedBody];
            }
            $offset = $bodyEnd + strlen($closing[0][0]);
        }

        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $length, $replacement]) {
            $markup = substr_replace($markup, $replacement, $start, $length);
        }
        return $markup;
    }

    /**
     * End byte of an opening HTML tag, respecting `>` inside quoted values.
     */
    private static function htmlTagEnd(string $markup, int $start): ?int
    {
        $quote = null;
        $length = strlen($markup);
        for ($i = $start + 1; $i < $length; $i++) {
            $char = $markup[$i];
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
                return $i;
            }
        }
        return null;
    }

    /**
     * Rewrite only a real, whitespace-delimited style attribute. The parser
     * advances over each quoted value as a unit, so `data-style` and text such
     * as `aria-label="Literal style='…'"` cannot be mistaken for CSS.
     *
     * @param callable(string):string $replace
     */
    private static function normalizeStyleAttributeCssXs(string $tag, callable $replace): string
    {
        $edits = [];
        $length = strlen($tag);
        $i = 1;

        // Skip the element name.
        while ($i < $length && !str_contains(" \t\r\n\f/>", $tag[$i])) {
            $i++;
        }

        while ($i < $length) {
            while ($i < $length && str_contains(" \t\r\n\f", $tag[$i])) {
                $i++;
            }
            if ($i >= $length || $tag[$i] === '>' || $tag[$i] === '/') {
                break;
            }

            $nameStart = $i;
            while ($i < $length && !str_contains(" \t\r\n\f=/>", $tag[$i])) {
                $i++;
            }
            if ($i === $nameStart) {
                $i++;
                continue;
            }
            $name = substr($tag, $nameStart, $i - $nameStart);

            while ($i < $length && str_contains(" \t\r\n\f", $tag[$i])) {
                $i++;
            }
            if ($i >= $length || $tag[$i] !== '=') {
                continue;
            }
            $i++;
            while ($i < $length && str_contains(" \t\r\n\f", $tag[$i])) {
                $i++;
            }
            if ($i >= $length) {
                break;
            }

            $quote = in_array($tag[$i], ['"', "'"], true) ? $tag[$i] : null;
            $valueStart = $quote === null ? $i : ++$i;
            if ($quote === null) {
                while ($i < $length && !str_contains(" \t\r\n\f>", $tag[$i])) {
                    $i++;
                }
                $valueEnd = $i;
            } else {
                while ($i < $length && $tag[$i] !== $quote) {
                    $i++;
                }
                $valueEnd = $i;
                if ($i < $length) {
                    $i++;
                }
            }

            if (strcasecmp($name, 'style') === 0) {
                $value = substr($tag, $valueStart, $valueEnd - $valueStart);
                $normalized = $replace($value);
                if ($normalized !== $value) {
                    $edits[] = [$valueStart, strlen($value), $normalized];
                }
            }
        }

        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $editLength, $replacement]) {
            $tag = substr_replace($tag, $replacement, $start, $editLength);
        }
        return $tag;
    }

    /** @param string|array<mixed> $themeJson @return array<mixed> */
    private static function decodedTheme(string|array $themeJson, string $key): array
    {
        if (is_array($themeJson)) {
            return $themeJson;
        }
        $decoded = json_decode($themeJson, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException("part '{$key}' received invalid theme_json");
        }
        return $decoded;
    }

    private static function serializer(): Serializer
    {
        // Loading and certifying the frozen registry is comparatively costly;
        // Serializer is stateless across transform() calls, so one process-local
        // instance safely validates every member of a generated batch.
        static $serializer = null;
        return $serializer ??= new Serializer();
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
