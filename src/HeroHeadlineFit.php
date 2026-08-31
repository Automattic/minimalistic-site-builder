<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Guarantees the hero headline is set at the masthead scale, and that its
 * longest word fits its copy measure.
 *
 * Three passes, in that order.
 *
 * PROMOTION. The `display` preset exists for exactly one thing — the hero
 * masthead — and the hero model picks the H1's preset itself. When it picks
 * `section-title` instead, the headline renders at the same size as every
 * section H2 below it and the first screen reads as a caption over a
 * photograph (lumen3/atlas3/pulso3, all three at ~41px against a `display`
 * that resolves to 87-96px; BIGR-883). `prompts/hero.md` tells the model to
 * step down to "the largest heading preset that fits" inside a narrow column,
 * and these three stepped past it to the section preset. Nothing downstream
 * checked, even though HeaderHeroStep already demotes a site title that
 * "competes with the hero's display H1".
 *
 * So the page's one H1 is promoted to `display` and then sized down from the
 * measure and the blueprint's desktop line target, which is what "the largest
 * preset that fits" actually means. A heading the model already set at
 * `display`, or pinned to an explicit size, is left exactly as it was: this
 * pass raises a floor, it does not re-litigate a choice that already clears it.
 *
 * WORD FIT. The display preset is sized by one model call and the hero
 * headline is written by another, so nothing stops a long brand word
 * ("ELECTRONIC") from outgrowing the copy column at the preset's desktop
 * maximum. When that happens a too-wide word used to snap mid-line with no
 * hyphen (`overflow-wrap: break-word`) on browsers without hyphenation
 * dictionaries — a broken first screen (BIGR-798, BIGR-864). Headings now
 * wrap at spaces only; this pass still pins the size so the longest word
 * fits the measure instead of overflowing.
 *
 * It runs where both facts are finally known (the delivered hero
 * markup and theme.json): it estimates the widest word of every
 * display-size heading against the measure its layout chain implies, and
 * when that word cannot fit at the preset's maximum, pins the heading to
 * `min(var(--wp--preset--font-size--display), <cap>px)` — fluid behaviour
 * below the cap is untouched, and headings whose words already fit are left
 * byte-identical.
 *
 * LINE TARGET. Since BIGR-900 the hero model authors `display` on the H1
 * itself, so the promotion above declines and its line-target bound stopped
 * running for every delivered hero: the only remaining check was the
 * single-word fit, which a headline of many short words sails past. The
 * cohort showed the result — tbilisi and atlas (layered-poster, `dramatic`
 * scale) rendered 9-word headlines at the full 128px display maximum, one
 * to two lines past their 3- and 4-line blueprints (BIGR-951). So the
 * masthead H1 is bounded by the blueprint's desktop line target even when
 * it already authors `display`, with the same `min(display, <cap>px)` pin
 * the promotion writes. A target no size above the pin threshold can hold
 * stays unpinned: that target is already lost, and a sub-threshold masthead
 * is worse than an extra wrapped line.
 *
 * Two deliberate limits:
 *
 * - The width estimate is a character count times one generous per-character
 *   advance, not a per-glyph table. Real families vary far too much for
 *   per-glyph precision to mean anything (a condensed display face runs
 *   ~0.45em/char, an extended slab ~0.9em), so the estimate only separates
 *   "comfortably fits" from "cannot possibly fit", and the CSS guard stays
 *   the last resort for the faces it misjudges.
 * - The measure comes from the block tree, never from CSS. A container query
 *   (`cqi`) would read the true column width at runtime, but `container-type`
 *   zeroes a contained box's intrinsic contribution, and hero copy regions
 *   routinely sit inside content-sized ancestors — a cover with a custom
 *   `contentPosition` gets `width: auto` on its inner container — where that
 *   collapses the whole column to nothing (measured: portfolio4/pulso5,
 *   2026-08-10). A too-wide measure merely under-protects; a collapsed hero
 *   is a broken site, so this stays arithmetic on the markup.
 */
final class HeroHeadlineFit
{
    /**
     * Generous per-character advance in em: uppercase runs wider than mixed
     * case, and both sit above a normal grotesque so the common families
     * land inside the estimate.
     */
    private const UPPERCASE_EM = 0.70;
    private const MIXED_CASE_EM = 0.58;

    /** Non-em letter spacing means "spacing exists"; only widening counts. */
    private const UNKNOWN_SPACING_EM = 0.03;

    /** The measure keeps a small margin for the copy wrapper's own padding. */
    private const MEASURE_SAFETY = 0.96;

    /** Below this a pin is worse than the CSS guard; leave the guard to it. */
    private const MINIMUM_CAP_PX = 32;

    /**
     * Per-character advance for the LINE-COUNT estimate, in em.
     *
     * Separate from the two constants above on purpose. Those are deliberately
     * generous because over-estimating a word's width only ever pins a heading
     * SMALLER, which is the safe direction for overflow. Here the same
     * generosity compounds: it inflates every line, so the headline is
     * predicted to need more lines than it does and the cap comes back far
     * below the size that actually fits.
     *
     * These are measured, not guessed — rendered advance of the delivered hero
     * H1 at 1366px, including each face's own tracking (2026-08-25):
     *
     *   Work Sans 700, mixed case, -0.025em ...... 0.487 em/char
     *   Be Vietnam Pro 700, mixed case ........... 0.474 em/char
     *   Chakra Petch 700, uppercase, +0.043em .... 0.573 em/char
     *
     * The values below sit ~8% above those, so a face heavier than the three
     * measured still lands inside the estimate.
     */
    private const LINE_UPPERCASE_EM = 0.58;
    private const LINE_MIXED_CASE_EM = 0.52;

    /**
     * The stylesheet's hyphenation hook (ScaffoldThemeStep). Hero headlines
     * wrap whole words by default — blanket `hyphens: auto` hyphenates
     * ordinary words at ordinary line breaks — so hyphenation is opted into
     * per heading, only where no pinnable size fits.
     */
    private const HYPHENATE_CLASS = 'headline-hyphenate';

    /**
     * The masthead preset. Nothing else on the page uses it.
     */
    private const DISPLAY_SLUG = 'display';

    /**
     * @param list<int>|null $desktopLineTarget the blueprint's desktop
     *        [min, max] headline line target; without it only the word-fit
     *        pass bounds the headline.
     * @return array{markup:string, notes:list<string>}
     */
    public static function apply(string $markup, array $theme, ?array $desktopLineTarget = null): array
    {
        $displayMax = self::displayMaxPx($theme);
        if ($displayMax === null || !is_finite($displayMax) || $displayMax <= 0 || $displayMax > PHP_INT_MAX) {
            return ['markup' => $markup, 'notes' => []];
        }
        $doc = BlockMarkup::parse($markup);
        if ($doc->hasMismatchedDelimiters() || $doc->hasMalformedDelimiters()) {
            return ['markup' => $markup, 'notes' => []];
        }

        $notes = [];
        self::promoteMasthead($doc, $theme, $displayMax, $desktopLineTarget, $notes);
        $masthead = self::mastheadIndex($doc);
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'heading' || !$doc->isStructurallySafe($i)) {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            if (($attrs['fontSize'] ?? null) !== 'display') {
                continue;
            }
            if (isset($attrs['style']['typography']['fontSize'])) {
                // An authored (or previously pinned) explicit size wins; this
                // also makes the pass idempotent.
                continue;
            }
            $fit = self::wordFit($doc, $i, $theme, $attrs, $displayMax);
            if ($fit === null) {
                continue;
            }
            $wordCap = $fit['cap'];
            if ($wordCap !== null && $wordCap < self::MINIMUM_CAP_PX) {
                // A word this long in a measure this narrow has no size worth
                // pinning. This is the one case where a hyphen beats a bare
                // mid-word snap, so opt this heading — and only this heading —
                // into the stylesheet's hyphenation hook.
                self::optIntoHyphenation($doc, $i, $attrs, $fit, $notes);
                continue;
            }
            // The masthead authors `display` itself since BIGR-900, so the
            // promotion above declines and its line-target bound never runs;
            // apply the same bound here (BIGR-951). A target no size above
            // the pin threshold can hold stays unpinned: that target is
            // already lost, and a sub-threshold masthead is worse than an
            // extra wrapped line.
            $lineCap = $i === $masthead
                ? self::lineTargetCapPx($doc, $i, $theme, $attrs, $desktopLineTarget, $displayMax)
                : null;
            if ($lineCap !== null && ($lineCap < self::MINIMUM_CAP_PX || $lineCap >= $displayMax)) {
                $lineCap = null;
            }
            $caps = array_values(array_filter(
                [$wordCap, $lineCap],
                static fn (?int $value): bool => $value !== null,
            ));
            if ($caps === []) {
                continue;
            }
            $cap = min($caps);
            // The preset class must go with the preset attr: WordPress
            // renders `.has-display-font-size` with !important, which would
            // beat the pinned inline size. The min() keeps the preset var,
            // so fluid behaviour below the cap is unchanged.
            unset($attrs['fontSize']);
            $attrs = self::withPinnedFontSize(
                $attrs,
                'min(var(--wp--preset--font-size--display), ' . $cap . 'px)',
            );
            $doc->setAttrs($i, $attrs);
            $doc->removeClassTokenInOwnHtml($i, 'has-display-font-size');
            $notes[] = $cap === $wordCap
                ? sprintf(
                    "headline word-fit: '%s' (%d chars%s, ~%.2fem) cannot fit the %dpx measure at the display "
                        . 'maximum %dpx; heading pinned to min(display, %dpx)',
                    $fit['word'],
                    $fit['chars'],
                    $fit['uppercase'] ? ', uppercase' : '',
                    $fit['wordEm'],
                    (int) round($fit['measure']),
                    (int) round($displayMax),
                    $cap,
                )
                : sprintf(
                    'headline line-fit: the headline cannot hold the desktop line target inside the %dpx '
                        . 'measure at the display maximum %dpx; heading pinned to min(display, %dpx)',
                    (int) round($fit['measure']),
                    (int) round($displayMax),
                    $cap,
                );
        }

        return ['markup' => $doc->isMutated() ? $doc->render() : $markup, 'notes' => $notes];
    }

    /**
     * The page's masthead: its FIRST level-1 heading, or null without one.
     * The blueprint's line target describes this one heading, so no heading
     * below it is line-bounded.
     */
    private static function mastheadIndex(BlockMarkup $doc): ?int
    {
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'heading') {
                continue;
            }
            if ((int) (($doc->attrs($i) ?? [])['level'] ?? 2) === 1) {
                return $i;
            }
        }
        return null;
    }

    /**
     * The largest size at which this heading's longest word fits its measure.
     *
     * Extracted so the promotion below can consult the SAME answer. Promotion
     * writes an explicit size, and the word-fit loop deliberately skips any
     * heading that has one (that skip is what makes the pass idempotent), so a
     * promoted heading would otherwise never be word-checked at all — a hard
     * bypass of the guard BIGR-798/864 exist for. Hero headings are set to
     * `overflow-wrap: normal; hyphens: manual` precisely because this pass is
     * the only guard, and `.hero-composition--layered-poster` clips overflow,
     * so the failure is a headline cut off at the column edge.
     *
     * @param array<mixed> $attrs
     * @return array{cap:?int,word:string,chars:int,uppercase:bool,wordEm:float,measure:float}|null
     *         null when the heading cannot be measured at all; `cap` null when
     *         the word already fits at the display maximum.
     */
    private static function wordFit(
        BlockMarkup $doc,
        int $heading,
        array $theme,
        array $attrs,
        float $displayMax,
    ): ?array {
        $word = self::longestWord($doc->innerHtml($heading));
        if ($word === null) {
            return null;
        }
        $measure = self::measurePx($doc, $heading, $theme);
        if ($measure === null) {
            return null;
        }
        $level = is_numeric($attrs['level'] ?? null) ? (int) $attrs['level'] : 2;
        $uppercase = self::effectiveTransform($attrs, $theme, $level) === 'uppercase';
        $spacingEm = self::effectiveLetterSpacingEm($attrs, $theme, $level);
        $chars = mb_strlen($word);
        $wordEm = $chars * ($uppercase ? self::UPPERCASE_EM : self::MIXED_CASE_EM)
            + max(0, $chars - 1) * $spacingEm;
        if ($wordEm <= 0) {
            return null;
        }

        $available = $measure * self::MEASURE_SAFETY;
        return [
            'cap' => $displayMax * $wordEm <= $available
                ? null
                : (int) floor($available / $wordEm),
            'word' => $word,
            'chars' => $chars,
            'uppercase' => $uppercase,
            'wordEm' => $wordEm,
            'measure' => $measure,
        ];
    }

    /**
     * Raise the hero's one H1 to the masthead preset when the model set it
     * below that, then bound it by the measure and the desktop line target.
     *
     * Only the FIRST level-1 heading is considered: it is the page's masthead,
     * and a hero that somehow carries two H1s has a structural problem this
     * pass is the wrong owner for.
     *
     * Three ways to decline, all of them "the existing choice already works":
     * the heading is already at `display`; it carries an explicit size (an
     * author's pin, or this pass's own output on a re-run, which is what makes
     * it idempotent); or the size it would end up at is no larger than the
     * preset it already has, so promoting would be a demotion in disguise.
     *
     * @param list<int>|null $desktopLineTarget
     * @param list<string> $notes
     */
    private static function promoteMasthead(
        BlockMarkup $doc,
        array $theme,
        float $displayMax,
        ?array $desktopLineTarget,
        array &$notes,
    ): void {
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'heading') {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            if ((int) ($attrs['level'] ?? 2) !== 1) {
                continue;
            }
            // The FIRST h1 is the masthead. If it cannot be edited safely,
            // stop — the next h1 down the page is not a substitute for it.
            if (!$doc->isStructurallySafe($i)) {
                return;
            }
            // An empty headline has no scale worth promoting.
            if (trim(html_entity_decode(strip_tags($doc->innerHtml($i)), ENT_QUOTES | ENT_HTML5)) === '') {
                return;
            }

            $current = $attrs['fontSize'] ?? null;
            $current = is_string($current) && $current !== '' ? $current : null;
            if ($current === self::DISPLAY_SLUG || isset($attrs['style']['typography']['fontSize'])) {
                return;
            }

            // Two independent bounds, and the smaller one wins.
            //
            // The line target keeps the headline inside the blueprint's wrap;
            // the word fit keeps its longest word inside the measure. Promotion
            // writes an explicit size and the word-fit loop skips any heading
            // that has one, so consulting only the line target here would ship
            // a masthead whose longest word overflows a clipped hero — the
            // exact defect BIGR-798/864 exist to prevent.
            if (self::measurePx($doc, $i, $theme) === null) {
                // Neither bound can be computed, so promoting would ship an
                // unbounded display headline nothing has checked. Leave it.
                return;
            }
            $lineCap = self::lineTargetCapPx($doc, $i, $theme, $attrs, $desktopLineTarget, $displayMax);
            $fit = self::wordFit($doc, $i, $theme, $attrs, $displayMax);
            $wordCap = $fit['cap'] ?? null;

            $currentMax = $current === null ? null : self::presetMaxPx($theme, $current);
            $caps = array_values(array_filter(
                [$lineCap, $wordCap],
                static fn (?int $value): bool => $value !== null,
            ));
            $cap = $caps === [] ? null : min($caps);
            if ($cap !== null && $cap < self::MINIMUM_CAP_PX) {
                // No size in this measure satisfies both bounds. The model's
                // smaller preset is the better answer. A below-display
                // heading will not enter the word-fit loop, though, so apply
                // its hyphenation escape here when the current preset would
                // still overflow the unbreakable word.
                if (
                    $fit !== null
                    && $wordCap !== null
                    && $wordCap < self::MINIMUM_CAP_PX
                    && ($currentMax === null || $currentMax > $wordCap)
                ) {
                    self::optIntoHyphenation($doc, $i, $attrs, $fit, $notes);
                }
                return;
            }
            $delivered = $cap === null ? $displayMax : min((float) $cap, $displayMax);

            // Never promote into a smaller rendered size than the model chose.
            if ($currentMax !== null && $delivered <= $currentMax) {
                return;
            }

            // Every preset class must go with the preset attr — WordPress
            // renders `.has-<slug>-font-size` with !important, which beats an
            // inline size — and the block fixer that runs after this step
            // rescues a stale token straight back out of the saved HTML. The
            // class can be present with NO matching attr (the very shape the
            // fixer exists to repair), so this cannot key off `$current`.
            foreach (self::presetSlugs($theme) as $slug) {
                $doc->removeClassTokenInOwnHtml($i, 'has-' . $slug . '-font-size');
            }
            if ($cap !== null && $cap < $displayMax) {
                unset($attrs['fontSize']);
                $attrs = self::withPinnedFontSize(
                    $attrs,
                    'min(var(--wp--preset--font-size--' . self::DISPLAY_SLUG . '), ' . $cap . 'px)',
                );
            } else {
                $attrs['fontSize'] = self::DISPLAY_SLUG;
            }
            $doc->setAttrs($i, $attrs);

            $bound = match (true) {
                $cap === null || $cap >= $displayMax => '',
                $wordCap !== null && $cap === $wordCap && $cap !== $lineCap =>
                    sprintf(", pinned to min(display, %dpx) so '%s' fits the measure", $cap, $fit['word']),
                $wordCap !== null && $cap === $wordCap =>
                    sprintf(', pinned to min(display, %dpx) by the line target and the word fit', $cap),
                default => sprintf(', pinned to min(display, %dpx) by the desktop line target', $cap),
            };
            $notes[] = sprintf(
                'headline scale: hero h1 was set at %s (max %s); promoted to the display preset%s '
                    . '— the display preset is the masthead and nothing else on the page uses it',
                $current ?? 'no preset',
                $currentMax === null ? 'unknown' : (int) round($currentMax) . 'px',
                $bound,
            );
            return;
        }
    }

    /**
     * The largest whole-pixel size at which the headline still wraps inside
     * its desktop line target, or null when the target or the measure cannot
     * be resolved.
     *
     * The line count comes from a greedy word wrap, not from dividing the
     * total run by the number of lines allowed. Real wrapping leaves ragged
     * line ends — "Glass Given a Second Life as Light" packs to 20 characters
     * then 13, not to 17 and 17 — so perfect-packing arithmetic overshoots and
     * hands back a size that needs one more line than the blueprint allows.
     *
     * The answer is deliberately conservative, in two ways that compound:
     * spaces are charged at the full per-character rate, and the theme ships
     * `text-wrap: pretty`, which is not a greedy wrap and which no arithmetic
     * here models. So this returns a size at or below the largest one that
     * actually fits — measured on the delivered cohort it lands 10-30% under.
     * That is the correct direction: the failure this guards against is a
     * headline that overruns its own line target, and a slightly smaller
     * masthead is still a masthead.
     *
     * @param array<mixed> $attrs
     * @param list<int>|null $desktopLineTarget
     */
    private static function lineTargetCapPx(
        BlockMarkup $doc,
        int $heading,
        array $theme,
        array $attrs,
        ?array $desktopLineTarget,
        float $displayMax,
    ): ?int {
        // The blueprint shape is [min, max]; fall back to the only number a
        // malformed target supplies rather than leaving the headline unbounded.
        $maxLines = null;
        foreach (is_array($desktopLineTarget) ? $desktopLineTarget : [] as $value) {
            if (is_numeric($value) && (int) $value >= 1) {
                $maxLines = max((int) $maxLines, (int) $value);
            }
        }
        if ($maxLines === null) {
            return null;
        }
        $measure = self::measurePx($doc, $heading, $theme);
        if ($measure === null) {
            return null;
        }
        $words = preg_split(
            '/\s+/u',
            self::headlineText($doc->innerHtml($heading)),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        if ($words === []) {
            return null;
        }
        $level = is_numeric($attrs['level'] ?? null) ? (int) $attrs['level'] : 2;
        $charEm = self::effectiveTransform($attrs, $theme, $level) === 'uppercase'
            ? self::LINE_UPPERCASE_EM
            : self::LINE_MIXED_CASE_EM;
        $spacingEm = self::effectiveLetterSpacingEm($attrs, $theme, $level);
        if ($charEm <= 0) {
            return null;
        }

        $available = $measure * self::MEASURE_SAFETY;
        // Line count is monotonic in size, so a binary search finds the
        // largest fitting whole-pixel size without trusting an LLM-authored
        // preset maximum as a loop bound. The floor is the smaller of the pin
        // threshold and the preset's own maximum, so a theme whose display
        // preset tops out below the threshold is still evaluated instead of
        // reporting "nothing fits".
        $maximum = (int) floor($displayMax);
        $floor = min(self::MINIMUM_CAP_PX, $maximum);
        if (self::wrappedLines($words, $charEm, $spacingEm, (float) $floor, $available) > (int) $maxLines) {
            return $floor - 1;
        }

        $best = $floor;
        $low = $floor + 1;
        $high = $maximum;
        while ($low <= $high) {
            $size = $low + intdiv($high - $low, 2);
            if (self::wrappedLines($words, $charEm, $spacingEm, (float) $size, $available) <= (int) $maxLines) {
                $best = $size;
                $low = $size + 1;
            } else {
                $high = $size - 1;
            }
        }
        return $best;
    }

    /**
     * Greedy word wrap: how many lines the headline takes at one size.
     *
     * A word wider than the whole measure still takes its own line — this
     * counts lines, and the word-fit pass is what stops a word overflowing.
     *
     * @param list<string> $words
     */
    private static function wrappedLines(
        array $words,
        float $charEm,
        float $spacingEm,
        float $sizePx,
        float $available,
    ): int {
        $width = static fn (int $chars): float =>
            $chars * $charEm * $sizePx + max(0, $chars - 1) * $spacingEm * $sizePx;

        $space = $width(1);
        $lines = 1;
        $current = null;
        foreach ($words as $word) {
            $wordPx = $width(mb_strlen($word));
            if ($current === null) {
                $current = $wordPx;
                continue;
            }
            if ($current + $space + $wordPx <= $available) {
                $current += $space + $wordPx;
                continue;
            }
            $lines++;
            $current = $wordPx;
        }
        return $lines;
    }

    /** The heading's plain text, with line breaks reduced to one space. */
    private static function headlineText(string $innerHtml): string
    {
        $text = preg_replace('/<br\s*\/?>/i', ' ', $innerHtml) ?? $innerHtml;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Every font-size preset slug the theme defines.
     *
     * @return list<string>
     */
    private static function presetSlugs(array $theme): array
    {
        $slugs = [];
        foreach ((array) ($theme['settings']['typography']['fontSizes'] ?? []) as $preset) {
            $slug = is_array($preset) ? ($preset['slug'] ?? null) : null;
            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }
        return $slugs;
    }

    /** One named preset's largest resolvable size in px, or null. */
    public static function presetMaxPx(array $theme, string $slug): ?float
    {
        foreach ((array) ($theme['settings']['typography']['fontSizes'] ?? []) as $preset) {
            if (!is_array($preset) || ($preset['slug'] ?? null) !== $slug) {
                continue;
            }
            $size = $preset['size'] ?? null;
            return is_string($size) ? self::cssMaxPx($size) : null;
        }
        return null;
    }

    /**
     * Write the explicit size, tolerating a model-authored `style` (or
     * `style.typography`) that is not an array. Reading through one is safe
     * (`??` on a scalar offset yields null), but WRITING through it is a
     * TypeError that takes the whole build down, and a scalar cannot have
     * held a font size for WordPress to render in the first place.
     *
     * @param array<mixed> $attrs
     * @return array<mixed>
     */
    private static function withPinnedFontSize(array $attrs, string $size): array
    {
        if (!is_array($attrs['style'] ?? null)) {
            unset($attrs['style']);
        }
        if (!is_array($attrs['style']['typography'] ?? null)) {
            unset($attrs['style']['typography']);
        }
        $attrs['style']['typography']['fontSize'] = $size;
        return $attrs;
    }

    /** @return list<string> the block's own class tokens */
    private static function classes(array $attrs): array
    {
        return preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Add the reviewed last-resort word-break hook exactly once.
     *
     * @param array<mixed> $attrs
     * @param array{cap:?int,word:string,chars:int,uppercase:bool,wordEm:float,measure:float} $fit
     * @param list<string> $notes
     */
    private static function optIntoHyphenation(
        BlockMarkup $doc,
        int $heading,
        array $attrs,
        array $fit,
        array &$notes,
    ): void {
        $classes = self::classes($attrs);
        if (in_array(self::HYPHENATE_CLASS, $classes, true)) {
            return;
        }
        $classes[] = self::HYPHENATE_CLASS;
        $attrs['className'] = implode(' ', $classes);
        $doc->setAttrs($heading, $attrs);
        $notes[] = sprintf(
            "headline word-fit: '%s' (%d chars) fits no size above %dpx in the %dpx measure; "
                . 'heading opted into hyphenation instead of a pinned size',
            $fit['word'],
            $fit['chars'],
            self::MINIMUM_CAP_PX,
            (int) round($fit['measure']),
        );
    }

    /** The heading's longest word, or null when it has no text. */
    private static function longestWord(string $innerHtml): ?string
    {
        // A line break is a word boundary: stripping it bare would fuse the
        // words on either side into one impossibly long token.
        $text = preg_replace('/<br\s*\/?>/i', ' ', $innerHtml) ?? $innerHtml;
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
        $longest = null;
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if ($longest === null || mb_strlen($word) > mb_strlen($longest)) {
                $longest = $word;
            }
        }
        return $longest;
    }

    /** The display preset's largest resolvable size in px, or null. */
    public static function displayMaxPx(array $theme): ?float
    {
        return self::presetMaxPx($theme, self::DISPLAY_SLUG);
    }

    /** Resolves px/rem literals and the maximum term of a clamp(). */
    private static function cssMaxPx(string $value): ?float
    {
        $value = trim($value);
        if (preg_match('/^clamp\((.*)\)$/is', $value, $m)) {
            $terms = self::splitTopLevel($m[1]);
            return count($terms) === 3 ? self::cssMaxPx($terms[2]) : null;
        }
        return self::lengthPx($value);
    }

    /** A bare px/rem length in px, or null for anything else. */
    private static function lengthPx(string $value): ?float
    {
        $value = trim($value);
        if (preg_match('/^([\d.]+)px$/i', $value, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/^([\d.]+)rem$/i', $value, $m)) {
            return (float) $m[1] * 16.0;
        }
        return null;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $args): array
    {
        $terms = [];
        $depth = 0;
        $current = '';
        foreach (str_split($args) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $terms[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $terms[] = trim($current);
        }
        return $terms;
    }

    /**
     * The width the headline actually gets, walked outward from the heading:
     * the innermost box that states a width in px wins, narrowed by every
     * percentage column between it and the heading. Falls back to the theme's
     * global content size; null when nothing resolves.
     *
     * A group's own `flexSize` counts: it is the copy width the composition
     * asked for, and it still describes the intended column even where core
     * only honours it inside a flex parent.
     */
    private static function measurePx(BlockMarkup $doc, int $heading, array $theme): ?float
    {
        $share = 1.0;
        // A constrained ancestor with NO contentSize of its own still
        // constrains: core caps its children at the theme's global content
        // size. atlas walked past its copy group to the cover's poster-wide
        // 1560px contentSize while the group rendered the h1 at 960px, so
        // the line bound cleared a measure half again as wide as the real
        // one (BIGR-951). Record the cap such a group imposes at its depth,
        // and never return a measure above the tightest one seen.
        $global = $theme['settings']['layout']['contentSize'] ?? null;
        $globalPx = is_string($global) ? self::lengthPx($global) : null;
        $globalCap = null;
        for ($i = $doc->parent($heading); $i !== null; $i = $doc->parent($i)) {
            $attrs = $doc->attrs($i) ?? [];
            $name = $doc->name($i);

            $layout = $attrs['layout'] ?? null;
            if (is_array($layout) && ($layout['type'] ?? null) === 'constrained') {
                $size = $layout['contentSize'] ?? null;
                $px = is_string($size) ? self::lengthPx($size) : null;
                if ($px !== null) {
                    $measure = $px * $share;
                    return $globalCap === null ? $measure : min($measure, $globalCap);
                }
                if ($globalPx !== null) {
                    $capHere = $globalPx * $share;
                    $globalCap = $globalCap === null ? $capHere : min($globalCap, $capHere);
                }
            }

            $flexSize = $attrs['style']['layout']['flexSize'] ?? null;
            $px = is_string($flexSize) ? self::lengthPx($flexSize) : null;
            if ($px !== null) {
                $measure = $px * $share;
                return $globalCap === null ? $measure : min($measure, $globalCap);
            }

            if ($name === 'column') {
                $width = $attrs['width'] ?? null;
                if (is_string($width)) {
                    $px = self::lengthPx($width);
                    if ($px !== null) {
                        // A px-width column wider than a constrained group it
                        // holds does not widen that group: core still caps the
                        // group's children at the global content size, so the
                        // cap recorded above binds here too (BIGR-951 review
                        // follow-up).
                        $measure = $px * $share;
                        return $globalCap === null ? $measure : min($measure, $globalCap);
                    }
                    if (preg_match('/^([\d.]+)%$/', trim($width), $m) && (float) $m[1] > 0) {
                        $share *= (float) $m[1] / 100.0;
                    }
                }
            } elseif ($name === 'media-text') {
                // The copy half of a media-text: core's default split is 50%,
                // and mediaWidth names the media side's percentage.
                $mediaWidth = $attrs['mediaWidth'] ?? null;
                $media = is_numeric($mediaWidth) ? (float) $mediaWidth : 50.0;
                if ($media > 0 && $media < 100) {
                    $share *= (100.0 - $media) / 100.0;
                }
            }
        }

        if ($globalPx === null) {
            return null;
        }
        $measure = $globalPx * $share;
        return $globalCap === null ? $measure : min($measure, $globalCap);
    }

    private static function effectiveTransform(array $attrs, array $theme, int $level): ?string
    {
        $own = $attrs['style']['typography']['textTransform'] ?? null;
        if (is_string($own) && $own !== '') {
            return $own;
        }
        foreach (self::typographySources($theme, $level) as $typography) {
            $transform = $typography['textTransform'] ?? null;
            if (is_string($transform) && $transform !== '') {
                return $transform;
            }
        }
        return null;
    }

    private static function effectiveLetterSpacingEm(array $attrs, array $theme, int $level): float
    {
        $candidates = [$attrs['style']['typography']['letterSpacing'] ?? null];
        foreach (self::typographySources($theme, $level) as $typography) {
            $candidates[] = $typography['letterSpacing'] ?? null;
        }
        foreach ($candidates as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            if (preg_match('/^(-?[\d.]+)em$/i', $value, $m)) {
                // Tighter tracking only helps the fit; never widen for it.
                return max(0.0, (float) $m[1]);
            }
            if (str_starts_with($value, '-')) {
                return 0.0;
            }
            // A non-em unit (px on a fluid heading is authoring noise) still
            // means "spacing exists": estimate conservatively rather than
            // treating it as zero.
            return self::UNKNOWN_SPACING_EM;
        }
        return 0.0;
    }

    /** @return list<array<string,mixed>> most-specific first */
    private static function typographySources(array $theme, int $level): array
    {
        $styles = (array) ($theme['styles'] ?? []);
        $sources = [];
        foreach ([
            $styles['blocks']['core/heading']['typography'] ?? null,
            $styles['elements']['h' . $level]['typography'] ?? null,
            $styles['elements']['heading']['typography'] ?? null,
            $styles['typography'] ?? null,
        ] as $typography) {
            if (is_array($typography)) {
                $sources[] = $typography;
            }
        }
        return $sources;
    }
}
