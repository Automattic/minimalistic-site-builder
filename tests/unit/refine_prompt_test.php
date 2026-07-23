<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\RefinePromptStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array{0:Project,1:FakeLlm,2:string} */
function make_refine_fixture(bool $multiPage = false, string $prompt = 'Lisbon coffee shop with separate Home, Menu, and About pages'): array
{
    $tmp = sys_get_temp_dir() . '/builder_refine_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', [
        'prompt'     => $prompt,
        'multi_page' => $multiPage,
    ]);
    return [$project, new FakeLlm(), $tmp];
}

test('refine-prompt without multi_page asks for a single landing page', function () {
    [$project, $llm, $tmp] = make_refine_fixture(multiPage: false);
    $llm->queueText('A refined single-page coffee shop brief.');
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new RefinePromptStep($llm, $renderer))->run($project);

    assert_eq(1, count($llm->calls));
    assert_contains('single landing page', $llm->calls[0]['prompt']);
    assert_true(
        !str_contains($llm->calls[0]['prompt'], 'can produce multiple pages'),
        'multi-page scope must not appear when multi_page is off'
    );
    assert_eq('A refined single-page coffee shop brief.', $project->readJson('meta.json')['prompt']);
    assert_eq(
        'Lisbon coffee shop with separate Home, Menu, and About pages',
        $project->readJson('meta.json')['original_prompt']
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('refine-prompt with multi_page preserves multi-page intent in the brief rule', function () {
    [$project, $llm, $tmp] = make_refine_fixture(multiPage: true);
    $llm->queueText('A refined multi-page coffee shop brief with Home, Menu, and About.');
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new RefinePromptStep($llm, $renderer))->run($project);

    assert_eq(1, count($llm->calls));
    assert_contains('can produce multiple pages', $llm->calls[0]['prompt']);
    assert_contains('do not collapse them into one scrollable landing page', $llm->calls[0]['prompt']);
    assert_true(
        !str_contains($llm->calls[0]['prompt'], 'not a multi-page site'),
        'single-page force must not appear when multi_page is on'
    );
    // Named pages must still be listed as facts to preserve.
    assert_contains('named pages', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('refine-prompt keeps the original when the model returns empty', function () {
    [$project, $llm, $tmp] = make_refine_fixture(multiPage: true, prompt: 'A bakery');
    $llm->queueText('   ');
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new RefinePromptStep($llm, $renderer))->run($project);

    $meta = $project->readJson('meta.json');
    assert_eq('A bakery', $meta['prompt']);
    assert_true(!isset($meta['original_prompt']), 'empty refinement must not rewrite meta');

    exec('rm -rf ' . escapeshellarg($tmp));
});
