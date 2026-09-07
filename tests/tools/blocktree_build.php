<?php
declare(strict_types=1);

/**
 * Rebuild a project's sections from patterns the model chooses and fills,
 * into a copy of an existing theme so everything but the sections stays
 * identical. The point is a side-by-side screenshot against the original.
 *
 * Usage:
 *   php tests/tools/blocktree_build.php --project=projects/dapper-garden --out=<dir> [--model=claude-opus-5] [--only=slug,slug]
 *
 * Writes <dir>/theme (a copy of the project's theme with parts/section-*.html
 * replaced) and <dir>/specs/<slug>.json (what the model returned).
 */

require __DIR__ . '/../../autoload.php';
require __DIR__ . '/SpecRenderer.php';
require __DIR__ . '/Patterns.php';

use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\ClaudeCliLlm;
use Automattic\SiteBuild\Tools\Patterns;
use Automattic\SiteBuild\Tools\SpecRenderer;

$opts = getopt('', ['project:', 'out:', 'model::', 'only::', 'replay']);
$projectDir = rtrim($opts['project'] ?? '', '/');
$outDir = rtrim($opts['out'] ?? '', '/');
$model = $opts['model'] ?? 'claude-opus-5';
$only = isset($opts['only']) ? explode(',', $opts['only']) : null;
// --replay re-renders from the specs the model already returned, so a
// pattern change can be seen without spending model calls.
$replay = array_key_exists('replay', $opts);
if ($projectDir === '' || $outDir === '') {
    fwrite(STDERR, "--project and --out are required\n");
    exit(2);
}

$siteSpec = json_decode(file_get_contents("$projectDir/siteSpec.json"), true);
$direction = json_decode(file_get_contents("$projectDir/designDirection.json"), true);
$sections = json_decode(file_get_contents("$projectDir/sections.json"), true)['sections'];
$themeSlug = basename($projectDir);
$idiom = Patterns::idiom("$projectDir/theme");
$language = $siteSpec['language'] ?? 'English';
$json = fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Copy the theme once; only parts/section-*.html change.
if (!is_dir("$outDir/theme")) {
    @mkdir("$outDir/specs", 0777, true);
    exec(sprintf('cp -R %s %s', escapeshellarg("$projectDir/theme"), escapeshellarg("$outDir/theme")));
}

$template = file_get_contents(__DIR__ . '/inner-section-pattern.md');
$schema = Patterns::schema($idiom);
$llm = new ClaudeCliLlm($model);
$registry = new BlockRegistry();
$renderer = new SpecRenderer($registry);
$serializer = new Serializer($registry);

printf("%-26s %-22s %-7s %-6s %s\n", 'section', 'pattern', 'blocks', 'fixed', 'time');
foreach ($sections as $section) {
    $slug = (string) $section['slug'];
    if ($only !== null && !in_array($slug, $only, true)) { continue; }
    $started = microtime(true);
    $prompt = strtr($template, [
        '{{site_spec}}'        => $json($siteSpec),
        '{{design_direction}}' => $json($direction),
        '{{page_outline}}'     => $json(['sections' => array_map(
            fn ($s) => array_intersect_key($s, array_flip(['slug', 'title', 'type', 'purpose'])),
            $sections,
        )]),
        '{{section_spec}}'     => $json($section),
        '{{section_slug}}'     => $slug,
        '{{language}}'         => $language,
        '{{assets}}'           => implode(', ', array_map(fn ($a) => "`$a`", array_diff($idiom['assets'], [$idiom['ornament']]))),
    ]);
    try {
        if ($replay) {
            $raw = file_get_contents("$outDir/specs/$slug.json");
        } else {
            $raw = $llm->complete($prompt, ['json_schema' => ['name' => 'section_pattern', 'schema' => $schema]]);
            file_put_contents("$outDir/specs/$slug.json", $raw);
        }
        $choice = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $spec = Patterns::spec($choice['pattern'], $choice['params'], [
            'slug' => $slug,
            'theme' => $themeSlug,
            'idiom' => $idiom,
            'first' => $slug === $sections[0]['slug'],
        ]);
        $html = $renderer->document([$spec]);
        // Ship what the pipeline ships: its own normalizer's output. `fixed`
        // records whether the spec already was that.
        $normalized = $serializer->transform($html)->html;
        $fixed = $normalized === $html;
        file_put_contents("$outDir/theme/parts/section-$slug.html", $normalized);
        printf("%-26s %-22s %-7d %-6s %.0fs\n", $slug, $choice['pattern'], substr_count($html, '<!-- wp:'), $fixed ? 'yes' : 'NO', microtime(true) - $started);
    } catch (\Throwable $e) {
        printf("%-26s FAILED: %s\n", $slug, $e->getMessage());
    }
}
echo "theme in $outDir/theme\n";
