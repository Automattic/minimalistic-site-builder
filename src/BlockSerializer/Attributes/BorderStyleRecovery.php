<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Attributes;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonString;

/**
 * Recovers a `border-style` the saved markup declares but the block attributes
 * omit.
 *
 * A CSS border only renders when `border-style` is set, so generated markup
 * routinely writes `border-left-color` and `border-left-width` into the
 * delimiter while emitting all three declarations — including
 * `border-left-style:solid` — into the HTML, because that is what makes the
 * border visible. The canonical re-render then reproduces two declarations out
 * of three, and the paragraph signature guard rejects the block for the
 * difference.
 *
 * The authored intent is unambiguous here: the saved HTML states the style
 * outright. Sourcing it back into the attributes makes the re-render
 * byte-identical to what was authored, so the border survives exactly as
 * designed — strictly better than dropping the declaration or failing the run.
 * This is not one of Gutenberg's built-in validation repairs; it is a repair
 * for how markup gets generated here, which is why it lives outside
 * CompatibilityRepairs.
 *
 * Only the `style` longhand is recovered. Colors and widths arrive as rendered
 * CSS variables whose mapping back to `var:preset|…` form is lossy, and they
 * are not what goes missing in practice.
 */
final class BorderStyleRecovery
{
    /** CSS border-style keywords. Anything else in that slot is not a style. */
    private const KEYWORDS = [
        'none', 'hidden', 'dotted', 'dashed', 'solid',
        'double', 'groove', 'ridge', 'inset', 'outset',
    ];

    /** Sides addressable as `border-<side>-style`, plus the shorthand. */
    private const SIDES = ['top', 'right', 'bottom', 'left'];

    /**
     * Add any border style the root element declares but the delimiter omits,
     * returning the dotted attribute paths that were recovered.
     *
     * Mutates $attributes in place, exactly as the SupportDomainGuard pruners
     * do, because the normalizer overlays the raw comment back over the sourced
     * attributes near the end of the pass — a recovery applied only to the
     * working copy would be silently reverted by that overlay.
     *
     * @return list<string>
     */
    public static function apply(JsonObject $attributes, string $originalContent): array
    {
        if (trim($originalContent) === '' || preg_match('/^\s*<!--\s+wp:/', $originalContent) === 1) {
            return [];
        }
        $declared = self::rootBorderStyles($originalContent);
        if ($declared === []) {
            return [];
        }
        $style = $attributes->get('style');
        if (!$style instanceof JsonObject) {
            return [];
        }
        $border = $style->get('border');
        if (!$border instanceof JsonObject) {
            return [];
        }

        $recovered = [];
        foreach ($declared as $side => $keyword) {
            if ($side === '') {
                if (!$border->has('style')) {
                    $border->set('style', new JsonString($keyword));
                    $recovered[] = 'style.border.style';
                }
                continue;
            }
            // Only fill a side the delimiter already describes: a side absent
            // entirely was never authored as a border, and inventing one from a
            // stray declaration would be a guess rather than a recovery.
            $sideObject = $border->get($side);
            if (!$sideObject instanceof JsonObject || $sideObject->has('style')) {
                continue;
            }
            $sideObject->set('style', new JsonString($keyword));
            $recovered[] = "style.border.{$side}.style";
        }
        return $recovered;
    }

    /**
     * Border style keywords declared on the root element's inline style, keyed
     * by side ('' for the shorthand).
     *
     * @return array<string,string>
     */
    private static function rootBorderStyles(string $html): array
    {
        $root = HtmlFragment::parse($html)->root()->elementChildren()[0] ?? null;
        $style = $root?->attribute('style');
        if (!is_string($style) || $style === '') {
            return [];
        }
        $found = [];
        foreach (explode(';', $style) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            $value = strtolower(trim(substr($declaration, $colon + 1)));
            if (!in_array($value, self::KEYWORDS, true)) {
                continue;
            }
            if ($property === 'border-style') {
                $found[''] = $value;
                continue;
            }
            foreach (self::SIDES as $side) {
                if ($property === "border-{$side}-style") {
                    $found[$side] = $value;
                }
            }
        }
        return $found;
    }
}
