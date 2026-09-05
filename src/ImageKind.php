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

    /**
     * The one class an author may add to a framed screen on a `ui-mockup`
     * site for a gentle perspective tilt (frm W7b). The kit paints it with
     * the individual `rotate` property, so a reveal class that owns
     * `transform` on the same block never fights it; phones lie it flat.
     */
    public const TILT_CLASS = 'screen-frame--tilt';

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

    /**
     * Whether every delivered picture of this kind goes through the vision
     * check (frm W7b). A product screen is where painted words hurt most:
     * a fake wordmark or a legible menu in a dashboard mockup reads as the
     * site's own copy. Photographs keep the hero-and-cover rule.
     */
    public static function inspectsEveryImage(?string $raw): bool
    {
        return self::explicit($raw) === 'ui-mockup';
    }

    /**
     * The kind-specific reading of the QA prompt's text question. A mockup
     * is drawn with blurred placeholder bars and chart shapes on purpose;
     * only legible letters, words or numerals are a finding there.
     */
    public static function qaTextRule(?string $raw): string
    {
        if (self::explicit($raw) !== 'ui-mockup') {
            return '';
        }
        return ' This picture is a product-interface mockup: blurred placeholder bars, blocks and abstract'
            . ' chart shapes are NOT text. Answer true only for legible letters, words or numerals.';
    }

    /**
     * The framed-screen kit (frm W7b), shipped only for `ui-mockup`. Every
     * contained picture on such a site is a product screen, so the build
     * frames it as one window: the committed panel radius, a hairline ring
     * and a window bar drawn in the surface's own ink, a soft drop shadow.
     * Covers and backgrounds, avatars, logos and transparent assets keep
     * their own treatment. The selector keys on the image role hooks the
     * section authors already write, so no markup changes.
     */
    public static function kitCss(?string $raw): ?string
    {
        if (self::explicit($raw) !== 'ui-mockup') {
            return null;
        }
        $tilt = self::TILT_CLASS;
        return <<<CSS
            /* Committed 'ui-mockup' imagery (frm W7b): each contained picture is a
               product screen framed as one window. Covers, backgrounds, avatars,
               logos and transparent assets keep their own treatment. */
            :is(.wp-block-image, .card-media, .card-media-tall, .card-media-thumb, .feature-media, .hero-composition__stage):not(.wp-block-cover *):not(.is-style-rounded):not([class*="avatar"]):not([class*="logo"]):has(> img:not([src$=".png"])) {
                position: relative;
                padding-block-start: 1.75rem;
                border-radius: var(--shape-radius-panel, 1rem);
                overflow: hidden;
                background: color-mix(in srgb, currentColor 7%, transparent);
                box-shadow:
                    inset 0 0 0 1px color-mix(in srgb, currentColor 16%, transparent),
                    0 1.5rem 2.5rem -1.75rem rgb(0 0 0 / 0.45);
            }
            :is(.wp-block-image, .card-media, .card-media-tall, .card-media-thumb, .feature-media, .hero-composition__stage):not(.wp-block-cover *):not(.is-style-rounded):not([class*="avatar"]):not([class*="logo"]):has(> img:not([src$=".png"]))::before {
                content: "";
                position: absolute;
                inset-block-start: 0.6875rem;
                inset-inline-start: 0.875rem;
                width: 2.25rem;
                height: 0.375rem;
                background: radial-gradient(circle, currentColor 0.1875rem, transparent 0.2rem) 0 50% / 0.75rem 0.375rem repeat-x;
                opacity: 0.35;
                pointer-events: none;
            }
            :is(.wp-block-image, .card-media, .card-media-tall, .card-media-thumb, .feature-media, .hero-composition__stage):not(.wp-block-cover *):not(.is-style-rounded):not([class*="avatar"]):not([class*="logo"]) > img:not([src$=".png"]) {
                display: block;
                width: 100%;
                height: auto;
                border-radius: 0;
            }
            /* Optional tilt on one screen: individual `rotate`, so a reveal
               class that owns `transform` never fights it. Phones lie flat. */
            :has(> .{$tilt}) {
                perspective: 1400px;
            }
            .{$tilt} {
                rotate: x 6deg;
                transform-origin: 50% 100%;
            }
            @media (max-width: 781px) {
                .{$tilt} {
                    rotate: none;
                }
            }

            CSS;
    }
}
