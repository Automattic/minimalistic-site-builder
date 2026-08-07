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
 * markup and theme.json): it estimates the widest single word of every
 * display-size hero heading with a per-glyph advance table, and when that
 * word cannot fit the heading's constrained measure at the preset's
 * maximum, it pins the heading to
 * `min(var(--wp--preset--font-size--display), <cap>px)` — fluid behaviour
 * below the cap is untouched, and headings whose words already fit are
 * left byte-identical. The estimate is deliberately a little wide, so a
 * real font that is narrower than the table only makes the fit safer; the
 * CSS hyphenation guard remains the last resort for fonts wider than any
 * table can anticipate.
 */
final class HeroHeadlineFit
{
    /**
     * Generic grotesque advance widths in em (slightly wide of most text
     * faces so estimates err toward fitting).
     */
    private const UPPER = [
        'A' => .70, 'B' => .70, 'C' => .72, 'D' => .74, 'E' => .64, 'F' => .60,
        'G' => .78, 'H' => .74, 'I' => .28, 'J' => .52, 'K' => .70, 'L' => .58,
        'M' => .86, 'N' => .74, 'O' => .78, 'P' => .66, 'Q' => .78, 'R' => .70,
        'S' => .66, 'T' => .62, 'U' => .72, 'V' => .68, 'W' => .96, 'X' => .66,
        'Y' => .66, 'Z' => .62,
    ];
    private const LOWER = [
        'a' => .56, 'b' => .58, 'c' => .52, 'd' => .58, 'e' => .56, 'f' => .30,
        'g' => .58, 'h' => .56, 'i' => .24, 'j' => .24, 'k' => .52, 'l' => .24,
        'm' => .86, 'n' => .56, 'o' => .58, 'p' => .58, 'q' => .58, 'r' => .36,
        's' => .50, 't' => .30, 'u' => .56, 'v' => .50, 'w' => .74, 'x' => .50,
        'y' => .50, 'z' => .50,
    ];
    private const DIGIT_EM = .58;
    private const OTHER_EM = .60;

    /** The measure keeps a small margin for the copy wrapper's own padding. */
    private const MEASURE_SAFETY = 0.96;

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
            $text = trim(html_entity_decode(strip_tags($doc->innerHtml($i)), ENT_QUOTES | ENT_HTML5));
            if ($text === '') {
                continue;
            }
            $level = is_numeric($attrs['level'] ?? null) ? (int) $attrs['level'] : 2;
            $uppercase = self::effectiveTransform($attrs, $theme, $level) === 'uppercase';
            $spacingEm = self::effectiveLetterSpacingEm($attrs, $theme, $level);
            $measure = self::constrainedMeasurePx($doc, $i, $theme);
            if ($measure === null) {
                continue;
            }

            $widest = null;
            foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $em = self::wordEm($uppercase ? mb_strtoupper($word) : $word, $spacingEm);
                if ($widest === null || $em > $widest['em']) {
                    $widest = ['word' => $word, 'em' => $em];
                }
            }
            if ($widest === null || $widest['em'] <= 0) {
                continue;
            }

            $available = $measure * self::MEASURE_SAFETY;
            if ($displayMax * $widest['em'] <= $available) {
                continue;
            }
            $cap = (int) floor($available / $widest['em']);
            if ($cap < 1) {
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
                "headline word-fit: '%s' (~%.2fem%s) cannot fit the %dpx measure at the display maximum "
                    . '%dpx; heading pinned to min(display, %dpx)',
                $widest['word'],
                $widest['em'],
                $uppercase ? ', uppercase' : '',
                $measure,
                $displayMax,
                $cap,
            );
        }

        return ['markup' => $doc->isMutated() ? $doc->render() : $markup, 'notes' => $notes];
    }

    /** Estimated advance width of one word in em, including letter spacing. */
    private static function wordEm(string $word, float $spacingEm): float
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $em = 0.0;
        foreach ($chars as $char) {
            $em += self::UPPER[$char]
                ?? self::LOWER[$char]
                ?? (ctype_digit($char) ? self::DIGIT_EM : self::OTHER_EM);
        }
        $count = count($chars);
        if ($count > 1) {
            $em += ($count - 1) * $spacingEm;
        }
        return $em;
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
     * The nearest constrained ancestor's px contentSize, else the theme's
     * global content size; null when neither resolves to px.
     */
    private static function constrainedMeasurePx(BlockMarkup $doc, int $heading, array $theme): ?float
    {
        for ($i = $doc->parent($heading); $i !== null; $i = $doc->parent($i)) {
            $attrs = $doc->attrs($i) ?? [];
            $layout = $attrs['layout'] ?? null;
            if (!is_array($layout) || ($layout['type'] ?? null) !== 'constrained') {
                continue;
            }
            $size = $layout['contentSize'] ?? null;
            if (is_string($size) && preg_match('/^([\d.]+)px$/i', trim($size), $m)) {
                return (float) $m[1];
            }
        }
        $global = $theme['settings']['layout']['contentSize'] ?? null;
        if (is_string($global) && preg_match('/^([\d.]+)px$/i', trim($global), $m)) {
            return (float) $m[1];
        }
        return null;
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
            if (preg_match('/^(-?[\d.]+)em$/i', trim($value), $m)) {
                return (float) $m[1];
            }
            // A non-em unit (px on a fluid heading is authoring noise) still
            // means "spacing exists": estimate conservatively rather than
            // treating it as zero.
            return 0.03;
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
