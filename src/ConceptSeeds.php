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

    /**
     * The design tradition the seed speaks in.
     *
     * `utilitarian` is deliberately absent (BIGR-871): the pick is uniform, and
     * a no-frills world winning a fifth of the time made underwhelming sites
     * out of briefs that never asked for one. It lives in EXTRA_REGISTERS, so a
     * brief that names it still gets it. `art-deco` and `brutalist` moved the
     * other way — they are real traditions a designer would propose unprompted,
     * and holding them back was costing variety the list is here to supply.
     */
    public const REGISTERS = [
        'heritage', 'modernist', 'editorial', 'expressive',
        'art-deco', 'brutalist', 'poster', 'noir',
        'archival', 'craft', 'retro-futurist', 'pop',
        'organic', 'technical',
    ];

    /**
     * The letterform tradition the seed sets its lettering in.
     *
     * Kept apart from `register` because one axis carrying both mood and type
     * is why quiet moods kept landing on the same few quiet faces: across 128
     * audited builds, 128 sites drew on 13 heading families and five of them
     * accounted for over half. Naming the tradition separately lets a calm
     * concept still be set in a Didone.
     */
    public const TYPE_REGISTERS = [
        'grotesque', 'didone', 'slab', 'humanist', 'geometric',
        'transitional', 'condensed', 'mono', 'script', 'display-serif',
    ];

    /** Which part of the color wheel the accent family comes from. */
    public const ACCENTS = ['warm', 'cool', 'earth', 'jewel', 'neutral'];

    /**
     * Which way the ground itself is tinted. `ground` says light or dark;
     * this says warm, cool, violet, green, blush or neutral. Kept as one
     * vocabulary with the build's own classifier so a seed cannot commit to
     * a family the palette check does not recognise.
     */
    public const TINTS = GroundTint::ALL;

    /**
     * Looks a brief may lock that are not on the universal lists. Canonical
     * word => phrases in the user's prompt that count as naming it. Topic
     * clichés do not belong here — only words the user actually wrote.
     *
     * @var array<string,list<string>>
     */
    private const EXTRA_REGISTERS = [
        'luxury'      => ['luxury', 'luxurious', 'luxe'],
        'playful'     => ['playful', 'whimsical'],
        'utilitarian' => ['utilitarian', 'no-frills', 'practical', 'functional', 'workmanlike'],
    ];

    /**
     * @var array<string,list<string>>
     */
    private const EXTRA_ACCENTS = [
        'pastel' => ['pastel', 'pastels'],
        'neon'   => ['neon'],
        'gold'   => ['gold', 'gilt'],
    ];

    /**
     * Coerce one raw seed into `{text, ground, register, accent, tint, type_register}`.
     *
     * The prompt asks for an object; a bare string (the older shape, and what
     * a small model falls back to under load) still parses, with no
     * coordinates. An axis outside its vocabulary reads as absent rather than
     * as a value of its own: an invented word must never make two identical
     * seeds look like two ideas, nor two different ones collide.
     *
     * `$locked` is extra register/accent words this brief named. They join
     * the universal lists for this round only.
     *
     * @param mixed $raw
     * @param array{registers?:list<string>,accents?:list<string>} $locked
     * @return array{text:string,ground:?string,register:?string,accent:?string,tint:?string,type_register:?string}|null
     */
    public static function normalize($raw, array $locked = []): ?array
    {
        if (is_string($raw)) {
            $text = trim($raw);
            return $text === ''
                ? null
                : [
                    'text'          => $text,
                    'ground'        => null,
                    'register'      => null,
                    'accent'        => null,
                    'tint'          => null,
                    'type_register' => null,
                ];
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
        $registers = array_values(array_unique([
            ...self::REGISTERS,
            ...($locked['registers'] ?? []),
        ]));
        $accents = array_values(array_unique([
            ...self::ACCENTS,
            ...($locked['accents'] ?? []),
        ]));
        return [
            'text'          => $text,
            'ground'        => self::axis($raw['ground'] ?? null, self::GROUNDS),
            'register'      => self::axis($raw['register'] ?? null, $registers),
            'accent'        => self::axis($raw['accent'] ?? null, $accents),
            'tint'          => self::axis($raw['tint'] ?? null, self::TINTS),
            'type_register' => self::axis($raw['type_register'] ?? null, self::TYPE_REGISTERS),
        ];
    }

    /**
     * Extra register and accent words this brief named, in catalog order.
     *
     * @return array{registers:list<string>,accents:list<string>}
     */
    public static function lockedFromBrief(string $brief): array
    {
        return [
            'registers' => self::namedInBrief($brief, self::EXTRA_REGISTERS),
            'accents'   => self::namedInBrief($brief, self::EXTRA_ACCENTS),
        ];
    }

    /**
     * Prompt paragraph listing locked extras, or empty when the brief named none.
     */
    public static function lockedLabelsPrompt(string $brief): string
    {
        $locked = self::lockedFromBrief($brief);
        $lines = [];
        if ($locked['registers'] !== []) {
            $lines[] = '- `register`: ' . self::quotedWords($locked['registers']);
        }
        if ($locked['accents'] !== []) {
            $lines[] = '- `accent`: ' . self::quotedWords($locked['accents']);
        }
        if ($lines === []) {
            return '';
        }
        return "This brief already named a look. For this round you may also use these words, and you should when a seed is that look:\n"
            . implode("\n", $lines);
    }

    /**
     * @return array{user_prompt:string,site_spec:string,locked_labels:string}
     */
    public static function seedPromptVars(string $brief, string $spec): array
    {
        return [
            'user_prompt'   => $brief,
            'site_spec'     => $spec,
            'locked_labels' => self::lockedLabelsPrompt($brief),
        ];
    }

    /**
     * The seed's coordinates as one comparable key, or null when it did not
     * declare all three. A seed missing a coordinate is never dropped on the
     * triple: an unstated axis is not evidence of sameness. Byte-identical
     * text still is, because the pick lands on the sentence.
     *
     * `tint` and `type_register` stay out of the key on purpose. Each extra
     * coordinate makes two seeds look distinct more easily and so quietly
     * weakens the collapse guard — and two seeds in one world, differing only
     * in how the ground leans or which face sets the headline, are still one
     * world.
     *
     * @param array{text:string,ground:?string,register:?string,accent:?string,tint:?string,type_register:?string} $seed
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
     * narrowing further. An unstated axis is not evidence of sameness, but
     * byte-identical text is: the pick lands on the sentence, so two copies of
     * it still win twice as often. And every drop is recorded, so a run where
     * the spread keeps collapsing is visible in the build's warnings instead of
     * only in the sameness of the finished sites.
     *
     * @param list<array{text:string,ground:?string,register:?string,accent:?string,tint:?string,type_register:?string}> $seeds
     * @param list<string> $warnings
     * @return list<array{text:string,ground:?string,register:?string,accent:?string,tint:?string,type_register:?string}>
     */
    public static function distinct(array $seeds, array &$warnings = []): array
    {
        $pool = [];
        $dropped = [];
        $seen = [];
        $seenText = [];
        foreach ($seeds as $seed) {
            $key = self::axisKey($seed);
            $text = $seed['text'];
            $twin = null;
            $dropKey = null;
            if ($key !== null && isset($seen[$key])) {
                $twin = $seen[$key];
                $dropKey = $key;
            } elseif (isset($seenText[$text])) {
                $twin = $seenText[$text];
                $dropKey = $key ?? 'same text';
            }
            if ($twin !== null) {
                $dropped[] = ['seed' => $seed, 'twin' => $twin, 'key' => $dropKey];
                continue;
            }
            if ($key !== null) {
                $seen[$key] = $seed;
            }
            $seenText[$text] = $seed;
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
     * The one ground a whole round shares, or null when it spans both, when
     * any seed left ground unstated, or when fewer than two seeds arrived.
     * One named ground is not a round-wide claim: a small model dropping or
     * misspelling `ground` on two of three is not exotic, and "every seed is
     * light-grounded" must not be derived from a single vote.
     *
     * @param list<array{text:string,ground:?string,register:?string,accent:?string,tint:?string,type_register:?string}> $seeds
     */
    public static function sharedGround(array $seeds): ?string
    {
        if (count($seeds) < 2) {
            return null;
        }
        $grounds = [];
        foreach ($seeds as $seed) {
            if ($seed['ground'] === null) {
                return null;
            }
            $grounds[$seed['ground']] = true;
        }
        return count($grounds) === 1 ? (string) array_key_first($grounds) : null;
    }

    /**
     * The one tint a whole round shares, or null when it spans families,
     * when any seed left tint unstated, or when fewer than two seeds
     * arrived. Same guards as sharedGround: a single named tint is not a
     * round-wide claim (BIGR-922).
     *
     * @param list<array{text:string,ground:?string,register:?string,accent:?string,tint:?string,type_register:?string}> $seeds
     */
    public static function sharedTint(array $seeds): ?string
    {
        if (count($seeds) < 2) {
            return null;
        }
        $tints = [];
        foreach ($seeds as $seed) {
            if ($seed['tint'] === null) {
                return null;
            }
            $tints[$seed['tint']] = true;
        }
        return count($tints) === 1 ? (string) array_key_first($tints) : null;
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
        $value = preg_replace('/\s+/', '-', $value) ?? $value;
        return in_array($value, $vocabulary, true) ? $value : null;
    }

    /**
     * @param array<string,list<string>> $catalog
     * @return list<string>
     */
    private static function namedInBrief(string $brief, array $catalog): array
    {
        $named = [];
        foreach ($catalog as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (self::briefHas($brief, $alias)) {
                    $named[] = $canonical;
                    break;
                }
            }
        }
        return $named;
    }

    private static function briefHas(string $brief, string $needle): bool
    {
        $haystack = strtolower(str_replace('-', ' ', $brief));
        $phrase = strtolower(str_replace('-', ' ', $needle));
        return preg_match(
            '/(?<![a-z0-9])' . preg_quote($phrase, '/') . '(?![a-z0-9])/',
            $haystack,
        ) === 1;
    }

    /** @param list<string> $words */
    private static function quotedWords(array $words): string
    {
        return implode(', ', array_map(
            static fn (string $word): string => '`"'.$word.'"`',
            $words,
        ));
    }
}
