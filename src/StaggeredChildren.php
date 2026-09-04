<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Flatten staggered sibling top offsets in a horizontal row.
 *
 * The section recipe's `staggered-grid` (and HTML-first copies of it) push
 * every second column down with a top margin so items don't share a baseline.
 * That rhythm reads as a defect wherever the build did not assign it, so
 * fix-blocks runs this pass on every section except one assigned the
 * offset-grid archetype. It equalizes sibling top margins in horizontal
 * rows; stacked groups and uniformly offset siblings stay untouched.
 * Idempotent. Generated defects never throw.
 */
final class StaggeredChildren
{
    /**
     * @return array{markup:string, notes:list<string>}
     */
    public static function flatten(string $markup): array
    {
        try {
            return self::flattenDocument($markup);
        } catch (\Throwable) {
            return ['markup' => $markup, 'notes' => []];
        }
    }

    /**
     * @return array{markup:string, notes:list<string>}
     */
    private static function flattenDocument(string $markup): array
    {
        $document = BlockMarkup::parse($markup);

        $notes = [];
        foreach ($document->indices() as $parent) {
            if (!$document->isStructurallySafe($parent) || !self::isHorizontalRow($document, $parent)) {
                continue;
            }
            $children = $document->children($parent);
            if (count($children) < 2) {
                continue;
            }
            $unsafe = false;
            foreach ($children as $child) {
                if (!$document->isStructurallySafe($child)) {
                    $unsafe = true;
                    break;
                }
            }
            if ($unsafe) {
                continue;
            }

            $carriers = [];
            $tops = [];
            foreach ($children as $child) {
                [$top, $carrier] = self::topMarginCarrier($document, $child);
                $tops[] = $top;
                if ($carrier !== null) {
                    $carriers[] = $carrier;
                }
            }
            if (count(array_unique($tops, SORT_STRING)) < 2 || $carriers === []) {
                continue;
            }
            $recipeSized = false;
            foreach ($tops as $top) {
                if (self::isRecipeStaggerOffset($top)) {
                    $recipeSized = true;
                    break;
                }
            }
            if (!$recipeSized) {
                continue;
            }

            foreach (array_unique($carriers, SORT_NUMERIC) as $carrier) {
                self::clearTopMargin($document, $carrier);
            }
            $notes[] = 'flattened staggered top offsets on a ' . $document->name($parent) . ' row';
        }

        return [
            'markup' => $notes === [] ? $markup : $document->render(),
            'notes'  => $notes,
        ];
    }

    private static function isHorizontalRow(BlockMarkup $document, int $i): bool
    {
        $name = $document->name($i);
        if ($name === 'columns') {
            return true;
        }
        if ($name !== 'group') {
            return false;
        }
        $attrs = $document->attrs($i) ?? [];
        $layout = $attrs['layout'] ?? null;
        if (!is_array($layout)) {
            return false;
        }
        $type = (string) ($layout['type'] ?? '');
        if ($type === 'grid') {
            return true;
        }
        if ($type !== 'flex') {
            return false;
        }
        return ($layout['orientation'] ?? 'horizontal') !== 'vertical';
    }

    /**
     * @return array{0:string,1:?int}
     */
    private static function topMarginCarrier(BlockMarkup $document, int $child): array
    {
        $own = self::topMargin($document, $child);
        if (self::isRecipeStaggerOffset($own)) {
            return [$own, $child];
        }
        $firstNonEmpty = $own === '' ? null : [$own, $child];
        foreach ($document->children($child) as $inner) {
            $top = self::topMargin($document, $inner);
            if ($top === '') {
                continue;
            }
            $firstNonEmpty ??= [$top, $inner];
            if (self::isRecipeStaggerOffset($top)) {
                return [$top, $inner];
            }
        }
        return $firstNonEmpty ?? ['', null];
    }

    /**
     * The staggered-grid recipe pushes every second card by 3rem or 4rem.
     * Preset spacing on one column of a split is not that pattern.
     */
    private static function isRecipeStaggerOffset(string $top): bool
    {
        if ($top === '' || preg_match('/^(\d+(?:\.\d+)?)rem$/i', $top, $match) !== 1) {
            return false;
        }
        return (float) $match[1] >= 2.0;
    }

    private static function topMargin(BlockMarkup $document, int $i): string
    {
        $attrs = $document->attrs($i) ?? [];
        $style = $attrs['style'] ?? null;
        if (!is_array($style)) {
            return '';
        }
        $spacing = $style['spacing'] ?? null;
        if (!is_array($spacing)) {
            return '';
        }
        $margin = $spacing['margin'] ?? null;
        if (!is_array($margin) || !array_key_exists('top', $margin)) {
            return '';
        }
        $top = $margin['top'];
        return is_string($top) || is_int($top) || is_float($top) ? (string) $top : '';
    }

    private static function clearTopMargin(BlockMarkup $document, int $i): void
    {
        self::clearCommentTopMargin($document, $i);
        self::clearInlineMarginTop($document, $i);
    }

    private static function clearCommentTopMargin(BlockMarkup $document, int $i): void
    {
        $attrs = $document->attrs($i) ?? [];
        $style = $attrs['style'] ?? null;
        if (!is_array($style)) {
            return;
        }
        $spacing = $style['spacing'] ?? null;
        if (!is_array($spacing)) {
            return;
        }
        $margin = $spacing['margin'] ?? null;
        if (!is_array($margin) || !array_key_exists('top', $margin)) {
            return;
        }
        unset($margin['top']);
        if ($margin === []) {
            unset($spacing['margin']);
        } else {
            $spacing['margin'] = $margin;
        }
        if ($spacing === []) {
            unset($style['spacing']);
        } else {
            $style['spacing'] = $spacing;
        }
        if ($style === []) {
            unset($attrs['style']);
        } else {
            $attrs['style'] = $style;
        }
        $document->setAttrs($i, $attrs);
    }

    private static function clearInlineMarginTop(BlockMarkup $document, int $i): void
    {
        $own = $document->ownHtml($i);
        $tag = MarkupScan::wrapperTag($own, 0);
        if ($tag === null
            || preg_match(
                '/[\x20\t\r\n\f]style\s*=\s*(["\'])(.*?)\1/i',
                $tag,
                $match,
                PREG_OFFSET_CAPTURE
            ) !== 1) {
            return;
        }
        $value = $match[2][0];
        $stripped = self::withoutTopMarginDeclaration($value);
        if ($stripped === $value) {
            return;
        }
        if (trim($stripped, " \t;") === '') {
            $document->spliceOwnHtml($i, $match[0][1], strlen($match[0][0]), '');
            return;
        }
        $document->spliceOwnHtml($i, $match[2][1], strlen($value), $stripped);
    }

    private static function withoutTopMarginDeclaration(string $style): string
    {
        $kept = [];
        foreach (MarkupScan::parseInlineStyle($style) as $declaration) {
            if ($declaration['property'] === 'margin-top') {
                continue;
            }
            if ($declaration['property'] === '' && $declaration['value'] === null) {
                continue;
            }
            $kept[] = $declaration['segment'];
        }
        return implode(';', $kept);
    }
}
