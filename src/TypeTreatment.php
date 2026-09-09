<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** One bounded heading case/tracking language and its exact theme.json leaves. */
final class TypeTreatment
{
    public const ALL = ['sentence', 'tight', 'title', 'caps-tight', 'caps-tracked', 'lowercase'];
    public const DEFAULT = 'sentence';

    /** @var array<string,array{textTransform:string,letterSpacing:string}> */
    private const TYPOGRAPHY = [
        'sentence'     => ['textTransform' => 'none', 'letterSpacing' => '-0.01em'],
        // The product-site display language: sentence case, set very tight.
        // Modern grotesque display type on the web sits at -0.04em to
        // -0.06em; -0.04em keeps h5/h6 legible under the same site-wide pair.
        'tight'        => ['textTransform' => 'none', 'letterSpacing' => '-0.04em'],
        'title'        => ['textTransform' => 'capitalize', 'letterSpacing' => '-0.02em'],
        'caps-tight'   => ['textTransform' => 'uppercase', 'letterSpacing' => '-0.03em'],
        'caps-tracked' => ['textTransform' => 'uppercase', 'letterSpacing' => '0.08em'],
        'lowercase'    => ['textTransform' => 'lowercase', 'letterSpacing' => '0.01em'],
    ];

    public static function explicit(mixed $value): ?string
    {
        return BoundedChoice::explicit($value, self::ALL);
    }

    /** @return array{textTransform:string,letterSpacing:string}|null */
    public static function typography(mixed $treatment): ?array
    {
        $treatment = self::explicit($treatment);
        return $treatment === null ? null : self::TYPOGRAPHY[$treatment];
    }

    /**
     * Tracking for the statement-lines register, per uppercase treatment. The
     * site value is tuned for caps; sentence case at the same value sets too
     * tight under `caps-tight` and far too open under `caps-tracked`.
     *
     * @var array<string,string>
     */
    private const STATEMENT_LINE_TRACKING = [
        'caps-tight'   => '-0.02em',
        'caps-tracked' => '0.01em',
    ];

    /**
     * The statement-lines register: an opt-out from the site heading case for
     * one archetype.
     *
     * A statement line carries a whole statement, not a label. Under an
     * uppercase site treatment five stacked statements lose the word shapes a
     * reader recognizes, and each line sets wider, so a statement that fits
     * one line in sentence case wraps to two. That breaks the archetype's own
     * premise. Under `caps-tight` and `caps-tracked` these lines keep the
     * heading family and drop the caps.
     *
     * Every other treatment ships no kit: `lowercase` is a deliberate craft
     * voice on long lines, and `sentence`, `tight` and `title` never set caps.
     */
    public static function kitCss(mixed $treatment): ?string
    {
        $treatment = self::explicit($treatment);
        if ($treatment === null || !isset(self::STATEMENT_LINE_TRACKING[$treatment])) {
            return null;
        }
        $tracking = self::STATEMENT_LINE_TRACKING[$treatment];

        return <<<CSS

            .section-composition--statement-lines .wp-block-group.statement-lines > .wp-block-heading {
                text-transform: none;
                letter-spacing: {$tracking};
            }

            CSS;
    }

    public static function meaning(string $treatment): string
    {
        return match ($treatment) {
            'sentence'     => 'sentence case with gently tight -0.01em tracking',
            'tight'        => 'sentence case with very tight -0.04em tracking for a product or technical display voice',
            'title'        => 'title case with editorial -0.02em tracking',
            'caps-tight'   => 'uppercase with compact -0.03em tracking',
            'caps-tracked' => 'uppercase with open 0.08em tracking for an archival or technical voice',
            'lowercase'    => 'lowercase with relaxed 0.01em tracking for a craft or expressive voice',
            default        => 'the committed deterministic heading treatment',
        };
    }
}
