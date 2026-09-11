<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\FixtureLoader;
use Automattic\SiteBuild\Project;

/** @return array{fixture: string, project: Project} */
function loader_scratch(): array
{
    $root = sys_get_temp_dir() . '/fixture-loader-' . bin2hex(random_bytes(4));
    mkdir($root . '/fixture/patterns/pages', 0o777, true);
    mkdir($root . '/project', 0o777, true);

    return ['fixture' => $root . '/fixture', 'project' => new Project($root . '/project', 'p')];
}

function loader_page(string $fixture, string $slug): void
{
    file_put_contents($fixture . '/patterns/pages/' . $slug . '.json', '{"slug":"' . $slug . '"}');
}

test('a fixture is copied into the project with its layout intact', function () {
    ['fixture' => $fixture, 'project' => $project] = loader_scratch();
    loader_page($fixture, 'home');

    FixtureLoader::load($fixture, $project);

    assert_eq(true, is_file($project->path('patterns/pages/home.json')));
});

/**
 * The bug this exists for. Loading a smaller fixture over a project that held
 * a bigger one left the extra pages behind, and the build used them: every
 * check passed, because stale pages are themselves valid.
 */
test('a smaller fixture does not inherit pages from the one before it', function () {
    ['fixture' => $fixture, 'project' => $project] = loader_scratch();

    foreach (['home', 'agenda', 'speakers'] as $slug) {
        loader_page($fixture, $slug);
    }
    FixtureLoader::load($fixture, $project);

    unlink($fixture . '/patterns/pages/agenda.json');
    unlink($fixture . '/patterns/pages/speakers.json');
    FixtureLoader::load($fixture, $project);

    $left = glob($project->path('patterns/pages') . '/*.json') ?: [];
    assert_eq(['home.json'], array_map('basename', $left));
});

/**
 * Only what the fixture supplies is replaced. A directory the build wrote and
 * the fixture says nothing about is not the loader's to delete.
 */
test('directories the fixture does not supply are left alone', function () {
    ['fixture' => $fixture, 'project' => $project] = loader_scratch();
    loader_page($fixture, 'home');
    mkdir($project->path('logs'), 0o777, true);
    file_put_contents($project->path('logs/keep.txt'), 'kept');

    FixtureLoader::load($fixture, $project);

    assert_eq('kept', file_get_contents($project->path('logs/keep.txt')));
});
