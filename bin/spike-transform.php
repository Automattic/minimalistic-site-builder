#!/usr/bin/env php
<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\Steps\ContrastFixStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Steps\MotionSanityStep;
use Automattic\SiteBuild\Steps\NormalizeLayoutStep;

require dirname(__DIR__) . '/autoload.php';

const SPIKE_FIXUP_IDS = [
    'normalize-layout',
    'contrast-fix',
    'motion-sanity',
    'fix-blocks',
];

/**
 * @param array<int,array<string,mixed>> $blocks
 * @param array<string,int> $histogram
 */
function spike_count_blocks(array $blocks, array &$histogram): void
{
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $name = is_string($block['blockName'] ?? null) && $block['blockName'] !== ''
            ? $block['blockName']
            : 'unknown';
        $histogram[$name] = ($histogram[$name] ?? 0) + 1;
        spike_count_blocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [], $histogram);
    }
}

/**
 * @param array<int,array<string,mixed>> $assets
 */
function spike_carried_css_bytes(array $assets): int
{
    $bytes = 0;
    foreach ($assets as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $isCss = ($asset['kind'] ?? null) === 'css'
            || ($asset['mime_type'] ?? null) === 'text/css'
            || ($asset['media_type'] ?? null) === 'text/css';
        if ($isCss && is_string($asset['content'] ?? null)) {
            $bytes += strlen($asset['content']);
        }
    }
    return $bytes;
}

/**
 * @param array<int,array<string,mixed>> $templateParts
 * @return array<string,string>
 */
function spike_template_parts(array $templateParts): array
{
    $parts = [];
    foreach ($templateParts as $part) {
        if (!is_array($part)) {
            continue;
        }
        $area = is_string($part['area'] ?? null) ? $part['area'] : '';
        $markup = is_string($part['block_markup'] ?? null) ? $part['block_markup'] : '';
        if (in_array($area, ['header', 'footer'], true) && trim($markup) !== '') {
            $parts[$area . '.html'] = $markup;
        }
    }
    return $parts;
}

/** @return array<string,string> theme-relative path => bytes */
function spike_snapshot_parts(Project $project): array
{
    $snapshot = [];
    foreach (glob($project->themePath('parts/*.html')) ?: [] as $path) {
        $snapshot['parts/' . basename($path)] = $project->readText('theme/parts/' . basename($path));
    }
    ksort($snapshot);
    return $snapshot;
}

/** @param array<string,string> $snapshot */
function spike_restore_parts(Project $project, array $snapshot): void
{
    foreach ($snapshot as $relative => $markup) {
        $project->writeText('theme/' . $relative, $markup);
    }
}

/**
 * Capture visitor-visible geometry carriers separately in saved HTML and block
 * attributes. Missing evidence after a fixup means the carried styling was lost.
 *
 * @param array<string,string> $snapshot
 * @return array<string,true>
 */
function spike_carrier_evidence(array $snapshot): array
{
    $evidence = [];
    foreach ($snapshot as $file => $markup) {
        if (preg_match_all('/\bclass=(["\'])(.*?)\1/is', $markup, $classMatches, PREG_SET_ORDER)) {
            foreach ($classMatches as $match) {
                $tokens = preg_split('/\s+/', trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')))
                    ?: [];
                foreach ($tokens as $token) {
                    if (preg_match('/^(?:be-inline-geometry-|carrier-|geometry-)/', $token) === 1) {
                        $evidence[$file . "\0html-class\0" . $token] = true;
                    }
                }
            }
        }
        if (preg_match_all('/"className":"((?:\\\\.|[^"\\\\])*)"/', $markup, $classNameMatches)) {
            foreach ($classNameMatches[1] as $encoded) {
                $decoded = json_decode('"' . $encoded . '"');
                if (!is_string($decoded)) {
                    continue;
                }
                foreach (preg_split('/\s+/', trim($decoded)) ?: [] as $token) {
                    if (preg_match('/^(?:be-inline-geometry-|carrier-|geometry-)/', $token) === 1) {
                        $evidence[$file . "\0json-class\0" . $token] = true;
                    }
                }
            }
        }
        if (preg_match_all('/\bstyle=(["\'])(.*?)\1/is', $markup, $styleMatches, PREG_SET_ORDER)) {
            foreach ($styleMatches as $match) {
                $style = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($style !== '') {
                    $evidence[$file . "\0html-style\0" . $style] = true;
                }
            }
        }
        if (preg_match_all('/"inlineGeometryStyle":"((?:\\\\.|[^"\\\\])*)"/', $markup, $geometryMatches)) {
            foreach ($geometryMatches[1] as $encoded) {
                $decoded = json_decode('"' . $encoded . '"');
                if (is_string($decoded) && trim($decoded) !== '') {
                    $evidence[$file . "\0json-style\0" . trim($decoded)] = true;
                }
            }
        }
    }
    return $evidence;
}

/**
 * @param array<string,string> $before
 * @param array<string,string> $after
 * @return array{outcome:string,changed_files:list<string>,lost_evidence:list<string>}
 */
function spike_classify_fixup(array $before, array $after): array
{
    $changed = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $file) {
        if (($before[$file] ?? null) !== ($after[$file] ?? null)) {
            $changed[] = $file;
        }
    }
    sort($changed);
    if ($changed === []) {
        return ['outcome' => 'noop', 'changed_files' => [], 'lost_evidence' => []];
    }

    $lost = array_keys(array_diff_key(spike_carrier_evidence($before), spike_carrier_evidence($after)));
    sort($lost);
    return [
        'outcome' => $lost === [] ? 'mutated' : 'destroyed',
        'changed_files' => $changed,
        'lost_evidence' => $lost,
    ];
}

/**
 * @return array{outcome:string,changed_files:list<string>,lost_evidence:list<string>,error:?string}
 */
function spike_run_fixup(Project $project, Step $step): array
{
    $before = spike_snapshot_parts($project);
    $error = null;
    ob_start();
    try {
        $step->run($project);
    } catch (Throwable $caught) {
        $error = $caught::class . ': ' . $caught->getMessage();
        spike_restore_parts($project, $before);
    } finally {
        ob_end_clean();
    }
    $classified = spike_classify_fixup($before, spike_snapshot_parts($project));
    return $classified + ['error' => $error];
}

/** @param array<string,mixed> $report */
function spike_validate_report(array $report, string $schemaPath): void
{
    $schemaText = file_get_contents($schemaPath);
    if ($schemaText === false) {
        throw new RuntimeException("Could not read report schema: {$schemaPath}");
    }
    $schema = json_decode($schemaText, true, 512, JSON_THROW_ON_ERROR);
    $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
    $actualKeys = array_keys($report);
    sort($required);
    sort($actualKeys);
    if ($required !== $actualKeys) {
        throw new RuntimeException('Spike report keys do not match the frozen schema.');
    }
    if (!is_int($report['fallback_count'])
        || !is_array($report['fallback_codes'])
        || !is_array($report['block_histogram'])
        || !is_int($report['carried_css_bytes'])
        || !is_array($report['fixup_outcomes'])
        || !is_bool($report['header_detected'])
        || !is_bool($report['footer_detected'])) {
        throw new RuntimeException('Spike report value types do not match the frozen schema.');
    }
    if (array_keys($report['fixup_outcomes']) !== SPIKE_FIXUP_IDS) {
        throw new RuntimeException('Spike report fixup ids do not match the frozen schema.');
    }
    foreach ($report['fixup_outcomes'] as $outcome) {
        if (!in_array($outcome, ['noop', 'mutated', 'destroyed'], true)) {
            throw new RuntimeException('Spike report contains an invalid fixup outcome.');
        }
    }
}

/**
 * @param array<string,mixed> $report
 * @param array<string,array{outcome:string,changed_files:list<string>,lost_evidence:list<string>,error:?string}> $details
 */
function spike_markdown(array $report, array $details, string $status, string $upstreamCommit): string
{
    $lines = [
        '# Transformer spike report',
        '',
        'Generated by `php bin/spike-transform.php`.',
        '',
        '- Upstream commit: `' . $upstreamCommit . '`',
        '- Transformer status: `' . $status . '`',
        '- Fallback count: ' . $report['fallback_count'],
        '- Carried CSS bytes: ' . $report['carried_css_bytes'],
        '- Header detected: ' . ($report['header_detected'] ? 'yes' : 'no'),
        '- Footer detected: ' . ($report['footer_detected'] ? 'yes' : 'no'),
        '',
        '## Fallback codes',
        '',
    ];
    if ($report['fallback_codes'] === []) {
        $lines[] = '- None';
    } else {
        foreach ($report['fallback_codes'] as $code) {
            $lines[] = '- `' . $code . '`';
        }
    }

    $lines[] = '';
    $lines[] = '## Block histogram';
    $lines[] = '';
    $lines[] = '| Block | Count |';
    $lines[] = '|---|---:|';
    foreach ($report['block_histogram'] as $block => $count) {
        $lines[] = '| `' . $block . '` | ' . $count . ' |';
    }

    $lines[] = '';
    $lines[] = '## Fixup outcomes';
    $lines[] = '';
    $lines[] = '| Fixup | Outcome | Changed parts | Lost carrier evidence |';
    $lines[] = '|---|---|---:|---:|';
    foreach ($report['fixup_outcomes'] as $id => $outcome) {
        $detail = $details[$id];
        $lines[] = '| `' . $id . '` | `' . $outcome . '` | '
            . count($detail['changed_files']) . ' | ' . count($detail['lost_evidence']) . ' |';
        foreach ($detail['lost_evidence'] as $lost) {
            $lines[] = '';
            $lines[] = '- `' . $id . '` lost carrier evidence: `'
                . str_replace(["\0", '`'], [' / ', '\\`'], $lost) . '`';
        }
        if ($detail['error'] !== null) {
            $lines[] = '';
            $lines[] = '- `' . $id . '` abandoned and restored input bytes: `' . str_replace('`', '\\`', $detail['error']) . '`';
        }
    }
    $lines[] = '';
    $lines[] = 'Two deliberate source probes: one inline `<svg>` and one `<form>`. Reported fallback count is raw; no probe subtraction applied.';
    $lines[] = '';
    return implode("\n", $lines);
}

/** @param array<mixed> $value */
function spike_write_json(string $path, array $value): void
{
    $json = json_encode(
        $value,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR
    ) . "\n";
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("Could not write report: {$path}");
    }
}

function spike_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

$repoRoot = dirname(__DIR__);
$fixturePath = $repoRoot . '/tests/fixtures/design/spike-home.html';
$schemaPath = $repoRoot . '/eval/spike-transform-report.schema.json';
$upstreamCommitPath = $repoRoot . '/lib/blocks-engine-php-transformer/UPSTREAM_COMMIT';
$temporaryRoot = sys_get_temp_dir() . '/site-build-transform-spike-' . bin2hex(random_bytes(6));

try {
    $html = file_get_contents($fixturePath);
    if ($html === false) {
        throw new RuntimeException("Could not read spike fixture: {$fixturePath}");
    }
    $upstreamCommit = trim((string) file_get_contents($upstreamCommitPath));
    if (preg_match('/^[0-9a-f]{40}$/', $upstreamCommit) !== 1) {
        throw new RuntimeException('UPSTREAM_COMMIT must contain a 40-hex sha.');
    }

    $result = (new ArtifactCompiler())->compile(['generated_html' => $html]);
    $compiledSite = is_array($result->sourceReports['compiled_site'] ?? null)
        ? $result->sourceReports['compiled_site']
        : [];
    $templateParts = is_array($compiledSite['template_parts'] ?? null)
        ? $compiledSite['template_parts']
        : [];
    $parts = spike_template_parts($templateParts);
    $pages = is_array($compiledSite['pages'] ?? null) ? $compiledSite['pages'] : [];
    $pageMarkup = is_string($pages[0]['block_markup'] ?? null)
        ? $pages[0]['block_markup']
        : $result->serializedBlocks;
    if (trim($pageMarkup) === '') {
        throw new RuntimeException('ArtifactCompiler emitted no serialized page blocks.');
    }
    $parts['page-home--spike.html'] = $pageMarkup;

    if (!mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) {
        throw new RuntimeException("Could not create spike project: {$temporaryRoot}");
    }
    $project = new Project($temporaryRoot);
    $project->writeJson('theme/theme.json', [
        'version' => 3,
        'settings' => [
            'layout' => ['contentSize' => '768px', 'wideSize' => '1184px'],
            'spacing' => ['spacingSizes' => [
                ['slug' => '20', 'size' => '0.5rem', 'name' => '2XS'],
                ['slug' => '40', 'size' => '1rem', 'name' => 'S'],
                ['slug' => '60', 'size' => '2rem', 'name' => 'L'],
            ]],
            'color' => ['palette' => [
                ['slug' => 'base', 'color' => '#f6f2e8', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#17322b', 'name' => 'Contrast'],
                ['slug' => 'primary', 'color' => '#a93d20', 'name' => 'Primary'],
            ]],
        ],
        'styles' => [
            'color' => [
                'background' => 'var(--wp--preset--color--base)',
                'text' => 'var(--wp--preset--color--contrast)',
            ],
            'elements' => [
                'link' => ['color' => ['text' => 'var(--wp--preset--color--primary)']],
            ],
        ],
    ]);
    $project->writeJson('designDirection.json', ['description' => 'Transformer spike', 'motion' => 'none']);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
        'sections' => [['slug' => 'spike', 'title' => 'Spike homepage', 'type' => 'content']],
    ]]]);
    foreach ($parts as $filename => $markup) {
        $project->writeText('theme/parts/' . $filename, $markup);
    }

    $steps = [
        new NormalizeLayoutStep(),
        new ContrastFixStep(),
        new MotionSanityStep(),
        new FixBlocksStep(new PhpBlockFixer()),
    ];
    $details = [];
    $fixupOutcomes = [];
    foreach ($steps as $step) {
        $details[$step->id()] = spike_run_fixup($project, $step);
        $fixupOutcomes[$step->id()] = $details[$step->id()]['outcome'];
    }

    $fallbackCodes = [];
    foreach ($result->fallbacks as $fallback) {
        if (!is_array($fallback)) {
            continue;
        }
        $code = (string) ($fallback['diagnostic_code'] ?? $fallback['code'] ?? 'unknown');
        if ($code !== '') {
            $fallbackCodes[] = $code;
        }
    }
    $fallbackCodes = array_values(array_unique($fallbackCodes));
    sort($fallbackCodes);

    $histogram = [];
    spike_count_blocks($result->blocks, $histogram);
    ksort($histogram);

    $areas = array_values(array_filter(array_map(
        static fn (mixed $part): string => is_array($part) && is_string($part['area'] ?? null)
            ? $part['area']
            : '',
        $templateParts,
    )));
    $report = [
        'fallback_count' => count($result->fallbacks),
        'fallback_codes' => $fallbackCodes,
        'block_histogram' => $histogram,
        'carried_css_bytes' => spike_carried_css_bytes($result->assets),
        'fixup_outcomes' => $fixupOutcomes,
        'header_detected' => in_array('header', $areas, true),
        'footer_detected' => in_array('footer', $areas, true),
    ];
    spike_validate_report($report, $schemaPath);

    spike_write_json($repoRoot . '/eval/spike-transform-report.json', $report);
    $markdown = spike_markdown($report, $details, $result->status, $upstreamCommit);
    if (file_put_contents($repoRoot . '/eval/spike-transform-report.md', $markdown) === false) {
        throw new RuntimeException('Could not write Markdown spike report.');
    }
    echo "Wrote eval/spike-transform-report.json and eval/spike-transform-report.md\n";
} catch (Throwable $error) {
    Narrator::write($error->getMessage() . "\n");
    exit(1);
} finally {
    spike_remove_tree($temporaryRoot);
}
