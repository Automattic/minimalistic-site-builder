<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ContrastMath;

/**
 * A card whose surface comes from the theme's own css keeps readable text
 * (frm PR-5k). luzia-like24's zigzag steps sat on a contrast band whose
 * light text every card inherited, while the theme css painted
 * `.card-style--flush` in the light base preset: white cards, white copy.
 * The section markup never sees that css, so this pass reads it, finds the
 * card-style classes it paints with a palette preset, and gives each such
 * card group the text colour the paint needs. A descendant that names the
 * opposite text preset loses it and inherits the card's.
 */
final class CardTextContract
{
    private const LIGHT_LUMINANCE = 0.40;

    /**
     * @return array{markup:string,repairs:list<array<string,string>>,warnings:list<string>}
     */
    public static function enforce(string $markup, string $part, string|array|null $themeJson): array
    {
        $paints = self::paintedCardStyles($themeJson);
        if ($paints === []) {
            return ['markup' => $markup, 'repairs' => [], 'warnings' => []];
        }
        $document = BlockMarkup::parse($markup);
        $warnings = [];
        $fixed = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'group') {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $style = null;
            foreach ($classes as $class) {
                if (isset($paints[$class])) {
                    $style = $class;
                    break;
                }
            }
            if ($style === null || isset($attrs['backgroundColor']) || isset($attrs['style']['color']['background'])) {
                continue;
            }
            $wanted = $paints[$style]['text'];
            $opposite = $wanted === 'contrast' ? 'base' : 'contrast';
            $changed = false;
            if ((string) ($attrs['textColor'] ?? '') !== $wanted) {
                $authored = (string) ($attrs['textColor'] ?? '');
                $attrs['textColor'] = $wanted;
                $document->setAttrs($index, $attrs);
                self::rewriteTextClasses($document, $index, $wanted);
                $changed = true;
                $warnings[] = "file='theme/parts/{$part}.html'; block='group " . $style . "'; authored=textColor "
                    . ($authored === '' ? 'inherited' : '"' . $authored . '"') . "; delivered=\"{$wanted}\""
                    . '; disposition=the theme css paints this card ' . $paints[$style]['slug']
                    . ', so the copy takes the colour that surface can carry';
            }
            foreach (self::descendants($document, $index) as $child) {
                $childAttrs = $document->attrs($child) ?? [];
                if ((string) ($childAttrs['textColor'] ?? '') !== $opposite) {
                    continue;
                }
                unset($childAttrs['textColor']);
                $document->setAttrs($child, $childAttrs);
                $document->removeClassTokenInOwnHtml($child, 'has-' . $opposite . '-color');
                $document->removeClassTokenInOwnHtml($child, 'has-text-color');
                $changed = true;
            }
            if ($changed) {
                $fixed[] = $style;
            }
        }
        if ($fixed === []) {
            return ['markup' => $markup, 'repairs' => [], 'warnings' => []];
        }
        return [
            'markup' => $document->render(),
            'repairs' => [[
                'code' => 'card-text-contract',
                'part' => $part,
                'authored' => count($fixed) . ' card(s) whose css paint contradicted the inherited text colour',
                'delivered' => 'card text colour set from the css paint',
                'disposition' => 'repaired',
            ]],
            'warnings' => $warnings,
        ];
    }

    /**
     * The card-style classes the theme's own css paints with a palette
     * preset, with the text preset that paint can carry.
     *
     * @return array<string,array{slug:string,text:string}>
     */
    public static function paintedCardStyles(string|array|null $themeJson): array
    {
        $theme = is_array($themeJson) ? $themeJson : null;
        if (is_string($themeJson) && trim($themeJson) !== '') {
            $decoded = json_decode($themeJson, true);
            $theme = is_array($decoded) ? $decoded : null;
        }
        if ($theme === null) {
            return [];
        }
        $css = (string) ($theme['styles']['css'] ?? '');
        if ($css === '' || !str_contains($css, 'card-style--')) {
            return [];
        }
        $palette = [];
        foreach ((array) ($theme['settings']['color']['palette'] ?? []) as $preset) {
            if (is_array($preset) && is_string($preset['slug'] ?? null) && is_string($preset['color'] ?? null)) {
                $palette[strtolower(trim($preset['slug']))] = $preset['color'];
            }
        }
        $paints = [];
        if (preg_match_all('/\.(card-style--[a-z]+)[^{}]*\{([^}]*)\}/i', $css, $rules, PREG_SET_ORDER) < 1) {
            return [];
        }
        foreach ($rules as $rule) {
            if (preg_match('/background(?:-color)?\s*:\s*var\(--wp--preset--color--([a-z0-9-]+)\)/i', $rule[2], $m) !== 1) {
                continue;
            }
            $slug = strtolower($m[1]);
            $rgb = isset($palette[$slug]) ? ContrastMath::hexToRgb($palette[$slug]) : null;
            if ($rgb === null) {
                continue;
            }
            $light = ContrastMath::luminance($rgb) > self::LIGHT_LUMINANCE;
            $paints[strtolower($rule[1])] = ['slug' => $slug, 'text' => $light ? 'contrast' : 'base'];
        }
        return $paints;
    }

    /** @return list<int> every descendant index, depth first */
    private static function descendants(BlockMarkup $document, int $index): array
    {
        $out = [];
        foreach ($document->children($index) as $child) {
            $out[] = $child;
            array_push($out, ...self::descendants($document, $child));
        }
        return $out;
    }

    /** One splice rewrites the card's opening tag with the wanted text preset classes. */
    private static function rewriteTextClasses(BlockMarkup $document, int $index, string $wanted): void
    {
        $own = $document->ownHtml($index);
        if (preg_match('/<div\b[^>]*>/', $own, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return;
        }
        $opening = $m[0][0];
        $clean = preg_replace_callback('/\sclass="([^"]*)"/', static function (array $c) use ($wanted): string {
            $tokens = preg_split('/\s+/', trim($c[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_filter($tokens, static fn (string $t): bool
                => preg_match('/^has-[a-z0-9-]+-color$/', $t) !== 1 && $t !== 'has-text-color'));
            $tokens[] = 'has-' . $wanted . '-color';
            $tokens[] = 'has-text-color';
            return ' class="' . implode(' ', $tokens) . '"';
        }, $opening, 1) ?? $opening;
        if ($clean !== $opening) {
            $document->spliceOwnHtml($index, (int) $m[0][1], strlen($opening), $clean);
        }
    }
}
