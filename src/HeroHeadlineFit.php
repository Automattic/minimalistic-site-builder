<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Guarantees the hero headline is set at the masthead scale, and that its
 * longest word fits its copy measure.
 *
 * Two passes, in that order.
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
     *        [min, max] headline line target; without it a promoted heading
     *        gets the plain preset and only the word-fit pass bounds it.
     * @return array{markup:string, notes:list<string>}
     */
    public static function apply(string $markup, array $theme, ?array $desktopLineTarget = null): array
    {
        $displayMax = self::displayMaxPx($theme);
        if ($displayMax === null) {
            return ['markup' => $markup, 'notes' => []];
        }
        $doc = BlockMarkup::parse($markup);
        if ($doc->hasMismatchedDelimiters() || $doc->hasMalformedDelimiters()) {
            return ['markup' => $markup, 'notes' => []];
        }

        $notes = [];
        self::promoteMasthead($doc, $theme, $displayMax, $desktopLineTarget, $notes);
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
            $word = self::longestWord($doc->innerHtml($i));
            if ($word === null) {
                continue;
            }
            $measure = self::measurePx($doc, $i, $theme);
            if ($measure === null) {
                continue;
            }
            $level = is_numeric($attrs['level'] ?? null) ? (int) $attrs['level'] : 2;
            $uppercase = self::effectiveTransform($attrs, $theme, $level) === 'uppercase';
            $spacingEm = self::effectiveLetterSpacingEm($attrs, $theme, $level);
            $chars = mb_strlen($word);
            $wordEm = $chars * ($uppercase ? self::UPPERCASE_EM : self::MIXED_CASE_EM)
                + max(0, $chars - 1) * $spacingEm;
            if ($wordEm <= 0) {
                continue;
            }

            $available = $measure * self::MEASURE_SAFETY;
            if ($displayMax * $wordEm <= $available) {
                continue;
            }
            $cap = (int) floor($available / $wordEm);
            if ($cap < self::MINIMUM_CAP_PX) {
                // A word this long in a measure this narrow has no size worth
                // pinning. This is the one case where a hyphen beats a bare
                // mid-word snap, so opt this heading — and only this heading —
                // into the stylesheet's hyphenation hook.
                $classes = self::classes($attrs);
                if (!in_array(self::HYPHENATE_CLASS, $classes, true)) {
                    $classes[] = self::HYPHENATE_CLASS;
                    $attrs['className'] = implode(' ', $classes);
                    $doc->setAttrs($i, $attrs);
                    $notes[] = sprintf(
                        "headline word-fit: '%s' (%d chars) fits no size above %dpx in the %dpx measure; "
                            . 'heading opted into hyphenation instead of a pinned size',
                        $word,
                        $chars,
                        self::MINIMUM_CAP_PX,
                        (int) round($measure),
                    );
                }
                continue;
            }
            // The preset class must go with the preset attr: WordPress
            // renders `.has-display-font-size` with !important, which would
            // beat the pinned inline size. The min() keeps the preset var,
            // so fluid behaviour below the cap is unchanged.
            unset($attrs['fontSize']);
            $attrs['style']['typography']['fontSize'] =
                'min(var(--wp--preset--font-size--display), ' . $cap . 'px)';
            $doc->setAttrs($i, $attrs);
            $doc->removeClassTokenInOwnHtml($i, 'has-display-font-size');
            $notes[] = sprintf(
                "headline word-fit: '%s' (%d chars%s, ~%.2fem) cannot fit the %dpx measure at the display "
                    . 'maximum %dpx; heading pinned to min(display, %dpx)',
                $word,
                $chars,
                $uppercase ? ', uppercase' : '',
                $wordEm,
                (int) round($measure),
                (int) round($displayMax),
                $cap,
            );
        }

        return ['markup' => $doc->isMutated() ? $doc->render() : $markup, 'notes' => $notes];
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
            if ($doc->name($i) !== 'heading' || !$doc->isStructurallySafe($i)) {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            if ((int) ($attrs['level'] ?? 2) !== 1) {
                continue;
            }

            $current = $attrs['fontSize'] ?? null;
            $current = is_string($current) && $current !== '' ? $current : null;
            if ($current === self::DISPLAY_SLUG || isset($attrs['style']['typography']['fontSize'])) {
                return;
            }

            $cap = self::lineTargetCapPx($doc, $i, $theme, $attrs, $desktopLineTarget, $displayMax);
            if ($cap !== null && $cap < self::MINIMUM_CAP_PX) {
                // No size in this measure keeps the headline inside its own
                // line target. The model's smaller preset is the better answer.
                return;
            }
            $delivered = $cap === null ? $displayMax : min((float) $cap, $displayMax);

            // Never promote into a smaller rendered size than the model chose.
            $currentMax = $current === null ? null : self::presetMaxPx($theme, $current);
            if ($currentMax !== null && $delivered <= $currentMax) {
                return;
            }

            if ($current !== null) {
                // The preset class must go with the preset attr — WordPress
                // renders `.has-<slug>-font-size` with !important — and the
                // block fixer that runs after this step would otherwise rescue
                // the stale token straight back out of the saved HTML.
                $doc->removeClassTokenInOwnHtml($i, 'has-' . $current . '-font-size');
            }
            if ($cap !== null && $cap < $displayMax) {
                unset($attrs['fontSize']);
                $attrs['style']['typography']['fontSize'] =
                    'min(var(--wp--preset--font-size--' . self::DISPLAY_SLUG . '), ' . $cap . 'px)';
            } else {
                $attrs['fontSize'] = self::DISPLAY_SLUG;
            }
            $doc->setAttrs($i, $attrs);

            $notes[] = sprintf(
                'headline scale: hero h1 was set at %s (max %s); promoted to the display preset%s '
                    . '— the display preset is the masthead and nothing else on the page uses it',
                $current ?? 'no preset',
                $currentMax === null ? 'unknown' : (int) round($currentMax) . 'px',
                $cap !== null && $cap < $displayMax
                    ? sprintf(', pinned to min(display, %dpx) by the desktop line target', $cap)
                    : '',
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
        $maxLines = is_array($desktopLineTarget) ? ($desktopLineTarget[1] ?? null) : null;
        if (!is_numeric($maxLines) || (int) $maxLines < 1) {
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
        // Line count is monotonic in size, so the first size that fits, walking
        // down from the preset maximum, is the largest one that fits.
        for ($size = (int) floor($displayMax); $size >= self::MINIMUM_CAP_PX; $size--) {
            if (self::wrappedLines($words, $charEm, $spacingEm, (float) $size, $available) <= (int) $maxLines) {
                return $size;
            }
        }
        return self::MINIMUM_CAP_PX - 1;
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

    /** @return list<string> the block's own class tokens */
    private static function classes(array $attrs): array
    {
        return preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
        for ($i = $doc->parent($heading); $i !== null; $i = $doc->parent($i)) {
            $attrs = $doc->attrs($i) ?? [];
            $name = $doc->name($i);

            $layout = $attrs['layout'] ?? null;
            if (is_array($layout) && ($layout['type'] ?? null) === 'constrained') {
                $size = $layout['contentSize'] ?? null;
                $px = is_string($size) ? self::lengthPx($size) : null;
                if ($px !== null) {
                    return $px * $share;
                }
            }

            $flexSize = $attrs['style']['layout']['flexSize'] ?? null;
            $px = is_string($flexSize) ? self::lengthPx($flexSize) : null;
            if ($px !== null) {
                return $px * $share;
            }

            if ($name === 'column') {
                $width = $attrs['width'] ?? null;
                if (is_string($width)) {
                    $px = self::lengthPx($width);
                    if ($px !== null) {
                        return $px * $share;
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

        $global = $theme['settings']['layout']['contentSize'] ?? null;
        $px = is_string($global) ? self::lengthPx($global) : null;
        return $px === null ? null : $px * $share;
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
