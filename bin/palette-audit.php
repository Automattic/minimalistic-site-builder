<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\PaletteFloor;
use Automattic\SiteBuild\PaletteReconciliation;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Warnings;

/**
 * Palette-floor audit. No LLM, no network.
 *
 *   php bin/palette-audit.php --fixtures
 *   php bin/palette-audit.php --projects [dir]
 *   php bin/palette-audit.php <slug>
 *
 * --fixtures runs check → repair → check on tests/fixtures/palette-floor.
 * --projects runs repair() then check() on every built project's theme.json.
 * <slug> audits the delivered theme.json palette of a built project.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$arg = $argv[1] ?? null;
$extra = $argv[2] ?? null;

if ($arg === '--fixtures' && $extra === null) {
    exit(audit_fixtures());
}

if ($arg === '--projects' && ($extra === null || !str_starts_with($extra, '-'))) {
    exit(audit_projects($extra ?? repo_path('projects')));
}

if ($arg === '--corpus' && ($extra === null || !str_starts_with($extra, '-'))) {
    exit(audit_corpus($extra ?? repo_path('projects')));
}

if ($arg === null || $arg === '' || str_starts_with($arg, '-') || $extra !== null) {
    fwrite(STDERR, "Usage: php bin/palette-audit.php --fixtures\n");
    fwrite(STDERR, "Usage: php bin/palette-audit.php --projects [dir]\n");
    fwrite(STDERR, "Usage: php bin/palette-audit.php --corpus [dir]\n");
    fwrite(STDERR, "Usage: php bin/palette-audit.php <slug>\n");
    exit(1);
}

exit(audit_slug($arg));

function audit_fixtures(): int
{
    $dir = repo_path('tests/fixtures/palette-floor');
    $files = glob($dir . '/*.json') ?: [];
    sort($files);

    $fixtureCount = 0;
    $violating = 0;
    $preFindings = 0;
    $postRemaining = 0;

    foreach ($files as $path) {
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['palette']) || !is_array($data['palette'])) {
            continue;
        }
        $palette = [];
        foreach ($data['palette'] as $slug => $hex) {
            if (is_string($slug) && is_string($hex)) {
                $palette[$slug] = $hex;
            }
        }
        $id = is_string($data['id'] ?? null) ? $data['id'] : basename($path, '.json');
        $label = is_string($data['label'] ?? null) ? $data['label'] : '';

        $findings = PaletteFloor::check($palette);
        $fixtureCount++;
        $preFindings += count($findings);
        if ($findings !== []) {
            $violating++;
        }

        printf("%s %s findings=%d\n", $id, $label, count($findings));
        foreach ($findings as $finding) {
            echo format_finding($finding);
        }

        $warnings = [];
        $repaired = PaletteFloor::repair($palette, $warnings);
        $post = PaletteFloor::check($repaired);
        $postRemaining += count($post);
        printf("%s repaired findings=%d\n", $id, count($post));
        foreach ($post as $finding) {
            echo format_finding($finding);
        }
    }

    echo "fixtures: {$fixtureCount}\n";
    echo "pre-repair violating palettes: {$violating}\n";
    echo "pre-repair findings: {$preFindings}\n";
    echo "post-repair remaining: {$postRemaining}\n";

    return $postRemaining === 0 ? 0 : 1;
}

function audit_projects(string $root): int
{
    $files = glob(rtrim($root, '/') . '/*/theme/theme.json') ?: [];
    sort($files);

    $palettes = 0;
    $residualPalettes = 0;
    $residualWithoutUnrepaired = 0;
    $repairedBlackWhite = [];

    foreach ($files as $path) {
        $theme = json_decode((string) file_get_contents($path), true);
        if (!is_array($theme)) {
            continue;
        }
        $palette = PaletteReconciliation::themePalette($theme);
        if ($palette === []) {
            continue;
        }
        $palettes++;
        $slug = basename(dirname(dirname($path)));
        $warnings = [];
        $repaired = PaletteFloor::repair($palette, $warnings);
        $findings = PaletteFloor::check($repaired);
        if ($findings !== []) {
            $residualPalettes++;
        }
        foreach ($findings as $finding) {
            $role = $finding['role'];
            $unrepaired = false;
            foreach ($warnings as $row) {
                if (
                    str_contains($row, 'path="palette.' . $role . '"')
                    && str_contains($row, 'disposition=unrepaired')
                ) {
                    $unrepaired = true;
                    break;
                }
            }
            if (!$unrepaired) {
                $residualWithoutUnrepaired++;
            }
            $flag = $unrepaired ? 'unrepaired' : 'MISSING-unrepaired';
            printf(
                "%s residual class=%s role=%s metric=%s warning=%s\n",
                $slug,
                $finding['class'],
                $role,
                format_metric((float) $finding['metric']),
                $flag,
            );
        }
        foreach ($repaired as $role => $hex) {
            $authored = $palette[$role] ?? null;
            if (!is_string($authored)) {
                continue;
            }
            $from = ContrastMath::hexToRgb($authored);
            $to = ContrastMath::hexToRgb($hex);
            $norm = strtoupper($hex);
            if (
                $from !== null && $to !== null && $from !== $to
                && ($norm === '#000000' || $norm === '#FFFFFF')
            ) {
                $repairedBlackWhite[] = "{$slug}.{$role}={$norm}";
            }
        }
    }

    echo "projects palettes: {$palettes}\n";
    echo "residual palettes: {$residualPalettes}\n";
    echo "residuals missing unrepaired warning: {$residualWithoutUnrepaired}\n";
    echo 'repaired #000000/#FFFFFF: ' . count($repairedBlackWhite) . "\n";
    foreach ($repairedBlackWhite as $row) {
        echo "  {$row}\n";
    }

    return $residualWithoutUnrepaired === 0 ? 0 : 1;
}

function audit_corpus(string $projectsRoot): int
{
    $delivered = [];
    $multiWarnRoles = 0;
    $deliveredMismatch = 0;
    $residualPalettes = 0;
    $residualWithoutUnrepaired = 0;
    $repairedBlackWhite = [];

    $fixtureDir = repo_path('tests/fixtures/palette-floor');
    foreach (glob($fixtureDir . '/*.json') ?: [] as $path) {
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['palette']) || !is_array($data['palette'])) {
            continue;
        }
        $id = is_string($data['id'] ?? null) ? $data['id'] : basename($path, '.json');
        score_corpus_palette(
            'fixture:' . $id,
            $data['palette'],
            $delivered,
            $multiWarnRoles,
            $deliveredMismatch,
            $residualPalettes,
            $residualWithoutUnrepaired,
            $repairedBlackWhite,
        );
    }

    foreach (glob(rtrim($projectsRoot, '/') . '/*/theme/theme.json') ?: [] as $path) {
        $theme = json_decode((string) file_get_contents($path), true);
        if (!is_array($theme)) {
            continue;
        }
        $slug = basename(dirname(dirname($path)));
        score_corpus_palette(
            'project:' . $slug,
            PaletteReconciliation::themePalette($theme),
            $delivered,
            $multiWarnRoles,
            $deliveredMismatch,
            $residualPalettes,
            $residualWithoutUnrepaired,
            $repairedBlackWhite,
        );
    }

    ksort($delivered);
    $hash = hash('sha256', json_encode($delivered, JSON_UNESCAPED_SLASHES));

    echo 'corpus palettes: ' . count($delivered) . "\n";
    echo 'delivered hash: ' . $hash . "\n";
    echo 'php: ' . PHP_VERSION . "\n";
    echo "roles with more than one warning: {$multiWarnRoles}\n";
    echo "warnings whose delivered differs from returned hex: {$deliveredMismatch}\n";
    echo "residual palettes: {$residualPalettes}\n";
    echo "residuals missing unrepaired warning: {$residualWithoutUnrepaired}\n";
    echo 'repaired #000000/#FFFFFF: ' . count($repairedBlackWhite) . "\n";
    foreach ($repairedBlackWhite as $row) {
        echo "  {$row}\n";
    }

    return ($multiWarnRoles === 0 && $deliveredMismatch === 0 && $residualWithoutUnrepaired === 0) ? 0 : 1;
}

/**
 * @param array<string,string> $palette
 * @param array<string,array<string,string>> $delivered
 * @param list<string> $repairedBlackWhite
 */
function score_corpus_palette(
    string $name,
    array $palette,
    array &$delivered,
    int &$multiWarnRoles,
    int &$deliveredMismatch,
    int &$residualPalettes,
    int &$residualWithoutUnrepaired,
    array &$repairedBlackWhite,
): void {
    if ($palette === []) {
        return;
    }
    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    $delivered[$name] = $out;
    $byRole = [];
    foreach ($warnings as $row) {
        if (preg_match('/path="palette\\.([^"]+)".*delivered=("[^"]*"|[^;]+)/s', $row, $match) !== 1) {
            continue;
        }
        $role = $match[1];
        $byRole[$role][] = $row;
        $shipped = $out[$role] ?? '';
        $need = 'delivered=' . Warnings::value($shipped);
        if (!str_contains($row, $need)) {
            $deliveredMismatch++;
        }
    }
    foreach ($byRole as $rows) {
        if (count($rows) > 1) {
            $multiWarnRoles++;
        }
    }
    $findings = PaletteFloor::check($out);
    if ($findings !== []) {
        $residualPalettes++;
    }
    foreach ($findings as $finding) {
        $ok = false;
        foreach ($warnings as $row) {
            if (
                str_contains($row, 'path="palette.' . $finding['role'] . '"')
                && str_contains($row, 'disposition=unrepaired')
            ) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $residualWithoutUnrepaired++;
        }
    }
    foreach ($out as $role => $hex) {
        $authored = $palette[$role] ?? null;
        if (!is_string($authored)) {
            continue;
        }
        $from = ContrastMath::hexToRgb($authored);
        $to = ContrastMath::hexToRgb($hex);
        $norm = strtoupper($hex);
        if (
            $from !== null && $to !== null && $from !== $to
            && ($norm === '#000000' || $norm === '#FFFFFF')
        ) {
            $repairedBlackWhite[] = "{$name}.{$role}={$norm}";
        }
    }
}

function audit_slug(string $slug): int
{
    try {
        $project = (new ProjectStore(repo_path('projects')))->open($slug);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    if (!$project->exists('theme/theme.json')) {
        fwrite(STDERR, "Missing theme/theme.json for {$project->slug()}\n");
        return 1;
    }

    $theme = json_decode($project->readText('theme/theme.json'), true);
    if (!is_array($theme)) {
        fwrite(STDERR, "Invalid theme/theme.json for {$project->slug()}\n");
        return 1;
    }

    $palette = PaletteReconciliation::themePalette($theme);
    $findings = PaletteFloor::check($palette);

    echo $project->slug() . "\n";
    echo 'findings: ' . count($findings) . "\n";
    foreach ($findings as $finding) {
        echo format_finding($finding);
    }

    return $findings === [] ? 0 : 1;
}

/**
 * @param array{
 *     class: string,
 *     role: string,
 *     against: string,
 *     authored: string,
 *     metric: float,
 *     floor: float
 * } $finding
 */
function format_finding(array $finding): string
{
    $against = $finding['against'] === '' ? '-' : $finding['against'];
    return sprintf(
        "  %s role=%s against=%s authored=%s metric=%s floor=%s\n",
        $finding['class'],
        $finding['role'],
        $against,
        $finding['authored'],
        format_metric((float) $finding['metric']),
        format_metric((float) $finding['floor']),
    );
}

function format_metric(float $value): string
{
    $text = sprintf('%.4f', $value);
    return rtrim(rtrim($text, '0'), '.') ?: '0';
}
