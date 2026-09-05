<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Bounded site-wide imagery kind (frm W7a): what the site's generated
 * pictures ARE, before the photographic grade says how they are lit.
 *
 * The reference corpus is not photographic by default: DreamMotion mixes one
 * photo series with app mockups, Cohesion floats clay 3D objects, Zova draws
 * line illustrations beside dashboard mockups, Spector adds gradient
 * abstracts. The direction commits one kind; the section authors pick the
 * matching AI_IMAGE style keyword; the prompt composer appends the kind's
 * render clause to every request; the QA rules stay per style.
 */
final class ImageKind
{
    public const ALL = ['photo', '3d-object', 'ui-mockup', 'line-illustration', 'abstract-gradient'];

    public const DEFAULT = 'photo';

    /** The AI_IMAGE `style` keyword each kind asks the section author to use. */
    private const STYLE = [
        'photo'             => 'photorealistic',
        '3d-object'         => '3d-render',
        'ui-mockup'         => 'flat-design',
        'line-illustration' => 'illustration',
        'abstract-gradient' => 'abstract',
    ];

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    public static function styleKeyword(string $kind): string
    {
        return self::STYLE[self::explicit($kind) ?? self::DEFAULT];
    }

    public static function meaning(string $kind): string
    {
        return match ($kind) {
            '3d-object'         => 'smooth matte clay-like 3D objects and simple geometric forms, rendered in soft studio light on plain seamless backdrops; no people, no scenes',
            'ui-mockup'         => 'framed product screens: dashboards, panels and cards drawn as clean abstract interface shapes with blurred placeholder text and simple charts, seen straight-on or gently tilted, never a readable word',
            'line-illustration' => 'single-weight line illustrations with two or three flat colours and generous white space, one subject per image',
            'abstract-gradient' => 'soft abstract gradient fields with fine grain and slow colour drift; no objects, no scenes, no text',
            default             => 'photographs, one graded series',
        };
    }

    /**
     * The render instruction the composer appends to every request. Empty for
     * photographs: the grade already says everything about a photo.
     */
    public static function promptClause(?string $raw): string
    {
        $kind = self::explicit($raw) ?? self::DEFAULT;
        return match ($kind) {
            '3d-object'         => 'Imagery kind for all site imagery: smooth matte clay-like 3D objects and simple geometric'
                . ' forms in soft studio light on a plain seamless backdrop, no people and no environment.',
            'ui-mockup'         => 'Imagery kind for all site imagery: a framed product interface rendered as clean abstract'
                . ' shapes, panels, bars and simple charts with blurred placeholder text, seen straight-on or gently'
                . ' tilted on a plain backdrop; no readable words, letters or numerals anywhere.',
            'line-illustration' => 'Imagery kind for all site imagery: a single-weight line illustration with two or three flat'
                . ' colours, generous white space and one subject; no photographic texture, no text.',
            'abstract-gradient' => 'Imagery kind for all site imagery: a soft abstract gradient field with fine grain and'
                . ' slow colour drift, edge to edge; no objects, no scene, no text.',
            default             => '',
        };
    }
}
