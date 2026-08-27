<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Eval;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ItemPattern;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionComposition;

/** Structural metrics shared by eval entrypoints and matrix builds. */
final class EvalMetrics
{
    /** @return array<string,mixed> */
    public static function collect(Project $project): array
    {
        $metrics = [
            'name' => null,
            'fonts' => null,
            'fonts_loaded' => false,
            'pages' => 0,
            'content_blocks' => 0,
            'sections' => 0,
            'theme_bytes' => 0,
            // Layout variety, per BIGR-885. A histogram alone hides the failure
            // it is meant to expose, so the concentration is reported as its own
            // number: one archetype carrying most of a site is the defect.
            'archetypes' => [],
            'archetype_max_share' => 0.0,
            // BIGR-945. `unbalanced_split_bands` is the stranded-quadrant count
            // — the delivered bands whose regions carry media so unevenly they
            // cannot end near each other. Nothing reported this before, and it
            // is the number the merge is supposed to move.
            'split_bands' => 0,
            'unbalanced_split_bands' => 0,
            'pinned_split_bands' => 0,
        ];

        $metrics = array_merge($metrics, self::layoutMetrics($project));

        if ($project->exists('theme/functions.php')) {
            $metrics['fonts_loaded'] = str_contains(
                $project->readText('theme/functions.php'),
                'fonts.googleapis.com',
            );
        }

        if ($project->exists('siteSpec.json')) {
            $spec = $project->readJson('siteSpec.json');
            $metrics['name'] = $spec['name'] ?? null;
            $metrics['sections'] = is_array($spec['sections'] ?? null) ? count($spec['sections']) : 0;
        }

        if ($project->exists('theme/theme.json')) {
            $theme = json_decode($project->readText('theme/theme.json'), true);
            $families = $theme['settings']['typography']['fontFamilies'] ?? [];
            // Show the primary family from each stack (more accurate than the label).
            $metrics['fonts'] = implode(' + ', array_map(static function ($family) {
                $primary = trim(explode(',', (string) ($family['fontFamily'] ?? ''))[0], " \"'");
                return $primary !== '' ? $primary : ($family['name'] ?? '?');
            }, $families));
        }

        if ($project->exists('plugin/pages.json')) {
            $manifest = $project->readJson('plugin/pages.json');
            foreach ($manifest['pages'] ?? [] as $page) {
                $metrics['pages']++;
                $rel = 'plugin/pages/' . (string) ($page['slug'] ?? '') . '.html';
                if ($project->exists($rel)) {
                    $metrics['content_blocks'] += preg_match_all('/<!--\s*wp:/', $project->readText($rel));
                }
            }
        }

        foreach (glob($project->themePath('') . '/{,*/}*.{html,json,css,txt}', GLOB_BRACE) ?: [] as $file) {
            $metrics['theme_bytes'] += filesize($file);
        }
        foreach (glob($project->pluginPath('') . '/{,*/}*.{html,json,php}', GLOB_BRACE) ?: [] as $file) {
            $metrics['theme_bytes'] += filesize($file);
        }

        return $metrics;
    }

    /**
     * Layout-variety metrics over one built site.
     *
     * Every number here is read from artifacts, never recomputed from a prompt:
     * the archetype each section was PLANNED as comes from `pages.json`, and
     * whether the delivered band honoured it comes from the assembled page
     * markup the build actually wrote. A site missing either artifact
     * contributes zeros rather than a partial figure that would read as a
     * measurement.
     *
     * @return array<string,mixed>
     */
    private static function layoutMetrics(Project $project): array
    {
        if (!$project->exists('pages.json')) {
            return [];
        }

        $histogram = [];
        foreach (($project->readJson('pages.json')['pages'] ?? []) as $page) {
            foreach ((array) ($page['sections'] ?? []) as $section) {
                $archetype = (string) ($section['layout_archetype'] ?? '');
                if (SectionComposition::isKnown($archetype)) {
                    $histogram[$archetype] = ($histogram[$archetype] ?? 0) + 1;
                }
            }
        }
        arsort($histogram);
        $planned = array_sum($histogram);

        return [
            'archetypes' => $histogram,
            'archetype_max_share' => $planned > 0 ? round(max($histogram) / $planned, 3) : 0.0,
        ] + self::deliveredBandMetrics($project);
    }

    /**
     * The delivered half: what the band markup actually says, not what the plan
     * asked for.
     *
     * Read from the ASSEMBLED pages, not from `theme/parts/`. A section part is
     * an intermediate artifact — `assemble-pages` folds it into the page and a
     * finished project keeps only `header.html` and `footer.html` under parts,
     * so a parts-only reader silently scores every completed build as zero.
     * The patterns directory carries the same bands a second time, so it is
     * deliberately not read: counting it would double every number here.
     *
     * Each band is identified by the root marker class it carries, and its item
     * pattern by the `item-pattern--<id>` class beside it, so this needs no
     * slug-to-part mapping and cannot drift from one.
     *
     * @return array<string,int>
     */
    private static function deliveredBandMetrics(Project $project): array
    {
        $splits = 0;
        $unbalanced = 0;
        $pinned = 0;

        foreach (self::deliveredPageMarkup($project) as $path => $markup) {
            $document = BlockMarkup::parse($markup);
            foreach ($document->indices() as $index) {
                $classes = preg_split(
                    '/\s+/',
                    trim((string) (($document->attrs($index) ?? [])['className'] ?? '')),
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                ) ?: [];

                $archetype = null;
                $itemPattern = null;
                foreach ($classes as $token) {
                    if (str_starts_with($token, SectionComposition::MARKER_PREFIX)) {
                        $archetype = substr($token, strlen(SectionComposition::MARKER_PREFIX));
                    } elseif (str_starts_with($token, ItemPattern::MARKER_PREFIX)) {
                        $itemPattern = substr($token, strlen(ItemPattern::MARKER_PREFIX));
                    }
                }
                if ($archetype === null
                    || !SectionComposition::isKnown($archetype)
                    || !SectionComposition::hasUnequalRegions($archetype)
                ) {
                    continue;
                }

                // The whole subtree, delimiters included, so the band reparses
                // with its own root on top. `ownHtml()` would stop at the first
                // child and hide every region this measures.
                $end = $document->endOffset($index);
                if ($end === null) {
                    continue;
                }
                $start = $document->openingOffset($index);
                $band = substr($markup, $start, $end - $start);

                $splits++;
                if (str_contains($band, SectionComposition::PIN_CLASS)) {
                    $pinned++;
                }
                foreach (SectionComposition::markupWarnings($band, $archetype, $path, $itemPattern) as $row) {
                    if (str_contains($row, 'archetype region balance')) {
                        $unbalanced++;
                        break;
                    }
                }
            }
        }

        return [
            'split_bands' => $splits,
            'unbalanced_split_bands' => $unbalanced,
            'pinned_split_bands' => $pinned,
        ];
    }

    /**
     * Delivered page markup, keyed by project-relative path. Assembled pages
     * are the finished artifact; the section parts are the fallback for a
     * project inspected before `assemble-pages` ran.
     *
     * @return array<string,string>
     */
    private static function deliveredPageMarkup(Project $project): array
    {
        $sources = [];
        foreach (glob($project->pluginPath('pages') . '/*.html') ?: [] as $file) {
            $sources['plugin/pages/' . basename($file)] = (string) file_get_contents($file);
        }
        if ($sources !== []) {
            return $sources;
        }

        foreach (glob($project->themePath('parts') . '/*.html') ?: [] as $file) {
            $name = basename($file, '.html');
            if ($name === 'header' || $name === 'footer') {
                continue;
            }
            $sources['theme/parts/' . basename($file)] = (string) file_get_contents($file);
        }
        return $sources;
    }
}
