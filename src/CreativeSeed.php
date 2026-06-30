<?php
declare(strict_types=1);

/**
 * A per-build "creative seed": one sensibility phrase sampled at build time and
 * injected into the design-direction prompt.
 *
 * The builder has no other mechanism to decorrelate consecutive builds — same
 * prompt, same API defaults, so the model drifts toward the same safe attractor
 * (centered hero, all-sans, blue/teal). Temperature is already 1.0, so the lever
 * is prompt-level variety: nudging each build toward a different sensibility
 * breaks the tie and pushes two runs of the same prompt apart.
 *
 * The seed is a NUDGE, not a costume: the design-direction prompt is told to let
 * it bias the concept only when it fits the brand, never to override a genuinely
 * better fit. It is recorded in meta.json so a build's direction is inspectable
 * and reproducible.
 */
final class CreativeSeed
{
    /**
     * Curated sensibilities to bias a build toward. Deliberately broad and a bit
     * unexpected — the point is to pull away from the default, not to enforce a
     * specific style. Each is phrased to slot into "lean toward a ___ sensibility".
     *
     * @var string[]
     */
    private const SEEDS = [
        'editorial and magazine-like',
        'brutalist and raw',
        'warm and analog',
        'austere and minimalist',
        'maximalist and exuberant',
        'retro-futurist',
        'hand-crafted and tactile',
        'architectural and structural',
        'playful and toy-like',
        'cinematic and moody',
        'documentary and unvarnished',
        'luxurious and restrained',
        'botanical and organic',
        'technical and blueprint-like',
        'nostalgic and print-era',
        'bold and poster-like',
        'quiet and contemplative',
        'energetic and kinetic',
        'vintage-ceremonial',
        'industrial and utilitarian',
        'dreamlike and surreal',
        'folk and vernacular',
        'high-contrast and graphic',
        'sun-bleached and faded',
    ];

    /** @return string[] the full curated list (for inspection/tests). */
    public static function all(): array
    {
        return self::SEEDS;
    }

    /**
     * Sample one seed. Uses random_int (CSPRNG-backed) because PHP has no
     * resume/replay constraint on randomness here — each build genuinely wants a
     * fresh draw. Pass a value through meta.json to pin it for reproducibility.
     */
    public static function sample(): string
    {
        return self::SEEDS[random_int(0, count(self::SEEDS) - 1)];
    }
}
