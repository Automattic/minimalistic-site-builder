<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Keep theme values that affect markup and omit editor and font-download data. */
final class MarkupContext
{
    public static function theme(array $theme): string
    {
        $settings = is_array($theme['settings'] ?? null) ? $theme['settings'] : [];
        $kept = array_intersect_key($settings, array_flip([
            'color', 'typography', 'spacing', 'layout', 'custom', 'shadow', 'dimensions', 'border',
        ]));
        foreach (['color' => ['palette', 'gradients', 'duotone'],
            'typography' => ['fontFamilies', 'fontSizes'],
            'spacing' => ['spacingSizes'], 'shadow' => ['presets']] as $group => $keys) {
            if (is_array($kept[$group] ?? null)) {
                $kept[$group] = array_intersect_key($kept[$group], array_flip($keys));
            }
        }
        foreach ($kept['typography']['fontFamilies'] ?? [] as $index => $font) {
            if (is_array($font)) {
                unset($kept['typography']['fontFamilies'][$index]['fontFace']);
            }
        }
        return json_encode([
            'settings' => $kept,
            'styles' => $theme['styles'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
