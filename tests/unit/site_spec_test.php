<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array{0:Project,1:FakeLlm,2:string} */
function make_sitespec_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_sitespec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    return [$project, new FakeLlm(), $tmp];
}

test('site-spec writes a factual, normalized siteSpec.json', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        // slug intentionally omitted -> derived from name
        'site_type' => 'bakery storefront',
        'topic' => 'artisan sourdough and pastries',
        'area' => 'bakery',
        'audience' => 'neighborhood locals',
        'language' => 'en',
        'persona_name' => '',
        'email_domain' => 'HearthAndCrumb.com',          // must be lowercased
        'invented' => ['name', 'email_domain', 'colors'], // unknown key must be dropped
        'visual_vibe' => 'warm and rustic',
        'sections' => ['Hero', 'Menu', 'About', 'Visit'],
        // An extra factual field the user stated — must pass through.
        'hours' => 'Tue–Sun 7am–3pm',
    ]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('Hearth & Crumb', $spec['name']);
    assert_eq('hearth-crumb', $spec['slug']);            // derived + slugified
    assert_eq('Hearth & Crumb', $spec['title']);         // title falls back to name
    assert_eq('warm and rustic', $spec['visual_vibe']);
    assert_eq('en', $spec['language']);
    assert_eq('hearthandcrumb.com', $spec['email_domain']);       // lowercased
    assert_eq(['name', 'email_domain'], $spec['invented']);       // non-identity key dropped
    assert_true(is_array($spec['sections']));
    assert_eq('Hero', $spec['sections'][0]);
    assert_eq('Tue–Sun 7am–3pm', $spec['hours']);        // arbitrary fact preserved

    // No design fields should be invented/filled.
    assert_true(!isset($spec['colors']), 'no colors in factual spec');
    assert_true(!isset($spec['typography']), 'no typography in factual spec');
    assert_true(!isset($spec['layout']), 'no layout in factual spec');

    // The rendered prompt must carry the user's words.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec fills missing fixed properties with empty strings', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['name' => 'Solo', 'language' => 'en']); // only name + language
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('Solo', $spec['name']);
    foreach (['title', 'site_type', 'topic', 'area', 'audience', 'visual_vibe', 'persona_name'] as $key) {
        assert_true(array_key_exists($key, $spec), "{$key} key present");
    }
    assert_eq([], $spec['sections']);
    // A missing email_domain is derived from the slug and flagged as invented.
    assert_eq('solo.com', $spec['email_domain']);
    assert_eq(['email_domain'], $spec['invented']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec derives email_domain from multi-word slug when implausible', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['name' => 'Hearth & Crumb', 'language' => 'en', 'email_domain' => 'not a domain!']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('hearthcrumb.com', $spec['email_domain']); // slug minus hyphens
    assert_eq(['email_domain'], $spec['invented']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec accepts a language name as well as a BCP-47 code', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['name' => 'Solo', 'language' => 'Spanish (Argentina)']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    // Parenthesised region is not a plausible code or plain name -> reject.
    assert_throws(function () use ($llm, $renderer, $project) {
        (new SiteSpecStep($llm, $renderer))->run($project);
    });

    $llm->queueJson(['name' => 'Solo', 'language' => 'es-AR']);
    (new SiteSpecStep($llm, $renderer))->run($project);
    assert_eq('es-AR', $project->readJson('siteSpec.json')['language']);

    $llm->queueJson(['name' => 'Solo', 'language' => 'Spanish']);
    (new SiteSpecStep($llm, $renderer))->run($project);
    assert_eq('Spanish', $project->readJson('siteSpec.json')['language']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec throws when name missing', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['topic' => 'no name here', 'language' => 'en']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new SiteSpecStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec throws when language missing or implausible', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));

    $llm->queueJson(['name' => 'Solo']); // no language
    assert_throws(function () use ($llm, $renderer, $project) {
        (new SiteSpecStep($llm, $renderer))->run($project);
    });

    $llm->queueJson(['name' => 'Solo', 'language' => '12345']); // not a code or name
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
