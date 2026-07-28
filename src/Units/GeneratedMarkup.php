<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockCloserRepair;
use Automattic\SiteBuild\BlockDocumentRecovery;
use Automattic\SiteBuild\MarkupSalvage;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\ToonBlockAttrs;

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
     * truncation salvage, TOON expand). Callers with a Project route them to
     * warnings.json; every note is also echoed to STDERR for live visibility.
     *
     * Block openers with attributes MUST use TOON (mandatory by default).
     * JSON attrs on `<!-- wp:… -->` openers are rejected when $requireToon is
     * true. TOON is expanded to standard Gutenberg comment JSON before recovery
     * (pure PHP — Toon / ToonBlockAttrs). Attr-less openers remain valid.
     *
     * Pass $requireToon = false only for internal/test fixtures that feed
     * already-JSON Gutenberg markup through this intake (never for model output).
     */
    public static function normalize(string $text, string $key, array &$notes = [], bool $requireToon = true): string
    {
        $record = static function (string $note) use ($key, &$notes): void {
            fwrite(STDERR, "    (part '{$key}': {$note})\n");
            $notes[] = "part '{$key}': {$note}";
        };

        // Sanitize the whole response before looking for a document boundary:
        // block-looking comments inside a script body are not payload.
        $sanitizerNotes = [];
        $text = MarkupSanitizer::sanitize(self::stripFences(trim($text)), $sanitizerNotes);
        foreach ($sanitizerNotes as $note) {
            $record("sanitized script-capable markup — {$note}");
        }

        // TOON → JSON on attributed block openers (JSON forbidden when mandatory).
        $toonNotes = [];
        try {
            $text = ToonBlockAttrs::expand($text, $toonNotes, $requireToon);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("part '{$key}': {$e->getMessage()}");
        }
        foreach ($toonNotes as $note) {
            $record($note);
        }

        // Insert missing structural closers (group/columns/column/buttons) when
        // the model closed an ancestor too early — common after TOON expand.
        $closerNotes = [];
        $text = BlockCloserRepair::repair($text, $closerNotes);
        foreach ($closerNotes as $note) {
            fwrite(STDERR, "    (part '{$key}': {$note})\n");
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
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\r?\n/', '', $text);
            $text = preg_replace('/\r?\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }
}
