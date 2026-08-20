<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The concept-seed round: the proposals the seed model writes, one of which
 * becomes the whole site's design direction.
 *
 * The seed spread is the pipeline's only variety source — it is where a build
 * decides which visual world it lives in — so three seeds describing the same
 * world quietly turn the random pick into no pick at all. The prompt has
 * always asked for divergence in prose; nothing checked it, and the audited
 * failure is three seeds orbiting the topic's obvious mood (for a bakery,
 * three shades of warm-cream coziness) that a designer would call one idea.
 *
 * So each seed now states its own coordinates, and a seed repeating an earlier
 * seed's whole triple is one idea wearing two names: dropped here, so the pick
 * lands on distinct worlds instead of on whichever world the model happened to
 * describe twice.
 *
 * Pure — unit-testable.
 */
final class ConceptSeeds
{
    /** Whether the world is built up from light or down from dark. */
    public const GROUNDS = ['light', 'dark'];

    /** The design tradition the seed speaks in. */
    public const REGISTERS = ['heritage', 'modernist', 'editorial', 'expressive', 'utilitarian'];

    /** Which part of the color wheel the accent family comes from. */
    public const ACCENTS = ['warm', 'cool', 'earth', 'jewel', 'neutral'];

    /**
     * Coerce one raw seed into `{text, ground, register, accent}`.
     *
     * The prompt asks for an object; a bare string (the older shape, and what
     * a small model falls back to under load) still parses, with no
     * coordinates. An axis outside its vocabulary reads as absent rather than
     * as a value of its own: an invented word must never make two identical
     * seeds look like two ideas, nor two different ones collide.
     *
     * @param mixed $raw
     * @return array{text:string,ground:?string,register:?string,accent:?string}|null
     */
    public static function normalize($raw): ?array
    {
        if (is_string($raw)) {
            $text = trim($raw);
            return $text === ''
                ? null
                : ['text' => $text, 'ground' => null, 'register' => null, 'accent' => null];
        }
        if (!is_array($raw)) {
            return null;
        }
        $text = null;
        foreach (['seed', 'title', 'text'] as $key) {
            if (is_string($raw[$key] ?? null) && trim($raw[$key]) !== '') {
                $text = trim($raw[$key]);
                break;
            }
        }
        if ($text === null) {
            return null;
        }
        return [
            'text'     => $text,
            'ground'   => self::axis($raw['ground'] ?? null, self::GROUNDS),
            'register' => self::axis($raw['register'] ?? null, self::REGISTERS),
            'accent'   => self::axis($raw['accent'] ?? null, self::ACCENTS),
        ];
    }

    /**
     * The seed's coordinates as one comparable key, or null when it did not
     * declare all three. A seed missing a coordinate is never dropped: an
     * unstated axis is not evidence of sameness.
     *
     * @param array{text:string,ground:?string,register:?string,accent:?string} $seed
     */
    public static function axisKey(array $seed): ?string
    {
        if ($seed['ground'] === null || $seed['register'] === null || $seed['accent'] === null) {
            return null;
        }
        return $seed['ground'] . '/' . $seed['register'] . '/' . $seed['accent'];
    }

    /**
     * The pool the pick may land on: the seeds in order, minus any that repeat
     * an earlier seed's whole triple.
     *
     * Two guards keep this from over-firing. Repeats are dropped only while at
     * least two seeds survive, because a brief that fixes the palette, era and
     * mood SHOULD produce one world three times — that is the model obeying the
     * user, not collapsing — and a pool of one distinct world is not worth
     * narrowing further. And every drop is recorded, so a run where the spread
     * keeps collapsing is visible in the build's warnings instead of only in
     * the sameness of the finished sites.
     *
     * @param list<array{text:string,ground:?string,register:?string,accent:?string}> $seeds
     * @param list<string> $warnings
     * @return list<array{text:string,ground:?string,register:?string,accent:?string}>
     */
    public static function distinct(array $seeds, array &$warnings = []): array
    {
        $pool = [];
        $dropped = [];
        $seen = [];
        foreach ($seeds as $seed) {
            $key = self::axisKey($seed);
            if ($key !== null && isset($seen[$key])) {
                $dropped[] = ['seed' => $seed, 'twin' => $seen[$key], 'key' => $key];
                continue;
            }
            if ($key !== null) {
                $seen[$key] = $seed;
            }
            $pool[] = $seed;
        }

        if (count($pool) < 2) {
            // Nothing distinct enough to narrow to. Keep the round whole and
            // say so; see the guard note above.
            if ($dropped !== []) {
                $warnings[] = sprintf(
                    'design-direction: all %d concept seeds describe one world (%s); '
                        . 'kept the round whole and picked from it; disposition tolerated',
                    count($seeds),
                    $dropped[0]['key'],
                );
            }
            return $seeds;
        }

        foreach ($dropped as $drop) {
            $warnings[] = sprintf(
                'design-direction: concept seed %s repeats %s (%s) — one idea wearing two names; '
                    . 'dropped from the pick; disposition dropped',
                Warnings::value(self::shortTitle($drop['seed']['text'])),
                Warnings::value(self::shortTitle($drop['twin']['text'])),
                $drop['key'],
            );
        }

        return $pool;
    }

    /**
     * The one ground a whole round shares, or null when it spans both (or when
     * no seed named one). The prompt asks for at least one light-grounded and
     * one dark-grounded world whenever the brief leaves the mood open, and a
     * round that answers with three of a kind is the spread narrowing before
     * the pick ever happens — worth saying out loud even when the seeds are
     * otherwise distinct enough to keep.
     *
     * @param list<array{text:string,ground:?string,register:?string,accent:?string}> $seeds
     */
    public static function sharedGround(array $seeds): ?string
    {
        if (count($seeds) < 2) {
            return null;
        }
        $grounds = [];
        foreach ($seeds as $seed) {
            if ($seed['ground'] !== null) {
                $grounds[$seed['ground']] = true;
            }
        }
        return count($grounds) === 1 ? (string) array_key_first($grounds) : null;
    }

    /** The seed's title, the part before its committing sentence. */
    private static function shortTitle(string $text): string
    {
        $title = preg_split('/\s[-\x{2013}\x{2014}:]\s/u', $text, 2)[0] ?? $text;
        $title = trim($title);
        return $title === '' ? $text : $title;
    }

    /**
     * One axis value, or null when it is absent or outside its vocabulary.
     *
     * @param mixed $raw
     * @param list<string> $vocabulary
     */
    private static function axis($raw, array $vocabulary): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = strtolower(trim($raw));
        return in_array($value, $vocabulary, true) ? $value : null;
    }
}
