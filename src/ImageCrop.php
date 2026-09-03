<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Shared bounded vocabulary and deterministic execution for image proportion. */
final class ImageCrop
{
    public const ALL = ['landscape', 'portrait', 'square', 'panoramic', 'mixed'];

    public const DEFAULT = 'mixed';

    /** @var array<string,array{card:string,tall:string,thumb:string,feature:string}> */
    private const CSS_RATIOS = [
        'landscape' => [
            'card' => '3 / 2',
            'tall' => '4 / 3',
            'thumb' => '4 / 3',
            'feature' => '16 / 9',
        ],
        'portrait' => [
            'card' => '4 / 5',
            'tall' => '2 / 3',
            'thumb' => '3 / 4',
            'feature' => '4 / 5',
        ],
        'square' => [
            'card' => '1 / 1',
            'tall' => '1 / 1',
            'thumb' => '1 / 1',
            'feature' => '1 / 1',
        ],
        'panoramic' => [
            'card' => '16 / 9',
            'tall' => '3 / 2',
            'thumb' => '16 / 9',
            'feature' => '21 / 9',
        ],
    ];

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    /**
     * Override the scaffold's mixed per-role ratios for one committed system.
     * The list-thumb flush recipe deliberately keeps its more-specific
     * full-height crop: there the text column, not a ratio, owns row height.
     */
    public static function kitCss(mixed $raw): ?string
    {
        $crop = self::explicit($raw);
        if ($crop === null || $crop === 'mixed') {
            return null;
        }
        $ratio = self::CSS_RATIOS[$crop];
        return <<<CSS
            /* Committed '{$crop}' image crop. These role hooks are authored by
               section generation; the build owns their delivered proportions. */
            .card-media img { aspect-ratio: {$ratio['card']} !important; height: auto; }
            .card-media-tall img { aspect-ratio: {$ratio['tall']} !important; height: auto; }
            .card-media-thumb img { aspect-ratio: {$ratio['thumb']} !important; height: auto; }
            .feature-media img {
                width: 100%;
                aspect-ratio: {$ratio['feature']} !important;
                height: auto;
                object-fit: cover;
                display: block;
            }
            /* Flush list rows are the reviewed exception: their text column
               owns row height and the thumbnail stretches to that live box. */
            .list-thumb-flush .card-media-thumb img {
                aspect-ratio: auto !important;
                height: 100%;
            }

            CSS;
    }

    /**
     * Ratio sent to image generation so source pixels resemble their target
     * crop. Viewport-spanning images stay wide for every system; panoramic
     * makes those bands ultrawide. Mixed preserves the authored slot ratio.
     */
    public static function generationRatio(mixed $raw, string $authored, string $pageContext = ''): string
    {
        $current = GeminiImage::aspectRatio($authored);
        $crop = self::explicit($raw);
        if ($crop === null || $crop === 'mixed') {
            return $current;
        }

        // Any context that names a background is a viewport-spanning slot:
        // the documented examples include "background of a call-to-action
        // band", which no role word alone would classify as full-frame.
        $fullFrame = $current === '21:9' || preg_match(
            '/\b(?:full[- ](?:bleed|frame|width)|edge[- ]to[- ]edge|hero\s+cover|background)\b/iu',
            $pageContext,
        ) === 1;
        if ($fullFrame) {
            return $crop === 'panoramic' ? '21:9' : '16:9';
        }

        $thumb = preg_match('/\bthumb(?:nail)?\b/iu', $pageContext) === 1;
        $tall = preg_match('/\b(?:dominant|tall)\b/iu', $pageContext) === 1;
        $feature = preg_match('/\b(?:feature|band|banner)\b/iu', $pageContext) === 1
            && preg_match('/\b(?:card|thumb(?:nail)?|grid|tile)\b/iu', $pageContext) !== 1;
        return match ($crop) {
            'landscape' => $feature ? '16:9' : (($thumb || $tall) ? '4:3' : '3:2'),
            'portrait'  => $tall ? '2:3' : ($thumb ? '3:4' : '4:5'),
            'square'    => '1:1',
            'panoramic' => $feature ? '21:9' : ($tall ? '3:2' : '16:9'),
        };
    }

    /**
     * Composition guidance consumed by ImagePromptComposer.
     *
     * The wording deliberately avoids "frame" and "contained" (BIGR-956): the
     * image model sometimes reads that vocabulary — stacked with "photograph",
     * "series" and a film grade — as a printed photo and paints a literal
     * white border into the pixels. The clause instead states positively that
     * the scene fills the canvas to its edges.
     */
    public static function promptClause(mixed $raw): string
    {
        return match (self::explicit($raw)) {
            'landscape' => 'Site-wide crop direction: compose each scene to fill its wide horizontal canvas out to every edge, keeping the focal subject inside the central landscape safe area.',
            'portrait' => 'Site-wide crop direction: compose each scene to fill its tall vertical canvas out to every edge, keeping the focal subject inside the central portrait safe area.',
            'square' => 'Site-wide crop direction: compose each scene to fill its square canvas out to every edge, with balanced central weight.',
            'panoramic' => 'Site-wide crop direction: compose shallow, lateral scenes that fill the wide panoramic canvas out to every edge, with the focal subject protected inside the central band.',
            default => '',
        };
    }
}
