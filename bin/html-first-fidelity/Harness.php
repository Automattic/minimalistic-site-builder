<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Verification;

use Automattic\SiteBuild\Narrator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Pure report measurements and rendering for the HTML-first fidelity harness. */
final class HtmlFirstFidelityReport
{
    public const RERUN_COMMAND = 'php bin/html-first-fidelity.php';

    /** @var list<string> */
    public const SLUGS = [
        'silver-summit',
        'swift-grove',
        'sunny-ember',
        'calm-lantern',
        'azure-garden',
        'amber-ember',
    ];

    /** @var list<string> */
    public const ENGINE_MARKERS = [
        'blocks-engine-synthetic-paragraph',
        'blocks-engine-synthetic-anchor-undecorated',
        'blocks-engine-inline-layout-carrier',
        'blocks-engine-css-owned-flow',
        'blocks-engine-css-owned-grid',
        'blocks-engine-css-owned-layout',
        'blocks-engine-css-owned-layout-item',
        'blocks-engine-empty-flex-item',
        'blocks-engine-control',
        'blocks-engine-list-navigation',
        'richtext-marker',
    ];

    /** @return array<string,string> relative design input path => SHA-256 */
    public static function designHashes(string $projectPath): array
    {
        $design = $projectPath . '/design';
        $files = glob($design . '/*.html') ?: [];
        $css = $design . '/site.css';
        if (!is_file($css)) {
            throw new RuntimeException("Required design input missing: {$css}");
        }
        $files[] = $css;
        sort($files, SORT_STRING);

        $hashes = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $relative = 'design/' . basename($file);
            $hash = hash_file('sha256', $file);
            if (!is_string($hash)) {
                throw new RuntimeException("Could not hash design input: {$file}");
            }
            $hashes[$relative] = $hash;
        }
        if (count($hashes) < 2) {
            throw new RuntimeException("Expected design/*.html and design/site.css under {$projectPath}");
        }
        ksort($hashes, SORT_STRING);
        return $hashes;
    }

    /** @return array<string,mixed> */
    public static function measureProject(string $projectPath): array
    {
        $pageFiles = glob($projectPath . '/plugin/pages/*.html') ?: [];
        sort($pageFiles, SORT_STRING);
        if ($pageFiles === []) {
            throw new RuntimeException("No assembled plugin/pages/*.html found under {$projectPath}");
        }

        $markup = '';
        foreach ($pageFiles as $file) {
            $bytes = file_get_contents($file);
            if (!is_string($bytes)) {
                throw new RuntimeException("Could not read assembled markup: {$file}");
            }
            $markup .= $bytes . "\n";
        }

        $cssFiles = self::filesWithExtension($projectPath . '/theme', 'css');
        $css = '';
        foreach ($cssFiles as $file) {
            $bytes = file_get_contents($file);
            if (!is_string($bytes)) {
                throw new RuntimeException("Could not read theme CSS: {$file}");
            }
            $css .= $bytes . "\n";
        }

        $themeJsonPath = $projectPath . '/theme/theme.json';
        $themeJsonBytes = file_get_contents($themeJsonPath);
        if (!is_string($themeJsonBytes)) {
            throw new RuntimeException("Could not read theme.json: {$themeJsonPath}");
        }
        $themeJson = json_decode($themeJsonBytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($themeJson)) {
            throw new RuntimeException("theme.json is not an object: {$themeJsonPath}");
        }

        return self::measureBytes($markup, $css, $themeJson);
    }

    /** @param array<string,mixed> $themeJson @return array<string,mixed> */
    public static function measureBytes(string $markup, string $css, array $themeJson): array
    {
        preg_match_all('~<div class="wp-block-buttons[^"]*"></div>~', $markup, $emptyButtons);
        preg_match_all('~<mark\b[^>]*>~i', $markup, $markTags);
        $marksWithoutBackground = 0;
        foreach ($markTags[0] as $tag) {
            if (preg_match('~\bstyle\s*=\s*(["\'])(.*?)\1~is', $tag, $style) !== 1
                || preg_match('~(?:^|;)\s*background-color\s*:~i', $style[2]) !== 1) {
                $marksWithoutBackground++;
            }
        }

        $layout = $themeJson['settings']['layout'] ?? [];
        if (!is_array($layout)) {
            $layout = [];
        }
        $unmatched = self::unmatchedEngineMarkers($markup, $css);

        return [
            'empty_buttons' => count($emptyButtons[0]),
            'marks_without_background_color' => $marksWithoutBackground,
            'align_wide' => substr_count($markup, '"align":"wide"'),
            'layout' => [
                'content_size' => self::layoutValue($layout['contentSize'] ?? null),
                'wide_size' => self::layoutValue($layout['wideSize'] ?? null),
            ],
            'unmatched_engine_markers' => $unmatched,
            'unmatched_engine_marker_occurrences' => array_sum($unmatched),
        ];
    }

    /** @return array{value:string|int|float|null,unitless:bool} */
    public static function layoutValue(mixed $value): array
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return ['value' => null, 'unitless' => false];
        }
        $unitless = is_int($value) || is_float($value)
            || preg_match('~^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$~', trim($value)) === 1;
        return ['value' => $value, 'unitless' => $unitless];
    }

    /** @return array<string,int> marker => occurrence count, only when selector is absent */
    public static function unmatchedEngineMarkers(string $markup, string $css): array
    {
        $classCounts = [];
        preg_match_all('~\bclass\s*=\s*(["\'])(.*?)\1~is', $markup, $attributes, PREG_SET_ORDER);
        foreach ($attributes as $attribute) {
            $tokens = preg_split('/\s+/', trim(html_entity_decode($attribute[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: [];
            foreach ($tokens as $token) {
                if ($token !== '') {
                    $classCounts[$token] = ($classCounts[$token] ?? 0) + 1;
                }
            }
        }

        /** @var array<string,array<string,int>> $categoryTokens */
        $categoryTokens = [];
        foreach ($classCounts as $token => $count) {
            if ($token === 'blocks-engine-control' || str_starts_with($token, 'blocks-engine-control-')) {
                $categoryTokens['blocks-engine-control'][$token] = $count;
            } elseif (in_array($token, self::ENGINE_MARKERS, true) && $token !== 'richtext-marker') {
                $categoryTokens[$token][$token] = $count;
            }
        }

        foreach (self::richTextMarkerCounts($markup) as $token => $count) {
            $categoryTokens['richtext-marker'][$token] = $count;
        }

        $unmatched = [];
        foreach (self::ENGINE_MARKERS as $category) {
            $count = 0;
            foreach ($categoryTokens[$category] ?? [] as $token => $occurrences) {
                $matched = $category === 'richtext-marker'
                    ? self::cssHasRichTextMarkerSelector($css, $token)
                    : self::cssHasClassSelector($css, $token);
                if (!$matched) {
                    $count += $occurrences;
                }
            }
            if ($count > 0) {
                $unmatched[$category] = $count;
            }
        }
        return $unmatched;
    }

    public static function cssHasClassSelector(string $css, string $class): bool
    {
        $classPattern = '~\.' . preg_quote($class, '~') . '(?![-_a-zA-Z0-9])~';
        foreach (self::cssSelectorPreludes($css) as $prelude) {
            $withoutStrings = preg_replace('~(["\'])(?:\\\\.|(?!\1).)*\1~s', '', $prelude) ?? $prelude;
            if (preg_match($classPattern, $withoutStrings) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function cssHasRichTextMarkerSelector(string $css, string $marker): bool
    {
        $markerPattern = '~(?:--blocks-engine-richtext-marker\s*:\s*|data-blocks-engine-richtext-marker\s*=\s*["\'])'
            . preg_quote($marker, '~') . '(?![-_a-zA-Z0-9])~';
        foreach (self::cssSelectorPreludes($css) as $prelude) {
            if (preg_match($markerPattern, $prelude) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,int> concrete rich-text marker => markup occurrence count */
    private static function richTextMarkerCounts(string $markup): array
    {
        $counts = [];
        preg_match_all('~\bstyle\s*=\s*(["\'])(.*?)\1~is', $markup, $styles, PREG_SET_ORDER);
        foreach ($styles as $style) {
            preg_match_all(
                '~--blocks-engine-richtext-marker\s*:\s*(blocks-engine-richtext-[a-zA-Z0-9_-]+)~',
                $style[2],
                $markers,
            );
            foreach ($markers[1] as $marker) {
                $counts[$marker] = ($counts[$marker] ?? 0) + 1;
            }
        }
        preg_match_all(
            '~\bdata-blocks-engine-richtext-marker\s*=\s*(["\'])(blocks-engine-richtext-[a-zA-Z0-9_-]+)\1~i',
            $markup,
            $attributes,
            PREG_SET_ORDER,
        );
        foreach ($attributes as $attribute) {
            $marker = $attribute[2];
            $counts[$marker] = ($counts[$marker] ?? 0) + 1;
        }
        return $counts;
    }

    /** @return list<string> selector text before each ordinary CSS rule */
    private static function cssSelectorPreludes(string $css): array
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        $preludes = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        $depth = 0;
        $length = strlen($css);

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                $buffer .= $char;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '{') {
                $prelude = trim($buffer);
                if ($prelude !== '' && $prelude[0] !== '@') {
                    $preludes[] = $prelude;
                }
                $depth++;
                $buffer = '';
                continue;
            }
            if ($char === '}') {
                $depth = max(0, $depth - 1);
                $buffer = '';
                continue;
            }
            if ($char === ';' && $depth === 0) {
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        return $preludes;
    }

    /** @param array<string,mixed> $metrics @return array<string,int> */
    public static function countTotals(array $metrics): array
    {
        return [
            'empty_buttons' => (int) ($metrics['empty_buttons'] ?? 0),
            'marks_without_background_color' => (int) ($metrics['marks_without_background_color'] ?? 0),
            'align_wide' => (int) ($metrics['align_wide'] ?? 0),
            'unmatched_engine_marker_occurrences' => (int) ($metrics['unmatched_engine_marker_occurrences'] ?? 0),
        ];
    }

    /** @param array<string,int> $control @param array<string,int> $treatment @return array<string,int> */
    public static function delta(array $control, array $treatment): array
    {
        $delta = [];
        foreach (array_keys(self::countTotals([])) as $key) {
            $delta[$key] = (int) ($treatment[$key] ?? 0) - (int) ($control[$key] ?? 0);
        }
        return $delta;
    }

    /** @param array<string,mixed> $report */
    public static function renderGallery(array $report): string
    {
        $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
        $projects = is_array($report['projects'] ?? null) ? $report['projects'] : [];
        $cards = '';
        foreach ($projects as $project) {
            if (is_array($project)) {
                $cards .= self::renderProjectCard($project);
            }
        }

        return "<!doctype html>\n" . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>HTML-first fidelity: control vs treatment</title><style>'
            . ':root{--bg:#0c0e12;--panel:#14171d;--panel2:#1a1e26;--line:#262c37;--ink:#e7ecf3;--dim:#9aa4b2;--faint:#6b7482;--good:#4bb381;--bad:#c05b6a;--link:#5aa6ff}'
            . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
            . '.wrap{max-width:1720px;margin:auto;padding:32px 24px 96px;min-width:0}h1{font-size:28px;letter-spacing:-.02em;margin:0 0 4px}.sub{color:var(--dim);margin:0 0 24px;max-width:90ch}.path{overflow-wrap:anywhere;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}'
            . '.status{display:flex;gap:9px;flex-wrap:wrap;margin:0 0 18px}.badge{font-size:11px;text-transform:uppercase;letter-spacing:.06em;padding:3px 9px;border-radius:999px;border:1px solid currentColor}.pass{color:var(--good)}.fail{color:var(--bad)}'
            . 'table{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}th,td{padding:8px 10px;text-align:right;border-bottom:1px solid var(--line)}th:first-child,td:first-child{text-align:left}th{background:var(--panel2);color:var(--dim);font-size:11px;text-transform:uppercase;letter-spacing:.04em}.summary{margin-bottom:36px}'
            . '.build{background:var(--panel);border:1px solid var(--line);border-radius:14px;margin:0 0 34px;overflow:hidden;min-width:0}.build-head{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--line);flex-wrap:wrap}.slug{font-size:19px;font-weight:700}.body{padding:18px;min-width:0}'
            . '.shots{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px;min-width:0}.shot{margin:0;min-width:0;background:var(--panel2);border:1px solid var(--line);border-radius:10px;overflow:hidden}.shot figcaption{padding:9px 11px;border-bottom:1px solid var(--line);font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}.frame{max-height:600px;overflow:hidden auto}.shot img{display:block;width:100%;height:auto}.mini{padding:9px 11px;color:var(--dim);font-size:11px;overflow-wrap:anywhere}.metrics{table-layout:fixed}.metrics th,.metrics td{overflow-wrap:anywhere}.unitless{color:#f0b85b;font-weight:700}.delta-neg{color:var(--good)}.delta-pos{color:var(--bad)}'
            . '@media(max-width:1100px){.shots{grid-template-columns:1fr}.frame{max-height:520px}}'
            . '</style></head><body><main class="wrap"><h1>HTML-first fidelity</h1>'
            . '<p class="sub">Reference design, origin/trunk control, current treatment. Same design bytes. Negative defect delta means improvement.</p>'
            . self::renderStatus($projects)
            . self::renderTotals($totals)
            . $cards
            . '<p class="path">Re-run: ' . self::escape(self::RERUN_COMMAND) . '</p>'
            . '</main></body></html>';
    }

    /** @param list<mixed> $projects */
    private static function renderStatus(array $projects): string
    {
        $resumesPass = count($projects) === 6;
        $inputsPass = count($projects) === 6;
        foreach ($projects as $project) {
            if (!is_array($project)) {
                $resumesPass = $inputsPass = false;
                continue;
            }
            foreach (['control', 'treatment'] as $side) {
                $resume = $project[$side]['resume'] ?? [];
                if (!is_array($resume) || ($resume['exit_code'] ?? -1) !== 0 || ($resume['llm_requests'] ?? -1) !== 0) {
                    $resumesPass = false;
                }
            }
            $inputs = $project['design_inputs'] ?? [];
            if (!is_array($inputs) || !($inputs['identical_before'] ?? false)
                || !($inputs['control_unchanged'] ?? false) || !($inputs['treatment_unchanged'] ?? false)) {
                $inputsPass = false;
            }
        }
        return '<div class="status">' . self::badge('12 zero-request resumes', $resumesPass)
            . self::badge('Identical unchanged design inputs', $inputsPass) . '</div>';
    }

    /** @param array<string,mixed> $totals */
    private static function renderTotals(array $totals): string
    {
        $labels = self::metricLabels();
        $rows = '';
        foreach ($labels as $key => $label) {
            if (str_starts_with($key, 'layout.')) {
                continue;
            }
            $rows .= '<tr><td>' . self::escape($label) . '</td>'
                . '<td>' . self::number($totals['control'][$key] ?? null) . '</td>'
                . '<td>' . self::number($totals['treatment'][$key] ?? null) . '</td>'
                . '<td>' . self::deltaNumber($totals['delta'][$key] ?? null) . '</td></tr>';
        }
        return '<table class="summary"><thead><tr><th>Defect total</th><th>Control</th><th>Treatment</th><th>Delta</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
    }

    /** @param array<string,mixed> $project */
    private static function renderProjectCard(array $project): string
    {
        $slug = (string) ($project['slug'] ?? 'unknown');
        $control = is_array($project['control']['metrics'] ?? null) ? $project['control']['metrics'] : [];
        $treatment = is_array($project['treatment']['metrics'] ?? null) ? $project['treatment']['metrics'] : [];
        $inputs = is_array($project['design_inputs'] ?? null) ? $project['design_inputs'] : [];
        $inputPass = ($inputs['identical_before'] ?? false) && ($inputs['control_unchanged'] ?? false)
            && ($inputs['treatment_unchanged'] ?? false);

        return '<section class="build"><header class="build-head"><span class="slug">' . self::escape($slug) . '</span>'
            . self::badge('Design SHA-256 ' . ($inputPass ? 'match' : 'mismatch'), $inputPass) . '</header><div class="body">'
            . '<div class="shots">'
            . self::shot('Design HTML · reference truth', 'shots/' . $slug . '-design.png', 'Authored design render')
            . self::shot('Control · origin/trunk', 'shots/' . $slug . '-control.png', self::miniMetrics($control))
            . self::shot('Treatment · current', 'shots/' . $slug . '-treatment.png', self::miniMetrics($treatment))
            . '</div>' . self::projectMetricTable($control, $treatment, is_array($project['delta'] ?? null) ? $project['delta'] : [])
            . '</div></section>';
    }

    private static function shot(string $caption, string $src, string $detail): string
    {
        return '<figure class="shot"><figcaption>' . self::escape($caption) . '</figcaption><div class="frame"><img loading="lazy" alt="'
            . self::escape($caption) . '" src="' . self::escape($src) . '"></div><div class="mini">' . $detail . '</div></figure>';
    }

    /** @param array<string,mixed> $metrics */
    private static function miniMetrics(array $metrics): string
    {
        return 'Empty buttons ' . self::number($metrics['empty_buttons'] ?? null)
            . ' · mark defects ' . self::number($metrics['marks_without_background_color'] ?? null)
            . ' · align wide ' . self::number($metrics['align_wide'] ?? null)
            . ' · unmatched marker uses ' . self::number($metrics['unmatched_engine_marker_occurrences'] ?? null);
    }

    /** @param array<string,mixed> $control @param array<string,mixed> $treatment @param array<string,mixed> $delta */
    private static function projectMetricTable(array $control, array $treatment, array $delta): string
    {
        $rows = '';
        foreach (self::metricLabels() as $key => $label) {
            if (str_starts_with($key, 'layout.')) {
                $layoutKey = substr($key, strlen('layout.'));
                $controlValue = $control['layout'][$layoutKey] ?? [];
                $treatmentValue = $treatment['layout'][$layoutKey] ?? [];
                $rows .= '<tr><td>' . self::escape($label) . '</td><td>' . self::layoutDisplay($controlValue)
                    . '</td><td>' . self::layoutDisplay($treatmentValue) . '</td><td>&mdash;</td></tr>';
                continue;
            }
            $rows .= '<tr><td>' . self::escape($label) . '</td><td>' . self::number($control[$key] ?? null)
                . '</td><td>' . self::number($treatment[$key] ?? null) . '</td><td>'
                . self::deltaNumber($delta[$key] ?? null) . '</td></tr>';
        }
        return '<table class="metrics"><thead><tr><th>Metric</th><th>Control</th><th>Treatment</th><th>Delta</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
    }

    /** @return array<string,string> */
    private static function metricLabels(): array
    {
        return [
            'empty_buttons' => 'Empty wp:buttons containers',
            'marks_without_background_color' => 'Marks without background-color',
            'align_wide' => 'align=wide occurrences',
            'unmatched_engine_marker_occurrences' => 'Unmatched engine marker occurrences',
            'layout.content_size' => 'theme.json contentSize',
            'layout.wide_size' => 'theme.json wideSize',
        ];
    }

    private static function layoutDisplay(mixed $layout): string
    {
        if (!is_array($layout)) {
            return '&mdash;';
        }
        $value = $layout['value'] ?? null;
        $display = $value === null ? '&mdash;' : self::escape((string) $value);
        return $display . (($layout['unitless'] ?? false) ? ' <span class="unitless">unitless</span>' : '');
    }

    private static function badge(string $label, bool $pass): string
    {
        return '<span class="badge ' . ($pass ? 'pass' : 'fail') . '">' . self::escape(($pass ? 'PASS · ' : 'FAIL · ') . $label) . '</span>';
    }

    private static function number(mixed $value): string
    {
        return is_numeric($value) ? self::escape(number_format((float) $value, 0)) : '&mdash;';
    }

    private static function deltaNumber(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '&mdash;';
        }
        $number = (int) $value;
        $class = $number < 0 ? 'delta-neg' : ($number > 0 ? 'delta-pos' : '');
        return '<span class="' . $class . '">' . ($number > 0 ? '+' : '') . self::escape((string) $number) . '</span>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return list<string> */
    private static function filesWithExtension(string $root, string $extension): array
    {
        if (!is_dir($root)) {
            throw new RuntimeException("Required directory missing: {$root}");
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === strtolower($extension)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }
}

/** Materialize one immutable subdirectory directly from a Git commit. */
final class HtmlFirstFidelityFrozenGitTree
{
    public static function requireCommit(string $repository, string $commit): void
    {
        if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            throw new RuntimeException("Frozen Git commit must be a full SHA-1: {$commit}");
        }
        if (!is_dir($repository)) {
            throw new RuntimeException("Frozen Git repository missing: {$repository}");
        }
        self::mustRun(['git', 'cat-file', '-e', $commit . '^{commit}'], $repository);
    }

    /**
     * @return array{commit:string,tree_sha256:string,reference:string}
     */
    public static function install(
        string $repository,
        string $commit,
        string $subdirectory,
        string $workRoot,
        string $target,
        string $referencePath,
    ): array {
        self::requireCommit($repository, $commit);
        if ($subdirectory === '' || str_contains($subdirectory, '..') || str_starts_with($subdirectory, '/')) {
            throw new RuntimeException("Unsafe frozen Git subdirectory: {$subdirectory}");
        }

        self::makeDirectory($workRoot);
        $archive = $workRoot . '/frozen-tree.tar';
        $extract = $workRoot . '/extract';
        self::makeDirectory($extract);
        self::mustRun([
            'git', 'archive', '--format=tar', '--output=' . $archive, $commit, $subdirectory,
        ], $repository);
        self::mustRun(['tar', '-xf', $archive, '-C', $extract], $repository);
        @unlink($archive);

        $source = $extract . '/' . $subdirectory;
        if (!is_dir($source)) {
            throw new RuntimeException("Frozen Git archive omitted required directory: {$subdirectory}");
        }
        $sourceHashes = self::treeHashes($source);
        self::removeTree($target);
        self::copyTree($source, $target);
        if ($sourceHashes !== self::treeHashes($target)) {
            throw new RuntimeException('Frozen Git archive bytes differ after treatment installation.');
        }
        $treeSha = hash(
            'sha256',
            json_encode($sourceHashes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        return [
            'commit' => $commit,
            'tree_sha256' => $treeSha,
            'reference' => $referencePath . '@' . $commit . '#tree-sha256=' . $treeSha,
        ];
    }

    /** @param list<string> $command */
    private static function mustRun(array $command, string $cwd): void
    {
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd, is_array(getenv()) ? getenv() : null);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start frozen Git command: ' . implode(' ', $command));
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $detail = trim((string) $stderr) ?: trim((string) $stdout);
            throw new RuntimeException(
                'Frozen Git command failed (' . $exit . '): ' . implode(' ', $command)
                . ($detail !== '' ? " — {$detail}" : ''),
            );
        }
    }

    private static function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Could not create frozen Git directory: {$path}");
        }
    }

    private static function copyTree(string $source, string $target): void
    {
        self::makeDirectory($target);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;
            if ($item->isLink()) {
                self::makeDirectory(dirname($destination));
                if (!symlink((string) readlink($item->getPathname()), $destination)) {
                    throw new RuntimeException("Could not copy frozen Git symlink: {$destination}");
                }
            } elseif ($item->isDir()) {
                self::makeDirectory($destination);
            } else {
                self::makeDirectory(dirname($destination));
                if (!copy($item->getPathname(), $destination)) {
                    throw new RuntimeException("Could not copy frozen Git file: {$destination}");
                }
            }
        }
    }

    /** @return array<string,string> */
    private static function treeHashes(string $root): array
    {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($root) + 1);
            if ($item->isFile() && !$item->isLink()) {
                $hashes[$relative] = (string) hash_file('sha256', $item->getPathname());
            } elseif ($item->isLink()) {
                $hashes[$relative] = 'link:' . (string) readlink($item->getPathname());
            }
        }
        ksort($hashes, SORT_STRING);
        return $hashes;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}

/** Atomic publication boundary for complete gallery generations. */
final class HtmlFirstFidelityPublisher
{
    private const RENAME_SWAP = 0x00000002;
    public const ABSENT_LIVE_RENAME = 'absent-live atomic rename';
    public const EXISTING_LIVE_SWAP = 'existing-live renamex_np(staging, live, RENAME_SWAP)';

    /** @var null|\Closure(string,string):bool */
    private ?\Closure $swap;
    /** @var null|\Closure(string,string):bool */
    private ?\Closure $rename;
    /** @var null|\Closure(string):void */
    private ?\Closure $remove;

    public function __construct(?callable $swap = null, ?callable $rename = null, ?callable $remove = null)
    {
        $this->swap = $swap === null ? null : \Closure::fromCallable($swap);
        $this->rename = $rename === null ? null : \Closure::fromCallable($rename);
        $this->remove = $remove === null ? null : \Closure::fromCallable($remove);
    }

    public function createStaging(string $live, ?string $suffix = null): string
    {
        $parent = dirname($live);
        if (!is_dir($parent)) {
            throw new RuntimeException("Gallery parent missing: {$parent}");
        }
        $suffix ??= getmypid() . '-' . bin2hex(random_bytes(6));
        $staging = $parent . '/.' . basename($live) . '.staging-' . $suffix;
        if (file_exists($staging) || is_link($staging)) {
            throw new RuntimeException("Gallery staging path already exists: {$staging}");
        }
        if (!mkdir($staging, 0775) || !mkdir($staging . '/shots', 0775)) {
            $this->discard($staging);
            throw new RuntimeException("Could not create gallery staging tree: {$staging}");
        }
        return $staging;
    }

    /** @return array{report:string,index:string,design:string,control:string,treatment:string} */
    public static function artifactPaths(string $staging, string $slug): array
    {
        return [
            'report' => $staging . '/report.json',
            'index' => $staging . '/index.html',
            'design' => $staging . '/shots/' . $slug . '-design.png',
            'control' => $staging . '/shots/' . $slug . '-control.png',
            'treatment' => $staging . '/shots/' . $slug . '-treatment.png',
        ];
    }

    public static function publicationNarration(string $mechanism): string
    {
        return "Publication: {$mechanism}\n";
    }

    public function publish(string $staging, string $live): string
    {
        if (!is_dir($staging) || is_link($staging)) {
            throw new RuntimeException("Complete gallery staging tree missing: {$staging}");
        }
        if (dirname($staging) !== dirname($live) || $staging === $live) {
            $this->discard($staging);
            throw new RuntimeException('Gallery staging and live paths must be distinct siblings on one filesystem.');
        }

        if (!file_exists($live) && !is_link($live)) {
            $rename = $this->rename ?? static fn (string $from, string $to): bool => @rename($from, $to);
            if (!$rename($staging, $live)) {
                $this->discard($staging);
                throw new RuntimeException("Could not atomically publish gallery into absent destination: {$live}");
            }
            return self::ABSENT_LIVE_RENAME;
        }
        if (!is_dir($live) || is_link($live)) {
            $this->discard($staging);
            throw new RuntimeException("Live gallery destination is not a directory: {$live}");
        }

        $swap = $this->swap ?? self::renameSwap(...);
        try {
            $swapped = $swap($staging, $live);
        } catch (Throwable $error) {
            $this->discard($staging);
            throw new RuntimeException("Atomic gallery exchange failed: {$error->getMessage()}", 0, $error);
        }
        if (!$swapped) {
            $this->discard($staging);
            throw new RuntimeException("Atomic gallery exchange failed; live tree unchanged: {$live}");
        }

        // renamex_np exchanged both directory entries. Staging now names the
        // complete old live generation and can be removed after publication.
        try {
            $this->discard($staging);
        } catch (Throwable $error) {
            Narrator::write(
                'WARNING: Gallery publication committed via ' . self::EXISTING_LIVE_SWAP
                . "; swapped-out old tree could not be deleted. Manual cleanup required: {$staging}"
                . " ({$error->getMessage()})\n",
            );
        }
        return self::EXISTING_LIVE_SWAP;
    }

    public function discard(string $staging): void
    {
        if ($this->remove !== null) {
            ($this->remove)($staging);
        } else {
            self::removeTree($staging);
        }
        if (file_exists($staging) || is_link($staging)) {
            throw new RuntimeException("Could not remove gallery staging tree: {$staging}");
        }
    }

    private static function renameSwap(string $staging, string $live): bool
    {
        if (!class_exists(\FFI::class)) {
            throw new RuntimeException('PHP FFI is unavailable; macOS renamex_np cannot be called.');
        }
        try {
            $ffi = \FFI::cdef(
                'int renamex_np(const char *from, const char *to, unsigned int flags);',
                '/usr/lib/libSystem.B.dylib',
            );
            return $ffi->renamex_np($staging, $live, self::RENAME_SWAP) === 0;
        } catch (Throwable $error) {
            throw new RuntimeException("macOS renamex_np unavailable: {$error->getMessage()}", 0, $error);
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}

/** Expensive control-versus-treatment orchestration. Pure helpers above remain unit-testable. */
final class HtmlFirstFidelityRunner
{
    private const SOURCE_PROJECTS = '/Users/matt/git/minimalistic-site-builder/projects';
    private const OVERLAY = '/Users/matt/projects/a8c/blocks-engine-wt-support-css/php-transformer';
    private const GALLERY = '/Users/matt/git/site-builder-eval/eval/html-first-fidelity';
    private const MUTEX = '/tmp/msb-gate.lock';
    private const CONTROL_TRANSFORMER = '0.4.15';
    public const TREATMENT_TRANSFORMER_COMMIT = '5d1b8bf549000334778648c1dc7ec543d640c963';
    public const TREATMENT_TRANSFORMER_TREE_SHA256 = '9c69c95f77b1edf6ec9b3b68b78d35045f27cb6e2f1f6ac9064e3fbf98313290';
    public const TREATMENT_TRANSFORMER_REFERENCE = '/Users/matt/projects/a8c/blocks-engine-wt-support-css/php-transformer@5d1b8bf549000334778648c1dc7ec543d640c963#tree-sha256=9c69c95f77b1edf6ec9b3b68b78d35045f27cb6e2f1f6ac9064e3fbf98313290';

    private string $repo;
    private string $tempRoot = '';
    private string $controlRoot = '';
    private string $stagingRoot = '';
    private HtmlFirstFidelityPublisher $publisher;
    private bool $lockHeld = false;
    private bool $worktreeAdded = false;
    private bool $composerLockExisted;
    /** @var array<string,string|null> */
    private array $treatmentProjectBackups = [];
    /** @var list<int> */
    private array $usedPorts = [];
    /** @var array{transformer_label:string,transformer_reference:string} */
    private array $overlayProvenance = [];
    private bool $cleaned = false;

    public function __construct(string $repo, ?HtmlFirstFidelityPublisher $publisher = null)
    {
        $real = realpath($repo);
        if (!is_string($real)) {
            throw new RuntimeException("Repository missing: {$repo}");
        }
        $this->repo = $real;
        $this->publisher = $publisher ?? new HtmlFirstFidelityPublisher();
        $this->composerLockExisted = is_file($this->repo . '/composer.lock');
    }

    /**
     * @param array{commit:string,tree_sha256:string,reference:string} $frozen
     * @return array{transformer_label:string,transformer_reference:string}
     */
    public static function treatmentTransformerProvenance(array $frozen): array
    {
        if ($frozen['commit'] !== self::TREATMENT_TRANSFORMER_COMMIT
            || $frozen['tree_sha256'] !== self::TREATMENT_TRANSFORMER_TREE_SHA256
            || $frozen['reference'] !== self::TREATMENT_TRANSFORMER_REFERENCE) {
            throw new RuntimeException('Frozen treatment transformer provenance differs from the required contract.');
        }
        return [
            'transformer_label' => 'frozen source archive',
            'transformer_reference' => self::TREATMENT_TRANSFORMER_REFERENCE,
        ];
    }

    public function run(): int
    {
        register_shutdown_function([$this, 'cleanup']);
        try {
            $this->acquireMutex();
            $this->validateInputs();
            $this->tempRoot = sys_get_temp_dir() . '/msb-html-first-fidelity-' . getmypid() . '-' . bin2hex(random_bytes(3));
            self::makeDirectory($this->tempRoot);
            $this->controlRoot = $this->tempRoot . '/control';
            $this->stagingRoot = $this->publisher->createStaging(self::GALLERY);

            $controlSha = trim($this->capture(['git', 'rev-parse', 'origin/trunk'], $this->repo));
            $treatmentSha = trim($this->capture(['git', 'rev-parse', 'HEAD'], $this->repo));
            $treatmentRef = trim($this->capture(['git', 'branch', '--show-current'], $this->repo));
            if ($controlSha === '' || $treatmentSha === '' || $treatmentRef === '') {
                throw new RuntimeException('Could not resolve control/treatment Git provenance.');
            }

            $this->mustRun(['git', 'worktree', 'add', '--detach', $this->controlRoot, 'origin/trunk'], $this->repo);
            $this->worktreeAdded = true;
            $this->installDependencies($this->controlRoot);
            $this->installDependencies($this->repo);
            $this->overlayTreatmentTransformer();
            $this->installNodeDependencies($this->controlRoot);
            $this->installNodeDependencies($this->repo);
            $this->copyProjects();

            $projects = [];
            foreach (HtmlFirstFidelityReport::SLUGS as $slug) {
                Narrator::write("\n=== {$slug} ===\n");
                $projects[] = $this->runProject($slug);
            }

            $controlTotals = self::sumProjectTotals($projects, 'control');
            $treatmentTotals = self::sumProjectTotals($projects, 'treatment');
            $report = [
                'schema_version' => 1,
                'generated_at' => gmdate('c'),
                'rerun_command' => HtmlFirstFidelityReport::RERUN_COMMAND,
                'provenance' => [
                    'source_projects' => self::SOURCE_PROJECTS,
                    'control' => [
                        'site_builder_ref' => 'origin/trunk',
                        'site_builder_sha' => $controlSha,
                        'transformer_label' => 'v' . self::CONTROL_TRANSFORMER,
                        'transformer_reference' => 'composer:automattic/blocks-engine-php-transformer@' . self::CONTROL_TRANSFORMER,
                    ],
                    'treatment' => [
                        'site_builder_ref' => $treatmentRef,
                        'site_builder_sha' => $treatmentSha,
                        'transformer_label' => $this->overlayProvenance['transformer_label'],
                        'transformer_reference' => $this->overlayProvenance['transformer_reference'],
                    ],
                ],
                'projects' => $projects,
                'totals' => [
                    'control' => $controlTotals,
                    'treatment' => $treatmentTotals,
                    'delta' => HtmlFirstFidelityReport::delta($controlTotals, $treatmentTotals),
                ],
            ];

            $paths = HtmlFirstFidelityPublisher::artifactPaths($this->stagingRoot, HtmlFirstFidelityReport::SLUGS[0]);
            self::writeFileAtomically(
                $paths['report'],
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );
            self::writeFileAtomically($paths['index'], HtmlFirstFidelityReport::renderGallery($report));
            $publication = $this->publisher->publish($this->stagingRoot, self::GALLERY);
            $this->stagingRoot = '';

            Narrator::write("\nReport: " . self::GALLERY . "/report.json\n");
            Narrator::write("Gallery: " . self::GALLERY . "/index.html\n");
            Narrator::write(HtmlFirstFidelityPublisher::publicationNarration($publication));
            Narrator::write('Re-run: ' . HtmlFirstFidelityReport::RERUN_COMMAND . "\n");
            return 0;
        } catch (Throwable $error) {
            Narrator::write("HTML-first fidelity harness failed: {$error->getMessage()}\n");
            return 1;
        } finally {
            $this->cleanup();
        }
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }
        $this->cleaned = true;

        $restoreFailed = false;
        foreach ($this->treatmentProjectBackups as $slug => $backup) {
            $target = $this->repo . '/projects/' . $slug;
            self::removeTree($target);
            if (file_exists($target) || is_link($target)) {
                $restoreFailed = true;
                Narrator::write("Could not remove generated treatment project before restore: {$target}\n");
                continue;
            }
            if (is_string($backup) && file_exists($backup)) {
                if (!@rename($backup, $target)) {
                    $restoreFailed = true;
                    Narrator::write("Could not restore preserved project: {$backup} -> {$target}\n");
                }
            }
        }
        if (!$this->composerLockExisted && is_file($this->repo . '/composer.lock')) {
            @unlink($this->repo . '/composer.lock');
        }
        if ($this->worktreeAdded && $this->controlRoot !== '') {
            $this->runWithoutFailure(['git', 'worktree', 'remove', '--force', $this->controlRoot], $this->repo);
        }
        $stagingFailure = null;
        if ($this->stagingRoot !== '' && (file_exists($this->stagingRoot) || is_link($this->stagingRoot))) {
            try {
                $this->publisher->discard($this->stagingRoot);
            } catch (Throwable $error) {
                $stagingFailure = $error;
            }
        }
        if ($this->tempRoot !== '' && !$restoreFailed) {
            self::removeTree($this->tempRoot);
        } elseif ($restoreFailed) {
            Narrator::write("Preserved project backup retained: {$this->tempRoot}\n");
        }
        if ($this->lockHeld) {
            @rmdir(self::MUTEX);
            $this->lockHeld = false;
        }
        if ($restoreFailed) {
            throw new RuntimeException("Treatment project restoration failed; preserved backup remains under {$this->tempRoot}");
        }
        if ($stagingFailure !== null) {
            throw new RuntimeException("Gallery staging cleanup failed: {$stagingFailure->getMessage()}", 0, $stagingFailure);
        }
    }

    private function acquireMutex(): void
    {
        Narrator::write('Waiting for ' . self::MUTEX . "\n");
        while (!@mkdir(self::MUTEX, 0775)) {
            sleep(5);
        }
        $this->lockHeld = true;
        Narrator::write("Gate mutex acquired.\n");
    }

    private function validateInputs(): void
    {
        foreach ([self::SOURCE_PROJECTS, dirname(self::OVERLAY), dirname(self::GALLERY)] as $dir) {
            if (!is_dir($dir)) {
                throw new RuntimeException("Required directory missing: {$dir}");
            }
        }
        HtmlFirstFidelityFrozenGitTree::requireCommit(
            dirname(self::OVERLAY),
            self::TREATMENT_TRANSFORMER_COMMIT,
        );
        if (!is_file($this->repo . '/bin/build.php') || !is_file($this->repo . '/bin/screenshot.php')) {
            throw new RuntimeException("Run harness from minimalistic-site-builder repository root.");
        }
        foreach (HtmlFirstFidelityReport::SLUGS as $slug) {
            if (!is_dir(self::SOURCE_PROJECTS . '/' . $slug)) {
                throw new RuntimeException("Source project missing: " . self::SOURCE_PROJECTS . "/{$slug}");
            }
        }
    }

    private function installDependencies(string $repo): void
    {
        Narrator::write("Installing dependencies in {$repo}\n");
        $this->mustRun([
            'composer', 'update',
            '--with=automattic/blocks-engine-php-transformer:' . self::CONTROL_TRANSFORMER,
            '--no-interaction', '--no-progress', '--prefer-dist',
        ], $repo);
        $installed = json_decode((string) file_get_contents($repo . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = is_array($installed['packages'] ?? null) ? $installed['packages'] : (is_array($installed) ? $installed : []);
        $version = null;
        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'automattic/blocks-engine-php-transformer') {
                $version = ltrim((string) ($package['pretty_version'] ?? $package['version'] ?? ''), 'v');
                break;
            }
        }
        if ($version !== self::CONTROL_TRANSFORMER) {
            throw new RuntimeException("Expected transformer " . self::CONTROL_TRANSFORMER . ", Composer installed " . ($version ?? 'nothing'));
        }
    }

    private function overlayTreatmentTransformer(): void
    {
        $frozen = HtmlFirstFidelityFrozenGitTree::install(
            repository: dirname(self::OVERLAY),
            commit: self::TREATMENT_TRANSFORMER_COMMIT,
            subdirectory: basename(self::OVERLAY),
            workRoot: $this->tempRoot . '/frozen-treatment-transformer',
            target: $this->repo . '/vendor/automattic/blocks-engine-php-transformer',
            referencePath: self::OVERLAY,
        );
        $this->overlayProvenance = self::treatmentTransformerProvenance($frozen);
    }

    private function installNodeDependencies(string $repo): void
    {
        Narrator::write("Installing Node dependencies in {$repo}\n");
        $this->mustRun(['npm', 'ci', '--no-audit', '--no-fund'], $repo);
    }

    private function copyProjects(): void
    {
        self::makeDirectory($this->controlRoot . '/projects');
        self::makeDirectory($this->repo . '/projects');
        self::makeDirectory($this->tempRoot . '/treatment-project-backups');
        foreach (HtmlFirstFidelityReport::SLUGS as $slug) {
            $source = self::SOURCE_PROJECTS . '/' . $slug;
            self::copyTree($source, $this->controlRoot . '/projects/' . $slug);

            $target = $this->repo . '/projects/' . $slug;
            $backup = null;
            if (file_exists($target) || is_link($target)) {
                $backup = $this->tempRoot . '/treatment-project-backups/' . $slug;
                if (!@rename($target, $backup)) {
                    throw new RuntimeException("Could not preserve existing treatment project: {$target}");
                }
            }
            $this->treatmentProjectBackups[$slug] = $backup;
            self::copyTree($source, $target);
        }
    }

    /** @return array<string,mixed> */
    private function runProject(string $slug): array
    {
        $controlProject = $this->controlRoot . '/projects/' . $slug;
        $treatmentProject = $this->repo . '/projects/' . $slug;
        $controlBefore = HtmlFirstFidelityReport::designHashes($controlProject);
        $treatmentInitial = HtmlFirstFidelityReport::designHashes($treatmentProject);
        if ($controlBefore !== $treatmentInitial) {
            throw new RuntimeException("G2 failed before resume for {$slug}: design hashes differ.");
        }

        $controlExit = $this->runResume($this->controlRoot, $slug);
        $controlRequests = $this->requestsFromStats($controlProject, 'control', $slug);
        $controlAfter = HtmlFirstFidelityReport::designHashes($controlProject);
        $treatmentBefore = HtmlFirstFidelityReport::designHashes($treatmentProject);
        if ($controlAfter !== $controlBefore || $controlAfter !== $treatmentBefore) {
            throw new RuntimeException("G2 failed before treatment resume for {$slug}: control changed or fresh inputs differ.");
        }

        $treatmentExit = $this->runResume($this->repo, $slug);
        $treatmentRequests = $this->requestsFromStats($treatmentProject, 'treatment', $slug);
        $treatmentAfter = HtmlFirstFidelityReport::designHashes($treatmentProject);
        if ($controlExit !== 0 || $treatmentExit !== 0 || $controlRequests !== 0 || $treatmentRequests !== 0) {
            throw new RuntimeException("G1 failed for {$slug}: control exit/requests {$controlExit}/{$controlRequests}; treatment {$treatmentExit}/{$treatmentRequests}");
        }
        if ($controlAfter !== $controlBefore || $treatmentAfter !== $treatmentBefore) {
            throw new RuntimeException("G2 failed after resume for {$slug}: design inputs changed.");
        }

        $controlMetrics = HtmlFirstFidelityReport::measureProject($controlProject);
        $treatmentMetrics = HtmlFirstFidelityReport::measureProject($treatmentProject);
        $controlCounts = HtmlFirstFidelityReport::countTotals($controlMetrics);
        $treatmentCounts = HtmlFirstFidelityReport::countTotals($treatmentMetrics);

        $shots = HtmlFirstFidelityPublisher::artifactPaths($this->stagingRoot, $slug);
        $this->mustRun([
            'node', $this->repo . '/bin/screenshot/screenshot.js',
            'file://' . $treatmentProject . '/design/home.html',
            $shots['design'], '--width=1366',
        ], $this->repo);
        $this->mustRun([
            PHP_BINARY, 'bin/screenshot.php', $slug,
            '--port=' . $this->freePort(), '--out=' . $shots['control'],
        ], $this->controlRoot);
        $this->mustRun([
            PHP_BINARY, 'bin/screenshot.php', $slug,
            '--port=' . $this->freePort(), '--out=' . $shots['treatment'],
        ], $this->repo);

        return [
            'slug' => $slug,
            'design_inputs' => [
                'control_before' => $controlBefore,
                'treatment_before' => $treatmentBefore,
                'control_after' => $controlAfter,
                'treatment_after' => $treatmentAfter,
                'identical_before' => true,
                'control_unchanged' => true,
                'treatment_unchanged' => true,
            ],
            'control' => [
                'resume' => ['exit_code' => $controlExit, 'llm_requests' => $controlRequests],
                'metrics' => $controlMetrics,
            ],
            'treatment' => [
                'resume' => ['exit_code' => $treatmentExit, 'llm_requests' => $treatmentRequests],
                'metrics' => $treatmentMetrics,
            ],
            'delta' => HtmlFirstFidelityReport::delta($controlCounts, $treatmentCounts),
        ];
    }

    private function runResume(string $repo, string $slug): int
    {
        return $this->runCommand([
            PHP_BINARY, 'bin/build.php', '--slug=' . $slug, '--from=transform-site', '--no-serve',
        ], $repo, [
            'ANTHROPIC_API_KEY' => 'html-first-fidelity-no-network',
            'LLM_PROVIDER' => 'anthropic',
            'SITE_BUILD_LEGACY' => '0',
        ]);
    }

    private function requestsFromStats(string $project, string $side, string $slug): int
    {
        $path = $project . '/build-stats.json';
        $stats = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($stats) || !is_int($stats['requests'] ?? null)) {
            throw new RuntimeException("Missing integer requests in {$side} {$slug} build-stats.json");
        }
        return $stats['requests'];
    }

    private function freePort(): int
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if (!is_resource($socket)) {
                continue;
            }
            $name = stream_socket_get_name($socket, false);
            fclose($socket);
            $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
            if ($port > 0 && !in_array($port, $this->usedPorts, true)) {
                $this->usedPorts[] = $port;
                return $port;
            }
        }
        throw new RuntimeException('Could not reserve distinct screenshot port.');
    }

    /** @param list<array<string,mixed>> $projects @return array<string,int> */
    private static function sumProjectTotals(array $projects, string $side): array
    {
        $totals = HtmlFirstFidelityReport::countTotals([]);
        foreach ($projects as $project) {
            $counts = HtmlFirstFidelityReport::countTotals(
                is_array($project[$side]['metrics'] ?? null) ? $project[$side]['metrics'] : [],
            );
            foreach ($totals as $key => $_) {
                $totals[$key] += $counts[$key];
            }
        }
        return $totals;
    }

    /** @param list<string> $command @param array<string,string> $environment */
    private function mustRun(array $command, string $cwd, array $environment = []): void
    {
        $exit = $this->runCommand($command, $cwd, $environment);
        if ($exit !== 0) {
            throw new RuntimeException('Command exited ' . $exit . ': ' . self::formatCommand($command));
        }
    }

    /** @param list<string> $command @param array<string,string> $environment */
    private function runCommand(array $command, string $cwd, array $environment = []): int
    {
        Narrator::write('$ ' . self::formatCommand($command) . "\n");
        $env = array_replace(is_array(getenv()) ? getenv() : [], $environment);
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', 'php://stdout', 'a'],
            2 => ['file', 'php://stderr', 'a'],
        ], $pipes, $cwd, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start command: ' . self::formatCommand($command));
        }
        return proc_close($process);
    }

    /** @param list<string> $command */
    private function capture(array $command, string $cwd): string
    {
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd, is_array(getenv()) ? getenv() : null);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start command: ' . self::formatCommand($command));
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException(trim((string) $stderr) ?: 'Command failed: ' . self::formatCommand($command));
        }
        return (string) $stdout;
    }

    /** @param list<string> $command */
    private function runWithoutFailure(array $command, string $cwd): void
    {
        $process = @proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, $cwd);
        if (is_resource($process)) {
            proc_close($process);
        }
    }

    /** @param list<string> $command */
    private static function formatCommand(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }

    private static function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Could not create directory: {$path}");
        }
    }

    private static function copyTree(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException("Copy source missing: {$source}");
        }
        self::makeDirectory($target);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;
            if ($item->isLink()) {
                self::makeDirectory(dirname($destination));
                if (!symlink((string) readlink($item->getPathname()), $destination)) {
                    throw new RuntimeException("Could not copy symlink: {$destination}");
                }
            } elseif ($item->isDir()) {
                self::makeDirectory($destination);
            } else {
                self::makeDirectory(dirname($destination));
                if (!copy($item->getPathname(), $destination)) {
                    throw new RuntimeException("Could not copy file: {$destination}");
                }
            }
        }
    }

    /** @return array<string,string> */
    private static function treeHashes(string $root): array
    {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $relative = substr($item->getPathname(), strlen($root) + 1);
                $hashes[$relative] = (string) hash_file('sha256', $item->getPathname());
            } elseif ($item->isLink()) {
                $relative = substr($item->getPathname(), strlen($root) + 1);
                $hashes[$relative] = 'link:' . (string) readlink($item->getPathname());
            }
        }
        ksort($hashes, SORT_STRING);
        return $hashes;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    private static function writeFileAtomically(string $path, string $bytes): void
    {
        self::makeDirectory(dirname($path));
        $temporary = $path . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $bytes) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write output: {$path}");
        }
    }
}
