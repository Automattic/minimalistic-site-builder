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

        foreach ($checked as $rel) {
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

        // Raw form controls are dead UI: nothing generated serves a submission,
        // so a section that emits them silently discards whatever visitors type.
        foreach ($checked as $rel) {
            if ($project->exists($rel) && preg_match('/<(form|input|textarea|select)\b/i', $project->readText($rel), $m)) {
                $problems[] = "{$rel}: contains form markup (<" . strtolower($m[1]) . '>) — the site has no form backend';
            }
        }

        // Generated links must resolve. The section prompt promises real
        // destinations, but only a scan of what the model actually emitted
        // enforces it.
        return array_merge($problems, self::linkProblems($project));
    }

    /**
     * Broken-link problems across the generated site: every root-relative
     * href must be the path of a page in pages.json, a fragment must be an
     * anchor that exists on the page it targets (the chrome's anchors count
     * everywhere — it renders on every page), a bare "#fragment" in the
     * chrome must resolve on EVERY page, and a button link must carry an
     * href at all. External URLs, mailto:/tel:, and the bare "#" placeholder
     * the prompts allow for social links are not judged. Fragment checks are
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

            // Destinations live both in the rendered href and in block-JSON
            // "url" attributes (wp:navigation-link has no rendered HTML).
            $links = preg_match_all('/\bhref="([^"]*)"/i', $markup, $m) ? $m[1] : [];
            foreach (preg_match_all('/"url"\s*:\s*"([^"]*)"/', $markup, $m) ? $m[1] : [] as $url) {
                $links[] = str_replace('\/', '/', $url);
            }

            foreach ($links as $href) {
                if ($href === '#') {
                    continue; // the placeholder the prompts allow for external/social links
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
                    continue; // external schemes, mailto:, tel:, theme assets
                }
                $parts = parse_url($href) ?: [];
                $path = self::normalizePath((string) ($parts['path'] ?? ''));
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
     * Anchor names the markup exposes: HTML id attributes plus block-JSON
     * "anchor" attributes (they mirror each other in serialized markup, but
     * either alone still resolves).
     *
     * @return array<string,true>
     */
    private static function anchorsIn(string $markup): array
    {
        $set = [];
        foreach (preg_match_all('/\bid="([^"]+)"/', $markup, $m) ? $m[1] : [] as $id) {
            $set[$id] = true;
        }
        foreach (preg_match_all('/"anchor"\s*:\s*"([^"]+)"/', $markup, $m) ? $m[1] : [] as $id) {
            $set[$id] = true;
        }
        return $set;
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
     * @return string[] list of warnings (empty means widths follow the contract)
     */
    public static function layoutWarnings(Project $project): array
    {
        $warnings = [];
        $contentSize = Steps\FixBlocksStep::themeContentSize($project);
        foreach (Steps\FixBlocksStep::themeFiles($project) as $rel) {
            $result = LayoutFixer::fix(
                $project->readText('theme/' . $rel),
                LayoutFixer::roleFor($rel),
                $contentSize
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
                $result = SectionRhythm::rewrite($entries, $footerSurface);
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
     * Page-plan warnings: interior pages that open at homepage-hero scale.
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
            if (!is_array($page) || !empty($page['front'])) {
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
