<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Deterministic content/wide layout pairs for one committed page measure. */
final class Measure
{
    public const ALL = ['narrow', 'standard', 'wide', 'full'];
    public const DEFAULT = 'standard';

    /** @var array<string,array{contentSize:string,wideSize:string}> */
    private const WIDTHS = [
        'narrow'   => ['contentSize' => '640px', 'wideSize' => '1000px'],
        'standard' => ['contentSize' => '860px', 'wideSize' => '1320px'],
        'wide'     => ['contentSize' => '960px', 'wideSize' => '1560px'],
        'full'     => ['contentSize' => '1040px', 'wideSize' => '1760px'],
    ];

    public static function explicit(mixed $value): ?string
    {
        return BoundedChoice::explicit($value, self::ALL);
    }

    /** @return array{contentSize:string,wideSize:string}|null */
    public static function widths(mixed $measure): ?array
    {
        $measure = self::explicit($measure);
        return $measure === null ? null : self::WIDTHS[$measure];
    }

    public static function meaning(string $measure): string
    {
        return match ($measure) {
            'narrow'   => 'a 640px reading column inside a 1000px wide stage for art-book, poetry, and single-column editorial work',
            'standard' => 'an 860px reading column inside the balanced 1320px general-purpose stage',
            'wide'     => 'a 960px content column inside a 1560px stage for dense products and catalogs',
            'full'     => 'a 1040px content column inside a 1760px screen-filling gallery stage',
            default    => 'the committed deterministic layout pair',
        };
    }
}
