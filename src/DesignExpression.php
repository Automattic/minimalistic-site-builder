<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Blocks-path decorative paint, not another style recipe. Authors choose the
 * geometry; this boundary keeps it away from content, media and interactions.
 * Delivery warnings prove presence of hooks/paint, NOT visual style fidelity.
 */
final class DesignExpression
{
    public const CLASSES = [
        'design-frame' => 'borders on a generic wp:group wrapper: border[-side], border[-side]-width/style/color, border-radius only; widths 0–8px; no background, padding, shadow, or content styling',
        'design-motif' => 'empty ::before and/or ::after decoration on a generic wp:group: gradients, background-size/position/repeat, borders, border-radius, clip-path; width 0–100% or 0–16rem/256px, height 0–12rem/192px; normal flow, never an overlay; do not style the wrapper itself',
    ];

    /** @return list<string> Explicit hook commitments; never infer them from prose. */
    public static function normalizeHooks(mixed $authored, array &$warnings = []): array
    {
        if ($authored === null || $authored === []) {
            return [];
        }
        $hooks = is_array($authored) && array_is_list($authored) ? $authored : [$authored];
        $out = [];
        foreach ($hooks as $hook) {
            if (is_string($hook) && isset(self::CLASSES[$hook])) {
                $out[$hook] = true;
            } else {
                $warnings[] = "file='designDirection.json'; path='style_hooks'; authored=" . Warnings::value($hook)
                    . '; delivered=removed; disposition=unsupported decorative hook; surviving commitments retained';
            }
        }
        return array_keys($out);
    }

    /** Only exact selectors; no descendant/sibling escape or nested selectors. */
    public static function selectorAllowed(string $selector): bool
    {
        return preg_match('/^\.(?:design-frame|design-motif::(?:before|after))$/D', trim($selector)) === 1;
    }

    /** @return list<string> Actual HTML group classes, not JSON comments or copy. */
    public static function classesIn(string $markup): array
    {
        $dom = Html::loadUtf8Html($markup, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$dom instanceof \DOMDocument) {
            return [];
        }
        $found = [];
        foreach ($dom->getElementsByTagName('div') as $node) {
            $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
            if (in_array('wp-block-group', $classes, true)) {
                $found = array_merge($found, array_intersect(array_keys(self::CLASSES), $classes));
            }
        }
        return array_values(array_intersect(array_keys(self::CLASSES), $found));
    }

    /**
     * Scan-row contract from CssChecks::scanDeclarations(). Positive property
     * and value vocabularies keep variables, escapes and shorthand resets from
     * smuggling layout/content changes into this deliberately paint-only API.
     */
    public static function declarationProblem(array $row): ?string
    {
        $context = trim((string) preg_replace('~/\*.*?\*/~s', '', $row['context']));
        if (!str_contains($context . ' ' . implode(' ', $row['ancestors']), 'design-')) {
            return null;
        }
        $selectors = array_map('trim', explode(',', $context));
        foreach ($selectors as $selector) {
            if (!self::selectorAllowed($selector)) {
                return 'design expression requires an exact documented paint selector';
            }
        }
        foreach ($row['ancestors'] as $ancestor) {
            if (!preg_match('/^\s*@media\b/i', $ancestor)) {
                return 'design expression cannot use nested style selectors';
            }
        }
        $property = strtolower($row['property']);
        $value = trim((string) preg_replace('~/\*.*?\*/~s', '', $row['value']));
        if (!$row['structurallySafe'] || str_contains($value, '\\') || str_contains($value, '!')
            || preg_match('/\b(?:inherit|initial|unset|revert|attr|env)\b/i', $value)
        ) {
            return 'design expression value cannot escape its paint contract';
        }
        // No fallback payloads or mutable variables; only delivered palette.
        $literal = preg_replace('/var\(--wp--preset--color--(?:base|band|contrast|primary|secondary|accent)\)/', 'currentColor', $value);
        if (preg_match('/\bvar\s*\(/i', $literal)) {
            return 'design expression only accepts direct theme palette variables';
        }
        $frame = in_array('.design-frame', $selectors, true);
        if (preg_match('/^border(?:-(?:top|right|bottom|left))?(?:-(?:width|style|color))?$/', $property)) {
            $parts = preg_split('/\s+/', $literal) ?: [];
            foreach ($parts as $part) {
                if (!preg_match('/^(?:0|(?:[0-7](?:\.\d+)?|8)px|none|solid|double|dashed|dotted|currentColor|transparent)$/i', $part)) {
                    return 'design expression borders use 0–8px strokes and palette colors';
                }
            }
            return null;
        }
        if ($property === 'border-radius') {
            return preg_match('/^[\d.\s%\/]+$/D', $value) ? null : 'design expression radius uses percentages';
        }
        if ($frame) {
            return 'design-frame may paint borders only; content and layout remain block-owned';
        }
        if ($property === 'content') {
            return in_array($value, ['""', "''"], true) ? null : 'design-motif cannot generate text or images';
        }
        if (in_array($property, ['width', 'height'], true)) {
            if (!preg_match('/^(\d+(?:\.\d+)?)(%|rem|px)?$/D', $value, $m)) {
                return 'design-motif size must be a bounded literal';
            }
            $unit = $m[2] ?? '';
            $limit = match ($unit) {
                '%' => $property === 'width' ? 100 : 0,
                'rem' => $property === 'width' ? 16 : 12,
                'px' => $property === 'width' ? 256 : 192,
                default => 0,
            };
            return (float) $m[1] <= $limit ? null : 'design-motif size exceeds its content-safe bounds';
        }
        if (in_array($property, ['background', 'background-image', 'background-size', 'background-position', 'background-repeat', 'clip-path'], true)) {
            // Functional paint only: resources are independently rejected by
            // PageStylesStep, but keep this contract closed in isolation too.
            preg_match_all('/([\w-]+)\s*\(/', $literal, $functions);
            foreach ($functions[1] as $function) {
                if (!in_array(strtolower($function), ['linear-gradient', 'radial-gradient', 'conic-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient', 'repeating-conic-gradient', 'polygon', 'circle', 'ellipse', 'inset'], true)) {
                    return 'design-motif accepts CSS geometry, not resources or arbitrary functions';
                }
            }
            return preg_match('/^[\w\s.,()%+\/-]+$/D', $literal) ? null : 'unsupported design-motif paint value';
        }
        return 'design-motif may paint empty decoration only; position, motion and content are build-owned';
    }

    /** Code-owned geometry: pseudo-elements cannot overlay/hide/capture content. */
    public static function foundation(string $css): string
    {
        $groups = [];
        foreach (CssChecks::scanDeclarations($css) as $row) {
            if (self::declarationProblem($row) !== null || !self::isPaint($row)) {
                continue;
            }
            foreach (array_map('trim', explode(',', $row['context'])) as $selector) {
                if (str_starts_with($selector, '.design-motif::')) {
                    $key = implode("\n", $row['ancestors']);
                    $groups[$key]['ancestors'] = $row['ancestors'];
                    $groups[$key]['selectors'][$selector] = true;
                }
            }
        }
        $css = '';
        foreach ($groups as $group) {
            $rule = implode(', ', array_map(static fn (string $selector): string => 'div.wp-block-group' . $selector, array_keys($group['selectors']))) . " {\n"
            . "  content: \"\" !important; display: block !important; position: static !important;\n"
            . "  box-sizing: border-box !important; max-width: 100% !important; max-height: 192px !important;\n"
            . "  width: 100%; height: 3rem; margin: 1rem auto !important;\n"
            . "  pointer-events: none !important; overflow: hidden !important; transform: none !important;\n"
            . "}\n";
            // Content/dimensions outside this condition would leave a blank
            // decorative box where the authored paint does not apply.
            foreach (array_reverse($group['ancestors']) as $ancestor) {
                $rule = $ancestor . " {\n" . $rule . "}\n";
            }
            $css .= $rule;
        }
        return $css;
    }

    private static function isPaint(array $row): bool
    {
        if (preg_match('/^background(?:-image)?$/', $row['property'])) {
            return preg_match('/\b(?:var\(|currentColor\b)/i', $row['value']) === 1;
        }
        // A border color/width alone has no stroke; require a visible style.
        return preg_match('/^border(?:-(?:top|right|bottom|left))?(?:-style)?$/', $row['property']) === 1
            && preg_match('/\b(?:solid|double|dashed|dotted)\b/', $row['value']) === 1
            && preg_match('/(?:^|\s)0(?:px)?(?:\s|$)/', $row['value']) !== 1;
    }

    /**
     * Presence audit only. A border/gradient is not proof of beauty or of an
     * era. A rendered cohort with the target model is still the acceptance gate.
     * @param list<string> $used
     * @return list<string>
     */
    public static function deliveryWarnings(array $direction, array $used, string $css): array
    {
        $signature = (string) ($direction['style_signature'] ?? '');
        $promised = self::normalizeHooks($direction['style_hooks'] ?? []);
        $rows = [];
        if (trim($signature) === '' && trim((string) ($direction['requested_style'] ?? '')) !== '') {
            $rows[] = "file='designDirection.json'; path='style_signature'; authored=\"\"; delivered=\"\"; "
                . 'disposition=requested style has no observable feature commitment; retained site, needs rendered style review';
        }
        foreach (array_keys(self::CLASSES) as $class) {
            if (!in_array($class, $used, true) && !in_array($class, $promised, true)) {
                continue;
            }
            $paint = false;
            foreach (CssChecks::scanDeclarations($css) as $row) {
                if (str_contains($row['context'], '.' . $class) && self::declarationProblem($row) === null && self::isPaint($row)) {
                    $paint = true;
                }
            }
            if (in_array($class, $used, true) && $paint) {
                continue;
            }
            $missing = !in_array($class, $used, true) ? 'no delivered wp:group hook' : 'no decorative paint survived';
            $rows[] = "file='theme/style.css'; path='{$class}'; authored=" . Warnings::value($signature ?: $class)
                . '; delivered=' . Warnings::value($missing)
                . '; disposition=retained page content; review designDirection.json style_signature and delivered markup/CSS for missing visual expression';
        }
        return $rows;
    }
}
