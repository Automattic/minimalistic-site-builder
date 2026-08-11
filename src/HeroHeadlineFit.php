<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Guarantees the hero headline's longest word fits its copy measure.
 *
 * The display preset is sized by one model call and the hero headline is
 * written by another, so nothing stops a long brand word ("ELECTRONIC")
 * from outgrowing the copy column at the preset's desktop maximum. When
 * that happens the CSS last-resort (`overflow-wrap: break-word`) snaps the
 * word mid-line with no hyphen on browsers without hyphenation
 * dictionaries — a broken first screen (BIGR-798).
 *
 * This pass runs where both facts are finally known (the delivered hero
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
     * The stylesheet's hyphenation hook (ScaffoldThemeStep). Hero headlines
     * wrap whole words by default — blanket `hyphens: auto` hyphenates
     * ordinary words at ordinary line breaks — so hyphenation is opted into
     * per heading, only where no pinnable size fits.
     */
    private const HYPHENATE_CLASS = 'headline-hyphenate';

    /** @return array{markup:string, notes:list<string>} */
    public static function apply(string $markup, array $theme): array
    {
        if (!str_contains($markup, '"fontSize":"display"')) {
            return ['markup' => $markup, 'notes' => []];
        }
        $displayMax = self::displayMaxPx($theme);
        if ($displayMax === null) {
            return ['markup' => $markup, 'notes' => []];
        }
        $doc = BlockMarkup::parse($markup);
        if ($doc->hasMismatchedDelimiters() || $doc->hasMalformedDelimiters()) {
            return ['markup' => $markup, 'notes' => []];
        }

        $notes = [];
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
        foreach ((array) ($theme['settings']['typography']['fontSizes'] ?? []) as $preset) {
            if (!is_array($preset) || ($preset['slug'] ?? null) !== 'display') {
                continue;
            }
            $size = $preset['size'] ?? null;
            return is_string($size) ? self::cssMaxPx($size) : null;
        }
        return null;
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
