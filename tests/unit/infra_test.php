<?php
declare(strict_types=1);

use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;

// Infrastructure tests: ProjectStore, Project, PromptRenderer.

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

test('prompt renderer fills placeholders', function () {
    $out = PromptRenderer::fill('Hello {{ name }}, slug={{slug}}', ['name' => 'Bob', 'slug' => 'bob']);
    assert_eq('Hello Bob, slug=bob', $out);
});

test('prompt renderer throws on unresolved placeholder', function () {
    assert_throws(function () {
        PromptRenderer::fill('Hi {{missing}}', ['name' => 'Bob']);
    });
});

test('env get falls back to default', function () {
    assert_eq('fallback', Env::get('DEFINITELY_NOT_SET_VAR_XYZ', 'fallback'));
});
