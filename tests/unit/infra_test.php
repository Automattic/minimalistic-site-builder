<?php
declare(strict_types=1);

use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;

// Infrastructure tests: ProjectStore, Project.

test('slugify lowercases and hyphenates', function () {
    assert_eq('a-climate-care-blog', ProjectStore::slugify('A Climate Care Blog!'));
});

test('slugify collapses non-alnum and trims hyphens', function () {
    assert_eq('pizza-menu', ProjectStore::slugify('  Pizza   @ Menu  '));
});

test('slugify falls back to "site" for empty input', function () {
    assert_eq('site', ProjectStore::slugify('@@@'));
});

test('slugify caps length', function () {
    $long = str_repeat('word ', 40);
    assert_true(strlen(ProjectStore::slugify($long)) <= 60);
});

test('project writes and reads text and json round-trip', function () {
    $tmp = sys_get_temp_dir() . '/builder_test_' . uniqid();
    $store = new ProjectStore($tmp);
    $p = $store->create('Round Trip Site');

    assert_eq('round-trip-site', $p->slug());
    $p->writeText('theme/style.css', "/* hi */\n");
    assert_eq("/* hi */\n", $p->readText('theme/style.css'));

    $p->writeJson('siteSpec.json', ['name' => 'X', 'slug' => 'x']);
    $data = $p->readJson('siteSpec.json');
    assert_eq('X', $data['name']);
    assert_true($p->exists('siteSpec.json'));
    assert_true(!$p->exists('nope.json'));

    // cleanup
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('project atomically replaces a JSON progress artifact', function () {
    $tmp = sys_get_temp_dir() . '/builder_atomic_json_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', [['status' => 'pending']]);

    $project->writeJsonAtomic('images.json', [['status' => 'completed']]);

    assert_eq([['status' => 'completed']], $project->readJson('images.json'));
    assert_eq([], glob($project->root . '/.block-fixer-*') ?: [], 'no staging file remains');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('project atomic JSON keeps external invalid UTF-8 from corrupting the artifact', function () {
    $tmp = sys_get_temp_dir() . '/builder_atomic_json_utf8_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', [['status' => 'pending']]);

    $project->writeJsonAtomic('images.json', [[
        'status' => 'failed',
        'error' => "malformed API response: \xFF",
    ]]);

    $raw = $project->readText('images.json');
    $decoded = json_decode($raw, true);
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_true(is_array($decoded), 'the progress artifact remains valid JSON');
    assert_eq('malformed API response: �', $decoded[0]['error'], 'invalid bytes are replaced, not silently serialized as blank JSON');
});

test('project atomic JSON replacement preserves the target when replace fails', function () {
    $tmp = sys_get_temp_dir() . '/builder_atomic_json_fail_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $target = $project->path('images.json');
    mkdir($target);

    assert_throws(
        fn () => $project->writeJsonAtomic('images.json', [['status' => 'completed']]),
        'a target that cannot be replaced must remain untouched',
    );
    assert_true(is_dir($target), 'failed replacement preserves the prior target');
    assert_eq([], glob($project->root . '/.block-fixer-*') ?: [], 'failed replacement discards staging');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('project readText throws when an existing file cannot be read', function () {
    $tmp = sys_get_temp_dir() . '/builder_unreadable_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('artifact.json', '{}');
    $path = $project->path('artifact.json');
    chmod($path, 0000);
    if (is_readable($path)) {
        chmod($path, 0600);
        exec('rm -rf ' . escapeshellarg($tmp));
        skip_test('runtime user can read mode-000 files');
    }

    $message = '';
    try {
        $project->readText('artifact.json');
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    } finally {
        chmod($path, 0600);
    }

    assert_contains('Could not read file:', $message);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('themePath builds under theme/', function () {
    $p = new Project('/base/projects/demo');
    assert_eq('/base/projects/demo/theme', $p->themePath());
    assert_eq('/base/projects/demo/theme/theme.json', $p->themePath('theme.json'));
});

test('env get falls back to default', function () {
    assert_eq('fallback', Env::get('DEFINITELY_NOT_SET_VAR_XYZ', 'fallback'));
});
