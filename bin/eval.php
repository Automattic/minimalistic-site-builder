<?php
declare(strict_types=1);

/**
 * Phase 2 evaluation: generate the 5 eval sites, record per-step speed and
 * structural quality, and write eval/report.md + eval/results.json.
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
$store = new ProjectStore(repo_path('projects'));

// Report-only mode: rebuild eval/report.md from projects already on disk,
// reusing captured step timings from eval/results.json. Used after a
// deterministic-only pipeline change so we don't re-run the LLM steps.
if ($only === '--report') {
    rebuild_report();
    echo "Report rebuilt from disk into eval/report.md\n";
    exit(0);
}

$llm = make_llm();

$results = [];
foreach (SITES as $slug => $prompt) {
    if ($only !== null && $slug !== $only) {
        continue;
    }
    echo "\n=== {$slug} ===\n";
    $project = $store->create($slug);
    $project->writeJson('meta.json', [
        'prompt' => $prompt, 'provisional_slug' => $slug, 'created_at' => gmdate('c'),
    ]);

    $timings = [];
    $error = null;
    $total = 0.0;
    try {
        build_pipeline($llm)->runThrough($project, null, function (Step $s, float $secs) use (&$timings, &$total) {
            $timings[$s->id()] = round($secs, 1);
            $total += $secs;
            printf("  %-22s %6.1fs\n", $s->id(), $secs);
        });
    } catch (Throwable $e) {
        $error = $e->getMessage();
        echo "  ERROR: {$error}\n";
    }

    $problems = $error === null ? ThemeValidator::validate($project) : ['build failed before validation'];
    $metrics = collect_metrics($project);

    $results[$slug] = [
        'prompt'   => $prompt,
        'timings'  => $timings,
        'total'    => round($total, 1),
        'error'    => $error,
        'problems' => $problems,
        'metrics'  => $metrics,
    ];
    printf("  %-22s %6.1fs   %s\n", 'TOTAL', $total, $problems === [] ? 'VALID' : count($problems) . ' problem(s)');
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
        $timings['finalize-theme'] = $timings['finalize-theme'] ?? 0.0;
        $results[$slug] = [
            'prompt'   => SITES[$slug],
            'timings'  => $timings,
            'total'    => array_sum($timings),
            'error'    => null,
            'problems' => ThemeValidator::validate($project),
            'metrics'  => collect_metrics($project),
        ];
    }
    write_report($results);
}

/** @return array<string,mixed> */
function collect_metrics(Project $project): array
{
    $m = ['name' => null, 'fonts' => null, 'fonts_loaded' => false, 'front_page_blocks' => 0, 'sections' => 0, 'theme_bytes' => 0];
    if ($project->exists('theme/functions.php')) {
        $m['fonts_loaded'] = str_contains($project->readText('theme/functions.php'), 'fonts.googleapis.com');
    }
    if ($project->exists('siteSpec.json')) {
        $spec = $project->readJson('siteSpec.json');
        $m['name'] = $spec['name'] ?? null;
        $m['sections'] = is_array($spec['sections'] ?? null) ? count($spec['sections']) : 0;
    }
    if ($project->exists('theme/theme.json')) {
        $t = json_decode($project->readText('theme/theme.json'), true);
        $fams = $t['settings']['typography']['fontFamilies'] ?? [];
        // Show the primary family from each stack (more accurate than the label).
        $m['fonts'] = implode(' + ', array_map(static function ($f) {
            $primary = trim(explode(',', (string) ($f['fontFamily'] ?? ''))[0], " \"'");
            return $primary !== '' ? $primary : ($f['name'] ?? '?');
        }, $fams));
    }
    if ($project->exists('theme/templates/front-page.html')) {
        $m['front_page_blocks'] = preg_match_all('/<!--\s*wp:/', $project->readText('theme/templates/front-page.html'));
    }
    foreach (glob($project->themePath('') . '/{,*/}*.{html,json,css,txt}', GLOB_BRACE) ?: [] as $f) {
        $m['theme_bytes'] += filesize($f);
    }
    return $m;
}

/** @param array<string,mixed> $results */
function write_report(array $results): void
{
    $stepIds = ['scaffold-theme', 'site-spec', 'apply-identity', 'theme-json+section-plan', 'sections', 'assemble-landing-page', 'collect-images', 'fix-blocks', 'finalize-theme'];

    $md = "# Builder — Phase 2 Evaluation\n\n";
    $md .= 'Generated: ' . gmdate('Y-m-d H:i') . " UTC · model: " . Env::get('LLM_MODEL', 'claude-opus-4-8') . "\n\n";

    // Speed table.
    $md .= "## Speed (seconds per step)\n\n";
    $md .= '| Site | ' . implode(' | ', array_map(fn ($s) => short($s), $stepIds)) . " | **Total** |\n";
    $md .= '|' . str_repeat('---|', count($stepIds) + 2) . "\n";
    foreach ($results as $slug => $r) {
        $row = ["`{$slug}`"];
        foreach ($stepIds as $sid) {
            $row[] = isset($r['timings'][$sid]) ? (string) $r['timings'][$sid] : '–';
        }
        $row[] = '**' . $r['total'] . '**';
        $md .= '| ' . implode(' | ', $row) . " |\n";
    }

    // Quality table.
    $md .= "\n## Quality (structural)\n\n";
    $md .= "| Site | Name | Fonts | Fonts load | Sections | Front-page blocks | Theme KB | Validation |\n";
    $md .= "|---|---|---|---|---|---|---|---|\n";
    foreach ($results as $slug => $r) {
        $m = $r['metrics'];
        $val = $r['problems'] === [] ? '✅ valid' : '⚠️ ' . count($r['problems']);
        $md .= sprintf(
            "| `%s` | %s | %s | %s | %d | %d | %.1f | %s |\n",
            $slug, $m['name'] ?? '–', $m['fonts'] ?? '–',
            ($m['fonts_loaded'] ?? false) ? '✅' : '—', $m['sections'],
            $m['front_page_blocks'], ($m['theme_bytes'] ?? 0) / 1024, $val
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
    }
    if (!$any) {
        $md .= "None — all sites structurally valid.\n";
    }

    @mkdir(repo_path('eval'), 0775, true);
    file_put_contents(repo_path('eval/report.md'), $md);
    file_put_contents(repo_path('eval/results.json'), json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function short(string $stepId): string
{
    return match ($stepId) {
        'scaffold-theme' => 'scaf', 'site-spec' => 'spec', 'apply-identity' => 'ident',
        'theme-json+section-plan' => 'tjson+plan', 'sections' => 'sect', 'assemble-landing-page' => 'asm',
        'collect-images' => 'imgs', 'fix-blocks' => 'fix', 'finalize-theme' => 'fin', default => $stepId,
    };
}
