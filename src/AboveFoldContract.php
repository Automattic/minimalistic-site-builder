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
        $overlaySupported = $imageLed
            && $canvas !== 'framed'
            && in_array(self::MODE_OVERLAY, $recipeHeaderModes, true)
            && self::tokensContrast($tokens)
            && array_reduce(
                $openings,
                static fn (bool $ok, array $opening): bool => $ok
                    && in_array($opening['surface'], ['image', 'contrast'], true),
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

        $pool = self::headerPool($mode, count($pages), $imageLed);
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
        $foreground = $mode === self::MODE_OVERLAY ? 'base' : 'contrast';
        $protection = $mode === self::MODE_OVERLAY ? 'contrast' : 'base';
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
            ],
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
        $contract['phase'] = self::PHASE_FINAL;
        return $contract;
    }

    /** Canonical serialization shared byte-for-byte by HeaderUnit and HeroUnit. */
    public static function frontContract(array $contract): string
    {
        self::assertContract($contract);
        $copy = $contract;
        unset($copy['theme_tokens'], $copy['degradations'], $copy['delivered']);
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

    /** @return list<string> */
    private static function headerPool(
        string $mode,
        int $pageCount,
        bool $imageLed,
    ): array {
        if ($mode === self::MODE_OVERLAY) {
            return ['minimal-overlay'];
        }
        $excluded = ['minimal-overlay', 'oversized-wordmark'];
        if ($pageCount <= 1) {
            $excluded[] = 'split-nav';
        }
        if ($imageLed) {
            $excluded[] = 'centered-masthead';
        }
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
        $contract['header'] = [
            'mode' => self::MODE_STACKED,
            'archetype' => 'standard-row',
            'foreground_token' => 'contrast',
            'protection_token' => 'base',
            'protection_orientation' => 'top-edge',
            'protect_top_edge' => false,
            'safe_top_px' => 0,
        ];
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
        $expectedHeader = $overlay
            ? [
                'archetype' => 'minimal-overlay',
                'foreground_token' => 'base',
                'protection_token' => 'contrast',
                'protect_top_edge' => true,
                'safe_top_px' => 80,
            ]
            : [
                'foreground_token' => 'contrast',
                'protection_token' => 'base',
                'protect_top_edge' => false,
                'safe_top_px' => 0,
            ];
        foreach ($expectedHeader as $field => $expected) {
            if (($header[$field] ?? null) !== $expected) {
                throw new \RuntimeException("aboveFold.json has an incoherent header.{$field}");
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
