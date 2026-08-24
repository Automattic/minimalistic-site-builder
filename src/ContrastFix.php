<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Deterministic text/background contrast lint + repair for block markup.
 *
 * Walks a document's block tree tracking the *effective* background of every
 * block (nearest ancestor that paints one, defaulting to the theme's `base`
 * page background), computes the WCAG contrast ratio for every visible
 * text/background and link/background pair, and repairs failures:
 *
 *  - failing text gets an explicit `textColor` swapped to whichever of
 *    `base`/`contrast` reads best against that background;
 *  - regions whose effective link color fails get an explicit
 *    `style.elements.link` color injected on the background-providing block,
 *    so links stop inheriting the theme's global (often invisible) default;
 *  - covers with a background image and inner text get a `dimRatio` floor of
 *    40 — an un-dimmed photo makes text readability a coin flip.
 *
 * Text inside an image-backed cover is otherwise left alone: the image's
 * brightness is unknowable here. CoverContrastStep re-checks those covers
 * against the real pixels once images exist.
 *
 * Repairs only touch the block-comment JSON attributes (via BlockMarkup);
 * the block fixer re-serializes the HTML from those attributes afterwards,
 * which keeps markup and attributes in sync.
 *
 * Pure with respect to I/O: palette in, markup in, markup + findings out.
 */
final class ContrastFix
{
    /** Blocks whose content is user-read text (checked against the background). */
    private const TEXT_BLOCKS = [
        'paragraph', 'heading', 'list', 'quote', 'pullquote', 'verse',
        'site-title', 'site-tagline', 'post-title', 'navigation',
    ];

    /** Text blocks rendered at large-text scale — the 3:1 WCAG threshold applies. */
    private const LARGE_TEXT_BLOCKS = ['heading', 'site-title', 'post-title', 'pullquote'];

    /** Blocks whose own HTML may carry a `<figcaption>` the walk must check. */
    private const CAPTION_BLOCKS = ['image', 'gallery'];

    /**
     * Void/dynamic blocks that render text at runtime (no inner HTML to
     * inspect) and whose rendered links inherit the block's own text color,
     * not the theme's elements.link default.
     */
    private const DYNAMIC_TEXT_BLOCKS = ['navigation', 'site-title', 'site-tagline', 'post-title'];

    /** Minimum dim over a cover's background image when text sits on it. */
    public const COVER_DIM_FLOOR = 40;

    /** How high a failing pair may be repaired to at most (defensive cap on stops). */
    private const MAX_BG_COLORS = 8;

    private BlockMarkup $doc;

    /** @var list<array<string,mixed>> analysis rows for text-bearing blocks */
    private array $texts = [];

    /** @var list<array{index:int, dim:int}> image-backed covers with inner text */
    private array $covers = [];

    /** @var list<array{kind:string, block:string, detail:string, repaired:bool}> */
    private array $findings = [];

    /**
     * @param array<string,string> $palette   color slug => #hex
     * @param array<string,string> $gradients gradient slug => CSS gradient
     * @param string|null $globalLink         the theme's global link color
     *                                        (hex or preset reference), if set
     * @param string|null $defaultText        theme.json styles.color.text — what
     *                                        unstyled body text actually renders
     * @param string|null $headingText        styles.elements.heading.color.text
     * @param array<string,string> $fontSizes font-size preset slug => CSS size
     */
    public function __construct(
        private array $palette,
        private array $gradients = [],
        private ?string $globalLink = null,
        private ?string $defaultText = null,
        private ?string $headingText = null,
        private array $fontSizes = [],
        private float $normalText = ContrastMath::NORMAL_TEXT,
    ) {}

    /**
     * Lint one markup document; when $repair is true also rewrite failing
     * block attributes. With $repair false every failure is reported as a
     * warning only (used for the header part, which commonly floats
     * transparently over the hero and would be mis-repaired against `base`).
     *
     * @return array{markup: string, changed: bool,
     *               findings: list<array{kind:string, block:string, detail:string, repaired:bool, residual?:bool}>}
     */
    public function process(string $markup, bool $repair = true): array
    {
        $this->doc = BlockMarkup::parse($markup);
        $this->texts = [];
        $this->covers = [];
        $this->findings = [];

        $rootBg = [$this->rgbFor('base') ?? [255, 255, 255]];
        $rootLink = $this->globalLink !== null ? $this->resolveColorValue($this->globalLink) : null;
        foreach ($this->doc->indices() as $i) {
            if ($this->doc->parent($i) === null) {
                $this->walk($i, $rootBg, 'base', null, null, $rootLink === null ? null : [
                    'rgb' => $rootLink['rgb'], 'label' => $rootLink['label'], 'fromNode' => null,
                ], null);
            }
        }

        if ($this->texts !== [] || $this->covers !== []) {
            $this->plan($repair);
        }

        return [
            'markup'   => $this->doc->render(),
            'changed'  => $this->doc->isMutated(),
            'findings' => $this->findings,
        ];
    }

    // ── analysis walk ────────────────────────────────────────────────────

    /**
     * @param list<array{0:int,1:int,2:int}>|null $bgColors null = unknowable (image)
     * @param array{rgb: array{0:int,1:int,2:int}, label: string, node: int}|null $textCtx
     * @param array{rgb: array{0:int,1:int,2:int}, label: string, fromNode: ?int}|null $linkCtx
     * @param array{rgb: array{0:int,1:int,2:int}, label: string, fromNode: ?int}|null $hoverCtx
     */
    private function walk(
        int $i,
        ?array $bgColors,
        string $bgLabel,
        ?int $bgProvider,
        ?array $textCtx,
        ?array $linkCtx,
        ?array $hoverCtx,
    ): void {
        $attrs = $this->doc->attrs($i) ?? [];
        $name = $this->doc->name($i);

        // Explicit text color on this block shadows the inherited one.
        $own = $this->ownTextColor($attrs);
        if ($own !== null) {
            $textCtx = ['rgb' => $own['rgb'], 'label' => $own['label'], 'node' => $i];
        }

        // Explicit per-block link color (style.elements.link).
        $ownLink = $attrs['style']['elements']['link']['color']['text'] ?? null;
        if (is_string($ownLink) && ($resolved = $this->resolveColorValue($ownLink)) !== null) {
            $linkCtx = ['rgb' => $resolved['rgb'], 'label' => $resolved['label'], 'fromNode' => $i];
        }

        // The hover is a separate declaration, and the generator writes it
        // without checking either colour against this background. Carry it
        // like the resting one so a hover can be judged on its own.
        $ownHover = $attrs['style']['elements']['link'][':hover']['color']['text'] ?? null;
        if (is_string($ownHover) && ($resolved = $this->resolveColorValue($ownHover)) !== null) {
            $hoverCtx = ['rgb' => $resolved['rgb'], 'label' => $resolved['label'], 'fromNode' => $i];
        }

        // Does this block paint a new background for its subtree?
        if ($name === 'cover') {
            // Core renders unstyled cover text white (black with is-light);
            // inherited and theme-default colors don't reach inside a cover.
            if ($own === null) {
                $textCtx = ($attrs['isDark'] ?? true) === false
                    ? ['rgb' => [0, 0, 0], 'label' => 'cover default (black)', 'node' => null]
                    : ['rgb' => [255, 255, 255], 'label' => 'cover default (white)', 'node' => null];
            }
            $hasImage = is_string($attrs['url'] ?? null) && trim((string) $attrs['url']) !== ''
                || ($attrs['useFeaturedImage'] ?? false) === true;
            $dim = (int) ($attrs['dimRatio'] ?? 100); // block.json default
            if ($hasImage) {
                if ($this->subtreeHasVisibleText($i)) {
                    $this->covers[] = ['index' => $i, 'dim' => $dim];
                }
                $bgColors = null; // unknowable until the image exists
                $bgLabel = 'image';
                $bgProvider = $i;
            } else {
                $overlay = $this->coverOverlayColors($attrs);
                if ($bgColors !== null) {
                    $bgColors = $this->compositeStops($overlay, $dim / 100, $bgColors);
                    $bgLabel = 'cover-overlay over ' . $bgLabel;
                    $bgProvider = $i;
                }
            }
        } else {
            $ownBg = $this->ownBackground($attrs, $bgColors);
            if ($ownBg !== null) {
                $bgColors = $ownBg['colors'];
                $bgLabel = $ownBg['label'];
                $bgProvider = $i;
            }
        }

        // Record a row for text-bearing blocks — but let leaves speak for
        // containers (a quote's paragraphs carry the readable text).
        if (in_array($name, self::TEXT_BLOCKS, true) && !$this->hasTextBlockChild($i)) {
            $inner = $this->doc->innerHtml($i);
            $dynamic = in_array($name, self::DYNAMIC_TEXT_BLOCKS, true);
            $this->texts[] = [
                'index'     => $i,
                'name'      => $name,
                'fg'        => $textCtx,
                'bg'        => $bgColors,
                'bgLabel'   => $bgLabel,
                'provider'  => $bgProvider,
                'threshold' => $this->textThreshold($name, $attrs),
                'hasText'   => $dynamic || self::visibleText($inner) !== '',
                // Navigation and site-title color their own runtime links via
                // textColor (core sets a { color: inherit } on them), so only
                // raw anchors count — but a post-title with isLink renders an
                // anchor that follows elements.link like any other link.
                'hasAnchor' => (!$dynamic && stripos($inner, '<a ') !== false)
                    || ($name === 'post-title' && ($attrs['isLink'] ?? false) === true),
                'link'      => $linkCtx,
                'hover'     => $hoverCtx,
            ];
        } elseif (in_array($name, self::CAPTION_BLOCKS, true)
            && preg_match('/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $this->captionHtml($i), $cap)
            && self::visibleText($cap[1]) !== '') {
            // A figcaption inherits the surrounding text color — no core rule
            // recolors it, and removeUnverifiedContextColors strips authored
            // theme-level caption colors on the promise that this pass checks
            // rendered backgrounds. The block supports no textColor, so a
            // failing caption is repaired through the theme stylesheet's
            // caption-text-* class hooks instead.
            $this->texts[] = [
                'index'     => $i,
                'name'      => $name,
                'caption'   => true,
                'fg'        => $textCtx,
                'bg'        => $bgColors,
                'bgLabel'   => $bgLabel,
                'provider'  => $bgProvider,
                'threshold' => $this->normalText,
                'hasText'   => true,
                'hasAnchor' => stripos($cap[1], '<a ') !== false,
                'link'      => $linkCtx,
                'hover'     => $hoverCtx,
            ];
        } elseif (in_array($name, ['quote', 'pullquote'], true)
            && preg_match('/<cite\b[^>]*>(.*?)<\/cite>/is', $this->doc->innerHtml($i), $cite)
            && self::visibleText($cite[1]) !== '') {
            // The quote's paragraphs speak for themselves, but its <cite>
            // lives in the quote's own HTML — check the attribution too.
            $this->texts[] = [
                'index'     => $i,
                'name'      => $name,
                'fg'        => $textCtx,
                'bg'        => $bgColors,
                'bgLabel'   => $bgLabel,
                'provider'  => $bgProvider,
                'threshold' => $this->normalText,
                'hasText'   => true,
                'hasAnchor' => stripos($cite[1], '<a ') !== false,
                'link'      => $linkCtx,
                'hover'     => $hoverCtx,
            ];
        }

        foreach ($this->doc->children($i) as $child) {
            $this->walk($child, $bgColors, $bgLabel, $bgProvider, $textCtx, $linkCtx, $hoverCtx);
        }
    }

    /**
     * The HTML segment that can hold this block's OWN figcaption: everything
     * for a childless image, but only the tail after the last child block for
     * a gallery — core serializes the gallery caption there, and matching the
     * whole innerHtml would claim a child image's caption as the gallery's.
     */
    private function captionHtml(int $i): string
    {
        $children = $this->doc->children($i);
        if ($children === []) {
            return $this->doc->ownHtml($i);
        }
        $lastEnd = $this->doc->endOffset($children[count($children) - 1]);
        if ($lastEnd === null) {
            return '';
        }
        $innerStart = $this->doc->openingOffset($i) + $this->doc->openingLength($i);
        return substr($this->doc->innerHtml($i), $lastEnd - $innerStart);
    }

    // ── repair planning ──────────────────────────────────────────────────

    private function plan(bool $repair): void
    {
        $this->planTexts($repair);
        $this->planLinks($repair);
        $this->planCoverDims($repair);
    }

    // Text/background pairs.
    private function planTexts(bool $repair): void
    {
        foreach ($this->texts as $row) {
            if ($row['bg'] === null || !$row['hasText']) {
                continue; // image-backed cover (phase 2) or nothing visible
            }
            $caption = $row['caption'] ?? false;
            $fg = $row['fg'] ?? $this->defaultTextFor($row['name']);
            $ratio = $this->minRatio($fg['rgb'], $row['bg']);
            if ($ratio >= $row['threshold']) {
                continue;
            }

            [$bestSlug, $bestRatio] = $this->bestOf(['base', 'contrast'], $row['bg']);
            $detail = sprintf(
                '%s %s %s on %s: %.2f < %.1f',
                $row['name'], $caption ? 'caption' : 'text',
                $fg['label'], $row['bgLabel'], $ratio, $row['threshold']
            );
            // Full repair when a candidate passes; partial repair when the
            // background is a mid-tone nothing passes against but a candidate
            // is still a clear improvement (1.07 → 3.83 is worth taking).
            $passes = $bestRatio >= $row['threshold'];
            $improves = $bestRatio >= ContrastMath::LARGE_TEXT && $bestRatio >= $ratio * 1.25;
            if ($bestSlug !== null && $repair && ($passes || $improves)) {
                if ($caption) {
                    // No textColor support on the block; the theme stylesheet
                    // recolors the figcaption via this class hook.
                    $this->setCaptionClass($row['index'], $bestSlug);
                    $applied = "caption-text-{$bestSlug}";
                } else {
                    // When the failing color was an explicit preset on this
                    // very block, its has-<slug>-color class must be swapped too.
                    $oldSlug = ($fg['node'] ?? null) === $row['index'] && isset($this->palette[$fg['label']])
                        ? $fg['label'] : null;
                    $this->setTextColor($row['index'], $bestSlug, $oldSlug);
                    $applied = "textColor={$bestSlug}";
                }
                $this->findings[] = [
                    'kind' => 'text', 'block' => $row['name'],
                    'detail' => $detail . " → {$applied} (" . sprintf('%.2f', $bestRatio) . ')'
                        . ($passes ? '' : ' — best available, still below threshold'),
                    'repaired' => true,
                    'residual' => !$passes,
                ];
            } else {
                $this->findings[] = [
                    'kind' => 'text', 'block' => $row['name'],
                    'detail' => $detail . ($bestSlug === null || !$passes
                        ? ' (no palette color passes — palette-level problem)' : ''),
                    'repaired' => false,
                ];
            }
        }
    }

    // Link/background pairs, one decision per background region and
    // distinct link color: a passing first paragraph must not hide a
    // second whose links inherit a different (failing) color.
    private function planLinks(bool $repair): void
    {
        $groups = []; // provider key => rows with anchors
        foreach ($this->texts as $row) {
            if ($row['bg'] === null || !$row['hasAnchor']) {
                continue;
            }
            $groups[$row['provider'] ?? -1][] = $row;
        }

        $injected = []; // provider index => true
        foreach ($groups as $key => $rows) {
            // Rows sharing a link source share its color — one check each.
            $byLink = [];
            foreach ($rows as $r) {
                $byLink[$r['link']['fromNode'] ?? -1] ??= $r;
            }
            foreach ($byLink as $row) {
                $this->checkLinkRow($row, (int) $key, $repair, $injected);
            }
        }

        // An injected link color cascades into nested background regions whose
        // links previously passed by inheritance — pin those regions to the
        // color they were evaluated with so the injection can't break them.
        foreach ($groups as $key => $rows) {
            if ($key === -1 || isset($injected[$key])) {
                continue;
            }
            $row = $rows[0];
            $inherits = false;
            foreach ($rows as $r) {
                if (($r['link']['fromNode'] ?? null) === null
                    || !$this->isSelfOrDescendant($r['link']['fromNode'], (int) $key)) {
                    $inherits = true;
                    break;
                }
            }
            if (!$inherits) {
                continue; // every row's color is already pinned in the region
            }
            foreach (array_keys($injected) as $p) {
                if ($this->isSelfOrDescendant((int) $key, $p) && $key !== $p) {
                    [$bestSlug, $bestRatio] = $this->bestOf(['base', 'contrast', 'primary', 'secondary', 'accent'], $row['bg']);
                    if ($bestSlug !== null && $bestRatio >= $this->normalText) {
                        $this->setLinkColor((int) $key, $bestSlug, $row['bg']);
                        $this->findings[] = [
                            'kind' => 'link', 'block' => $this->doc->name((int) $key),
                            'detail' => "pinned elements.link={$bestSlug} (ancestor link repair would cascade here)",
                            'repaired' => true,
                        ];
                    }
                    break;
                }
            }
        }
    }

    // Cover dim floor.
    private function planCoverDims(bool $repair): void
    {
        foreach ($this->covers as $cover) {
            if ($cover['dim'] >= self::COVER_DIM_FLOOR) {
                continue;
            }
            $detail = sprintf('cover with text over image has dimRatio %d < %d', $cover['dim'], self::COVER_DIM_FLOOR);
            if ($repair) {
                $attrs = $this->doc->attrs($cover['index']) ?? [];
                $attrs['dimRatio'] = self::COVER_DIM_FLOOR;
                $this->doc->setAttrs($cover['index'], $attrs);
                self::swapDimClass($this->doc, $cover['index'], $cover['dim'], self::COVER_DIM_FLOOR);
                $this->findings[] = [
                    'kind' => 'cover-dim', 'block' => 'cover',
                    'detail' => $detail . ' → dimRatio=' . self::COVER_DIM_FLOOR,
                    'repaired' => true,
                ];
            } else {
                $this->findings[] = ['kind' => 'cover-dim', 'block' => 'cover', 'detail' => $detail, 'repaired' => false];
            }
        }
    }

    /**
     * Check (and repair) one link-color source against its background region.
     *
     * @param array<string,mixed> $row a texts row with hasAnchor
     * @param int $key background-provider node index, -1 for the root region
     * @param array<int,bool> $injected provider index => true, updated in place
     */
    private function checkLinkRow(array $row, int $key, bool $repair, array &$injected): void
    {
        $link = $row['link'];
        $linkRgb = $link['rgb'] ?? $this->rgbFor('primary');
        $linkLabel = $link['label'] ?? 'primary (theme default)';
        if ($linkRgb === null) {
            return;
        }
        $ratio = $this->minRatio($linkRgb, $row['bg']);
        if ($ratio >= $this->normalText) {
            $this->checkHoverOnly($row, $key, $repair);
            return;
        }

        [$bestSlug, $bestRatio] = $this->bestOf(['base', 'contrast', 'primary', 'secondary', 'accent'], $row['bg']);
        $detail = sprintf('links %s on %s: %.2f < %.1f', $linkLabel, $row['bgLabel'], $ratio, $this->normalText);

        if ($key === -1) {
            // Root region (page background). A block-authored elements.link
            // is repaired at that block; the global theme.json default is
            // not ours to fix — ContrastFixStep repairs theme.json itself.
            if ($link !== null && $link['fromNode'] !== null && $repair
                && $bestSlug !== null && $bestRatio >= $this->normalText) {
                $this->setLinkColor($link['fromNode'], $bestSlug, $row['bg']);
                $injected[$link['fromNode']] = true;
                $this->findings[] = [
                    'kind' => 'link', 'block' => $this->doc->name($link['fromNode']),
                    'detail' => $detail . " → elements.link={$bestSlug} (" . sprintf('%.2f', $bestRatio) . ')',
                    'repaired' => true,
                ];
            } else {
                $this->findings[] = [
                    'kind' => 'link', 'block' => 'document',
                    'detail' => $detail . ' (global theme.json link default — fix at theme level)',
                    'repaired' => false,
                ];
            }
            return;
        }
        if ($bestSlug === null || $bestRatio < $this->normalText || !$repair) {
            $this->findings[] = ['kind' => 'link', 'block' => $this->doc->name($key), 'detail' => $detail, 'repaired' => false];
            return;
        }

        // Fix at the block that set the failing color when it's inside the
        // region; otherwise inject on the background provider.
        $target = ($link !== null && $link['fromNode'] !== null
            && $this->isSelfOrDescendant($link['fromNode'], $key))
            ? $link['fromNode'] : $key;
        $this->setLinkColor($target, $bestSlug, $row['bg']);
        $injected[$target] = true;
        $this->findings[] = [
            'kind' => 'link', 'block' => $this->doc->name($target),
            'detail' => $detail . " → elements.link={$bestSlug} (" . sprintf('%.2f', $bestRatio) . ')',
            'repaired' => true,
        ];
    }

    // ── attribute mutations ──────────────────────────────────────────────

    private function setTextColor(int $i, string $slug, ?string $oldSlug = null): void
    {
        $attrs = $this->doc->attrs($i) ?? [];
        $attrs['textColor'] = $slug;
        unset($attrs['style']['color']['text']);
        self::pruneEmpty($attrs);
        $this->doc->setAttrs($i, $attrs);
        if ($oldSlug !== null && $oldSlug !== $slug) {
            $this->doc->replaceInOwnHtml($i, "has-{$oldSlug}-color", "has-{$slug}-color");
        }
    }

    /**
     * Opt the figure into the theme stylesheet's caption color hook
     * (`.caption-text-<slug> > figcaption`), replacing any earlier one.
     */
    private function setCaptionClass(int $i, string $slug): void
    {
        $attrs = $this->doc->attrs($i) ?? [];
        $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $classes = array_values(array_filter(
            $classes,
            static fn (string $c): bool => !str_starts_with($c, 'caption-text-'),
        ));
        $classes[] = 'caption-text-' . $slug;
        $attrs['className'] = implode(' ', $classes);
        $this->doc->setAttrs($i, $attrs);
    }

    /**
     * The resting link reads here, so no repair ran for this region — but hover
     * is a second declaration with its own colour, and a generator that picked
     * a readable resting colour can still pair it with an accent that vanishes
     * on the same background.
     *
     * Only an authored hover is repaired: a region that inherits the theme's
     * hover also inherits its resting colour, which would have failed here and
     * taken the repair path instead. The global pair is ContrastFixStep's, and
     * it checks that against the page background.
     *
     * @param array{bg: list<array{0:int,1:int,2:int}>, bgLabel: string,
     *              link: array{label: string}|null,
     *              hover: array{rgb: array{0:int,1:int,2:int}, label: string, fromNode: ?int}|null} $row
     * @param int $key background-provider node index, -1 for the root region
     */
    private function checkHoverOnly(array $row, int $key, bool $repair): void
    {
        $hover = $row['hover'] ?? null;
        if ($hover === null || $hover['fromNode'] === null) {
            return;
        }
        $ratio = $this->minRatio($hover['rgb'], $row['bg']);
        if ($ratio >= $this->normalText) {
            return;
        }

        $detail = sprintf(
            'link hover %s on %s: %.2f < %.1f',
            $hover['label'], $row['bgLabel'], $ratio, $this->normalText
        );
        $restingSlug = $row['link']['label'] ?? null;
        $fallback = $restingSlug !== null && isset($this->palette[$restingSlug]) ? $restingSlug : null;
        if ($fallback === null) {
            [$best, $bestRatio] = $this->bestOf(['base', 'contrast', 'primary', 'secondary', 'accent'], $row['bg']);
            $fallback = $best !== null && $bestRatio >= $this->normalText ? $best : null;
        }
        if ($fallback === null || !$repair) {
            $this->findings[] = [
                'kind' => 'link', 'block' => $this->doc->name($hover['fromNode']),
                'detail' => $detail, 'repaired' => false,
            ];
            return;
        }

        // The hover may be declared on an ancestor that also governs regions
        // this row does not speak for. Repair inside the region — on its own
        // background provider — rather than rewriting the ancestor's colour
        // out from under a sibling band it already reads on.
        $target = $key === -1 || $this->isSelfOrDescendant($hover['fromNode'], $key)
            ? $hover['fromNode'] : $key;
        $slug = $this->repairHover($target, $fallback, $row['bg']);
        if ($slug === null) {
            return; // already readable here — an earlier region repaired it
        }
        $this->findings[] = [
            'kind' => 'link', 'block' => $this->doc->name($target),
            'detail' => $detail . " → elements.link:hover={$slug}",
            'repaired' => true,
        ];
    }

    /** @param list<array{0:int,1:int,2:int}> $bg */
    private function setLinkColor(int $i, string $slug, array $bg): void
    {
        $attrs = $this->doc->attrs($i) ?? [];
        $attrs['style']['elements']['link']['color']['text'] = 'var:preset|color|' . $slug;
        $this->doc->setAttrs($i, $attrs);
        $this->repairHover($i, $slug, $bg);
    }

    /**
     * Keep the hover readable on the background the resting link was judged on.
     *
     * Hover is body-size text too, so it gets the same 4.5:1 bar. An authored
     * hover that reads is left alone; otherwise prefer the accent when it
     * passes, else fall back to $fallback so hover never goes invisible either.
     *
     * @param list<array{0:int,1:int,2:int}> $bg
     * @return string|null the slug written, or null when the hover already read
     */
    private function repairHover(int $i, string $fallback, array $bg): ?string
    {
        $attrs = $this->doc->attrs($i) ?? [];
        $authored = $attrs['style']['elements']['link'][':hover']['color']['text'] ?? null;
        if (is_string($authored)
            && ($resolved = $this->resolveColorValue($authored)) !== null
            && $this->minRatio($resolved['rgb'], $bg) >= $this->normalText) {
            return null;
        }
        $accent = $this->rgbFor('accent');
        $slug = ($accent !== null && $this->minRatio($accent, $bg) >= $this->normalText)
            ? 'accent' : $fallback;
        $attrs['style']['elements']['link'][':hover']['color']['text'] = 'var:preset|color|' . $slug;
        $this->doc->setAttrs($i, $attrs);
        return $slug;
    }

    /**
     * Swap the stale `has-background-dim-N` class after a dimRatio change so
     * the fixer's custom-classname rescue can't keep the old opacity around.
     * Covers at dim 0 or 50 carry no numbered class (core save() omits it).
     */
    public static function swapDimClass(BlockMarkup $doc, int $i, int $oldDim, int $newDim): void
    {
        if ($oldDim === $newDim) {
            return;
        }
        $oldClass = self::dimClass($oldDim);
        $newClass = self::dimClass($newDim);
        if ($oldClass !== null) {
            // The generic token is already present beside every numbered
            // Core dim class. Moving to the 50% default therefore removes
            // only the old numbered token instead of duplicating the generic.
            $doc->replaceClassTokenInOwnHtml($i, $oldClass, $newClass ?? '');
            return;
        }
        if ($newClass !== null) {
            // Core's 50% default has only the generic token. Preserve it while
            // adding the numbered opacity token the new value requires.
            $doc->replaceClassTokenInOwnHtml(
                $i,
                'has-background-dim',
                $newClass . ' has-background-dim',
            );
        }
    }

    /** Core cover save(): numbered dim class, absent only at the 50 default. */
    private static function dimClass(int $dim): ?string
    {
        $rounded = 10 * (int) round($dim / 10);
        return $rounded === 50 ? null : 'has-background-dim-' . $rounded;
    }

    /** Drop style/color scaffolding left empty by an unset. @param array<mixed> $attrs */
    public static function pruneEmpty(array &$attrs): void
    {
        if (isset($attrs['style']['color']) && $attrs['style']['color'] === []) {
            unset($attrs['style']['color']);
        }
        if (isset($attrs['style']) && $attrs['style'] === []) {
            unset($attrs['style']);
        }
    }

    // ── color resolution helpers ─────────────────────────────────────────

    /**
     * What an unstyled block of this type actually renders: the theme's
     * element default (headings), its global text default, or `contrast`.
     *
     * @return array{rgb: array{0:int,1:int,2:int}, label: string, node: null}
     */
    private function defaultTextFor(string $name): array
    {
        $isHeading = in_array($name, ['heading', 'site-title', 'post-title'], true);
        $value = $isHeading ? ($this->headingText ?? $this->defaultText) : $this->defaultText;
        if ($value !== null && ($resolved = $this->resolveColorValue($value)) !== null) {
            return ['rgb' => $resolved['rgb'], 'label' => $resolved['label'] . ' (theme default)', 'node' => null];
        }
        return ['rgb' => $this->rgbFor('contrast') ?? [0, 0, 0], 'label' => 'contrast', 'node' => null];
    }

    /**
     * The WCAG threshold this block's text must clear. Heading-scale blocks
     * get the 3:1 large-text bar only when they actually render large: an
     * explicit font size decides (WCAG large text is ≥24px, or ≥18.66px
     * bold); without one, only h1–h3 are assumed to be at large scale.
     * Public: CoverContrastStep classifies cover texts with the same rule.
     */
    public function textThreshold(string $name, array $attrs): float
    {
        if (!in_array($name, self::LARGE_TEXT_BLOCKS, true)) {
            return $this->normalText;
        }
        $px = $this->resolvedFontSizePx($attrs);
        if ($px !== null) {
            $weight = $attrs['style']['typography']['fontWeight'] ?? null;
            $bold = $weight === 'bold' || (is_numeric($weight) && (int) $weight >= 700);
            return ($px >= 24.0 || ($bold && $px >= 18.66))
                ? ContrastMath::LARGE_TEXT : $this->normalText;
        }
        if ($name === 'heading') {
            return ((int) ($attrs['level'] ?? 2)) <= 3
                ? ContrastMath::LARGE_TEXT : $this->normalText;
        }
        return ContrastMath::LARGE_TEXT; // site-title, post-title, pullquote
    }

    /** The block's explicit font size in px, when one is set and parseable. */
    private function resolvedFontSizePx(array $attrs): ?float
    {
        $size = null;
        $slug = $attrs['fontSize'] ?? null;
        if (is_string($slug) && isset($this->fontSizes[$slug])) {
            $size = $this->fontSizes[$slug];
        } elseif (is_string($attrs['style']['typography']['fontSize'] ?? null)) {
            $size = $attrs['style']['typography']['fontSize'];
        }
        if ($size === null || !preg_match('/^([0-9.]+)(px|rem|em|pt)$/', trim($size), $m)) {
            return null; // no explicit size, or clamp()/var() — undecidable
        }
        $n = (float) $m[1];
        return match ($m[2]) {
            'px'         => $n,
            'rem', 'em'  => $n * 16,
            'pt'         => $n * 4 / 3,
        };
    }

    /** @return array{rgb: array{0:int,1:int,2:int}, label: string}|null */
    private function ownTextColor(array $attrs): ?array
    {
        $slug = $attrs['textColor'] ?? null;
        if (is_string($slug) && ($rgb = $this->rgbFor($slug)) !== null) {
            return ['rgb' => $rgb, 'label' => $slug];
        }
        $raw = $attrs['style']['color']['text'] ?? null;
        if (is_string($raw)) {
            return $this->resolveColorValue($raw);
        }
        return null;
    }

    /**
     * The background this block paints, if any: backgroundColor preset,
     * raw style.color.background, or a gradient (checked per color stop).
     * Public: ContrastFixStep's overlay-header lint evaluates a section's
     * top-level background with the same rules.
     *
     * @param list<array{0:int,1:int,2:int}>|null $parentColors
     * @return array{colors: list<array{0:int,1:int,2:int}>, label: string}|null
     */
    public function ownBackground(array $attrs, ?array $parentColors): ?array
    {
        $solid = null;
        $solidLabel = null;
        $slug = $attrs['backgroundColor'] ?? null;
        if (is_string($slug) && ($rgb = $this->rgbFor($slug)) !== null) {
            $solid = $rgb;
            $solidLabel = $slug;
        } else {
            $raw = $attrs['style']['color']['background'] ?? null;
            if (is_string($raw) && ($resolved = $this->resolveColorValue($raw)) !== null) {
                $solid = $resolved['rgb'];
                $solidLabel = $resolved['label'];
            }
        }

        $gradientCss = null;
        $label = null;
        $gradientSlug = $attrs['gradient'] ?? null;
        if (is_string($gradientSlug) && isset($this->gradients[$gradientSlug])) {
            $gradientCss = $this->gradients[$gradientSlug];
            $label = 'gradient:' . $gradientSlug;
        } elseif (is_string($attrs['style']['color']['gradient'] ?? null)) {
            $gradientCss = $attrs['style']['color']['gradient'];
            $label = 'custom gradient';
        }
        // A gradient paints over the co-authored background color (WP layers
        // it as background-image), so it wins — composited over the solid
        // where translucent stops let it show through.
        if ($gradientCss !== null) {
            $stops = ContrastMath::gradientStops($gradientCss);
            if ($stops !== []) {
                $parents = $solid !== null ? [$solid] : ($parentColors ?? [[255, 255, 255]]);
                $colors = $this->compositeStops($stops, 1.0, $parents);
                return ['colors' => $colors, 'label' => (string) $label];
            }
        }
        if ($solid !== null) {
            return ['colors' => [$solid], 'label' => (string) $solidLabel];
        }
        return null;
    }

    /**
     * The overlay colors of a cover: preset/custom overlay color, gradient
     * stops, or core's black default when only dimRatio is set. Public:
     * CoverContrastStep evaluates the same overlays against real image pixels.
     *
     * @return list<array{rgb: array{0:int,1:int,2:int}, alpha: float}>
     */
    public function coverOverlayColors(array $attrs): array
    {
        $solid = null;
        $slug = $attrs['overlayColor'] ?? null;
        if (is_string($slug) && ($rgb = $this->rgbFor($slug)) !== null) {
            $solid = $rgb;
        } else {
            $custom = $attrs['customOverlayColor'] ?? null;
            if (is_string($custom) && ($rgb = ContrastMath::hexToRgb($custom)) !== null) {
                $solid = $rgb;
            }
        }

        // A co-authored gradient paints over the solid overlay color within
        // the same span, so it wins; translucent stops show the solid, which
        // makes the composite opaque.
        $gradientCss = $this->coverGradientCss($attrs);
        if ($gradientCss !== null) {
            $stops = ContrastMath::gradientStops($gradientCss);
            if ($stops !== []) {
                if ($solid === null) {
                    return $stops;
                }
                return array_map(
                    static fn (array $s) => [
                        'rgb'   => ContrastMath::compositeOver($s['rgb'], $s['alpha'], $solid),
                        'alpha' => 1.0,
                    ],
                    $stops
                );
            }
        }
        if ($solid !== null) {
            return [['rgb' => $solid, 'alpha' => 1.0]];
        }
        return [['rgb' => [0, 0, 0], 'alpha' => 1.0]]; // core default overlay
    }

    /**
     * The CSS of a cover's overlay gradient (preset or custom), if any.
     * Public: CoverContrastStep needs it to read the gradient's direction.
     */
    public function coverGradientCss(array $attrs): ?string
    {
        $slug = $attrs['gradient'] ?? null;
        if (is_string($slug) && isset($this->gradients[$slug])) {
            return $this->gradients[$slug];
        }
        $custom = $attrs['customGradient'] ?? null;
        return is_string($custom) ? $custom : null;
    }

    /**
     * Composite overlay stops at $opacity over every parent color; the result
     * is the set of possible effective backgrounds (capped for sanity).
     *
     * @param list<array{rgb: array{0:int,1:int,2:int}, alpha: float}> $stops
     * @param list<array{0:int,1:int,2:int}> $parents
     * @return list<array{0:int,1:int,2:int}>
     */
    private function compositeStops(array $stops, float $opacity, array $parents): array
    {
        $out = [];
        foreach ($stops as $stop) {
            foreach ($parents as $parent) {
                $out[] = ContrastMath::compositeOver($stop['rgb'], $stop['alpha'] * $opacity, $parent);
                if (count($out) >= self::MAX_BG_COLORS) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /**
     * Resolve a color value as written in attributes: "#hex",
     * "var:preset|color|slug" or "var(--wp--preset--color--slug)".
     *
     * @return array{rgb: array{0:int,1:int,2:int}, label: string}|null
     */
    public function resolveColorValue(string $value): ?array
    {
        $value = trim($value);
        if (($rgb = ContrastMath::hexToRgb($value)) !== null) {
            return ['rgb' => $rgb, 'label' => strtolower($value)];
        }
        if (preg_match('/^var:preset\|color\|([a-z0-9-]+)$/i', $value, $m)
            || preg_match('/^var\(--wp--preset--color--([a-z0-9-]+)\)$/i', $value, $m)) {
            $rgb = $this->rgbFor($m[1]);
            return $rgb === null ? null : ['rgb' => $rgb, 'label' => $m[1]];
        }
        return null;
    }

    /** @return array{0:int,1:int,2:int}|null */
    public function rgbFor(string $slug): ?array
    {
        $hex = $this->palette[$slug] ?? null;
        return $hex === null ? null : ContrastMath::hexToRgb($hex);
    }

    /**
     * Worst-case ratio of a foreground against every possible background color.
     *
     * @param array{0:int,1:int,2:int} $fg
     * @param list<array{0:int,1:int,2:int}> $bgColors
     */
    private function minRatio(array $fg, array $bgColors): float
    {
        $min = PHP_FLOAT_MAX;
        foreach ($bgColors as $bg) {
            $min = min($min, ContrastMath::ratio($fg, $bg));
        }
        return $min;
    }

    /**
     * The palette slug (among $slugs) with the best worst-case ratio.
     *
     * @param list<string> $slugs
     * @param list<array{0:int,1:int,2:int}> $bgColors
     * @return array{0: ?string, 1: float}
     */
    private function bestOf(array $slugs, array $bgColors): array
    {
        $bestSlug = null;
        $bestRatio = 0.0;
        foreach ($slugs as $slug) {
            $rgb = $this->rgbFor($slug);
            if ($rgb === null) {
                continue;
            }
            $r = $this->minRatio($rgb, $bgColors);
            if ($r > $bestRatio) {
                $bestRatio = $r;
                $bestSlug = $slug;
            }
        }
        return [$bestSlug, $bestRatio];
    }

    // ── tree helpers ─────────────────────────────────────────────────────

    private function hasTextBlockChild(int $i): bool
    {
        foreach ($this->doc->children($i) as $child) {
            if (in_array($this->doc->name($child), self::TEXT_BLOCKS, true)) {
                return true;
            }
        }
        return false;
    }

    private function subtreeHasVisibleText(int $i): bool
    {
        foreach ($this->doc->children($i) as $child) {
            $name = $this->doc->name($child);
            if (in_array($name, self::TEXT_BLOCKS, true)
                && (in_array($name, self::DYNAMIC_TEXT_BLOCKS, true)
                    || self::visibleText($this->doc->innerHtml($child)) !== '')) {
                return true;
            }
            if ($this->subtreeHasVisibleText($child)) {
                return true;
            }
        }
        return false;
    }

    private function isSelfOrDescendant(int $node, int $ancestor): bool
    {
        for ($i = $node; $i !== null; $i = $this->doc->parent($i)) {
            if ($i === $ancestor) {
                return true;
            }
        }
        return false;
    }

    /** Human-visible text of an inner-HTML fragment (comments and tags stripped). */
    public static function visibleText(string $html): string
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
        return trim(html_entity_decode(strip_tags($html)), " \t\n\r\0\x0B\u{00a0}");
    }
}
