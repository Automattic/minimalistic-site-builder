<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * Deterministic tree normalization, applied between the model's output and
 * the gates. Ported from the x-pipeline's lib/normalize.mjs. One rule so far:
 *
 * The flat-borderColor footgun. A tree that sets the flat `borderColor`
 * preset attribute alongside a PER-SIDE style.border ships borders nobody
 * designed: WordPress emits `has-border-color`, whose CSS paints border-style
 * solid on ALL FOUR sides, and every side without a declared width then
 * renders at the browser's default `medium` (3px). The model's intent — a
 * colored rule on the declared side(s) only — is recoverable mechanically:
 * fold the colour into each declared side and drop the flat attribute. A flat
 * borderColor WITHOUT per-side entries is left alone: there the all-sides box
 * is the intent.
 */
final class Normalize
{
    private const SIDES = ['top', 'right', 'bottom', 'left'];

    /**
     * Fold flat borderColor attributes into their per-side style.border
     * entries, in place. Returns the number of nodes folded.
     *
     * @param array<string,mixed> $tree Mutated in place.
     */
    public static function normalizeTreeBorders(array &$tree): int
    {
        $folded = 0;
        $walk = function (array &$node) use (&$walk, &$folded): void {
            if (is_array($node['attributes'] ?? null)
                && is_string($node['attributes']['borderColor'] ?? null) && $node['attributes']['borderColor'] !== ''
                && is_array($node['attributes']['style']['border'] ?? null)
            ) {
                $attrs = &$node['attributes'];
                $border = &$attrs['style']['border'];
                $hasSideObject = false;
                foreach (self::SIDES as $side) {
                    if (self::isSideObject($border[$side] ?? null)) {
                        $hasSideObject = true;
                        break;
                    }
                }
                if ($hasSideObject) {
                    foreach (self::SIDES as $side) {
                        if (self::isSideObject($border[$side] ?? null)) {
                            $entry = array_merge(['style' => 'solid'], $border[$side]);
                            $entry['color'] = $border[$side]['color'] ?? 'var:preset|color|' . $attrs['borderColor'];
                            $border[$side] = $entry;
                        }
                    }
                    unset($attrs['borderColor']);
                    $folded++;
                }
                unset($border, $attrs);
            }
            if (isset($node['innerBlocks']) && is_array($node['innerBlocks'])) {
                foreach ($node['innerBlocks'] as &$child) {
                    if (is_array($child)) {
                        $walk($child);
                    }
                }
                unset($child);
            }
        };
        if (isset($tree['blocks']) && is_array($tree['blocks'])) {
            foreach ($tree['blocks'] as &$node) {
                if (is_array($node)) {
                    $walk($node);
                }
            }
            unset($node);
        }
        return $folded;
    }

    /**
     * A per-side border entry: a JSON object ({width, style, color} subset).
     * An empty array is the decoded form of `{}` and counts as one.
     */
    private static function isSideObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
