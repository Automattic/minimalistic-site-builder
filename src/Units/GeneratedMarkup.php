<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\MarkupSalvage;
use Automattic\SiteBuild\MarkupSanitizer;

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
        try {
            $markup = self::recoverDocument(self::stripFences(trim($text)));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("part '{$key}': {$e->getMessage()}");
        }
        if ($markup === '' || !str_contains($markup, 'wp:')) {
            throw new \RuntimeException("part '{$key}' is not block markup");
        }
        $markup = MarkupSanitizer::sanitize(self::normalizePresetRefs(rtrim($markup)));

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

        // Validate the final artifact strictly: everything must live inside
        // its top-level blocks. Recovery and salvage guarantee this for the
        // shapes they read; anything else (e.g. prose wedged between two
        // top-level blocks) fails HERE with an explicit message instead of
        // much later at the section-rhythm gate.
        self::assertNoContentOutsideBlocks($salvage['markup'], $key);
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
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = preg_replace('/\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }

    /**
     * Recover the one block document from a model response that wrapped it —
     * a sentence of reasoning before the markup ("Looking at the notes… Let
     * me build the contrast band…"), a leftover code fence or prose after it,
     * a delimiter *quoted inside the prose* ("I'll use `<!-- wp:group -->` as
     * the root."), or an ordinary trailing HTML comment. Both wrappers
     * survive fence-stripping — which only fires when the text *starts* with
     * a fence — and would leave the part with content outside its root block,
     * failing the whole build much later at the section-rhythm gate.
     *
     * The recovery is structural, not textual: the candidate is a complete,
     * balanced block document found by parsing (see balancedCandidate), never
     * "the first opener-shaped substring to the last `-->`" — so an ordinary
     * trailing HTML comment falls outside the recovered span. Two plausible
     * documents are an explicit ambiguity error, and a truncated container
     * wrapper keeps its whole tail for MarkupSalvage rather than having a
     * complete nested child promoted to document root.
     *
     * Text with no block delimiter at all is returned untouched for the
     * caller's "not block markup" guard to reject.
     */
    private static function recoverDocument(string $text): string
    {
        $full = BlockMarkup::parse($text);
        if ($full->indices() === []) {
            return $text;
        }

        // The end of the last block delimiter in the text: a candidate must
        // reach it, else it would silently discard trailing block content.
        $lastDelimiterEnd = 0;
        foreach ($full->indices() as $i) {
            $lastDelimiterEnd = max(
                $lastDelimiterEnd,
                $full->endOffset($i) ?? $full->openingOffset($i) + $full->openingLength($i),
            );
        }

        $candidate = self::balancedCandidate($text, $full, $lastDelimiterEnd);

        if ($candidate !== null) {
            [$start, $end] = $candidate;
            // Every opener before the candidate, at any depth: the tolerant
            // parse nests the real payload under a quoted opener, so a
            // truncated wrapper is not necessarily top-level.
            foreach ($full->indices() as $i) {
                if ($full->openingOffset($i) >= $start || self::isDecorativeOpener($full, $i)) {
                    continue;
                }
                if ($full->endOffset($i) !== null) {
                    // A complete block before the candidate: two plausible
                    // documents. Refuse to guess which one is meant.
                    throw new \RuntimeException(
                        'contains multiple candidate block documents; cannot recover one unambiguously'
                    );
                }
                // A truncated container wrapper: keep the whole tail for
                // salvage — never promote its complete child to document root.
                $candidate = null;
                break;
            }
            if ($candidate !== null) {
                return trim(substr($text, $start, $end - $start));
            }
        }

        // No balanced candidate (or a truncated wrapper to preserve): strip
        // the preamble down to the first anchor-worthy opener and keep the
        // tail for MarkupSalvage. Decorative openers cannot anchor.
        foreach ($full->indices() as $i) {
            if (!self::isDecorativeOpener($full, $i)) {
                return trim(substr($text, $full->openingOffset($i)));
            }
        }
        return trim(substr($text, $full->openingOffset(0)));
    }

    /**
     * Whether an opener is a block delimiter quoted in the wrapper prose
     * rather than a real block: it never got a closer AND its own HTML builds
     * no container. A genuine static-save container block cut off by
     * truncation leaves its root tag open until its closer, so it fails the
     * second test and keeps anchoring the document.
     */
    private static function isDecorativeOpener(BlockMarkup $full, int $i): bool
    {
        return $full->endOffset($i) === null
            && MarkupSalvage::openElements($full->ownHtml($i)) === [];
    }

    /**
     * The earliest opener whose suffix parses as one balanced block document
     * covering the text through its last block delimiter, as an absolute
     * [start, end) span — or null when no opener does.
     *
     * @return array{0:int,1:int}|null
     */
    private static function balancedCandidate(string $text, BlockMarkup $full, int $lastDelimiterEnd): ?array
    {
        foreach ($full->indices() as $i) {
            if ($full->endOffset($i) === null) {
                continue; // unclosed in the full parse ⇒ unclosed in its own suffix too
            }
            $start = $full->openingOffset($i);
            $end = self::documentEnd(substr($text, $start));
            if ($end === null || $start + $end < $lastDelimiterEnd) {
                continue;
            }
            return [$start, $start + $end];
        }
        return null;
    }

    /**
     * The end offset of $suffix's block content when it parses as ONE
     * balanced document — every block closed, delimiters matched, nothing
     * but whitespace between its top-level blocks — or null when it doesn't.
     */
    private static function documentEnd(string $suffix): ?int
    {
        $p = BlockMarkup::parse($suffix);
        if ($p->indices() === [] || $p->unclosedIndices() !== [] || $p->hasMismatchedDelimiters()) {
            return null;
        }
        $prevEnd = null;
        foreach ($p->indices() as $j) {
            if ($p->parent($j) !== null) {
                continue;
            }
            if ($prevEnd !== null && trim(substr($suffix, $prevEnd, $p->openingOffset($j) - $prevEnd)) !== '') {
                return null; // prose between top-level blocks — not one document
            }
            $prevEnd = $p->endOffset($j);
        }
        return $prevEnd;
    }

    /**
     * Fail loudly when the final normalized artifact still has content
     * outside its top-level blocks — the exact defect the downstream gates
     * reject late, surfaced here with the part identified.
     */
    private static function assertNoContentOutsideBlocks(string $markup, string $key): void
    {
        $doc = BlockMarkup::parse($markup);
        $outside = '';
        $pos = 0;
        foreach ($doc->indices() as $i) {
            if ($doc->parent($i) !== null) {
                continue;
            }
            $outside .= substr($markup, $pos, $doc->openingOffset($i) - $pos);
            $pos = $doc->endOffset($i) ?? strlen($markup);
        }
        $outside .= substr($markup, $pos);
        if (trim($outside) !== '') {
            throw new \RuntimeException("part '{$key}' has content outside its top-level blocks after recovery");
        }
    }
}
