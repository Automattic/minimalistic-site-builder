<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\AssembleLandingPageStep;

/**
 * Unit tests for AssembleLandingPageStep: deterministic composition of the
 * landing page from the generated parts, in plan order, plus templateParts
 * registration in theme.json.
 */

test('frontPage composes header, sections in order, footer', function () {
    $markup = AssembleLandingPageStep::frontPage(['section-hero', 'section-about']);

    assert_contains('"slug":"header","tagName":"header"', $markup);
    assert_contains('"slug":"footer","tagName":"footer"', $markup);
    assert_contains('"slug":"section-hero","tagName":"section"', $markup);
    assert_true(
        strpos($markup, 'section-hero') < strpos($markup, 'section-about'),
        'sections kept in given order'
    );
    assert_true(strpos($markup, 'header') < strpos($markup, 'section-hero'), 'header first');
    assert_true(strpos($markup, 'section-about') < strpos($markup, 'footer'), 'footer last');
});

function assemble_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_asm_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'hero', 'title' => 'Hero'],
        ['slug' => 'about', 'title' => 'About'],
    ]]);
    foreach (['header', 'footer', 'section-hero', 'section-about'] as $name) {
        $project->writeText("theme/parts/{$name}.html", '<!-- wp:group --><!-- /wp:group -->');
    }
    return [$project, $tmp];
}

test('assemble writes front-page + index and registers template parts', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssembleLandingPageStep())->run($project);

    assert_true($project->exists('theme/templates/front-page.html'), 'front-page written');
    assert_true($project->exists('theme/templates/index.html'), 'index written');

    $front = $project->readText('theme/templates/front-page.html');
    assert_contains('section-hero', $front);
    assert_contains('section-about', $front);

    $index = $project->readText('theme/templates/index.html');
    assert_contains('wp:post-content', $index);

    $parts = $project->readJson('theme/theme.json')['templateParts'];
    $names = array_column($parts, 'name');
    assert_eq(['header', 'footer', 'section-hero', 'section-about'], $names);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble throws when a referenced section part is missing', function () {
    [$project, $tmp] = assemble_fixture();
    unlink($project->themePath('parts/section-about.html'));

    assert_throws(function () use ($project) {
        (new AssembleLandingPageStep())->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
