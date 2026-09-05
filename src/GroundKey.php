<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Whether the page is built up from a light ground or down from a dark one.
 *
 * This is the luminance coordinate paired with GroundTint's hue coordinate.
 * The boundary intentionally matches the surface kit's established dark-page
 * split, so one delivered base cannot be called light by one executor and
 * dark by another.
 */
final class GroundKey
{
    public const ALL = ['light', 'dark'];

    public const DARK_THRESHOLD = 0.28;

    /** Small clearance keeps 8-bit rounding from landing on the wrong side. */
    private const CLEARANCE = 0.02;

    /**
     * The ground a brief states in so many words, or null when it is silent
     * (frm PR-4g). A bounded phrase list, not a category matcher: these are
     * the client's own instructions about the page ground, and a seed or a
     * scene sentence never outranks them. Checked in order, dark phrases
     * first, so "white text on a dark ground" reads as dark.
     *
     * @var array<string,list<string>>
     */
    private const STATED_PHRASES = [
        'dark' => [
            'dark ground', 'dark page', 'dark background', 'dark site', 'dark landing', 'dark mode',
            'black ground', 'black page', 'black background', 'near-black', 'near black',
            'midnight ground', 'charcoal ground', 'on a dark', 'on black',
        ],
        'light' => [
            'white page', 'white ground', 'white background', 'light page', 'light ground',
            'light background', 'pale page', 'pale ground', 'cream page', 'cream ground',
            'off-white page', 'on white', 'on a white', 'on a light',
        ],
    ];

    public static function statedInBrief(string $brief): ?string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        foreach (self::STATED_PHRASES as $key => $phrases) {
            foreach ($phrases as $phrase) {
                if (preg_match('/(?<![\\p{L}-])' . preg_quote($phrase, '/') . '(?![\\p{L}-])/u', $text) === 1) {
                    return $key;
                }
            }
        }
        return null;
    }

    public static function classify(string $hex): ?string
    {
        $rgb = ContrastMath::hexToRgb($hex);
        if ($rgb === null) {
            return null;
        }
        return ContrastMath::luminance($rgb) < self::DARK_THRESHOLD ? 'dark' : 'light';
    }

    /**
     * Move only as far as needed to honor the committed side of the boundary.
     * The RGB ray toward black/white retains the authored color relationship;
     * DesignDirectionStep reapplies GroundTint afterward when a tint was also
     * committed.
     */
    public static function move(string $hex, string $key): ?string
    {
        $rgb = ContrastMath::hexToRgb($hex);
        if ($rgb === null || !in_array($key, self::ALL, true)) {
            return null;
        }
        if (self::classify($hex) === $key) {
            return self::toHex($rgb);
        }

        $target = $key === 'dark'
            ? self::DARK_THRESHOLD - self::CLEARANCE
            : self::DARK_THRESHOLD + self::CLEARANCE;
        $extreme = $key === 'dark' ? [0, 0, 0] : [255, 255, 255];
        $lo = 0.0;
        $hi = 1.0;
        $best = $rgb;
        $bestDelta = INF;
        for ($i = 0; $i < 60; $i++) {
            $amount = ($lo + $hi) / 2;
            $candidate = self::mix($rgb, $extreme, $amount);
            $luminance = ContrastMath::luminance($candidate);
            $delta = abs($luminance - $target);
            if ($delta < $bestDelta) {
                $best = $candidate;
                $bestDelta = $delta;
            }
            if ($key === 'dark') {
                if ($luminance > $target) {
                    $lo = $amount;
                } else {
                    $hi = $amount;
                }
            } elseif ($luminance < $target) {
                $lo = $amount;
            } else {
                $hi = $amount;
            }
        }

        return self::toHex($best);
    }

    /** @param array{0:int,1:int,2:int} $from @param array{0:int,1:int,2:int} $to */
    private static function mix(array $from, array $to, float $amount): array
    {
        return [
            (int) round($from[0] + ($to[0] - $from[0]) * $amount),
            (int) round($from[1] + ($to[1] - $from[1]) * $amount),
            (int) round($from[2] + ($to[2] - $from[2]) * $amount),
        ];
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function toHex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }
}
