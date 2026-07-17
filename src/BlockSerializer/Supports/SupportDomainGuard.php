<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Supports;

/** Fail-closed boundary for the reviewed block-support compatibility domain. */
final class SupportDomainGuard
{
    /**
     * Closed style path tree implemented by StyleEngine, SupportEngine, and
     * the explicit renderers. `@leaf` means the node may itself be a scalar as
     * well as accepting the listed object keys.
     *
     * @var array<string,mixed>
     */
    private const STYLE_PATHS = [
        'background' => [
            'backgroundImage' => ['@leaf' => true, 'url' => true],
            'gradient' => true,
            'backgroundPosition' => true,
            'backgroundRepeat' => true,
            'backgroundSize' => true,
            'backgroundAttachment' => true,
        ],
        'border' => [
            'color' => true,
            'style' => true,
            'width' => true,
            'radius' => [
                '@leaf' => true,
                'topLeft' => true,
                'topRight' => true,
                'bottomLeft' => true,
                'bottomRight' => true,
            ],
            'top' => ['color' => true, 'style' => true, 'width' => true],
            'right' => ['color' => true, 'style' => true, 'width' => true],
            'bottom' => ['color' => true, 'style' => true, 'width' => true],
            'left' => ['color' => true, 'style' => true, 'width' => true],
        ],
        'color' => [
            'text' => true,
            'gradient' => true,
            'background' => true,
        ],
        'dimensions' => [
            'height' => true,
            'minHeight' => true,
            'minWidth' => true,
            'aspectRatio' => true,
            'width' => true,
            'objectFit' => true,
        ],
        'elements' => [
            'link' => [
                'color' => ['text' => true],
                ':hover' => ['color' => ['text' => true]],
            ],
        ],
        'layout' => [
            'selfStretch' => ['@values' => ['fill', 'fit', 'fixed']],
            'flexSize' => true,
        ],
        'outline' => [
            'color' => true,
            'style' => true,
            'offset' => true,
            'width' => true,
        ],
        'shadow' => true,
        'spacing' => [
            'margin' => [
                '@leaf' => true,
                'top' => true,
                'right' => true,
                'bottom' => true,
                'left' => true,
            ],
            'padding' => [
                '@leaf' => true,
                'top' => true,
                'right' => true,
                'bottom' => true,
                'left' => true,
            ],
            // Client save does not emit blockGap, but the reviewed layout
            // support pipeline explicitly admits these two Gutenberg axes.
            'blockGap' => ['@leaf' => true, 'top' => true, 'left' => true],
        ],
        'typography' => [
            'fontFamily' => true,
            'fontSize' => true,
            'fontStyle' => true,
            'fontWeight' => true,
            'letterSpacing' => true,
            'lineHeight' => true,
            'textAlign' => true,
            'textColumns' => true,
            'textDecoration' => true,
            'textIndent' => true,
            'textShadow' => true,
            'textTransform' => true,
            'writingMode' => true,
        ],
    ];

    /** @var array<string,list<string>> */
    private const LAYOUT_VALUES = [
        'type' => ['constrained', 'flex'],
        'orientation' => ['horizontal', 'vertical'],
        'justifyContent' => ['left', 'center', 'right', 'space-between'],
        'verticalAlignment' => ['top', 'center', 'bottom', 'stretch'],
        'flexWrap' => ['wrap', 'nowrap'],
    ];

    /** @param array<string,mixed> $attributes */
    public function assertSupported(string $name, array $attributes, string $blockPath): void
    {
        if (array_key_exists('style', $attributes)) {
            $this->assertPathValue(
                $attributes['style'],
                self::STYLE_PATHS,
                $name,
                $blockPath,
                'style',
            );
        }
        if (array_key_exists('layout', $attributes)) {
            $layout = $attributes['layout'];
            if (!is_array($layout)) {
                throw new \RuntimeException("Unsupported non-object layout for {$name} at {$blockPath}");
            }
            foreach ($layout as $key => $value) {
                if (!is_string($key) || !isset(self::LAYOUT_VALUES[$key])) {
                    $label = is_string($key) ? $key : (string) $key;
                    throw new \RuntimeException(
                        "Unsupported block-support layout variant 'layout.{$label}' for {$name} at {$blockPath}"
                    );
                }
                if (!is_string($value) || !in_array($value, self::LAYOUT_VALUES[$key], true)) {
                    $encoded = is_scalar($value) ? (string) $value : get_debug_type($value);
                    throw new \RuntimeException(
                        "Unsupported block-support layout value '{$encoded}' for {$name} at {$blockPath} layout.{$key}"
                    );
                }
            }
        }
    }

    /** @param array<string,mixed>|true $rule */
    private function assertPathValue(
        mixed $value,
        array|bool $rule,
        string $name,
        string $blockPath,
        string $valuePath,
    ): void {
        if ($rule === true) {
            if (is_array($value)) {
                throw new \RuntimeException(
                    "Unsupported block-support object at {$name} {$blockPath} {$valuePath}"
                );
            }
            return;
        }
        if (!is_array($value)) {
            if (isset($rule['@values']) && is_array($rule['@values'])) {
                if (!is_string($value) || !in_array($value, $rule['@values'], true)) {
                    $encoded = is_scalar($value) ? (string) $value : get_debug_type($value);
                    throw new \RuntimeException(
                        "Unsupported block-support value '{$encoded}' at {$name} {$blockPath} {$valuePath}"
                    );
                }
                return;
            }
            if (($rule['@leaf'] ?? false) === true) {
                return;
            }
            throw new \RuntimeException(
                "Unsupported block-support scalar at {$name} {$blockPath} {$valuePath}"
            );
        }
        foreach ($value as $key => $child) {
            if (!is_string($key) || $key === '@leaf' || !array_key_exists($key, $rule)) {
                $label = is_string($key) ? $key : (string) $key;
                throw new \RuntimeException(
                    "Unsupported block-support path '{$valuePath}.{$label}' for {$name} at {$blockPath}"
                );
            }
            $this->assertPathValue(
                $child,
                $rule[$key],
                $name,
                $blockPath,
                $valuePath . '.' . $key,
            );
        }
    }
}
