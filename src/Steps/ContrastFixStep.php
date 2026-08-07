<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ContrastFix;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (deterministic): lint and auto-repair text/background contrast.
 *
 * Input:  theme/theme.json + theme/parts/*.html + theme/templates/*.html
 * Output: the same files with failing color pairs repaired, plus
 *         logs/contrast-report.txt listing every pair checked and fixed.
 *
 * The LLM is only *asked* to keep text readable; nothing upstream verifies
 * it. This step is the deterministic backstop: it computes real WCAG ratios
 * for every text/background and link/background pair the markup produces
 * (see ContrastFix) and repairs the failures. It also fixes the theme.json
 * global pairs — an unreadable global link default (`elements.link`) is the
 * most common shipped bug, since `primary` links go invisible on dark
 * (`contrast`-background) sections.
 *
 * Runs BEFORE fix-blocks on purpose: repairs only rewrite the block-comment
 * JSON attributes, and the fix-blocks re-serialization then regenerates the
 * saved HTML from exactly those attributes — no second serializer needed and
 * markup/attribute drift is impossible.
 *
 * The header part is linted but never repaired: it commonly floats
 * transparently over the hero image, so judging it against the `base` page
 * background would produce confidently wrong "fixes".
 */
final class ContrastFixStep implements Step
{
    private const REPORT_FILE = 'contrast-report.txt';

    /** base↔contrast should be comfortably readable, not borderline. */
    private const PALETTE_TARGET = 7.0;

    public function id(): string
    {
        return 'contrast-fix';
    }

    public function label(): string
    {
        return 'Contrast lint & repair';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            // Templates are only scanned when they exist; in the default graph
            // they are written by assemble-pages, which runs after this step.
            reads: ['theme/theme.json', 'pages.json', 'theme/parts/*'],
            writes: ['theme/theme.json', 'theme/parts/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $themeJson = $project->readJson('theme/theme.json');
        $palette = self::paletteMap($themeJson);
        $gradients = self::gradientMap($themeJson);

        $report = [];
        $repaired = 0;
        $warnings = 0;

        // Theme-level pairs first: the global link repair below changes the
        // default that the per-file walk then inherits.
        $themeChanged = $this->fixThemeLevel($themeJson, $palette, $report, $repaired, $warnings);
        if ($themeChanged) {
            $project->writeJson('theme/theme.json', $themeJson);
        }

        $globalLink = $themeJson['styles']['elements']['link']['color']['text'] ?? null;
        $defaultText = $themeJson['styles']['color']['text'] ?? null;
        $headingText = $themeJson['styles']['elements']['heading']['color']['text'] ?? null;
        $fix = new ContrastFix(
            $palette,
            $gradients,
            is_string($globalLink) ? $globalLink : null,
            is_string($defaultText) ? $defaultText : null,
            is_string($headingText) ? $headingText : null,
            self::fontSizeMap($themeJson),
        );

        foreach ($project->themeFiles() as $rel) {
            $markup = $project->readText('theme/' . $rel);
            // The header floats over the hero — lint only (see class doc).
            $repair = basename($rel) !== 'header.html';
            $result = $fix->process($markup, $repair);
            if ($result['changed']) {
                $project->writeText('theme/' . $rel, $result['markup']);
            }
            foreach ($result['findings'] as $f) {
                $warning = !$f['repaired'] || ($f['residual'] ?? false);
                $disposition = $f['repaired']
                    ? ($warning ? '(repaired) (warning)' : '(repaired)')
                    : '(warning)';
                $report[] = sprintf('[%s] %s %s', $rel, $f['detail'], $disposition);
                if ($f['repaired']) {
                    $repaired++;
                }
                if ($warning) {
                    $warnings++;
                }
            }
        }

        $this->lintOverlayHeader($project, $fix, is_string($defaultText) ? $defaultText : null, $report, $warnings);

        // Every unrepairable pair is a defect the build delivers through:
        // record it durably for the later repair pass, not just in the log.
        // Fully repaired rows stay out; best-effort repairs that remain below
        // threshold end in `(warning)` and stay in the durable queue.
        $project->addWarnings($this->id(), array_values(array_filter(
            $report,
            static fn (string $row): bool => str_ends_with($row, '(warning)'),
        )));

        if ($report === []) {
            $report[] = 'All text/background and link/background pairs pass WCAG thresholds.';
        }
        $project->writeText('logs/' . self::REPORT_FILE, implode("\n", $report) . "\n");

        echo sprintf(
            "  contrast: %d repaired, %d warning(s) (details: logs/%s)\n",
            $repaired, $warnings, self::REPORT_FILE
        );
    }

    /**
     * Overlay-header lint. A `header-behavior-overlay-to-solid` header starts
     * translucently on EVERY generated page, floating over each page's FIRST
     * section — but the header
     * and the sections are generated concurrently, blind to each other, so
     * nothing upstream guarantees the one text color the header committed to
     * reads against every page's opening background. This is the
     * deterministic backstop: the header's effective text color is checked
     * against each page's first-section background AS THE TRUSTED KIT PAINTS
     * IT — the overlay's top state always composites its verified black scrim
     * (HeaderBehavior::OVERLAY_SCRIM_ALPHA) under the header text, bounding
     * every pixel to the worst case the foreground was already selected
     * against, so the lint judges the scrimmed background rather than the raw
     * one. Warnings only — the right fix (recolor the header, darken the
     * section, or drop the overlay) is a design decision this step must not
     * make — and image-backed covers are skipped like everywhere else in
     * phase one (their pixels are unknowable until images exist).
     *
     * @param list<string> $report
     */
    private function lintOverlayHeader(Project $project, ContrastFix $fix, ?string $defaultText, array &$report, int &$warnings): void
    {
        if (!$project->exists('pages.json') || !$project->exists('theme/parts/header.html')) {
            return;
        }
        $header = BlockMarkup::parse($project->readText('theme/parts/header.html'));
        $top = $header->topLevel();
        if ($top === null) {
            return;
        }
        $attrs = $header->attrs($top) ?? [];
        $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array('header-behavior-overlay-to-solid', $classes, true)) {
            return;
        }

        // The header's effective text color: its own, else the theme default.
        $fg = null;
        $slug = $attrs['textColor'] ?? null;
        if (is_string($slug) && ($rgb = $fix->rgbFor($slug)) !== null) {
            $fg = ['rgb' => $rgb, 'label' => $slug];
        } elseif (is_string($attrs['style']['color']['text'] ?? null)) {
            $fg = $fix->resolveColorValue($attrs['style']['color']['text']);
        }
        if ($fg === null && $defaultText !== null) {
            $fg = $fix->resolveColorValue($defaultText);
        }
        if ($fg === null && ($rgb = $fix->rgbFor('contrast')) !== null) {
            $fg = ['rgb' => $rgb, 'label' => 'contrast (fallback)'];
        }
        if ($fg === null) {
            return;
        }

        $base = $fix->rgbFor('base') ?? [255, 255, 255];
        foreach (($project->readJson('pages.json')['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $pageSlug = (string) ($page['slug'] ?? '');
            $first = $page['sections'][0] ?? null;
            if (!is_array($first)) {
                continue;
            }
            $sectionSlug = (string) ($first['slug'] ?? '');
            $rel = 'parts/' . SectionsStep::partSlug($pageSlug, $sectionSlug) . '.html';
            if (!$project->exists('theme/' . $rel)) {
                continue;
            }
            $section = BlockMarkup::parse($project->readText('theme/' . $rel));
            $secTop = $section->topLevel();
            if ($secTop === null) {
                continue;
            }
            $secAttrs = $section->attrs($secTop) ?? [];

            if ($section->name($secTop) === 'cover') {
                $hasImage = (is_string($secAttrs['url'] ?? null) && trim((string) $secAttrs['url']) !== '')
                    || ($secAttrs['useFeaturedImage'] ?? false) === true;
                if ($hasImage) {
                    continue; // unknowable until images exist
                }
                $dim = (int) ($secAttrs['dimRatio'] ?? 100);
                $bg = [];
                foreach ($fix->coverOverlayColors($secAttrs) as $stop) {
                    $bg[] = ContrastMath::compositeOver($stop['rgb'], $stop['alpha'] * $dim / 100, $base);
                }
                $bgLabel = 'cover-overlay over base';
            } else {
                $ownBg = $fix->ownBackground($secAttrs, [$base]);
                [$bg, $bgLabel] = $ownBg !== null ? [$ownBg['colors'], $ownBg['label']] : [[$base], 'base'];
            }

            // The trusted kit's top state always paints its verified scrim
            // between the raw background and the header text; lint what the
            // viewer sees, not the unscrimmed surface beneath it.
            $min = PHP_FLOAT_MAX;
            foreach ($bg as $color) {
                $scrimmed = ContrastMath::compositeOver(
                    [0, 0, 0],
                    HeaderBehavior::OVERLAY_SCRIM_ALPHA,
                    $color,
                );
                $min = min($min, ContrastMath::ratio($fg['rgb'], $scrimmed));
            }
            if ($min >= ContrastMath::NORMAL_TEXT) {
                continue;
            }
            $report[] = sprintf(
                "[parts/header.html] overlay header text %s floats over page '%s' opening section '%s' on %s under the trusted %d%%-black scrim: %.2f < %.1f — the transparent header renders on EVERY page; keep opening backgrounds consistent with it or pick another archetype (warning)",
                $fg['label'], $pageSlug, $sectionSlug, $bgLabel,
                (int) round(HeaderBehavior::OVERLAY_SCRIM_ALPHA * 100),
                $min, ContrastMath::NORMAL_TEXT
            );
            $warnings++;
        }
    }

    /**
     * Check and repair the theme.json global pairs: base↔contrast (report
     * only — swapping palette hexes would wreck the design), the global link
     * color on `base`, and the button label on the button background.
     *
     * @param array<mixed> $themeJson modified in place
     * @param array<string,string> $palette
     * @param list<string> $report
     */
    private function fixThemeLevel(array &$themeJson, array $palette, array &$report, int &$repaired, int &$warnings): bool
    {
        $changed = false;
        $base = self::rgb($palette, 'base');
        $contrast = self::rgb($palette, 'contrast');

        if ($base !== null && $contrast !== null) {
            $ratio = ContrastMath::ratio($base, $contrast);
            if ($ratio < ContrastMath::NORMAL_TEXT) {
                $report[] = sprintf(
                    '[theme.json] palette base/contrast ratio %.2f is below the %.1f minimum — body text is unreadable; palette needs different hexes (warning)',
                    $ratio, ContrastMath::NORMAL_TEXT
                );
                $warnings++;
            } elseif ($ratio < self::PALETTE_TARGET) {
                $report[] = sprintf(
                    '[theme.json] palette base/contrast ratio %.2f is below the %.1f target (warning)',
                    $ratio, self::PALETTE_TARGET
                );
                $warnings++;
            }
        }

        // Global link and link hover colors on the page background — hover
        // text is body-size too, so it gets the same 4.5:1 bar as the
        // resting state.
        if ($base !== null) {
            $onBase = ['rgb' => $base, 'label' => 'base'];
            $candidates = ['primary', 'contrast', 'secondary', 'accent'];
            $changed = $this->repairThemePair(
                $themeJson, $palette, 'styles.elements.link.color.text',
                'global link color', $onBase, $candidates, false,
                $report, $repaired, $warnings
            ) || $changed;
            $changed = $this->repairThemePair(
                $themeJson, $palette, 'styles.elements.link.:hover.color.text',
                'global link hover color', $onBase, $candidates, false,
                $report, $repaired, $warnings
            ) || $changed;
        }

        // Button label on the button background.
        $btnBgValue = $themeJson['styles']['elements']['button']['color']['background'] ?? null;
        $btnBg = is_string($btnBgValue) ? self::resolve($palette, $btnBgValue) : null;
        if ($btnBg !== null) {
            $changed = $this->repairThemePair(
                $themeJson, $palette, 'styles.elements.button.color.text',
                'button text', $btnBg, ['base', 'contrast'], true,
                $report, $repaired, $warnings
            ) || $changed;
        }

        return $changed;
    }

    /**
     * Check the theme.json foreground color at dot-separated $jsonPath
     * against a background and repair it to the best-reading candidate slug.
     * With $bestEffort, a candidate that merely improves a failing ratio is
     * still taken (and the failure kept on the record) — for mid-tone
     * backgrounds nothing passes against; otherwise only a passing candidate
     * is written.
     *
     * @param array<mixed> $themeJson modified in place
     * @param array<string,string> $palette
     * @param string $label report label for the pair, e.g. 'global link color'
     * @param array{rgb: array{0:int,1:int,2:int}, label: string} $bg
     * @param list<string> $candidates repair slugs, best ratio wins
     * @param list<string> $report
     */
    private function repairThemePair(
        array &$themeJson,
        array $palette,
        string $jsonPath,
        string $label,
        array $bg,
        array $candidates,
        bool $bestEffort,
        array &$report,
        int &$repaired,
        int &$warnings,
    ): bool {
        $keys = explode('.', $jsonPath);
        $value = $themeJson;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }
        $fg = is_string($value) ? self::resolve($palette, $value) : null;
        if ($fg === null) {
            return false;
        }
        $ratio = ContrastMath::ratio($fg['rgb'], $bg['rgb']);
        if ($ratio >= ContrastMath::NORMAL_TEXT) {
            return false;
        }

        [$slug, $best] = self::best($palette, $candidates, $bg['rgb']);
        $passes = $slug !== null && $best >= ContrastMath::NORMAL_TEXT;
        $improves = $bestEffort && $slug !== null && $best > $ratio;
        if (!$passes && !$improves) {
            $report[] = sprintf(
                '[theme.json] %s %s on %s: %.2f < %.1f and no palette color %s (warning)',
                $label, $fg['label'], $bg['label'], $ratio, ContrastMath::NORMAL_TEXT,
                $bestEffort ? 'improves it' : 'passes'
            );
            $warnings++;
            return false;
        }

        $node = &$themeJson;
        foreach ($keys as $key) {
            $node = &$node[$key];
        }
        $node = "var(--wp--preset--color--{$slug})";
        $report[] = sprintf(
            '[theme.json] %s %s on %s: %.2f < %.1f → %s (%.2f)%s',
            $label, $fg['label'], $bg['label'], $ratio, ContrastMath::NORMAL_TEXT, $slug, $best,
            $passes ? ' (repaired)' : ' — best available, still below threshold (repaired) (warning)'
        );
        $repaired++;
        if (!$passes) {
            $warnings++;
        }
        return true;
    }

    // ── theme.json readers (public: CoverContrastStep reuses them) ────────

    /**
     * Resolve one authored color value — a hex literal, a bare palette slug,
     * or either preset var() form — to its uppercase hex, if any.
     *
     * @param array<string,string> $palette slug => hex (see paletteMap)
     */
    public static function paletteHex(array $palette, mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (isset($palette[$value])) {
            return strtoupper($palette[$value]);
        }
        if ((preg_match('/^var:preset\|color\|([a-z0-9_-]+)$/i', $value, $match) === 1
                || preg_match('/^var\(--wp--preset--color--([a-z0-9_-]+)\)$/i', $value, $match) === 1)
            && isset($palette[$match[1]])
        ) {
            return strtoupper($palette[$match[1]]);
        }
        return ContrastMath::hexToRgb($value) === null ? null : strtoupper($value);
    }

    /** @param array<mixed> $themeJson @return array<string,string> slug => hex */
    public static function paletteMap(array $themeJson): array
    {
        $out = [];
        foreach (($themeJson['settings']['color']['palette'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['slug'], $entry['color'])) {
                $out[(string) $entry['slug']] = (string) $entry['color'];
            }
        }
        return $out;
    }

    /** @param array<mixed> $themeJson @return array<string,string> slug => CSS gradient */
    public static function gradientMap(array $themeJson): array
    {
        $out = [];
        foreach (($themeJson['settings']['color']['gradients'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['slug'], $entry['gradient'])) {
                $out[(string) $entry['slug']] = (string) $entry['gradient'];
            }
        }
        return $out;
    }

    /** @param array<mixed> $themeJson @return array<string,string> slug => CSS size */
    public static function fontSizeMap(array $themeJson): array
    {
        $out = [];
        foreach (($themeJson['settings']['typography']['fontSizes'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['slug'], $entry['size'])) {
                $out[(string) $entry['slug']] = (string) $entry['size'];
            }
        }
        return $out;
    }

    /** @param array<string,string> $palette @return array{0:int,1:int,2:int}|null */
    private static function rgb(array $palette, string $slug): ?array
    {
        return isset($palette[$slug]) ? ContrastMath::hexToRgb($palette[$slug]) : null;
    }

    /**
     * Resolve a theme.json color value: #hex, var(--wp--preset--color--x) or
     * var:preset|color|x.
     *
     * @param array<string,string> $palette
     * @return array{rgb: array{0:int,1:int,2:int}, label: string}|null
     */
    private static function resolve(array $palette, string $value): ?array
    {
        $value = trim($value);
        if (($rgb = ContrastMath::hexToRgb($value)) !== null) {
            return ['rgb' => $rgb, 'label' => strtolower($value)];
        }
        if (preg_match('/^var\(--wp--preset--color--([a-z0-9-]+)\)$/i', $value, $m)
            || preg_match('/^var:preset\|color\|([a-z0-9-]+)$/i', $value, $m)) {
            $rgb = self::rgb($palette, $m[1]);
            return $rgb === null ? null : ['rgb' => $rgb, 'label' => $m[1]];
        }
        return null;
    }

    /**
     * The candidate slug with the best ratio against a background.
     *
     * @param array<string,string> $palette
     * @param list<string> $slugs
     * @param array{0:int,1:int,2:int} $bg
     * @return array{0: ?string, 1: float}
     */
    private static function best(array $palette, array $slugs, array $bg): array
    {
        $bestSlug = null;
        $bestRatio = 0.0;
        foreach ($slugs as $slug) {
            $rgb = self::rgb($palette, $slug);
            if ($rgb === null) {
                continue;
            }
            $r = ContrastMath::ratio($rgb, $bg);
            if ($r > $bestRatio) {
                $bestRatio = $r;
                $bestSlug = $slug;
            }
        }
        return [$bestSlug, $bestRatio];
    }
}
