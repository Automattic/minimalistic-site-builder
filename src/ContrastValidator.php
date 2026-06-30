<?php
declare(strict_types=1);

/**
 * Validator V1 — WCAG-AA color contrast, computed (never trusted to the model).
 *
 * The design system locks its palette at theme.json time. Downstream sections
 * reference color slugs by name and trust them to be readable; if a pairing
 * ships low-contrast, the whole site renders unreadable text with no recovery
 * downstream. So we do the math here, at token-generation time, and reject
 * failing palettes (the ThemeJsonStep retries with the violations fed back).
 *
 * Authority: telex `server/prompts/parallel/foundation.md` (4.5:1 AA floor,
 * aim 7:1; reject near-matches under ~25 lightness steps). Pure + static so it
 * is trivially unit-testable.
 */
final class ContrastValidator
{
    /** WCAG-AA minimum contrast ratio for normal text. */
    public const AA = 4.5;

    /** Two slugs closer than this in lightness (0–100) fail on normal-weight text. */
    public const MIN_LIGHTNESS_DELTA = 25.0;

    /**
     * Validate the key foreground/background pairings of a theme's palette.
     *
     * @param array<mixed> $theme decoded theme.json
     * @return string[] human-readable violations (empty = passes V1)
     */
    public static function validate(array $theme): array
    {
        $hex = self::paletteHex($theme);

        $problems = [];

        // Text-on-page-background pairings. Body, headings, and muted/metadata
        // text all render on `base`, so each must clear AA against it.
        $pairs = [
            ['contrast',  'base', 'body text on page background'],
            ['primary',   'base', 'headings on page background'],
            ['secondary', 'base', 'muted/metadata text on page background'],
        ];

        // The button label-on-surface pairing, resolved from the actual button
        // element styles when present, else the documented default (base text on
        // accent surface). This catches the light-on-light / dark-on-dark family.
        [$btnText, $btnBg] = self::buttonPair($theme);
        $pairs[] = [$btnText, $btnBg, 'button label on button surface'];

        foreach ($pairs as [$fgSlug, $bgSlug, $label]) {
            $fg = $hex[$fgSlug] ?? null;
            $bg = $hex[$bgSlug] ?? null;
            if ($fg === null || $bg === null) {
                $problems[] = "{$label}: cannot resolve colors ({$fgSlug}/{$bgSlug}) to hex";
                continue;
            }

            $ratio = self::ratio($fg, $bg);
            if ($ratio === null) {
                $problems[] = "{$label}: {$fgSlug} ({$fg}) or {$bgSlug} ({$bg}) is not a valid hex color";
                continue;
            }
            if ($ratio < self::AA) {
                $problems[] = sprintf(
                    '%s: contrast %.2f:1 is below AA 4.5:1 (%s %s on %s %s)',
                    $label, $ratio, $fgSlug, $fg, $bgSlug, $bg
                );
            }

            $delta = self::lightnessDelta($fg, $bg);
            if ($delta !== null && $delta < self::MIN_LIGHTNESS_DELTA) {
                $problems[] = sprintf(
                    '%s: lightness delta %.0f between %s and %s is below %.0f (near-match)',
                    $label, $delta, $fgSlug, $bgSlug, self::MIN_LIGHTNESS_DELTA
                );
            }
        }

        return $problems;
    }

    /**
     * WCAG contrast ratio between two hex colors (>= 1.0), or null if either is
     * not a parseable hex.
     */
    public static function ratio(string $a, string $b): ?float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);
        if ($la === null || $lb === null) {
            return null;
        }
        $hi = max($la, $lb);
        $lo = min($la, $lb);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** Absolute difference in HSL lightness (0–100) of two hex colors, or null. */
    public static function lightnessDelta(string $a, string $b): ?float
    {
        $la = self::lightness($a);
        $lb = self::lightness($b);
        if ($la === null || $lb === null) {
            return null;
        }
        return abs($la - $lb);
    }

    /** HSL lightness 0–100 of a hex color, or null if unparseable. */
    public static function lightness(string $hex): ?float
    {
        $rgb = self::parseHex($hex);
        if ($rgb === null) {
            return null;
        }
        $max = max($rgb[0], $rgb[1], $rgb[2]) / 255;
        $min = min($rgb[0], $rgb[1], $rgb[2]) / 255;
        return (($max + $min) / 2) * 100;
    }

    /** WCAG relative luminance (0–1) of a hex color, or null if unparseable. */
    public static function relativeLuminance(string $hex): ?float
    {
        $rgb = self::parseHex($hex);
        if ($rgb === null) {
            return null;
        }
        $lin = static function (int $c): float {
            $s = $c / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $lin($rgb[0]) + 0.7152 * $lin($rgb[1]) + 0.0722 * $lin($rgb[2]);
    }

    /**
     * Parse "#RRGGBB" or "#RGB" (with/without leading #) to [r, g, b] (0–255),
     * or null when the value isn't a plain hex color (e.g. a CSS var reference).
     *
     * @return array{0:int,1:int,2:int}|null
     */
    public static function parseHex(string $hex): ?array
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3 && ctype_xdigit($h)) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (strlen($h) !== 6 || !ctype_xdigit($h)) {
            return null;
        }
        return [
            (int) hexdec(substr($h, 0, 2)),
            (int) hexdec(substr($h, 2, 2)),
            (int) hexdec(substr($h, 4, 2)),
        ];
    }

    /**
     * slug => hex map from settings.color.palette (skips non-hex / var entries).
     *
     * @param array<mixed> $theme
     * @return array<string,string>
     */
    public static function paletteHex(array $theme): array
    {
        $palette = $theme['settings']['color']['palette'] ?? [];
        $out = [];
        if (is_array($palette)) {
            foreach ($palette as $entry) {
                $slug = (string) ($entry['slug'] ?? '');
                $color = (string) ($entry['color'] ?? '');
                if ($slug !== '' && self::parseHex($color) !== null) {
                    $out[$slug] = $color;
                }
            }
        }
        return $out;
    }

    /**
     * Resolve the button element's [textSlug, bgSlug] from theme.json, falling
     * back to the documented default (base text on accent surface). Accepts the
     * two theme.json reference forms ("var:preset|color|accent" and
     * "var(--wp--preset--color--accent)") plus a bare slug.
     *
     * @param array<mixed> $theme
     * @return array{0:string,1:string}
     */
    private static function buttonPair(array $theme): array
    {
        $btn = $theme['styles']['elements']['button']['color'] ?? [];
        $bg = self::refToSlug((string) ($btn['background'] ?? '')) ?? 'accent';
        $text = self::refToSlug((string) ($btn['text'] ?? '')) ?? 'base';
        return [$text, $bg];
    }

    /** Extract a palette slug from a theme.json color reference, or null. */
    private static function refToSlug(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/var:preset\|color\|([a-z0-9-]+)/i', $value, $m)
            || preg_match('/--wp--preset--color--([a-z0-9-]+)/i', $value, $m)) {
            return $m[1];
        }
        // A bare slug (e.g. "accent") with no var wrapper.
        if (preg_match('/^[a-z0-9-]+$/i', $value)) {
            return $value;
        }
        return null;
    }
}
