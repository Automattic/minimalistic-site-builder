<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Project-free authority for the relationship between the global header, the
 * front-page hero, every page opening, and the hero's lower edge.
 *
 * resolve() makes the one creative assignment. The two finalizers may only
 * narrow facts invalidated by delivered units or objective markup; they never
 * make a fresh aesthetic choice.
 */
final class AboveFoldContract
{
    public const VERSION = 1;
    public const HEADER_ARCHETYPE_ENV = 'HEADER_ARCHETYPE';
    public const PHASE_DELIVERY = 'delivery';
    public const PHASE_FINAL = 'final';
    public const MODE_STACKED = 'stacked';
    public const MODE_OVERLAY = 'overlay';

    /** Header catalog retained from the pre-contract implementation. */
    public const HEADER_ARCHETYPES = [
        'standard-row',
        'centered-masthead',
        'minimal-overlay',
        'oversized-wordmark',
        'branded-lockup',
        'split-nav',
    ];

    /**
     * Reject an operator override only when caller-owned facts already prove
     * it impossible. Generated canvas, recipe, plan, opening, and delivered
     * page drift remain resolve()/finalizer degradations so a paid-for build
     * can continue.
     *
     * `$definitivePageCount` is null when the caller left page scope to the
     * model; generated site-spec/page-plan counts must not be passed here.
     *
     * @param array<string,mixed> $designConstraints
     */
    public static function validateHeaderArchetypePreflight(
        ?string $forcedHeaderArchetype,
        array $designConstraints = [],
        ?int $definitivePageCount = null,
    ): void {
        $forced = trim((string) ($forcedHeaderArchetype ?? ''));
        if ($forced === '') {
            return;
        }
        if (!in_array($forced, self::HEADER_ARCHETYPES, true)) {
            throw new \InvalidArgumentException(
                "unknown HEADER_ARCHETYPE '{$forced}' (use one of: "
                . implode(', ', self::HEADER_ARCHETYPES) . ')'
            );
        }
        if ($definitivePageCount !== null && $definitivePageCount < 1) {
            throw new \InvalidArgumentException('definitive header page count must be at least 1');
        }
        if ($forced === 'split-nav' && $definitivePageCount === 1) {
            throw new \InvalidArgumentException(
                "HEADER_ARCHETYPE='split-nav' is incompatible with the caller-owned one-page scope"
            );
        }
        if ($forced !== 'minimal-overlay') {
            return;
        }

        $constraints = HeroComposition::validateConstraints($designConstraints);
        if (($constraints['hero_canvas'] ?? null) === 'framed') {
            throw new \InvalidArgumentException(
                "HEADER_ARCHETYPE='minimal-overlay' is incompatible with "
                . "caller-owned design_constraints.hero_canvas='framed'"
            );
        }
        $overlayPossible = false;
        foreach (HeroComposition::compatible($constraints) as $recipe) {
            $metadata = HeroComposition::metadata($recipe);
            if (in_array(self::MODE_OVERLAY, (array) ($metadata['header_modes'] ?? []), true)
                && in_array('cover-image', (array) ($metadata['media_modes'] ?? []), true)
            ) {
                $overlayPossible = true;
                break;
            }
        }
        if (!$overlayPossible) {
            throw new \InvalidArgumentException(
                "HEADER_ARCHETYPE='minimal-overlay' is incompatible with caller-owned "
                . 'design_constraints because no compatible cover/overlay hero recipe remains'
            );
        }
    }

    /**
     * Resolve the initial delivery contract from complete, reconciled plans.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $themeContext raw theme.json or {base,contrast}
     * @param array<string,mixed> $siteContext stable_id, writing_direction, page_count
     * @param array<string,mixed> $footerContext archetype and surface
     * @param ?string $designCss design/site.css bytes; the design's own
     *                           `header` rule is the only authored evidence
     *                           of the stacked surface. Null on the legacy
     *                           path, which has no design stylesheet.
     * @return array<string,mixed>
     */
    public static function resolve(
        array $pages,
        array $blueprint,
        string $canvas,
        array $themeContext,
        array $siteContext,
        array $footerContext,
        ?string $forcedHeaderArchetype = null,
        ?string $designCss = null,
    ): array {
        $pages = self::pages($pages);
        $front = self::frontPage($pages);
        $sections = self::sections($front);
        $hero = $sections[0];
        $recipe = trim((string) ($blueprint['recipe'] ?? ''));
        HeroComposition::assertKnown($recipe);
        $recipeMeta = HeroComposition::metadata($recipe);

        $writingDirection = strtolower(trim((string) ($siteContext['writing_direction'] ?? 'ltr')));
        if (!in_array($writingDirection, ['ltr', 'rtl'], true)) {
            throw new \InvalidArgumentException("above-fold writing_direction must be ltr or rtl");
        }

        $tokens = self::themeTokens($themeContext);
        $openings = [];
        foreach ($pages as $page) {
            $opening = self::sections($page)[0];
            $surface = trim((string) ($opening['background'] ?? 'base'));
            $openings[] = [
                'page' => (string) ($page['slug'] ?? ''),
                'section' => (string) ($opening['slug'] ?? ''),
                'part' => self::partKey($page, $opening),
                'surface' => $surface,
                'top_protection_token' => in_array($surface, ['image', 'contrast'], true)
                    ? 'contrast'
                    : null,
            ];
        }

        $frontSurface = trim((string) ($hero['background'] ?? 'base'));
        // The blueprint's media mode and the delivered surface are objective
        // image facts. A generic layout name is not: a reviewed solid fallback
        // may retain the same layout archetype without putting pixels beneath
        // the header.
        $imageLed = (string) ($blueprint['media_mode'] ?? '') === 'cover-image'
            || $frontSurface === 'image';
        $recipeHeaderModes = is_array($recipeMeta['header_modes'] ?? null)
            ? $recipeMeta['header_modes']
            : [self::MODE_STACKED];
        // A solid interior opening only supports overlay when it IS the
        // prospective protection surface — the luminance-ordered dark token,
        // not a name-fixed 'contrast'.
        [$overlayForeground, $overlayProtection] = self::overlayTokenRoles($tokens);
        $overlaySupported = $imageLed
            && $canvas !== 'framed'
            && in_array(self::MODE_OVERLAY, $recipeHeaderModes, true)
            && self::tokensContrast($tokens)
            && array_reduce(
                $openings,
                static fn (bool $ok, array $opening): bool => $ok
                    && in_array($opening['surface'], ['image', $overlayProtection], true),
                true,
            );
        $mode = $overlaySupported ? self::MODE_OVERLAY : self::MODE_STACKED;

        $degradations = [];
        $forced = trim((string) ($forcedHeaderArchetype ?? ''));
        if ($forced !== '' && !in_array($forced, self::HEADER_ARCHETYPES, true)) {
            throw new \InvalidArgumentException(
                "unknown header archetype '{$forced}' (use one of: "
                . implode(', ', self::HEADER_ARCHETYPES) . ')'
            );
        }

        $pool = self::headerPool($mode);
        if ($forced !== '' && self::forcedHeaderCompatible($forced, $overlaySupported, count($pages), $imageLed)) {
            $archetype = $forced;
            $mode = $forced === 'minimal-overlay' ? self::MODE_OVERLAY : self::MODE_STACKED;
        } elseif ($forced !== '') {
            $archetype = 'standard-row';
            $mode = self::MODE_STACKED;
            $degradations[] = self::degradation(
                'forced-header-degraded',
                'aboveFold.json',
                'header.archetype',
                $forced,
                $archetype,
                'generated page/direction facts made the requested header relation unsafe; delivered the reviewed stacked relation',
            );
        } else {
            $archetype = self::stablePick($pool, [
                $siteContext['stable_id'] ?? '',
                count($pages),
                $recipe,
                array_map(static fn (array $o): array => [$o['page'], $o['surface']], $openings),
            ]);
        }

        if ($archetype === 'minimal-overlay') {
            $mode = self::MODE_OVERLAY;
        }
        $tagline = trim((string) ($siteContext['tagline'] ?? ''));
        // An overlay header floats over the opening image, so its pair is
        // owned by the luminance ordering above; only a stacked header paints
        // a band of its own, and the design's `header` rule is the authored
        // evidence of what colour that band is.
        [$stackedForeground, $stackedProtection] = self::stackedTokenRoles($designCss, $themeContext);
        $foreground = $mode === self::MODE_OVERLAY ? $overlayForeground : $stackedForeground;
        $protection = $mode === self::MODE_OVERLAY ? $overlayProtection : $stackedProtection;
        foreach ($openings as &$opening) {
            $opening['top_protection_token'] = $opening['surface'] === 'image'
                || $opening['surface'] === $protection
                    ? $protection
                    : null;
        }
        unset($opening);

        $following = $sections[1] ?? null;
        $action = self::action($hero['primary_action'] ?? null, $blueprint);
        $textSafe = self::region((string) ($blueprint['text_safe_region'] ?? 'start'), $writingDirection);
        $focal = self::region((string) ($blueprint['focal_region'] ?? 'none'), $writingDirection);
        $footerArchetype = trim((string) ($footerContext['archetype'] ?? ''));
        $footerSurface = trim((string) ($footerContext['surface'] ?? 'base'));

        return [
            'version' => self::VERSION,
            'phase' => self::PHASE_DELIVERY,
            'front_page' => (string) ($front['slug'] ?? ''),
            'hero_section' => (string) ($hero['slug'] ?? ''),
            'hero_part' => self::partKey($front, $hero),
            'following_section' => is_array($following) ? [
                'slug' => (string) ($following['slug'] ?? ''),
                'part' => self::partKey($front, $following),
                'layout_archetype' => (string) ($following['layout_archetype'] ?? ''),
                'surface' => (string) ($following['background'] ?? 'base'),
            ] : null,
            'openings' => $openings,
            'recipe' => $recipe,
            'writing_direction' => $writingDirection,
            'header' => [
                'mode' => $mode,
                'archetype' => $archetype,
                'foreground_token' => $foreground,
                'protection_token' => $protection,
                'protection_orientation' => 'top-edge',
                'protect_top_edge' => $mode === self::MODE_OVERLAY,
                'safe_top_px' => $mode === self::MODE_OVERLAY ? 80 : 0,
            ] + self::headerTextFacts($archetype, $tagline),
            'viewport' => [
                'height_profile' => (string) ($blueprint['height_profile'] ?? 'standard'),
                'headline_register' => (string) ($blueprint['headline_register'] ?? 'display'),
                'headline_line_target' => $blueprint['headline_line_target'] ?? [
                    'desktop' => [1, 3],
                    'mobile' => [2, 5],
                ],
                'stacked_cover_max_vh' => $mode === self::MODE_STACKED ? 80 : null,
            ],
            'regions' => [
                'text_safe' => $textSafe,
                'focal' => $focal,
            ],
            'mobile_transformation' => (string) ($blueprint['mobile_transformation'] ?? 'stack-copy-first'),
            // Carried for the same reason as mobile_transformation: the build
            // stamps a root class from it at finalization, and by then the
            // blueprint itself is no longer in hand (BIGR-925).
            'media_aspect' => self::mediaAspect($blueprint, $recipe),
            'primary_action' => $action,
            'seam' => [
                'following_kind' => is_array($following) ? 'section' : 'footer',
                'surface' => is_array($following)
                    ? (string) ($following['background'] ?? 'base')
                    : $footerSurface,
                'footer_archetype' => $footerArchetype,
                'footer_surface' => $footerSurface,
            ],
            'ownership' => [
                'header' => ['identity', 'navigation'],
                'hero' => $action === null
                    ? ['proposition', 'emotional-focus']
                    : ['proposition', 'emotional-focus', 'primary-action'],
                'following' => is_array($following) ? ['detail', 'proof'] : [],
            ],
            'delivered' => [
                'page_count' => count($pages),
                'part_keys' => [],
            ],
            'theme_tokens' => $tokens,
            // Kept so a later degradation to the stacked relation restores the
            // surface the design authored. Page count and lost overlay support
            // invalidate the header's SHAPE; they say nothing about its colour.
            'stacked_pair' => ['foreground' => $stackedForeground, 'protection' => $stackedProtection],
            'degradations' => $degradations,
        ];
    }

    /**
     * Narrow the contract after generated units, fallbacks, and pruning.
     *
     * @param array<int,array<string,mixed>> $deliveredPages
     * @param array<string,mixed> $facts normalized delivery facts
     * @return array<string,mixed>
     */
    public static function finalizeDelivery(array $initial, array $deliveredPages, array $facts): array
    {
        self::assertPhase($initial, self::PHASE_DELIVERY);
        $contract = $initial;
        $pages = self::pages($deliveredPages);
        $partKeys = array_values(array_filter(
            is_array($facts['part_keys'] ?? null) ? $facts['part_keys'] : [],
            'is_string',
        ));
        $partKeys = array_values(array_unique($partKeys));
        sort($partKeys, SORT_STRING);
        $contract['delivered'] = [
            'page_count' => count($pages),
            'part_keys' => $partKeys,
        ];

        $contract = self::refreshDeliveredFacts($contract, $pages, $facts);
        if (($contract['header']['archetype'] ?? '') === 'split-nav' && count($pages) <= 1) {
            $contract = self::degradeHeader(
                $contract,
                'split-nav-page-count',
                'header.archetype',
                'split-nav',
                'standard-row',
                'delivered page count no longer supports two navigation groups; delivered the reviewed stacked row',
            );
        }

        if (($contract['header']['mode'] ?? '') === self::MODE_OVERLAY) {
            $support = is_array($facts['opening_overlay_support'] ?? null)
                ? $facts['opening_overlay_support']
                : [];
            foreach ((array) ($contract['openings'] ?? []) as $opening) {
                $part = (string) ($opening['part'] ?? '');
                if (($support[$part] ?? false) !== true) {
                    $contract = self::degradeHeader(
                        $contract,
                        'overlay-support-lost',
                        'header.mode',
                        self::MODE_OVERLAY,
                        self::MODE_STACKED,
                        "delivered opening '{$part}' no longer guarantees the assigned top-edge protection; "
                            . 'header relation degraded to the reviewed stacked row',
                    );
                    break;
                }
            }
        }

        // Header degradation changes the protection token. Refresh the
        // delivered openings once more so the first finalized contract is
        // already the fixed point a subsequent independent finalizer sees.
        $contract = self::refreshDeliveredFacts($contract, $pages, $facts);

        if (($contract['primary_action'] ?? null) !== null
            && ($facts['primary_action_delivered'] ?? false) !== true
        ) {
            $authored = $contract['primary_action'];
            $targetLost = array_key_exists('primary_action_target_delivered', $facts)
                && $facts['primary_action_target_delivered'] !== true;
            $contract['primary_action'] = null;
            $contract['ownership']['hero'] = array_values(array_diff(
                (array) ($contract['ownership']['hero'] ?? []),
                ['primary-action'],
            ));
            $contract['degradations'][] = self::degradation(
                $targetLost ? 'primary-action-target-lost' : 'primary-action-not-delivered',
                'aboveFold.json',
                'primary_action',
                $authored,
                null,
                $targetLost
                    ? 'delivered pages/part anchors no longer resolve the authoritative destination; '
                        . 'the whole action was removed instead of delivering a dead control'
                    : 'generated/fallback hero did not preserve the authoritative action; '
                        . 'contract presence was removed instead of claiming dead UI',
            );
        }

        $contract['phase'] = self::PHASE_DELIVERY;
        $contract['degradations'] = self::uniqueDegradations((array) ($contract['degradations'] ?? []));
        return $contract;
    }

    /** Final objective check after rhythm/layout normalization. */
    public static function finalizeMarkup(array $delivery, array $deliveredPages, array $facts): array
    {
        self::assertPhase($delivery, self::PHASE_DELIVERY);
        $contract = self::finalizeDelivery($delivery, $deliveredPages, $facts);
        $headerFacts = is_array($facts['header'] ?? null) ? $facts['header'] : [];
        if (($contract['header']['displays_tagline'] ?? false) === true
            && (($headerFacts['site_tagline_blocks'] ?? 0) !== 1
                || ($headerFacts['malformed_site_tagline_blocks'] ?? 0) !== 0
                || ($headerFacts['invalid_site_tagline_topology'] ?? 0) !== 0)
        ) {
            $authored = $contract['header']['tagline_text'] ?? null;
            $contract['header']['displays_tagline'] = false;
            $contract['header']['tagline_text'] = null;
            $contract['header']['text_rows'] = 1;
            $contract['degradations'][] = self::degradation(
                'header-tagline-not-delivered',
                'theme/parts/header.html',
                'wp:site-tagline',
                $authored,
                null,
                'the finalized header does not contain exactly one structurally complete wp:site-tagline block; '
                    . 'the delivered text-shape facts were narrowed instead of claiming an identity row count '
                    . 'that visitors do not receive',
            );
            $contract['degradations'] = self::uniqueDegradations((array) $contract['degradations']);
        }
        if (($contract['header']['displays_tagline'] ?? false) !== true
            && (($headerFacts['site_tagline_blocks'] ?? 0) > 0
                || ($headerFacts['malformed_site_tagline_blocks'] ?? 0) > 0)
        ) {
            $contract['degradations'][] = self::degradation(
                'header-tagline-unplanned-delivery',
                'theme/parts/header.html',
                'wp:site-tagline',
                false,
                [
                    'complete_blocks' => $headerFacts['site_tagline_blocks'] ?? 0,
                    'malformed_blocks' => $headerFacts['malformed_site_tagline_blocks'] ?? 0,
                ],
                'the finalized header retained dynamic tagline markup even though the authoritative contract '
                    . 'does not display one; contract facts remain narrowed and the residual block was queued '
                    . 'for isolated removal',
            );
            $contract['degradations'] = self::uniqueDegradations((array) $contract['degradations']);
        }
        $contract['phase'] = self::PHASE_FINAL;
        return $contract;
    }

    /** Canonical serialization shared byte-for-byte by HeaderUnit and HeroUnit. */
    public static function frontContract(array $contract): string
    {
        self::assertContract($contract);
        $copy = $contract;
        unset($copy['theme_tokens'], $copy['stacked_pair'], $copy['degradations'], $copy['delivered']);
        return "ABOVE-FOLD CONTRACT (authoritative; front page only):\n"
            . self::encode($copy);
    }

    /** Header-facing subset for an interior opening; deliberately recipe-free. */
    public static function openingHeaderContract(array $contract, string $pageSlug): string
    {
        self::assertContract($contract);
        $pageSlug = trim($pageSlug);
        $opening = null;
        foreach ((array) ($contract['openings'] ?? []) as $candidate) {
            if ((string) ($candidate['page'] ?? '') === $pageSlug) {
                $opening = $candidate;
                break;
            }
        }
        if ($pageSlug === '' || !is_array($opening)) {
            throw new \InvalidArgumentException(
                "above-fold contract has no delivered opening for page '{$pageSlug}'"
            );
        }
        return "GLOBAL HEADER CONTRACT (this page opening only):\n" . self::encode([
            'version' => $contract['version'],
            'page' => $pageSlug,
            'writing_direction' => $contract['writing_direction'],
            'header' => $contract['header'],
            'viewport' => [
                'stacked_cover_max_vh' => $contract['viewport']['stacked_cover_max_vh'] ?? null,
            ],
            'opening' => $opening,
            'ownership' => ['header' => ['identity', 'navigation'], 'opening' => ['page-orientation']],
        ]);
    }

    /** Reject a consumer reading the wrong artifact phase. */
    public static function assertPhase(array $contract, string $phase): void
    {
        self::assertContract($contract);
        if (($contract['phase'] ?? null) !== $phase) {
            throw new \RuntimeException(
                "aboveFold.json phase must be '{$phase}', got '" . (string) ($contract['phase'] ?? '') . "'"
            );
        }
    }

    /** Actionable durable warning rows for any newly added degradation. */
    public static function warningRows(array $contract, int $offset = 0): array
    {
        $rows = array_slice((array) ($contract['degradations'] ?? []), $offset);
        return array_map(static function (array $row): string {
            return 'above-fold: code="' . (string) ($row['code'] ?? 'above-fold-degradation')
                . '"; file=\'' . (string) ($row['file'] ?? 'aboveFold.json')
                . "'; path=\"" . (string) ($row['path'] ?? '') . '"; authored='
                . self::value($row['authored'] ?? null) . '; delivered='
                . self::value($row['delivered'] ?? null) . '; disposition='
                . (string) ($row['disposition'] ?? 'degraded and continued');
        }, array_values(array_filter($rows, 'is_array')));
    }

    /** @return array<string,array{token:string,hex:string}> */
    public static function themeTokens(array $theme): array
    {
        $palette = $theme['settings']['color']['palette'] ?? ($theme['palette'] ?? $theme);
        $out = [];
        foreach (is_array($palette) ? $palette : [] as $key => $entry) {
            if (is_array($entry)) {
                $slug = (string) ($entry['slug'] ?? $key);
                $hex = strtoupper(trim((string) ($entry['color'] ?? $entry['hex'] ?? '')));
            } else {
                $slug = (string) $key;
                $hex = strtoupper(trim((string) $entry));
            }
            if (in_array($slug, ['base', 'contrast'], true) && ContrastMath::hexToRgb($hex) !== null) {
                $out[$slug] = ['token' => $slug, 'hex' => $hex];
            }
        }
        $out['base'] ??= ['token' => 'base', 'hex' => '#FFFFFF'];
        $out['contrast'] ??= ['token' => 'contrast', 'hex' => '#111111'];
        return $out;
    }

    /** @param array<string,mixed> $page @param array<string,mixed> $section */
    private static function partKey(array $page, array $section): string
    {
        return 'page-' . trim((string) ($page['slug'] ?? ''))
            . '--' . trim((string) ($section['slug'] ?? ''));
    }

    /** @return array<int,array<string,mixed>> */
    private static function pages(array $pages): array
    {
        $pages = array_values(array_filter($pages, 'is_array'));
        if ($pages === []) {
            throw new \InvalidArgumentException('above-fold requires at least one delivered page');
        }
        foreach ($pages as $page) {
            self::sections($page);
        }
        return $pages;
    }

    /** @param array<string,mixed> $page @return array<int,array<string,mixed>> */
    private static function sections(array $page): array
    {
        $sections = array_values(array_filter((array) ($page['sections'] ?? []), 'is_array'));
        if ($sections === []) {
            throw new \InvalidArgumentException(
                "above-fold page '" . (string) ($page['slug'] ?? '') . "' has no opening section"
            );
        }
        return $sections;
    }

    /** @param array<int,array<string,mixed>> $pages @return array<string,mixed> */
    private static function frontPage(array $pages): array
    {
        foreach ($pages as $page) {
            if (($page['front'] ?? false) === true) {
                return $page;
            }
        }
        return $pages[0];
    }

    /**
     * The blueprint's committed media aspect, held to what the recipe can
     * actually serve. A blueprint naming an aspect its recipe has no slot for
     * falls back to the recipe default, the same resolution HeroBlueprint's
     * own normalize step applies (BIGR-925).
     *
     * @param array<string,mixed> $blueprint
     */
    private static function mediaAspect(array $blueprint, string $recipe): string
    {
        $meta = HeroComposition::metadata($recipe);
        $committed = is_string($blueprint['media_aspect'] ?? null)
            ? trim($blueprint['media_aspect'])
            : '';
        return in_array($committed, (array) $meta['media_aspects'], true)
            ? $committed
            : (string) $meta['defaults']['media_aspect'];
    }

    /**
     * Canonical header text-shape facts (BIGR-773), shared byte-for-byte by
     * both above-fold authors. `displays_tagline` is true only when the
     * archetype's catalog form includes wp:site-tagline AND a stated tagline
     * exists to render (an empty tagline leaves a title-only form — the
     * blank block is stripped deterministically). The tagline-bearing forms
     * are branded-lockup and standard-row (BIGR-775): the hero no longer
     * carries an eyebrow, so the orientation micro-copy that used to live
     * there renders as the header tagline instead. `text_rows` counts the
     * header's stacked text lines: a two-row header bans the hero eyebrow,
     * because a third caption-scale line ~100px below reads as a masthead
     * row, not hero copy.
     *
     * @return array{displays_tagline:bool,tagline_text:?string,text_rows:int}
     */
    private static function headerTextFacts(string $archetype, string $tagline): array
    {
        $displays = in_array($archetype, ['branded-lockup', 'standard-row'], true) && $tagline !== '';
        return [
            'displays_tagline' => $displays,
            'tagline_text' => $displays ? $tagline : null,
            'text_rows' => $displays || $archetype === 'centered-masthead' ? 2 : 1,
        ];
    }

    /** @return list<string> */
    private static function headerPool(string $mode): array
    {
        if ($mode === self::MODE_OVERLAY) {
            return ['minimal-overlay'];
        }
        // centered-masthead and split-nav are retired from auto-assignment
        // (BIGR-872). Forced HEADER_ARCHETYPE can still pick them through
        // forcedHeaderCompatible().
        $excluded = [
            'minimal-overlay',
            'oversized-wordmark',
            'centered-masthead',
            'split-nav',
        ];
        return array_values(array_diff(self::HEADER_ARCHETYPES, $excluded));
    }

    private static function forcedHeaderCompatible(
        string $archetype,
        bool $overlaySupported,
        int $pageCount,
        bool $imageLed,
    ): bool
    {
        if ($archetype === 'minimal-overlay') {
            return $overlaySupported;
        }
        if ($archetype === 'split-nav' && $pageCount <= 1) {
            return false;
        }
        if ($archetype === 'centered-masthead' && $imageLed) {
            return false;
        }
        // The initial production catalog conservatively excludes this when a
        // front hero exists. resolve() always has one by construction.
        if ($archetype === 'oversized-wordmark') {
            return false;
        }
        // A compatible non-overlay override chooses its exact stacked
        // relation even when the automatic assignment could have overlaid.
        return true;
    }

    /** @param list<string> $pool */
    private static function stablePick(array $pool, array $seed): string
    {
        if ($pool === []) {
            throw new \RuntimeException('above-fold header compatibility pool is empty');
        }
        sort($pool, SORT_STRING);
        $hash = hash('sha256', self::encode($seed), true);
        $bucket = 0;
        foreach (str_split($hash) as $byte) {
            $bucket = (($bucket * 256) + ord($byte)) % count($pool);
        }
        return $pool[$bucket];
    }

    /** @return array{logical:string,physical:?string} */
    private static function region(string $logical, string $writingDirection): array
    {
        $logical = in_array($logical, ['start', 'center', 'end', 'full', 'none'], true)
            ? $logical
            : 'center';
        $physical = match ($logical) {
            'start' => $writingDirection === 'rtl' ? 'right' : 'left',
            'end' => $writingDirection === 'rtl' ? 'left' : 'right',
            'center' => 'center',
            'full' => 'full',
            default => null,
        };
        return ['logical' => $logical, 'physical' => $physical];
    }

    /**
     * The overlay pair is luminance-ordered, not name-ordered: the header
     * text sits on a dimmed image, so the foreground must be whichever of
     * the two verified tokens is lighter and the protection dim whichever
     * is darker. Light themes keep the historical base/contrast assignment;
     * a dark theme's inverted palette previously demanded a light scrim
     * over the image that no hero author could sensibly deliver.
     *
     * @param array<string,mixed> $tokens
     * @return array{0:string,1:string} [foreground, protection]
     */
    private static function overlayTokenRoles(array $tokens): array
    {
        $base = ContrastMath::hexToRgb((string) ($tokens['base']['hex'] ?? ''));
        $contrast = ContrastMath::hexToRgb((string) ($tokens['contrast']['hex'] ?? ''));
        if ($base === null || $contrast === null
            || ContrastMath::luminance($base) >= ContrastMath::luminance($contrast)) {
            return ['base', 'contrast'];
        }
        return ['contrast', 'base'];
    }

    /**
     * The stacked pair, taken from the design's own `header` rule wherever
     * the palette already carries the authored colour. Each side falls back
     * to the reviewed literal on its own, because a design may author only a
     * background.
     *
     * The two sides cannot be adopted in isolation, though: a design whose
     * background the palette does not carry but whose ink it does would pair
     * the reviewed surface with ink of that same colour, leaving invisible
     * header text. calm-lantern is exactly that shape — `#2E0B5A` matches no
     * slug while its `#fff` matches `base` — so a collided pair yields to the
     * reviewed default, and the design's ink hint is discarded along with the
     * surface it was chosen for.
     *
     * An unauthored ink resolves against the surface actually chosen rather
     * than to a fixed `contrast`. On the reviewed `base` surface that still
     * picks `contrast`, so every design that derives no surface keeps its
     * present pair exactly; on a derived surface it stops the contract
     * advertising an ink that `opaquePairWithSafety()` will then refuse to
     * deliver — a mismatch the header author also reads through
     * openingHeaderContract().
     *
     * @param array<string,mixed> $themeContext
     * @return array{0:string,1:string} [foreground, protection]
     */
    private static function stackedTokenRoles(?string $designCss, array $themeContext): array
    {
        $palette = self::paletteMap($themeContext);
        $pair = DesignHeaderSurface::stackedPair($designCss, $palette);
        $protection = $pair['protection'] ?? 'base';
        $foreground = $pair['foreground'];
        // A design that stated a surface the palette cannot carry also stated
        // its ink against that surface. Keeping the ink while painting a
        // different band is the mismatch readableInk() exists to avoid, so the
        // hint goes with the surface it was chosen for.
        if ($pair['authored_background'] && $pair['protection'] === null) {
            $foreground = null;
        }
        if ($foreground === null || $foreground === $protection) {
            $foreground = self::readableInk($protection, $palette);
        }
        return [$foreground, $protection];
    }

    /**
     * Whichever reviewed token reads better on this surface. The reviewed
     * `base` surface, an unreadable palette, and a tie all keep `contrast`,
     * which is the historical answer.
     *
     * @param array<string,string> $palette
     */
    private static function readableInk(string $protection, array $palette): string
    {
        if ($protection === 'contrast') {
            return 'base';
        }
        $surface = ContrastMath::hexToRgb($palette[$protection] ?? '');
        $base = ContrastMath::hexToRgb($palette['base'] ?? '');
        $contrast = ContrastMath::hexToRgb($palette['contrast'] ?? '');
        if ($protection === 'base' || $surface === null || $base === null || $contrast === null) {
            return 'contrast';
        }
        return ContrastMath::ratio($base, $surface) > ContrastMath::ratio($contrast, $surface)
            ? 'base'
            : 'contrast';
    }

    /**
     * Every concrete palette slug, unlike themeTokens() which deliberately
     * narrows to the verified base/contrast pair it persists.
     *
     * @param array<string,mixed> $theme
     * @return array<string,string> slug => hex
     */
    private static function paletteMap(array $theme): array
    {
        $palette = $theme['settings']['color']['palette'] ?? ($theme['palette'] ?? $theme);
        $out = [];
        foreach (is_array($palette) ? $palette : [] as $key => $entry) {
            if (is_array($entry)) {
                $slug = (string) ($entry['slug'] ?? $key);
                $hex = (string) ($entry['color'] ?? $entry['hex'] ?? '');
            } else {
                $slug = (string) $key;
                $hex = (string) $entry;
            }
            if ($slug !== '' && ContrastMath::hexToRgb($hex) !== null) {
                $out[$slug] = strtoupper(trim($hex));
            }
        }
        return $out;
    }

    private static function tokensContrast(array $tokens): bool
    {
        $base = ContrastMath::hexToRgb((string) ($tokens['base']['hex'] ?? ''));
        $contrast = ContrastMath::hexToRgb((string) ($tokens['contrast']['hex'] ?? ''));
        return $base !== null && $contrast !== null
            && ContrastMath::ratio($base, $contrast) >= ContrastMath::NORMAL_TEXT;
    }

    private static function action(mixed $action, array $blueprint): ?array
    {
        if (!is_array($action)) {
            return null;
        }
        foreach (['label', 'intent', 'destination'] as $key) {
            if (!is_string($action[$key] ?? null) || trim($action[$key]) === '') {
                return null;
            }
        }
        return [
            'label' => $action['label'],
            'intent' => $action['intent'],
            'destination' => $action['destination'],
            'treatment' => (string) ($blueprint['cta_treatment'] ?? 'prominent'),
        ];
    }

    /**
     * Refresh only facts whose delivered page/part identity can invalidate the
     * initial assignment. No aesthetic selection occurs here.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,mixed> $facts
     */
    private static function refreshDeliveredFacts(array $contract, array $pages, array $facts): array
    {
        $front = self::frontPage($pages);
        $sections = self::sections($front);
        $hero = $sections[0];
        $following = $sections[1] ?? null;
        $surfaces = is_array($facts['opening_surfaces'] ?? null)
            ? $facts['opening_surfaces']
            : [];
        $protection = (string) ($contract['header']['protection_token'] ?? 'contrast');
        $openings = [];
        foreach ($pages as $page) {
            $opening = self::sections($page)[0];
            $part = self::partKey($page, $opening);
            $surface = is_string($surfaces[$part] ?? null) && trim($surfaces[$part]) !== ''
                ? trim($surfaces[$part])
                : trim((string) ($opening['background'] ?? 'base'));
            $openings[] = [
                'page' => (string) ($page['slug'] ?? ''),
                'section' => (string) ($opening['slug'] ?? ''),
                'part' => $part,
                'surface' => $surface,
                'top_protection_token' => $surface === 'image' || $surface === $protection
                    ? $protection
                    : null,
            ];
        }

        $contract['front_page'] = (string) ($front['slug'] ?? '');
        $contract['hero_section'] = (string) ($hero['slug'] ?? '');
        $contract['hero_part'] = self::partKey($front, $hero);
        $contract['openings'] = $openings;
        $contract['following_section'] = is_array($following) ? [
            'slug' => (string) ($following['slug'] ?? ''),
            'part' => self::partKey($front, $following),
            'layout_archetype' => (string) ($following['layout_archetype'] ?? ''),
            'surface' => (string) ($following['background'] ?? 'base'),
        ] : null;
        $contract['seam']['following_kind'] = is_array($following) ? 'section' : 'footer';
        $contract['seam']['surface'] = is_array($following)
            ? (string) ($following['background'] ?? 'base')
            : (string) ($contract['seam']['footer_surface'] ?? 'base');
        $contract['ownership']['following'] = is_array($following) ? ['detail', 'proof'] : [];
        return $contract;
    }

    private static function degradeHeader(
        array $contract,
        string $code,
        string $path,
        mixed $authored,
        mixed $delivered,
        string $disposition,
    ): array {
        $stacked = is_array($contract['stacked_pair'] ?? null) ? $contract['stacked_pair'] : [];
        $contract['header'] = [
            'mode' => self::MODE_STACKED,
            'archetype' => 'standard-row',
            'foreground_token' => (string) ($stacked['foreground'] ?? 'contrast'),
            'protection_token' => (string) ($stacked['protection'] ?? 'base'),
            'protection_orientation' => 'top-edge',
            'protect_top_edge' => false,
            'safe_top_px' => 0,
        ] + self::headerTextFacts('standard-row', '');
        $contract['viewport']['stacked_cover_max_vh'] = 80;
        $contract['degradations'][] = self::degradation(
            $code,
            'aboveFold.json',
            $path,
            $authored,
            $delivered,
            $disposition,
        );
        return $contract;
    }

    /** @return array<string,mixed> */
    private static function degradation(
        string $code,
        string $file,
        string $path,
        mixed $authored,
        mixed $delivered,
        string $disposition,
    ): array {
        return compact('code', 'file', 'path', 'authored', 'delivered', 'disposition');
    }

    /** @param array<int,mixed> $rows @return array<int,array<string,mixed>> */
    private static function uniqueDegradations(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = self::encode($row);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $row;
            }
        }
        return $out;
    }

    private static function assertContract(array $contract): void
    {
        if (($contract['version'] ?? null) !== self::VERSION) {
            throw new \RuntimeException('aboveFold.json has an unsupported or missing version');
        }
        if (!in_array($contract['phase'] ?? null, [self::PHASE_DELIVERY, self::PHASE_FINAL], true)) {
            throw new \RuntimeException('aboveFold.json has an invalid phase');
        }
        if (!is_array($contract['header'] ?? null)) {
            throw new \RuntimeException('aboveFold.json has no header contract');
        }
        foreach (['front_page', 'hero_section', 'hero_part', 'recipe', 'writing_direction', 'mobile_transformation'] as $field) {
            if (!is_string($contract[$field] ?? null) || trim($contract[$field]) === '') {
                throw new \RuntimeException("aboveFold.json has an invalid or missing '{$field}'");
            }
        }
        if (!in_array($contract['recipe'], HeroComposition::RECIPES, true)) {
            throw new \RuntimeException("aboveFold.json has unknown recipe '{$contract['recipe']}'");
        }
        if (!in_array($contract['writing_direction'], ['ltr', 'rtl'], true)) {
            throw new \RuntimeException('aboveFold.json writing_direction must be ltr or rtl');
        }
        if (!in_array($contract['mobile_transformation'], HeroBlueprint::MOBILE_TRANSFORMATIONS, true)
            || !in_array(
                $contract['mobile_transformation'],
                HeroComposition::metadata($contract['recipe'])['mobile_transformations'],
                true,
            )
        ) {
            throw new \RuntimeException('aboveFold.json has an incompatible mobile transformation');
        }
        if (!in_array(
            $contract['media_aspect'] ?? null,
            HeroComposition::metadata($contract['recipe'])['media_aspects'],
            true,
        )) {
            throw new \RuntimeException('aboveFold.json has an incompatible media aspect');
        }

        $header = $contract['header'];
        if (!in_array($header['mode'] ?? null, [self::MODE_STACKED, self::MODE_OVERLAY], true)) {
            throw new \RuntimeException('aboveFold.json has an invalid header mode');
        }
        if (!in_array($header['archetype'] ?? null, self::HEADER_ARCHETYPES, true)) {
            throw new \RuntimeException('aboveFold.json has an invalid header archetype');
        }
        foreach (['foreground_token', 'protection_token'] as $field) {
            if (!is_string($header[$field] ?? null) || trim($header[$field]) === '') {
                throw new \RuntimeException("aboveFold.json has an invalid header.{$field}");
            }
        }
        if (($header['protection_orientation'] ?? null) !== 'top-edge'
            || !is_bool($header['protect_top_edge'] ?? null)
            || !is_int($header['safe_top_px'] ?? null)
        ) {
            throw new \RuntimeException('aboveFold.json has invalid header protection facts');
        }
        $overlay = $header['mode'] === self::MODE_OVERLAY;
        [$overlayForeground, $overlayProtection] = self::overlayTokenRoles(
            is_array($contract['theme_tokens'] ?? null) ? $contract['theme_tokens'] : [],
        );
        // An overlay pair stays fully derivable from the persisted tokens, so
        // it is still checked by value. A stacked pair is not: it comes from
        // the design's own `header` rule, which no consumer of aboveFold.json
        // holds. What remains objective is that both slugs are ones the
        // header kit can actually paint, and that they are distinct.
        $expectedHeader = $overlay
            ? [
                'archetype' => 'minimal-overlay',
                'foreground_token' => $overlayForeground,
                'protection_token' => $overlayProtection,
                'protect_top_edge' => true,
                'safe_top_px' => 80,
            ]
            : [
                'protect_top_edge' => false,
                'safe_top_px' => 0,
            ];
        foreach ($expectedHeader as $field => $expected) {
            if (($header[$field] ?? null) !== $expected) {
                throw new \RuntimeException("aboveFold.json has an incoherent header.{$field}");
            }
        }
        if (!$overlay) {
            foreach (['foreground_token', 'protection_token'] as $field) {
                if (!in_array($header[$field], HeaderBehavior::SURFACES, true)) {
                    throw new \RuntimeException(
                        "aboveFold.json has an unpaintable header.{$field} '{$header[$field]}'"
                    );
                }
            }
            if ($header['foreground_token'] === $header['protection_token']) {
                throw new \RuntimeException('aboveFold.json has an invisible stacked header pair');
            }
        }
        if (!$overlay && $header['archetype'] === 'minimal-overlay') {
            throw new \RuntimeException('aboveFold.json has an incoherent stacked header archetype');
        }

        $themeTokens = $contract['theme_tokens'] ?? null;
        if (!is_array($themeTokens)) {
            throw new \RuntimeException('aboveFold.json has no verified theme token pair');
        }
        foreach (['base', 'contrast'] as $token) {
            $entry = $themeTokens[$token] ?? null;
            if (!is_array($entry)
                || ($entry['token'] ?? null) !== $token
                || !is_string($entry['hex'] ?? null)
                || ContrastMath::hexToRgb($entry['hex']) === null
            ) {
                throw new \RuntimeException("aboveFold.json has an invalid theme_tokens.{$token}");
            }
        }
        if ($overlay && !self::tokensContrast($themeTokens)) {
            throw new \RuntimeException('aboveFold.json overlay token pair no longer provides verified contrast');
        }

        $openings = $contract['openings'] ?? null;
        if (!is_array($openings) || !array_is_list($openings) || $openings === []) {
            throw new \RuntimeException('aboveFold.json has no valid openings list');
        }
        foreach ($openings as $index => $opening) {
            if (!is_array($opening)) {
                throw new \RuntimeException("aboveFold.json openings[{$index}] is not an object");
            }
            foreach (['page', 'section', 'part', 'surface'] as $field) {
                if (!is_string($opening[$field] ?? null) || trim($opening[$field]) === '') {
                    throw new \RuntimeException("aboveFold.json has invalid openings[{$index}].{$field}");
                }
            }
            if (($opening['top_protection_token'] ?? null) !== null
                && (!is_string($opening['top_protection_token'])
                    || trim($opening['top_protection_token']) === '')
            ) {
                throw new \RuntimeException(
                    "aboveFold.json has invalid openings[{$index}].top_protection_token"
                );
            }
            $topToken = $opening['top_protection_token'] ?? null;
            if (($topToken !== null && $topToken !== $header['protection_token'])
                || ($overlay && $topToken !== $header['protection_token'])
            ) {
                throw new \RuntimeException(
                    "aboveFold.json has incoherent openings[{$index}].top_protection_token"
                );
            }
        }

        foreach (['viewport', 'regions', 'seam', 'ownership', 'delivered'] as $field) {
            if (!is_array($contract[$field] ?? null)) {
                throw new \RuntimeException("aboveFold.json has no valid '{$field}' contract");
            }
        }
        $stackedMax = $contract['viewport']['stacked_cover_max_vh'] ?? null;
        if (($header['mode'] === self::MODE_STACKED
                && (!is_int($stackedMax) || $stackedMax < 1 || $stackedMax > 100))
            || ($header['mode'] === self::MODE_OVERLAY && $stackedMax !== null)
        ) {
            throw new \RuntimeException('aboveFold.json has an invalid stacked cover viewport budget');
        }
        if (!is_int($contract['delivered']['page_count'] ?? null)
            || $contract['delivered']['page_count'] < 1
            || !is_array($contract['delivered']['part_keys'] ?? null)
            || !array_is_list($contract['delivered']['part_keys'])
            || array_filter(
                $contract['delivered']['part_keys'],
                static fn (mixed $part): bool => !is_string($part) || trim($part) === '',
            ) !== []
        ) {
            throw new \RuntimeException('aboveFold.json has invalid delivered part facts');
        }
        if (!is_array($contract['degradations'] ?? null) || !array_is_list($contract['degradations'])) {
            throw new \RuntimeException('aboveFold.json has invalid degradations');
        }

        if (!array_key_exists('primary_action', $contract)) {
            throw new \RuntimeException('aboveFold.json is missing primary_action');
        }
        $action = $contract['primary_action'];
        if ($action !== null) {
            if (!is_array($action)) {
                throw new \RuntimeException('aboveFold.json primary_action must be an object or null');
            }
            foreach (['label', 'intent', 'destination', 'treatment'] as $field) {
                if (!is_string($action[$field] ?? null) || trim($action[$field]) === '') {
                    throw new \RuntimeException("aboveFold.json has invalid primary_action.{$field}");
                }
            }
        }

        if (!array_key_exists('following_section', $contract)) {
            throw new \RuntimeException('aboveFold.json is missing following_section');
        }
        $following = $contract['following_section'];
        if ($following !== null) {
            if (!is_array($following)) {
                throw new \RuntimeException('aboveFold.json following_section must be an object or null');
            }
            foreach (['slug', 'part', 'layout_archetype', 'surface'] as $field) {
                if (!is_string($following[$field] ?? null) || trim($following[$field]) === '') {
                    throw new \RuntimeException("aboveFold.json has invalid following_section.{$field}");
                }
            }
        }
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private static function value(mixed $value): string
    {
        return $value === null ? 'null' : self::encode($value);
    }
}
