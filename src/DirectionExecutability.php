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
 * ## How it decides
 *
 * The unit of judgement is ONE SENTENCE. A promise and the words that qualify
 * it live in the same sentence; a restraint in a different sentence has no
 * bearing on it. Within a sentence, a promise is present when either:
 *
 * 1. a noun that only ever names drawn graphic ornament appears
 *    (`filigree`, `rosette`, `knotwork`, `fret`, `roundel`, …), or
 * 2. a decoration noun with an ordinary innocuous use (`motif`, `ornament`,
 *    `illustration`, `silhouette`) appears AND the sentence either says it was
 *    made by hand (`hand-drawn`, `illustrated`) or places it on the page
 *    (`used as`, `separates`, `runs along`, `rendered as`).
 *
 * …and the sentence does not NEGATE it.
 *
 * ## Negation, not hedging
 *
 * Only true negation suppresses — `no`, `never`, `without`, `rather than`,
 * `instead of`. Hedges do not: `sparingly`, `restrained`, `at most`, `only`,
 * `quiet`, `subtle` are exactly how a model softens a promise it is still
 * making. tbilisi4's own text is "used **sparingly** as band separators", which
 * is a promise, and an earlier version of this class suppressed on `sparingly`
 * — it caught tbilisi4 only because that word happened to fall after the noun
 * rather than before it. That was luck, not design.
 *
 * ## What is deliberately NOT a decoration noun
 *
 * `rule`, `band`, `line`, `border`, `frame`, `mark`, `divider`, `separator`,
 * `strip`, `pattern`, `icon`, `glyph`. Every one of them names something the
 * build genuinely ships — a hairline rule, an alternating band, a framed card,
 * a page frame — so matching them turns real deliveries into warnings. Per the
 * escalation ladder's rung 2, `warnings.json` is only useful when every row is
 * actionable.
 *
 * ## Known limits
 *
 * Recall is partial and this class does not pretend otherwise. It reads words,
 * not intent, so a narrative that describes custom artwork without ever naming
 * it as ornament — "zigzag chevrons, stepped diamonds and tiny hooked crosses"
 * on its own — passes. The prompt change that stops the promise being made in
 * the first place is the actual fix; this is the visibility net under it.
 *
 * Pure — no I/O, no LLM, unit-testable.
 */
final class DirectionExecutability
{
    /**
     * Nouns that only ever name drawn graphic ornament. A sentence containing
     * one is a promise on its own, with no qualifier needed.
     */
    private const ORNAMENT_NOUNS = [
        'filigree', 'rosette', 'rosettes', 'tendril', 'tendrils',
        'scrollwork', 'arabesque', 'arabesques', 'fleuron', 'fleurons',
        'curlicue', 'curlicues', 'damask', 'paisley', 'dingbat', 'dingbats',
        'woodcut', 'woodcuts', 'linocut', 'linocuts', 'latticework',
        'trellis', 'interlace', 'knotwork', 'fret', 'frets', 'fretwork',
        'roundel', 'roundels', 'medallion', 'medallions',
        'cartouche', 'cartouches', 'fleur-de-lis', 'filagree',
    ];

    /**
     * Decoration nouns with an ordinary innocuous use. They are a promise only
     * when the same sentence says the thing was made by hand, or places it on
     * the page.
     */
    private const DECORATION_NOUNS = [
        'ornament', 'ornaments', 'ornamentation', 'ornamental',
        'motif', 'motifs', 'illustration', 'illustrations',
        'silhouette', 'silhouettes', 'emblem', 'emblems', 'insignia',
        'monogram', 'monograms', 'crest', 'crests', 'heraldry',
        'flourish', 'flourishes', 'lattice', 'wreath', 'wreaths',
        'garland', 'garlands', 'vignette', 'vignettes', 'seal', 'seals',
        'drawing', 'drawings', 'doodle', 'doodles',
    ];

    /** Says the thing was made by hand rather than composed from the kit. */
    private const DRAWN_QUALIFIERS = [
        'hand-drawn', 'hand drawn', 'handdrawn', 'hand-painted', 'hand painted',
        'hand-lettered', 'hand lettered', 'hand-lettering', 'hand-inked',
        'hand inked', 'hand-illustrated', 'hand-cut', 'hand cut',
        'custom-drawn', 'bespoke', 'illustrated', 'sketched', 'engraved',
        'vector outline', 'vector outlines', 'line art', 'line-art',
    ];

    /**
     * Says the decoration is PLACED on the page — the difference between
     * naming a visual tradition and committing to render something.
     *
     * Bare common verbs (`above`, `carries`, `sits`, `marks`, `frames`) are
     * deliberately absent. These sentences run 200+ characters, so a word that
     * ordinary prose uses anywhere is true of nearly every sentence: `above`
     * matched tbilisi6's "Ornament is restrained and earned: thin double rules
     * … above section titles", which promises only rules and labels — both
     * things the build ships.
     */
    private const PLACEMENT_PHRASES = [
        'used as', 'used only as', 'used sparingly as', 'reserved for',
        'appears', 'appear', 'rendered as', 'rendered in', 'set as',
        'separates', 'separating', 'divides', 'dividing',
        'runs along', 'runs across', 'runs between',
        'section divider', 'band separator', 'list bullet', 'per screen',
        'between major', 'between sections',
    ];

    /**
     * How near a qualifier or placement phrase must sit to the noun it
     * governs, in bytes. Anywhere-in-the-sentence was too loose once the
     * sentence is a 300-character clause chain.
     */
    private const GOVERNS_REACH = 60;

    /**
     * True negation only. Hedges (`sparingly`, `restrained`, `only`,
     * `at most`, `quiet`, `subtle`) are deliberately absent — see the class
     * docblock. A marker suppresses only when it governs the noun, which is
     * approximated as "appears before it in the same sentence".
     */
    private const NEGATION_MARKERS = [
        'no', 'not', 'never', 'without', 'nothing', 'none',
        'neither', 'nor', 'free of', 'devoid of', 'rather than',
        'instead of', 'avoid', 'avoids', 'avoiding', 'absent', 'lacks',
    ];

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
                . Warnings::value(implode(' | ', $found))
                . '; delivered=not executed; disposition=the narrative promises drawn ornament that no'
                . ' step can produce — the ornament vocabulary is ' . self::vocabulary() . ', and '
                . $shipped . '. Every downstream design and section prompt receives this narrative as'
                . ' the authoritative brief, so the delivered page is plainer than its own direction.',
        ];
    }

    /**
     * The offending sentences, in order, one entry each.
     *
     * One sentence is one promise however many ornament words it contains, so
     * the evidence that reaches `warnings.json` is the promise itself rather
     * than three overlapping windows onto the same clause.
     *
     * @return list<string>
     */
    public static function findings(string $description): array
    {
        $found = [];
        foreach (self::sentences($description) as $sentence) {
            $reason = self::promiseIn($sentence);
            if ($reason !== null) {
                $found[] = self::clip($sentence);
            }
        }
        return $found;
    }

    /**
     * Why this sentence is a promise, or null when it is not.
     *
     * Returns the matched word so a caller (and a test) can tell WHICH rule
     * fired rather than only that one did.
     */
    public static function promiseIn(string $sentence): ?string
    {
        foreach (self::ORNAMENT_NOUNS as $noun) {
            $at = self::wordPosition($sentence, $noun);
            if ($at !== null && !self::negatedBefore($sentence, $at)) {
                return $noun;
            }
        }

        $governors = array_merge(
            self::positionsOf($sentence, self::DRAWN_QUALIFIERS),
            self::positionsOf($sentence, self::PLACEMENT_PHRASES),
        );
        if ($governors === []) {
            return null;
        }
        foreach (self::DECORATION_NOUNS as $noun) {
            $at = self::wordPosition($sentence, $noun);
            if ($at === null || self::negatedBefore($sentence, $at)) {
                continue;
            }
            foreach ($governors as $governor) {
                if (abs($governor - $at) <= self::GOVERNS_REACH) {
                    return $noun;
                }
            }
        }
        return null;
    }

    /**
     * Split into sentences on terminal punctuation.
     *
     * A colon does NOT end a sentence here: "Ornament comes from the loom, not
     * from clip art: a narrow repeating band of Georgian carpet geometry …
     * separates major sections" is one thought, and the promise is on the far
     * side of the colon from the negation.
     *
     * @return list<string>
     */
    private static function sentences(string $description): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $description, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            // A narrative that is not valid UTF-8 still gets one look.
            return [$description];
        }
        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $sentence): bool => $sentence !== '',
        ));
    }

    /**
     * Byte offset of a whole-word match, or null.
     *
     * The trailing guard forbids a following word character but ALLOWS a
     * hyphen, so `grapevine-and-tendril fret` matches on `tendril` — hyphen
     * compounding is how these narratives name a motif, not a way out of one.
     */
    private static function wordPosition(string $sentence, string $word): ?int
    {
        $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($word, '/') . '(?![\p{L}\p{N}_])/iu';
        if (preg_match($pattern, $sentence, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        return $m[0][1];
    }

    /**
     * Byte offsets of every one of these that appears in the sentence.
     *
     * @param list<string> $needles
     * @return list<int>
     */
    private static function positionsOf(string $sentence, array $needles): array
    {
        $found = [];
        foreach ($needles as $needle) {
            $at = self::wordPosition($sentence, $needle);
            if ($at !== null) {
                $found[] = $at;
            }
        }
        return $found;
    }

    /**
     * Whether a negation governs a noun at this offset.
     *
     * Scoped to the CLAUSE, not the whole sentence. These narratives
     * habitually set up a contrast and then make the promise on the far side
     * of a colon or dash — "Ornament is structural, never sprinkled: flat
     * vector blocks of Georgian borjgali rosettes …", "Ornament comes from the
     * loom, not from clip art: a narrow repeating band of Georgian carpet
     * geometry …". Reading the whole sentence lets that `never`/`not` cancel a
     * promise it has nothing to do with, which hid three real cases.
     *
     * A comma is NOT a boundary: "no gradients, no glow, no ornament" keeps
     * its negation next to each noun anyway, and treating commas as boundaries
     * would strand the negation in "never used for running text or ornament".
     */
    private static function negatedBefore(string $sentence, int $at): bool
    {
        $before = substr($sentence, 0, $at);
        $boundary = 0;
        foreach ([':', ';', '—', '–'] as $mark) {
            $found = strrpos($before, $mark);
            if ($found !== false) {
                $boundary = max($boundary, $found + strlen($mark));
            }
        }
        // A comma starts a new clause only when a coordinating conjunction
        // follows it. "Nothing is glossy, AND hand-drawn rosettes mark every
        // band" is two clauses and the second is a promise; "no gradients, no
        // glow, no ornament" is one list that the leading `no` governs
        // throughout.
        if (preg_match_all('/,\s+(?:and|but|yet|while|whereas|though)\b/i', $before, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($m[0]);
            $boundary = max($boundary, $last[1] + strlen($last[0]));
        }
        $clause = substr($before, $boundary);
        foreach (self::NEGATION_MARKERS as $marker) {
            if (self::wordPosition($clause, $marker) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * One sentence, trimmed to a quotable length.
     *
     * `mb_substr` and an encoding-guarded whitespace collapse: cutting a
     * multibyte character in half makes `preg_replace('//u')` return null,
     * which would silently replace the whole evidence string with nothing.
     * These narratives are full of em dashes and curly quotes.
     */
    private static function clip(string $sentence): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $sentence);
        if (!is_string($collapsed)) {
            $collapsed = (string) preg_replace('/\s+/', ' ', $sentence);
        }
        $collapsed = trim($collapsed);
        return mb_strlen($collapsed) > 150
            ? mb_substr($collapsed, 0, 150) . '…'
            : $collapsed;
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
