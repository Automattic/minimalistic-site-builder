<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Supports;

use Automattic\SiteBuild\BlockSerializer\Save\BlockProps;

/** Pinned blocks.getSaveContent.extraProps pipeline, in effective hook order. */
final class SupportEngine
{
    public function __construct(private ?StyleEngine $styles = null)
    {
        $this->styles ??= new StyleEngine();
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $supports
     * @param array<string,mixed> $initialProps
     */
    public function apply(string $name, array $attributes, array $supports, array $initialProps = []): BlockProps
    {
        $props = new BlockProps($initialProps);

        // Composite core/editor/hooks filter (priority 0).
        $align = $attributes['align'] ?? null;
        if (is_string($align) && $this->validAlign($align, $supports['align'] ?? false, $supports['alignWide'] ?? true)) {
            $props->prependClass('align' . $align);
        }

        $textAlign = $attributes['style']['typography']['textAlign'] ?? null;
        $textAlignSupport = $supports['typography']['textAlign'] ?? false;
        $typographySkip = $supports['typography']['__experimentalSkipSerialization'] ?? false;
        if (is_string($textAlign) && in_array($textAlign, ['left', 'center', 'right'], true)
            && !$this->skipFeature($typographySkip, 'textAlign')
            && ($textAlignSupport === true || (is_array($textAlignSupport) && in_array($textAlign, $textAlignSupport, true)))) {
            $props->prependClass('has-text-align-' . $textAlign);
        }

        if (($supports['anchor'] ?? false) && array_key_exists('anchor', $attributes)) {
            $props->set('id', $attributes['anchor'] === '' ? null : $attributes['anchor']);
        }
        $ariaSupport = $supports['ariaLabel'] ?? false;
        $ariaSkip = is_array($ariaSupport) ? ($ariaSupport['__experimentalSkipSerialization'] ?? false) : false;
        if ($ariaSupport && !$this->skipFeature($ariaSkip, 'ariaLabel')
            && array_key_exists('ariaLabel', $attributes)) {
            $props->set('aria-label', $attributes['ariaLabel'] === '' ? null : $attributes['ariaLabel']);
        }

        if (($supports['customClassName'] ?? true) && !empty($attributes['className'])) {
            $props->appendClass((string) $attributes['className']);
        }

        $borderSupport = $supports['__experimentalBorder'] ?? null;
        if (is_array($borderSupport) && ($borderSupport['color'] ?? false)
            && !$this->skipFeature($borderSupport['__experimentalSkipSerialization'] ?? false, 'color')) {
            $borderColor = $attributes['borderColor'] ?? null;
            $customBorder = $attributes['style']['border']['color'] ?? null;
            if ($borderColor || $customBorder) {
                $props->appendClass('has-border-color');
            }
            if ($borderColor) {
                $props->appendClass('has-' . self::slug((string) $borderColor) . '-border-color');
            }
        }

        if (($supports['typography']['fitText'] ?? false)
            && !$this->skipFeature($supports['typography']['__experimentalSkipSerialization'] ?? false, 'fitText')
            && !empty($attributes['fitText'])) {
            $props->appendClass('has-fit-text');
        }

        $colorSupport = $supports['color'] ?? null;
        if ($this->hasColorSupport($colorSupport) && !$this->skipFeature($colorSupport, '*')) {
            $color = is_array($colorSupport) ? $colorSupport : [];
            $skip = $color['__experimentalSkipSerialization'] ?? false;
            // Once Gutenberg admits the color support as a whole, its save
            // filter gates individual serialized values only through the
            // skip list. Feature flags control editor UI, not these classes.
            $textAllowed = !$this->skipFeature($skip, 'text');
            $backgroundAllowed = !$this->skipFeature($skip, 'background');
            $gradientAllowed = !$this->skipFeature($skip, 'gradients');
            $linkAllowed = !$this->skipFeature($skip, 'link');
            $textColor = $attributes['textColor'] ?? null;
            $backgroundColor = $attributes['backgroundColor'] ?? null;
            $gradient = $attributes['gradient'] ?? null;
            $style = is_array($attributes['style'] ?? null) ? $attributes['style'] : [];
            $customText = $style['color']['text'] ?? null;
            $customBackground = $style['color']['background'] ?? null;
            $customGradient = $style['color']['gradient'] ?? null;

            if ($textAllowed && $textColor) {
                $props->appendClass('has-' . self::slug((string) $textColor) . '-color');
            }
            if ($gradientAllowed && $gradient) {
                $props->appendClass('has-' . self::slug((string) $gradient) . '-gradient-background');
            }
            if ($backgroundAllowed && $backgroundColor && !($gradientAllowed && $customGradient)) {
                $props->appendClass('has-' . self::slug((string) $backgroundColor) . '-background-color');
            }
            if ($textAllowed && ($textColor || $customText)) {
                $props->appendClass('has-text-color');
            }
            if (($backgroundAllowed || $gradientAllowed)
                && ($backgroundColor || $customBackground || ($gradientAllowed && ($gradient || $customGradient)))) {
                $props->appendClass('has-background');
            }
            if ($linkAllowed && !empty($style['elements']['link']['color'])) {
                $props->appendClass('has-link-color');
            }
        }

        if (is_array($attributes['style'] ?? null) && $this->hasStyleSupport($supports)) {
            $style = $this->omitSkippedStyles($attributes['style'], $supports);
            $props->prependStyles($this->styles->declarations($style));
        }

        if (($supports['typography']['__experimentalFontFamily'] ?? false)
            && !$this->skipFeature($typographySkip, 'fontFamily')
            && !empty($attributes['fontFamily'])) {
            $props->appendClass('has-' . self::slug((string) $attributes['fontFamily']) . '-font-family');
        }
        if (($supports['typography']['fontSize'] ?? false)
            && !$this->skipFeature($typographySkip, 'fontSize')
            && !empty($attributes['fontSize'])) {
            $props->appendClass('has-' . self::slug((string) $attributes['fontSize']) . '-font-size');
        }

        // Generated class filter, followed by the cleanup filter.
        if (($supports['className'] ?? true) !== false) {
            $props->generatedClass(self::defaultClass($name));
        }
        $props->deduplicateClasses();

        return $props;
    }

    public static function defaultClass(string $name): string
    {
        $name = preg_replace('#^core/#', '', $name) ?? $name;
        return 'wp-block-' . str_replace('/', '-', $name);
    }

    public static function slug(string $value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? $value;
        return strtolower(trim($value, '-'));
    }

    private function validAlign(string $align, mixed $support, mixed $alignWide): bool
    {
        $allowed = $support === true ? ['left', 'center', 'right', 'wide', 'full'] : (is_array($support) ? $support : []);
        if ($support === true && $alignWide === false) {
            $allowed = array_values(array_diff($allowed, ['wide', 'full']));
        }
        return in_array($align, $allowed, true);
    }

    private function hasColorSupport(mixed $support): bool
    {
        if (!$support) {
            return false;
        }
        if (!is_array($support)) {
            return true;
        }
        return ($support['link'] ?? false) === true
            || ($support['gradient'] ?? false) === true
            || ($support['background'] ?? true) !== false
            || ($support['text'] ?? true) !== false;
    }

    /** @param array<string,mixed> $supports */
    private function hasStyleSupport(array $supports): bool
    {
        return isset($supports['typography']) || isset($supports['__experimentalBorder'])
            || isset($supports['color']) || isset($supports['dimensions'])
            || isset($supports['background']) || isset($supports['spacing'])
            || isset($supports['shadow']);
    }

    private function skipFeature(mixed $skip, string $feature): bool
    {
        if ($feature === '*' && $skip === true) {
            return true;
        }
        if ($skip === true) {
            return true;
        }
        return is_array($skip) && in_array($feature, $skip, true);
    }

    /** @param array<string,mixed> $style @param array<string,mixed> $supports @return array<string,mixed> */
    private function omitSkippedStyles(array $style, array $supports): array
    {
        // Save mode always omits these; their renderers/other supports own them.
        unset($style['dimensions']['aspectRatio'], $style['background']);
        $map = [
            '__experimentalBorder' => 'border',
            'color' => 'color',
            'typography' => 'typography',
            'dimensions' => 'dimensions',
            'spacing' => 'spacing',
            'shadow' => 'shadow',
        ];
        foreach ($map as $supportKey => $styleKey) {
            $support = $supports[$supportKey] ?? null;
            $skip = is_array($support) ? ($support['__experimentalSkipSerialization'] ?? false) : false;
            if ($skip === true) {
                unset($style[$styleKey]);
            } elseif (is_array($skip)) {
                foreach ($skip as $feature) {
                    $feature = $feature === 'gradients' ? 'gradient' : $feature;
                    unset($style[$styleKey][$feature]);
                }
            }
        }
        return $style;
    }
}
