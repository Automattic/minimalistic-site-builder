<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Remove per-button construction that would outrank the committed CTA style.
 *
 * Width is a container decision, not a style decision. A `block` button keeps
 * a model-authored full width only inside a narrow container: a card, or a
 * column chain whose share of the theme's contentSize is at most
 * CtaStyle::NARROW_CONTAINER_SHARE. Everywhere else, and for every other
 * style, an authored width is removed and the button keeps its intrinsic
 * width. The normalizer never adds a width of its own.
 */
final class CtaStyleMarkup
{
    private const VARIATIONS = ['is-style-outline', 'is-style-fill'];
    private const FULL_WIDTH_CLASS = 'wp-block-button__width-100';
    private const CUSTOM_WIDTH_CLASS = 'has-custom-width';

    /**
     * @param float|null $contentSize theme.json settings.layout.contentSize in px
     * @param float|null $wideSize theme.json settings.layout.wideSize in px
     * @return array{markup:string,changes:list<array{blockPath:string,blockName:string,
     *     property:string,authored:mixed,delivered:mixed,disposition:string}>}
     */
    public static function normalize(
        string $markup,
        ?string $style,
        ?float $contentSize = null,
        ?float $wideSize = null,
    ): array {
        $style = CtaStyle::explicit($style);
        if ($style === null) {
            return ['markup' => $markup, 'changes' => []];
        }

        $doc = BlockMarkup::parse($markup);
        $paths = self::blockPaths($doc);
        $changes = [];
        foreach ($doc->indices() as $i) {
            if (!$doc->isStructurallySafe($i)) {
                continue;
            }
            $name = $doc->name($i);
            $name = str_contains($name, '/') ? $name : 'core/' . $name;
            if ($name !== 'core/button') {
                continue;
            }

            $attrs = $doc->attrs($i) ?? [];
            $path = $paths[$i] ?? (string) $i;
            $changed = false;
            foreach (['backgroundColor', 'textColor', 'gradient'] as $property) {
                if (!array_key_exists($property, $attrs)) {
                    continue;
                }
                $authored = $attrs[$property];
                unset($attrs[$property]);
                $changed = true;
                self::record($changes, $path, $property, $authored, null, $style);
                foreach (self::presetClasses($property, $authored) as $token) {
                    $doc->removeClassTokenInOwnHtml($i, $token);
                }
            }

            $localStyle = $attrs['style'] ?? null;
            if (is_array($localStyle) && ($localStyle === [] || !array_is_list($localStyle))) {
                self::removeStyleColor($localStyle, $path, $style, $changes, $changed);
                self::removeStyleBorder($localStyle, $path, $style, $changes, $changed);
                self::removeStylePadding($localStyle, $path, $style, $changes, $changed);
                if (is_string($localStyle['css'] ?? null)) {
                    [$deliveredCss, $dropped] = CssChecks::dropDeclarations(
                        $localStyle['css'],
                        static fn (array $declaration): bool => CssChecks::isCtaAffectingDeclaration(
                            $declaration['property'],
                            $declaration['value'],
                        ),
                        true,
                    );
                    if ($dropped !== []) {
                        $changed = true;
                        if (trim($deliveredCss) === '') {
                            unset($localStyle['css']);
                        } else {
                            $localStyle['css'] = $deliveredCss;
                        }
                        foreach ($dropped as $declaration) {
                            self::record(
                                $changes,
                                $path,
                                'style.css declaration',
                                trim($declaration['raw']),
                                null,
                                $style,
                            );
                        }
                    }
                }
                if ($localStyle === []) {
                    unset($attrs['style']);
                } else {
                    $attrs['style'] = $localStyle;
                }
            }

            $className = is_string($attrs['className'] ?? null) ? $attrs['className'] : '';
            $tokens = preg_split('/\s+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $authoredWidth = $attrs['width'] ?? null;
            $htmlWidths = self::ownHtmlWidthClassValues($doc, $i);
            $rootHtmlWidths = self::ownHtmlWidthClassValues($doc, $i, true);
            $authoredFullWidth = (is_int($authoredWidth) || is_float($authoredWidth) || is_string($authoredWidth))
                && (float) $authoredWidth === 100.0
                || in_array(self::FULL_WIDTH_CLASS, $tokens, true)
                || in_array('100', $htmlWidths, true);
            $container = self::container($doc, $i, $contentSize, $wideSize);
            $keepFullWidth = $style === 'block' && $authoredFullWidth && $container['narrow'];
            $widthDisposition = $style === 'block' && $authoredFullWidth && !$container['narrow']
                ? 'removed full width outside a narrow container (' . $container['reason']
                    . '); enforced committed block CTA construction'
                : null;

            foreach ($tokens as $token) {
                if ($token === self::CUSTOM_WIDTH_CLASS) {
                    // Only the width support attribute emits this class, and
                    // the frozen serializer never keeps that attribute.
                    $tokens = array_values(array_filter(
                        $tokens,
                        static fn (string $candidate): bool => $candidate !== $token,
                    ));
                    $changed = true;
                    self::record($changes, $path, 'className', $token, null, $style, $widthDisposition);
                    continue;
                }
                if (!str_starts_with($token, 'wp-block-button__width-')
                    || ($keepFullWidth && $token === self::FULL_WIDTH_CLASS)
                ) {
                    continue;
                }
                $tokens = array_values(array_filter(
                    $tokens,
                    static fn (string $candidate): bool => $candidate !== $token,
                ));
                $changed = true;
                self::record($changes, $path, 'className', $token, null, $style, $widthDisposition);
            }
            foreach (self::VARIATIONS as $variation) {
                $inAttrs = in_array($variation, $tokens, true);
                $inHtml = self::ownHtmlHasClassToken($doc, $i, $variation);
                if (!$inAttrs && !$inHtml) {
                    $doc->removeClassTokenInOwnHtml($i, $variation);
                    continue;
                }
                $tokens = array_values(array_filter(
                    $tokens,
                    static fn (string $token): bool => $token !== $variation,
                ));
                $doc->removeClassTokenInOwnHtml($i, $variation);
                $changed = true;
                self::record($changes, $path, 'className', $variation, null, $style);
            }
            if ($keepFullWidth && !in_array(self::FULL_WIDTH_CLASS, $tokens, true)) {
                // The model authored the full width (attribute or saved
                // class); the class on className is its canonical form.
                $tokens[] = self::FULL_WIDTH_CLASS;
                $changed = true;
                self::record(
                    $changes,
                    $path,
                    'className',
                    null,
                    self::FULL_WIDTH_CLASS,
                    $style,
                    'kept authored full width in a narrow container (' . $container['reason']
                        . '); enforced committed block CTA construction',
                );
            }
            $deliveredClassName = implode(' ', $tokens);
            if ($deliveredClassName === '') {
                unset($attrs['className']);
            } else {
                $attrs['className'] = $deliveredClassName;
            }

            $canonicalFullWidthHtml = $keepFullWidth
                && $htmlWidths === ['100']
                && $rootHtmlWidths === ['100'];
            foreach (array_values(array_unique($htmlWidths)) as $width) {
                if (!$canonicalFullWidthHtml) {
                    $doc->removeClassTokenInOwnHtml($i, 'wp-block-button__width-' . $width);
                }
            }
            if (self::ownHtmlHasClassToken($doc, $i, self::CUSTOM_WIDTH_CLASS)) {
                $doc->removeClassTokenInOwnHtml($i, self::CUSTOM_WIDTH_CLASS);
            }
            if ($keepFullWidth) {
                if (!$canonicalFullWidthHtml) {
                    $doc->replaceClassTokenInOwnHtml(
                        $i,
                        'wp-block-button',
                        'wp-block-button ' . self::FULL_WIDTH_CLASS,
                    );
                }
                if (array_key_exists('width', $attrs) || !$canonicalFullWidthHtml) {
                    unset($attrs['width']);
                    $changed = true;
                    self::record(
                        $changes,
                        $path,
                        'width',
                        $authoredWidth,
                        self::FULL_WIDTH_CLASS . ' class',
                        $style,
                    );
                }
            } elseif (array_key_exists('width', $attrs) || $htmlWidths !== []) {
                unset($attrs['width']);
                $changed = true;
                $authored = $authoredWidth ?? (
                    $htmlWidths === []
                        ? null
                        : 'wp-block-button__width-' . implode('/', array_unique($htmlWidths)) . ' class'
                );
                self::record($changes, $path, 'width', $authored, null, $style, $widthDisposition);
            }

            if ($style === 'block' && !$keepFullWidth) {
                self::relaxStretchedContainer($doc, $i, $paths, $style, $container['reason'], $changes);
            }

            if ($changed) {
                // No local construction remains, so stale generic classes must
                // not be rescued into className by the following serializer.
                foreach (['has-background', 'has-text-color', 'has-background-gradient'] as $token) {
                    $doc->removeClassTokenInOwnHtml($i, $token);
                }
                $doc->setAttrs($i, $attrs);
            }
        }

        return ['markup' => $doc->render(), 'changes' => $changes];
    }

    /** @param array<mixed> $style @param list<array<mixed>> $changes */
    private static function removeStyleColor(
        array &$style,
        string $path,
        string $cta,
        array &$changes,
        bool &$changed,
    ): void {
        $color = $style['color'] ?? null;
        if (!is_array($color) || ($color !== [] && array_is_list($color))) {
            return;
        }
        foreach (['background', 'text', 'gradient'] as $property) {
            if (!array_key_exists($property, $color)) {
                continue;
            }
            $authored = $color[$property];
            unset($color[$property]);
            $changed = true;
            self::record($changes, $path, 'style.color.' . $property, $authored, null, $cta);
        }
        if ($color === []) {
            unset($style['color']);
        } else {
            $style['color'] = $color;
        }
    }

    /** @param array<mixed> $style @param list<array<mixed>> $changes */
    private static function removeStyleBorder(
        array &$style,
        string $path,
        string $cta,
        array &$changes,
        bool &$changed,
    ): void {
        $border = $style['border'] ?? null;
        if (!is_array($border) || ($border !== [] && array_is_list($border))) {
            return;
        }
        foreach (array_keys($border) as $property) {
            if ($property === 'radius') {
                continue;
            }
            $authored = $border[$property];
            unset($border[$property]);
            $changed = true;
            self::record($changes, $path, 'style.border.' . $property, $authored, null, $cta);
        }
        if ($border === []) {
            unset($style['border']);
        } else {
            $style['border'] = $border;
        }
    }

    /** @param array<mixed> $style @param list<array<mixed>> $changes */
    private static function removeStylePadding(
        array &$style,
        string $path,
        string $cta,
        array &$changes,
        bool &$changed,
    ): void {
        $spacing = $style['spacing'] ?? null;
        if (!is_array($spacing)
            || ($spacing !== [] && array_is_list($spacing))
            || !array_key_exists('padding', $spacing)
        ) {
            return;
        }
        $authored = $spacing['padding'];
        unset($spacing['padding']);
        $changed = true;
        self::record($changes, $path, 'style.spacing.padding', $authored, null, $cta);
        if ($spacing === []) {
            unset($style['spacing']);
        } else {
            $style['spacing'] = $spacing;
        }
    }

    /** @return list<string> */
    private static function presetClasses(string $property, mixed $value): array
    {
        if (!is_string($value) || preg_match('/^[a-z0-9-]+$/i', $value) !== 1) {
            return [];
        }
        return match ($property) {
            'backgroundColor' => ['has-' . $value . '-background-color'],
            'textColor' => ['has-' . $value . '-color'],
            'gradient' => ['has-' . $value . '-gradient-background'],
            default => [],
        };
    }

    private static function ownHtmlHasClassToken(BlockMarkup $doc, int $i, string $token): bool
    {
        if (preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $doc->ownHtml($i), $matches) < 1) {
            return false;
        }
        foreach ($matches[2] as $value) {
            $tokens = preg_split('/[\x20\t\r\n\f]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array($token, $tokens, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> exact suffixes of saved width class tokens, in appearance order */
    private static function ownHtmlWidthClassValues(
        BlockMarkup $doc,
        int $i,
        bool $rootOnly = false,
    ): array
    {
        $values = [];
        $html = $doc->ownHtml($i);
        if ($rootOnly) {
            if (preg_match('/<[a-z][^>]*>/i', $html, $root) !== 1) {
                return [];
            }
            $html = $root[0];
        }
        if (preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $matches) < 1) {
            return [];
        }
        foreach ($matches[2] as $classValue) {
            foreach (preg_split('/[\x20\t\r\n\f]+/', trim($classValue), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (preg_match('/^wp-block-button__width-(.+)$/', $token, $width) === 1) {
                    $values[] = $width[1];
                }
            }
        }
        return $values;
    }

    /** @param list<array<mixed>> $changes */
    private static function record(
        array &$changes,
        string $path,
        string $property,
        mixed $authored,
        mixed $delivered,
        string $style,
        ?string $disposition = null,
        string $blockName = 'core/button',
    ): void {
        $changes[] = [
            'blockPath' => $path,
            'blockName' => $blockName,
            'property' => $property,
            'authored' => $authored,
            'delivered' => $delivered,
            'disposition' => $disposition ?? ('enforced committed ' . $style . ' CTA construction'),
        ];
    }

    /**
     * A vertical wp:buttons container with `justifyContent: stretch` widens
     * every child wrapper to the container. Outside a narrow container that
     * is the same band the removed width drew, so drop it with the width.
     *
     * @param array<int,string> $paths
     * @param list<array<mixed>> $changes
     */
    private static function relaxStretchedContainer(
        BlockMarkup $doc,
        int $button,
        array $paths,
        string $style,
        string $reason,
        array &$changes,
    ): void {
        $parent = $doc->parent($button);
        if ($parent === null || self::coreName($doc->name($parent)) !== 'core/buttons') {
            return;
        }
        $attrs = $doc->attrs($parent) ?? [];
        $layout = $attrs['layout'] ?? null;
        if (!is_array($layout) || ($layout['justifyContent'] ?? null) !== 'stretch') {
            return;
        }
        unset($layout['justifyContent']);
        if ($layout === []) {
            unset($attrs['layout']);
        } else {
            $attrs['layout'] = $layout;
        }
        $doc->setAttrs($parent, $attrs);
        $doc->removeClassTokenInOwnHtml($parent, 'is-content-justification-stretch');
        self::record(
            $changes,
            $paths[$parent] ?? (string) $parent,
            'layout.justifyContent',
            'stretch',
            null,
            $style,
            'removed stretched container outside a narrow container (' . $reason
                . '); enforced committed block CTA construction',
            'core/buttons',
        );
    }

    /**
     * How much of the theme's contentSize the button's container spans.
     *
     * A card is always narrow. Otherwise the share is the product of every
     * ancestor column's share of its row, scaled by wideSize/contentSize when
     * the outermost row is aligned wide or full. A button with no column or
     * card ancestor sits at content width.
     *
     * @return array{narrow:bool,reason:string}
     */
    private static function container(
        BlockMarkup $doc,
        int $button,
        ?float $contentSize,
        ?float $wideSize,
    ): array {
        $share = 1.0;
        $wideFactor = 1.0;
        $sawColumn = false;
        for ($node = $doc->parent($button); $node !== null; $node = $doc->parent($node)) {
            $name = self::coreName($doc->name($node));
            $attrs = $doc->attrs($node) ?? [];
            if ($name === 'core/group' && self::isCard($attrs)) {
                return ['narrow' => true, 'reason' => 'inside a card'];
            }
            if ($name !== 'core/column') {
                continue;
            }
            $sawColumn = true;
            $share *= self::columnShare($doc, $node, $contentSize);
            $row = $doc->parent($node);
            $rowAlign = $row === null ? null : ($doc->attrs($row)['align'] ?? null);
            // Only the outermost row's alignment reaches the viewport; the
            // walk is inner to outer, so the last assignment wins.
            $wideFactor = in_array($rowAlign, ['wide', 'full'], true)
                && $contentSize !== null && $wideSize !== null && $contentSize > 0
                ? $wideSize / $contentSize
                : 1.0;
        }
        if (!$sawColumn) {
            return ['narrow' => false, 'reason' => 'no column or card ancestor, the button sits at content width'];
        }
        $total = $share * $wideFactor;
        return [
            'narrow' => $total <= CtaStyle::NARROW_CONTAINER_SHARE + 0.005,
            'reason' => sprintf('column share %d%% of the content width', (int) round($total * 100)),
        ];
    }

    /** The column's share of its row; unsized columns split what sized siblings leave. */
    private static function columnShare(BlockMarkup $doc, int $column, ?float $contentSize): float
    {
        $own = self::columnWidthShare($doc->attrs($column)['width'] ?? null, $contentSize);
        if ($own !== null) {
            return $own;
        }
        $row = $doc->parent($column);
        $siblings = $row === null ? [$column] : array_values(array_filter(
            $doc->children($row),
            fn (int $child): bool => self::coreName($doc->name($child)) === 'core/column',
        ));
        $sized = 0.0;
        $unsized = 0;
        foreach ($siblings as $sibling) {
            $width = self::columnWidthShare($doc->attrs($sibling)['width'] ?? null, $contentSize);
            if ($width === null) {
                $unsized++;
            } else {
                $sized += $width;
            }
        }
        if ($unsized === 0) {
            return 1.0 / max(1, count($siblings));
        }
        return max(0.0, 1.0 - $sized) / $unsized;
    }

    private static function columnWidthShare(mixed $width, ?float $contentSize): ?float
    {
        if (!is_string($width) && !is_int($width) && !is_float($width)) {
            return null;
        }
        $width = trim((string) $width);
        if (preg_match('/^([0-9.]+)%$/', $width, $m) === 1) {
            return ((float) $m[1]) / 100;
        }
        if (preg_match('/^([0-9.]+)px$/', $width, $m) === 1 && $contentSize !== null && $contentSize > 0) {
            return ((float) $m[1]) / $contentSize;
        }
        return null;
    }

    /** @param array<mixed> $attrs */
    private static function isCard(array $attrs): bool
    {
        $className = is_string($attrs['className'] ?? null) ? $attrs['className'] : '';
        foreach (preg_split('/\s+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (str_starts_with($token, 'card-style--') || $token === 'card-body' || $token === 'card-flush') {
                return true;
            }
        }
        return false;
    }

    private static function coreName(string $name): string
    {
        return str_contains($name, '/') ? $name : 'core/' . $name;
    }

    /** @return array<int,string> */
    private static function blockPaths(BlockMarkup $doc): array
    {
        $paths = [];
        $root = 0;
        foreach ($doc->indices() as $i) {
            if ($doc->parent($i) !== null) {
                continue;
            }
            self::collectPaths($doc, $i, (string) $root, $paths);
            $root++;
        }
        return $paths;
    }

    /** @param array<int,string> $paths */
    private static function collectPaths(BlockMarkup $doc, int $i, string $path, array &$paths): void
    {
        $paths[$i] = $path;
        foreach ($doc->children($i) as $childIndex => $child) {
            self::collectPaths($doc, $child, $path . '/' . $childIndex, $paths);
        }
    }
}
