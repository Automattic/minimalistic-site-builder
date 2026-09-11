<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Warnings;
use Automattic\SiteBuild\MarkupScan;
use Automattic\SiteBuild\ContrastMath;

/** Prepare complete card shapes before the strict card contract runs. */
final class CardPreparation
{
    private const HOOKS = ['card-style--flush', 'card-style--framed', 'card-style--overlap', 'card-flush', 'card-body', 'overlap-up'];

    public static function enforce(string $markup, string $style, string $part, array &$repairs, array &$warnings, string|array|null $themeJson = null): string
    {
        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'group' || !$document->isStructurallySafe($index)) {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: [];
            if (array_intersect($classes, ['card-style--flush', 'card-style--framed', 'card-style--overlap', 'card-flush']) === []) {
                continue;
            }
            $inner = $document->innerHtml($index);
            $children = $document->children($index);
            $names = array_map(static fn ($child) => $document->name($child), $children);
            if (!preg_match('/<img\b|<!--\s*wp:(image|cover|media-text)\b/i', $inner)) {
                foreach ([$index, ...$children] as $target) {
                    if ($document->name($target) !== 'group' || !$document->isStructurallySafe($target)) {
                        continue;
                    }
                    $targetAttrs = $document->attrs($target) ?? [];
                    $tokens = preg_split('/\s+/', trim((string) ($targetAttrs['className'] ?? ''))) ?: [];
                    $removed = array_values(array_intersect($tokens, self::HOOKS));
                    if ($removed === []) {
                        continue;
                    }
                    $targetAttrs['className'] = implode(' ', array_diff($tokens, self::HOOKS));
                    $document->setAttrs($target, $targetAttrs);
                    foreach ($removed as $hook) {
                        $document->removeClassTokenInOwnHtml($target, $hook);
                    }
                    $warnings[] = "file='theme/parts/{$part}.html'; block='group[{$target}]'; authored=" . Warnings::value($removed)
                        . '; delivered=removed; disposition=removed image-card hooks from a text-only item; retained its surface and text';
                    $repairs[] = ['code' => 'text-item-card-hooks', 'part' => $part, 'block' => "group[{$target}]",
                        'authored' => implode(' ', $removed), 'delivered' => 'removed', 'disposition' => 'repaired'];
                }
                continue;
            }
            if ($style !== 'flush' || $names !== ['image', 'group']
                || isset($attrs['backgroundColor']) || isset($attrs['gradient'])
                || isset($attrs['style']['color']['background']) || isset($attrs['style']['color']['gradient'])
                || preg_match('/background(?:-color|-image)?\s*:/i', $document->ownHtml($index))) {
                continue;
            }
            $surface = self::surfaceForText($document, $index, $themeJson);
            $own = $document->ownHtml($index);
            $tag = MarkupScan::wrapperTag($own, 0);
            $class = $tag === null ? null : MarkupScan::tagAttribute($tag, 'class');
            if ($surface === null || $tag === null || !preg_match('/^\s*<div\b/i', $tag)
                || ($class === null && preg_match('/\sclass\s*=/i', $tag))) {
                $warnings[] = "file='theme/parts/{$part}.html'; block='group[{$index}]'; authored=absent card surface; delivered=unchanged;"
                    . ' disposition=retained the ambiguous wrapper or text color; repair the card surface';
                continue;
            }
            $tokens = $class === null ? [] : (preg_split('/\s+/', trim($class[0]), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $tokens[] = 'has-' . $surface . '-background-color';
            $tokens[] = 'has-background';
            $value = implode(' ', array_unique($tokens));
            $changed = $class === null
                ? substr_replace($tag, ' class="' . $value . '"', -1, 0)
                : substr_replace($tag, $value, $class[1], strlen($class[0]));
            $attrs['backgroundColor'] = $surface;
            $document->setAttrs($index, $attrs);
            $document->spliceOwnHtml($index, 0, strlen($tag), $changed);
            $warnings[] = "file='theme/parts/{$part}.html'; block='group[{$index}]'; authored=absent card surface; delivered="
                . Warnings::value($surface) . '; disposition=added the surface required by the assigned flush card; retained all content';
            $repairs[] = ['code' => 'flush-card-surface', 'part' => $part, 'block' => "group[{$index}]",
                'authored' => 'absent surface', 'delivered' => $surface, 'disposition' => 'repaired'];
        }
        return $document->render();
    }
    /** Choose the opposite surface only when each text region has known ink. */
    private static function surfaceForText(BlockMarkup $document, int $root, string|array|null $themeJson): ?string
    {
        $theme = is_string($themeJson) ? json_decode($themeJson, true) : $themeJson;
        $palette = $themeJson === null ? ['base' => [255, 255, 255], 'contrast' => [0, 0, 0]] : [];
        foreach ($theme['settings']['color']['palette'] ?? [] as $entry) {
            if (is_array($entry) && is_string($entry['slug'] ?? null) && is_string($entry['color'] ?? null)) {
                $rgb = ContrastMath::hexToRgb($entry['color']);
                if ($rgb !== null) {
                    $palette[$entry['slug']] = $rgb;
                }
            }
        }
        $defaultInk = 'contrast';
        $authoredInk = $theme['styles']['color']['text'] ?? null;
        if ($authoredInk !== null) {
            if (!is_string($authoredInk)) {
                return null;
            }
            if (preg_match('/^var:preset[|:]color[|:]([a-z0-9-]+)$/i', $authoredInk, $match)
                || preg_match('/^var\(--wp--preset--color--([a-z0-9-]+)\)$/i', $authoredInk, $match)) {
                $defaultInk = $match[1];
            } else {
                $rgb = ContrastMath::hexToRgb($authoredInk);
                if ($rgb === null) {
                    return null;
                }
                $defaultInk = '__theme-text';
                $palette[$defaultInk] = $rgb;
            }
        }
        $inks = [];
        $indices = [$root];
        foreach ($document->indices() as $index) {
            for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
                if ($parent === $root) {
                    $indices[] = $index;
                    break;
                }
            }
        }
        foreach ($indices as $index) {
            if ($index !== $root && !in_array($document->name($index), ['paragraph', 'heading', 'list', 'list-item', 'buttons'], true)) {
                continue;
            }
            $ink = $defaultInk;
            for ($node = $index; $node !== null; $node = $document->parent($node)) {
                $attrs = $document->attrs($node) ?? [];
                $tag = MarkupScan::wrapperTag($document->ownHtml($node), 0);
                $style = $tag === null ? null : MarkupScan::tagAttribute($tag, 'style');
                if (isset($attrs['style']['color']['text']) || ($style !== null && preg_match('/(?:^|;)\s*color\s*:/i', $style[0]))) {
                    return null;
                }
                if (isset($attrs['textColor'])) {
                    if (!is_string($attrs['textColor']) || !isset($palette[$attrs['textColor']])) {
                        return null;
                    }
                    $ink = $attrs['textColor'];
                    break;
                }
                $class = $tag === null ? null : MarkupScan::tagAttribute($tag, 'class');
                if ($class !== null && preg_match('/(?:^|\s)has-([a-z0-9-]+)-color(?:\s|$)/', $class[0], $match)) {
                    if (!isset($palette[$match[1]])) {
                        return null;
                    }
                    $ink = $match[1];
                    break;
                }
            }
            $inks[$ink] = true;
        }
        $best = null;
        $bestRatio = 4.5;
        foreach (['base', 'contrast'] as $surface) {
            if (!isset($palette[$surface])) {
                continue;
            }
            $ratio = INF;
            foreach (array_keys($inks) as $ink) {
                if (!isset($palette[$ink])) {
                    return null;
                }
                $ratio = min($ratio, ContrastMath::ratio($palette[$surface], $palette[$ink]));
            }
            if ($ratio >= 4.5 && ($best === null || $ratio > $bestRatio)) {
                $best = $surface;
                $bestRatio = $ratio;
            }
        }
        return $best;
    }

}
