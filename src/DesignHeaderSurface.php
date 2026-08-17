<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The palette slugs a design's own `header` rule asks for.
 *
 * A generated theme can only paint the header with one of five palette slugs,
 * so an authored colour is honored when the palette already contains it and
 * ignored otherwise. The match is a recognition test, not a snap-to-nearest:
 * with five slugs something is always "nearest", so an unbounded nearest
 * would repaint every header whose colour the palette does not carry.
 */
final class DesignHeaderSurface
{
    /**
     * CIELAB dE76 window for "the palette already has this colour". The
     * just-noticeable difference is ~2.3; 3.0 admits encoding noise from
     * var() resolution and colour round-trips without admitting a colour a
     * designer would see as different. calm-lantern's authored #2E0B5A sits
     * dE 34.6 from its nearest slug and is correctly refused.
     */
    public const MATCH_DELTA_E = 3.0;

    /**
     * The stacked pair a design authors, each side null when the design does
     * not author it or authors a colour the palette does not carry.
     *
     * `authored_background` separates those two cases, which the caller has to
     * tell apart: ink chosen against a surface the palette refused says nothing
     * about the surface that gets painted instead.
     *
     * @param array<string,string> $palette slug => hex
     * @return array{protection:?string,foreground:?string,authored_background:bool}
     */
    public static function stackedPair(?string $css, array $palette): array
    {
        if ($css === null || trim($css) === '') {
            return ['protection' => null, 'foreground' => null, 'authored_background' => false];
        }
        $authored = self::authored($css);
        return [
            'protection' => self::slugFor($authored['background'], $palette),
            'foreground' => self::slugFor($authored['text'], $palette),
            'authored_background' => $authored['background'] !== null,
        ];
    }

    /**
     * The `background` and `color` a design's own `header` rule declares,
     * resolved through any var() chain to a concrete CSS colour.
     *
     * @return array{background:?string,text:?string}
     */
    public static function authored(string $css): array
    {
        $css = self::withoutComments($css);
        $vars = self::rootVars($css);
        $background = null;
        $text = null;
        // Decoration count of the rule that last set each property. A more
        // decorated rule never overrides a plainer one, so a variant cannot
        // take the resting band from the rule that states it; equal
        // decoration falls back to last-wins, matching the cascade.
        $backgroundRank = PHP_INT_MAX;
        $textRank = PHP_INT_MAX;
        foreach (self::rules($css) as [$selector, $body]) {
            $rank = self::headerRootRank($selector);
            if ($rank === null) {
                continue;
            }
            if ($rank <= $backgroundRank
                && preg_match_all('/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $body, $m)
            ) {
                $background = self::resolveVar(trim((string) end($m[1])), $vars);
                $backgroundRank = $rank;
            }
            if ($rank <= $textRank
                && preg_match_all('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $body, $m)
            ) {
                $text = self::resolveVar(trim((string) end($m[1])), $vars);
                $textRank = $rank;
            }
        }
        return ['background' => $background, 'text' => $text];
    }

    /**
     * The palette slug carrying this colour, or null when none is within the
     * match window. Restricted to HeaderBehavior::SURFACES: a slug outside
     * that vocabulary has no class in the header kit, so a derived surface
     * naming one would never reach the rendered header.
     *
     * @param array<string,string> $palette slug => hex
     */
    public static function slugFor(?string $color, array $palette): ?string
    {
        $rgb = $color === null ? null : ContrastMath::hexToRgb($color);
        if ($rgb === null) {
            return null;
        }
        $lab = self::toLab($rgb);
        $best = null;
        $bestDelta = INF;
        foreach ($palette as $slug => $hex) {
            $slug = (string) $slug;
            if (!in_array($slug, HeaderBehavior::SURFACES, true)) {
                continue;
            }
            $candidate = ContrastMath::hexToRgb((string) $hex);
            if ($candidate === null) {
                continue;
            }
            $delta = self::deltaE($lab, self::toLab($candidate));
            // Strict `<` leaves a tie to the palette's own order rather than
            // to iteration luck, so the mapping stays deterministic.
            if ($delta < $bestDelta) {
                $best = $slug;
                $bestDelta = $delta;
            }
        }
        return $bestDelta <= self::MATCH_DELTA_E ? $best : null;
    }

    /**
     * Custom properties from top-level `:root` blocks only. A `:root` inside
     * `@media (prefers-color-scheme: dark)` states the colour for a mode the
     * band may never render in, and merging it would let the dark value win
     * on last-wins ordering.
     *
     * @return array<string,string> custom property => declared value
     */
    private static function rootVars(string $css): array
    {
        $out = [];
        foreach (self::rules($css) as [$selector, $body]) {
            if (trim($selector) !== ':root') {
                continue;
            }
            foreach (explode(';', $body) as $declaration) {
                if (preg_match('/^\s*(--[\w-]+)\s*:\s*(.+)$/s', $declaration, $m)) {
                    $out[trim($m[1])] = trim($m[2]);
                }
            }
        }
        return $out;
    }

    /**
     * Comments removed, without treating a comment marker inside a string
     * value as one.
     *
     * A regex cannot do this. One rule whose content property holds an open
     * marker and a later rule whose content holds a close marker make it
     * delete every rule in between, which is how a quoted marker used to
     * choose the band.
     *
     * This and rules() below share one convention: a quoted span ends at its
     * matching quote OR at a raw newline, because CSS forbids newlines inside
     * strings. Without the newline terminator a lone apostrophe, as in
     * `font-family:Foo` + apostrophe + `s Font`, opens a span that runs to end
     * of file and silently discards every rule after it.
     */
    private static function withoutComments(string $css): string
    {
        $out = '';
        $length = strlen($css);
        $quote = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                $out .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $out .= $css[$i + 1];
                    $i++;
                } elseif ($char === $quote || $char === "\n" || $char === "\r") {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $out .= $char;
                continue;
            }
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $i = $end + 1;
                continue;
            }
            $out .= $char;
        }
        return $out;
    }

    /**
     * Top-level `selector { declarations }` pairs, in source order.
     *
     * Rules nested in an at-rule are deliberately skipped. `@media print`
     * states the header's paper colour and `@media (max-width: …)` states a
     * narrow-viewport variant; treating either as the band would repaint the
     * desktop header from a rule that never applies to it. Missing a design
     * whose only header rule is conditional costs nothing — the contract
     * keeps its reviewed default.
     *
     * Braces inside a quoted value are literal text, not structure. Counting
     * them desynchronises every following rule: `content:"}"` alone silently
     * loses the header surface, and a whole `header{…}` written inside a
     * string would be read as a real rule.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function rules(string $css): array
    {
        $out = [];
        $length = strlen($css);
        $depth = 0;
        $start = 0;
        $quote = null;
        $prelude = '';
        $bodyStart = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote || $char === "\n" || $char === "\r") {
                    // CSS forbids a raw newline inside a string, so one ends
                    // the span. A lone apostrophe then costs one declaration
                    // instead of every rule to end of file.
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '{') {
                if ($depth === 0) {
                    $prelude = trim((string) preg_replace('/\s+/', ' ', substr($css, $start, $i - $start)));
                    $bodyStart = $i + 1;
                }
                $depth++;
                continue;
            }
            if ($char !== '}') {
                continue;
            }
            if ($depth === 0) {
                // A stray close brace. Resynchronise and keep reading: letting
                // one of them abandon the rest of the stylesheet loses every
                // later rule, which is worse than the typo that caused it.
                $start = $i + 1;
                continue;
            }
            $depth--;
            if ($depth === 0) {
                if ($prelude !== '' && !str_starts_with($prelude, '@')) {
                    $out[] = [$prelude, substr($css, $bodyStart, $i - $bodyStart)];
                }
                $start = $i + 1;
            }
        }
        return $out;
    }

    /**
     * How plain a selector's claim on the site `<header>` is, or null when it
     * has none. Zero is a bare `header`; each extra class adds one.
     *
     * `header` and `header.site-header` qualify. `header nav` and
     * `.site-header .brand` do not, because they paint something inside it.
     * Neither does `article header` or `main > header`: a nested content
     * header is a different element that happens to share the tag name.
     *
     * State and variant selectors are refused outright rather than ranked.
     * `header:hover`, `header:focus-within` and `header[data-open]` describe
     * a header in a condition, not the header at rest, and `header::after`
     * paints decoration over the band rather than the band. Reading any of
     * them as the resting surface is how a design that ships its own sticky
     * treatment loses its resting colour to its scrolled one.
     *
     * The rank exists for the same reason: a design may state the band on
     * `header` and a variant on `header.is-scrolled`, and the plainer rule
     * wins regardless of source order.
     *
     * That deliberately contradicts the cascade when the decoration is an
     * identity rather than a state: CSS gives
     * `header{…} header.site-header{…}` to the second rule on both
     * specificity and order, and this gives it to the first. The two are
     * indistinguishable as tokens — `.site-header` and `.is-scrolled` are
     * both just classes — so no syntax-only rule reads both patterns
     * correctly, and honouring specificity would reopen exactly the defect
     * the rank exists to close. The branch that protects a design shipping
     * its own sticky treatment is the one worth having.
     */
    private static function headerRootRank(string $selector): ?int
    {
        $best = null;
        foreach (explode(',', $selector) as $one) {
            $one = trim($one);
            if ($one === '' || str_starts_with($one, '@')) {
                continue;
            }
            $compounds = preg_split('/\s*[\s>+~]\s*/', $one) ?: [];
            // The element must be the subject of the whole selector AND have
            // no ancestor part, so only a top-level `<header>` qualifies.
            if (count($compounds) !== 1) {
                continue;
            }
            $only = trim((string) $compounds[0]);
            if ($only === '' || !preg_match('/^header(?![\w-])/i', $only)) {
                continue;
            }
            // Any pseudo, attribute filter or other qualifier makes it a
            // conditional rule rather than a statement about the band.
            if (preg_match('/[:\[]/', $only)) {
                continue;
            }
            $rank = substr_count($only, '.');
            if ($best === null || $rank < $best) {
                $best = $rank;
            }
        }
        return $best;
    }

    private static function resolveVar(?string $value, array $vars, int $depth = 0): ?string
    {
        if ($value === null || $depth > 8) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^var\(\s*(--[\w-]+)\s*(?:,\s*(.+))?\)$/s', $value, $m)) {
            return $value;
        }
        if (isset($vars[$m[1]])) {
            return self::resolveVar($vars[$m[1]], $vars, $depth + 1);
        }
        return isset($m[2]) ? self::resolveVar($m[2], $vars, $depth + 1) : null;
    }

    /**
     * sRGB to CIELAB (D65). Euclidean RGB distance is unusable here: it ranks
     * near-black above a brighter violet for a deep-violet input, because
     * dark colours crowd together in RGB.
     *
     * @param array{0:int,1:int,2:int} $rgb
     * @return array{0:float,1:float,2:float}
     */
    private static function toLab(array $rgb): array
    {
        $linear = static function (int $channel): float {
            $c = $channel / 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $r = $linear($rgb[0]);
        $g = $linear($rgb[1]);
        $b = $linear($rgb[2]);
        $x = ($r * 0.4124564 + $g * 0.3575761 + $b * 0.1804375) / 0.95047;
        $y = ($r * 0.2126729 + $g * 0.7151522 + $b * 0.0721750);
        $z = ($r * 0.0193339 + $g * 0.1191920 + $b * 0.9503041) / 1.08883;
        $f = static fn (float $t): float => $t > 0.008856 ? $t ** (1 / 3) : (7.787 * $t + 16 / 116);
        $fx = $f($x);
        $fy = $f($y);
        $fz = $f($z);
        return [116 * $fy - 16, 500 * ($fx - $fy), 200 * ($fy - $fz)];
    }

    /**
     * @param array{0:float,1:float,2:float} $a
     * @param array{0:float,1:float,2:float} $b
     */
    private static function deltaE(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }
}
