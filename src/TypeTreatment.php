<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** One bounded heading case/tracking language and its exact theme.json leaves. */
final class TypeTreatment
{
    public const ALL = ['sentence', 'title', 'caps-tight', 'caps-tracked', 'lowercase'];
    public const DEFAULT = 'sentence';

    /** @var array<string,array{textTransform:string,letterSpacing:string}> */
    private const TYPOGRAPHY = [
        'sentence'     => ['textTransform' => 'none', 'letterSpacing' => '-0.01em'],
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

    public static function meaning(string $treatment): string
    {
        return match ($treatment) {
            'sentence'     => 'sentence case with gently tight -0.01em tracking',
            'title'        => 'title case with editorial -0.02em tracking',
            'caps-tight'   => 'uppercase with compact -0.03em tracking',
            'caps-tracked' => 'uppercase with open 0.08em tracking for an archival or technical voice',
            'lowercase'    => 'lowercase with relaxed 0.01em tracking for a craft or expressive voice',
            default        => 'the committed deterministic heading treatment',
        };
    }
}
