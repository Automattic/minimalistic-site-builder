<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Supports;

use Automattic\SiteBuild\BlockSerializer\Attributes\AttributeNameResolver;
use Automattic\SiteBuild\BlockSerializer\Json\JsonNative;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonString;

/** Fail-closed boundary for the reviewed block-support compatibility domain. */
final class SupportDomainGuard
{
    /**
     * Style families (or family members) that the pinned runtime implements
     * but this PHP pipeline does not. An unknown authored key at or under one
     * of these paths may carry real styling or editor metadata, so it is
     * never pruned as invented state. Matching is by exact path or by
     * `<entry>.` prefix.
     *
     * Entries also listed in CARRIED_STYLE_PATHS are carried per block
     * instead of failing validation; the rest stay fail-closed.
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
     * Pinned-unimplemented families whose unreviewed keys are carried, not
     * validated: kept verbatim in the delimiter and hidden from the
     * validation view, exactly as the pinned reserializer keeps them. This
     * is byte-safe because no save-path consumer reads these families —
     * StyleEngine and SupportEngine fetch exact reviewed paths only, so at
     * the pinned save hooks everything here is render-time (or dead) state
     * that cannot change saved markup. Reviewed paths inside these families
     * (the elements.link color recipe, the inert layout/position signatures)
     * keep strict value validation.
     *
     * `background` is deliberately absent: StyleEngine consumes
     * style.background wholesale, and an unreviewed shape (e.g. an url-less
     * backgroundImage object) reaches a branch never certified against the
     * pinned engine, where carried keys could change saved bytes. It stays
     * fail-closed.
     *
     * @var list<string>
     */
    private const CARRIED_STYLE_PATHS = [
        'css',
        'filter',
        'color.duotone',
        'variation',
        'elements',
        'layout',
        'position',
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
     * Correct or delete unusable `layout` state before assertSupported() sees
     * it, returning one row per action for repair reporting. Mutates
     * $attributes so the serialized output drops the same bytes.
     *
     * The generator gets layout wrong the same way it gets attribute names
     * wrong: by borrowing something real from next door. `justifyContent`
     * accepts left/center/right/space-between, `verticalAlignment` accepts
     * stretch — so a section reading `"justifyContent":"stretch"` is a valid
     * value on the wrong property, exactly as `verticalAlignment` on core/group
     * was a valid name on the wrong block. One such word used to discard an
     * entire build at the last step.
     *
     * A value whose shape matches a permitted one is corrected (`space between`
     * → `space-between`); anything else has its key removed, which leaves a
     * layout that renders without that one refinement rather than no site at
     * all. Only unusable state is touched — everything valid passes through and
     * assertSupported() still fails closed on whatever this did not handle.
     *
     * @return list<array{action:string,key:string,from:string,to:string}>
     */
    public function pruneInvalidLayout(string $name, JsonObject $attributes): array
    {
        if (!$attributes->has('layout')) {
            return [];
        }
        $layout = $attributes->get('layout');
        if (!$layout instanceof JsonObject) {
            // A scalar or list where the layout object belongs carries no
            // recoverable intent; the block renders with its default layout.
            $attributes->remove('layout');
            return [['action' => 'dropped', 'key' => 'layout', 'from' => 'non-object', 'to' => '']];
        }

        $rows = [];
        foreach ($layout->entries() as $entry) {
            $key = (string) $entry['key'];
            $value = JsonNative::value($entry['value']);

            if (isset(self::LAYOUT_STRING_KEYS[$key])) {
                if (!is_string($value) || $value === '') {
                    $layout->remove($key);
                    $rows[] = ['action' => 'dropped', 'key' => $key, 'from' => self::describe($value), 'to' => ''];
                }
                continue;
            }

            if (!isset(self::LAYOUT_VALUES[$key])) {
                $layout->remove($key);
                $rows[] = ['action' => 'dropped', 'key' => $key, 'from' => self::describe($value), 'to' => ''];
                continue;
            }

            if (is_string($value) && in_array($value, self::LAYOUT_VALUES[$key], true)) {
                continue;
            }

            $corrected = is_string($value)
                ? AttributeNameResolver::canonicalize($value, self::LAYOUT_VALUES[$key])
                : null;
            if ($corrected !== null) {
                $layout->set($key, new JsonString($corrected));
                $rows[] = ['action' => 'corrected', 'key' => $key, 'from' => $value, 'to' => $corrected];
                continue;
            }

            $layout->remove($key);
            $rows[] = ['action' => 'dropped', 'key' => $key, 'from' => self::describe($value), 'to' => ''];
        }

        // A layout stripped to nothing is noise in the delimiter; removing it
        // matches what pruneInventedStylePaths does to an emptied style.
        if ($rows !== [] && count($layout) === 0) {
            $attributes->remove('layout');
        }
        return $rows;
    }

    /**
     * Correct or delete style values whose *shape* the reviewed tree cannot
     * accept, returning one row per action. Mutates $attributes.
     *
     * pruneInventedStylePaths() above removes keys that do not exist in the
     * tree. This handles the other half: a key that does exist, carrying
     * something it cannot hold — an object where a scalar belongs
     * (`style.elements.link.color.text` written as a nested object), a scalar
     * where a subtree belongs, or a scalar outside an enumerated set. A value
     * whose shape matches a permitted one is corrected; the rest have their
     * path removed, so the block keeps every style it expressed legally.
     *
     * @return list<array{action:string,path:string,from:string,to:string}>
     */
    public function pruneUnusableStyleValues(string $name, JsonObject $attributes): array
    {
        $style = $attributes->get('style');
        if (!$style instanceof JsonObject) {
            return [];
        }
        $view = $this->withoutReviewedInertStyleState($name, JsonNative::value($style));
        if (!is_array($view)) {
            return [];
        }
        $rows = [];
        $this->collectUnusableValuePaths($view, self::STYLE_PATHS, '', $rows);
        foreach ($rows as $row) {
            $segments = explode('.', $row['path']);
            if ($row['action'] === 'corrected') {
                $this->setStylePath($style, $segments, new JsonString($row['to']));
                continue;
            }
            $this->removeStylePath($style, $segments);
        }
        if ($rows !== [] && count($style) === 0) {
            $attributes->remove('style');
        }
        return $rows;
    }

    /**
     * Walk the reviewed view alongside its rule, recording every path whose
     * value shape the rule cannot accept. Mirrors collectInventedPaths, which
     * owns the unknown-key half of the same problem; anything under a
     * pinned-but-unimplemented family is left alone there and here.
     *
     * @param list<array{action:string,path:string,from:string,to:string}> $rows
     */
    private function collectUnusableValuePaths(array $view, array|bool $rule, string $prefix, array &$rows): void
    {
        foreach ($view as $key => $child) {
            if (!is_string($key) || in_array($key, ['@leaf', '@values', '@pattern'], true)) {
                continue;
            }
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            // `background` stays fail-closed by reviewed decision: StyleEngine
            // consumes it, so a wrong value renders a visibly broken band
            // rather than a missing refinement. Every other family is walked —
            // including the pinned-unimplemented ones, which assertPathValue
            // validates regardless of collectInventedPaths leaving their
            // unknown keys alone.
            if (self::isFailClosedStylePath($path)) {
                continue;
            }
            if (!is_array($rule) || !array_key_exists($key, $rule)) {
                // An unknown key is collectInventedPaths' business, not ours.
                // That also covers unknown keys inside a carried family, which
                // are kept verbatim on purpose — this walk only ever touches
                // paths the reviewed tree names, whose value shape it can judge.
                continue;
            }
            $childRule = $rule[$key];

            if ($childRule === true) {
                if (is_array($child)) {
                    $rows[] = ['action' => 'dropped', 'path' => $path, 'from' => 'object', 'to' => ''];
                }
                continue;
            }
            if (is_array($child)) {
                $this->collectUnusableValuePaths($child, $childRule, $path, $rows);
                continue;
            }

            $values = is_array($childRule) && isset($childRule['@values']) && is_array($childRule['@values'])
                ? $childRule['@values']
                : null;
            $pattern = is_array($childRule) && isset($childRule['@pattern']) && is_string($childRule['@pattern'])
                ? $childRule['@pattern']
                : null;

            if ($values !== null || $pattern !== null) {
                if (is_string($child) && $values !== null && in_array($child, $values, true)) {
                    continue;
                }
                if (is_string($child) && $pattern !== null && preg_match($pattern, $child) === 1) {
                    continue;
                }
                $corrected = is_string($child) && $values !== null
                    ? AttributeNameResolver::canonicalize($child, array_values($values))
                    : null;
                $rows[] = $corrected !== null
                    ? ['action' => 'corrected', 'path' => $path, 'from' => (string) $child, 'to' => $corrected]
                    : ['action' => 'dropped', 'path' => $path, 'from' => self::describe($child), 'to' => ''];
                continue;
            }

            if (is_array($childRule) && ($childRule['@leaf'] ?? false) !== true) {
                // A scalar where the rule expects a subtree.
                $rows[] = ['action' => 'dropped', 'path' => $path, 'from' => self::describe($child), 'to' => ''];
            }
        }
    }

    /** Set a nested style path, creating nothing — an absent parent is a no-op. */
    private function setStylePath(JsonObject $style, array $segments, JsonString $value): void
    {
        $key = array_shift($segments);
        if ($key === null) {
            return;
        }
        if ($segments === []) {
            $style->set($key, $value);
            return;
        }
        $child = $style->get($key);
        if ($child instanceof JsonObject) {
            $this->setStylePath($child, $segments, $value);
        }
    }

    /**
     * Style families that keep failing closed rather than degrading.
     *
     * `background` is the reviewed exception: StyleEngine consumes it, so a
     * value this pass could not judge would render a visibly broken band
     * instead of one missing refinement. Everything else is safe to degrade.
     */
    private const FAIL_CLOSED_STYLE_PATHS = ['background'];

    private static function isFailClosedStylePath(string $path): bool
    {
        foreach (self::FAIL_CLOSED_STYLE_PATHS as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '.')) {
                return true;
            }
        }
        return false;
    }

    /** A short, loggable rendering of an authored layout value of any type. */
    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        return is_scalar($value) || $value === null
            ? var_export($value, true)
            : get_debug_type($value);
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

        // State beyond the reviewed tree at or under a carried style family
        // is carried, not validated: the raw authored bytes stay in the
        // delimiter, exactly as the pinned reserializer keeps them, and no
        // save-path consumer reads those families (see CARRIED_STYLE_PATHS),
        // so the saved markup cannot change. Generated markup occasionally
        // invents such paths (a button :hover background under elements, a
        // style.layout.contentSize on a group, per-block css) — carry them
        // per block instead of failing the whole file. Reviewed paths inside
        // the same families keep strict value validation.
        return $this->reviewedStyleView($style, self::STYLE_PATHS, '');
    }

    /**
     * Restrict an authored style tree to the reviewed rule tree wherever the
     * unreviewed remainder is carried state: an unknown key at or under a
     * carried family is hidden from this validation view while the raw
     * authored state stays in the delimiter. Unknown keys outside carried
     * families stay visible, so invented-path pruning and the fail-closed
     * assertions still see them.
     *
     * @param array<string,mixed> $value
     * @param array<string,mixed>|true $rule
     * @return array<string,mixed>
     */
    private function reviewedStyleView(array $value, array|bool $rule, string $prefix): array
    {
        $view = [];
        foreach ($value as $key => $child) {
            $label = is_string($key) ? $key : (string) $key;
            $path = $prefix === '' ? $label : $prefix . '.' . $label;
            $reviewed = is_string($key)
                && is_array($rule)
                && !str_starts_with($key, '@')
                && array_key_exists($key, $rule);
            if (!$reviewed) {
                if (!$this->isCarriedStylePath($path)) {
                    $view[$key] = $child;
                }
                continue;
            }
            if (is_array($child) && is_array($rule[$key])) {
                $childView = $this->reviewedStyleView($child, $rule[$key], $path);
                if ($childView !== []) {
                    $view[$key] = $childView;
                }
                continue;
            }
            $view[$key] = $child;
        }
        return $view;
    }

    private function isCarriedStylePath(string $path): bool
    {
        foreach (self::CARRIED_STYLE_PATHS as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '.')) {
                return true;
            }
        }
        return false;
    }
}
