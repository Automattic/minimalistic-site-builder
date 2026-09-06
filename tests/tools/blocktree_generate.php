<?php
declare(strict_types=1);

/**
 * Can the model produce a block spec directly, instead of markup?
 *
 * Renders the section prompt with a block-tree output contract, asks the
 * model N times through the same transport the pipeline uses, and pushes
 * every answer through SpecRenderer and then Serializer::transform() — the
 * pipeline's own normalizer — to see whether it comes out valid, and how
 * many repairs it needs.
 *
 * Usage:
 *   php tests/tools/blocktree_generate.php --project=projects/dapper-garden \
 *       --section=philosophy-class-types [--runs=5] [--model=claude-opus-5] [--out=dir]
 */

require __DIR__ . '/../../autoload.php';
require __DIR__ . '/SpecRenderer.php';

use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\ClaudeCliLlm;
use Automattic\SiteBuild\Tools\SpecRenderer;

$opts = getopt('', ['project:', 'section:', 'runs::', 'model::', 'out::']);
$projectDir = $opts['project'] ?? null;
$sectionSlug = $opts['section'] ?? null;
$runs = (int) ($opts['runs'] ?? 5);
$model = $opts['model'] ?? 'claude-opus-5';
$outDir = $opts['out'] ?? sys_get_temp_dir() . '/blocktree-runs';
if ($projectDir === null || $sectionSlug === null) {
    fwrite(STDERR, "--project and --section are required\n");
    exit(2);
}
@mkdir($outDir, 0777, true);

$siteSpec = json_decode(file_get_contents("$projectDir/siteSpec.json"), true);
$direction = json_decode(file_get_contents("$projectDir/designDirection.json"), true);
$sections = json_decode(file_get_contents("$projectDir/sections.json"), true)['sections'];
$section = null;
foreach ($sections as $s) {
    if (($s['slug'] ?? '') === $sectionSlug) { $section = $s; }
}
if ($section === null) {
    fwrite(STDERR, "section '$sectionSlug' not found in $projectDir/sections.json\n");
    exit(2);
}
$language = $siteSpec['language'] ?? 'English';
$json = fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$prompt = strtr(file_get_contents(__DIR__ . '/inner-section-blocktree.md'), [
    '{{site_spec}}'        => $json($siteSpec),
    '{{design_direction}}' => $json($direction),
    '{{page_outline}}'     => $json(['sections' => array_map(
        fn ($s) => array_intersect_key($s, array_flip(['slug', 'title', 'type', 'purpose'])),
        $sections,
    )]),
    '{{section_spec}}'     => $json($section),
    '{{section_slug}}'     => $sectionSlug,
    '{{language}}'         => $language,
    '{{image}}'            => 'image://placeholder',
]);

$registry = new BlockRegistry();
$domain = array_keys(require __DIR__ . '/../../src/BlockSerializer/Registry/supported-blocks.php');
$blockSchema = [
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string', 'enum' => $domain],
        'attrs' => ['type' => 'object'],
        'innerBlocks' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/block']],
    ],
    'required' => ['name', 'attrs', 'innerBlocks'],
    'additionalProperties' => false,
];
$schema = [
    'type' => 'object',
    '$defs' => ['block' => $blockSchema],
    'properties' => ['blocks' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/block']]],
    'required' => ['blocks'],
    'additionalProperties' => false,
];

$llm = new ClaudeCliLlm($model);
$renderer = new SpecRenderer($registry);
$serializer = new Serializer($registry);

/** Every block name in the tree, flattened. @return list<string> */
$names = function (array $blocks) use (&$names): array {
    $out = [];
    foreach ($blocks as $b) {
        $out[] = (string) ($b['name'] ?? '?');
        $out = array_merge($out, $names($b['innerBlocks'] ?? []));
    }
    return $out;
};

printf("%-4s %-8s %-7s %-6s %-9s %-7s %s\n", 'run', 'json', 'blocks', 'in-dom', 'rendered', 'fixed', 'repairs');
$summary = ['json' => 0, 'domain' => 0, 'rendered' => 0, 'fixed' => 0, 'repairs' => 0];
for ($i = 1; $i <= $runs; $i++) {
    $started = microtime(true);
    try {
        $raw = $llm->complete($prompt, ['json_schema' => ['name' => 'block_tree', 'schema' => $schema]]);
    } catch (\Throwable $e) {
        printf("%-4d transport error: %s\n", $i, $e->getMessage());
        continue;
    }
    file_put_contents("$outDir/$sectionSlug-$i.json", $raw);
    $specs = null;
    try {
        $specs = SpecRenderer::fromJson($raw);
    } catch (\Throwable $e) {
        printf("%-4d %-8s %s\n", $i, 'FAIL', $e->getMessage());
        continue;
    }
    $summary['json']++;
    $all = $names($specs);
    $outside = array_values(array_unique(array_diff($all, $domain)));
    $inDomain = $outside === [];
    $summary['domain'] += (int) $inDomain;

    $rendered = null; $renderError = null;
    try {
        $rendered = $renderer->document($specs);
        file_put_contents("$outDir/$sectionSlug-$i.html", $rendered);
    } catch (\Throwable $e) {
        $renderError = $e->getMessage();
    }
    $summary['rendered'] += (int) ($rendered !== null);

    $fixed = false; $repairs = [];
    if ($rendered !== null) {
        $result = $serializer->transform($rendered);
        $fixed = $result->html === $rendered;
        $repairs = array_map(fn ($r) => $r->code, $result->repairs);
        $summary['fixed'] += (int) $fixed;
        $summary['repairs'] += count($repairs);
    }

    printf(
        "%-4d %-8s %-7d %-6s %-9s %-7s %s  (%.0fs)\n",
        $i,
        'ok',
        count($all),
        $inDomain ? 'yes' : 'NO',
        $rendered !== null ? 'yes' : 'NO',
        $fixed ? 'yes' : 'no',
        $repairs ? implode(',', array_map(fn ($code, $n) => "$code×$n", array_keys(array_count_values($repairs)), array_count_values($repairs))) : '-',
        microtime(true) - $started,
    );
    if (!$inDomain) { printf("     outside domain: %s\n", implode(', ', $outside)); }
    if ($renderError !== null) { printf("     render error: %s\n", $renderError); }
}

printf(
    "\n%d runs: json %d, in-domain %d, rendered %d, fixed-point %d, total repairs %d\noutputs in %s\n",
    $runs, $summary['json'], $summary['domain'], $summary['rendered'], $summary['fixed'], $summary['repairs'], $outDir,
);
