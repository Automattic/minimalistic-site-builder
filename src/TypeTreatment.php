<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** One bounded heading case/tracking language and its exact theme.json leaves. */
final class TypeTreatment
{
    public const ALL = ['sentence', 'tight', 'caps-tight', 'caps-tracked', 'lowercase'];
    public const DEFAULT = 'sentence';

    /** A retired title token falls back to sentence case with a warning. */
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

    /** @return array{textTransform:string,letterSpacing:string}|null */
    public static function typography(mixed $treatment): ?array
    {
        $treatment = self::explicit($treatment);
        return $treatment === null ? null : self::TYPOGRAPHY[$treatment];
    }

    /**
     * The uppercase treatments that ship a display kit.
     *
     * Every other treatment ships no kit: `lowercase` is a deliberate craft
     * voice on long lines, and `sentence`, `tight` and `title` never set caps.
     *
     * @var list<string>
     */
    private const CAPS_TREATMENTS = ['caps-tight', 'caps-tracked'];

    /**
     * The display register for an uppercase site heading case.
     *
     * Uppercase display lines set wider and taller than sentence case, so a
     * headline that holds one line in sentence case wraps under caps. The kit
     * tightens the display line height and balances the wrap.
     */
    public static function kitCss(mixed $treatment): ?string
    {
        $treatment = self::explicit($treatment);
        if ($treatment === null || !in_array($treatment, self::CAPS_TREATMENTS, true)) {
            return null;
        }

        return <<<CSS

            /* The build sets the '{$treatment}' display line height. */
            .hero-composition__copy .wp-block-heading:is(h1, .has-display-font-size),
            .wp-block-heading.has-display-font-size {
                line-height: 0.92;
                text-wrap: balance;
            }

            CSS;
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
}
