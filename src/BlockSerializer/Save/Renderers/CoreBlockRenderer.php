<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Save\Renderers;

use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\ElementNode;
use Automattic\SiteBuild\BlockSerializer\Save\RawNode;
use Automattic\SiteBuild\BlockSerializer\Save\SaveNode;
use Automattic\SiteBuild\BlockSerializer\Save\TextNode;
use Automattic\SiteBuild\BlockSerializer\Supports\StyleEngine;
use Automattic\SiteBuild\BlockSerializer\Supports\SupportEngine;

/** Explicit save implementations for every static block in the admitted domain. */
final class CoreBlockRenderer
{
    public function __construct(
        private BlockRegistry $registry,
        private ?SupportEngine $supports = null,
        private ?StyleEngine $styles = null,
    ) {
        $this->supports ??= new SupportEngine();
        $this->styles ??= new StyleEngine();
    }

    /** @param array<string,mixed> $attrs */
    public function render(string $name, array $attrs, string $innerHtml): SaveNode|string|null
    {
        return match ($name) {
            'core/paragraph' => $this->paragraph($attrs),
            'core/group' => $this->group($attrs, $innerHtml),
            'core/column' => $this->column($attrs, $innerHtml),
            'core/columns' => $this->columns($attrs, $innerHtml),
            'core/heading' => $this->heading($attrs),
            'core/image' => $this->image($attrs),
            'core/separator' => $this->separator($attrs),
            'core/buttons' => $this->buttons($attrs, $innerHtml),
            'core/button' => $this->button($attrs),
            'core/spacer' => $this->spacer($attrs),
            'core/list' => $this->list($attrs, $innerHtml),
            'core/list-item' => $this->listItem($attrs, $innerHtml),
            'core/cover' => $this->cover($attrs, $innerHtml),
            'core/details' => $this->details($attrs, $innerHtml),
            'core/media-text' => $this->mediaText($attrs, $innerHtml),
            'core/quote' => $this->quote($attrs, $innerHtml),
            'core/pullquote' => $this->pullquote($attrs),
            'core/gallery' => $this->gallery($attrs, $innerHtml),
            'core/table' => $this->table($attrs),
            'core/embed' => $this->embed($attrs),
            'core/social-links' => $this->socialLinks($attrs, $innerHtml),
            default => throw new \RuntimeException("No static PHP renderer for '{$name}'"),
        };
    }

    /** @param array<string,mixed> $attrs */
    private function paragraph(array $attrs): ElementNode
    {
        $textAlign = $attrs['style']['typography']['textAlign'] ?? null;
        $class = !empty($attrs['dropCap']) && !in_array($textAlign, ['right', 'center'], true)
            ? 'has-drop-cap' : '';
        return new ElementNode('p', $this->props('core/paragraph', $attrs, [
            'className' => $class,
            'dir' => $attrs['direction'] ?? null,
        ]), [new RawNode((string) ($attrs['content'] ?? ''))]);
    }

    /** @param array<string,mixed> $attrs */
    private function group(array $attrs, string $inner): ElementNode
    {
        return new ElementNode(
            (string) ($attrs['tagName'] ?? 'div'),
            $this->props('core/group', $attrs),
            [new RawNode($inner)],
        );
    }

    /** @param array<string,mixed> $attrs */
    private function column(array $attrs, string $inner): ElementNode
    {
        $class = !empty($attrs['verticalAlignment']) ? 'is-vertically-aligned-' . $attrs['verticalAlignment'] : '';
        $style = [];
        $width = $attrs['width'] ?? null;
        if (($width !== null && preg_match('/\d/', (string) $width) === 1)) {
            if (is_int($width) || is_float($width)) {
                $style['flexBasis'] = $this->number($width) . '%';
            } else {
                $basis = (string) $width;
                if (str_ends_with($basis, '%')) {
                    $number = round((float) $basis * 1_000_000_000_000) / 1_000_000_000_000;
                    $basis = $this->number($number) . '%';
                }
                $style['flexBasis'] = $basis;
            }
        }
        return new ElementNode('div', $this->props('core/column', $attrs, ['className' => $class, 'style' => $style]), [new RawNode($inner)]);
    }

    /** @param array<string,mixed> $attrs */
    private function columns(array $attrs, string $inner): ElementNode
    {
        $classes = [];
        if (!empty($attrs['verticalAlignment'])) {
            $classes[] = 'are-vertically-aligned-' . $attrs['verticalAlignment'];
        }
        if (($attrs['isStackedOnMobile'] ?? true) === false) {
            $classes[] = 'is-not-stacked-on-mobile';
        }
        return new ElementNode('div', $this->props('core/columns', $attrs, ['className' => implode(' ', $classes)]), [new RawNode($inner)]);
    }

    /** @param array<string,mixed> $attrs */
    private function details(array $attrs, string $inner): ElementNode
    {
        // Gutenberg's save spreads blockProps first, then name={name || undefined}
        // and open={showContent}: the anchor id and support classes/styles land
        // before both attributes, and an empty name is omitted entirely.
        $props = $this->props('core/details', $attrs);
        $name = $attrs['name'] ?? null;
        $props['name'] = is_string($name) && $name !== '' ? $name : null;
        $props['open'] = !empty($attrs['showContent']);
        return new ElementNode('details', $props, [
            new ElementNode('summary', [], [new RawNode(is_string($attrs['summary'] ?? null) ? $attrs['summary'] : '')]),
            new RawNode($inner),
        ]);
    }

    /** @param array<string,mixed> $attrs */
    private function heading(array $attrs): ElementNode
    {
        $level = $attrs['level'] ?? 2;
        return new ElementNode('h' . (string) $level, $this->props('core/heading', $attrs), [
            new RawNode((string) ($attrs['content'] ?? '')),
        ]);
    }

    /** @param array<string,mixed> $attrs */
    private function image(array $attrs): ElementNode
    {
        [$borderClasses, $borderStyle] = $this->borderProps($attrs);
        $shadowStyle = $this->shadowStyles($attrs);
        $figureClasses = [];
        if (($attrs['align'] ?? null) === 'none') {
            $figureClasses[] = 'alignnone';
        }
        if (!empty($attrs['sizeSlug'])) {
            $figureClasses[] = 'size-' . $attrs['sizeSlug'];
        }
        if (!empty($attrs['width']) || !empty($attrs['height'])) {
            $figureClasses[] = 'is-resized';
        }
        if ($borderClasses !== '' || $borderStyle !== []) {
            $figureClasses[] = 'has-custom-border';
        }

        $imageClasses = $borderClasses;
        if (!empty($attrs['id'])) {
            $imageClasses = trim($imageClasses . ' wp-image-' . $attrs['id']);
        }
        $imageStyle = array_replace($borderStyle, $shadowStyle);
        foreach (['aspectRatio', 'scale'] as $key) {
            if (array_key_exists($key, $attrs)) {
                $imageStyle[$key === 'scale' ? 'objectFit' : $key] = $attrs[$key];
            }
        }
        if (!empty($attrs['focalPoint']) && !empty($attrs['scale'])) {
            $imageStyle['objectPosition'] = $this->mediaPosition($attrs['focalPoint']);
        }
        foreach (['width', 'height'] as $key) {
            if (array_key_exists($key, $attrs)) {
                $imageStyle[$key] = $attrs[$key];
            }
        }
        $image = new ElementNode('img', [
            'src' => $attrs['url'] ?? null,
            'alt' => $attrs['alt'] ?? '',
            'className' => $imageClasses !== '' ? $imageClasses : null,
            'style' => $imageStyle,
            'title' => $attrs['title'] ?? null,
        ]);
        $children = [];
        if (!empty($attrs['href'])) {
            $children[] = new ElementNode('a', [
                'className' => $attrs['linkClass'] ?? null,
                'href' => $attrs['href'],
                'target' => $attrs['linkTarget'] ?? null,
                'rel' => !empty($attrs['rel']) ? $attrs['rel'] : null,
            ], [$image]);
        } else {
            $children[] = $image;
        }
        $caption = $attrs['caption'] ?? '';
        if (is_string($caption) && !$this->richTextEmpty($caption)) {
            $children[] = new ElementNode('figcaption', ['className' => 'wp-element-caption'], [
                new RawNode($caption),
            ]);
        }
        return new ElementNode('figure', $this->props('core/image', $attrs, [
            'className' => implode(' ', $figureClasses),
        ]), $children);
    }

    /** @param array<string,mixed> $attrs */
    private function separator(array $attrs): ElementNode
    {
        [$colorClasses, $colorStyles] = $this->colorProps($attrs);
        $background = $attrs['backgroundColor'] ?? null;
        $custom = $attrs['style']['color']['background'] ?? null;
        $classes = [];
        if ($background || $custom) {
            $classes[] = 'has-text-color';
        }
        if ($background) {
            $classes[] = 'has-' . SupportEngine::slug((string) $background) . '-color';
        }
        if (($attrs['opacity'] ?? 'alpha-channel') === 'css') {
            $classes[] = 'has-css-opacity';
        }
        if (($attrs['opacity'] ?? 'alpha-channel') === 'alpha-channel') {
            $classes[] = 'has-alpha-channel-opacity';
        }
        if ($colorClasses !== '') {
            $classes[] = $colorClasses;
        }
        $style = [
            'backgroundColor' => $colorStyles['backgroundColor'] ?? null,
            'color' => $background ? null : $custom,
        ];
        return new ElementNode((string) ($attrs['tagName'] ?? 'hr'), $this->props('core/separator', $attrs, [
            'className' => implode(' ', $classes), 'style' => $style,
        ]));
    }

    /** @param array<string,mixed> $attrs */
    private function buttons(array $attrs, string $inner): ElementNode
    {
        $customFont = !empty($attrs['fontSize']) || !empty($attrs['style']['typography']['fontSize']);
        return new ElementNode('div', $this->props('core/buttons', $attrs, [
            'className' => $customFont ? 'has-custom-font-size' : '',
        ]), [new RawNode($inner)]);
    }

    /** @param array<string,mixed> $attrs */
    private function button(array $attrs): ElementNode
    {
        [$borderClasses, $borderStyles] = $this->borderProps($attrs);
        [$colorClasses, $colorStyles] = $this->colorProps($attrs);
        $spacingStyles = $this->subsetStyles($attrs, ['spacing']);
        $shadowStyles = $this->shadowStyles($attrs);
        [$typographyClasses, $typographyStyles] = $this->typographyProps($attrs);
        $classes = array_filter([
            'wp-block-button__link', $colorClasses, $borderClasses, $typographyClasses,
            (($attrs['style']['border']['radius'] ?? null) === 0 ? 'no-border-radius' : ''),
            (!empty($attrs['fontSize']) || !empty($attrs['style']['typography']['fontSize'])) ? 'has-custom-font-size' : '',
            'wp-element-button',
        ]);
        $style = array_replace($borderStyles, $colorStyles, $spacingStyles, $shadowStyles, $typographyStyles);
        unset($style['writingMode']);
        $tag = (string) ($attrs['tagName'] ?? 'a');
        $button = new ElementNode($tag, [
            'type' => $tag === 'button' ? ($attrs['type'] ?? 'button') : null,
            'className' => implode(' ', $classes),
            'href' => $tag === 'button' ? null : ($attrs['url'] ?? null),
            'title' => $attrs['title'] ?? null,
            'style' => $style,
            'target' => $tag === 'button' ? null : ($attrs['linkTarget'] ?? null),
            'rel' => $tag === 'button' ? null : ($attrs['rel'] ?? null),
        ], [new RawNode((string) ($attrs['text'] ?? ''))]);
        return new ElementNode('div', $this->props('core/button', $attrs), [$button]);
    }

    /** @param array<string,mixed> $attrs */
    private function spacer(array $attrs): ElementNode
    {
        $stretch = $attrs['style']['layout']['selfStretch'] ?? null;
        $height = in_array($stretch, ['fill', 'fit'], true) ? null : ($attrs['height'] ?? '100px');
        return new ElementNode('div', $this->props('core/spacer', $attrs, [
            'style' => [
                'height' => $this->spacingPreset($height),
                'width' => $this->spacingPreset($attrs['width'] ?? null),
            ],
            'aria-hidden' => true,
        ]));
    }

    /** @param array<string,mixed> $attrs */
    private function list(array $attrs, string $inner): ElementNode
    {
        $ordered = !empty($attrs['ordered']);
        return new ElementNode($ordered ? 'ol' : 'ul', $this->props('core/list', $attrs, [
            'reversed' => $attrs['reversed'] ?? null,
            'start' => $attrs['start'] ?? null,
            'style' => ['listStyleType' => $ordered && ($attrs['type'] ?? 'decimal') !== 'decimal' ? $attrs['type'] : null],
        ]), [new RawNode($inner)]);
    }

    /** @param array<string,mixed> $attrs */
    private function listItem(array $attrs, string $inner): ElementNode
    {
        return new ElementNode('li', $this->props('core/list-item', $attrs), [
            new RawNode((string) ($attrs['content'] ?? '')), new RawNode($inner),
        ]);
    }

    /** @param array<string,mixed> $attrs */
    private function cover(array $attrs, string $inner): ElementNode
    {
        $type = $attrs['backgroundType'] ?? 'image';
        $url = $attrs['url'] ?? null;
        $focal = $attrs['focalPoint'] ?? null;
        $parallax = !empty($attrs['hasParallax']);
        $repeated = !empty($attrs['isRepeated']);
        $isImg = !$parallax && !$repeated;
        $position = is_array($focal) ? $this->mediaPosition($focal) : null;
        $contentPosition = $attrs['contentPosition'] ?? null;
        $positionClasses = [
            'top left' => 'is-position-top-left', 'top center' => 'is-position-top-center',
            'top right' => 'is-position-top-right', 'center left' => 'is-position-center-left',
            'center center' => 'is-position-center-center', 'center' => 'is-position-center-center',
            'center right' => 'is-position-center-right', 'bottom left' => 'is-position-bottom-left',
            'bottom center' => 'is-position-bottom-center', 'bottom right' => 'is-position-bottom-right',
        ];
        $center = $contentPosition === null || in_array($contentPosition, ['center', 'center center'], true);
        $classes = [];
        if (($attrs['isDark'] ?? true) === false) {
            $classes[] = 'is-light';
        }
        if ($parallax) {
            $classes[] = 'has-parallax';
        }
        if ($repeated) {
            $classes[] = 'is-repeated';
        }
        if (!$center) {
            $classes[] = 'has-custom-content-position';
            if (isset($positionClasses[$contentPosition])) {
                $classes[] = $positionClasses[$contentPosition];
            }
        }
        $minHeight = $attrs['minHeight'] ?? null;
        if ($minHeight && !empty($attrs['minHeightUnit'])) {
            $minHeight .= $attrs['minHeightUnit'];
        }
        $children = [];
        $imgClasses = array_filter([
            'wp-block-cover__image-background',
            !empty($attrs['id']) ? 'wp-image-' . $attrs['id'] : '',
            !empty($attrs['sizeSlug']) ? 'size-' . $attrs['sizeSlug'] : '',
            $parallax ? 'has-parallax' : '', $repeated ? 'is-repeated' : '',
        ]);
        if (empty($attrs['useFeaturedImage']) && $type === 'image' && $url) {
            if ($isImg) {
                $children[] = new ElementNode('img', [
                    'className' => implode(' ', $imgClasses), 'alt' => $attrs['alt'] ?? '',
                    'src' => $url, 'style' => ['objectPosition' => $position],
                    'data-object-fit' => 'cover', 'data-object-position' => $position,
                ]);
            } else {
                $children[] = new ElementNode('div', [
                    'role' => !empty($attrs['alt']) ? 'img' : null,
                    'aria-label' => !empty($attrs['alt']) ? $attrs['alt'] : null,
                    'className' => implode(' ', $imgClasses),
                    'style' => [
                        'backgroundPosition' => $position ?? '50% 50%',
                        'backgroundImage' => 'url(' . $url . ')',
                    ],
                ]);
            }
        }
        if ($type === 'video' && $url) {
            $children[] = new ElementNode('video', [
                'className' => 'wp-block-cover__video-background intrinsic-ignore',
                'autoPlay' => true, 'muted' => true, 'loop' => true, 'playsInline' => true,
                'src' => $url, 'poster' => $attrs['poster'] ?? null,
                'style' => ['objectPosition' => $position],
                'data-object-fit' => 'cover', 'data-object-position' => $position,
            ]);
        }
        if ($type === 'embed-video' && $url) {
            $children[] = new ElementNode('figure', ['className' => 'wp-block-cover__video-background wp-block-cover__embed-background wp-block-embed'], [
                new ElementNode('div', ['className' => 'wp-block-embed__wrapper'], [new TextNode((string) $url)]),
            ]);
        }
        $overlayColor = $attrs['overlayColor'] ?? null;
        $overlayClass = $overlayColor ? 'has-' . SupportEngine::slug((string) $overlayColor) . '-background-color' : '';
        $gradient = $attrs['gradient'] ?? null;
        $customGradient = $attrs['customGradient'] ?? null;
        $gradientClass = $gradient ? 'has-' . SupportEngine::slug((string) $gradient) . '-gradient-background' : '';
        $dim = $attrs['dimRatio'] ?? 100;
        $dimClass = ($dim === null || (is_numeric($dim) && (float) $dim === 50.0))
            ? ''
            : 'has-background-dim-' . (10 * round(((float) $dim) / 10));
        $gradientValue = $gradient ?: $customGradient;
        $overlayClasses = array_filter([
            'wp-block-cover__background', $overlayClass, $dimClass,
            $dim !== null ? 'has-background-dim' : '',
            ($url && $gradientValue && (!is_numeric($dim) || (float) $dim !== 0.0))
                ? 'wp-block-cover__gradient-background'
                : '',
            $gradientValue ? 'has-background-gradient' : '', $gradientClass,
        ]);
        $children[] = new ElementNode('span', [
            'aria-hidden' => true, 'className' => implode(' ', $overlayClasses),
            'style' => [
                'backgroundColor' => $overlayClass === '' ? ($attrs['customOverlayColor'] ?? null) : null,
                'background' => $customGradient ?: null,
            ],
        ]);
        $children[] = new ElementNode('div', ['className' => 'wp-block-cover__inner-container'], [new RawNode($inner)]);
        return new ElementNode((string) ($attrs['tagName'] ?? 'div'), $this->props('core/cover', $attrs, [
            'className' => implode(' ', $classes), 'style' => ['minHeight' => $minHeight ?: null],
        ]), $children);
    }

    /** @param array<string,mixed> $attrs */
    private function mediaText(array $attrs, string $inner): ElementNode
    {
        $position = $attrs['mediaPosition'] ?? 'left';
        $mediaType = $attrs['mediaType'] ?? null;
        $mediaUrl = $attrs['mediaUrl'] ?? null;
        $classes = array_filter([
            $position === 'right' ? 'has-media-on-the-right' : '',
            ($attrs['isStackedOnMobile'] ?? true) ? 'is-stacked-on-mobile' : '',
            !empty($attrs['verticalAlignment']) ? 'is-vertically-aligned-' . $attrs['verticalAlignment'] : '',
            !empty($attrs['imageFill']) ? 'is-image-fill-element' : '',
        ]);
        $width = $attrs['mediaWidth'] ?? 50;
        $grid = $width != 50 ? ($position === 'right' ? 'auto ' . $this->number($width) . '%' : $this->number($width) . '% auto') : null;
        $media = null;
        if ($mediaType === 'image' && $mediaUrl) {
            $imageClasses = array_filter([
                !empty($attrs['mediaId']) ? 'wp-image-' . $attrs['mediaId'] : '',
                !empty($attrs['mediaId']) ? 'size-' . ($attrs['mediaSizeSlug'] ?? 'full') : '',
            ]);
            $image = new ElementNode('img', [
                'src' => $mediaUrl, 'alt' => $attrs['mediaAlt'] ?? '',
                'className' => $imageClasses !== [] ? implode(' ', $imageClasses) : null,
                'style' => !empty($attrs['imageFill']) ? ['objectPosition' => $this->mediaPosition($attrs['focalPoint'] ?? ['x' => .5, 'y' => .5])] : [],
            ]);
            $media = !empty($attrs['href']) ? new ElementNode('a', [
                'className' => $attrs['linkClass'] ?? null, 'href' => $attrs['href'],
                'target' => $attrs['linkTarget'] ?? null, 'rel' => !empty($attrs['rel']) ? $attrs['rel'] : null,
            ], [$image]) : $image;
        } elseif ($mediaType === 'video') {
            $media = new ElementNode('video', ['controls' => true, 'src' => $mediaUrl]);
        }
        $figure = new ElementNode('figure', ['className' => 'wp-block-media-text__media'], $media ? [$media] : []);
        $content = new ElementNode('div', ['className' => 'wp-block-media-text__content'], [new RawNode($inner)]);
        $children = $position === 'right' ? [$content, $figure] : [$figure, $content];
        return new ElementNode('div', $this->props('core/media-text', $attrs, [
            'className' => implode(' ', $classes), 'style' => ['gridTemplateColumns' => $grid],
        ]), $children);
    }

    /** @param array<string,mixed> $attrs */
    private function quote(array $attrs, string $inner): ElementNode
    {
        $children = [new RawNode($inner)];
        if (!$this->richTextEmpty($attrs['citation'] ?? '')) {
            $children[] = new ElementNode('cite', [], [new RawNode((string) $attrs['citation'])]);
        }
        return new ElementNode('blockquote', $this->props('core/quote', $attrs, [
            'className' => !empty($attrs['textAlign']) ? 'has-text-align-' . $attrs['textAlign'] : '',
        ]), $children);
    }

    /** @param array<string,mixed> $attrs */
    private function pullquote(array $attrs): ElementNode
    {
        $quote = [new ElementNode('p', [], [new RawNode((string) ($attrs['value'] ?? ''))])];
        if (!$this->richTextEmpty($attrs['citation'] ?? '')) {
            $quote[] = new ElementNode('cite', [], [new RawNode((string) $attrs['citation'])]);
        }
        return new ElementNode('figure', $this->props('core/pullquote', $attrs, [
            'className' => !empty($attrs['textAlign']) ? 'has-text-align-' . $attrs['textAlign'] : '',
        ]), [new ElementNode('blockquote', [], $quote)]);
    }

    /** @param array<string,mixed> $attrs */
    private function gallery(array $attrs, string $inner): ElementNode
    {
        $classes = ['has-nested-images'];
        $classes[] = array_key_exists('columns', $attrs) ? 'columns-' . $attrs['columns'] : 'columns-default';
        if (($attrs['imageCrop'] ?? true)) {
            $classes[] = 'is-cropped';
        }
        $children = [new RawNode($inner)];
        if (!$this->richTextEmpty($attrs['caption'] ?? '')) {
            $children[] = new ElementNode('figcaption', ['className' => 'blocks-gallery-caption wp-element-caption'], [
                new RawNode((string) $attrs['caption']),
            ]);
        }
        return new ElementNode('figure', $this->props('core/gallery', $attrs, ['className' => implode(' ', $classes)]), $children);
    }

    /** @param array<string,mixed> $attrs */
    private function table(array $attrs): ?ElementNode
    {
        $head = is_array($attrs['head'] ?? null) ? $attrs['head'] : [];
        $body = is_array($attrs['body'] ?? null) ? $attrs['body'] : [];
        $foot = is_array($attrs['foot'] ?? null) ? $attrs['foot'] : [];
        if ($head === [] && $body === [] && $foot === []) {
            return null;
        }
        [$colorClasses, $colorStyles] = $this->colorProps($attrs);
        [$borderClasses, $borderStyles] = $this->borderProps($attrs);
        $tableClasses = trim($colorClasses . ' ' . $borderClasses . (($attrs['hasFixedLayout'] ?? true) ? ' has-fixed-layout' : ''));
        $className = trim((string) ($attrs['className'] ?? ''));
        if ($className !== '' && str_contains($className, 'island-bare-table')) {
            foreach (preg_split('/\s+/', $className, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                if ($class !== 'wp-block-table' && $class !== 'island-bare-table') {
                    $tableClasses = trim($tableClasses . ' ' . $class);
                }
            }
        }
        $sections = [];
        foreach ([['head', $head], ['body', $body], ['foot', $foot]] as [$type, $rows]) {
            if ($rows === []) {
                continue;
            }
            $rowNodes = [];
            foreach ($rows as $row) {
                $cells = [];
                foreach (($row['cells'] ?? []) as $cell) {
                    $align = $cell['align'] ?? null;
                    $cells[] = new ElementNode((string) ($cell['tag'] ?? 'td'), [
                        'className' => $align ? 'has-text-align-' . $align : null,
                        'data-align' => $align, 'scope' => ($cell['tag'] ?? 'td') === 'th' ? ($cell['scope'] ?? null) : null,
                        'colSpan' => $cell['colspan'] ?? null, 'rowSpan' => $cell['rowspan'] ?? null,
                    ], [new RawNode((string) ($cell['content'] ?? ''))]);
                }
                $rowNodes[] = new ElementNode('tr', [], $cells);
            }
            $sections[] = new ElementNode('t' . $type, [], $rowNodes);
        }
        $children = [new ElementNode('table', [
            'className' => $tableClasses !== '' ? $tableClasses : null,
            'style' => array_replace($colorStyles, $borderStyles),
        ], $sections)];
        if (!$this->richTextEmpty($attrs['caption'] ?? '')) {
            $children[] = new ElementNode('figcaption', ['className' => 'wp-element-caption'], [new RawNode((string) $attrs['caption'])]);
        }
        return new ElementNode('figure', $this->props('core/table', $attrs), $children);
    }

    /** @param array<string,mixed> $attrs */
    private function embed(array $attrs): ?ElementNode
    {
        if (empty($attrs['url'])) {
            return null;
        }
        $classes = ['wp-block-embed'];
        if (!empty($attrs['type'])) {
            $classes[] = 'is-type-' . $attrs['type'];
        }
        if (!empty($attrs['providerNameSlug'])) {
            $classes[] = 'is-provider-' . $attrs['providerNameSlug'];
            $classes[] = 'wp-block-embed-' . $attrs['providerNameSlug'];
        }
        $children = [new ElementNode('div', ['className' => 'wp-block-embed__wrapper'], [new TextNode("\n" . $attrs['url'] . "\n")])];
        if (!$this->richTextEmpty($attrs['caption'] ?? '')) {
            $children[] = new ElementNode('figcaption', ['className' => 'wp-element-caption'], [new RawNode((string) $attrs['caption'])]);
        }
        return new ElementNode('figure', $this->props('core/embed', $attrs, ['className' => implode(' ', $classes)]), $children);
    }

    /** @param array<string,mixed> $attrs */
    private function socialLinks(array $attrs, string $inner): ElementNode
    {
        $classes = array_filter([
            $attrs['size'] ?? '', !empty($attrs['showLabels']) ? 'has-visible-labels' : '',
            !empty($attrs['iconColorValue']) ? 'has-icon-color' : '',
            !empty($attrs['iconBackgroundColorValue']) ? 'has-icon-background-color' : '',
        ]);
        return new ElementNode('ul', $this->props('core/social-links', $attrs, ['className' => implode(' ', $classes)]), [new RawNode($inner)]);
    }

    /** @param array<string,mixed> $attrs @param array<string,mixed> $initial @return array<string,mixed> */
    private function props(string $name, array $attrs, array $initial = []): array
    {
        $supports = $this->registry->block($name)['supports'] ?? [];
        return $this->supports->apply($name, $attrs, is_array($supports) ? $supports : [], $initial)->all();
    }

    /** @param array<string,mixed> $attrs @return array{0:string,1:array<string,string|int|float>} */
    private function borderProps(array $attrs): array
    {
        $style = is_array($attrs['style']['border'] ?? null) ? ['border' => $attrs['style']['border']] : [];
        $classes = [];
        if (!empty($attrs['borderColor']) || !empty($style['border']['color'])) {
            $classes[] = 'has-border-color';
        }
        if (!empty($attrs['borderColor'])) {
            $classes[] = 'has-' . SupportEngine::slug((string) $attrs['borderColor']) . '-border-color';
        }
        return [implode(' ', $classes), $this->styles->declarations($style)];
    }

    /** @param array<string,mixed> $attrs @return array{0:string,1:array<string,string|int|float>} */
    private function colorProps(array $attrs): array
    {
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
        $text = $attrs['textColor'] ?? null;
        $background = $attrs['backgroundColor'] ?? null;
        $gradient = $attrs['gradient'] ?? null;
        $customGradient = $style['color']['gradient'] ?? null;
        $classes = [];
        if ($text) {
            $classes[] = 'has-' . SupportEngine::slug((string) $text) . '-color';
        }
        if ($gradient) {
            $classes[] = 'has-' . SupportEngine::slug((string) $gradient) . '-gradient-background';
        }
        if ($background && !$customGradient) {
            $classes[] = 'has-' . SupportEngine::slug((string) $background) . '-background-color';
        }
        if ($text || !empty($style['color']['text'])) {
            $classes[] = 'has-text-color';
        }
        if ($background || !empty($style['color']['background']) || $gradient || $customGradient) {
            $classes[] = 'has-background';
        }
        if (!empty($style['elements']['link']['color'])) {
            $classes[] = 'has-link-color';
        }
        return [implode(' ', $classes), $this->styles->declarations(['color' => $style['color'] ?? []])];
    }

    /** @param array<string,mixed> $attrs @return array{0:string,1:array<string,string|int|float>} */
    private function typographyProps(array $attrs): array
    {
        $classes = [];
        if (!empty($attrs['fontFamily'])) {
            $classes[] = 'has-' . SupportEngine::slug((string) $attrs['fontFamily']) . '-font-family';
        }
        $textAlign = $attrs['style']['typography']['textAlign'] ?? null;
        if (is_string($textAlign) && in_array($textAlign, ['left', 'center', 'right'], true)) {
            $classes[] = 'has-text-align-' . $textAlign;
        }
        if (!empty($attrs['fontSize'])) {
            $classes[] = 'has-' . SupportEngine::slug((string) $attrs['fontSize']) . '-font-size';
        }
        $style = ['typography' => is_array($attrs['style']['typography'] ?? null) ? $attrs['style']['typography'] : []];
        return [implode(' ', $classes), $this->styles->declarations($style)];
    }

    /** @param array<string,mixed> $attrs @param list<string> $keys @return array<string,string|int|float> */
    private function subsetStyles(array $attrs, array $keys): array
    {
        $style = [];
        foreach ($keys as $key) {
            if (is_array($attrs['style'][$key] ?? null)) {
                $style[$key] = $attrs['style'][$key];
            }
        }
        return $this->styles->declarations($style);
    }

    /** @param array<string,mixed> $attrs @return array<string,string|int|float> */
    private function shadowStyles(array $attrs): array
    {
        return isset($attrs['style']['shadow']) ? $this->styles->declarations(['shadow' => $attrs['style']['shadow']]) : [];
    }

    private function spacingPreset(mixed $value): mixed
    {
        if (!is_string($value) || !str_starts_with($value, 'var:preset|spacing|')) {
            return $value;
        }
        return 'var(--wp--preset--spacing--' . SupportEngine::slug(substr($value, strlen('var:preset|spacing|'))) . ')';
    }

    /** @param array<string,mixed> $point */
    private function mediaPosition(array $point): string
    {
        return round(((float) ($point['x'] ?? .5)) * 100) . '% ' . round(((float) ($point['y'] ?? .5)) * 100) . '%';
    }

    private function richTextEmpty(mixed $value): bool
    {
        return trim(strip_tags((string) $value)) === '';
    }

    private function number(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return JsJsonEncoder::stringifyNumber($value);
        }
        return (string) $value;
    }
}
