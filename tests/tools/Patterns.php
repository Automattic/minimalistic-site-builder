<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tools;

/**
 * Section patterns as spec templates. The model picks a pattern and fills
 * its parameters; each method here turns that into a block spec tree in the
 * idiom of the dapper-garden theme (its preset slugs, its custom classes,
 * its ornament above section headings).
 *
 * A spec is {name, attrs, innerBlocks}; see SpecRenderer.
 */
final class Patterns
{
    public const ALL = [
        'full-bleed-cover', 'centered-stack', 'offset-grid', 'equal-card-grid',
        'asymmetric-split', 'mixed-width-editorial', 'list-with-thumbnails',
    ];

    private const ORNAMENT = 'olive-sprig-ornament.png';

    /** @var array{slug:string,theme:string} */
    private array $ctx;

    /** @param array{slug:string,theme:string,first?:bool} $ctx */
    private function __construct(array $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * @param array<string,mixed> $params
     * @param array{slug:string,theme:string,first?:bool} $ctx
     * @return array<string,mixed>
     */
    public static function spec(string $pattern, array $params, array $ctx): array
    {
        if (!in_array($pattern, self::ALL, true)) {
            throw new \RuntimeException("unknown pattern '$pattern'");
        }
        $method = lcfirst(str_replace('-', '', ucwords($pattern, '-')));
        return (new self($ctx))->$method($params);
    }

    /** JSON schema for the model's answer. @param list<string> $assets @return array<string,mixed> */
    public static function schema(array $assets): array
    {
        $image = ['type' => 'object', 'properties' => [
            'asset' => ['type' => 'string', 'enum' => array_values(array_diff($assets, [self::ORNAMENT]))],
            'alt' => ['type' => 'string'],
        ], 'required' => ['asset', 'alt'], 'additionalProperties' => false];
        $cta = ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string'], 'url' => ['type' => 'string'],
        ], 'required' => ['text', 'url'], 'additionalProperties' => false];
        $item = ['type' => 'object', 'properties' => [
            'title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'meta' => ['type' => 'string'],
            'image' => $image, 'cta' => $cta,
        ], 'required' => ['title', 'body'], 'additionalProperties' => false];
        return ['type' => 'object', 'properties' => [
            'pattern' => ['type' => 'string', 'enum' => self::ALL],
            'params' => ['type' => 'object', 'properties' => [
                'background' => ['type' => 'string', 'enum' => ['base', 'tinted', 'contrast', 'image']],
                'eyebrow' => ['type' => 'string'],
                'heading' => ['type' => 'string'],
                'lead' => ['type' => 'string'],
                'paragraphs' => ['type' => 'array', 'items' => ['type' => 'string']],
                'image' => $image,
                'items' => ['type' => 'array', 'items' => $item],
                'table' => ['type' => 'object', 'properties' => [
                    'header' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'rows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ], 'required' => ['rows'], 'additionalProperties' => false],
                'cta' => $cta,
                'secondary_cta' => $cta,
            ], 'required' => ['heading'], 'additionalProperties' => false],
        ], 'required' => ['pattern', 'params'], 'additionalProperties' => false];
    }

    // ---- patterns -------------------------------------------------------

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function fullBleedCover(array $p): array
    {
        $buttons = array_values(array_filter([
            isset($p['cta']) ? $this->button($p['cta'], false) : null,
            isset($p['secondary_cta']) ? $this->button($p['secondary_cta'], true) : null,
        ]));
        $inner = [$this->ornament(96)];
        $inner[] = $this->heading($p['heading'], 1, ['textColor' => 'base', 'fontFamily' => 'heading', 'fontSize' => 'display',
            'style' => ['spacing' => ['margin' => ['top' => $this->sp('sm')]]]]);
        if (!empty($p['lead'])) {
            $inner[] = $this->para($p['lead'], ['textColor' => 'base', 'fontSize' => 'lead',
                'style' => ['spacing' => ['margin' => ['top' => $this->sp('md')]]]]);
        }
        if ($buttons) {
            $inner[] = $this->block('core/buttons', ['style' => ['spacing' => ['margin' => ['top' => $this->sp('lg')]]]], $buttons);
        }
        $cover = $this->block('core/cover', [
            'align' => 'full',
            'url' => $this->asset($p['image']['asset'] ?? 'hero-sunlit-studio-circle.jpg'),
            'alt' => $p['image']['alt'] ?? '',
            'dimRatio' => 50, 'overlayColor' => 'contrast', 'minHeight' => 90, 'minHeightUnit' => 'vh',
            'contentPosition' => 'bottom left',
            'style' => ['spacing' => ['padding' => ['top' => $this->sp('xxl'), 'bottom' => $this->sp('xxl')]]],
        ], [$this->block('core/group', ['layout' => ['type' => 'constrained', 'contentSize' => '720px', 'justifyContent' => 'left']], $inner)]);
        return $this->block('core/group', ['anchor' => $this->ctx['slug'], 'align' => 'full',
            'style' => ['spacing' => ['margin' => ['top' => '0']]], 'layout' => ['type' => 'constrained']], [$cover]);
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function centeredStack(array $p): array
    {
        $bg = $p['background'] ?? 'base';
        $children = [$this->intro($p, $bg, '720px'), $this->spacer('lg')];
        if (!empty($p['paragraphs'])) {
            $children[] = $this->block('core/group', ['layout' => ['type' => 'constrained', 'contentSize' => '680px'],
                'style' => ['spacing' => ['blockGap' => $this->sp('md')]]],
                array_map(fn ($t) => $this->para($t, $this->textAttrs($bg)), $p['paragraphs']));
        }
        if (!empty($p['table']['rows'])) {
            $children[] = $this->card($bg, [$this->table($p['table'])], 'wide');
        }
        if (!empty($p['items'])) {
            $children[] = $this->card($bg, array_map(fn ($it) => $this->listRow($it, 'base'), $p['items']), 'wide');
        }
        $children = [...$children, ...$this->ctaRow($p, $bg)];
        return $this->band($bg, $children);
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function offsetGrid(array $p): array
    {
        $bg = $p['background'] ?? 'base';
        $cols = [[], []];
        foreach (array_values($p['items'] ?? []) as $i => $it) {
            $cols[$i % 2][] = $this->textCard($it, $bg);
        }
        $columns = $this->block('core/columns', ['align' => 'wide', 'style' => ['spacing' => ['blockGap' => ['top' => $this->sp('lg'), 'left' => $this->sp('lg')]]]], [
            $this->block('core/column', ['width' => '50%', 'style' => ['spacing' => ['blockGap' => $this->sp('lg')]]], $cols[0]),
            $this->block('core/column', ['width' => '50%', 'style' => ['spacing' => ['blockGap' => $this->sp('lg'), 'padding' => ['top' => $this->sp('xl')]]]], $cols[1]),
        ]);
        return $this->band($bg, [$this->intro($p, $bg, '680px'), $this->spacer('lg'), $columns, ...$this->ctaRow($p, $bg)]);
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function equalCardGrid(array $p): array
    {
        $bg = $p['background'] ?? 'base';
        $rows = [];
        foreach (array_chunk(array_values($p['items'] ?? []), 3) as $chunk) {
            $rows[] = $this->block('core/columns', ['align' => 'wide', 'className' => 'equal-cards',
                'style' => ['spacing' => ['blockGap' => ['top' => $this->sp('lg'), 'left' => $this->sp('lg')]]]],
                array_map(fn ($it) => $this->block('core/column', [], [$this->mediaCard($it, $bg)]), $chunk));
        }
        return $this->band($bg, [$this->intro($p, $bg, '720px'), $this->spacer('lg'), ...$rows, ...$this->ctaRow($p, $bg)]);
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function asymmetricSplit(array $p): array
    {
        $bg = $p['background'] ?? 'contrast';
        $side = [$this->ornament(64, 'left')];
        if (!empty($p['eyebrow'])) { $side[] = $this->eyebrow($p['eyebrow'], $bg, 'left'); }
        $side[] = $this->heading($p['heading'], 2, ['fontFamily' => 'heading', 'fontSize' => 'section-title'] + $this->textAttrs($bg));
        if (!empty($p['lead'])) { $side[] = $this->para($p['lead'], ['fontSize' => 'lead'] + $this->textAttrs($bg)); }
        $buttons = array_values(array_filter([isset($p['cta']) ? $this->button($p['cta'], false) : null]));
        if ($buttons) { $side[] = $this->block('core/buttons', ['style' => ['spacing' => ['margin' => ['top' => $this->sp('md')]]]], $buttons); }
        $main = array_map(fn ($it) => $this->textCard($it, $bg, true), array_values($p['items'] ?? []));
        $columns = $this->block('core/columns', ['verticalAlignment' => 'top', 'align' => 'wide',
            'style' => ['spacing' => ['blockGap' => ['top' => $this->sp('xl'), 'left' => $this->sp('xl')]]]], [
            $this->block('core/column', ['verticalAlignment' => 'top', 'width' => '38%'], [
                $this->block('core/group', ['className' => 'sticky-side', 'layout' => ['type' => 'constrained', 'contentSize' => '420px', 'justifyContent' => 'left'],
                    'style' => ['spacing' => ['blockGap' => $this->sp('sm')]]], $side),
            ]),
            $this->block('core/column', ['verticalAlignment' => 'top', 'width' => '62%', 'style' => ['spacing' => ['blockGap' => $this->sp('lg')]]], $main),
        ]);
        return $this->band($bg, [$columns], true);
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function mixedWidthEditorial(array $p): array
    {
        $bg = $p['background'] ?? 'base';
        $rows = [];
        foreach (array_values($p['items'] ?? []) as $i => $it) {
            $body = [$this->heading($it['title'], 3, ['fontFamily' => 'heading', 'fontSize' => 'heading'] + $this->textAttrs($bg))];
            if (!empty($it['meta'])) { $body[] = $this->eyebrow($it['meta'], $bg, 'left'); }
            $body[] = $this->para($it['body'], $this->textAttrs($bg));
            $rows[] = $this->block('core/media-text', [
                'align' => 'wide', 'mediaPosition' => $i % 2 ? 'right' : 'left', 'mediaType' => 'image',
                'mediaUrl' => $this->asset($it['image']['asset'] ?? 'studio-detail-props-window.jpg'),
                'mediaAlt' => $it['image']['alt'] ?? '', 'mediaWidth' => 40, 'verticalAlignment' => 'center',
                'imageFill' => false, 'className' => 'card-media',
                'style' => ['spacing' => ['margin' => ['bottom' => $this->sp('xl')]]],
            ], [$this->block('core/group', ['layout' => ['type' => 'constrained', 'contentSize' => '520px', 'justifyContent' => 'left'],
                'style' => ['spacing' => ['blockGap' => $this->sp('sm')]]], $body)]);
        }
        return $this->band($bg, [$this->intro($p, $bg, '720px'), $this->spacer('xl'), ...$rows, ...$this->ctaRow($p, $bg)]);
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function listWithThumbnails(array $p): array
    {
        $bg = $p['background'] ?? 'tinted';
        $rows = [];
        foreach (array_values($p['items'] ?? []) as $i => $it) {
            if ($i > 0) { $rows[] = $this->block('core/separator', ['className' => 'is-style-wide', 'backgroundColor' => 'secondary', 'style' => ['spacing' => ['margin' => ['top' => $this->sp('md'), 'bottom' => $this->sp('md')]]]]); }
            $rows[] = $this->listRow($it, 'base');
        }
        return $this->band($bg, [$this->intro($p, $bg, '640px'), $this->card($bg, $rows, 'wide', 'lg'), ...$this->ctaRow($p, $bg)], true);
    }

    // ---- building blocks -----------------------------------------------

    /** @param list<array<string,mixed>> $children @return array<string,mixed> */
    private function band(string $bg, array $children, bool $sidePadding = false): array
    {
        $padding = ['top' => $this->sp('xxl'), 'bottom' => $this->sp('xxl')];
        if ($sidePadding || $bg !== 'base') { $padding += ['left' => $this->sp('md'), 'right' => $this->sp('md')]; }
        $attrs = ['anchor' => $this->ctx['slug'], 'align' => 'full',
            'style' => ['spacing' => ['margin' => ['top' => '0'], 'padding' => $padding]], 'layout' => ['type' => 'constrained']];
        return $this->block('core/group', $this->bandColors($bg) + $attrs, $children);
    }

    /** @return array<string,string> */
    private function bandColors(string $bg): array
    {
        return match ($bg) {
            'tinted' => ['backgroundColor' => 'secondary', 'textColor' => 'base'],
            'contrast' => ['backgroundColor' => 'contrast', 'textColor' => 'base'],
            default => [],
        };
    }

    /** Colors for body text sitting directly on a band. @return array<string,string> */
    private function textAttrs(string $bg): array
    {
        return $bg === 'base' ? [] : ['textColor' => 'base'];
    }

    /** Ornament, eyebrow, heading, lead — centered. @param array<string,mixed> $p @return array<string,mixed> */
    private function intro(array $p, string $bg, string $width): array
    {
        $children = [$this->ornament(64)];
        if (!empty($p['eyebrow'])) { $children[] = $this->eyebrow($p['eyebrow'], $bg); }
        $children[] = $this->heading($p['heading'], 2, ['fontFamily' => 'heading', 'fontSize' => 'section-title',
            'style' => ['typography' => ['textAlign' => 'center']]] + ($bg === 'base' ? ['textColor' => 'primary'] : $this->textAttrs($bg)));
        if (!empty($p['lead'])) {
            $lead = ['align' => 'center', 'fontSize' => 'lead', 'style' => ['typography' => ['textAlign' => 'center']]] + $this->textAttrs($bg);
            if ($bg !== 'base') { $lead['className'] = 'has-base-color has-text-color'; }
            $children[] = $this->para($p['lead'], $lead);
        }
        return $this->block('core/group', ['style' => ['spacing' => ['blockGap' => $this->sp('md')]],
            'layout' => ['type' => 'constrained', 'contentSize' => $width]], $children);
    }

    /** @return array<string,mixed> */
    private function eyebrow(string $text, string $bg, string $align = 'center'): array
    {
        $color = $bg === 'base' ? 'secondary' : 'base';
        $attrs = ['textColor' => $color, 'fontSize' => 'caption', 'style' => ['typography' => ['textTransform' => 'uppercase', 'letterSpacing' => '0.18em']]];
        if ($align === 'center') {
            // The theme's own markup mirrors the color classes on centered
            // paragraphs, and the normalizer keeps it that way.
            $attrs += ['align' => 'center', 'className' => "has-$color-color has-text-color"];
            $attrs['style']['typography']['textAlign'] = 'center';
        }
        return $this->para($text, $attrs);
    }

    /** A bounded surface on a band: base card on a colored band, tinted card on base. @param list<array<string,mixed>> $children @return array<string,mixed> */
    private function card(string $bg, array $children, string $align = '', string $pad = 'lg'): array
    {
        $colors = $bg === 'base' ? ['backgroundColor' => 'base', 'textColor' => 'contrast'] : ['backgroundColor' => 'base', 'textColor' => 'contrast'];
        $attrs = $colors + ['className' => 'hover-lift', 'style' => ['border' => ['radius' => '12px', 'width' => '1px', 'color' => 'var:preset|color|secondary'],
            'spacing' => ['padding' => array_fill_keys(['top', 'bottom', 'left', 'right'], $this->sp($pad)), 'blockGap' => $this->sp('sm')]],
            'layout' => ['type' => 'constrained', 'justifyContent' => 'left']];
        if ($align !== '') { $attrs['align'] = $align; }
        return $this->block('core/group', $attrs, $children);
    }

    /** Text-only card: meta, title, body, optional button. @param array<string,mixed> $it @return array<string,mixed> */
    private function textCard(array $it, string $bg, bool $onDark = false): array
    {
        $children = [];
        if (!empty($it['meta'])) { $children[] = $this->para($it['meta'], ['textColor' => 'secondary', 'fontSize' => 'caption', 'style' => ['typography' => ['textTransform' => 'uppercase', 'letterSpacing' => '0.12em']]]); }
        $children[] = $this->heading($it['title'], 3, ['fontFamily' => 'heading', 'fontSize' => 'heading', 'textColor' => 'contrast']);
        $children[] = $this->para($it['body'], ['textColor' => 'contrast']);
        if (!empty($it['cta'])) { $children[] = $this->block('core/buttons', [], [$this->button($it['cta'], true)]); }
        return $this->card($bg, $children);
    }

    /** Card with an optional image on top. @param array<string,mixed> $it @return array<string,mixed> */
    private function mediaCard(array $it, string $bg): array
    {
        $children = [];
        if (!empty($it['image'])) {
            $children[] = $this->image($it['image'], ['sizeSlug' => 'large', 'className' => 'card-media-tall',
                'style' => ['border' => ['radius' => '12px'], 'spacing' => ['margin' => ['bottom' => $this->sp('sm')]]]]);
        }
        $children[] = $this->heading($it['title'], 3, ['fontFamily' => 'heading', 'fontSize' => 'heading', 'textColor' => 'contrast']);
        $children[] = $this->para($it['body'], ['textColor' => 'contrast']);
        if (!empty($it['meta'])) { $children[] = $this->para($it['meta'], ['textColor' => 'secondary', 'fontSize' => 'caption']); }
        if (!empty($it['cta'])) { $children[] = $this->block('core/buttons', ['className' => 'cta-bottom'], [$this->button($it['cta'], true)]); }
        return $this->card($bg, $children);
    }

    /** Thumbnail beside title and body. @param array<string,mixed> $it @return array<string,mixed> */
    private function listRow(array $it, string $bg): array
    {
        $text = [$this->heading($it['title'], 3, ['fontFamily' => 'heading', 'fontSize' => 'heading', 'textColor' => 'contrast'])];
        $text[] = $this->para($it['body'], ['textColor' => 'contrast']);
        if (!empty($it['meta'])) { $text[] = $this->para($it['meta'], ['textColor' => 'secondary', 'fontSize' => 'caption']); }
        if (!empty($it['cta'])) { $text[] = $this->block('core/buttons', [], [$this->button($it['cta'], true)]); }
        $cols = [];
        if (!empty($it['image'])) {
            $cols[] = $this->block('core/column', ['width' => '20%'], [$this->image($it['image'], ['sizeSlug' => 'medium', 'className' => 'card-media-thumb', 'style' => ['border' => ['radius' => '12px']]])]);
        }
        $cols[] = $this->block('core/column', ['width' => $cols ? '80%' : '100%', 'style' => ['spacing' => ['blockGap' => $this->sp('sm')]]], $text);
        return $this->block('core/columns', ['verticalAlignment' => 'center', 'style' => ['spacing' => ['blockGap' => ['left' => $this->sp('lg')]]]], $cols);
    }

    /** @param array<string,mixed> $p @return list<array<string,mixed>> */
    private function ctaRow(array $p, string $bg): array
    {
        $buttons = array_values(array_filter([
            isset($p['cta']) ? $this->button($p['cta'], $bg !== 'base') : null,
            isset($p['secondary_cta']) ? $this->button($p['secondary_cta'], true) : null,
        ]));
        if (!$buttons) { return []; }
        return [$this->spacer('lg'), $this->block('core/buttons', ['layout' => ['type' => 'flex', 'justifyContent' => 'center']], $buttons)];
    }

    /** @param array{text:string,url:string} $cta @return array<string,mixed> */
    private function button(array $cta, bool $outline): array
    {
        $attrs = ['text' => $cta['text'], 'url' => $cta['url']];
        if ($outline) { $attrs['className'] = 'is-style-outline'; }
        return $this->block('core/button', $attrs);
    }

    /** @param array{header?:list<string>,rows:list<list<string>>} $t @return array<string,mixed> */
    private function table(array $t): array
    {
        $cell = fn (string $c, string $tag) => ['content' => $c, 'tag' => $tag];
        $attrs = ['hasFixedLayout' => true, 'body' => array_map(fn ($r) => ['cells' => array_map(fn ($c) => $cell((string) $c, 'td'), $r)], $t['rows'])];
        if (!empty($t['header'])) { $attrs['head'] = [['cells' => array_map(fn ($c) => $cell((string) $c, 'th'), $t['header'])]]; }
        return $this->block('core/table', $attrs);
    }

    /** @param array{asset:string,alt?:string} $img @param array<string,mixed> $attrs @return array<string,mixed> */
    private function image(array $img, array $attrs): array
    {
        return $this->block('core/image', ['url' => $this->asset($img['asset']), 'alt' => $img['alt'] ?? ''] + $attrs);
    }

    /** @return array<string,mixed> */
    private function ornament(int $px, string $align = 'center'): array
    {
        $attrs = ['width' => "{$px}px", 'sizeSlug' => 'large', 'className' => 'is-resized'];
        if ($align === 'center') { $attrs['align'] = 'center'; }
        return $this->block('core/image', ['url' => $this->asset(self::ORNAMENT), 'alt' => ''] + $attrs);
    }

    /** @param array<string,mixed> $attrs @return array<string,mixed> */
    private function heading(string $text, int $level, array $attrs = []): array
    {
        return $this->block('core/heading', ['content' => $text, 'level' => $level] + $attrs);
    }

    /** @param array<string,mixed> $attrs @return array<string,mixed> */
    private function para(string $text, array $attrs = []): array
    {
        return $this->block('core/paragraph', ['content' => $text] + $attrs);
    }

    /** @return array<string,mixed> */
    private function spacer(string $slug): array
    {
        return $this->block('core/spacer', ['height' => $this->sp($slug)]);
    }

    private function sp(string $slug): string
    {
        return "var:preset|spacing|$slug";
    }

    private function asset(string $file): string
    {
        return "/wp-content/themes/{$this->ctx['theme']}/assets/$file";
    }

    /** @param array<string,mixed> $attrs @param list<array<string,mixed>> $children @return array<string,mixed> */
    private function block(string $name, array $attrs = [], array $children = []): array
    {
        return ['name' => $name, 'attrs' => $attrs, 'innerBlocks' => $children];
    }
}
