<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Attributes;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\RichText;
use Automattic\SiteBuild\BlockSerializer\Repair;
use Automattic\SiteBuild\BlockSerializer\Supports\SupportEngine;

/**
 * Reviewed adapters for deprecation signatures observed by the pinned oracle.
 *
 * New instrumentation hits must receive an explicit adapter or a reviewed
 * current-save-equivalent disposition before the supported manifest can grow.
 */
final class DeprecationAdapters
{
    /**
     * Legacy-only comment keys whose pinned migration is covered by a golden.
     * The current-schema overlay deliberately omits these keys after the
     * adapter has admitted the signature.
     */
    private const REVIEWED_LEGACY_COMMENT_KEYS = [
        'core/group' => [
            // AI-authored legacy support form observed in a generated demo.
            // The pinned current schema has no top-level shadow attribute,
            // so createBlock drops both the delimiter key and stale HTML
            // declaration. Covered by generated-demo-* golden drops.
            'shadow' => true,
        ],
        'core/heading' => [
            // Deprecated version 0; exercised by heading-legacy-text-align.
            // The pinned migration reads the recovered has-text-align-* class,
            // not this comment key, so the key is dropped either way.
            'textAlign' => true,
        ],
        'core/site-title' => [
            // Deprecated version 0; exercised by tbilisi25-footer-fixed-point.
            'textAlign' => true,
        ],
    ];

    public function isReviewedLegacyCommentAttribute(string $name, string $key): bool
    {
        return isset(self::REVIEWED_LEGACY_COMMENT_KEYS[$name][$key]);
    }

    /**
     * Reject recognizable historical signatures which are outside the
     * reviewed adapter set. This runs after current-schema recreation, while
     * the original saved HTML is still available, and therefore prevents a
     * lossy current renderer from disguising an unported deprecation match.
     *
     * @param array<string,mixed> $commentAttributes
     */
    public function assertNoUnknownSignature(
        string $name,
        array $commentAttributes,
        string $originalContent,
        string $currentContent,
        string $blockPath,
    ): void {
        if ($name !== 'core/paragraph') {
            return;
        }
        $actual = $this->rootStyles($originalContent);
        if ($actual === []) {
            return;
        }
        $current = $this->effectiveRootStyles($currentContent);
        foreach ($actual as $property => $value) {
            if (!array_key_exists($property, $current) || $current[$property] !== $value) {
                throw new \RuntimeException(
                    "Unsupported deprecated core/paragraph style signature at {$blockPath}: {$property}; "
                    . 'a reviewed deprecation adapter is required'
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $rawCommentAttributes
     * @return array{attributes:array<string,mixed>,repairs:list<Repair>,matched:bool}
     */
    public function apply(
        string $name,
        array $attributes,
        string $originalContent,
        string $blockPath,
        array $rawCommentAttributes = [],
        bool $currentCandidateValid = false,
    ): array {
        $matched = false;
        if ($name === 'core/paragraph') {
            $attributes = $this->paragraph(
                $attributes,
                $originalContent,
                $matched,
                $currentCandidateValid,
            );
            if ($matched && is_string($attributes['content'] ?? null)) {
                $attributes['content'] = RichText::fromHtmlString($attributes['content']);
            }
        } elseif ($name === 'core/columns') {
            $attributes = $this->columns(
                $attributes,
                $originalContent,
                $matched,
                $currentCandidateValid,
            );
        } elseif ($name === 'core/group') {
            $attributes = $this->group(
                $attributes,
                $originalContent,
                $matched,
                $currentCandidateValid,
            );
        } elseif ($name === 'core/heading') {
            $attributes = $this->heading($attributes, $rawCommentAttributes, $matched);
        } elseif ($name === 'core/navigation') {
            $attributes = $this->navigation($attributes, $matched);
        } elseif ($name === 'core/site-title') {
            $attributes = $this->siteTitle($attributes, $rawCommentAttributes, $matched);
        }
        return ['attributes' => $attributes, 'repairs' => [], 'matched' => $matched];
    }

    /**
     * Reviewed group deprecation index 4: element-link support invalidates the
     * current candidate, while the old save recovers authored text-color
     * classes before the raw current attributes are overlaid.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function group(
        array $attributes,
        string $originalContent,
        bool &$matched,
        bool $currentCandidateValid,
    ): array {
        if ($currentCandidateValid
            || ($attributes['align'] ?? null) !== 'full'
            || !is_array($attributes['style']['elements'] ?? null)) {
            return $attributes;
        }
        $root = HtmlFragment::parse($originalContent)->root()->elementChildren()[0] ?? null;
        if ($root === null) {
            return $attributes;
        }
        $classes = preg_split('/\s+/', trim((string) ($root->attribute('class') ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (in_array('has-link-color', $classes, true)) {
            return $attributes;
        }
        $recovered = array_values(array_filter(
            $classes,
            static fn (string $class): bool => $class !== 'wp-block-group'
                && !str_starts_with($class, 'align')
                && $class !== 'has-background'
                && !str_ends_with($class, '-background-color')
                && $class !== 'has-link-color',
        ));
        if ($recovered === []) {
            return $attributes;
        }
        $matched = true;
        $attributes['className'] = implode(' ', $recovered);
        return $attributes;
    }

    /**
     * Reviewed columns deprecation index 0: its save predates element-link
     * support and recovers the authored alignment class while the raw current
     * style object is restored by the later overlay.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function columns(
        array $attributes,
        string $originalContent,
        bool &$matched,
        bool $currentCandidateValid,
    ): array {
        $align = $attributes['align'] ?? null;
        if ($currentCandidateValid
            || !is_string($align)
            || !is_array($attributes['style']['elements'] ?? null)) {
            return $attributes;
        }
        $root = HtmlFragment::parse($originalContent)->root()->elementChildren()[0] ?? null;
        if ($root === null || $root->tagName() !== 'div') {
            return $attributes;
        }
        $class = 'align' . $align;
        $classes = preg_split('/\s+/', trim((string) ($root->attribute('class') ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array($class, $classes, true)) {
            return $attributes;
        }
        $matched = true;
        $attributes['className'] = $class;
        return $attributes;
    }

    /**
     * Reviewed heading deprecation index 0 (heading-legacy-text-align): a
     * legacy top-level textAlign comment attribute leaves an authored
     * has-text-align-* class that the current save() cannot emit, so the
     * built-in class recovery carries it into className and the pinned
     * migration then moves it into style.typography.textAlign. When the
     * alignment class was authored in the comment's own className, the
     * current save validates as-is and the pinned registry never migrates.
     *
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $rawCommentAttributes
     * @return array<string,mixed>
     */
    private function heading(
        array $attributes,
        array $rawCommentAttributes,
        bool &$matched,
    ): array {
        $className = $attributes['className'] ?? null;
        if (!is_string($className)
            || preg_match('/\bhas-text-align-(left|center|right)\b/', $className, $m) !== 1) {
            return $attributes;
        }
        $authored = $rawCommentAttributes['className'] ?? null;
        if (is_string($authored)
            && preg_match('/\b' . preg_quote($m[0], '/') . '\b/', $authored) === 1) {
            return $attributes;
        }
        $matched = true;
        $style = is_array($attributes['style'] ?? null) ? $attributes['style'] : [];
        $typography = is_array($style['typography'] ?? null) ? $style['typography'] : [];
        $typography['textAlign'] = $m[1];
        $style['typography'] = $typography;
        $attributes['style'] = $style;
        $custom = array_values(array_filter(
            preg_split('/\s+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $class): bool => $class !== $m[0],
        ));
        if ($custom === []) {
            unset($attributes['className']);
        } else {
            $attributes['className'] = implode(' ', $custom);
        }
        return $attributes;
    }

    /**
     * Reviewed site-title deprecations, in the pinned candidate order:
     * textAlign/className migration first, then style-level font family.
     * The caller's later shallow raw-comment overlay intentionally decides
     * whether a raw style object supersedes the migrated text alignment.
     *
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $rawCommentAttributes
     * @return array<string,mixed>
     */
    private function siteTitle(
        array $attributes,
        array $rawCommentAttributes,
        bool &$matched,
    ): array {
        $legacyAlign = $rawCommentAttributes['textAlign'] ?? null;
        if ($legacyAlign !== null && !is_string($legacyAlign)) {
            throw new \RuntimeException(
                'Unsupported deprecated core/site-title text-align value; '
                . 'a reviewed deprecation adapter is required'
            );
        }
        $className = $rawCommentAttributes['className'] ?? ($attributes['className'] ?? null);
        $alignEligible = is_string($legacyAlign) && $legacyAlign !== '';
        $classEligible = is_string($className)
            && preg_match('/\bhas-text-align-(?:left|center|right)\b/', $className) === 1;
        if ($alignEligible || $classEligible) {
            $matched = true;
            if ($alignEligible) {
                $style = is_array($attributes['style'] ?? null) ? $attributes['style'] : [];
                $typography = is_array($style['typography'] ?? null) ? $style['typography'] : [];
                $typography['textAlign'] = $legacyAlign;
                $style['typography'] = $typography;
                $attributes['style'] = $style;
            }
            return $attributes;
        }

        $typography = $attributes['style']['typography'] ?? null;
        $legacyFamily = is_array($typography) ? ($typography['fontFamily'] ?? null) : null;
        if ($legacyFamily === null || $legacyFamily === '') {
            return $attributes;
        }
        if (!is_string($legacyFamily)) {
            throw new \RuntimeException(
                'Unsupported deprecated core/site-title font-family value; '
                . 'a reviewed deprecation adapter is required'
            );
        }
        $matched = true;
        $parts = explode('|', $legacyFamily);
        $attributes['fontFamily'] = $parts[count($parts) - 1];
        return $attributes;
    }

    /**
     * Navigation deprecation index 3 is eligible when the old style-level
     * font-family value is present. Its migration supplies an overlay default
     * and flex layout; the authored current-schema overlay later restores
     * either key when it was explicitly present.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function navigation(array $attributes, bool &$matched): array
    {
        $typography = $attributes['style']['typography'] ?? null;
        if (!is_array($typography) || !array_key_exists('fontFamily', $typography)
            || $typography['fontFamily'] === null || $typography['fontFamily'] === '') {
            return $attributes;
        }
        if (!is_string($typography['fontFamily'])) {
            throw new \RuntimeException(
                'Unsupported deprecated core/navigation font-family value; '
                . 'a reviewed deprecation adapter is required'
            );
        }
        $matched = true;
        $attributes['overlayMenu'] = 'never';
        $parts = explode('|', $typography['fontFamily']);
        $attributes['fontFamily'] = $parts[count($parts) - 1];
        if (!isset($attributes['layout'])) {
            $attributes['layout'] = ['type' => 'flex', 'orientation' => 'horizontal'];
        }
        return $attributes;
    }

    /**
     * Adapters for the paragraph deprecations observed by the fixed-point
     * footer fixture: index 0 (align -> typography.textAlign), index 1 (the
     * pre-font-support save), and index 6 (selector-less content). Their
     * effects are intentionally not counted as PHP repair rows because the
     * reviewed oracle expectation records only the byte-affecting paragraph
     * nesting repair for this historical chain.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function paragraph(
        array $attributes,
        string $originalContent,
        bool &$matched,
        bool $currentCandidateValid,
    ): array {
        $root = HtmlFragment::parse($originalContent)->root()->elementChildren()[0] ?? null;
        if ($root === null || $root->tagName() !== 'p') {
            return $attributes;
        }
        $classes = preg_split(
            '/\s+/',
            trim((string) ($root->attribute('class') ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $align = $attributes['align'] ?? null;
        $fontSize = $attributes['fontSize'] ?? null;
        $fontClass = is_string($fontSize) && $fontSize !== ''
            ? 'has-' . SupportEngine::slug($fontSize) . '-font-size'
            : null;

        if (is_string($align) && $align !== ''
            && in_array('has-text-align-' . $align, $classes, true)
            && !$this->hasShortSpacingPresetSignature($attributes, $originalContent)) {
            $matched = true;
            if ($fontClass !== null && !in_array($fontClass, $classes, true)) {
                // The earlier align deprecations cannot validate when their
                // font-size support would add a class absent from the input.
                // The observed selector-less version sources the entire root,
                // which creates the nested paragraph repaired post-save.
                $attributes['content'] = $root->outerHtml();
                unset($attributes['className']);
                return $attributes;
            }

            $style = is_array($attributes['style'] ?? null) ? $attributes['style'] : [];
            $typography = is_array($style['typography'] ?? null) ? $style['typography'] : [];
            $typography['textAlign'] = $align;
            $style['typography'] = $typography;
            $attributes['style'] = $style;
            $custom = array_values(array_filter(
                $classes,
                static fn (string $class): bool => $class !== 'has-text-align-' . $align
                    && ($fontClass === null || $class !== $fontClass),
            ));
            if ($custom === []) {
                unset($attributes['className']);
            } else {
                $attributes['className'] = implode(' ', $custom);
            }
            return $attributes;
        }

        // Paragraph deprecation index 0 predates element-link support. If
        // that newer support is the reason the current candidate is invalid,
        // the old save validates and Gutenberg's deprecation-phase class
        // recovery carries the authored root classes into className.
        if (!$currentCandidateValid
            && is_array($attributes['style']['elements'] ?? null)) {
            $matched = true;
            $recovered = array_values(array_filter(
                $classes,
                static fn (string $class): bool => $fontClass === null || $class !== $fontClass,
            ));
            if ($recovered === []) {
                unset($attributes['className']);
            } else {
                $attributes['className'] = implode(' ', $recovered);
            }
            return $attributes;
        }

        // Index 1 predates spacing support. It validates when spacing exists
        // only in the comment (the authored root has none of those
        // declarations), while retaining the existing font-size class via
        // the same built-in recovery. If authored spacing is present, this
        // candidate cannot match and the reviewed selector-less adapter below
        // remains responsible for carrying it through.
        if (!$currentCandidateValid
            && $fontClass !== null
            && in_array($fontClass, $classes, true)
            && is_array($attributes['style']['spacing'] ?? null)
            && !$this->hasAuthoredSpacingDeclaration($attributes, $originalContent)) {
            $matched = true;
            $attributes['className'] = implode(' ', $classes);
            return $attributes;
        }

        // The observed pre-font-support version validates a paragraph whose
        // authored HTML omits the current preset font-size class. Its built-in
        // class recovery carries the remaining authored root classes forward.
        if (!$currentCandidateValid
            && $fontClass !== null
            && !in_array($fontClass, $classes, true)) {
            $matched = true;
            if ($classes === []) {
                unset($attributes['className']);
            } else {
                $attributes['className'] = implode(' ', $classes);
            }
            return $attributes;
        }

        // Every other invalid paragraph falls through to the selector-less
        // deprecation, whose save reproduces the authored bytes and therefore
        // always validates. It sources the entire root element as content;
        // the current save then wraps it, and the post-serialize
        // nested-paragraph repair merges the wrapper's classes and inline
        // styles with the authored ones. Observed for authored inline styles
        // that contradict the comment's preset color
        // (paragraph-inline-color-carryover).
        if (!$currentCandidateValid) {
            $matched = true;
            $attributes['content'] = $root->outerHtml();
            unset($attributes['className']);
        }
        return $attributes;
    }

    /**
     * Paragraph deprecation index 0 serializes short spacing slugs literally,
     * while the selector-less index 6 can validate authored preset-variable
     * declarations and carry them through the nested-paragraph merge. This
     * exact signature is emitted by generated themes (`top: "md"` paired
     * with `margin-top:var(--wp--preset--spacing--md)`).
     *
     * @param array<string,mixed> $attributes
     */
    private function hasShortSpacingPresetSignature(array $attributes, string $originalContent): bool
    {
        $spacing = $attributes['style']['spacing'] ?? null;
        if (!is_array($spacing)) {
            return false;
        }
        $actual = $this->rootStyles($originalContent);
        foreach (['margin', 'padding'] as $family) {
            $box = $spacing[$family] ?? null;
            if (!is_array($box)) {
                continue;
            }
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $slug = $box[$side] ?? null;
                if (!is_string($slug) || $slug === '' || str_contains($slug, ':')) {
                    continue;
                }
                $property = $family . '-' . $side;
                if (($actual[$property] ?? null) === "var(--wp--preset--spacing--{$slug})") {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string,mixed> $attributes */
    private function hasAuthoredSpacingDeclaration(array $attributes, string $originalContent): bool
    {
        $spacing = $attributes['style']['spacing'] ?? null;
        if (!is_array($spacing)) {
            return false;
        }
        $actual = $this->rootStyles($originalContent);
        foreach (['margin', 'padding'] as $family) {
            $box = $spacing[$family] ?? null;
            if (!is_array($box)) {
                continue;
            }
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                if (array_key_exists($side, $box) && array_key_exists("{$family}-{$side}", $actual)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Root style declarations of a rendered candidate after projecting the
     * pinned post-serialize nested-paragraph merge: when the current save
     * wraps selector-less deprecation content in a second <p>, the wrapper's
     * declarations are merged with the authored inner ones, inner values
     * winning — exactly what the document-level ParagraphFixer later applies.
     *
     * @return array<string,string>
     */
    private function effectiveRootStyles(string $html): array
    {
        $elements = HtmlFragment::parse($html)->root()->elementChildren();
        $styles = $this->rootStyles($html);
        // HTML parsing auto-closes a <p> when the next one opens, so the
        // save wrapper and the authored root arrive as two sibling <p>
        // elements rather than nested ones.
        if (count($elements) === 2
            && $elements[0]->tagName() === 'p'
            && $elements[1]->tagName() === 'p') {
            foreach ($this->rootStyles($elements[1]->outerHtml()) as $property => $value) {
                $styles[$property] = $value;
            }
        }
        return $styles;
    }

    /** @return array<string,string> */
    private function rootStyles(string $html): array
    {
        $root = HtmlFragment::parse($html)->root()->elementChildren()[0] ?? null;
        $style = $root?->attribute('style');
        if ($style === null || trim($style) === '') {
            return [];
        }
        $declarations = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '') {
                throw new \RuntimeException('Unsupported malformed paragraph style declaration');
            }
            $property = strtolower(trim($parts[0]));
            $value = preg_replace('/\s+/', ' ', trim($parts[1])) ?? trim($parts[1]);
            $declarations[$property] = $value;
        }
        return $declarations;
    }
}
