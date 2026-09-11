<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;

/**
 * Deterministic typographic craft floor for delivered markup (BIGR-1012).
 *
 * Every structural check in this pipeline can pass — contrast, measure,
 * alignment, heading hierarchy — and the page still reads as machine-set,
 * because the defects that give it away are below the structural layer. They
 * are also the defects no prompt reliably prevents: an author writing one
 * section cannot see where its heading will break, and asking a model for
 * curly quotes buys a coin flip, not a guarantee. So they are fixed here,
 * on the delivered bytes, where the answer is knowable.
 *
 * Three passes, each bounded, semantics-safe, and idempotent (rung 1 of the
 * escalation ladder in AGENTS.md):
 *
 * WIDOWS. A heading that wraps with one word alone on its last line is the
 * most visible typographic error on a page — the eye reads the gap before it
 * reads the words. Binding the last two words with U+00A0 makes that break
 * impossible without changing a character the visitor reads. Two guards keep
 * the binding from causing the overflow it prevents: headings under
 * MIN_HEADING_WORDS words are left alone (too short to widow), and a last
 * pair longer than MAX_BOUND_PAIR characters is left alone (bound, it would
 * behave like one long word against a narrow measure).
 *
 * The hero masthead is excluded. HeroHeadlineFit already sizes the display
 * H1 against its copy measure and the blueprint's line target, and two passes
 * negotiating the same headline's wrapping would fight: PCRE treats U+00A0 as
 * whitespace under /u, so that fit reads a bound pair as two words and sizes
 * as though the break were still available. One owner per concern — the same
 * reason SectionCopyDedupe leaves hero copy to HeaderHeroStep.
 *
 * PUNCTUATION. Straight quotes and apostrophes are the typewriter's, not the
 * typographer's, and a model emits them roughly at random. Year ranges want
 * an en dash. A figure wants to keep its unit on its own line. These run on
 * TEXT NODES ONLY — HtmlFragment gives every text node its source span, so
 * block-comment JSON, tag names, `href` values and class lists are structurally
 * out of reach rather than merely avoided by a careful pattern.
 *
 * The en-dash rule is deliberately the narrowest one that still does the job:
 * both sides must be four-digit years, and neither side may touch another
 * digit or hyphen. That last guard is what keeps a grouped phone number
 * ("03-1234-1999") out of it — hard facts come from the spec verbatim, and a
 * pass that rewrote one would be worse than the defect it fixed. For the same
 * reason there is no true-minus rule: negative figures are rare on these
 * sites and the hyphens that are not minus signs are not.
 *
 * JUSTIFY. A guard, not a repair of anything observed: nothing in the prompts
 * rules out `align: justify`, and at the reading measure these layouts use it
 * opens rivers through the copy.
 */
final class TypographicHygiene
{
    private const NBSP = "\u{00A0}";

    /** Headings shorter than this cannot strand a word, so they are left alone. */
    private const MIN_HEADING_WORDS = 4;

    /** A bound last pair longer than this risks overflowing a narrow measure. */
    private const MAX_BOUND_PAIR = 15;

    /** The masthead preset HeroHeadlineFit owns; never widow-bound here. */
    private const MASTHEAD_PRESET = 'display';

    private const JUSTIFY_CLASS = 'has-text-align-justify';

    /** One separator, however the authored markup happened to wrap it. */
    private const WHITESPACE_RUN = '/[ \t\r\n]+/';

    /** Elements whose text is content, not prose. */
    private const VERBATIM_TAGS = ['code', 'pre', 'script', 'style'];

    /**
     * Units that may be bound to the figure before them. Unambiguous
     * abbreviations only: a token that could open a word ("pm", "de", "Main")
     * is not here, and the edit is invisible anyway — it forbids a line break
     * and changes nothing the visitor reads.
     */
    private const UNITS = [
        '%', '°', '°C', '°F',
        'km', 'm', 'cm', 'mm', 'µm',
        'kg', 'g', 'mg', 't',
        'l', 'ml', 'cl', 'dl',
        'h', 'min', 's', 'ms',
        'ha', 'm²', 'km²', 'm³',
        'kW', 'kWh', 'W', 'V', 'A',
        'KB', 'MB', 'GB', 'TB',
    ];

    /**
     * @return array{markup:string,widows:int,punctuation:int,justify:int}
     */
    public static function apply(string $markup): array
    {
        // Justify and widows run in SEPARATE parse/render cycles on purpose.
        // Clearing justification edits a class token in a node's own HTML and
        // binding a widow splices that same HTML; BlockMarkup forbids
        // overlapping the two on one node, and a heading that is both
        // justified and widow-prone hits exactly that case — the splice was
        // silently dropped while the count still claimed the binding.
        $justify = self::justifyPass($markup);
        $widows = self::widowPass($justify['markup']);
        $text = self::punctuationPass($widows['markup']);

        return [
            'markup' => $text['markup'],
            'widows' => $widows['widows'],
            'justify' => $justify['justify'],
            'punctuation' => $text['punctuation'],
        ];
    }

    /**
     * Clear justified alignment in every spelling that can reach the page.
     *
     * Core spells it `align` on paragraphs and `textAlign` on headings,
     * columns and media-text, and fix-blocks rescues the saved class from
     * `className` — so an attribute-only check would let two of the three
     * spellings ship.
     *
     * @return array{markup:string,justify:int}
     */
    private static function justifyPass(string $markup): array
    {
        $doc = self::parseEditable($markup);
        $justify = 0;

        foreach ($doc->indices() as $i) {
            if (!$doc->isStructurallySafe($i)) {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            $classes = self::classTokens($attrs);
            $viaClass = in_array(self::JUSTIFY_CLASS, $classes, true);
            $viaAlign = ($attrs['align'] ?? null) === 'justify';
            $viaTextAlign = ($attrs['textAlign'] ?? null) === 'justify';
            if (!$viaAlign && !$viaTextAlign && !$viaClass) {
                continue;
            }

            // Only the justified spelling goes. `align` also carries full and
            // wide, and unsetting it on the class-only branch would strip a
            // band's layout alignment.
            if ($viaAlign) {
                unset($attrs['align']);
            }
            if ($viaTextAlign) {
                unset($attrs['textAlign']);
            }
            if ($viaClass) {
                $kept = array_values(array_diff($classes, [self::JUSTIFY_CLASS]));
                if ($kept === []) {
                    unset($attrs['className']);
                } else {
                    $attrs['className'] = implode(' ', $kept);
                }
            }
            $doc->setAttrs($i, $attrs);
            $doc->removeClassTokenInOwnHtml($i, self::JUSTIFY_CLASS);
            $justify++;
        }

        return [
            'markup' => $doc->isMutated() ? $doc->render() : $markup,
            'justify' => $justify,
        ];
    }

    /**
     * @return array{markup:string,widows:int}
     */
    private static function widowPass(string $markup): array
    {
        $doc = self::parseEditable($markup);
        $widows = 0;

        foreach ($doc->indices() as $i) {
            if (!$doc->isStructurallySafe($i) || $doc->name($i) !== 'heading') {
                continue;
            }
            if (($doc->attrs($i)['fontSize'] ?? null) === self::MASTHEAD_PRESET) {
                continue;
            }
            $bind = self::lastProseSpace($doc->ownHtml($i));
            if ($bind === null) {
                continue;
            }
            $doc->spliceOwnHtml($i, $bind['start'], $bind['length'], self::NBSP);
            $widows++;
        }

        return [
            'markup' => $doc->isMutated() ? $doc->render() : $markup,
            'widows' => $widows,
        ];
    }

    private static function parseEditable(string $markup): BlockMarkup
    {
        $doc = BlockMarkup::parse($markup);
        if (
            $doc->unclosedIndices() !== []
            || $doc->hasMismatchedDelimiters()
            || $doc->hasMalformedDelimiters()
        ) {
            throw new \RuntimeException('block structure is malformed; markup left unchanged');
        }
        return $doc;
    }

    /** @return list<string> */
    private static function classTokens(array $attrs): array
    {
        return preg_split(
            '/\s+/',
            trim((string) ($attrs['className'] ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
    }

    /**
     * Where to bind a heading's last two words: the source offset and byte
     * length of the whitespace run before its final word, or null when this
     * heading must not be bound.
     *
     * "Last space" is the last one in the rendered TEXT, which is not the last
     * one in the source: a heading ending in `<span class="emph">two words</span>`
     * keeps its final space inside the span, and a heading ending in
     * `</span> word` keeps it outside. Walking tags rather than text is what
     * makes both land on the same separator. The separator is a whitespace RUN,
     * not a single space — authored markup wraps, and a heading whose last
     * separator is a newline would otherwise measure one pair and bind another.
     *
     * @return array{start:int,length:int}|null
     */
    private static function lastProseSpace(string $ownHtml): ?array
    {
        $text = '';
        /** @var list<int> $sourceAt one source offset per text byte collected */
        $sourceAt = [];

        $length = strlen($ownHtml);
        $offset = 0;
        $depth = 0;
        while ($offset < $length) {
            $char = $ownHtml[$offset];
            if ($char === '<') {
                $close = strpos($ownHtml, '>', $offset);
                if ($close === false) {
                    return null;
                }
                $depth++;
                $offset = $close + 1;
                continue;
            }
            // The heading's own opening tag is markup; everything after it is
            // prose until the closer, which the loop simply runs past.
            if ($depth > 0) {
                $text .= $char;
                $sourceAt[] = $offset;
            }
            $offset++;
        }

        $text = rtrim($text);
        if ($text === '') {
            return null;
        }
        // An already-bound heading must come back unchanged: this pass runs in
        // a repairable pipeline and has to reach a fixed point. Both spellings
        // count — HtmlNode writes U+00A0 back out as `&nbsp;`, so a resumed
        // build (or an authored entity) presents the bound heading that way.
        if (str_contains(PlainText::fromMarkup($text), self::NBSP)) {
            return null;
        }

        $words = preg_split(self::WHITESPACE_RUN, trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < self::MIN_HEADING_WORDS) {
            return null;
        }

        // Measure what renders, not what is encoded: "&amp;" is one character
        // to the reader, and counting five would refuse bindings that fit.
        $pair = PlainText::fromMarkup(
            $words[count($words) - 2] . ' ' . $words[count($words) - 1],
        );
        if (mb_strlen($pair, 'UTF-8') > self::MAX_BOUND_PAIR) {
            return null;
        }

        if (!preg_match_all(self::WHITESPACE_RUN, $text, $runs, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        [$run, $at] = $runs[0][count($runs[0]) - 1];
        $lastByte = $at + strlen($run) - 1;
        if (!isset($sourceAt[$at], $sourceAt[$lastByte])) {
            return null;
        }
        // A whitespace run holds no tags, so its bytes are contiguous in source.
        return [
            'start' => $sourceAt[$at],
            'length' => $sourceAt[$lastByte] - $sourceAt[$at] + 1,
        ];
    }

    /**
     * @return array{markup:string,punctuation:int}
     */
    private static function punctuationPass(string $markup): array
    {
        $fragment = HtmlFragment::parse($markup);

        /** @var list<array{start:int,end:int,raw:string}> $edits */
        $edits = [];
        $changed = 0;
        foreach (self::textNodes($fragment->root()) as [$node, $inHeading]) {
            $start = $node->startOffset();
            $end = $node->endOffset();
            $raw = substr($markup, $start, $end - $start);
            $fixed = self::fixPunctuation($raw, $inHeading);
            if ($fixed === $raw) {
                continue;
            }
            $edits[] = ['start' => $start, 'end' => $end, 'raw' => $fixed];
            $changed++;
        }
        if ($edits === []) {
            return ['markup' => $markup, 'punctuation' => 0];
        }

        // Splice from the end so each edit's offsets stay valid.
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($edits as $edit) {
            $markup = substr_replace($markup, $edit['raw'], $edit['start'], $edit['end'] - $edit['start']);
        }

        return ['markup' => $markup, 'punctuation' => $changed];
    }

    /**
     * Every text node under $node whose ancestors are all prose elements,
     * paired with whether it sits inside a heading. Comments are a distinct
     * node type, so block delimiters never appear here.
     *
     * @return list<array{0:HtmlNode,1:bool}>
     */
    private static function textNodes(HtmlNode $node, bool $inHeading = false): array
    {
        $found = [];
        foreach ($node->children() as $child) {
            if ($child->isText()) {
                $found[] = [$child, $inHeading];
                continue;
            }
            if (!$child->isElement()) {
                continue;
            }
            $tag = (string) $child->tagName();
            if (in_array($tag, self::VERBATIM_TAGS, true)) {
                continue;
            }
            $heading = $inHeading || preg_match('/^h[1-6]$/', $tag) === 1;
            foreach (self::textNodes($child, $heading) as $descendant) {
                $found[] = $descendant;
            }
        }
        return $found;
    }

    /** Typewriter punctuation to typographic, on one run of prose. */
    private static function fixPunctuation(string $text, bool $inHeading): string
    {
        // Opening double quote after nothing, whitespace, or an opening
        // bracket; closing everywhere else. The stateless form of the rule,
        // so a quote pair split across two text nodes still resolves.
        $text = preg_replace('/(^|[\s(\[{¿¡—–-])"/u', '$1“', $text) ?? $text;
        $text = str_replace('"', '”', $text);

        // Apostrophe inside a word ("don't") and the possessive plural, which
        // is spelled after an s ("the dogs' bowls"). A leading quote-style
        // apostrophe is left alone — it is as likely to open a quotation as to
        // elide a letter — and the trailing rule is held to s so that "Rock
        // 'n' roll" does not come back with one half curled and one straight.
        $text = preg_replace("/(\p{L})'(\p{L})/u", '$1’$2', $text) ?? $text;
        $text = preg_replace("/(?<=s)'(?=[\s.,;:!?)]|$)/u", '’', $text) ?? $text;

        // Year ranges only, and only when neither end touches another digit or
        // hyphen — the guard that keeps grouped phone numbers out.
        $text = preg_replace(
            '/(?<![\d-])(1\d{3}|20\d{2})\s*-\s*(1\d{3}|20\d{2})(?![\d-])/u',
            '$1–$2',
            $text,
        ) ?? $text;

        // Binding a unit to its figure is a wrapping decision, and inside a
        // heading the widow pass is the one owner of those — it is why the
        // masthead H1 is excluded there. Quotes and dashes change no wrapping,
        // so they still apply everywhere.
        return $inHeading ? $text : self::bindUnits($text);
    }

    /** Keep a unit on the same line as its figure. */
    private static function bindUnits(string $text): string
    {
        $units = array_map(
            static fn (string $unit): string => preg_quote($unit, '/'),
            self::UNITS,
        );
        // Longest first, so "km²" is not matched as "km" with a stray "²".
        usort($units, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return preg_replace(
            '/(\d) (' . implode('|', $units) . ')(?![\p{L}\d])/u',
            '$1' . self::NBSP . '$2',
            $text,
        ) ?? $text;
    }
}
