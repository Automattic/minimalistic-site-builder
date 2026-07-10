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
     */
    public function __construct(
        private array $palette,
        private array $gradients = [],
        private ?string $globalLink = null,
    ) {}

    /**
     * Lint one markup document; when $repair is true also rewrite failing
     * block attributes. With $repair false every failure is reported as a
     * warning only (used for the header part, which commonly floats
     * transparently over the hero and would be mis-repaired against `base`).
     *
     * @return array{markup: string, changed: bool,
     *               findings: list<array{kind:string, block:string, detail:string, repaired:bool}>}
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
                ]);
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
     */
    private function walk(int $i, ?array $bgColors, string $bgLabel, ?int $bgProvider, ?array $textCtx, ?array $linkCtx): void
    {
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

        // Does this block paint a new background for its subtree?
        if ($name === 'cover') {
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
                'threshold' => in_array($name, self::LARGE_TEXT_BLOCKS, true)
                    ? ContrastMath::LARGE_TEXT : ContrastMath::NORMAL_TEXT,
                'hasText'   => $dynamic || self::visibleText($inner) !== '',
                // Dynamic blocks color their own links via textColor
                // (currentColor), so only raw anchors count here.
                'hasAnchor' => !$dynamic && stripos($inner, '<a ') !== false,
                'link'      => $linkCtx,
            ];
        }

        foreach ($this->doc->children($i) as $child) {
            $this->walk($child, $bgColors, $bgLabel, $bgProvider, $textCtx, $linkCtx);
        }
    }

    // ── repair planning ──────────────────────────────────────────────────

    private function plan(bool $repair): void
    {
        $contrastRgb = $this->rgbFor('contrast') ?? [0, 0, 0];

        // Text/background pairs.
        foreach ($this->texts as $row) {
            if ($row['bg'] === null || !$row['hasText']) {
                continue; // image-backed cover (phase 2) or nothing visible
            }
            $fg = $row['fg'] ?? ['rgb' => $contrastRgb, 'label' => 'contrast', 'node' => null];
            $ratio = $this->minRatio($fg['rgb'], $row['bg']);
            if ($ratio >= $row['threshold']) {
                continue;
            }

            [$bestSlug, $bestRatio] = $this->bestOf(['base', 'contrast'], $row['bg']);
            $detail = sprintf(
                '%s text %s on %s: %.2f < %.1f',
                $row['name'], $fg['label'], $row['bgLabel'], $ratio, $row['threshold']
            );
            // Full repair when a candidate passes; partial repair when the
            // background is a mid-tone nothing passes against but a candidate
            // is still a clear improvement (1.07 → 3.83 is worth taking).
            $passes = $bestRatio >= $row['threshold'];
            $improves = $bestRatio >= ContrastMath::LARGE_TEXT && $bestRatio >= $ratio * 1.25;
            if ($bestSlug !== null && $repair && ($passes || $improves)) {
                // When the failing color was an explicit preset on this very
                // block, its has-<slug>-color class must be swapped too.
                $oldSlug = ($fg['node'] ?? null) === $row['index'] && isset($this->palette[$fg['label']])
                    ? $fg['label'] : null;
                $this->setTextColor($row['index'], $bestSlug, $oldSlug);
                $this->findings[] = [
                    'kind' => 'text', 'block' => $row['name'],
                    'detail' => $detail . " → textColor={$bestSlug} (" . sprintf('%.2f', $bestRatio) . ')'
                        . ($passes ? '' : ' — best available, still below threshold'),
                    'repaired' => true,
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

        // Link/background pairs, one decision per background region.
        $groups = []; // provider key => rows with anchors
        foreach ($this->texts as $row) {
            if ($row['bg'] === null || !$row['hasAnchor']) {
                continue;
            }
            $groups[$row['provider'] ?? -1][] = $row;
        }

        $injected = []; // provider index => true
        foreach ($groups as $key => $rows) {
            $row = $rows[0];
            $link = $row['link'];
            $linkRgb = $link['rgb'] ?? $this->rgbFor('primary');
            $linkLabel = $link['label'] ?? 'primary (theme default)';
            if ($linkRgb === null) {
                continue;
            }
            $ratio = $this->minRatio($linkRgb, $row['bg']);
            if ($ratio >= ContrastMath::NORMAL_TEXT) {
                continue;
            }

            [$bestSlug, $bestRatio] = $this->bestOf(['base', 'contrast', 'primary', 'secondary', 'accent'], $row['bg']);
            $detail = sprintf('links %s on %s: %.2f < %.1f', $linkLabel, $row['bgLabel'], $ratio, ContrastMath::NORMAL_TEXT);

            if ($key === -1) {
                // Root region (page background). A block-authored elements.link
                // is repaired at that block; the global theme.json default is
                // not ours to fix — ContrastFixStep repairs theme.json itself.
                if ($link !== null && $link['fromNode'] !== null && $repair
                    && $bestSlug !== null && $bestRatio >= ContrastMath::NORMAL_TEXT) {
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
                continue;
            }
            if ($bestSlug === null || $bestRatio < ContrastMath::NORMAL_TEXT || !$repair) {
                $this->findings[] = ['kind' => 'link', 'block' => $this->doc->name((int) $key), 'detail' => $detail, 'repaired' => false];
                continue;
            }

            // Fix at the block that set the failing color when it's inside the
            // region; otherwise inject on the background provider.
            $target = ($link !== null && $link['fromNode'] !== null
                && $this->isSelfOrDescendant($link['fromNode'], (int) $key))
                ? $link['fromNode'] : (int) $key;
            $this->setLinkColor($target, $bestSlug, $row['bg']);
            $injected[$target] = true;
            $this->findings[] = [
                'kind' => 'link', 'block' => $this->doc->name($target),
                'detail' => $detail . " → elements.link={$bestSlug} (" . sprintf('%.2f', $bestRatio) . ')',
                'repaired' => true,
            ];
        }

        // An injected link color cascades into nested background regions whose
        // links previously passed by inheritance — pin those regions to the
        // color they were evaluated with so the injection can't break them.
        foreach ($groups as $key => $rows) {
            if ($key === -1 || isset($injected[$key])) {
                continue;
            }
            $row = $rows[0];
            $hasOwnLink = ($row['link']['fromNode'] ?? null) !== null
                && $this->isSelfOrDescendant($row['link']['fromNode'], (int) $key);
            if ($hasOwnLink) {
                continue; // explicit color already pinned in the region
            }
            foreach (array_keys($injected) as $p) {
                if ($this->isSelfOrDescendant((int) $key, $p) && $key !== $p) {
                    [$bestSlug, $bestRatio] = $this->bestOf(['base', 'contrast', 'primary', 'secondary', 'accent'], $row['bg']);
                    if ($bestSlug !== null && $bestRatio >= ContrastMath::NORMAL_TEXT) {
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

        // Cover dim floor.
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

    // ── attribute mutations ──────────────────────────────────────────────

    private function setTextColor(int $i, string $slug, ?string $oldSlug = null): void
    {
        $attrs = $this->doc->attrs($i) ?? [];
        $attrs['textColor'] = $slug;
        unset($attrs['style']['color']['text']);
        $this->pruneEmpty($attrs);
        $this->doc->setAttrs($i, $attrs);
        if ($oldSlug !== null && $oldSlug !== $slug) {
            $this->doc->replaceInOwnHtml($i, "has-{$oldSlug}-color", "has-{$slug}-color");
        }
    }

    /** @param list<array{0:int,1:int,2:int}> $bg */
    private function setLinkColor(int $i, string $slug, array $bg): void
    {
        $attrs = $this->doc->attrs($i) ?? [];
        $attrs['style']['elements']['link']['color']['text'] = 'var:preset|color|' . $slug;
        // Keep the accent hover when it reads on this background; otherwise
        // reuse the repaired color so hover never goes invisible either.
        $accent = $this->rgbFor('accent');
        $hover = ($accent !== null && $this->minRatio($accent, $bg) >= ContrastMath::LARGE_TEXT)
            ? 'accent' : $slug;
        $attrs['style']['elements']['link'][':hover']['color']['text'] = 'var:preset|color|' . $hover;
        $this->doc->setAttrs($i, $attrs);
    }

    /**
     * Swap the stale `has-background-dim-N` class after a dimRatio change so
     * the fixer's custom-classname rescue can't keep the old opacity around.
     * Covers at dim 0 or 50 carry no numbered class (core save() omits it).
     */
    public static function swapDimClass(BlockMarkup $doc, int $i, int $oldDim, int $newDim): void
    {
        $oldClass = self::dimClass($oldDim);
        if ($oldClass === null || $oldDim === $newDim) {
            return;
        }
        // A duplicate has-background-dim token is harmless — the fixer
        // re-serializes to the canonical class list anyway; the point is
        // removing the stale numbered token.
        $doc->replaceInOwnHtml($i, $oldClass, self::dimClass($newDim) ?? 'has-background-dim');
    }

    /** Core cover save(): numbered dim class, absent at 0 and at the 50 default. */
    private static function dimClass(int $dim): ?string
    {
        $rounded = 10 * (int) round($dim / 10);
        return $rounded === 0 || $rounded === 50 ? null : 'has-background-dim-' . $rounded;
    }

    /** Drop style/color scaffolding left empty by an unset. @param array<mixed> $attrs */
    private function pruneEmpty(array &$attrs): void
    {
        if (isset($attrs['style']['color']) && $attrs['style']['color'] === []) {
            unset($attrs['style']['color']);
        }
        if (isset($attrs['style']) && $attrs['style'] === []) {
            unset($attrs['style']);
        }
    }

    // ── color resolution helpers ─────────────────────────────────────────

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
     *
     * @param list<array{0:int,1:int,2:int}>|null $parentColors
     * @return array{colors: list<array{0:int,1:int,2:int}>, label: string}|null
     */
    private function ownBackground(array $attrs, ?array $parentColors): ?array
    {
        $slug = $attrs['backgroundColor'] ?? null;
        if (is_string($slug) && ($rgb = $this->rgbFor($slug)) !== null) {
            return ['colors' => [$rgb], 'label' => $slug];
        }
        $raw = $attrs['style']['color']['background'] ?? null;
        if (is_string($raw) && ($resolved = $this->resolveColorValue($raw)) !== null) {
            return ['colors' => [$resolved['rgb']], 'label' => $resolved['label']];
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
        if ($gradientCss !== null) {
            $stops = ContrastMath::parseCssColors($gradientCss);
            if ($stops !== []) {
                $colors = $this->compositeStops($stops, 1.0, $parentColors ?? [[255, 255, 255]]);
                return ['colors' => $colors, 'label' => (string) $label];
            }
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
        $slug = $attrs['overlayColor'] ?? null;
        if (is_string($slug) && ($rgb = $this->rgbFor($slug)) !== null) {
            return [['rgb' => $rgb, 'alpha' => 1.0]];
        }
        $custom = $attrs['customOverlayColor'] ?? null;
        if (is_string($custom) && ($rgb = ContrastMath::hexToRgb($custom)) !== null) {
            return [['rgb' => $rgb, 'alpha' => 1.0]];
        }
        $gradientSlug = $attrs['gradient'] ?? null;
        if (is_string($gradientSlug) && isset($this->gradients[$gradientSlug])) {
            return ContrastMath::parseCssColors($this->gradients[$gradientSlug]);
        }
        $customGradient = $attrs['customGradient'] ?? null;
        if (is_string($customGradient)) {
            $stops = ContrastMath::parseCssColors($customGradient);
            if ($stops !== []) {
                return $stops;
            }
        }
        return [['rgb' => [0, 0, 0], 'alpha' => 1.0]]; // core default overlay
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
