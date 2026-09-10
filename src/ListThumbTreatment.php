<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Apply the card treatment to horizontal thumbnail rows. */
final class ListThumbTreatment
{
    public const MARKER_PREFIX = 'list-thumb-treatment--';

    public static function marker(string $style): string
    {
        if (!in_array($style, CardStyle::ALL, true)) {
            throw new \InvalidArgumentException('thumbnail treatment requires a normalized card style');
        }
        return self::MARKER_PREFIX . $style;
    }

    public static function instructions(string $style): string
    {
        $marker = self::marker($style);
        $description = match ($style) {
            'flush' => 'The thumbnail fills the row height and touches the row edges. Only the text has padding. The image width limit is 9rem.',
            'framed' => 'The row has sm padding on all sides. The thumbnail sits inside this space. The image width limit is 8rem.',
            'borderless' => 'The row and text have no padding. An sm gap separates the thumbnail from the text. The image width limit is 6rem.',
            'overlap' => 'The row has sm padding. The thumbnail rises by xs above its normal position. The image width limit is 8rem.',
        };
        return "- Thumbnail treatment: {$style}. Add `{$marker}` to the section root group.\n"
            . "  {$description}\n"
            . "  The theme owns image margins, padding, and size. Keep the 18%/82% column attributes.\n"
            . "  Keep the image and text in separate columns. Omit inline image sizes and spacing.\n"
            . "  Use card-media-thumb on each image. Keep card-style--* and card-body hooks off these rows.\n"
            . "  The site crop applies, except that flush thumbnails fill the row height.";
    }

    public static function css(): string
    {
        $scope = ':is(.' . implode(', .', array_map(self::marker(...), CardStyle::ALL)) . ')';
        $shape = '.wp-block-columns:has(> .wp-block-column:first-child > figure.card-media-thumb)'
            . ':has(> .wp-block-column:nth-child(2):last-child)'
            . ':not(:has(> .wp-block-column:last-child > figure.card-media-thumb))';
        $row = $scope . ' ' . $shape;
        $media = $row . ' > .wp-block-column:has(> figure.card-media-thumb)';
        $text = $row . ' > .wp-block-column:not(:has(> figure.card-media-thumb))';
        $flush = '.list-thumb-treatment--flush ' . $shape;

        return <<<CSS

            /* Thumbnail space and size follow the assigned card treatment. */
            {$scope} {
                --list-thumb-media-size: 8rem;
                --list-thumb-row-padding: var(--wp--preset--spacing--sm, 1rem);
                --list-thumb-text-padding: 0px;
                --list-thumb-gap: var(--wp--preset--spacing--sm, 1rem);
                --list-thumb-image-offset: 0px;
            }
            .list-thumb-treatment--flush {
                --list-thumb-media-size: 9rem;
                --list-thumb-row-padding: 0px;
                --list-thumb-text-padding: var(--wp--preset--spacing--sm, 1rem);
                --list-thumb-gap: 0px;
            }
            .list-thumb-treatment--borderless {
                --list-thumb-media-size: 6rem;
                --list-thumb-row-padding: 0px;
            }
            .list-thumb-treatment--overlap {
                --list-thumb-image-offset: calc(-1 * var(--wp--preset--spacing--xs, 0.5rem));
            }
            {$row} {
                padding: var(--list-thumb-row-padding) !important;
                gap: var(--list-thumb-gap) !important;
                flex-wrap: nowrap !important;
                overflow: visible;
                align-items: center;
            }
            {$row} > .wp-block-column {
                min-inline-size: 0;
                margin: 0 !important;
                align-self: center !important;
            }
            {$media} {
                flex: 0 1 var(--list-thumb-media-size) !important;
                max-inline-size: var(--list-thumb-media-size);
                padding: 0 !important;
            }
            {$text} {
                flex: 1 1 0% !important;
                padding: var(--list-thumb-text-padding) !important;
            }
            {$media} > figure.card-media-thumb {
                inline-size: 100%;
                height: auto;
                margin: 0 !important;
                margin-block-start: var(--list-thumb-image-offset) !important;
                margin-block-end: calc(-1 * var(--list-thumb-image-offset)) !important;
                padding: 0 !important;
            }
            {$media} > figure.card-media-thumb img {
                width: 100%;
                height: auto !important;
                aspect-ratio: var(--list-thumb-image-ratio, 1 / 1) !important;
                object-fit: cover;
            }
            {$flush} {
                overflow: hidden;
                align-items: stretch;
            }
            {$flush} > .wp-block-column {
                align-self: stretch !important;
            }
            {$flush} > .wp-block-column:has(> figure.card-media-thumb) > figure.card-media-thumb {
                height: 100%;
            }
            {$flush} > .wp-block-column:has(> figure.card-media-thumb) > figure.card-media-thumb img {
                height: 100% !important;
                aspect-ratio: auto !important;
                border-radius: 0 !important;
            }

            CSS;
    }
}
