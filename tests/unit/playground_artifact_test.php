<?php
declare(strict_types=1);

use Automattic\SiteBuild\PlaygroundArtifact;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;

test('playground artifact bundles runnable blueprint and full project archive once', function () {
    $tmp = sys_get_temp_dir() . '/builder_playground_artifact_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Demo Site');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo Theme\n*/\n");
    $project->writeText('theme/theme.json', '{"version":3}');
    $project->writeText('theme/templates/index.html', '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->');
    $project->writeJson('siteSpec.json', [
        'name' => 'Demo Site Name',
        'tagline' => 'A test tagline',
    ]);
    $project->writeText('logs/project.log', "build log\n");
    $project->writeText('logs/home.png', 'fake png bytes');

    $out = $tmp . '/artifact.zip';
    $bundle = PlaygroundArtifact::build($project, 'demo-site-playground-test.zip', $out);

    assert_eq($out, $bundle);
    $entries = zip_entries($bundle);

    assert_true(in_array('blueprint.json', $entries, true), 'blueprint at root');
    assert_true(in_array('project.zip', $entries, true), 'project archive at root');
    assert_true(!in_array('theme.zip', $entries, true), 'theme is not duplicated as a separate zip');

    $innerProjectZip = $tmp . '/project.zip';
    $rc = 0;
    exec('unzip -p ' . escapeshellarg($bundle) . ' project.zip > ' . escapeshellarg($innerProjectZip) . ' 2>&1', $extractOut, $rc);
    assert_eq(0, $rc, implode("\n", $extractOut ?? []));

    $projectEntries = zip_entries($innerProjectZip);
    assert_true(in_array('project/demo-site/theme/style.css', $projectEntries, true), 'theme copied into archive');
    assert_true(in_array('project/demo-site/logs/project.log', $projectEntries, true), 'logs copied into archive');
    assert_true(in_array('project/demo-site/logs/home.png', $projectEntries, true), 'screenshots copied into archive');
    assert_true(in_array('project/demo-site/siteSpec.json', $projectEntries, true), 'project JSON copied into archive');

    $blueprintJson = zip_file($bundle, 'blueprint.json');
    $blueprint = json_decode($blueprintJson, true);
    assert_true(!str_contains($blueprintJson, '0-preview-offline.php'), 'published browser blueprint keeps networking enabled');
    assert_eq('Demo Site Name', $blueprint['steps'][0]['options']['blogname']);
    assert_eq('A test tagline', $blueprint['steps'][0]['options']['blogdescription']);
    assert_eq('mkdir', $blueprint['steps'][1]['step']);
    assert_eq('unzip', $blueprint['steps'][2]['step']);
    assert_eq('bundled', $blueprint['steps'][2]['zipFile']['resource']);
    assert_eq('/project.zip', $blueprint['steps'][2]['zipFile']['path']);
    assert_eq('mv', $blueprint['steps'][3]['step']);
    assert_eq('/wordpress/wp-content/builder-project-archive/project/demo-site/theme', $blueprint['steps'][3]['fromPath']);
    assert_eq('/wordpress/wp-content/themes/demo-site', $blueprint['steps'][3]['toPath']);
    assert_eq('activateTheme', $blueprint['steps'][4]['step']);
    assert_eq('demo-site', $blueprint['steps'][4]['themeFolderName']);
    // Page paths like /menu/ must resolve on the seeded site.
    assert_eq('/%postname%/', $blueprint['steps'][0]['options']['permalink_structure']);
    // No content plugin in this fixture — the blueprint stays theme-only.
    assert_eq(5, count($blueprint['steps']));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('blueprint installs and activates the content plugin after the theme', function () {
    $tmp = sys_get_temp_dir() . '/builder_playground_plugin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo-site');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo Theme\n*/\n");
    $project->writeText('plugin/site-content.php', "<?php\n// seeder\n");
    $project->writeJson('plugin/pages.json', ['pages' => []]);

    $blueprint = PlaygroundArtifact::blueprint($project);
    $steps = $blueprint['steps'];

    $ids = array_column($steps, 'step');
    assert_eq(['setSiteOptions', 'mkdir', 'unzip', 'mv', 'activateTheme', 'mv', 'activatePlugin'], $ids);

    // The plugin moves out of the archive next to the theme…
    assert_eq('/wordpress/wp-content/builder-project-archive/project/demo-site/plugin', $steps[5]['fromPath']);
    assert_eq('/wordpress/wp-content/plugins/demo-site-content', $steps[5]['toPath']);
    // …and activates AFTER the theme, so the seeder resolves asset URLs
    // against the active stylesheet.
    assert_eq('demo-site-content/site-content.php', $steps[6]['pluginPath']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('offline guard step fails outbound HTTP fast for local CLI previews', function () {
    $step = PlaygroundArtifact::offlineGuardStep();

    assert_eq('writeFile', $step['step']);
    assert_eq('/wordpress/wp-content/mu-plugins/0-preview-offline.php', $step['path']);
    assert_contains('pre_oembed_result', $step['data']);
    assert_contains('pre_http_request', $step['data']);
    assert_contains('local Playground preview', $step['data']);
});

test('playground artifact URLs point a raw branch asset at Playground blueprint-url', function () {
    $artifact = PlaygroundArtifact::artifactUrl('owner/repo', 'playground-artifacts', 'demo-site-playground.zip');
    assert_eq(
        'https://raw.githubusercontent.com/owner/repo/playground-artifacts/demo-site-playground.zip',
        $artifact
    );
    assert_eq(
        'https://playground.wordpress.net/?blueprint-url=' . rawurlencode($artifact),
        PlaygroundArtifact::playgroundUrl($artifact)
    );
});

test('playground artifact README renders one row per uploaded zip', function () {
    $readme = PlaygroundArtifact::renderArtifactReadme([
        [
            'project' => 'demo-site',
            'asset' => 'demo-site-playground.zip',
            'artifact_url' => 'https://raw.githubusercontent.com/owner/repo/playground-artifacts/demo-site-playground.zip',
            'playground_url' => 'https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fexample.test%2Fdemo-site-playground.zip',
            'size_bytes' => 33523924,
            'created_at' => '2026-07-02T19:25:35+00:00',
        ],
        [
            'slug' => 'old-entry',
            'asset' => 'old-entry.zip',
            'artifact_url' => 'https://raw.githubusercontent.com/owner/repo/playground-artifacts/old-entry.zip',
            'playground_url' => 'https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fexample.test%2Fold-entry.zip',
            'size_bytes' => 512,
            'created_at' => '2026-07-02T19:29:53+00:00',
        ],
    ]);

    assert_contains('| Project | Created | ZIP | Playground | Size |', $readme);
    assert_contains('| demo-site | 2026-07-02 19:25:35 UTC | [demo-site-playground.zip](https://raw.githubusercontent.com/owner/repo/playground-artifacts/demo-site-playground.zip) | [Open](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fexample.test%2Fdemo-site-playground.zip) | 32.0 MB |', $readme);
    assert_contains('| old-entry | 2026-07-02 19:29:53 UTC | [old-entry.zip](https://raw.githubusercontent.com/owner/repo/playground-artifacts/old-entry.zip) | [Open](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fexample.test%2Fold-entry.zip) | 512 B |', $readme);
});

test('playground artifact index update replaces same-name assets and prepends the newest entry', function () {
    $index = PlaygroundArtifact::updateIndex(
        [
            ['asset' => 'a.zip', 'size_bytes' => 1],
            ['asset' => 'b.zip', 'size_bytes' => 2],
        ],
        ['asset' => 'a.zip', 'size_bytes' => 3]
    );

    assert_eq([
        ['asset' => 'a.zip', 'size_bytes' => 3],
        ['asset' => 'b.zip', 'size_bytes' => 2],
    ], $index);
});

test('playground artifact index parsing tolerates malformed content', function () {
    assert_eq([], PlaygroundArtifact::parseIndex(''));
    assert_eq([], PlaygroundArtifact::parseIndex('not json'));
    assert_eq([], PlaygroundArtifact::parseIndex('"a string"'));
    assert_eq(
        [['asset' => 'a.zip']],
        PlaygroundArtifact::parseIndex('[{"asset": "a.zip"}, "junk", 3, null]')
    );
});

test('playground artifact build rejects unsafe asset names', function () {
    $tmp = sys_get_temp_dir() . '/builder_playground_names_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Demo Site');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo Theme\n*/\n");

    $bad = [
        '',
        'no-extension',
        'nested/path.zip',
        '../escape.zip',
        '-leading-dash.zip',
        "line\nbreak.zip",
        'spaced name.zip',
    ];
    foreach ($bad as $name) {
        assert_throws(
            fn () => PlaygroundArtifact::build($project, $name),
            'asset name rejected: ' . str_replace("\n", '\n', $name)
        );
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

/** @return string[] */
function zip_entries(string $zip): array
{
    $out = [];
    $rc = 0;
    exec('unzip -Z1 ' . escapeshellarg($zip) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));
    return $out;
}

function zip_file(string $zip, string $file): string
{
    $out = [];
    $rc = 0;
    exec('unzip -p ' . escapeshellarg($zip) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));
    return implode("\n", $out);
}
