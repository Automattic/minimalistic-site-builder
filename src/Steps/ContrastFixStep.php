<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ContrastFix;
use Automattic\SiteBuild\ContrastMath;
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
            writes: ['theme/theme.json', 'theme/parts/*'],
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

        foreach (['parts', 'templates'] as $dir) {
            foreach (glob($project->themePath($dir . '/*.html')) ?: [] as $abs) {
                $rel = $dir . '/' . basename($abs);
                $markup = $project->readText('theme/' . $rel);
                // The header floats over the hero — lint only (see class doc).
                $repair = basename($abs) !== 'header.html';
                $result = $fix->process($markup, $repair);
                if ($result['changed']) {
                    $project->writeText('theme/' . $rel, $result['markup']);
                }
                foreach ($result['findings'] as $f) {
                    $report[] = sprintf('[%s] %s %s', $rel, $f['detail'], $f['repaired'] ? '(repaired)' : '(warning)');
                    $f['repaired'] ? $repaired++ : $warnings++;
                }
            }
        }

        $this->lintOverlayHeader($project, $fix, is_string($defaultText) ? $defaultText : null, $report, $warnings);

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
     * Overlay-header lint. A `header-overlay` header renders transparently on
     * EVERY page, floating over each page's FIRST section — but the header
     * and the sections are generated concurrently, blind to each other, so
     * nothing upstream guarantees the one text color the header committed to
     * reads against every page's opening background. This is the
     * deterministic backstop: the header's effective text color is checked
     * against each page's first-section background. Warnings only — the right
     * fix (recolor the header, darken the section, or drop the overlay) is a
     * design decision this step must not make — and image-backed covers are
     * skipped like everywhere else in phase one (their pixels are unknowable
     * until images exist).
     *
     * @param list<string> $report
     */
    private function lintOverlayHeader(Project $project, ContrastFix $fix, ?string $defaultText, array &$report, int &$warnings): void
    {
        if (!$project->exists('pages.json') || !$project->exists('theme/parts/header.html')) {
            return;
        }
        $header = BlockMarkup::parse($project->readText('theme/parts/header.html'));
        $top = self::topLevelIndex($header);
        if ($top === null) {
            return;
        }
        $attrs = $header->attrs($top) ?? [];
        if (!str_contains((string) ($attrs['className'] ?? ''), 'header-overlay')) {
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
            $secTop = self::topLevelIndex($section);
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

            $min = PHP_FLOAT_MAX;
            foreach ($bg as $color) {
                $min = min($min, ContrastMath::ratio($fg['rgb'], $color));
            }
            if ($min >= ContrastMath::NORMAL_TEXT) {
                continue;
            }
            $report[] = sprintf(
                "[parts/header.html] overlay header text %s floats over page '%s' opening section '%s' on %s: %.2f < %.1f — the transparent header renders on EVERY page; keep opening backgrounds consistent with it or pick another archetype (warning)",
                $fg['label'], $pageSlug, $sectionSlug, $bgLabel, $min, ContrastMath::NORMAL_TEXT
            );
            $warnings++;
        }
    }

    /** The first root node of a parsed part (its top-level block). */
    private static function topLevelIndex(BlockMarkup $doc): ?int
    {
        foreach ($doc->indices() as $i) {
            if ($doc->parent($i) === null) {
                return $i;
            }
        }
        return null;
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

        // Global link color on the page background.
        $linkValue = $themeJson['styles']['elements']['link']['color']['text'] ?? null;
        $link = is_string($linkValue) ? self::resolve($palette, $linkValue) : null;
        if ($base !== null && $link !== null) {
            $ratio = ContrastMath::ratio($link['rgb'], $base);
            if ($ratio < ContrastMath::NORMAL_TEXT) {
                [$slug, $best] = self::best($palette, ['primary', 'contrast', 'secondary', 'accent'], $base);
                if ($slug !== null && $best >= ContrastMath::NORMAL_TEXT) {
                    $themeJson['styles']['elements']['link']['color']['text'] = "var(--wp--preset--color--{$slug})";
                    $report[] = sprintf(
                        '[theme.json] global link color %s on base: %.2f < %.1f → %s (%.2f) (repaired)',
                        $link['label'], $ratio, ContrastMath::NORMAL_TEXT, $slug, $best
                    );
                    $repaired++;
                    $changed = true;
                } else {
                    $report[] = sprintf(
                        '[theme.json] global link color %s on base: %.2f < %.1f and no palette color passes (warning)',
                        $link['label'], $ratio, ContrastMath::NORMAL_TEXT
                    );
                    $warnings++;
                }
            }
        }

        // Global link hover color on the page background — hover text is
        // body-size too, so it gets the same 4.5:1 bar as the resting state.
        $hoverValue = $themeJson['styles']['elements']['link'][':hover']['color']['text'] ?? null;
        $hover = is_string($hoverValue) ? self::resolve($palette, $hoverValue) : null;
        if ($base !== null && $hover !== null) {
            $ratio = ContrastMath::ratio($hover['rgb'], $base);
            if ($ratio < ContrastMath::NORMAL_TEXT) {
                [$slug, $best] = self::best($palette, ['primary', 'contrast', 'secondary', 'accent'], $base);
                if ($slug !== null && $best >= ContrastMath::NORMAL_TEXT) {
                    $themeJson['styles']['elements']['link'][':hover']['color']['text'] = "var(--wp--preset--color--{$slug})";
                    $report[] = sprintf(
                        '[theme.json] global link hover color %s on base: %.2f < %.1f → %s (%.2f) (repaired)',
                        $hover['label'], $ratio, ContrastMath::NORMAL_TEXT, $slug, $best
                    );
                    $repaired++;
                    $changed = true;
                } else {
                    $report[] = sprintf(
                        '[theme.json] global link hover color %s on base: %.2f < %.1f and no palette color passes (warning)',
                        $hover['label'], $ratio, ContrastMath::NORMAL_TEXT
                    );
                    $warnings++;
                }
            }
        }

        // Button label on the button background.
        $btnBgValue = $themeJson['styles']['elements']['button']['color']['background'] ?? null;
        $btnTextValue = $themeJson['styles']['elements']['button']['color']['text'] ?? null;
        $btnBg = is_string($btnBgValue) ? self::resolve($palette, $btnBgValue) : null;
        $btnText = is_string($btnTextValue) ? self::resolve($palette, $btnTextValue) : null;
        if ($btnBg !== null && $btnText !== null) {
            $ratio = ContrastMath::ratio($btnText['rgb'], $btnBg['rgb']);
            if ($ratio < ContrastMath::NORMAL_TEXT) {
                [$slug, $best] = self::best($palette, ['base', 'contrast'], $btnBg['rgb']);
                if ($slug !== null && $best >= ContrastMath::NORMAL_TEXT) {
                    $themeJson['styles']['elements']['button']['color']['text'] = "var(--wp--preset--color--{$slug})";
                    $report[] = sprintf(
                        '[theme.json] button text %s on %s: %.2f < %.1f → %s (%.2f) (repaired)',
                        $btnText['label'], $btnBg['label'], $ratio, ContrastMath::NORMAL_TEXT, $slug, $best
                    );
                    $repaired++;
                    $changed = true;
                } elseif ($slug !== null && $best > $ratio) {
                    // Mid-tone button background nothing passes against: take
                    // the improvement but keep the failure on the record.
                    $themeJson['styles']['elements']['button']['color']['text'] = "var(--wp--preset--color--{$slug})";
                    $report[] = sprintf(
                        '[theme.json] button text %s on %s: %.2f < %.1f → %s (%.2f) — best available, still below threshold (repaired)',
                        $btnText['label'], $btnBg['label'], $ratio, ContrastMath::NORMAL_TEXT, $slug, $best
                    );
                    $repaired++;
                    $changed = true;
                } else {
                    $report[] = sprintf(
                        '[theme.json] button text %s on %s: %.2f < %.1f and no palette color improves it (warning)',
                        $btnText['label'], $btnBg['label'], $ratio, ContrastMath::NORMAL_TEXT
                    );
                    $warnings++;
                }
            }
        }

        return $changed;
    }

    // ── theme.json readers (public: CoverContrastStep reuses them) ────────

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
