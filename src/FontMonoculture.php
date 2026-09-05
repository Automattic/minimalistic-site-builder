<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Familiar faces omitted only from discovery suggestions, never from authored commitments. */
final class FontMonoculture
{
    /**
     * Lowercased family names that read as a default rather than a choice.
     *
     * None of these are bad faces, and any of them can be right when a brief
     * genuinely asks. The discovery shortlist omits them to suggest alternatives.
     * The model and an explicit user brief may still choose any available face.
     *
     * @var list<string>
     */
    public const OVERUSED = [
        // The older ubiquity.
        'inter', 'roboto', 'open sans', 'lato', 'montserrat', 'arial', 'helvetica',
        // The current wave.
        'fraunces', 'instrument sans', 'instrument serif',
        'geist', 'geist sans', 'geist mono', 'mona sans',
        'plus jakarta sans', 'space grotesk', 'recoleta',
        // This pipeline's own measured reflexes.
        'archivo', 'archivo black', 'playfair display', 'cormorant garamond',
    ];

    public static function isOverused(string $family): bool
    {
        return in_array(strtolower(trim($family)), self::OVERUSED, true);
    }

}
