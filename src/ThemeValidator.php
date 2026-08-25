<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Structural validation of a generated theme. Catches the failure modes that
 * matter for a WordPress block theme: missing required files, invalid
 * theme.json, and unbalanced block-comment grammar in templates/parts.
 *
 * Returns a list of human-readable problems (empty = valid).
 */
final class ThemeValidator
{
    private const HTML_SRC_PATTERN =
        '/\bsrc\s*=\s*(?:(["\'])(.*?)\1|([^\s"\'=<>`]+))/is';

    /** Block attributes whose `url` is a destination, not a media source. */
    private const LINK_URL_BLOCKS = ['navigation-link', 'social-link', 'button'];

    /** Footer-capable blocks whose `url` is a media source. */
    private const MEDIA_URL_BLOCKS = ['image', 'cover', 'media-text'];

    /** @return string[] list of problems (empty means valid) */
    public static function validate(Project $project): array
    {
        $problems = [];

        // style.css with a theme header.
        if (!$project->exists('theme/style.css')) {
            $problems[] = 'missing theme/style.css';
        } elseif (!str_contains($project->readText('theme/style.css'), 'Theme Name:')) {
            $problems[] = 'style.css missing "Theme Name:" header';
        }

        // theme.json valid + version 3.
        if (!$project->exists('theme/theme.json')) {
            $problems[] = 'missing theme/theme.json';
        } else {
            $theme = json_decode($project->readText('theme/theme.json'), true);
            if (!is_array($theme)) {
                $problems[] = 'theme.json is not valid JSON';
            } elseif (($theme['version'] ?? null) !== 3) {
                $problems[] = 'theme.json version is not 3';
            }
        }

        // Required block files exist and have balanced block grammar. The
        // content plugin's page files carry the site's actual markup now, so
        // they get the same balance + placeholder checks when present.
        $required = [
            'theme/templates/index.html',
            'theme/templates/page.html',
            'theme/parts/header.html',
            'theme/parts/footer.html',
        ];
        $checked = $required;
        foreach (glob($project->pluginPath('pages') . '/*.html') ?: [] as $abs) {
            $checked[] = 'plugin/pages/' . basename($abs);
        }
        foreach (glob($project->themePath('patterns') . '/*.php') ?: [] as $abs) {
            $checked[] = 'theme/patterns/' . basename($abs);
        }

        $blockChecked = $checked;

        foreach ($blockChecked as $rel) {
            if (!$project->exists($rel)) {
                if (in_array($rel, $required, true)) {
                    $problems[] = "missing {$rel}";
                }
                continue;
            }
            $balanceProblem = self::blockBalance($project->readText($rel));
            if ($balanceProblem !== null) {
                $problems[] = "{$rel}: {$balanceProblem}";
            }
        }

        // Leftover unfilled placeholders anywhere in the generated markup.
        foreach ($checked as $rel) {
            if ($project->exists($rel) && preg_match('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', $project->readText($rel))) {
                $problems[] = "{$rel}: contains unfilled {{placeholder}}";
            }
        }

        // The model never authors form controls. Without a host behind them
        // they are dead UI that discards whatever visitors type; with one, the
        // form is the host's to render from a placeholder. Either way, raw
        // markup here means a section ignored its instructions.
        foreach ($checked as $rel) {
            if ($project->exists($rel) && preg_match('/<(form|input|textarea|select)\b/i', $project->readText($rel), $m)) {
                $problems[] = "{$rel}: contains form markup (<" . strtolower($m[1])
                    . '>) — sections never author form controls';
            }
        }

        // A form placeholder is a spec a host has to parse. The library owns
        // that grammar, so the library checks it: a malformed one is invisible
        // to every downstream step and ships as literal grey body text on the
        // page. Off the flag, the marker must not appear at all.
        foreach ($checked as $rel) {
            if ($project->exists($rel)) {
                foreach (self::formPlaceholderProblems($project, $rel) as $problem) {
                    $problems[] = $problem;
                }
            }
        }

        // Generated interactions must remain usable. The prompts promise real
        // destinations and populated utility lists, but only a scan of what
        // the model actually delivered can catch placeholder links or list
        // content lost during normalization.
        return array_merge(
            $problems,
            self::unresolvedImageSourceProblems($project),
            self::placeholderLinkProblems($project),
            self::placeholderMediaSourceProblems($project),
            self::linkProblems($project),
            self::emptyListProblems($project),
        );
    }

    /**
     * Advisory final check of the persisted above-fold contract after section
     * parts have been serialized into plugin pages. This method never mutates
     * generated markup. Residual drift is actionable repair input and must not
     * prevent delivery of an otherwise usable theme.
     *
     * `aboveFold.json` is a required upstream artifact for callers of this
     * method; missing/corrupt/wrong-phase input remains an artifact failure,
     * not a generated-content defect.
     *
     * @return list<string>
     */
    public static function aboveFoldWarnings(Project $project): array
    {
        $contract = $project->readJson('aboveFold.json');
        AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_FINAL);
        $pages = array_values(array_filter(
            (array) ($project->readJson('pages.json')['pages'] ?? []),
            'is_array',
        ));
        $warnings = [];
        $requiresClearOpeningProof = false;
        if ($project->exists(HeaderBehavior::FILE)) {
            try {
                $headerBehavior = $project->readJson(HeaderBehavior::FILE);
                if (is_array($headerBehavior)) {
                    $headerBehavior = HeaderBehavior::validateArtifact($headerBehavior);
                    $requiresClearOpeningProof = $headerBehavior['behavior'] === HeaderBehavior::OVERLAY_TO_SOLID
                        && $headerBehavior['topTreatment'] === HeaderBehavior::TREATMENT_TRANSPARENT;
                }
            } catch (\RuntimeException|\InvalidArgumentException) {
                // ValidateThemeStep records the malformed artifact itself. Do
                // not let that advisory defect abort this independent scan.
            }
        }

        $headerFile = 'theme/parts/header.html';
        if (!$project->exists($headerFile)) {
            $warnings[] = self::aboveFoldWarning(
                $headerFile,
                'header',
                $contract['header'] ?? null,
                'removed',
                'final header part is absent; retain the delivered site and regenerate the contract-owned header',
            );
        } else {
            try {
                $facts = AboveFoldPartFacts::headerFacts($project->readText($headerFile));
                foreach (['mode', 'archetype'] as $field) {
                    $expected = $contract['header'][$field] ?? null;
                    $delivered = $facts[$field] ?? null;
                    if ($expected !== $delivered) {
                        $warnings[] = self::aboveFoldWarning(
                            $headerFile,
                            "header.{$field}",
                            $expected,
                            $delivered,
                            'downstream markup drifted from the final above-fold relation; retain it and repair this exact header field',
                        );
                    }
                }
                $expectedForeground = $contract['header']['foreground_token'] ?? null;
                if (($facts['foreground'] ?? null) !== $expectedForeground) {
                    $warnings[] = self::aboveFoldWarning(
                        $headerFile,
                        'header.foreground_token',
                        $expectedForeground,
                        $facts['foreground'] ?? null,
                        'downstream markup drifted from the final readable foreground; retain it and restore the contract token',
                    );
                }
                if (($contract['header']['mode'] ?? null) === AboveFoldContract::MODE_STACKED) {
                    $expectedSurface = $contract['header']['protection_token'] ?? null;
                    if (($facts['background'] ?? null) !== $expectedSurface) {
                        $warnings[] = self::aboveFoldWarning(
                            $headerFile,
                            'header.protection_token',
                            $expectedSurface,
                            $facts['background'] ?? null,
                            'downstream markup drifted from the stacked protection surface; retain it and restore the contract token',
                        );
                    }
                    if (($facts['gradient'] ?? null) !== null || ($facts['custom_background'] ?? false) === true) {
                        $warnings[] = self::aboveFoldWarning(
                            $headerFile,
                            'header.stacked_surface',
                            ['background' => $expectedSurface, 'gradient' => null, 'custom_background' => false],
                            [
                                'background' => $facts['background'] ?? null,
                                'gradient' => $facts['gradient'] ?? null,
                                'custom_background' => $facts['custom_background'] ?? false,
                            ],
                            'downstream markup added a competing stacked surface; retain it and restore only the contract-owned background',
                        );
                    }
                } elseif (($facts['background'] ?? null) !== null
                    || ($facts['gradient'] ?? null) !== null
                    || ($facts['custom_background'] ?? false) === true
                ) {
                    $warnings[] = self::aboveFoldWarning(
                        $headerFile,
                        'header.overlay_surface',
                        ['background' => null, 'gradient' => null, 'custom_background' => false],
                        [
                            'background' => $facts['background'] ?? null,
                            'gradient' => $facts['gradient'] ?? null,
                            'custom_background' => $facts['custom_background'] ?? false,
                        ],
                        'downstream markup made the overlay header opaque; retain it and restore transparent contract-owned chrome',
                    );
                }
            } catch (\RuntimeException $error) {
                $warnings[] = self::aboveFoldWarning(
                    $headerFile,
                    'header.root',
                    'parseable wp:group with final relation markers',
                    'uninspectable: ' . $error->getMessage(),
                    'final advisory inspection could not isolate the generated header drift; retain the bytes for a later repair pass',
                );
            }
        }

        foreach ($pages as $page) {
            $pageSlug = (string) ($page['slug'] ?? '');
            $pageFile = 'plugin/pages/' . $pageSlug . '.html';
            if ($pageSlug === '' || !$project->exists($pageFile)) {
                $warnings[] = self::aboveFoldWarning(
                    $pageFile,
                    "openings[page='{$pageSlug}']",
                    'first planned section markup',
                    'removed',
                    'assembled page opening is absent; retain the rest of the site and regenerate only this page opening',
                );
                continue;
            }
            try {
                $sections = Steps\SectionRhythmStep::splitTopLevel($project->readText($pageFile));
            } catch (\RuntimeException $error) {
                $warnings[] = self::aboveFoldWarning(
                    $pageFile,
                    "openings[page='{$pageSlug}']",
                    'inspectable first section',
                    'uninspectable: ' . $error->getMessage(),
                    'final advisory inspection could not isolate the generated opening drift; retain the page bytes for a later repair pass',
                );
                continue;
            }
            $opening = $sections[0] ?? '';
            if ($opening === '') {
                $warnings[] = self::aboveFoldWarning(
                    $pageFile,
                    "openings[page='{$pageSlug}']",
                    'first planned section markup',
                    'removed',
                    'assembled page has no opening; retain the page and regenerate only the missing opening',
                );
                continue;
            }

            if (($contract['header']['mode'] ?? null) === AboveFoldContract::MODE_OVERLAY) {
                $openingContract = null;
                foreach ((array) ($contract['openings'] ?? []) as $candidate) {
                    if (is_array($candidate) && (string) ($candidate['page'] ?? '') === $pageSlug) {
                        $openingContract = $candidate;
                        break;
                    }
                }
                $surface = (string) ($openingContract['surface'] ?? '');
                $protection = (string) ($contract['header']['protection_token'] ?? 'contrast');
                $protectionHex = is_string($contract['theme_tokens'][$protection]['hex'] ?? null)
                    ? (string) $contract['theme_tokens'][$protection]['hex']
                    : null;
                if ($requiresClearOpeningProof) {
                    $foregroundToken = (string) ($contract['header']['foreground_token'] ?? '');
                    $foregroundHex = is_string($contract['theme_tokens'][$foregroundToken]['hex'] ?? null)
                        ? (string) $contract['theme_tokens'][$foregroundToken]['hex']
                        : null;
                    $foreground = $foregroundHex === null ? null : ContrastMath::hexToRgb($foregroundHex);
                    $protectionRgb = $protectionHex === null ? null : ContrastMath::hexToRgb($protectionHex);
                    $dim = AboveFoldPartFacts::clearOverlayTopDimRatio(
                        $opening,
                        $surface,
                        $protection,
                        $protectionHex,
                    );
                    // Earned-clear CSS snaps the background surface while its
                    // shadow keeps the motion cue, so only the delivered rest
                    // state needs a dim composite proof here.
                    $supported = $foreground !== null
                        && $protectionRgb !== null
                        && $dim !== null
                        && HeaderBehavior::clearOverlayTopIsSafe(
                            $foreground,
                            $protectionRgb,
                            $dim,
                            null,
                            false,
                        );
                } else {
                    $supported = AboveFoldPartFacts::supportsOverlay(
                        $opening,
                        $surface,
                        $protection,
                        $protectionHex,
                    );
                }
                if (!$supported) {
                    $warnings[] = self::aboveFoldWarning(
                        $pageFile,
                        "openings[page='{$pageSlug}'].top_protection_token",
                        $protection,
                        'unsupported',
                        'serialized page opening no longer protects the final overlay header; retain it and restore the exact top-edge relation',
                    );
                }
            }

            if (($contract['header']['mode'] ?? null) === AboveFoldContract::MODE_STACKED) {
                $maxVh = $contract['viewport']['stacked_cover_max_vh'] ?? null;
                if (is_numeric($maxVh)) {
                    foreach (AboveFoldPartFacts::coverViewportHeights($opening) as $height) {
                        if ($height <= (float) $maxVh) {
                            continue;
                        }
                        $warnings[] = self::aboveFoldWarning(
                            $pageFile,
                            "openings[page='{$pageSlug}'].stacked_cover_max_vh",
                            (float) $maxVh,
                            $height,
                            'serialized page opening exceeds the final stacked first-viewport budget; retain it and cap only this cover height',
                        );
                    }
                }
            }

            if (($page['front'] ?? false) !== true) {
                continue;
            }
            $heroFacts = AboveFoldPartFacts::heroFacts($opening, (string) ($contract['recipe'] ?? ''));
            foreach (['root_group', 'recipe_marker'] as $field) {
                if (($heroFacts[$field] ?? false) !== true) {
                    $warnings[] = self::aboveFoldWarning(
                        $pageFile,
                        'hero.' . $field,
                        true,
                        $heroFacts[$field] ?? false,
                        'serialized front hero drifted from its objective envelope; retain it and restore only the root/assigned marker invariant',
                    );
                }
            }
            $action = $contract['primary_action'] ?? null;
            if (is_array($action) && !AboveFoldPartFacts::containsAction($opening, $action)) {
                $warnings[] = self::aboveFoldWarning(
                    $pageFile,
                    'hero.primary_action',
                    $action,
                    'removed or changed',
                    'serialized front hero no longer contains the exact final action; retain it and reconcile only that control',
                );
            }
        }

        return array_values(array_unique($warnings));
    }

    private static function aboveFoldWarning(
        string $file,
        string $path,
        mixed $authored,
        mixed $delivered,
        string $disposition,
    ): string {
        $value = static fn (mixed $item): string => is_string($item)
            ? json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return "above-fold final validation: file='{$file}'; path=\"{$path}\"; authored="
            . $value($authored) . '; delivered=' . $value($delivered)
            . '; disposition=' . $disposition;
    }

    /**
     * Raw AI_IMAGE specs still occupying URL/source fields in generated markup.
     *
     * Two shapes. The first is a raw AI_IMAGE spec still occupying a URL/source
     * field. The documented AI_IMAGE value belongs in an img alt until
     * collect-images records it, so a blanket marker scan would reject valid
     * canonical markup even after its src was resolved. That check is
     * deliberately contextual: only block-JSON url/src values and rendered HTML
     * src attributes can ship the prompt as an image URL.
     *
     * The second is an image reference collect-images never recorded — a bare
     * "hero.jpg" the design invented, or an empty src. Those 404 (or render
     * nothing) no matter how image generation goes, and before this check they
     * were invisible: a page full of them let generate-images report success
     * with zero images. A reference IS judged resolvable when images.json
     * carries it, whatever its status — generation deliberately leaves a
     * failed image's placeholder in place rather than abort the build.
     *
     * The ordinary final validator reports these as warnings for builds that
     * skip optional image generation. GenerateImagesStep also calls this helper
     * directly as a hard completion gate when image generation is requested.
     *
     * @return string[]
     */
    public static function unresolvedImageSourceProblems(Project $project): array
    {
        $problems = [];
        $root = rtrim($project->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $known = self::recordedImageSources($project);

        foreach (self::interactionMarkupFiles($project) as $file) {
            $markup = (string) file_get_contents($file);
            $contexts = [];
            if (preg_match('/"(?:url|src)"\s*:\s*"\s*AI_IMAGE:/i', $markup)) {
                $contexts[] = 'block JSON url/src';
            }
            if (preg_match('/\bsrc\s*=\s*(?:(?:["\'])\s*AI_IMAGE:|AI_IMAGE:)/i', $markup)) {
                $contexts[] = 'HTML src';
            }

            $rel = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                str_starts_with($file, $root) ? substr($file, strlen($root)) : $file,
            );
            if ($contexts !== []) {
                $problems[] = "{$rel}: contains unresolved AI_IMAGE: in " . implode(' and ', $contexts);
            }
            foreach (self::danglingImageSources($markup, $known) as $source) {
                $problems[] = "{$rel}: image source {$source} resolves to nothing and was never collected for generation";
            }
        }

        return $problems;
    }

    /**
     * Every image source collect-images recorded, by its markup spelling.
     * Null means images.json is absent — collection never ran for this project
     * (theme-only fixtures, hosts with their own image pipeline), so nothing
     * can be called dangling.
     *
     * @return array<string,true>|null
     */
    private static function recordedImageSources(Project $project): ?array
    {
        if (!$project->exists('images.json')) {
            return null;
        }
        $known = [];
        foreach ((array) $project->readJson('images.json') as $spec) {
            if (is_array($spec) && is_string($spec['src'] ?? null) && $spec['src'] !== '') {
                $known[$spec['src']] = true;
            }
        }
        return $known;
    }

    /**
     * Image references in one file that neither the browser nor the image
     * pipeline can resolve. Only relative/custom-scheme values that name an
     * image are judged, so a "#" social link or an absolute URL never trips it.
     *
     * @param array<string,true>|null $known
     * @return list<string>
     */
    private static function danglingImageSources(string $markup, ?array $known): array
    {
        if ($known === null) {
            return [];
        }

        $found = [];
        preg_match_all('/<img\b[^>]*>/i', $markup, $tags);
        foreach ($tags[0] as $tag) {
            if (preg_match('/(?<![-\w])src\s*=\s*(["\'])(.*?)\1/is', $tag, $m) === 1) {
                $found[] = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5));
            }
        }
        // wp:cover paints its background from the block attribute alone when
        // the overlay renders as a div, so the JSON is the only reference.
        preg_match_all('/<!--\s*wp:(?:image|cover)\s(?:(?!-->).)*?"url"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/is', $markup, $urls);
        foreach ($urls[1] as $url) {
            $decoded = json_decode('"' . $url . '"');
            $found[] = trim(is_string($decoded) ? $decoded : $url);
        }

        $problems = [];
        foreach (array_unique($found) as $source) {
            if (isset($known[$source]) || !self::isDanglingImageSource($source)) {
                continue;
            }
            $problems[] = $source === '' ? '(empty)' : $source;
        }
        return $problems;
    }

    /**
     * A source the browser cannot fetch: empty, or a relative/custom-scheme
     * path naming an image file. Root-relative, absolute, protocol-relative,
     * and data: values all resolve on their own and are left alone.
     */
    private static function isDanglingImageSource(string $source): bool
    {
        if ($source === '') {
            return true;
        }
        if (preg_match('#^(?:/|//|https?:|data:|mailto:|tel:|\#)#i', $source) === 1) {
            return false;
        }
        return str_starts_with($source, 'theme:')
            || preg_match('/\.(?:jpe?g|png|gif|webp|avif|svg)(?:[?\#]|$)/i', $source) === 1;
    }

    /**
     * Broken-link problems across the generated site: every root-relative
     * href must be the path of a page in pages.json, a fragment must be an
     * anchor that exists on the page it targets (the chrome's anchors count
     * everywhere — it renders on every page), a bare "#fragment" in the
     * chrome must resolve on EVERY page, and a button link must carry an
     * href at all. A bare "#" is dead UI and is reported with its file and
     * link index; external URLs, mailto:/tel:, and static theme asset URLs are
     * otherwise not judged. Asset URLs include the pre-image
     * `theme:./assets/…` form and the
     * post-GenerateImagesStep rewrite `/wp-content/themes/{slug}/assets/…`
     * (also scraped from cover/image block `"url"` JSON). Fragment checks are
     * skipped for pages whose markup is not on disk (theme-only builds).
     *
     * @return string[]
     */
    private static function linkProblems(Project $project): array
    {
        if (!$project->exists('pages.json')) {
            return [];
        }
        $pages = array_filter(
            (array) ($project->readJson('pages.json')['pages'] ?? []),
            static fn ($p) => is_array($p) && (string) ($p['slug'] ?? '') !== ''
        );
        if ($pages === []) {
            return [];
        }

        // Route → page slug, and per-page anchor sets (null = markup not on
        // disk, so fragments against that page can't be judged).
        $routes = [];
        $anchors = [];
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            $routes[self::normalizePath((string) ($page['path'] ?? ''))] = $slug;
            $rel = "plugin/pages/{$slug}.html";
            $anchors[$slug] = $project->exists($rel) ? self::anchorsIn($project->readText($rel)) : null;
        }

        $chrome = [];
        foreach (['theme/parts/header.html', 'theme/parts/footer.html'] as $rel) {
            if ($project->exists($rel)) {
                $chrome += self::anchorsIn($project->readText($rel));
            }
        }

        // Anchors present on EVERY page — what a bare chrome fragment needs;
        // null (unknown) when any page's markup is missing.
        $everywhere = null;
        if (!in_array(null, $anchors, true)) {
            $sets = array_values($anchors);
            $everywhere = array_shift($sets) ?? [];
            foreach ($sets as $set) {
                $everywhere = array_intersect_key($everywhere, $set);
            }
            $everywhere += $chrome;
        }

        // The files whose links get judged: the chrome (rendered on every
        // page, so its bare fragments are held to the every-page set) and
        // each page's markup.
        $scan = [];
        foreach (['theme/parts/header.html', 'theme/parts/footer.html'] as $rel) {
            if ($project->exists($rel)) {
                $scan[$rel] = null;
            }
        }
        foreach ($anchors as $slug => $set) {
            if ($set !== null) {
                $scan["plugin/pages/{$slug}.html"] = (string) $slug;
            }
        }
        foreach (glob($project->themePath('patterns') . '/*.php') ?: [] as $abs) {
            $rel = 'theme/patterns/' . basename($abs);
            $scan[$rel] = $rel;
            $anchors[$rel] = self::anchorsIn($project->readText($rel));
        }

        $problems = [];
        foreach ($scan as $rel => $pageSlug) {
            $markup = $project->readText($rel);

            // Buttons must lead somewhere; a button anchor with no href is
            // a dead CTA.
            foreach (preg_match_all('/<a\b[^>]*>/i', $markup, $m) ? $m[0] : [] as $tag) {
                if (str_contains($tag, 'wp-block-button__link') && !preg_match('/\shref\s*=/i', $tag)) {
                    $problems[] = "{$rel}: a button link has no href — every button needs a destination";
                }
            }

            // Destinations live in the rendered href and in block-JSON "url",
            // "href", "textLinkHref", "src", and "poster" attributes.
            $links = LinkTargets::allTargets($markup);

            foreach ($links as $href) {
                $href = LinkTargets::normalizeTarget($href);
                if (LinkTargets::isDangerousScheme($href)) {
                    $problems[] = "{$rel}: link href carries a dangerous scheme";
                    continue;
                }
                if ($href === '#') {
                    continue; // reported independently even when pages.json is unavailable
                }
                if ($href === '') {
                    $problems[] = "{$rel}: a link has an empty href";
                    continue;
                }
                if ($href[0] === '#') {
                    $fragment = substr($href, 1);
                    $set = $pageSlug === null ? $everywhere : $anchors[$pageSlug] + $chrome;
                    if ($set !== null && !isset($set[$fragment])) {
                        $problems[] = $pageSlug === null
                            ? "{$rel}: chrome link href=\"{$href}\" must resolve on every page, but not every page has id=\"{$fragment}\""
                            : "{$rel}: link href=\"{$href}\" targets no id=\"{$fragment}\" on this page";
                    }
                    continue;
                }
                if ($href[0] !== '/') {
                    continue; // external schemes, mailto:, tel:, theme:./assets
                }
                $parts = parse_url($href) ?: [];
                $path = self::normalizePath((string) ($parts['path'] ?? ''));
                // After generate-images, cover/image "url" and rewritten srcs
                // look root-relative — they are media, not page routes.
                if (self::isThemeAssetPath($path)) {
                    continue;
                }
                if (!isset($routes[$path])) {
                    $problems[] = "{$rel}: link href=\"{$href}\" targets no generated page (no page has path {$path})";
                    continue;
                }
                $fragment = (string) ($parts['fragment'] ?? '');
                $target = $routes[$path];
                if ($fragment !== '' && $anchors[$target] !== null
                    && !isset($anchors[$target][$fragment]) && !isset($chrome[$fragment])
                ) {
                    $problems[] = "{$rel}: link href=\"{$href}\" targets page '{$target}', which has no id=\"{$fragment}\"";
                }
            }
        }
        // href and block-JSON "url" mirror each other, so the same broken
        // destination would otherwise be reported twice.
        return array_values(array_unique($problems));
    }

    /**
     * Whether every form placeholder in one file is one a host can parse.
     *
     * The grammar lives in FormPlaceholder, which is also what the host reads
     * it with, so this check and the substitution downstream cannot disagree.
     * They would disagree silently: a placeholder is ordinary paragraph text,
     * so a spec no host can read reaches the visitor as literal grey body copy
     * with nothing else in the pipeline noticing.
     *
     * @return list<string>
     */
    private static function formPlaceholderProblems(Project $project, string $rel): array
    {
        $markup = $project->readText($rel);
        $markers = FormPlaceholder::markerCount($markup);
        if ($markers === 0) {
            return [];
        }

        if (!Steps\SectionsStep::formPlaceholders($project)) {
            return ["{$rel}: contains a " . FormPlaceholder::MARKER . ' marker but this build has no'
                . ' form host — disposition: rebuild the section, or enable form placeholders'];
        }

        $problems = [];
        $placeholders = FormPlaceholder::find($markup);
        foreach ($placeholders as $placeholder) {
            $parsed = FormPlaceholder::parse($placeholder['spec']);
            if (is_string($parsed)) {
                $problems[] = "{$rel}: unparseable form spec \"{$placeholder['spec']}\" ({$parsed})"
                    . ' — disposition: no host can substitute this; regenerate the section';
            }
        }

        // A marker outside a placeholder block is text the host never reads.
        $loose = $markers - count($placeholders);
        if ($loose > 0) {
            $problems[] = "{$rel}: {$loose} form marker(s) outside a "
                . FormPlaceholder::CLASS_NAME . ' block — disposition: the host only substitutes'
                . ' markers inside that block, so these would ship as visible text';
        }

        return $problems;
    }

    /**
     * Bare placeholder destinations are dead regardless of the generated page
     * map, so inspect them independently from route/fragment validation.
     *
     * @return string[]
     */
    public static function placeholderLinkProblems(Project $project): array
    {
        $problems = [];
        $root = rtrim($project->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach (self::interactionMarkupFiles($project) as $file) {
            $markup = (string) file_get_contents($file);
            $hrefs = self::hrefsIn($markup);

            $rel = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            foreach ($hrefs as $index => $href) {
                if ($href !== '#') {
                    continue;
                }
                $number = $index + 1;
                $problems[] = "{$rel}: link[{$number}] authored href=\"#\" -> delivered href=\"#\" "
                    . '(dead placeholder); disposition: replace it with a real destination or remove the link';
            }

            // Dynamic blocks such as navigation-link may carry no rendered
            // anchor in the saved markup. Inspect parsed block attributes too,
            // while suppressing the mirrored JSON url on blocks whose own HTML
            // anchor was already reported above.
            $blockUrl = 0;
            $document = BlockMarkup::parse($markup);
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index);
                if (
                    !in_array($document->name($index), self::LINK_URL_BLOCKS, true)
                    || !is_array($attrs)
                    || ($attrs['url'] ?? null) !== '#'
                ) {
                    continue;
                }
                if (in_array('#', self::hrefsIn($document->ownHtml($index)), true)) {
                    continue;
                }
                $number = ++$blockUrl;
                $problems[] = "{$rel}: block-url[{$number}] authored url=\"#\" -> delivered url=\"#\" "
                    . '(dead placeholder); disposition: replace it with a real destination or remove the link';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function hrefsIn(string $markup): array
    {
        return LinkTargets::hrefsIn($markup);
    }

    /**
     * Page, chrome, and generated pattern files. Link/image/placeholder scans
     * share this set so a defect that only exists in theme/patterns/*.php is
     * not invisible to the advisory validator.
     *
     * @return list<string> absolute paths
     */
    private static function interactionMarkupFiles(Project $project): array
    {
        return array_merge(
            $project->markupFiles(),
            glob($project->themePath('patterns') . '/*.php') ?: [],
        );
    }

    /**
     * Bare media placeholders render a broken image rather than a dead link.
     * Keep their warning context separate so a repair pass changes the source
     * (or removes the media block) instead of trying to invent navigation.
     *
     * @return string[]
     */
    public static function placeholderMediaSourceProblems(Project $project): array
    {
        $problems = [];
        $root = rtrim($project->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach (self::interactionMarkupFiles($project) as $file) {
            $markup = (string) file_get_contents($file);
            $rel = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

            $sources = self::htmlAttributeValues(self::HTML_SRC_PATTERN, $markup);
            foreach ($sources as $index => $source) {
                if ($source !== '#') {
                    continue;
                }
                $number = $index + 1;
                $problems[] = "{$rel}: media-src[{$number}] authored src=\"#\" -> delivered src=\"#\" "
                    . '(dead media source); disposition: replace it with a real theme asset or remove the media block';
            }

            $blockUrl = 0;
            $document = BlockMarkup::parse($markup);
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index);
                if (
                    !in_array($document->name($index), self::MEDIA_URL_BLOCKS, true)
                    || !is_array($attrs)
                    || ($attrs['url'] ?? null) !== '#'
                ) {
                    continue;
                }
                $ownSources = self::htmlAttributeValues(self::HTML_SRC_PATTERN, $document->ownHtml($index));
                if (in_array('#', $ownSources, true)) {
                    continue;
                }
                $number = ++$blockUrl;
                $block = $document->name($index);
                $problems[] = "{$rel}: wp:{$block}[{$number}] authored url=\"#\" -> delivered url=\"#\" "
                    . '(dead media source); disposition: replace it with a real theme asset or remove the media block';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function htmlAttributeValues(string $pattern, string $markup): array
    {
        if (!preg_match_all(
            $pattern,
            $markup,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        )) {
            return [];
        }
        return array_map(
            static fn (array $match): string => $match[2] !== null ? $match[2] : (string) $match[3],
            $matches
        );
    }

    /**
     * Empty core/list blocks are visible holes in generated navigation and
     * commonly mean a serializer had to drop malformed list items. The final
     * validator is advisory: it leaves the usable artifact untouched and
     * records enough file/block/value/disposition context for a repair pass.
     *
     * @return string[]
     */
    public static function emptyListProblems(Project $project): array
    {
        $problems = [];
        $root = rtrim($project->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach ($project->markupFiles() as $file) {
            $markup = (string) file_get_contents($file);
            $rel = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            $number = 0;
            $document = BlockMarkup::parse($markup);
            foreach ($document->indices() as $index) {
                if ($document->name($index) !== 'list') {
                    continue;
                }
                $number++;
                $hasItemBlock = false;
                foreach ($document->children($index) as $child) {
                    if ($document->name($child) === 'list-item') {
                        $hasItemBlock = true;
                        break;
                    }
                }
                if ($hasItemBlock || preg_match('/<li\b/i', $document->ownHtml($index))) {
                    continue;
                }
                $problems[] = "{$rel}: wp:list[{$number}] authored list block -> delivered empty list (0 items); "
                    . 'disposition: remove the empty block or restore its intended items';
            }
        }

        return $problems;
    }

    /**
     * Anchor names the markup exposes: HTML id attributes plus block-JSON
     * "anchor" attributes (they mirror each other in serialized markup, but
     * either alone still resolves).
     *
     * @return array<string,true>
     */
    private static function anchorsIn(string $markup): array
    {
        return LinkTargets::anchorsIn($markup);
    }

    /**
     * Root-relative URLs that are theme static files (not page routes).
     * GenerateImagesStep rewrites theme:./assets/* to
     * /wp-content/themes/{slug}/assets/*; cover/image block "url" attrs and
     * img src then look like paths and must not be judged as page links.
     */
    private static function isThemeAssetPath(string $path): bool
    {
        return LinkTargets::isThemeAssetPath($path);
    }

    /** Page paths compared in one canonical form: leading + trailing slash. */
    private static function normalizePath(string $path): string
    {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? '/' : "/{$trimmed}/";
    }

    /**
     * Width/rhythm warnings: whatever LayoutFixer would still change. After a
     * normal build these are empty — FixBlocksStep applies the same pass — so
     * anything reported here means generated markup drifted past the
     * deterministic normalization (or an old project predates it).
     *
     * @param bool $htmlFirst must match the flag the build's fix-blocks pass
     *        used, or the linter reports rules that path deliberately skips
     * @return string[] list of warnings (empty means widths follow the contract)
     */
    public static function layoutWarnings(Project $project, bool $htmlFirst = false): array
    {
        $warnings = [];
        $measure = Steps\DesignDirectionStep::measureFor($project);
        $expectedWidths = $htmlFirst ? null : Measure::widths($measure);
        if ($expectedWidths !== null && $project->exists('theme/theme.json')) {
            $theme = $project->readJson('theme/theme.json');
            $delivered = $theme['settings']['layout'] ?? null;
            if ($delivered !== $expectedWidths) {
                $warnings[] = 'theme.json settings.layout drifted from committed "' . $measure
                    . '" measure: expected ' . Warnings::value($expectedWidths)
                    . ', delivered ' . Warnings::value($delivered);
            }
        }
        $contentSize = Steps\FixBlocksStep::themeContentSize($project);
        $spacingSlugs = Steps\FixBlocksStep::themeSpacingSlugs($project);
        // The build's normalization consults the design's own stylesheet to
        // decide which roots already own their width. Reading it here too is
        // what keeps this a dry run of that pass: without it the linter reports
        // the very stamp normalize-layout deliberately withheld.
        $wideMeasureRootClasses = $htmlFirst && $project->exists('design/site.css')
            ? Units\GeneratedMarkup::wideMeasureSubjectClasses($project->readText('design/site.css'))
            : [];
        foreach ($project->themeFiles() as $rel) {
            $result = LayoutFixer::fix(
                $project->readText('theme/' . $rel),
                LayoutFixer::roleFor($rel),
                $contentSize,
                $spacingSlugs,
                $htmlFirst,
                $wideMeasureRootClasses,
            );
            foreach ($result['notes'] as $note) {
                $warnings[] = "{$rel}: {$note}";
            }
        }
        return $warnings;
    }

    /**
     * Deterministic vertical-rhythm checks for a completed generated site.
     *
     * The theme must retain the bounded canonical spacing profile installed by
     * ThemeJsonStep, and every planned section root must still match the
     * page-owned density/seam calculation after all serialization passes.
     * These are build failures in the default pipeline, not aesthetic hints.
     *
     * @return string[] list of warnings (empty means spacing follows the contract)
     */
    public static function spacingWarnings(Project $project): array
    {
        $warnings = [];

        if ($project->exists('theme/theme.json')) {
            $theme = json_decode($project->readText('theme/theme.json'), true);
            if (is_array($theme)) {
                $normalized = Steps\ThemeJsonStep::normalizeSpacingSettings($theme);
                if (($theme['settings']['spacing'] ?? null) !== ($normalized['settings']['spacing'] ?? null)) {
                    $warnings[] = 'theme.json settings.spacing drifted from the bounded canonical profile';
                }
                $normalized = Steps\ThemeJsonStep::normalizeGroupBlockPadding($theme);
                if ($theme !== $normalized) {
                    $authored = $theme['styles']['blocks']['core/group']['spacing']['padding'] ?? null;
                    $warnings[] = "file='theme/theme.json'; "
                        . "block='styles.blocks.core/group.spacing.padding'; authored="
                        . Warnings::value($authored)
                        . '; delivered=unchanged; disposition=remove global top/bottom Group padding '
                        . 'and place vertical spacing on explicit section or component roots';
                }
            }
        }

        if (!$project->exists('pages.json')) {
            return $warnings;
        }

        try {
            // The same entry builder the build pass derives from, so this
            // gate can never disagree with SectionRhythmStep about the
            // plan's demands. The transient parts were inlined into the
            // content plugin and dropped by assemble-pages, so each page is
            // judged over its assembled plugin/pages/<slug>.html.
            $footerSurface = Steps\SectionRhythmStep::footerSurface($project);
            foreach (Steps\SectionRhythmStep::pages($project) as $page) {
                $slug = trim((string) ($page['slug'] ?? ''));
                $entries = Steps\SectionRhythmStep::assembledEntries($project, $page);
                $result = SectionRhythm::rewrite(
                    $entries,
                    $footerSurface,
                    Steps\DesignDirectionStep::deviceFor($project),
                    Steps\SectionRhythmStep::footerMarkup($project),
                );
                foreach ($result['notes'] as $note) {
                    $warnings[] = "section root spacing drift (page '{$slug}'): " . $note;
                }
            }
        } catch (\Throwable $e) {
            $warnings[] = 'could not validate section rhythm: ' . $e->getMessage();
        }

        return $warnings;
    }

    /**
     * Soft typography checks (warnings, not build failures): font sizes
     * hardcoded in block markup instead of drawn from the theme.json
     * fontSizes scale, a "display" preset that no markup uses, and
     * multi-line paragraphs set at heading-scale presets.
     *
     * @return string[] list of warnings (empty means the scale governs the page)
     */
    public static function typographyWarnings(Project $project): array
    {
        $warnings = [];
        $files = $project->markupFiles();

        $theme = $project->exists('theme/theme.json')
            ? json_decode($project->readText('theme/theme.json'), true)
            : null;
        $sizeBySlug = [];
        foreach ($theme['settings']['typography']['fontSizes'] ?? [] as $entry) {
            if (isset($entry['slug'], $entry['size'])) {
                $sizeBySlug[$entry['slug']] = self::sizeToPx((string) $entry['size']);
            }
        }

        $hardcoded = [];
        $bigParagraphs = [];
        $tinyParagraphs = [];
        $displayUsed = false;
        foreach ($files as $file) {
            $markup = (string) file_get_contents($file);
            // Raw values in the fontSize block attribute (a preset slug or
            // var:preset reference is fine; "1.25rem" / "clamp(...)" is not).
            // Inline font-size styles mirror this attribute, so counting the
            // attribute counts each hardcoded size once.
            $count = preg_match_all('/"fontSize"\s*:\s*"(?:clamp\(|[0-9.]+(?:r?em|px|vw|vh|%))/', $markup);
            if ($count > 0) {
                $hardcoded[] = basename($file) . " ({$count})";
            }
            if (preg_match('/has-display-font-size|"fontSize"\s*:\s*"display"|font-size--display/', $markup)) {
                $displayUsed = true;
            }
            $count = self::paragraphsOutsideBodyScale($markup, $sizeBySlug, 20.0, null);
            if ($count > 0) {
                $bigParagraphs[] = basename($file) . " ({$count})";
            }
            $count = self::paragraphsOutsideBodyScale($markup, $sizeBySlug, null, 16.0);
            if ($count > 0) {
                $tinyParagraphs[] = basename($file) . " ({$count})";
            }
        }
        if ($hardcoded !== []) {
            $warnings[] = 'hardcoded font-size values bypass the fontSizes scale: ' . implode(', ', $hardcoded);
        }
        if ($bigParagraphs !== []) {
            $warnings[] = 'multi-line paragraphs set at heading-scale presets (>= 1.25rem): ' . implode(', ', $bigParagraphs);
        }
        if ($tinyParagraphs !== []) {
            $warnings[] = 'multi-line paragraphs set at caption-scale presets (< 1rem): ' . implode(', ', $tinyParagraphs);
        }
        if (in_array('display', array_keys($sizeBySlug), true) && !$displayUsed) {
            $warnings[] = 'theme.json defines a "display" fontSize that no template or part uses';
        }

        return $warnings;
    }

    /**
     * Page-plan warnings: footer-like page sections that duplicate the
     * template footer, plus interior pages that open at homepage-hero scale.
     * The normal build now enforces this at plan time (PagePlanStep rejects
     * an interior plan whose first section is 'full-bleed-cover', repairs it
     * once via the model, and demotes it mechanically as a last resort), so
     * after a fresh build this is empty; anything reported here means the
     * plan predates the enforcement or was edited by hand — visibility for
     * eval, because a site where every interior page opens with a
     * full-viewport cover has five homepages, not one.
     *
     * @return string[] list of warnings (empty means interior openings are compact)
     */
    public static function planWarnings(Project $project): array
    {
        if (!$project->exists('pages.json')) {
            return [];
        }
        $warnings = [];
        foreach (($project->readJson('pages.json')['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $pageSlug = (string) ($page['slug'] ?? '');
            if ($project->exists('theme/parts/footer.html')) {
                foreach (($page['sections'] ?? []) as $section) {
                    if (!is_array($section) || !FooterSectionIdentity::matches($section)) {
                        continue;
                    }
                    $sectionSlug = (string) ($section['slug'] ?? '');
                    $title = (string) ($section['title'] ?? '');
                    $type = (string) ($section['type'] ?? '');
                    $warnings[] = "pages.json: page[{$pageSlug}]/sections[{$sectionSlug}] authored "
                        . "slug=\"{$sectionSlug}\", title=\"{$title}\", type=\"{$type}\" -> delivered alongside "
                        . 'theme/parts/footer.html; disposition: remove the footer-like page section and keep '
                        . 'the reusable template footer';
                }
            }
            if (!empty($page['front'])) {
                continue;
            }
            $first = $page['sections'][0] ?? null;
            if (is_array($first) && ($first['layout_archetype'] ?? '') === 'full-bleed-cover') {
                $warnings[] = sprintf(
                    "interior page '%s' opens with a full-bleed-cover section ('%s') — interior pages plan a compact hero, not a second homepage hero",
                    (string) ($page['slug'] ?? ''),
                    (string) ($first['slug'] ?? '')
                );
            }
        }
        return $warnings;
    }

    /**
     * Count paragraphs whose fontSize preset falls outside body scale —
     * heading-size ($minPx set) or caption-size ($maxPx set) — while carrying
     * more than a short lead line of text. Running copy belongs at body size;
     * both drifts read wrong (inflated or unreadably small).
     *
     * @param array<string,float|null> $sizeBySlug preset slug => desktop px
     */
    private static function paragraphsOutsideBodyScale(string $markup, array $sizeBySlug, ?float $minPx, ?float $maxPx): int
    {
        if (!preg_match_all('/<!--\s*wp:paragraph\s+(\{.*?\})\s*-->\s*(<p\b.*?<\/p>)/s', $markup, $matches, PREG_SET_ORDER)) {
            return 0;
        }
        $count = 0;
        foreach ($matches as $m) {
            $attrs = json_decode($m[1], true);
            $px = $sizeBySlug[$attrs['fontSize'] ?? ''] ?? null;
            $text = trim(strip_tags($m[2]));
            if ($px === null || mb_strlen($text) <= 120) {
                continue;
            }
            if (($minPx !== null && $px >= $minPx) || ($maxPx !== null && $px < $maxPx)) {
                $count++;
            }
        }
        return $count;
    }

    /** Desktop px for a preset size; clamp()/fluid values judged at their max end. */
    private static function sizeToPx(string $size): ?float
    {
        if (!preg_match_all('/([0-9.]+)\s*(rem|em|px)/', $size, $m, PREG_SET_ORDER)) {
            return null;
        }
        $px = array_map(
            static fn (array $t): float => (float) $t[1] * ($t[2] === 'px' ? 1 : 16),
            $m
        );
        return max($px);
    }

    /** @return string|null problem description, or null if balanced */
    private static function blockBalance(string $markup): ?string
    {
        $allOpen = preg_match_all('/<!--\s*wp:/', $markup);
        $selfClose = preg_match_all('/<!--\s*wp:[^>]*?\/-->/', $markup);
        $close = preg_match_all('/<!--\s*\/wp:/', $markup);

        $openBlocks = $allOpen - $selfClose;
        if ($openBlocks !== $close) {
            return "unbalanced block comments (open={$openBlocks}, close={$close})";
        }
        if ($allOpen === 0) {
            return 'no block markup';
        }
        return null;
    }
}
