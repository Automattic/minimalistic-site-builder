<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Warnings;

/** Prepare complete card shapes before the strict card contract runs. */
final class CardPreparation
{
    private const HOOKS = ['card-style--flush', 'card-style--framed', 'card-style--overlap', 'card-flush', 'card-body', 'overlap-up'];

    public static function enforce(string $markup, string $style, string $part, array &$repairs, array &$warnings): string
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
            $surface = ($attrs['textColor'] ?? '') === 'base' ? 'contrast' : 'base';
            $attrs['backgroundColor'] = $surface;
            $document->setAttrs($index, $attrs);
            $own = $document->ownHtml($index);
            $changed = preg_replace('/\bclass="/', 'class="has-' . $surface . '-background-color has-background ', $own, 1);
            if (is_string($changed) && $changed !== $own) {
                $document->spliceOwnHtml($index, 0, strlen($own), $changed);
            }
            $warnings[] = "file='theme/parts/{$part}.html'; block='group[{$index}]'; authored=absent card surface; delivered="
                . Warnings::value($surface) . '; disposition=added the surface required by the assigned flush card; retained all content';
            $repairs[] = ['code' => 'flush-card-surface', 'part' => $part, 'block' => "group[{$index}]",
                'authored' => 'absent surface', 'delivered' => $surface, 'disposition' => 'repaired'];
        }
        return $document->render();
    }
}
