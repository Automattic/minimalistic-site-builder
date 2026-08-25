<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Flags design-direction narrative that promises decoration the build has no
 * way to execute.
 *
 * The direction is two things at once: a set of bounded structured fields the
 * pipeline executes literally (palette, type, surface, shape, device, rhythm,
 * density, card style, canvas, image grade, motion), and a prose narrative
 * that every downstream design and section prompt receives verbatim as the
 * authoritative brief.
 *
 * When the narrative promises something outside those fields, no step refuses
 * it and no step delivers it. tbilisi4 committed to "a quiet lattice of
 * hand-drawn ornament borrowed from Kakhetian textile borders … thin zigzag
 * chains, grape-leaf tendrils, and small eight-point rosettes … used sparingly
 * as band separators, list bullets, and a repeating border strip". The build
 * ships exactly three marks (`Device::ALL` minus `none`), placed on at most one
 * non-hero band. What arrived was a single 1px rule, and a page for a
 * traditional Georgian tavern that reads as generic (BIGR-884).
 *
 * `prompts/design-direction.md` already states the rule — "Twine, tape, and
 * illustrated motifs are not devices" — and nothing enforced it. This is the
 * enforcement, at rung 4 of the escalation ladder: the narrative cannot be
 * deterministically rewritten (that needs a model), so the gap is recorded in
 * `warnings.json` and the build continues. A warning here means the delivered
 * site was always going to be plainer than its own brief.
 *
 * Deliberately narrow. Two rules, both requiring an explicit signal:
 *
 * 1. Nouns that only ever name drawn graphic ornament — `filigree`, `rosette`,
 *    `tendril`, `scrollwork`, `arabesque`, `fleuron`, and friends. There is no
 *    innocuous reading of these.
 * 2. A made-by-hand qualifier attached to a decoration noun — "hand-drawn
 *    ornament", "illustrated motif", "hand-painted border".
 *
 * Generic words that carry an innocuous reading far more often than a literal
 * one are NOT matched, because a warning nobody can act on is worse than no
 * warning. Verified against the 24-project demo cohort: `pattern` ("a pattern
 * of alternating bands"), `border` (hearth3's "block borders"), `icon`
 * (atlas3's "icon strokes"), `glyph` (pulso3's "glyph clusters"), and every
 * color metaphor of the "the colour of wet granite" kind are all left alone.
 * Only tbilisi4 trips this.
 *
 * Pure — no I/O, no LLM, unit-testable.
 */
final class DirectionExecutability
{
    /**
     * Nouns that name drawn graphic ornament and nothing else. Matched on
     * their own, with no qualifier required.
     *
     * `ornament` itself is NOT here, and neither are `lattice`, `flourish`,
     * `crest` or `illustration`. Across the demo cohort the bare word almost
     * always appears in a RESTRAINT clause — portfolio2's "no ornament beyond
     * the rule", hearth's "never for body text or ornament", tbilisi3's
     * "Ornament comes from the loom, not from clip art" — which is the
     * direction promising less decoration, exactly what the build delivers.
     * Those reach this class only through a made-by-hand qualifier below.
     */
    private const ORNAMENT_NOUNS = [
        'filigree', 'rosette', 'rosettes', 'tendril', 'tendrils',
        'scrollwork', 'arabesque', 'arabesques', 'fleuron', 'fleurons',
        'curlicue', 'curlicues', 'damask', 'paisley', 'dingbat', 'dingbats',
        'woodcut', 'woodcuts', 'linocut', 'linocuts',
        'latticework', 'trellis', 'interlace', 'fleur-de-lis',
    ];

    /**
     * Made-by-hand qualifiers. On their own these are fine — "hand-blown
     * glass" is a photographic subject, not a page element — so they only
     * count when they qualify one of the nouns below.
     *
     * Bare `drawn` and `etched` are excluded on purpose: "colours drawn from
     * qvevri-earth" and "edges drawn with 1px walnut rules" are the common
     * readings, and the second one describes something the build DOES ship.
     * Only the explicit compounds count.
     */
    private const DRAWN_QUALIFIERS = [
        'hand-drawn', 'hand drawn', 'handdrawn',
        'hand-painted', 'hand painted',
        'hand-lettered', 'hand lettered', 'hand-lettering',
        'hand-inked', 'hand inked',
        'hand-illustrated', 'custom-drawn', 'bespoke',
        'illustrated', 'sketched',
    ];

    /**
     * Decoration nouns that are only a problem when something above says they
     * were drawn by hand. Each has an ordinary innocuous use on its own.
     */
    private const QUALIFIED_NOUNS = [
        'motif', 'motifs', 'ornament', 'ornaments', 'ornamentation',
        'illustration', 'illustrations', 'drawing', 'drawings',
        'pattern', 'patterns', 'lattice', 'flourish', 'flourishes',
        'border', 'borders', 'bullet', 'bullets', 'separator', 'separators',
        'divider', 'dividers', 'rule', 'rules', 'frame', 'frames',
        'icon', 'icons', 'emblem', 'emblems', 'badge', 'badges',
        'mark', 'marks', 'glyph', 'glyphs', 'chain', 'chains',
        'strip', 'strips', 'band', 'bands', 'crest', 'crests',
        'wreath', 'wreaths', 'garland', 'garlands', 'sprig', 'sprigs',
        'vine', 'vines', 'line', 'lines', 'letterform', 'letterforms',
    ];

    /** How far apart a qualifier and its noun may sit, in characters. */
    private const QUALIFIER_REACH = 40;

    /**
     * A direction that RULES OUT decoration is not promising any. These lead
     * the clause far more often than they trail it, so only the text before a
     * match is inspected, within one clause.
     */
    private const RESTRAINT_MARKERS = [
        'no', 'not', 'never', 'without', 'avoid', 'avoids', 'avoiding',
        'free of', 'devoid of', 'rather than', 'instead of', 'nothing',
        'none', 'neither', 'nor', 'sparing', 'sparingly', 'restrained',
        'minus', 'except', 'beyond', 'absent',
    ];

    /** How far back a restraint marker can sit and still govern, in characters. */
    private const RESTRAINT_REACH = 60;

    /**
     * Every unexecutable-decoration promise in a direction's narrative, as
     * actionable warning rows.
     *
     * @param array<string,mixed> $direction the normalized design direction
     * @return list<string>
     */
    public static function problems(array $direction): array
    {
        $description = trim((string) ($direction['description'] ?? ''));
        if ($description === '') {
            return [];
        }

        $found = self::findings($description);
        if ($found === []) {
            return [];
        }

        $device = trim((string) ($direction['device'] ?? Device::DEFAULT));
        $shipped = $device === '' || $device === 'none'
            ? 'the direction committed no device, so the build ships no mark at all'
            : "the build ships one '{$device}' on at most one non-hero band";

        return [
            "file='designDirection.json'; path=\"description\"; authored="
                . Warnings::value(implode('; ', $found))
                . '; delivered=not executed; disposition=the narrative promises drawn ornament that no'
                . ' step can produce — the ornament vocabulary is ' . self::vocabulary() . ', and '
                . $shipped . '. Every downstream design and section prompt receives this narrative as'
                . ' the authoritative brief, so the delivered page is plainer than its own direction.',
        ];
    }

    /**
     * The matched promises, in the order they appear, each quoted with enough
     * surrounding words to locate it in the narrative.
     *
     * @return list<string>
     */
    public static function findings(string $description): array
    {
        $found = [];
        $seen = [];

        foreach (self::ORNAMENT_NOUNS as $noun) {
            if (preg_match('/(?<![\w-])' . preg_quote($noun, '/') . '(?![\w-])/i', $description, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $key = strtolower($noun);
            if (isset($seen[$key]) || self::isRuledOut($description, $m[0][1])) {
                continue;
            }
            $seen[$key] = true;
            $found[$m[0][1]] = self::excerpt($description, $m[0][1], strlen($m[0][0]));
        }

        foreach (self::DRAWN_QUALIFIERS as $qualifier) {
            $offset = 0;
            while (
                preg_match(
                    '/(?<![\w-])' . preg_quote($qualifier, '/') . '(?![\w-])/i',
                    $description,
                    $m,
                    PREG_OFFSET_CAPTURE,
                    $offset,
                ) === 1
            ) {
                $at = $m[0][1];
                $offset = $at + strlen($m[0][0]);
                if (self::isRuledOut($description, $at)) {
                    continue;
                }
                $window = substr($description, $offset, self::QUALIFIER_REACH);
                foreach (self::QUALIFIED_NOUNS as $noun) {
                    if (preg_match('/(?<![\w-])' . preg_quote($noun, '/') . '(?![\w-])/i', $window) !== 1) {
                        continue;
                    }
                    if (!isset($found[$at])) {
                        $found[$at] = self::excerpt($description, $at, strlen($m[0][0]));
                    }
                    break;
                }
            }
        }

        ksort($found);
        return array_values($found);
    }

    /**
     * Whether the clause leading up to a match rules decoration OUT.
     *
     * The lookback stops at the nearest clause boundary before the match, so
     * a restraint in a previous sentence cannot excuse a promise in this one.
     */
    private static function isRuledOut(string $description, int $at): bool
    {
        $start = max(0, $at - self::RESTRAINT_REACH);
        $before = substr($description, $start, $at - $start);
        // Keep only the current clause.
        $boundary = max(
            (int) strrpos(' ' . $before, '.'),
            (int) strrpos(' ' . $before, ';'),
            (int) strrpos(' ' . $before, ':'),
        );
        if ($boundary > 0) {
            $before = substr($before, $boundary);
        }
        foreach (self::RESTRAINT_MARKERS as $marker) {
            if (preg_match('/(?<![\w-])' . preg_quote($marker, '/') . '(?![\w-])/i', $before) === 1) {
                return true;
            }
        }
        return false;
    }

    /** A short quoted excerpt around one match, for the warning row. */
    private static function excerpt(string $description, int $at, int $length): string
    {
        $start = max(0, $at - 30);
        $excerpt = substr($description, $start, $length + 70);
        $excerpt = trim((string) preg_replace('/\s+/u', ' ', $excerpt));
        return ($start > 0 ? '…' : '') . $excerpt . '…';
    }

    /** The marks the build can actually draw, for the warning row. */
    private static function vocabulary(): string
    {
        $marks = array_values(array_filter(
            Device::ALL,
            static fn (string $device): bool => $device !== 'none',
        ));
        return implode(', ', $marks);
    }
}
