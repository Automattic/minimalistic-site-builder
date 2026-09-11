<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\ExportBundleStep;
use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Project;

/**
 * A project with the artifacts export-bundle consumes already in place. The
 * stages that produce them are still being extracted, so seeding them directly
 * is how this stage gets exercised ahead of its neighbours.
 *
 * @param array<string, mixed> $inputs
 * @param list<array<string, mixed>> $pages
 * @param array<string, mixed> $provenance
 */
function export_project(array $inputs, array $pages, array $provenance, array $parts = []): Project
{
    $dir = sys_get_temp_dir() . '/export-bundle-' . bin2hex(random_bytes(4));
    mkdir($dir . '/patterns/pages', 0o777, true);
    mkdir($dir . '/patterns/parts', 0o777, true);

    $project = new Project($dir, basename($dir));
    $project->writeJson(PatternArtifacts::NORMALIZED, $inputs);
    $project->writeJson(PatternArtifacts::PROVENANCE, $provenance);
    $project->writeJson(PatternArtifacts::MEDIA, ['images' => []]);

    foreach ($pages as $page) {
        file_put_contents(
            $dir . '/patterns/pages/' . $page['slug'] . '.json',
            (string) json_encode($page),
        );
    }
    foreach ($parts as $part) {
        file_put_contents(
            $dir . '/patterns/parts/' . $part['slug'] . '.json',
            (string) json_encode($part),
        );
    }

    return $project;
}

function export_clean_inputs(): array
{
    return [
        'theme' => 'twentytwentyfive',
        'brand' => ['settings' => ['color' => ['palette' => [['slug' => 'base', 'color' => '#FFF']]]]],
        'inventory_ids' => ['twentytwentyfive/banner-cover-big-heading'],
        'capabilities' => ['classes' => []],
    ];
}

function export_clean_pages(): array
{
    return [
        ['slug' => 'home', 'title' => 'Home', 'menu_order' => 0, 'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->'],
    ];
}

function export_clean_provenance(): array
{
    return ['sections' => [['page' => 'home', 'pattern' => 'twentytwentyfive/banner-cover-big-heading']]];
}

test('a clean build writes the bundle and a passing report', function () {
    $project = export_project(export_clean_inputs(), export_clean_pages(), export_clean_provenance());

    (new ExportBundleStep())->run($project);

    $bundle = $project->readJson(PatternArtifacts::BUNDLE);
    $report = $project->readJson(PatternArtifacts::REPORT);

    assert_eq(ExportBundleStep::BUNDLE_VERSION, $bundle['version']);
    assert_eq('twentytwentyfive', $bundle['theme']);
    assert_eq(1, count($bundle['pages']));
    assert_eq(true, $report['passed']);
    assert_eq([], $report['violations']);
});

/**
 * The Brand reaches the host through the bundle. If it were dropped here, the
 * site would provision on the theme's own tokens and nobody would be told.
 */
test('the supplied Brand travels into the bundle unchanged', function () {
    $inputs = export_clean_inputs();
    $project = export_project($inputs, export_clean_pages(), export_clean_provenance());

    (new ExportBundleStep())->run($project);

    assert_eq($inputs['brand'], $project->readJson(PatternArtifacts::BUNDLE)['brand']);
});

/**
 * Failing closed is the whole point: a bundle that would render without its
 * layout must not be written, or a host will apply it.
 */
test('a bundle that fails its checks is not written', function () {
    $pages = [[
        'slug' => 'home',
        'title' => 'Home',
        'menu_order' => 0,
        'content' => '<div class="wp-block-group card-style--framed"></div>',
    ]];
    $project = export_project(export_clean_inputs(), $pages, export_clean_provenance());

    $thrown = assert_throws(
        static fn () => (new ExportBundleStep())->run($project),
        'an unportable bundle throws',
    );

    assert_contains('card-style--framed', $thrown->getMessage());
    assert_eq(false, $project->exists(PatternArtifacts::BUNDLE));
});

/**
 * A failed build is the one you most want to inspect, so the report has to
 * survive the failure that produced it.
 */
test('the report is written even when the build fails', function () {
    $pages = [[
        'slug' => 'home',
        'title' => 'Home',
        'menu_order' => 0,
        'content' => '<div class="wp-block-group bespoke-thing"></div>',
    ]];
    $project = export_project(export_clean_inputs(), $pages, export_clean_provenance());

    assert_throws(static fn () => (new ExportBundleStep())->run($project));

    $report = $project->readJson(PatternArtifacts::REPORT);
    assert_eq(false, $report['passed']);
    assert_eq(1, count($report['violations']));
});

test('pages come out in the order the plan put them', function () {
    $pages = [
        ['slug' => 'contact', 'title' => 'Contact', 'menu_order' => 20, 'content' => '<!-- wp:group --><div></div><!-- /wp:group -->'],
        ['slug' => 'home', 'title' => 'Home', 'menu_order' => 0, 'content' => '<!-- wp:group --><div></div><!-- /wp:group -->'],
        ['slug' => 'about', 'title' => 'About', 'menu_order' => 10, 'content' => '<!-- wp:group --><div></div><!-- /wp:group -->'],
    ];
    $provenance = ['sections' => array_map(
        static fn (array $p) => ['page' => $p['slug'], 'pattern' => 'twentytwentyfive/banner-cover-big-heading'],
        $pages,
    )];

    $project = export_project(export_clean_inputs(), $pages, $provenance);
    (new ExportBundleStep())->run($project);

    $slugs = array_column($project->readJson(PatternArtifacts::BUNDLE)['pages'], 'slug');
    assert_eq(['home', 'about', 'contact'], $slugs);
});

/**
 * Provenance is what lets the checks trace a section back to the inventory. A
 * page that arrived without it must not quietly pass.
 */
test('each page carries the patterns its sections came from', function () {
    $project = export_project(export_clean_inputs(), export_clean_pages(), export_clean_provenance());

    (new ExportBundleStep())->run($project);

    $page = $project->readJson(PatternArtifacts::BUNDLE)['pages'][0];
    assert_eq('twentytwentyfive/banner-cover-big-heading', $page['sections'][0]['pattern']);
});

test('shared parts are exported and checked with the pages', function () {
    $parts = [['slug' => 'header', 'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->']];
    $project = export_project(export_clean_inputs(), export_clean_pages(), export_clean_provenance(), $parts);

    (new ExportBundleStep())->run($project);

    assert_eq(1, count($project->readJson(PatternArtifacts::BUNDLE)['parts']));
});
