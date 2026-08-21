<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Eval;

use Automattic\SiteBuild\Project;

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
        ];

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
}
