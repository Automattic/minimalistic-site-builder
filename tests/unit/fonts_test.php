<?php
declare(strict_types=1);

/** Build a project with a theme.json carrying the given fontFamilies. */
function fonts_project(array $fontFamilies): Project
{
    $tmp = sys_get_temp_dir() . '/builder_fonts_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'slug' => 'demo-site']);
    $project->writeJson('theme/theme.json', [
        'version' => 3,
        'settings' => ['typography' => ['fontFamilies' => $fontFamilies]],
    ]);
    return $project;
}

test('fonts enqueues the heading and body Google families from theme.json', function () {
    $project = fonts_project([
        ['slug' => 'heading', 'name' => 'Heading', 'fontFamily' => '"Cormorant Garamond", serif'],
        ['slug' => 'body', 'name' => 'Body', 'fontFamily' => 'Spectral, Georgia, serif'],
    ]);

    (new FontsStep())->run($project);

    assert_true($project->exists('theme/functions.php'), 'functions.php written');
    $php = $project->readText('theme/functions.php');
    assert_contains('add_action( \'enqueue_block_assets\',', $php);
    assert_contains('family=Cormorant+Garamond:wght@400;700', $php);
    assert_contains('family=Spectral:wght@400;700', $php);
    // The web-safe fallbacks in the stacks must NOT be requested from Google.
    assert_true(!str_contains($php, 'family=serif'), 'no bare serif requested');
    assert_true(!str_contains($php, 'family=Georgia'), 'no Georgia requested');
    // Prefix derives from the site slug.
    assert_contains('demo_site_enqueue_fonts', $php);
});

test('fonts de-duplicates when heading and body share a family', function () {
    $project = fonts_project([
        ['slug' => 'heading', 'name' => 'H', 'fontFamily' => 'Oswald, sans-serif'],
        ['slug' => 'body', 'name' => 'B', 'fontFamily' => 'Oswald, sans-serif'],
    ]);

    (new FontsStep())->run($project);

    $php = $project->readText('theme/functions.php');
    assert_eq(1, substr_count($php, 'family=Oswald:wght@400;700'));
});

test('fonts writes nothing when both families are web-safe stacks', function () {
    $project = fonts_project([
        ['slug' => 'heading', 'name' => 'H', 'fontFamily' => 'Georgia, serif'],
        ['slug' => 'body', 'name' => 'B', 'fontFamily' => 'Arial, sans-serif'],
    ]);

    (new FontsStep())->run($project);

    assert_true(!$project->exists('theme/functions.php'), 'no functions.php for web-safe-only fonts');
});

test('fonts is a no-op when theme.json is absent', function () {
    $tmp = sys_get_temp_dir() . '/builder_fonts_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new FontsStep())->run($project);

    assert_true(!$project->exists('theme/functions.php'), 'no functions.php without theme.json');
});
