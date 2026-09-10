<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Whether the site's header stays on screen after the reader scrolls.
 *
 * This is a design commitment, not a platform default. A header that never
 * leaves takes viewport from every band below it and puts one fixed bar over
 * the composition the direction spent its budget on. A header that leaves
 * gives the page back its full height and asks the reader to return to the
 * top to navigate. Both answers are correct for some sites, so the direction
 * states which one this site wants (BIGR-998).
 *
 * The value never overrides a deterministic guarantee. HeaderBehavior still
 * keeps tall header archetypes off the persistent path, still refuses
 * persistent chrome on a site with too little depth to need it, and still
 * degrades to a static header when no palette pair proves both states
 * readable.
 */
final class HeaderChrome
{
    /** The header stays available while the reader scrolls. */
    public const PERSISTENT = 'persistent';

    /** The header scrolls away with the opening composition. */
    public const TRANSIENT = 'transient';

    public const ALL = [self::PERSISTENT, self::TRANSIENT];

    /**
     * A direction that commits nothing gets the header that costs nothing.
     * Persistent chrome is an explicit opt-in, in the same way a framed
     * canvas, a device mark, and a rounded corner language are: an accidental
     * fixed bar reads as a platform default, never as a decision.
     */
    public const DEFAULT = self::TRANSIENT;

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    /**
     * Normalize generated input and record the same actionable warning shape
     * the other bounded design-direction fields use.
     *
     * @param list<string> $warnings
     */
    public static function normalize(mixed $raw, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $raw,
            self::ALL,
            self::DEFAULT,
            'header_chrome',
            $warnings,
            'invalid header chrome commitment replaced by deterministic transient fallback',
        );
    }

    /** True when the direction asks for a header that survives the scroll. */
    public static function isPersistent(mixed $raw): bool
    {
        return (self::explicit($raw) ?? self::DEFAULT) === self::PERSISTENT;
    }

    public static function meaning(string $chrome): string
    {
        return match ($chrome) {
            self::PERSISTENT => 'the header stays available while the reader scrolls',
            default => 'the header scrolls away with the opening composition and returns at the top of the page',
        };
    }
}
