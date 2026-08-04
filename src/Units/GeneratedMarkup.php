<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockDocumentRecovery;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\CodeFences;
use Automattic\SiteBuild\MarkupSalvage;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Warnings;

/** Project-free normalization shared by every generated markup unit. */
final class GeneratedMarkup
{
    /**
     * Strip an accidental code fence, require block markup, repair common
     * malformed preset references, strip script-capable markup, and salvage a
     * truncated response back to its last complete block. Every part is
     * untrusted model output headed for templates and stored post content, so
     * this is the one intake it all passes through.
     *
     * $notes receives one "part '<key>': …" line per removal or degradation
     * that changed the delivered content (sanitizer strips, wrapper recovery,
     * truncation salvage). Callers with a Project route them to warnings.json;
     * every note is also narrated for live visibility.
     */
    public static function normalize(string $text, string $key, array &$notes = []): string
    {
        $record = static function (string $note) use ($key, &$notes): void {
            Narrator::write("    (part '{$key}': {$note})\n");
            $notes[] = "part '{$key}': {$note}";
        };

        // Sanitize the whole response before looking for a document boundary:
        // block-looking comments inside a script body are not payload.
        $sanitizerNotes = [];
        $text = MarkupSanitizer::sanitize(CodeFences::strip($text), $sanitizerNotes);
        foreach ($sanitizerNotes as $note) {
            $record("sanitized script-capable markup — {$note}");
        }
        $recoveryNotes = [];
        try {
            $markup = BlockDocumentRecovery::recover($text, $recoveryNotes);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("part '{$key}': {$e->getMessage()}");
        }
        foreach ($recoveryNotes as $note) {
            $record($note);
        }
        $markup = self::normalizePresetRefs(rtrim($markup));

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
            $record($note);
        }

        try {
            BlockDocumentRecovery::assertComplete($salvage['markup']);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("part '{$key}': {$e->getMessage()}");
        }
        return $salvage['markup'];
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
     * Remove a redundant header/footer landmark from a generated template part.
     *
     * The template-part block already supplies the semantic landmark. When the
     * generated part repeats it on any wp:group, WordPress renders nested
     * <header> or <footer> elements. Every matching group is normalized while
     * unrelated attributes, child markup, and unknown tag names stay untouched.
     *
     * A mutation is transactional at the group boundary: its literal wrapper
     * must already be a valid div pair, or both matching landmark tags must be
     * available for rewriting. Malformed or mismatched pairs keep their
     * tagName and source bytes for downstream degradation. Operations are
     * applied back-to-front, and a second pass is byte-for-byte stable.
     */
    public static function withoutRedundantLandmark(string $markup, string $landmark): string
    {
        if (!in_array($landmark, ['header', 'footer'], true)) {
            return $markup;
        }

        $document = BlockMarkup::parse($markup);
        $tag = preg_quote($landmark, '~');
        $openingPattern = '~\A(\s*)<(?<tag>div|' . $tag
            . ')(?=[\s>])(?:[^>"\']+|"[^"]*"|\'[^\']*\')*>~is';
        $closingPattern = '~</(?<tag>div|' . $tag . ')\s*>(\s*)\z~is';
        $operations = [];

        foreach ($document->indices() as $index) {
            if (
                $document->name($index) !== 'group'
                || $document->endOffset($index) === null
            ) {
                continue;
            }

            $attrs = $document->attrs($index);
            if (!is_array($attrs) || ($attrs['tagName'] ?? null) !== $landmark) {
                continue;
            }

            $inner = $document->innerHtml($index);
            if (
                preg_match($openingPattern, $inner, $opening, PREG_OFFSET_CAPTURE) !== 1
                || preg_match($closingPattern, $inner, $closing, PREG_OFFSET_CAPTURE) !== 1
            ) {
                continue;
            }

            $openingTag = strtolower($opening['tag'][0]);
            $closingTag = strtolower($closing['tag'][0]);
            if ($openingTag !== $closingTag) {
                continue;
            }

            unset($attrs['tagName']);
            $operations[] = [
                'start' => $document->openingOffset($index),
                'length' => $document->openingLength($index),
                'replacement' => BlockMarkup::serializeComment('group', $attrs, false),
            ];

            if ($openingTag === 'div') {
                continue;
            }

            $innerStart = $document->openingOffset($index) + $document->openingLength($index);
            $operations[] = [
                'start' => $innerStart + $opening[0][1],
                'length' => strlen($opening[0][0]),
                'replacement' => (string) preg_replace(
                    '~<' . $tag . '(?=[\s>])~i',
                    '<div',
                    $opening[0][0],
                    1
                ),
            ];
            $operations[] = [
                'start' => $innerStart + $closing[0][1],
                'length' => strlen($closing[0][0]),
                'replacement' => (string) preg_replace(
                    '~</' . $tag . '\s*>~i',
                    '</div>',
                    $closing[0][0],
                    1
                ),
            ];
        }

        usort($operations, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($operations as $operation) {
            $markup = substr_replace(
                $markup,
                $operation['replacement'],
                $operation['start'],
                $operation['length']
            );
        }

        return $markup;
    }

    /**
     * Require the template part to rely on its surrounding landmark wrapper.
     *
     * Called after withoutRedundantLandmark(): any matching group attribute or
     * literal element left at that point was not structurally safe to repair,
     * so the owning step can deliver its deterministic chrome fallback rather
     * than ship nested or malformed landmarks.
     */
    public static function assertNoRedundantLandmark(string $markup, string $landmark): void
    {
        if (!in_array($landmark, ['header', 'footer'], true)) {
            throw new \InvalidArgumentException("unsupported template-part landmark '{$landmark}'");
        }

        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            $attrs = $document->attrs($index);
            if (
                $document->name($index) === 'group'
                && is_array($attrs)
                && ($attrs['tagName'] ?? null) === $landmark
            ) {
                throw new \RuntimeException("contains an unrepaired nested {$landmark} landmark");
            }
        }
        if (preg_match('~</?' . preg_quote($landmark, '~') . '(?=[\s>])~i', $markup) === 1) {
            throw new \RuntimeException("contains a literal nested {$landmark} landmark");
        }
    }

    /**
     * A one-page footer's dynamic site-title must not render a link back to
     * the page the visitor is already viewing. Apply the explicit attribute to
     * every generated site-title so a nested lockup cannot evade the rule.
     */
    public static function withoutSiteTitleLinks(string $markup): string
    {
        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'site-title') {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            $attrs['isLink'] = false;
            $document->setAttrs($index, $attrs);
        }
        return $document->render();
    }

    /**
     * Cap footer AI_IMAGE placeholders to non-portrait aspect ratios.
     *
     * A portrait-oriented placeholder — the `portrait` (9:16) or
     * `card-portrait` (3:4) keyword, or any numeric ratio taller than wide —
     * generates an asset that, rendered in a footer column, stretches the
     * whole band and strands blank space beside the short utility rows. The
     * documented `<img alt>` form and any mirrored block-JSON "alt" value are
     * rewritten together so block re-serialization cannot restore the
     * portrait spec. Idempotent: a capped placeholder no longer matches
     * either pattern.
     *
     * @param list<string> $notes appended to once per rewritten placeholder
     */
    public static function withoutPortraitImagePlaceholders(string $markup, array &$notes = []): string
    {
        $patterns = [
            '/(?<prefix>alt\s*=\s*(?<quote>["\'])AI_IMAGE:(?:(?!\k{quote}).)*?\|\s*)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>\s*\k{quote})/is',
            '/(?<prefix>"alt"\s*:\s*"AI_IMAGE:(?:[^"\\\\]|\\\\.)*?\|\s*)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>\s*")/is',
        ];
        foreach ($patterns as $pattern) {
            $markup = (string) preg_replace_callback(
                $pattern,
                static function (array $m) use (&$notes): string {
                    $ratio = strtolower($m['ratio']);
                    if (preg_match('/^(\d+):(\d+)$/', $ratio, $wh) === 1
                        && (int) $wh[1] >= (int) $wh[2]
                    ) {
                        return $m[0]; // numeric square/landscape — already footer-safe
                    }
                    $notes[] = "file='parts/footer.html'; block='AI_IMAGE placeholder'; "
                        . "authored aspect-ratio={$ratio}; delivered=square; "
                        . 'disposition=a portrait-oriented footer image stretches the band and '
                        . 'strands blank space beside the utility rows, so the placeholder was '
                        . 'capped to square';
                    return $m['prefix'] . 'square' . $m['suffix'];
                },
                $markup
            );
        }
        return $markup;
    }

    /**
     * Enforce the concrete surface shared by the footer and closing sections.
     *
     * The comment attribute is the source of truth consumed by downstream
     * layout/contrast passes. Multiple generated roots — or a single root that
     * is not a wp:group — are wrapped without dropping content, and any saved
     * root element is synchronized as well so stale generated classes/styles
     * cannot be sourced back into the block before re-serialization.
     *
     * @param list<string> $notes appended to when opaque authored styling must
     *                            be removed to make the surface enforceable
     */
    public static function withRootBackgroundColor(string $markup, string $color, array &$notes = []): string
    {
        if (preg_match('/^[a-z0-9-]+$/', $color) !== 1) {
            throw new \InvalidArgumentException("invalid root background color slug '{$color}'");
        }

        $document = BlockMarkup::parse($markup);
        $roots = array_values(array_filter(
            $document->indices(),
            static fn (int $index): bool => $document->parent($index) === null
        ));
        if (
            $roots !== []
            && (count($roots) > 1 || $document->name($roots[0]) !== 'group')
        ) {
            $markup = '<!-- wp:group -->' . "\n"
                . '<div class="wp-block-group">' . "\n"
                . $markup . "\n"
                . '</div>' . "\n"
                . '<!-- /wp:group -->';
            $document = BlockMarkup::parse($markup);
            $roots = array_values(array_filter(
                $document->indices(),
                static fn (int $index): bool => $document->parent($index) === null
            ));
        }
        $root = $roots[0] ?? null;
        if ($root === null || $document->name($root) !== 'group') {
            throw new \RuntimeException('footer root is not a wp:group');
        }
        $attrs = $document->attrs($root) ?? [];

        unset($attrs['gradient']);
        if (array_key_exists('style', $attrs) && !is_array($attrs['style'])) {
            self::recordRemovedRootStyle($notes, 'style', $attrs['style'], $color);
            unset($attrs['style']);
        } elseif (is_array($attrs['style'] ?? null)) {
            unset($attrs['style']['background']);
            if (array_key_exists('color', $attrs['style']) && !is_array($attrs['style']['color'])) {
                self::recordRemovedRootStyle($notes, 'style.color', $attrs['style']['color'], $color);
                unset($attrs['style']['color']);
            } elseif (is_array($attrs['style']['color'] ?? null)) {
                unset($attrs['style']['color']['background'], $attrs['style']['color']['gradient']);
                if ($attrs['style']['color'] === []) {
                    unset($attrs['style']['color']);
                }
            }
            foreach (['css', 'variation'] as $opaqueStyle) {
                if (!array_key_exists($opaqueStyle, $attrs['style'])) {
                    continue;
                }
                self::recordRemovedRootStyle(
                    $notes,
                    "style.{$opaqueStyle}",
                    $attrs['style'][$opaqueStyle],
                    $color
                );
                unset($attrs['style'][$opaqueStyle]);
            }
            if ($attrs['style'] === []) {
                unset($attrs['style']);
            }
        }
        if (is_string($attrs['className'] ?? null)) {
            $tokens = self::withoutBackgroundClassTokens($attrs['className']);
            if ($tokens === []) {
                unset($attrs['className']);
            } else {
                $attrs['className'] = implode(' ', $tokens);
            }
        }
        $attrs['backgroundColor'] = $color;
        $document->setAttrs($root, $attrs);
        $markup = $document->render();

        $document = BlockMarkup::parse($markup);
        $root = $document->topLevel();
        if ($root === null || $document->name($root) !== 'group') {
            throw new \RuntimeException('footer root group disappeared during surface repair');
        }
        $ownHtml = $document->ownHtml($root);
        if (preg_match(
            '~\A(?:(?:\s+)|(?:<!--(?:(?!-->).)*-->))*(?<tag><[a-z][a-z0-9:-]*(?=[\s>])'
                . '(?:[^>"\']+|"[^"]*"|\'[^\']*\')*>)~is',
            $ownHtml,
            $opening,
            PREG_OFFSET_CAPTURE
        ) !== 1) {
            $withoutComments = preg_replace('~<!--(?:(?!-->).)*-->~s', '', $ownHtml);
            if (is_string($withoutComments) && trim($withoutComments) === '') {
                // A wrapperless/comment-only model response is still
                // repairable: fix-blocks will serialize the group wrapper from
                // the enforced attribute later.
                return $markup;
            }
            throw new \RuntimeException('footer root has no safe saved HTML wrapper');
        }

        $tag = $opening['tag'][0];
        $classes = self::htmlAttributes($tag, 'class');
        if ($classes !== []) {
            $tokens = self::withoutBackgroundClassTokens($classes[0]['value']);
            $tokens[] = "has-{$color}-background-color";
            $tokens[] = 'has-background';
            $classValue = implode(' ', array_values(array_unique($tokens)));
            for ($index = count($classes) - 1; $index >= 0; $index--) {
                $class = $classes[$index];
                $replacement = $index === 0
                    ? ' class="' . htmlspecialchars(
                        $classValue,
                        ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) . '"'
                    : '';
                $tag = substr_replace(
                    $tag,
                    $replacement,
                    $class['attributeOffset'],
                    strlen($class['attribute'])
                );
            }
        } else {
            $tag = substr_replace(
                $tag,
                ' class="has-' . $color . '-background-color has-background"',
                strrpos($tag, '>'),
                0
            );
        }

        $styles = self::htmlAttributes($tag, 'style');
        for ($index = count($styles) - 1; $index >= 0; $index--) {
            $style = $styles[$index];
            try {
                $styleValue = self::withoutBackgroundStyleDeclarations($style['value']);
            } catch (\RuntimeException) {
                self::recordRemovedRootStyle(
                    $notes,
                    "saved HTML style attribute[{$index}]",
                    $style['value'],
                    $color
                );
                $styleValue = '';
            }
            $replacement = $styleValue === ''
                ? ''
                : substr_replace(
                    $style['attribute'],
                    $styleValue,
                    $style['valueOffset'] - $style['attributeOffset'],
                    strlen($style['value'])
                );
            $tag = substr_replace(
                $tag,
                $replacement,
                $style['attributeOffset'],
                strlen($style['attribute'])
            );
        }

        $tagStart = $document->openingOffset($root)
            + $document->openingLength($root)
            + $opening['tag'][1];
        return substr_replace($markup, $tag, $tagStart, strlen($opening['tag'][0]));
    }

    /**
     * Locate one quoted or unquoted HTML attribute inside an already isolated
     * opening tag.
     *
     * @return list<array{
     *   attribute:string,attributeOffset:int,value:string,valueOffset:int
     * }>
     */
    private static function htmlAttributes(string $tag, string $name): array
    {
        $matches = [];
        foreach (MarkupSanitizer::openingTagAttributes($tag) as $attribute) {
            if (
                $attribute['name'] !== strtolower($name)
                || $attribute['valueStart'] === null
                || $attribute['valueEnd'] === null
            ) {
                continue;
            }
            $valueStart = $attribute['valueStart'];
            $valueEnd = $attribute['valueEnd'];
            $matches[] = [
                'attribute' => substr($tag, $attribute['start'], $attribute['end'] - $attribute['start']),
                'attributeOffset' => $attribute['start'],
                'value' => substr($tag, $valueStart, $valueEnd - $valueStart),
                'valueOffset' => $valueStart,
            ];
        }
        return $matches;
    }

    /** @param list<string> $notes */
    private static function recordRemovedRootStyle(
        array &$notes,
        string $path,
        mixed $authored,
        string $color
    ): void {
        $encoded = Warnings::value($authored);
        $notes[] = "file='parts/footer.html'; block='root wp:group'; authored {$path}={$encoded}; "
            . "delivered=removed; disposition=opaque or malformed root styling removed so the assigned "
            . "'{$color}' footer surface cannot be overridden";
    }

    /** @return list<string> */
    private static function withoutBackgroundClassTokens(string $classes): array
    {
        $classes = html_entity_decode($classes, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return array_values(array_filter(
            preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($classes)) ?: [],
            static fn (string $token): bool => $token !== 'has-background'
                && preg_match('/^has-[a-z0-9-]+-background-color$/', $token) !== 1
                && preg_match('/^has-[a-z0-9-]+-gradient-background$/', $token) !== 1
                && $token !== 'has-background-gradient'
        ));
    }

    /**
     * Remove CSS background declarations without splitting at semicolons in a
     * quoted string, comment, escape, or function argument.
     */
    private static function withoutBackgroundStyleDeclarations(string $style): string
    {
        $declarations = [];
        $start = 0;
        $quote = null;
        $parentheses = 0;
        $inComment = false;
        $length = strlen($style);

        for ($index = 0; $index < $length; $index++) {
            $character = $style[$index];
            if ($inComment) {
                if ($character === '*' && ($style[$index + 1] ?? '') === '/') {
                    $inComment = false;
                    $index++;
                }
                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    if ($index + 1 >= $length) {
                        throw new \RuntimeException('footer root has a malformed inline style escape');
                    }
                    $index++;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '/' && ($style[$index + 1] ?? '') === '*') {
                $inComment = true;
                $index++;
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '\\') {
                if ($index + 1 >= $length) {
                    throw new \RuntimeException('footer root has a malformed inline style escape');
                }
                $index++;
                continue;
            }
            if ($character === '(') {
                $parentheses++;
                continue;
            }
            if ($character === ')') {
                if ($parentheses === 0) {
                    throw new \RuntimeException('footer root has malformed inline style parentheses');
                }
                $parentheses--;
                continue;
            }
            if ($character === ';' && $parentheses === 0) {
                $declarations[] = substr($style, $start, $index - $start);
                $start = $index + 1;
            }
        }
        if ($quote !== null || $inComment || $parentheses !== 0) {
            throw new \RuntimeException('footer root has a malformed inline style');
        }
        $declarations[] = substr($style, $start);

        $kept = [];
        foreach ($declarations as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') {
                continue;
            }
            if (preg_match('/^([-\w]+)\s*:/', $declaration, $property) !== 1) {
                throw new \RuntimeException('footer root has a malformed inline style declaration');
            }
            if (str_starts_with(strtolower($property[1]), 'background')) {
                continue;
            }
            $kept[] = $declaration;
        }
        return implode(';', $kept);
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

}
