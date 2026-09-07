<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** One bounded heading case/tracking language and its exact theme.json leaves. */
final class TypeTreatment
{
    public const ALL = ['sentence', 'tight', 'caps-tight', 'caps-tracked', 'lowercase'];
    public const DEFAULT = 'sentence';

    /**
     * Retired (frm PR-5p): `title` set `textTransform: capitalize`, which
     * Title-Cases every word of every heading ("Trusted By Creators Who Ship
     * On Impossible Deadlines" on dreammotion-like44) against the sentence
     * case every reference sets its two-tone headings in. A direction that
     * still commits it falls to the sentence default with a warning.
     */
    public const RETIRED = ['title'];

    /** @var array<string,array{textTransform:string,letterSpacing:string}> */
    private const TYPOGRAPHY = [
        'sentence'     => ['textTransform' => 'none', 'letterSpacing' => '-0.01em'],
        // The product-site display language: sentence case, set very tight.
        // Modern grotesque display type on the web sits at -0.04em to
        // -0.06em; -0.04em keeps h5/h6 legible under the same site-wide pair.
        'tight'        => ['textTransform' => 'none', 'letterSpacing' => '-0.04em'],
        'caps-tight'   => ['textTransform' => 'uppercase', 'letterSpacing' => '-0.03em'],
        'caps-tracked' => ['textTransform' => 'uppercase', 'letterSpacing' => '0.08em'],
        'lowercase'    => ['textTransform' => 'lowercase', 'letterSpacing' => '0.01em'],
    ];

    public static function explicit(mixed $value): ?string
    {
        return BoundedChoice::explicit($value, self::ALL);
    }

    /**
     * Phrases that state a site-wide heading case (frm PR-5s): dasstudio's
     * "giant uppercase section titles", spector's "uppercase display
     * headline". A wordmark or name phrase ("giant lowercase wordmark") is
     * the wordmark's own case (HeroComposition::statedWordmarkCase) and
     * says nothing about the headings: fabrica-like27 read it as the
     * site-wide lowercase treatment and set its team names lowercase.
     *
     * @var array<string, list<string>>
     */
    private const STATED_HEADING_CASE_PHRASES = [
        'uppercase' => [
            'uppercase headings', 'uppercase heading', 'uppercase titles', 'uppercase section titles', 'uppercase title',
            'uppercase headlines', 'uppercase headline', 'uppercase display headline', 'uppercase display', 'uppercase type',
            'all caps headings', 'all-caps headings', 'all caps titles', 'all-caps titles', 'all caps type', 'all-caps type',
            'headings in capitals', 'titles in capitals', 'caps headings',
        ],
        'lowercase' => [
            'lowercase headings', 'lowercase heading', 'lowercase titles', 'lowercase section titles', 'lowercase title',
            'lowercase headlines', 'lowercase headline', 'lowercase display', 'lowercase type', 'all lowercase headings',
            'all-lowercase headings', 'all lowercase type',
        ],
    ];

    /**
     * Phrases that state the tight sentence-case treatment (frm PR-5t):
     * luzia's "tight sans headings with muted-plus-dark two-tone lines" met
     * a caps-tight commitment and every heading shipped uppercase.
     *
     * @var list<string>
     */
    private const STATED_TIGHT_PHRASES = [
        'tight sans headings', 'tight sans heading', 'tight headings', 'tight heading', 'tight sans type',
        'tight tracking', 'tightly tracked headings', 'tight display type', 'tight display headings',
        'tight grotesque headings', 'tight geometric headings', 'tight sans-serif headings',
    ];

    /**
     * The treatment a brief states in so many words, or null: `tight` for a
     * stated tight sans heading, `caps-tight` when the brief also states
     * uppercase titles, `lowercase` for stated lowercase headings.
     */
    public static function statedTreatment(string $brief): ?string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        $tight = false;
        foreach (self::STATED_TIGHT_PHRASES as $phrase) {
            if (preg_match('/(?<![\p{L}-])' . preg_quote($phrase, '/') . '(?![\p{L}-])/u', $text) === 1) {
                $tight = true;
                break;
            }
        }
        $case = self::statedHeadingCase($brief);
        if ($case === 'uppercase') {
            // A stated uppercase heading is the caps treatment (frm PR-5u):
            // spector-like47's "three-line uppercase display headline" met a
            // tight commitment and shipped in mixed case. Compact tracking is
            // the default; a committed caps-tracked keeps its tracking.
            return 'caps-tight';
        }
        if ($case === 'lowercase') {
            return 'lowercase';
        }
        return $tight ? 'tight' : null;
    }

    /** @param array<string,mixed> $meta */
    public static function statedTreatmentFor(array $meta): ?string
    {
        foreach (['original_prompt', 'prompt'] as $key) {
            $text = $meta[$key] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $treatment = self::statedTreatment($text);
                if ($treatment !== null) {
                    return $treatment;
                }
            }
        }
        return null;
    }

    /** The site-wide heading case a brief states, or null. */
    public static function statedHeadingCase(string $brief): ?string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        foreach (self::STATED_HEADING_CASE_PHRASES as $case => $phrases) {
            foreach ($phrases as $phrase) {
                if (preg_match('/(?<![\p{L}-])' . preg_quote($phrase, '/') . '(?![\p{L}-])/u', $text) === 1) {
                    return $case;
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $meta */
    public static function statedHeadingCaseFor(array $meta): ?string
    {
        foreach (['original_prompt', 'prompt'] as $key) {
            $text = $meta[$key] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $case = self::statedHeadingCase($text);
                if ($case !== null) {
                    return $case;
                }
            }
        }
        return null;
    }

    /** The case a treatment transforms headings to, or null for sentence case. */
    public static function caseOf(mixed $treatment): ?string
    {
        $treatment = self::explicit($treatment);
        return match ($treatment) {
            'lowercase' => 'lowercase',
            'caps-tight', 'caps-tracked' => 'uppercase',
            default => null,
        };
    }

    /** @return array{textTransform:string,letterSpacing:string}|null */
    public static function typography(mixed $treatment): ?array
    {
        $treatment = self::explicit($treatment);
        return $treatment === null ? null : self::TYPOGRAPHY[$treatment];
    }

    public static function meaning(string $treatment): string
    {
        return match ($treatment) {
            'sentence'     => 'sentence case with gently tight -0.01em tracking',
            'tight'        => 'sentence case with very tight -0.04em tracking for a product or technical display voice',
            'caps-tight'   => 'uppercase with compact -0.03em tracking',
            'caps-tracked' => 'uppercase with open 0.08em tracking for an archival or technical voice',
            'lowercase'    => 'lowercase with relaxed 0.01em tracking for a craft or expressive voice',
            default        => 'the committed deterministic heading treatment',
        };
    }

    /** The treatments whose display headings stack as tight uppercase lines (frm W5c). */
    public const STACKED_LINE_TREATMENTS = ['caps-tight', 'caps-tracked'];

    /**
     * Display-lines kit (frm W5c): under an uppercase treatment a display
     * heading reads as stacked lines, the way Spector sets its three-line
     * H1: leading near the cap height and balanced line breaks. Scoped to
     * display-size headings and the hero H1, so the theme model's heading
     * lineHeight (which repairTypeTreatment preserves) still governs the
     * other levels. Nothing ships for the other treatments.
     */
    public static function kitCss(mixed $treatment): ?string
    {
        $treatment = self::explicit($treatment);
        if ($treatment === null || !in_array($treatment, self::STACKED_LINE_TREATMENTS, true)) {
            return null;
        }
        return <<<CSS
            /* Committed '{$treatment}' display lines. Written by the build, never by a model. */
            .hero-composition__copy .wp-block-heading:is(h1, .has-display-font-size),
            .wp-block-heading.has-display-font-size {
                line-height: 0.92;
                text-wrap: balance;
            }

            CSS;
    }
}
