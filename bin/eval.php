<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\Eval\EvalMetrics;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\ThemeValidator;

/**
 * Phase 2 evaluation: generate the 5 eval sites, record per-step speed,
 * token usage, and structural quality, and write eval/report.md +
 * eval/results.json.
 *
 *   php bin/eval.php            # all 5 sites
 *   php bin/eval.php pizza-menu # a single site by slug
 *
 * Runs sites sequentially so per-step timing is not distorted by contention.
 */

require_once __DIR__ . '/../src/bootstrap.php';

/** @var array<string,string> slug => prompt */
const SITES = [
    'climate-care-blog' => 'A climate care blog about practical, everyday home sustainability',
    'photo-portfolio'   => 'A minimalist photography portfolio for a fine-art landscape photographer',
    'pizza-menu'        => 'A single-page menu site for a wood-fired Neapolitan pizzeria',
    'bakery-catalog'    => 'A product catalog for an artisan sourdough bakery',
    'bicycle-store'     => 'An online store for a premium urban and commuter bicycle brand',
];

$only = $argv[1] ?? null;

// Report-only mode: rebuild eval/report.md from projects already on disk,
// reusing captured step timings from eval/results.json. Used after a
// deterministic-only pipeline change so we don't re-run the LLM steps.
if ($only === '--report') {
    rebuild_report();
    echo "Report rebuilt from disk into eval/report.md\n";
    exit(0);
}

$llm = make_llm();
$builder = make_site_builder($llm);

$results = [];
foreach (SITES as $slug => $prompt) {
    if ($only !== null && $slug !== $only) {
        continue;
    }
    echo "\n=== {$slug} ===\n";
    try {
        $project = $builder->createProject($prompt, $slug);
    } catch (Throwable $e) {
        echo "  ERROR: {$e->getMessage()}\n";
        $results[$slug] = [
            'prompt'   => $prompt,
            'timings'  => [],
            'usage'    => [],
            'total'    => 0.0,
            'error'    => $e->getMessage(),
            'problems' => ['build failed before validation'],
            'warnings' => [],
            'metrics'  => [
                'pages'          => 0,
                'content_blocks' => 0,
            ],
        ];
        continue;
    }

    $timings = [];
    $usage = [];
    $previousUsage = $llm->usageTotals();
    $error = null;
    $total = 0.0;
    $runningStep = null;
    $runningStart = 0.0;
    try {
        $builder->pipeline()->runThrough($project, null, function (Step $s, float $secs) use (
            $llm,
            &$timings,
            &$usage,
            &$previousUsage,
            &$total,
            &$runningStep,
        ) {
            $runningStep = null;
            $timings[$s->id()] = round($secs, 1);
            $total += $secs;
            $currentUsage = $llm->usageTotals();
            $inputTokens = $currentUsage['input_tokens'] - $previousUsage['input_tokens'];
            $outputTokens = $currentUsage['output_tokens'] - $previousUsage['output_tokens'];
            $usage[$s->id()] = [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ];
            $previousUsage = $currentUsage;
            printf("  %-22s %6.1fs %10d out\n", $s->id(), $secs, $outputTokens);
        }, function (Step $s) use (&$runningStep, &$runningStart) {
            $runningStep = $s->id();
            $runningStart = microtime(true);
        });
    } catch (Throwable $e) {
        $error = $e->getMessage();
        echo "  ERROR: {$error}\n";
        // A throwing step never reaches the reporter, but its requests were
        // still billed — attribute the unreported delta to the failed step.
        if ($runningStep !== null) {
            $secs = microtime(true) - $runningStart;
            $timings[$runningStep] = round($secs, 1);
            $total += $secs;
            $currentUsage = $llm->usageTotals();
            $inputTokens = $currentUsage['input_tokens'] - $previousUsage['input_tokens'];
            $outputTokens = $currentUsage['output_tokens'] - $previousUsage['output_tokens'];
            $usage[$runningStep] = [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ];
        }
    }

    $problems = $error === null ? ThemeValidator::validate($project) : ['build failed before validation'];
    $warnings = $error === null
        ? array_merge(ThemeValidator::typographyWarnings($project), ThemeValidator::layoutWarnings($project), ThemeValidator::planWarnings($project))
        : [];
    $metrics = collect_metrics($project);

    $results[$slug] = [
        'prompt'   => $prompt,
        'timings'  => $timings,
        'usage'    => $usage,
        'total'    => round($total, 1),
        'error'    => $error,
        'problems' => $problems,
        'warnings' => $warnings,
        'metrics'  => $metrics,
    ];
    printf(
        "  %-22s %6.1fs   %s%s\n",
        'TOTAL',
        $total,
        $problems === [] ? 'VALID' : count($problems) . ' problem(s)',
        $warnings === [] ? '' : ', ' . count($warnings) . ' warning(s)'
    );
}

write_report($results);
echo "\nReport written to eval/report.md\n";

/**
 * Rebuild the report from on-disk projects + prior results.json (re-validating
 * and re-collecting metrics so deterministic post-changes are reflected).
 */
function rebuild_report(): void
{
    $prior = [];
    if (is_file(repo_path('eval/results.json'))) {
        $prior = json_decode((string) file_get_contents(repo_path('eval/results.json')), true) ?: [];
    }
    $store = new ProjectStore(repo_path('projects'));
    $results = [];
    foreach (array_keys(SITES) as $slug) {
        $dir = repo_path('projects/' . $slug);
        if (!is_dir($dir)) {
            continue;
        }
        $project = $store->open($slug);
        $timings = $prior[$slug]['timings'] ?? [];
        $usage = $prior[$slug]['usage'] ?? [];
        $timings['finalize-theme'] = $timings['finalize-theme'] ?? 0.0;
        $results[$slug] = [
            'prompt'   => SITES[$slug],
            'timings'  => $timings,
            'usage'    => $usage,
            'total'    => array_sum($timings),
            'error'    => null,
            'problems' => ThemeValidator::validate($project),
            'warnings' => array_merge(ThemeValidator::typographyWarnings($project), ThemeValidator::layoutWarnings($project), ThemeValidator::planWarnings($project)),
            'metrics'  => collect_metrics($project),
        ];
    }
    write_report($results);
}

/** @return array<string,mixed> */
function collect_metrics(Project $project): array
{
    return EvalMetrics::collect($project);
}

/** @param array<string,mixed> $results */
function write_report(array $results): void
{
    // Derive the columns from what actually ran (in run order): a hardcoded
    // list silently drops steps — and their real token spend — from the
    // totals the day the pipeline gains one, and a renamed step would render
    // as "–".
    $stepIds = [];
    foreach ($results as $r) {
        foreach ([array_keys($r['timings'] ?? []), array_keys($r['usage'] ?? [])] as $ids) {
            foreach ($ids as $sid) {
                if (!in_array($sid, $stepIds, true)) {
                    $stepIds[] = $sid;
                }
            }
        }
    }

    $md = "# Builder — Phase 2 Evaluation\n\n";
    $md .= 'Generated: ' . gmdate('Y-m-d H:i') . " UTC · model: " . default_llm_model() . "\n\n";

    // Speed table.
    $md .= "## Speed (seconds per step)\n\n";
    $speedHeaders = array_merge(
        ['Site'],
        array_map(fn ($s) => short($s), $stepIds),
        ['**Total**']
    );
    $md .= '| ' . implode(' | ', $speedHeaders) . " |\n";
    $md .= '|' . str_repeat('---|', count($speedHeaders)) . "\n";
    foreach ($results as $slug => $r) {
        $row = ["`{$slug}`"];
        foreach ($stepIds as $sid) {
            $row[] = isset($r['timings'][$sid]) ? (string) $r['timings'][$sid] : '–';
        }
        $row[] = '**' . $r['total'] . '**';
        $md .= '| ' . implode(' | ', $row) . " |\n";
    }

    // Output-token table. This is the direct regression gate for changes that
    // shorten model responses without changing request count or model tier.
    $md .= "\n## Output tokens per step\n\n";
    $md .= '| Site | ' . implode(' | ', array_map(fn ($s) => short($s), $stepIds)) . " | **Total** |\n";
    $md .= '|' . str_repeat('---|', count($stepIds) + 2) . "\n";
    foreach ($results as $slug => $r) {
        $row = ["`{$slug}`"];
        $totalOutputTokens = 0;
        $hasOutputUsage = false;
        foreach ($stepIds as $sid) {
            $outputTokens = $r['usage'][$sid]['output_tokens'] ?? null;
            if (is_int($outputTokens)) {
                $hasOutputUsage = true;
                $totalOutputTokens += $outputTokens;
                $row[] = number_format($outputTokens);
            } else {
                $row[] = '–';
            }
        }
        $row[] = $hasOutputUsage ? '**' . number_format($totalOutputTokens) . '**' : '–';
        $md .= '| ' . implode(' | ', $row) . " |\n";
    }

    // Quality table.
    $md .= "\n## Quality (structural)\n\n";
    $md .= "| Site | Name | Fonts | Fonts load | Pages | Content blocks | Site KB | Validation |\n";
    $md .= "|---|---|---|---|---|---|---|---|\n";
    foreach ($results as $slug => $r) {
        $m = $r['metrics'];
        $val = $r['problems'] === [] ? '✅ valid' : '⚠️ ' . count($r['problems']);
        $md .= sprintf(
            "| `%s` | %s | %s | %s | %d | %d | %.1f | %s |\n",
            $slug, $m['name'] ?? '–', $m['fonts'] ?? '–',
            ($m['fonts_loaded'] ?? false) ? '✅' : '—', $m['pages'],
            $m['content_blocks'], ($m['theme_bytes'] ?? 0) / 1024, $val
        );
    }

    // Problems / errors detail.
    $md .= "\n## Problems\n\n";
    $any = false;
    foreach ($results as $slug => $r) {
        if ($r['error'] !== null) {
            $md .= "- **{$slug}**: build error — {$r['error']}\n";
            $any = true;
        }
        foreach ($r['problems'] as $p) {
            if ($p !== '') {
                $md .= "- **{$slug}**: {$p}\n";
                $any = true;
            }
        }
        foreach ($r['warnings'] ?? [] as $w) {
            $md .= "- **{$slug}** (warning): {$w}\n";
            $any = true;
        }
    }
    if (!$any) {
        $md .= "None — all sites structurally valid.\n";
    }

    $evalDir = repo_path('eval');
    if (!is_dir($evalDir) && !mkdir($evalDir, 0775, true)) {
        fwrite(STDERR, "Failed to create {$evalDir}\n");
        exit(1);
    }
    if (file_put_contents($evalDir . '/report.md', $md) === false) {
        fwrite(STDERR, "Failed to write {$evalDir}/report.md\n");
        exit(1);
    }
    $resultsJson = json_encode(
        $results,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
    if (file_put_contents($evalDir . '/results.json', $resultsJson) === false) {
        fwrite(STDERR, "Failed to write {$evalDir}/results.json\n");
        exit(1);
    }
}

function short(string $stepId): string
{
    return match ($stepId) {
        'scaffold-theme' => 'scaf', 'scaffold-plugin' => 'plug', 'site-spec' => 'spec', 'apply-identity' => 'ident', 'design-direction' => 'dir',
        'theme-json+page-plan' => 'tjson+plan', 'sections' => 'sect', 'assemble-pages' => 'asm',
        'collect-images' => 'imgs', 'fix-blocks' => 'fix', 'finalize-theme' => 'fin', default => $stepId,
    };
}
