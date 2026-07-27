<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Attributes;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
use Automattic\SiteBuild\BlockSerializer\Html\RichText;
use Automattic\SiteBuild\BlockSerializer\ParagraphFixer;
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
        'core/button' => [
            // Button deprecation index 3 stored percentage widths as a
            // number and migrated them to style.dimensions.width.
            'width' => true,
            // AI-authored alias. The registered url attribute is sourced
            // from the saved <a href>, while this unregistered delimiter key
            // is discarded by the pinned createBlock path.
            'href' => true,
            // AI-authored legacy alignment paired with the old generated
            // has-text-align-center link class. The pinned current block
            // drops both rather than migrating them to typography support.
            'textAlign' => true,
        ],
        'core/group' => [
            // AI-authored legacy top-level border support. The current schema
            // accepts only style.border, so the pinned createBlock path drops
            // this key and its stale saved declaration.
            'border' => true,
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
        'core/image' => [
            // AI-authored legacy support form observed in tbilisi35. The
            // pinned registry drops the top-level key and its stale wrapper
            // box-shadow while retaining current style.border support.
            'shadow' => true,
        ],
        'core/paragraph' => [
            // AI-authored container layout on a leaf paragraph. The pinned
            // createBlock path drops it while retaining the paragraph's
            // current spacing and typography attributes.
            'layout' => true,
            // AI-authored alias for style.typography.fontStyle observed in
            // tbilisi60 ({"fontStyle":"italic"} with a font-style:italic
            // root declaration). The pinned createBlock path drops the
            // unregistered delimiter key; the authored italic survives via
            // the selector-less deprecation's root carryover, the same path
            // pinned by paragraph-inline-color-carryover.
            'fontStyle' => true,
        ],
        'core/site-title' => [
            // Deprecated version 0; exercised by tbilisi25-footer-fixed-point.
            'textAlign' => true,
        ],
    ];

    /**
     * Root inline-style properties an AI-authored core/paragraph carries even
     * though the block does not implement them. Strip these from selector-less
     * deprecation carryovers as well as current-save output, then report the
     * deterministic degradation instead of failing the whole build.
     */
    private const STRIPPED_INERT_PARAGRAPH_ROOT_STYLES = [
        // opacity is a separator/cover support, not a paragraph one; a
        // generated `style="opacity:…"` on a paragraph has no save consumer.
        // Full opacity is the safest readable fallback.
        'opacity' => true,
    ];

    /**
     * Residual style dispositions reviewed against generated build failures.
     * Every other residual property remains fail-closed until its exact
     * signature and semantics-safe fallback are reviewed separately.
     */
    private const REVIEWED_PARAGRAPH_STYLE_DEGRADATIONS = [
        'opacity' => true,
    ];

    public function isReviewedLegacyCommentAttribute(string $name, string $key): bool
    {
        return isset(self::REVIEWED_LEGACY_COMMENT_KEYS[$name][$key]);
    }

    /**
     * Report explicitly reviewed root-style degradations after every adapter
     * has had a chance to preserve them. Unknown residual styles still throw:
     * current-schema output is only a safe fallback for signatures whose
     * content and disposition have been reviewed and regression-tested.
     *
     * @param array<string,mixed> $commentAttributes
     * @return list<Repair>
     */
    public function residualParagraphStyleRepairs(
        string $name,
        array $commentAttributes,
        string $originalContent,
        string $currentContent,
        string $blockPath,
    ): array {
        if ($name !== 'core/paragraph') {
            return [];
        }
        $actual = $this->rootStyles($originalContent);
        if ($actual === []) {
            return [];
        }
        $current = $this->effectiveRootStyles($currentContent);
        $repairs = [];
        foreach ($actual as $property => $value) {
            if (array_key_exists($property, $current) && $current[$property] === $value) {
                continue;
            }
            if (!$this->isReviewedParagraphStyleDegradation(
                $property,
                $value,
                $commentAttributes,
                $originalContent,
                $currentContent,
            )) {
                throw new \RuntimeException(
                    "Unsupported deprecated core/paragraph style signature at {$blockPath}: {$property}; "
                    . 'a reviewed deprecation adapter is required'
                );
            }
            $payload = json_encode([
                'property' => $property,
                'authored' => $value,
                'delivered' => $current[$property] ?? null,
                'disposition' => $property === 'opacity'
                    ? 'removed; core/paragraph has no opacity save consumer'
                    : 'removed; conflicting center and justify alignment has no unambiguous winner',
                'reviewed' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $repairs[] = new Repair(
                'paragraph-style-degraded:' . ($payload === false ? '{}' : $payload),
                $blockPath,
            );
        }
        return $repairs;
    }

    /**
     * @param array<string,mixed> $commentAttributes
     */
    private function isReviewedParagraphStyleDegradation(
        string $property,
        string $value,
        array $commentAttributes,
        string $originalContent,
        string $currentContent,
    ): bool {
        $originalRoot = $this->soleParagraphRoot($originalContent);
        $currentRoot = $this->projectedCurrentParagraphRoot($currentContent);
        if ($originalRoot === null
            || $currentRoot === null
            || $originalRoot->innerHtml() !== $currentRoot->innerHtml()) {
            return false;
        }

        $originalAttributes = $this->attributeMap($originalRoot);
        $currentAttributes = $this->attributeMap($currentRoot);
        unset($originalAttributes['style'], $currentAttributes['style']);

        $originalStyles = $this->rootStyles($originalRoot->outerHtml());
        $currentStyles = $this->rootStyles($currentRoot->outerHtml());
        unset($originalStyles[$property]);
        if ($originalStyles !== $currentStyles) {
            return false;
        }

        if (isset(self::REVIEWED_PARAGRAPH_STYLE_DEGRADATIONS[$property])) {
            if (preg_match(
                '/^[+-]?(?:(?:\d+(?:\.\d*)?)|(?:\.\d+))(?:[eE][+-]?\d+)?%?$/',
                $value,
            ) !== 1) {
                return false;
            }
            return $originalAttributes === $currentAttributes;
        }

        // BIGR-728 is deliberately signature-specific. A legacy center class
        // contradicting current justify attributes has no unambiguous winner;
        // dropping only that rendered class and declaration keeps the copy and
        // every unrelated attribute/style byte.
        if ($property !== 'text-align'
            || $value !== 'justify'
            || ($commentAttributes['align'] ?? null) !== 'center'
            || ($commentAttributes['style']['typography']['textAlign'] ?? null) !== 'justify') {
            return false;
        }
        $classes = preg_split(
            '/\s+/',
            trim((string) ($originalAttributes['class'] ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $alignmentClasses = array_values(array_filter(
            $classes,
            static fn (string $class): bool => str_starts_with($class, 'has-text-align-'),
        ));
        if ($alignmentClasses !== ['has-text-align-center']) {
            return false;
        }
        $expectedClasses = array_values(array_filter(
            $classes,
            static fn (string $class): bool => $class !== 'has-text-align-center',
        ));
        if ($expectedClasses === []) {
            unset($originalAttributes['class']);
        } else {
            $originalAttributes['class'] = implode(' ', $expectedClasses);
        }
        if (($currentAttributes['class'] ?? '') === '') {
            unset($currentAttributes['class']);
        }
        return $originalAttributes === $currentAttributes;
    }

    private function soleParagraphRoot(string $html): ?HtmlNode
    {
        $paragraph = null;
        foreach (HtmlFragment::parse($html)->root()->children() as $child) {
            if ($child->isText() && trim($child->textContent()) === '') {
                continue;
            }
            if (!$child->isElement() || $child->tagName() !== 'p' || $paragraph !== null) {
                return null;
            }
            $paragraph = $child;
        }
        return $paragraph;
    }

    private function projectedCurrentParagraphRoot(string $currentContent): ?HtmlNode
    {
        $open = '<!-- wp:paragraph -->';
        $close = '<!-- /wp:paragraph -->';
        $fixed = (new ParagraphFixer())->fix($open . $currentContent . $close)->html;
        if (!str_starts_with($fixed, $open) || !str_ends_with($fixed, $close)) {
            return null;
        }
        return $this->soleParagraphRoot(substr(
            $fixed,
            strlen($open),
            strlen($fixed) - strlen($open) - strlen($close),
        ));
    }

    /** @return array<string,string> */
    private function attributeMap(HtmlNode $root): array
    {
        $attributes = [];
        foreach ($root->attributes() as $attribute) {
            $attributes[$attribute['name']] = $attribute['value'];
        }
        return $attributes;
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
        } elseif ($name === 'core/button') {
            $attributes = $this->button(
                $attributes,
                $originalContent,
                $rawCommentAttributes,
                $matched,
            );
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
        } elseif ($name === 'core/separator') {
            $attributes = $this->separator(
                $attributes,
                $originalContent,
                $matched,
                $currentCandidateValid,
            );
        } elseif ($name === 'core/image') {
            $attributes = $this->image(
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
     * Reviewed button deprecation index 3. Its numeric width and matching
     * wrapper classes migrate to the current dimensions support.
     *
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $rawCommentAttributes
     * @return array<string,mixed>
     */
    private function button(
        array $attributes,
        string $originalContent,
        array $rawCommentAttributes,
        bool &$matched,
    ): array {
        if (array_key_exists('textAlign', $rawCommentAttributes)
            && $rawCommentAttributes['textAlign'] !== 'center') {
            throw new \RuntimeException(
                'Unsupported legacy core/button textAlign value; '
                . 'a reviewed deprecation adapter is required'
            );
        }
        $width = $rawCommentAttributes['width'] ?? null;
        if ((!is_int($width) && !is_float($width)) || !is_finite((float) $width) || $width <= 0) {
            return $attributes;
        }
        $renderedWidth = rtrim(rtrim(sprintf('%.14F', (float) $width), '0'), '.');
        $root = HtmlFragment::parse($originalContent)->root()->elementChildren()[0] ?? null;
        if ($root === null || $root->tagName() !== 'div') {
            return $attributes;
        }
        $classes = preg_split(
            '/\s+/',
            trim((string) ($root->attribute('class') ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        if (!in_array('has-custom-width', $classes, true)
            || !in_array('wp-block-button__width-' . $renderedWidth, $classes, true)) {
            return $attributes;
        }
        $link = $root->elementChildren()[0] ?? null;
        $linkClasses = preg_split(
            '/\s+/',
            trim((string) ($link?->attribute('class') ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $hasBackground = !empty($attributes['backgroundColor'])
            || !empty($attributes['style']['color']['background']);
        if ($hasBackground && !in_array('has-background', $linkClasses, true)) {
            // The pinned width deprecation cannot validate this older save;
            // Gutenberg drops the legacy key but retains wrapper classes via
            // its preliminary custom-class recovery instead.
            return $attributes;
        }

        $style = is_array($attributes['style'] ?? null) ? $attributes['style'] : [];
        $dimensions = is_array($style['dimensions'] ?? null) ? $style['dimensions'] : [];
        $dimensions['width'] = $renderedWidth . '%';
        $style['dimensions'] = $dimensions;
        $attributes['style'] = $style;
        if (is_string($attributes['className'] ?? null)) {
            $custom = array_values(array_filter(
                preg_split('/\s+/', trim($attributes['className']), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                static fn (string $class): bool => $class !== 'has-custom-width'
                    && $class !== 'wp-block-button__width-' . $renderedWidth
                    && $class !== 'has-custom-font-size'
                    && (!is_string($attributes['fontSize'] ?? null)
                        || $class !== 'has-' . SupportEngine::slug($attributes['fontSize']) . '-font-size'),
            ));
            if ($custom === []) {
                unset($attributes['className']);
            } else {
                $attributes['className'] = implode(' ', $custom);
            }
        }
        $matched = true;
        return $attributes;
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
     * Reviewed separator opacity recovery: older generated markup carries
     * has-css-opacity in saved HTML without the current comment attribute.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function separator(
        array $attributes,
        string $originalContent,
        bool &$matched,
        bool $currentCandidateValid,
    ): array
    {
        $root = HtmlFragment::parse($originalContent)->root()->elementChildren()[0] ?? null;
        $classes = preg_split(
            '/\s+/',
            trim((string) ($root?->attribute('class') ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $hasCss = in_array('has-css-opacity', $classes, true);
        $hasAlpha = in_array('has-alpha-channel-opacity', $classes, true);
        if (!$hasCss && ($currentCandidateValid || !$hasAlpha)) {
            return $attributes;
        }
        $matched = true;
        $attributes['opacity'] = 'css';
        if (!$hasCss) {
            $attributes['className'] = implode(' ', array_values(array_filter(
                $classes,
                static fn (string $class): bool => $class !== 'wp-block-separator',
            )));
            return $attributes;
        }
        if (is_string($attributes['className'] ?? null)) {
            $custom = array_values(array_filter(
                preg_split('/\s+/', trim($attributes['className']), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                static fn (string $class): bool => $class !== 'has-css-opacity'
                    && $class !== 'has-alpha-channel-opacity',
            ));
            if ($custom === []) {
                unset($attributes['className']);
            } else {
                $attributes['className'] = implode(' ', $custom);
            }
        }
        return $attributes;
    }

    /**
     * Reviewed pre-border image save: a failed current candidate recovers the
     * authored has-custom-border wrapper class before current attrs overlay.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function image(
        array $attributes,
        string $originalContent,
        bool &$matched,
        bool $currentCandidateValid,
    ): array {
        if ($currentCandidateValid || !is_array($attributes['style']['border'] ?? null)) {
            return $attributes;
        }
        $root = HtmlFragment::parse($originalContent)->root()->elementChildren()[0] ?? null;
        $classes = preg_split(
            '/\s+/',
            trim((string) ($root?->attribute('class') ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        if (!in_array('has-custom-border', $classes, true)) {
            return $attributes;
        }
        $matched = true;
        $custom = is_string($attributes['className'] ?? null)
            ? preg_split('/\s+/', trim($attributes['className']), -1, PREG_SPLIT_NO_EMPTY) ?: []
            : [];
        if (!in_array('has-custom-border', $custom, true)) {
            $custom[] = 'has-custom-border';
        }
        $attributes['className'] = implode(' ', $custom);
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

        // A pinned paragraph version predates border support. When generated
        // HTML carries the authored color/font classes but not the current
        // has-border-color class, its deprecation-phase class recovery keeps
        // those existing classes in className before raw current attrs overlay.
        if (!$currentCandidateValid
            && is_array($attributes['style']['border'] ?? null)
            && !in_array('has-border-color', $classes, true)) {
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

        $typography = $attributes['style']['typography'] ?? null;
        $authoredStyles = $this->rootStyles($originalContent);
        if (!$currentCandidateValid
            && is_array($typography)
            && array_key_exists('lineHeight', $typography)
            && !array_key_exists('line-height', $authoredStyles)) {
            $matched = true;
            if ($classes === []) {
                unset($attributes['className']);
            } else {
                $attributes['className'] = implode(' ', $classes);
            }
            return $attributes;
        }

        if (is_string($align) && $align !== ''
            && in_array('has-text-align-' . $align, $classes, true)
            && !$this->hasShortSpacingPresetSignature($attributes, $originalContent)
            && !$this->hasAuthoredStyleCarryoverSignature($attributes, $originalContent)) {
            $matched = true;
            if ($fontClass !== null && !in_array($fontClass, $classes, true)) {
                // The earlier align deprecations cannot validate when their
                // font-size support would add a class absent from the input.
                // The observed selector-less version sources the entire root,
                // which creates the nested paragraph repaired post-save.
                $attributes['content'] = $this->paragraphCarryoverContent($root);
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
        $elements = $attributes['style']['elements'] ?? null;
        if (!$currentCandidateValid
            && is_array($elements)
            && is_array($elements['link'] ?? null)) {
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
            $attributes['content'] = $this->paragraphCarryoverContent($root);
            unset($attributes['className']);
        }
        return $attributes;
    }

    /**
     * Selector-less paragraph deprecations carry the authored root element as
     * rich-text content. Remove reviewed inert styles before that carryover so
     * every migration path reaches the same readable current-schema fallback.
     */
    private function paragraphCarryoverContent(HtmlNode $root): string
    {
        $style = $root->attribute('style');
        if ($style === null || $style === '') {
            return $root->outerHtml();
        }

        $kept = [];
        $removed = false;
        foreach ($this->styleDeclarations($style) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '') {
                // rootStyles() owns malformed-style validation; retaining the
                // declaration here keeps this helper side-effect-free.
                $kept[] = trim($declaration);
                continue;
            }
            if (isset(self::STRIPPED_INERT_PARAGRAPH_ROOT_STYLES[strtolower(trim($parts[0]))])) {
                $removed = true;
                continue;
            }
            $kept[] = trim($declaration);
        }
        if (!$removed) {
            return $root->outerHtml();
        }

        // Re-serialize the parsed root structurally. A string search for the
        // opening tag is not safe because HTML permits `>` inside a quoted
        // attribute value (for example, title="1 > 0").
        $tag = (string) $root->tagName();
        $html = '<' . $tag;
        foreach ($root->attributes() as $attribute) {
            if ($attribute['name'] === 'style') {
                if ($kept === []) {
                    continue;
                }
                $value = implode(';', $kept);
            } else {
                $value = $attribute['value'];
            }
            $html .= ' ' . $attribute['name'] . '="' . self::htmlAttributeValue($value) . '"';
        }
        return $html . '>' . $root->innerHtml() . '</' . $tag . '>';
    }

    private static function htmlAttributeValue(string $value): string
    {
        return str_replace(
            ['&', "\u{00A0}", '"'],
            ['&amp;', '&nbsp;', '&quot;'],
            $value,
        );
    }

    /**
     * Selector-less paragraph deprecations preserve authored styles that are
     * absent from the comment. Do not let the earlier align migration win
     * when it would discard one of these reviewed generated-theme properties.
     * The final signature pass still rejects every unreviewed unmirrored style.
     *
     * @param array<string,mixed> $attributes
     */
    private function hasAuthoredStyleCarryoverSignature(
        array $attributes,
        string $originalContent,
    ): bool {
        $actual = $this->rootStyles($originalContent);
        $typography = $attributes['style']['typography'] ?? [];
        $typography = is_array($typography) ? $typography : [];

        // ContrastFix swaps style.color.text for a preset textColor while
        // intentionally leaving the saved HTML stale for this serializer.
        // The unmirrored inline color prevents paragraph deprecation index 0
        // from validating, so the pinned parser reaches selector-less index 6.
        $authoredColor = $actual['color'] ?? null;
        $commentColor = $attributes['style']['color']['text'] ?? null;
        if ($authoredColor !== null
            && (!is_string($commentColor) || $commentColor !== $authoredColor)) {
            return true;
        }

        foreach ([
            'letter-spacing' => 'letterSpacing',
            'text-transform' => 'textTransform',
            // Exact model typo observed in naturaleza31. The pinned Node
            // selector-less deprecation preserves this inert declaration.
            'let-spacing' => null,
        ] as $css => $key) {
            if (array_key_exists($css, $actual)
                && ($key === null
                    || !array_key_exists($key, $typography)
                    || (string) $typography[$key] !== $actual[$css])) {
                return true;
            }
        }
        return false;
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
        foreach ($this->styleDeclarations($style) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }
            $parts = explode(':', $declaration, 2);
            $propertyToken = trim($parts[0] ?? '');
            if (count($parts) !== 2
                || $propertyToken === ''
                || str_contains($propertyToken, '/*')
                || str_contains($propertyToken, '*/')
                || str_contains($propertyToken, '\\')) {
                throw new \RuntimeException('Unsupported malformed paragraph style declaration');
            }
            $property = strtolower($propertyToken);
            $value = preg_replace('/\s+/', ' ', trim($parts[1])) ?? trim($parts[1]);
            if ($value === '') {
                throw new \RuntimeException('Unsupported malformed paragraph style declaration');
            }
            $declarations[$property] = $value;
        }
        return $declarations;
    }

    /**
     * Split a style attribute without treating semicolons inside strings,
     * comments, escapes, or function parentheses as declaration boundaries.
     *
     * @return list<string>
     */
    private function styleDeclarations(string $style): array
    {
        $declarations = [];
        $start = 0;
        $quote = null;
        $parentheses = 0;
        $inComment = false;
        $length = strlen($style);

        for ($index = 0; $index < $length; $index++) {
            $character = $style[$index];
            if ($inComment) {
                if ($character === '*' && ($style[$index + 1] ?? '') === '/') {
                    $inComment = false;
                    $index++;
                }
                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    if ($index + 1 >= $length) {
                        throw new \RuntimeException('Unsupported malformed paragraph style declaration');
                    }
                    $index++;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '/' && ($style[$index + 1] ?? '') === '*') {
                $inComment = true;
                $index++;
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '\\') {
                if ($index + 1 >= $length) {
                    throw new \RuntimeException('Unsupported malformed paragraph style declaration');
                }
                $index++;
                continue;
            }
            if ($character === '(') {
                $parentheses++;
                continue;
            }
            if ($character === ')') {
                if ($parentheses === 0) {
                    throw new \RuntimeException('Unsupported malformed paragraph style declaration');
                }
                $parentheses--;
                continue;
            }
            if ($character === ';' && $parentheses === 0) {
                $declarations[] = substr($style, $start, $index - $start);
                $start = $index + 1;
            }
        }

        if ($quote !== null || $inComment || $parentheses !== 0) {
            throw new \RuntimeException('Unsupported malformed paragraph style declaration');
        }
        $declarations[] = substr($style, $start);
        return $declarations;
    }
}
