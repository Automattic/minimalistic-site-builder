<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockDocumentRecovery;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\CodeFences;
use Automattic\SiteBuild\MarkupSalvage;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Narrator;

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
