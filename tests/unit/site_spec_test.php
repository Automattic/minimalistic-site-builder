<?php
declare(strict_types=1);

/** @return array{0:Project,1:FakeLlm,2:string} */
function make_sitespec_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_sitespec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    return [$project, new FakeLlm(), $tmp];
}

test('site-spec writes normalized siteSpec.json', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        // slug intentionally omitted -> derived from name
        'description' => 'A neighborhood bakery.',
        'colors' => ['primary' => '#8a5a2b'],
        'tone' => ['warm', 'homey'],
    ]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('Hearth & Crumb', $spec['name']);
    assert_eq('hearth-crumb', $spec['slug']);            // derived + slugified
    assert_eq('#8a5a2b', $spec['colors']['primary']);    // kept
    assert_eq('#ffffff', $spec['colors']['background']); // default filled
    assert_true(is_array($spec['pages']));               // default filled
    assert_eq('warm', $spec['tone'][0]);

    // The rendered prompt must carry the user's words.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec throws when name missing', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['description' => 'no name here']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new SiteSpecStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec throws when meta prompt missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_sitespec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => '']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($renderer, $project) {
        (new SiteSpecStep(new FakeLlm(), $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
