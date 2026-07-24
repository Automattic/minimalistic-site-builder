<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Supports;

use Automattic\SiteBuild\BlockSerializer\Json\JsonNative;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;

/** Fail-closed boundary for the reviewed block-support compatibility domain. */
final class SupportDomainGuard
{
    /**
     * Style families (or family members) that the pinned runtime implements
     * but this PHP pipeline does not. An unknown authored key at or under one
     * of these paths may carry real styling or editor metadata, so it is
     * never pruned as invented state; it stays fail-closed instead. Matching
     * is by exact path or by `<entry>.` prefix.
     *
     * @var list<string>
     */
    private const PINNED_UNIMPLEMENTED_STYLE_PATHS = [
        // Per-block custom CSS and duotone filters render real styles.
        'css',
        'filter',
        'color.duotone',
        // Section style variations resolve against theme.json at render time.
        'variation',
        // Families whose pinned schema is wider than the reviewed tree:
        // element selectors, grid child placement, position offsets, and
        // background attachment metadata.
        'elements',
        'layout',
        'position',
        'background',
    ];
    /**
     * Closed style path tree implemented by StyleEngine, SupportEngine, and
     * the explicit renderers. `@leaf` means the node may itself be a scalar as
     * well as accepting the listed object keys.
     *
     * @var array<string,mixed>
     */
    private const STYLE_PATHS = [
        'background' => [
            'backgroundImage' => [
                '@leaf' => true,
                'url' => true,
                // Generated no-image sentinel retained in the delimiter by
                // the pinned registry; group save emits no CSS for it.
                'ref' => [
                    '@values' => ['none'],
                    '@pattern' => '/^var:preset\|gradient\|[a-z0-9][a-z0-9_-]*$/',
                ],
            ],
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
            // A generated separator signature uses scalar "0" edge values.
            // The pinned style engine treats them as inert comment state;
            // all non-zero scalar edge spellings remain fail-closed.
            'top' => ['@values' => ['0'], 'color' => true, 'style' => true, 'width' => true],
            'right' => ['@values' => ['0'], 'color' => true, 'style' => true, 'width' => true],
            'bottom' => ['@values' => ['0'], 'color' => true, 'style' => true, 'width' => true],
            'left' => ['@values' => ['0'], 'color' => true, 'style' => true, 'width' => true],
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
        // Generated layout hint retained as inert delimiter state; the
        // top-level layout attribute remains the save implementation owner.
        'display' => ['@values' => ['flex']],
        'elements' => [
            'link' => [
                'color' => ['text' => true],
                'typography' => [
                    'textDecoration' => ['@values' => ['none', 'underline']],
                ],
                ':hover' => [
                    'color' => ['text' => true],
                    'typography' => [
                        'textDecoration' => ['@values' => ['none', 'underline']],
                    ],
                ],
            ],
        ],
        // Legacy generated typography spelling. The pinned current save does
        // not consume it, while validation's custom-class recovery preserves
        // the matching authored has-<slug>-font-family class.
        'fontFamily' => ['@pattern' => '/^[a-z0-9][a-z0-9-]*$/'],
        'layout' => [
            'selfStretch' => ['@values' => ['fill', 'fit', 'fixed']],
            'flexSize' => true,
            // Pinned generated footer signature. This is retained comment
            // state; core/group save() reads the top-level layout attribute.
            'type' => ['@values' => ['constrained']],
        ],
        'outline' => [
            'color' => true,
            'style' => true,
            'offset' => true,
            'width' => true,
        ],
        // Registered core/group support. The pinned save probe confirms this
        // remains comment state and adds no wrapper CSS in the frozen runtime.
        'position' => [
            'type' => ['@values' => ['sticky']],
            'top' => true,
        ],
        // Legacy generated group signature. The pinned registry preserves
        // this value in the delimiter but does not emit wrapper CSS (unlike
        // style.border.radius). Keep the reviewed domain deliberately narrow.
        'radius' => ['@values' => ['12px']],
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
        'type' => ['constrained', 'flex', 'default'],
        'orientation' => ['horizontal', 'vertical'],
        'justifyContent' => ['left', 'center', 'right', 'space-between'],
        'verticalAlignment' => ['top', 'center', 'bottom', 'stretch'],
        // Retained in the delimiter by the pinned registry but not consumed
        // by core/group save(). `center` is the reviewed generated-theme
        // signature covered by group-layout-align-items.
        'alignItems' => ['center'],
        'flexWrap' => ['wrap', 'nowrap'],
    ];

    /**
     * Free-string layout keys the pinned save() never consumes: they pass
     * through to the comment untouched (group-layout-content-size).
     *
     * @var array<string,true>
     */
    private const LAYOUT_STRING_KEYS = [
        'contentSize' => true,
        'wideSize' => true,
    ];

    /** @param array<string,mixed> $attributes */
    public function assertSupported(string $name, array $attributes, string $blockPath): void
    {
        if (array_key_exists('style', $attributes)) {
            $this->assertPathValue(
                $this->withoutReviewedInertStyleState($name, $attributes['style']),
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
                if (is_string($key) && isset(self::LAYOUT_STRING_KEYS[$key])) {
                    if (!is_string($value) || $value === '') {
                        $encoded = is_scalar($value) ? (string) $value : get_debug_type($value);
                        throw new \RuntimeException(
                            "Unsupported block-support layout value '{$encoded}' for {$name} at {$blockPath} layout.{$key}"
                        );
                    }
                    continue;
                }
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

    /**
     * Delete invented style keys — authored paths that exist neither in the
     * reviewed tree nor in the pinned runtime — from the raw comment state,
     * and return each pruned dotted path for repair reporting. Mutates
     * $attributes so the serialized output drops the same bytes.
     *
     * Only whole unknown keys are pruned. Value-level mismatches on known
     * paths, authored objects where the reviewed rule expects a scalar, and
     * anything at or under a pinned-but-unimplemented family are left for
     * assertSupported() to fail closed.
     *
     * @return list<string>
     */
    public function pruneInventedStylePaths(string $name, JsonObject $attributes): array
    {
        $style = $attributes->get('style');
        if (!$style instanceof JsonObject) {
            return [];
        }
        $view = $this->withoutReviewedInertStyleState($name, JsonNative::value($style));
        if (!is_array($view)) {
            return [];
        }
        $pruned = [];
        $this->collectInventedPaths($view, self::STYLE_PATHS, '', $pruned);
        foreach ($pruned as $path) {
            $this->removeStylePath($style, explode('.', $path));
        }
        if ($pruned !== [] && count($style) === 0) {
            $attributes->remove('style');
        }
        return $pruned;
    }

    /**
     * @param array<string,mixed> $view
     * @param array<string,mixed>|true $rule
     * @param list<string> $pruned
     */
    private function collectInventedPaths(array $view, array|bool $rule, string $prefix, array &$pruned): void
    {
        if (!is_array($rule)) {
            return;
        }
        foreach ($view as $key => $child) {
            if (!is_string($key)) {
                // List shapes are never valid authored style state.
                continue;
            }
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            if (in_array($key, ['@leaf', '@values', '@pattern'], true)
                || !array_key_exists($key, $rule)) {
                if (!$this->isPinnedUnimplementedStylePath($path)) {
                    $pruned[] = $path;
                }
                continue;
            }
            if (is_array($child)) {
                $this->collectInventedPaths($child, $rule[$key], $path, $pruned);
            }
        }
    }

    private function isPinnedUnimplementedStylePath(string $path): bool
    {
        foreach (self::PINNED_UNIMPLEMENTED_STYLE_PATHS as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '.')) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $segments */
    private function removeStylePath(JsonObject $style, array $segments): void
    {
        $key = array_shift($segments);
        if ($key === null) {
            return;
        }
        if ($segments === []) {
            $style->remove($key);
            return;
        }
        $child = $style->get($key);
        if (!$child instanceof JsonObject) {
            return;
        }
        $this->removeStylePath($child, $segments);
        if (count($child) === 0) {
            $style->remove($key);
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
            if ((isset($rule['@values']) && is_array($rule['@values']))
                || isset($rule['@pattern'])) {
                $matchesValue = is_string($value)
                    && isset($rule['@values'])
                    && is_array($rule['@values'])
                    && in_array($value, $rule['@values'], true);
                $matchesPattern = is_string($value)
                    && isset($rule['@pattern'])
                    && is_string($rule['@pattern'])
                    && preg_match($rule['@pattern'], $value) === 1;
                if (!$matchesValue && !$matchesPattern) {
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

    /**
     * Remove exact AI-authored style signatures which the pinned registry
     * retains in the comment delimiter but never hands to its style engine.
     * This operates on a validation copy only; the raw authored state remains
     * available to the normalizer and comment serializer.
     */
    private function withoutReviewedInertStyleState(string $name, mixed $style): mixed
    {
        if (!is_array($style)) {
            return $style;
        }

        // core/navigation consumes preset sizes from top-level fontSize. The
        // generated bare style.fontSize="caption" spelling is inert.
        if ($name === 'core/navigation' && ($style['fontSize'] ?? null) === 'caption') {
            unset($style['fontSize']);
        }

        // One generated italic paragraph pairs a made-up boolean companion
        // with the real fontStyle value. The false companion is inert.
        $typography = $style['typography'] ?? null;
        if ($name === 'core/paragraph'
            && is_array($typography)
            && ($typography['fontStyle'] ?? null) === 'italic'
            && array_key_exists('fontStyleNormal', $typography)
            && $typography['fontStyleNormal'] === false) {
            unset($typography['fontStyleNormal']);
            $style['typography'] = $typography;
        }

        // Generated gallery captions copied a theme.json element selector
        // into paragraph block state. The pinned registry retains this exact
        // object in the delimiter; the authored root carries the actual CSS.
        $elements = $style['elements'] ?? null;
        if ($name === 'core/paragraph'
            && is_array($elements)
            && ($elements['caption'] ?? null) === [
                'typography' => ['fontStyle' => 'italic'],
            ]) {
            unset($elements['caption']);
            if ($elements === []) {
                unset($style['elements']);
            } else {
                $style['elements'] = $elements;
            }
        }

        // Element-styles state beyond the reviewed tree is carried, not
        // validated. Per the pinned block-editor save hooks, the only
        // saved-markup effect of style.elements is the has-link-color class
        // derived from elements.link.color, which the renderers implement;
        // every other element path is render-time (or dead) state that
        // Gutenberg keeps verbatim in the delimiter. Generated markup
        // occasionally invents such paths (a button :hover background,
        // elements.heading on a heading) — carry them per block instead of
        // failing the whole file. Reviewed elements paths keep strict
        // value validation.
        $elements = $style['elements'] ?? null;
        if (is_array($elements)) {
            $view = $this->reviewedElementsView($elements, self::STYLE_PATHS['elements']);
            if ($view === []) {
                unset($style['elements']);
            } else {
                $style['elements'] = $view;
            }
        }

        return $style;
    }

    /**
     * Restrict an authored style.elements subtree to the reviewed rule tree,
     * hiding unreviewed keys from validation while the raw authored state
     * stays in the delimiter.
     *
     * @param array<string,mixed> $value
     * @param array<string,mixed>|true $rule
     * @return array<string,mixed>
     */
    private function reviewedElementsView(array $value, array|bool $rule): array
    {
        if (!is_array($rule)) {
            return $value;
        }
        $view = [];
        foreach ($value as $key => $child) {
            if (!is_string($key) || str_starts_with($key, '@') || !array_key_exists($key, $rule)) {
                continue;
            }
            if (is_array($child) && is_array($rule[$key])) {
                $childView = $this->reviewedElementsView($child, $rule[$key]);
                if ($childView !== []) {
                    $view[$key] = $childView;
                }
                continue;
            }
            $view[$key] = $child;
        }
        return $view;
    }
}
