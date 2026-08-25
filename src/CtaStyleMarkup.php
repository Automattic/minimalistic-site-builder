<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Remove per-button construction that would outrank the committed CTA style. */
final class CtaStyleMarkup
{
    private const VARIATIONS = ['is-style-outline', 'is-style-fill'];

    /**
     * @return array{markup:string,changes:list<array{blockPath:string,blockName:string,
     *     property:string,authored:mixed,delivered:mixed,disposition:string}>}
     */
    public static function normalize(string $markup, ?string $style): array
    {
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
            foreach ($tokens as $token) {
                if (!str_starts_with($token, 'wp-block-button__width-')
                    || ($style === 'block' && $token === 'wp-block-button__width-100')
                ) {
                    continue;
                }
                $tokens = array_values(array_filter(
                    $tokens,
                    static fn (string $candidate): bool => $candidate !== $token,
                ));
                $changed = true;
                self::record($changes, $path, 'className', $token, null, $style);
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
            if ($style === 'block' && !in_array('wp-block-button__width-100', $tokens, true)) {
                $tokens[] = 'wp-block-button__width-100';
                $changed = true;
                self::record(
                    $changes,
                    $path,
                    'className',
                    null,
                    'wp-block-button__width-100',
                    $style,
                );
            }
            $deliveredClassName = implode(' ', $tokens);
            if ($deliveredClassName === '') {
                unset($attrs['className']);
            } else {
                $attrs['className'] = $deliveredClassName;
            }

            $authoredWidth = $attrs['width'] ?? null;
            $htmlWidths = self::ownHtmlWidthClassValues($doc, $i);
            foreach ($htmlWidths as $width) {
                $token = 'wp-block-button__width-' . $width;
                if ($style !== 'block' || $width !== '100') {
                    $doc->removeClassTokenInOwnHtml($i, $token);
                }
            }
            if ($style === 'block') {
                if (!in_array('100', $htmlWidths, true)) {
                    $doc->replaceClassTokenInOwnHtml(
                        $i,
                        'wp-block-button',
                        'wp-block-button wp-block-button__width-100',
                    );
                }
                if (array_key_exists('width', $attrs) || $htmlWidths !== ['100']) {
                    unset($attrs['width']);
                    $changed = true;
                    self::record(
                        $changes,
                        $path,
                        'width',
                        $authoredWidth,
                        'wp-block-button__width-100 class',
                        $style,
                    );
                }
            } elseif (array_key_exists('width', $attrs) || $htmlWidths !== []) {
                unset($attrs['width']);
                $changed = true;
                self::record($changes, $path, 'width', $authoredWidth, null, $style);
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

    /** @return list<string> exact suffixes of every saved width class token */
    private static function ownHtmlWidthClassValues(BlockMarkup $doc, int $i): array
    {
        $values = [];
        if (preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $doc->ownHtml($i), $matches) < 1) {
            return [];
        }
        foreach ($matches[2] as $classValue) {
            foreach (preg_split('/[\x20\t\r\n\f]+/', trim($classValue), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (preg_match('/^wp-block-button__width-(.+)$/', $token, $width) === 1) {
                    $values[$width[1]] = true;
                }
            }
        }
        return array_map(static fn (int|string $value): string => (string) $value, array_keys($values));
    }

    /** @param list<array<mixed>> $changes */
    private static function record(
        array &$changes,
        string $path,
        string $property,
        mixed $authored,
        mixed $delivered,
        string $style,
    ): void {
        $changes[] = [
            'blockPath' => $path,
            'blockName' => 'core/button',
            'property' => $property,
            'authored' => $authored,
            'delivered' => $delivered,
            'disposition' => 'enforced committed ' . $style . ' CTA construction',
        ];
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
