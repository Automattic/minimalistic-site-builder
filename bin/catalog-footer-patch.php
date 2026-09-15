<?php
declare(strict_types=1);

use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\ProjectStore;

/**
 * Pin a planned project's footer archetype before the sections step runs.
 *
 *   php bin/catalog-footer-patch.php <slug> <footer-archetype>
 *
 * The header archetype and the hero recipe both have an operator env override.
 * The footer archetype has none: page-plan hashes it from the site spec and
 * the design direction and writes it to pages.json, and every later reader
 * prefers that persisted value (FooterComposition::archetypeForProject). So
 * the only way to steer it is to rewrite the plan between the two steps, which
 * is what bin/build-catalog-cohort.php uses this for.
 *
 * This is a catalog-coverage tool, not a build step. Nothing else may call it:
 * a footer archetype chosen for a screenshot rather than for the site is
 * exactly the drift page-plan exists to prevent.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$slug = $argv[1] ?? null;
$archetype = $argv[2] ?? null;
if ($slug === null || $archetype === null) {
    fwrite(STDERR, "Usage: php bin/catalog-footer-patch.php <slug> <footer-archetype>\n");
    fwrite(STDERR, '  archetypes: ' . implode(', ', FooterComposition::ARCHETYPES) . "\n");
    exit(1);
}
FooterComposition::assertKnown($archetype);

$store = new ProjectStore(repo_path('projects'));
$project = $store->open(ProjectStore::slugify($slug));
if (!$project->exists('pages.json')) {
    fwrite(STDERR, "No pages.json in projects/{$slug} — run the plan phase first.\n");
    exit(1);
}

$pages = $project->readJson('pages.json');
$was = (string) ($pages['footer_archetype'] ?? '—');
$pages['footer_archetype'] = $archetype;
$project->writeJson('pages.json', $pages);

echo "pages.json: footer_archetype {$was} → {$archetype}\n";
