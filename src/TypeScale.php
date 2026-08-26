<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Deterministic six-step typography ramps for one committed visual register.
 *
 * Every ramp starts from the same 1rem body size and applies one modular ratio
 * through lead, heading, section-title and display. Caption follows the inverse
 * ratio but never drops below 0.75rem, the design floor for visitor-visible
 * metadata. The two upper steps remain fluid; their desktop maxima are the
 * exact modular-scale values, and each keeps its own floor above the fixed
 * heading step so h3 < h2 < h1 stays visible at every viewport width.
 */
final class TypeScale
{
    public const ALL = ['compact', 'classic', 'editorial', 'dramatic', 'brutal'];
    public const DEFAULT = 'classic';

    private const BODY_REM = 1.0;
    private const CAPTION_FLOOR_REM = 0.75;

    /**
     * Minimum size of each fluid step, as a multiple of the fixed heading
     * step. A shared floor at the heading value renders h1, h2 and h3 at one
     * identical size on narrow viewports, so each fluid step keeps its own
     * floor: section-title bottoms out 25% above heading, display 55% above.
     * Both floors are capped at the step's own modular maximum, so a
     * restrained ramp never inverts its floor and its ceiling.
     */
    private const SECTION_FLOOR_OVER_HEADING = 1.25;
    private const DISPLAY_FLOOR_OVER_HEADING = 1.55;

    /** @var array<string,float> fourth roots of the intended display/body ratio */
    private const RATIOS = [
        'compact'   => 1.257433, // display = 2.5rem
        'classic'   => 1.414214, // display = 4rem
        'editorial' => 1.565085, // display = 6rem
        'dramatic'  => 1.681793, // display = 8rem
        'brutal'    => 1.861210, // display = 12rem
    ];

    /** @var array<string,array{section:string,display:string}> */
    private const VIEWPORT_TERMS = [
        'compact'   => ['section' => '2vw', 'display' => '4vw'],
        'classic'   => ['section' => '2.5vw', 'display' => '5vw'],
        'editorial' => ['section' => '3vw', 'display' => '7vw'],
        'dramatic'  => ['section' => '4vw', 'display' => '9vw'],
        'brutal'    => ['section' => '6vw', 'display' => '12vw'],
    ];

    /** The normalized commitment, or null when no valid one was persisted. */
    public static function explicit(mixed $value): ?string
    {
        return BoundedChoice::explicit($value, self::ALL);
    }

    /**
     * The six preset entries WordPress consumes, in semantic order.
     *
     * @return list<array{slug:string,name:string,size:string}>|null
     */
    public static function fontSizes(mixed $scale): ?array
    {
        $scale = self::explicit($scale);
        if ($scale === null) {
            return null;
        }

        $ratio = self::RATIOS[$scale];
        $body = self::BODY_REM;
        $caption = max(self::CAPTION_FLOOR_REM, $body / $ratio);
        $lead = $body * $ratio;
        $heading = $body * ($ratio ** 2);
        $section = $body * ($ratio ** 3);
        $display = $body * ($ratio ** 4);
        $sectionFloor = min($heading * self::SECTION_FLOOR_OVER_HEADING, $section);
        $displayFloor = min($heading * self::DISPLAY_FLOOR_OVER_HEADING, $display);
        $viewport = self::VIEWPORT_TERMS[$scale];

        return [
            ['slug' => 'caption', 'name' => 'Caption', 'size' => self::rem($caption)],
            ['slug' => 'body', 'name' => 'Body', 'size' => self::rem($body)],
            ['slug' => 'lead', 'name' => 'Lead', 'size' => self::rem($lead)],
            ['slug' => 'heading', 'name' => 'Heading', 'size' => self::rem($heading)],
            [
                'slug' => 'section-title',
                'name' => 'Section Title',
                'size' => 'clamp(' . self::rem($sectionFloor) . ', ' . $viewport['section'] . ', '
                    . self::rem($section) . ')',
            ],
            [
                'slug' => 'display',
                'name' => 'Display',
                'size' => 'clamp(' . self::rem($displayFloor) . ', ' . $viewport['display'] . ', '
                    . self::rem($display) . ')',
            ],
        ];
    }

    /** Human-readable execution meaning for downstream prompt echoes. */
    public static function meaning(string $scale): string
    {
        return match ($scale) {
            'compact'   => 'a restrained 1.257 ratio, keeping display near 2.5rem for archives and catalogs',
            'classic'   => 'a balanced 1.414 ratio, carrying display to 4rem without theatrical jumps',
            'editorial' => 'a pronounced 1.565 ratio, giving headlines a 6rem publication-style display ceiling',
            'dramatic'  => 'a high-contrast 1.682 ratio, giving the display step an 8rem ceiling',
            'brutal'    => 'an extreme 1.861 ratio, driving display to 12rem over the 1rem body anchor',
            default     => 'the committed deterministic modular scale',
        };
    }

    private static function rem(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
        return $formatted . 'rem';
    }
}
