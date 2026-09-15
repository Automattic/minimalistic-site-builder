<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Compile one section content document (see SectionContent) into the block
 * markup SectionUnit delivers today: the same root markers, class hooks,
 * surfaces, and AI_IMAGE placeholders every later step reads.
 *
 * Project-free: the unit input carries the plan section, the card style, and
 * the design direction text, and the compiler reads the few facts it executes
 * from that text. Output is minified block grammar with the custom classes
 * the attribute-light contract keeps; the block fixer restores the rest.
 */
final class SectionContentCompiler
{
    /** @var array<string,int> filenames used by the section being compiled */
    private array $files = [];

    private function __construct(
        private readonly array $doc,
        private readonly array $section,
        private readonly string $archetype,
        private readonly string $background,
        private readonly ?string $itemPattern,
        private readonly string $placement,
        private readonly string $cardStyle,
        private readonly string $slug,
        private readonly string $pagePath,
        private readonly bool $opening,
        private readonly bool $framed,
        private readonly bool $emphasis,
        private readonly ?string $crop,
        private readonly string $direction,
        private readonly bool $highlight,
    ) {}

    /**
     * @param array<string,mixed> $doc   the decoded content document
     * @param array<string,mixed> $input the SectionUnit input
     */
    public static function compile(array $doc, array $input): string
    {
        $section = is_array($input['section'] ?? null) ? $input['section'] : [];
        $archetype = (string) ($section['layout_archetype'] ?? '');
        SectionComposition::assertKnown($archetype);
        $direction = (string) ($input['design_direction'] ?? '');
        $spec = $input['site_spec'] ?? [];
        if (is_string($spec)) {
            $spec = json_decode($spec, true);
        }
        $page = is_array($input['page'] ?? null) ? $input['page'] : [];
        $compiler = new self(
            doc: $doc,
            section: $section,
            archetype: $archetype,
            background: in_array($section['background'] ?? '', SectionComposition::BACKGROUNDS, true)
                ? (string) $section['background'] : 'base',
            itemPattern: ItemPattern::explicit($section['item_pattern'] ?? null),
            placement: (string) ($section['text_placement'] ?? 'left-column'),
            cardStyle: (string) ($input['card_style'] ?? 'flush'),
            slug: trim((string) ($section['slug'] ?? 'section')) ?: 'section',
            pagePath: (string) ($page['path'] ?? '/'),
            opening: (bool) ($input['is_opening'] ?? false) && (($section['role'] ?? '') !== 'hero'),
            framed: preg_match('/\*\*Canvas\*\*: framed\b/', $direction) === 1,
            emphasis: preg_match('/\*\*Heading emphasis\*\*: (?!none\b)\S/', $direction) === 1,
            crop: preg_match('/\*\*Image crop\*\*: ([a-z]+)/', $direction, $m) === 1 ? ImageCrop::explicit($m[1]) : null,
            direction: (is_array($spec) ? (string) ($spec['writing_direction'] ?? 'ltr') : 'ltr') === 'rtl' ? 'rtl' : 'ltr',
            highlight: SectionComposition::highlightAppliesTo(
                is_string($input['stated_highlight'] ?? null) ? $input['stated_highlight'] : null,
                $section,
            ),
        );
        return $compiler->root();
    }

    // ------------------------------------------------------------------
    // Root and surfaces

    private function root(): string
    {
        $classes = [SectionComposition::marker($this->archetype)];
        if ($this->itemPattern !== null) {
            $classes[] = ItemPattern::marker($this->itemPattern);
        }
        $attrs = [
            'anchor'    => $this->slug,
            'className' => implode(' ', $classes),
        ];
        $attrs += $this->surface($this->background === 'image' ? 'base' : $this->background);
        $attrs['style']['spacing']['margin'] = ['top' => '0', 'bottom' => '0'];
        $attrs['layout'] = ['type' => 'constrained'];

        $body = $this->{self::BUILDERS[$this->archetype]}();
        if ($this->background === 'image' && $this->archetype !== 'full-bleed-cover') {
            $body = $this->cover(null, $body);
        }
        $tag = ['id' => $this->slug];
        if (isset($attrs['backgroundColor'])) {
            $tag['class'] = 'has-' . $attrs['backgroundColor'] . '-background-color has-background';
        }
        return $this->wrap('group', $attrs, $body, 'div', $tag);
    }

    private const BUILDERS = [
        'asymmetric-split'      => 'asymmetricSplit',
        'bento-grid'            => 'bentoGrid',
        'cta-panel'             => 'ctaPanel',
        'equal-card-grid'       => 'equalCardGrid',
        'faq-split'             => 'faqSplit',
        'feature-row-hairlines' => 'featureRow',
        'full-bleed-cover'      => 'fullBleedCover',
        'logo-strip'            => 'logoStrip',
        'offset-grid'           => 'offsetGrid',
        'pricing-tiers'         => 'pricingTiers',
        'project-grid-2x2'      => 'projectGrid',
        'stat-ledger'           => 'statLedger',
        'zigzag-steps'          => 'zigzagSteps',
    ];

    /** Background and text attributes for a band or a panel surface. */
    private function surface(string $background): array
    {
        return match ($background) {
            'tinted'   => ['backgroundColor' => 'band', 'textColor' => 'contrast'],
            'contrast' => [
                'backgroundColor' => 'contrast',
                'textColor'       => 'base',
                'style'           => ['elements' => ['link' => [
                    'color'  => ['text' => 'var:preset|color|base'],
                    ':hover' => ['color' => ['text' => 'var:preset|color|accent']],
                ]]],
            ],
            default    => [],
        };
    }

    /** The card surface that reads against the current band. */
    private function cardSurface(): array
    {
        return $this->background === 'base'
            ? ['backgroundColor' => 'band', 'textColor' => 'contrast']
            : ['backgroundColor' => 'base', 'textColor' => 'contrast'];
    }

    private function inverted(): array
    {
        return $this->background === 'contrast'
            ? ['backgroundColor' => 'base', 'textColor' => 'contrast']
            : ['backgroundColor' => 'contrast', 'textColor' => 'base'];
    }

    // ------------------------------------------------------------------
    // Copy stack

    private function copyStack(bool $withAction = true, bool $withParagraphs = true): string
    {
        $inner = $this->heading()
            . $this->lead()
            . ($withParagraphs ? $this->paragraphs() : '')
            . ($withAction ? $this->action() : '')
            . $this->textLink();
        $attrs = ['layout' => ['type' => 'constrained']];
        if ($this->placement === 'centered') {
            return $this->wrap('group', $attrs, $inner);
        }
        $attrs = ['align' => 'wide'] + $attrs;
        if ($this->placement === 'asymmetric-thirds') {
            if (crc32($this->slug) % 2 === 1) {
                $attrs['className'] = 'copy-end';
            }
        } else {
            $attrs['className'] = 'copy-flush';
        }
        return $this->wrap('group', $attrs, $inner);
    }

    private function heading(?int $level = null, ?string $text = null, array $extra = []): string
    {
        $own = $text === null;
        $text = $own ? $this->str('heading') : trim($text);
        if ($text === '') {
            return '';
        }
        $attrs = ['level' => $level ?? ($this->opening ? 1 : 2)] + $extra;
        if ($own && $this->opening) {
            $attrs['fontSize'] = 'section-title';
        }
        if ($own && $this->placement === 'centered' && !isset($attrs['style'])) {
            $attrs['style'] = ['typography' => ['textAlign' => 'center']];
        }
        $emph = $own ? $this->str('heading_emphasis') : '';
        if ($this->emphasis && $emph !== '' && str_contains($text, $emph)) {
            $text = str_replace($emph, '<span class="emph">' . $emph . '</span>', $text);
        }
        return $this->wrap('heading', $attrs, $this->inline($text), 'h' . $attrs['level']);
    }

    private function lead(array $extra = []): string
    {
        $lead = $this->str('lead');
        if ($lead === '') {
            return '';
        }
        $attrs = ['fontSize' => 'lead'] + $extra;
        if ($this->placement === 'centered' && $extra === []) {
            $attrs['align'] = 'center';
        }
        return $this->paragraph($lead, $attrs);
    }

    private function paragraphs(): string
    {
        $out = '';
        foreach ($this->list('paragraphs') as $text) {
            $out .= $this->paragraph($text, $this->placement === 'centered' ? ['align' => $this->start()] : []);
        }
        return $out;
    }

    private function start(): string
    {
        return $this->direction === 'rtl' ? 'right' : 'left';
    }

    /** The planned primary action as one button, or nothing. */
    private function action(array $extra = [], bool $center = false): string
    {
        $planned = $this->section['primary_action'] ?? null;
        if (!is_array($planned)) {
            return '';
        }
        $label = $this->str('action_label') ?: trim((string) ($planned['label'] ?? ''));
        $href = $this->href((string) ($planned['destination'] ?? ''));
        if ($label === '' || $href === null) {
            return '';
        }
        $attrs = $extra;
        if ($center) {
            $attrs['layout'] = ['type' => 'flex', 'justifyContent' => 'center'];
        }
        return $this->wrap('buttons', $attrs, $this->wrap(
            'button',
            [],
            '<a href="' . self::h($href) . '">' . self::h($label) . '</a>',
        ));
    }

    private function textLink(string $label = '', string $href = '', string $class = 'text-action'): string
    {
        $label = $label !== '' ? $label : $this->str('link_label');
        $href = $this->href($href !== '' ? $href : $this->str('link_href'));
        if ($label === '' || $href === null) {
            return '';
        }
        return $this->paragraph('<a href="' . $href . '">' . $label . '</a>', ['className' => $class]);
    }

    /** An internal path, an in-page anchor, or a mailto; anything else is dropped. */
    private function href(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '#' || $raw === $this->pagePath) {
            return null;
        }
        if (preg_match('~^(/[^\s"<>]*|#[\w-]+|mailto:[^\s"<>@]+@[^\s"<>]+)$~', $raw) === 1) {
            return $raw;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Archetypes

    private function equalCardGrid(): string
    {
        $items = $this->items();
        if ($this->ledger()) {
            return $this->copyStack() . $this->ledgerBand();
        }
        $columns = [];
        foreach ($items as $i => $item) {
            $columns[] = $this->card($item, $this->highlight && $i === 0 ? 'accent' : null);
        }
        return $this->copyStack() . $this->equalRow($columns);
    }

    private function bentoGrid(): string
    {
        $items = array_slice($this->items(), 0, 5);
        if ($this->ledger()) {
            return $this->copyStack() . $this->ledgerBand();
        }
        $twoFirst = crc32($this->slug) % 2 === 0;
        $first = array_slice($items, 0, $twoFirst ? 2 : 3);
        $second = array_slice($items, $twoFirst ? 2 : 3);
        $highlightIndex = $twoFirst ? 0 : count($first);
        $cards = [];
        foreach ($items as $i => $item) {
            $cards[] = $this->card($item, $i === $highlightIndex ? 'inverted' : null, $i === $highlightIndex);
        }
        return $this->copyStack(withAction: false)
            . $this->equalRow(array_slice($cards, 0, count($first)))
            . $this->equalRow(array_slice($cards, count($first), count($second)));
    }

    private function ctaPanel(): string
    {
        $image = $this->sectionImage('the picture beside the panel copy', 'card-media');
        $panel = [
            'className'       => SectionComposition::CTA_PANEL_CLASS,
            'align'           => 'wide',
            'backgroundColor' => 'contrast',
            'textColor'       => 'base',
            'style'           => ['spacing' => ['padding' => [
                'top' => 'var:preset|spacing|xl', 'bottom' => 'var:preset|spacing|xl',
                'left' => 'var:preset|spacing|lg', 'right' => 'var:preset|spacing|lg',
            ]]],
            'layout'          => ['type' => 'constrained'],
        ];
        if ($image === null) {
            $center = ['style' => ['typography' => ['textAlign' => 'center']]];
            $copy = $this->heading(2, null, $center) . $this->lead($center)
                . ($this->action([], true) ?: $this->fallbackButton(true));
            return $this->wrap('group', $panel, $copy);
        }
        $copy = $this->heading(2) . $this->lead() . ($this->action() ?: $this->fallbackButton());
        $flush = $this->cardStyle !== 'framed';
        if ($flush) {
            $panel['className'] .= ' ' . SectionComposition::CTA_PANEL_FLUSH_CLASS;
        }
        $row = $this->wrap('columns', ['align' => 'wide'],
            $this->column('60%', $copy, $flush ? ['className' => SectionComposition::CTA_PANEL_COPY_CLASS] : [])
            . $this->column('40%', $image, $flush ? ['className' => SectionComposition::CTA_PANEL_MEDIA_CLASS] : []));
        return $this->wrap('group', $panel, $row);
    }

    /** A closing panel always carries one button; the page path is the last resort. */
    private function fallbackButton(bool $center = false): string
    {
        $label = $this->str('action_label') ?: $this->str('link_label');
        if ($label === '') {
            return '';
        }
        $href = $this->href($this->str('link_href')) ?? '/#' . $this->slug;
        $attrs = $center ? ['layout' => ['type' => 'flex', 'justifyContent' => 'center']] : [];
        return $this->wrap('buttons', $attrs, $this->wrap('button', [], '<a href="' . self::h($href) . '">' . self::h($label) . '</a>'));
    }

    private function faqSplit(): string
    {
        $intro = $this->heading() . $this->lead() . ($this->sectionImage('a small image under the introduction', 'card-media') ?? '') . $this->action() . $this->textLink();
        {
            $list = '';
            foreach ($this->items() as $item) {
                $list .= $this->wrap(
                    'details',
                    $this->itemPattern !== null ? ['className' => ItemPattern::ITEM_MARKER] : [],
                    '<summary>' . $this->inline($item['heading'] ?? '') . '</summary>' . $this->paragraph($item['text'] ?? ''),
                    'details',
                );
            }
            $list = $this->wrap('group', ['className' => 'faq-list', 'style' => ['spacing' => ['blockGap' => '0']], 'layout' => ['type' => 'constrained']], $list);
        }
        $attrs = ['align' => 'wide'] + ($this->background === 'contrast' ? ['textColor' => 'base'] : []);
        return $this->wrap('columns', $attrs, $this->column('40%', $intro) . $this->column('60%', $list));
    }

    private function featureRow(): string
    {
        if ($this->ledger()) {
            return $this->copyStack() . $this->ledgerBand();
        }
        $items = array_slice($this->items(), 0, 4);
        $columns = [];
        foreach ($items as $item) {
            $columns[] = $this->heading(3, $item['heading'] ?? '') . $this->paragraph($item['text'] ?? '');
        }
        return $this->copyStack() . $this->equalRow($columns, false);
    }

    private function fullBleedCover(): string
    {
        $copy = $this->heading() . $this->lead() . $this->paragraphs() . $this->action();
        $image = $this->sectionImage('full-frame backdrop behind the section copy');
        if ($image === null) {
            return $this->wrap('group', ['align' => $this->framed ? 'wide' : 'full'] + $this->inverted() + ['layout' => ['type' => 'constrained']], $copy);
        }
        return $this->cover($image, $copy);
    }

    private function logoStrip(): string
    {
        $names = '';
        foreach (array_slice($this->items(), 0, 8) as $item) {
            $names .= $this->paragraph($item['heading'] ?? '', $this->itemPattern !== null ? ['className' => ItemPattern::ITEM_MARKER] : []);
        }
        $lead = $this->str('lead') !== '' ? $this->paragraph($this->str('lead'), ['align' => 'center']) : '';
        return $lead . $this->wrap('group', [
            'className' => SectionComposition::LOGO_STRIP_CLASS,
            'align'     => 'wide',
            'layout'    => ['type' => 'flex', 'justifyContent' => 'center', 'flexWrap' => 'wrap'],
        ], $names);
    }

    private function offsetGrid(): string
    {
        $items = array_slice($this->items(), 0, 6);
        $rows = '';
        foreach (array_chunk($items, 3) as $chunk) {
            $widths = match (count($chunk)) {
                1 => ['100%'],
                2 => ['45%', '55%'],
                default => ['40%', '25%', '35%'],
            };
            $columns = '';
            foreach ($chunk as $i => $item) {
                $card = $this->card(['heading' => '', 'text' => $item['text'] ?? '', 'image_subject' => $item['image_subject'] ?? '', 'image_context' => $item['image_context'] ?? ''], null, false, 'picture in a staggered gallery grid');
                if ($i % 2 === 1) {
                    $card = $this->wrap('group', ['style' => ['spacing' => ['margin' => ['top' => '3rem']]], 'layout' => ['type' => 'constrained']], $card);
                }
                $columns .= $this->column($widths[$i], $card);
            }
            $rows .= $this->wrap('columns', ['align' => 'wide'], $columns);
        }
        return $this->copyStack() . $rows;
    }

    private function pricingTiers(): string
    {
        $items = array_slice($this->items(), 0, 3);
        $recommended = count($items) === 3 ? 1 : count($items) - 1;
        $planned = is_array($this->section['primary_action'] ?? null) ? $this->section['primary_action'] : [];
        $href = $this->href((string) ($planned['destination'] ?? '')) ?? '/#' . $this->slug;
        $columns = [];
        foreach ($items as $i => $item) {
            $inner = $this->heading(3, $item['heading'] ?? '');
            $price = trim((string) ($item['meta'] ?? ''));
            if ($price !== '') {
                $inner .= $this->paragraph($price, preg_match('/\d/', $price) === 1 ? ['className' => 'price-figure'] : []);
            }
            $inner .= $this->bullets($item['list'] ?? []);
            $label = trim((string) ($item['link_label'] ?? '')) ?: ($i === $recommended ? (string) ($planned['label'] ?? '') : '');
            if ($label !== '') {
                $inner .= $this->wrap('buttons', ['className' => 'cta-bottom'], $this->wrap('button', [], '<a href="' . self::h($href) . '">' . self::h($label) . '</a>'));
            }
            $classes = [$this->itemPattern !== null ? ItemPattern::ITEM_MARKER : ''];
            if ($i === $recommended) {
                $classes[] = SectionComposition::BENTO_HIGHLIGHT_CLASS;
            }
            $attrs = ['className' => $this->classes($classes), 'layout' => ['type' => 'constrained']];
            $attrs += $i === $recommended ? $this->inverted() : $this->cardSurface();
            if ($this->cardStyle !== 'borderless' || $i === $recommended) {
                $attrs['style'] = ['spacing' => ['padding' => self::pad('md')]];
            }
            $columns[] = $this->wrap('group', $attrs, $inner);
        }
        return $this->copyStack(withAction: false) . $this->equalRow($columns);
    }

    private function projectGrid(): string
    {
        $items = array_slice($this->items(), 0, 4);
        if (count($items) === 3) {
            $items = array_slice($items, 0, 2);
        }
        $rows = '';
        foreach (array_chunk($items, 2) as $chunk) {
            $columns = '';
            foreach ($chunk as $item) {
                $image = $this->image($item['image_subject'] ?? '', $item['image_context'] ?? '', 'project tile in a two-by-two portfolio grid', 'card');
                $inner = $this->heading(3, $item['heading'] ?? '')
                    . $this->paragraph($item['meta'] ?? '', ['className' => SectionComposition::PROJECT_META_CLASS]);
                $attrs = [
                    'contentPosition'    => 'bottom left',
                    'customOverlayColor' => Units\GeneratedMarkup::PROJECT_TILE_OVERLAY,
                    'isUserOverlayColor' => true,
                    'dimRatio'           => Units\GeneratedMarkup::PROJECT_TILE_DIM,
                    'style'              => ['color' => ['text' => Units\GeneratedMarkup::PROJECT_TILE_INK]],
                ];
                if ($this->itemPattern !== null) {
                    $attrs['className'] = ItemPattern::ITEM_MARKER;
                }
                $columns .= $this->column('50%', $image === null
                    ? $this->wrap('group', $attrs + ['layout' => ['type' => 'constrained']] + $this->cardSurface(), $inner)
                    : $this->cover($image, $inner, $attrs));
            }
            $rows .= $this->wrap('columns', ['align' => 'wide'], $columns);
        }
        return $this->copyStack(withAction: false) . $rows . $this->textLink();
    }

    private function statLedger(): string
    {
        if ($this->ledger()) {
            return $this->copyStack() . $this->ledgerBand();
        }
        $columns = [];
        foreach (array_slice($this->items(), 0, 4) as $item) {
            $columns[] = $this->heading(3, $item['heading'] ?? '') . $this->paragraph($item['text'] ?? '', ['fontSize' => 'caption']);
        }
        return $this->copyStack() . $this->equalRow($columns, false);
    }

    private function zigzagSteps(): string
    {
        $rows = '';
        foreach (array_slice($this->items(), 0, 5) as $i => $item) {
            $text = $this->column('55%', $this->heading(3, $item['heading'] ?? '') . $this->paragraph($item['text'] ?? ''));
            $media = $this->column('45%', $this->itemImage($item, 'card-media', 'landscape image beside step copy in an alternating process band')
                ?? $this->wrap('group', ['className' => 'step-plate', 'layout' => ['type' => 'constrained']], ''));
            $attrs = ['align' => 'wide'];
            if ($this->itemPattern !== null) {
                $attrs['className'] = ItemPattern::ITEM_MARKER;
            }
            $rows .= $this->wrap('columns', $attrs, $i % 2 === 0 ? $text . $media : $media . $text);
        }
        return $this->copyStack() . $rows;
    }

    private function asymmetricSplit(): string
    {
        $lead = $this->heading() . $this->lead() . $this->paragraphs() . $this->action() . $this->textLink();
        $support = $this->sectionImage('the supporting picture beside the section copy', 'card-media') ?? '';
        if ($this->ledger()) {
            $support .= $this->ledgerRows();
        } elseif ($this->itemPattern === 'card') {
            foreach ($this->items() as $item) {
                $support .= $this->card($item);
            }
        } else {
            $support .= $this->factRows();
        }
        $leadAttrs = ['width' => '40%'];
        if (SectionComposition::pinDirective($this->archetype, $this->itemPattern) !== '') {
            $leadAttrs['className'] = SectionComposition::PIN_CLASS;
        }
        if ($support === '') {
            return $this->copyStack();
        }
        return $this->wrap('columns', ['align' => 'wide'], $this->column('40%', $lead, $leadAttrs) . $this->column('60%', $support));
    }

    // ------------------------------------------------------------------
    // Repeated items

    /** @return list<array<string,mixed>> */
    private function items(): array
    {
        $items = [];
        foreach ($this->list('items', false) as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * Whether the items render as a ledger or a chip cluster. Only the
     * archetypes whose catalog checks tolerate extra rows take one; the
     * others keep their native item shape and mark each item instead.
     */
    private function ledger(): bool
    {
        return SectionContent::ledgers($this->archetype, $this->itemPattern);
    }

    private function ledgerBand(): string
    {
        return $this->wrap('group', ['align' => 'wide', 'layout' => ['type' => 'constrained']], $this->ledgerRows());
    }

    private function ledgerRows(): string
    {
        $items = $this->items();
        if ($this->itemPattern === 'tag-cluster') {
            $chips = '';
            foreach ($items as $item) {
                $chips .= $this->paragraph($item['heading'] ?? '', [
                    'className' => ItemPattern::ITEM_MARKER,
                    'fontSize'  => 'caption',
                    'style'     => ['border' => ['width' => '1px', 'style' => 'solid'], 'spacing' => ['padding' => self::pad('xs', 'sm')]],
                ]);
            }
            return $this->wrap('group', ['layout' => ['type' => 'flex', 'flexWrap' => 'wrap'], 'style' => ['spacing' => ['blockGap' => 'var:preset|spacing|xs']]], $chips);
        }
        $rows = '';
        $last = count($items) - 1;
        foreach ($items as $i => $item) {
            $style = ['spacing' => ['padding' => ['top' => 'var:preset|spacing|sm', 'bottom' => 'var:preset|spacing|sm']]];
            if ($i < $last) {
                $style['border'] = ['bottom' => ['color' => 'var:preset|color|secondary', 'width' => '1px', 'style' => 'solid']];
            }
            $rows .= $this->wrap('columns', ['isStackedOnMobile' => false, 'className' => ItemPattern::ITEM_MARKER, 'style' => $style],
                $this->column('40%', $this->paragraph($item['heading'] ?? '', ['fontSize' => 'caption', 'textColor' => 'secondary']))
                . $this->column('60%', $this->paragraph($item['text'] ?? '')));
        }
        return $rows;
    }

    /** Label/value facts as a plain list when no ledger pattern is assigned. */
    private function factRows(): string
    {
        $lines = [];
        foreach ($this->items() as $item) {
            $label = trim((string) ($item['heading'] ?? ''));
            $value = trim((string) ($item['text'] ?? ''));
            $lines[] = $label !== '' && $value !== '' ? '<strong>' . $label . '</strong> ' . $value : $label . $value;
        }
        return $this->bullets($lines);
    }

    /** One card in the assigned card style. */
    private function card(array $item, ?string $highlight = null, bool $withAction = false, string $imageContext = 'card image in a row of equal cards'): string
    {
        $framed = $this->cardStyle === 'framed' ? ['style' => ['border' => ['radius' => '2px']]] : [];
        $image = $this->itemImage($item, 'card-media', $imageContext, $framed);
        $body = $this->heading(3, $item['heading'] ?? '')
            . $this->paragraph($item['text'] ?? '')
            . $this->bullets($item['list'] ?? [])
            . ($withAction ? $this->action(['className' => 'cta-bottom']) : '')
            . $this->textLink((string) ($item['link_label'] ?? ''), (string) ($item['link_href'] ?? ''), 'text-action cta-bottom');
        $classes = [$this->itemPattern !== null ? ItemPattern::ITEM_MARKER : ''];
        $paint = $highlight === 'accent'
            ? ['backgroundColor' => 'accent', 'textColor' => 'base']
            : ($highlight === 'inverted' ? $this->inverted() : $this->cardSurface());
        if ($highlight !== null) {
            $classes[] = SectionComposition::BENTO_HIGHLIGHT_CLASS;
        }
        if ($image === null) {
            $attrs = ['layout' => ['type' => 'constrained']];
            if ($this->cardStyle !== 'borderless') {
                $attrs += $paint;
                $attrs['style'] = ['spacing' => ['padding' => self::pad('md')]];
            }
            $class = $this->classes($classes);
            return $this->wrap('group', ($class !== '' ? ['className' => $class] : []) + $attrs, $body);
        }
        $classes[] = 'card-style--' . $this->cardStyle;
        $bodyAttrs = ['className' => 'card-body', 'layout' => ['type' => 'constrained']];
        $attrs = ['layout' => ['type' => 'constrained']];
        switch ($this->cardStyle) {
            case 'borderless':
                break;
            case 'framed':
                $attrs += $paint;
                $attrs['style'] = ['spacing' => ['padding' => self::px(24)], 'border' => ['radius' => '16px']];
                break;
            case 'overlap':
                $classes[] = 'card-flush';
                $attrs += $paint;
                $attrs['style'] = ['spacing' => ['blockGap' => '0']];
                $bodyAttrs['className'] = 'card-body overlap-up';
                $bodyAttrs += $paint;
                $bodyAttrs['style'] = ['spacing' => ['padding' => self::pad('md'), 'margin' => ['left' => '1rem', 'right' => '1rem']]];
                break;
            default: // flush
                $classes[] = 'card-flush';
                $attrs += $paint;
                $attrs['style'] = ['spacing' => ['blockGap' => '0']];
                $bodyAttrs['style'] = ['spacing' => ['padding' => self::pad('md')]];
        }
        return $this->wrap('group', ['className' => $this->classes($classes)] + $attrs, $image . $this->wrap('group', $bodyAttrs, $body));
    }

    /** @param list<string> $cells */
    private function equalRow(array $cells, bool $cards = true): string
    {
        $count = count($cells);
        if ($count === 0) {
            return '';
        }
        $columns = '';
        $extra = $cards ? ['verticalAlignment' => 'stretch'] : [];
        if (!$cards && $this->itemPattern !== null) {
            $extra['className'] = ItemPattern::ITEM_MARKER;
        }
        foreach ($cells as $i => $cell) {
            $columns .= $this->column(self::width($count, $i), $cell, $extra);
        }
        $attrs = ['align' => 'wide'];
        if ($cards) {
            $attrs['className'] = 'equal-cards';
        }
        return $this->wrap('columns', $attrs, $columns);
    }

    // ------------------------------------------------------------------
    // Images

    /** The section-level image as a wp:image, or null. */
    private function sectionImage(string $defaultContext, string $class = 'feature-media'): ?string
    {
        return $this->image($this->str('image_subject'), $this->str('image_context'), $defaultContext, $class === 'card-media' ? 'card' : 'feature', $class);
    }

    private function itemImage(array $item, string $class, string $defaultContext, array $extra = []): ?string
    {
        return $this->image((string) ($item['image_subject'] ?? ''), (string) ($item['image_context'] ?? ''), $defaultContext, 'card', $class, $extra);
    }

    /**
     * @return string|array{url:string,alt:string}|null a wp:image, the cover
     *         parts when $class is empty, or null when there is no subject
     */
    private function image(string $subject, string $context, string $defaultContext, string $slot, string $class = '', array $extra = []): string|array|null
    {
        $subject = trim(str_replace('|', ' ', $subject));
        if ($subject === '') {
            return null;
        }
        $context = trim(str_replace('|', ' ', $context)) ?: $defaultContext;
        $style = in_array($this->str('image_style'), SectionContent::IMAGE_STYLES, true) ? $this->str('image_style') : 'photorealistic';
        $ratio = match ($slot) {
            'cover' => $this->crop === 'panoramic' ? 'ultrawide' : 'landscape',
            'card'  => match ($this->crop) {
                'portrait' => 'card-portrait',
                'square'   => 'square',
                default    => 'card-landscape',
            },
            default => match ($this->crop) {
                'portrait' => 'card-portrait',
                'square'   => 'square',
                default    => 'landscape',
            },
        };
        $url = 'theme:./assets/' . $this->filename($subject) . '.jpg';
        $alt = 'AI_IMAGE: ' . $subject . ' | ' . $context . ' | ' . $style . ' | ' . $ratio;
        if ($class === '') {
            return ['url' => $url, 'alt' => $alt];
        }
        return $this->wrap('image', ['className' => $class] + $extra,
            '<img src="' . self::h($url) . '" alt="' . self::h($alt) . '"/>', 'figure');
    }

    private function filename(string $subject): string
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $subject) ?: $subject), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name = $this->slug . '-' . (implode('-', array_slice($words, 0, 6)) ?: 'image');
        $name = trim(preg_replace('/[^a-z0-9-]+/', '-', $name) ?? $name, '-');
        $n = ($this->files[$name] ?? 0) + 1;
        $this->files[$name] = $n;
        return $n === 1 ? $name : $name . '-' . $n;
    }

    /** A wp:cover around $inner, from a section-level or item image spec. */
    private function cover(string|array|null $image, string $inner, array $extra = []): string
    {
        if (!is_array($image)) {
            $image = $this->image($this->str('image_subject'), $this->str('image_context'), 'full-frame backdrop behind the section copy', 'cover');
        }
        $attrs = $extra + ($extra === [] ? [
            'dimRatio'          => 50,
            'overlayColor'      => 'base',
            'isUserOverlayColor' => true,
            'textColor'         => 'contrast',
        ] : []) + ['layout' => ['type' => 'constrained']];
        if ($extra === []) {
            $attrs = ['align' => $this->framed ? 'wide' : 'full'] + $attrs;
        }
        if (is_array($image)) {
            $attrs = ['url' => $image['url']] + $attrs;
        }
        $html = '<span aria-hidden="true" class="wp-block-cover__background has-background-dim-50 has-background-dim"'
            . (isset($attrs['customOverlayColor']) ? ' style="background-color:' . self::h((string) $attrs['customOverlayColor']) . '"' : '')
            . '></span>';
        if (is_array($image)) {
            $html = '<img class="wp-block-cover__image-background" src="' . self::h($image['url']) . '" alt="' . self::h($image['alt']) . '" data-object-fit="cover"/>' . $html;
        }
        $html .= '<div class="wp-block-cover__inner-container">' . $inner . '</div>';
        $open = BlockMarkup::serializeComment('cover', $attrs, false);
        $classes = trim('wp-block-cover ' . (isset($attrs['align']) ? 'align' . $attrs['align'] : '') . ' ' . ($attrs['className'] ?? ''));
        return $open . '<div class="' . $classes . '">' . $html . '</div><!-- /wp:cover -->';
    }

    // ------------------------------------------------------------------
    // Block helpers

    private function wrap(string $name, array $attrs, string $inner, string $tag = 'div', array $tagAttrs = []): string
    {
        if (isset($attrs['className']) && trim((string) $attrs['className']) === '') {
            unset($attrs['className']);
        }
        $open = BlockMarkup::serializeComment($name, $attrs, false);
        $html = '';
        $classes = trim((string) ($attrs['className'] ?? '') . ' ' . (string) ($tagAttrs['class'] ?? ''));
        unset($tagAttrs['class']);
        foreach ($tagAttrs as $key => $value) {
            $html .= ' ' . $key . '="' . self::h((string) $value) . '"';
        }
        if ($classes !== '') {
            $html .= ' class="' . self::h($classes) . '"';
        }
        return $open . '<' . $tag . $html . '>' . $inner . '</' . $tag . '><!-- /wp:' . $name . ' -->';
    }

    private function column(string $width, string $inner, array $extra = []): string
    {
        return $this->wrap('column', $extra + ['width' => $width], $inner);
    }

    private function paragraph(string $text, array $attrs = []): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        return $this->wrap('paragraph', $attrs, $this->inline($text), 'p');
    }

    /** @param mixed $lines */
    private function bullets(mixed $lines): string
    {
        if (!is_array($lines)) {
            return '';
        }
        $items = '';
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $items .= $this->wrap('list-item', [], $this->inline($line), 'li');
            }
        }
        return $items === '' ? '' : $this->wrap('list', [], $items, 'ul');
    }

    /** Escape text while keeping em, strong, and safe links. */
    private function inline(string $text): string
    {
        $parts = preg_split('~(<(?:/?(?:em|strong)|span class="emph"|/span|a href="[^"]*"|/a)>)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = '';
        $open = false;
        $dropped = false;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $out .= self::h(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                continue;
            }
            if (preg_match('~^<a href="([^"]*)">$~', $part, $m) === 1) {
                $href = $open ? null : $this->href(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($href === null) {
                    $dropped = true;
                    continue;
                }
                $open = true;
                $out .= '<a href="' . self::h($href) . '">';
                continue;
            }
            if ($part === '</a>') {
                if ($dropped) {
                    $dropped = false;
                    continue;
                }
                if (!$open) {
                    continue;
                }
                $open = false;
            }
            $out .= $part;
        }
        return $open ? $out . '</a>' : $out;
    }

    private static function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function str(string $key): string
    {
        $value = $this->doc[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /** @return list<mixed> */
    private function list(string $key, bool $strings = true): array
    {
        $value = $this->doc[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if ($strings) {
                if (is_string($entry) && trim($entry) !== '') {
                    $out[] = trim($entry);
                }
            } else {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** @param list<string> $classes */
    private function classes(array $classes): string
    {
        return implode(' ', array_values(array_filter(array_map('trim', $classes), static fn (string $c): bool => $c !== '')));
    }

    private static function width(int $count, int $index): string
    {
        return match ($count) {
            1 => '100%',
            2 => '50%',
            3 => $index === 2 ? '33.34%' : '33.33%',
            4 => '25%',
            default => '20%',
        };
    }

    /** @return array<string,string> */
    private static function pad(string $vertical, ?string $horizontal = null): array
    {
        $h = 'var:preset|spacing|' . ($horizontal ?? $vertical);
        $v = 'var:preset|spacing|' . $vertical;
        return ['top' => $v, 'bottom' => $v, 'left' => $h, 'right' => $h];
    }

    /** @return array<string,string> */
    private static function px(int $px): array
    {
        $v = $px . 'px';
        return ['top' => $v, 'bottom' => $v, 'left' => $v, 'right' => $v];
    }
}
