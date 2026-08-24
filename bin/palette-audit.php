<?php
declare(strict_types=1);

use Automattic\SiteBuild\PaletteFloor;
use Automattic\SiteBuild\PaletteReconciliation;
use Automattic\SiteBuild\ProjectStore;

/**
 * Palette-floor audit. No LLM, no network.
 *
 *   php bin/palette-audit.php --fixtures
 *   php bin/palette-audit.php <slug>
 *
 * --fixtures runs check → repair → check on tests/fixtures/palette-floor.
 * <slug> audits the delivered theme.json palette of a built project.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$arg = $argv[1] ?? null;
$extra = $argv[2] ?? null;

if ($arg === '--fixtures' && $extra === null) {
    exit(audit_fixtures());
}

if ($arg === null || $arg === '' || str_starts_with($arg, '-') || $extra !== null) {
    fwrite(STDERR, "Usage: php bin/palette-audit.php --fixtures\n");
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
