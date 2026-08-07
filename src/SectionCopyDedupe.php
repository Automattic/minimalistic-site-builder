<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Page-wide repeated-copy suppressor (BIGR-783).
 *
 * Sections are authored concurrently from the same site brief, so several of
 * them independently derive the same short identity line — a location kicker,
 * a tagline, a signature proverb — and the assembled page reads as a stutter:
 * the same line two or three times in identical styling. HeaderHeroStep's
 * twin-lines pass (BIGR-773) covers only the header-tagline/hero-eyebrow pair
 * above the fold; this pass covers the content sections below it, plus the
 * seam a page's closing section forms with the site-wide footer.
 *
 * Scope is deliberately narrow to keep false positives out:
 *
 *  - Only LABEL-STYLED paragraphs participate — uppercase-transformed or
 *    caption/small-preset lines, the styling that makes a repeat read as a
 *    stutter. Body prose is never compared: two sentences that share a city
 *    name are storytelling, not duplication.
 *  - Pullquote/quote bodies participate separately: two quotes opening with
 *    the same long word run are the same quote in paraphrase.
 *  - A duplicate must span DIFFERENT sections. Repeats inside one section are
 *    that section's own rhetoric (schedules, spec lists) and stay.
 *  - The later occurrence in reading order is removed; the footer is shared
 *    by every page and is treated as read-only canon, so a closing-section
 *    line that duplicates a footer line is removed on the page side only.
 *  - A removal widens through newly emptied wrappers but never deletes a
 *    whole planned section. Pages exceeding the safety cap stay byte-identical
 *    and surface actionable residual warnings at the step boundary.
 */
final class SectionCopyDedupe
{
    private const MAX_REMOVALS_PER_PAGE = 4;

    /** Word-prefix length at which two quotes are the same quote. */
    private const QUOTE_PREFIX_TOKENS = 6;

    /** Token-containment ratio at which two label lines are the same line. */
    private const LABEL_CONTAINMENT = 0.8;

    /**
     * @param list<array{slug:string,markup:string}> $sections ordered content
     *        sections of one page (hero excluded by the caller)
     * @param string $footerMarkup the shared footer part, read-only context;
     *        '' when the site has none
     * @return array{
     *     markups:list<string>,
     *     notes:list<string>,
     *     residuals:list<array{section:int,slug:string,excerpt:string,start:int}>,
     *     removed:int
     * }
     */
    public static function dedupe(array $sections, string $footerMarkup): array
    {
        $candidates = [];
        foreach ($sections as $s => $section) {
            foreach (self::candidates($section['markup']) as $candidate) {
                $candidates[] = $candidate + ['section' => $s, 'slug' => $section['slug']];
            }
        }
        $lastSection = count($sections) - 1;
        $footerCandidates = $footerMarkup === '' ? [] : self::candidates($footerMarkup);

        // Reading order: earlier candidate wins, later one is removed. A block
        // already marked for removal cannot claim a later block as its own
        // duplicate — the earliest occurrence is the only survivor.
        $removals = [];
        $notes = [];
        foreach ($candidates as $b => $late) {
            if (isset($removals[$b]) || !$late['removable']) {
                continue;
            }
            foreach ($candidates as $a => $early) {
                if ($a >= $b || isset($removals[$a])) {
                    continue;
                }
                if ($early['section'] === $late['section']) {
                    continue;
                }
                if (self::duplicates($early, $late)) {
                    $removals[$b] = true;
                    $notes[$b] = "section '{$late['slug']}': removed \"{$late['excerpt']}\" — repeats"
                        . " \"{$early['excerpt']}\" from section '{$early['slug']}'";
                    break;
                }
            }
        }

        // The closing-section/footer seam: the footer renders directly below
        // the page's last section on every page, so a duplicate there is the
        // most visible stutter of all. The footer is shared canon — the page
        // side is the removable occurrence.
        foreach ($candidates as $b => $late) {
            if (isset($removals[$b]) || !$late['removable'] || $late['section'] !== $lastSection) {
                continue;
            }
            foreach ($footerCandidates as $canon) {
                if (self::duplicates($canon, $late)) {
                    $removals[$b] = true;
                    $notes[$b] = "section '{$late['slug']}': removed \"{$late['excerpt']}\" — repeats"
                        . " the footer's \"{$canon['excerpt']}\" directly above it";
                    break;
                }
            }
        }

        $residuals = [];
        if (count($removals) > self::MAX_REMOVALS_PER_PAGE) {
            $keys = array_keys($removals);
            // A partial first-four rewrite is not a fixed point: a second pass
            // would see fewer duplicates and remove one that the first pass
            // retained. Treat an exceeded cap as evidence that the matcher may
            // be over-firing, preserve the whole page, and warn for every
            // duplicate that remains.
            foreach ($keys as $b) {
                $residuals[] = [
                    'section' => $candidates[$b]['section'],
                    'slug'    => $candidates[$b]['slug'],
                    'excerpt' => $candidates[$b]['excerpt'],
                    'start'   => $candidates[$b]['start'],
                ];
            }
            $removals = [];
            $notes = [];
        }

        // Splice per section, later spans first so earlier offsets stay valid.
        $markups = array_map(static fn (array $s): string => $s['markup'], $sections);
        $bySection = [];
        foreach (array_keys($removals) as $b) {
            $bySection[$candidates[$b]['section']][] = $candidates[$b];
        }
        foreach ($bySection as $s => $spans) {
            usort($spans, static fn (array $x, array $y): int => $y['start'] <=> $x['start']);
            $markup = $markups[$s];
            foreach ($spans as $span) {
                $markup = substr_replace($markup, '', $span['start'], $span['end'] - $span['start']);
            }
            $markups[$s] = (string) preg_replace("/\n{3,}/", "\n\n", $markup);
        }

        return [
            'markups'   => $markups,
            'notes'     => array_values($notes),
            'residuals' => $residuals,
            'removed'   => count($removals),
        ];
    }

    /**
     * Extract one markup's dedupe candidates: label-styled paragraphs and
     * quote bodies, each with its normalized token list and removable span.
     *
     * @return list<array{kind:string,tokens:list<string>,norm:string,excerpt:string,start:int,end:int}>
     */
    public static function candidates(string $markup): array
    {
        $doc = BlockMarkup::parse($markup);
        if (
            $doc->unclosedIndices() !== []
            || $doc->hasMismatchedDelimiters()
            || $doc->hasMalformedDelimiters()
        ) {
            throw new \RuntimeException('malformed block structure');
        }
        $out = [];
        foreach ($doc->indices() as $i) {
            if (!$doc->isStructurallySafe($i)) {
                continue;
            }
            $name = $doc->name($i);
            if ($name !== 'paragraph' && $name !== 'pullquote' && $name !== 'quote') {
                continue;
            }
            $inner = $doc->innerHtml($i);
            if ($name === 'paragraph') {
                // A paragraph inside a quote belongs to the quote candidate;
                // listing both would produce overlapping removal spans.
                if (self::insideQuote($doc, $i) || !self::isLabelStyled($inner)) {
                    continue;
                }
                $kind = 'label';
                $text = $inner;
            } else {
                $kind = 'quote';
                // The quote body is the identity; a differing attribution line
                // must not stop two identical quotes from matching.
                $text = (string) preg_replace('#<cite\b[^>]*>.*?</cite>#is', ' ', $inner);
            }
            $norm = self::normalize($text);
            $tokens = $norm === '' ? [] : explode(' ', $norm);
            if ($tokens === [] || ($kind === 'label' && count($tokens) < 2)) {
                continue;
            }
            // Removing a wrapper's only child would deliver an empty wrapper,
            // so the removal span widens to the highest ancestor that would
            // be emptied (a pullquote usually sits alone in a centering
            // group). A candidate whose widened span is a whole top-level
            // section stays anchoring-only: sections are the plan's units.
            $top = $i;
            for ($p = $doc->parent($top); $p !== null && $doc->children($p) === [$top]; $p = $doc->parent($p)) {
                $top = $p;
            }
            $plain = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
            $out[] = [
                'kind'      => $kind,
                // Quote matching needs the authored token sequence, including
                // repetitions. Label containment applies set semantics at the
                // comparison site below.
                'tokens'    => $tokens,
                'norm'      => $norm,
                'excerpt'   => mb_strlen($plain) > 60 ? mb_substr($plain, 0, 57) . '…' : $plain,
                'start'     => $doc->openingOffset($top),
                'end'       => (int) $doc->endOffset($top),
                'removable' => $doc->parent($top) !== null && $doc->isStructurallySafe($top),
            ];
        }
        return $out;
    }

    /**
     * Whether a later candidate repeats an earlier one closely enough that a
     * visitor reads it as the same line said twice.
     *
     * @param array{kind:string,tokens:list<string>,norm:string} $early
     * @param array{kind:string,tokens:list<string>,norm:string} $late
     */
    public static function duplicates(array $early, array $late): bool
    {
        if ($early['kind'] !== $late['kind']) {
            return false;
        }
        if ($early['norm'] === $late['norm']) {
            return true;
        }
        if ($early['kind'] === 'quote') {
            $prefix = 0;
            $max = min(count($early['tokens']), count($late['tokens']));
            while ($prefix < $max && $early['tokens'][$prefix] === $late['tokens'][$prefix]) {
                $prefix++;
            }
            return $prefix >= self::QUOTE_PREFIX_TOKENS;
        }
        // Near-duplicate labels must be an ECHO, not a longer line that merely
        // contains the earlier one's tokens: "Based in Buenos Aires, working
        // across the country" says more than a footer's "Buenos Aires" and
        // stays. Sharing an email domain or a city is not repetition either —
        // the overlap must dominate the shorter line AND be substantial.
        $earlyTokens = array_values(array_unique($early['tokens']));
        $lateTokens = array_values(array_unique($late['tokens']));
        $shorter = min(count($earlyTokens), count($lateTokens));
        if ($shorter < 3 || count($lateTokens) > 2 * count($earlyTokens)) {
            return false;
        }
        $shared = count(array_intersect($earlyTokens, $lateTokens));
        return $shared >= 3 && $shared / $shorter >= self::LABEL_CONTAINMENT;
    }

    /** Whether any ancestor block is a quote or pullquote. */
    private static function insideQuote(BlockMarkup $doc, int $i): bool
    {
        for ($p = $doc->parent($i); $p !== null; $p = $doc->parent($p)) {
            if (in_array($doc->name($p), ['quote', 'pullquote'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a paragraph's own markup carries the styling that makes a
     * repeated line read as a stutter: uppercase transform or a small
     * caption-class font preset. Matched on the paragraph's own tag only —
     * body prose never qualifies, however similar its words.
     */
    private static function isLabelStyled(string $inner): bool
    {
        return preg_match('/text-transform\s*:\s*uppercase/i', $inner) === 1
            || preg_match('/\bhas-(?:caption|small|x-small|xs|tiny|label|eyebrow)-font-size\b/', $inner) === 1;
    }

    /** Casefolded, tag/entity/punctuation-free, whitespace-collapsed text. */
    public static function normalize(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[·•◦▪—–‒―|\/\\\\,;:!?.…\'"“”‘’«»()\[\]{}&+*<>~^%$#@=_-]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
