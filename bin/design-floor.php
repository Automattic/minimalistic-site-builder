<?php
declare(strict_types=1);

use Automattic\SiteBuild\DesignFloor;
use Automattic\SiteBuild\ProjectStore;

/**
 * Print DesignFloor findings for a built project.
 *   php bin/design-floor.php <slug>
 *
 * Read-only. No LLM, no network, no rewrites. Scans plugin/pages/*.html
 * and theme/theme.json only — not theme chrome.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$slug = $argv[1] ?? null;
if ($slug === null) {
    fwrite(STDERR, "Usage: php bin/design-floor.php <slug>\n");
    exit(1);
}
$project = (new ProjectStore(repo_path('projects')))->open($slug);

$theme = [];
if ($project->exists('theme/theme.json')) {
    $decoded = json_decode($project->readText('theme/theme.json'), true);
    if (is_array($decoded)) {
        $theme = $decoded;
    }
}

$pageFiles = glob($project->pluginPath('pages') . '/*.html') ?: [];
sort($pageFiles);
foreach ($pageFiles as $abs) {
    $file = 'plugin/pages/' . basename($abs);
    $markup = $project->readText($file);
    foreach (DesignFloor::check($markup, []) as $finding) {
        echo DesignFloor::warningRow($file, $finding) . "\n";
    }
}

foreach (DesignFloor::check('', $theme) as $finding) {
    echo DesignFloor::warningRow('theme/theme.json', $finding) . "\n";
}
