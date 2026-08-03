<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\RefinePromptStep;
use Automattic\SiteBuild\Tests\FakeLlm;

function seed_refine_meta(Project $project, bool $multiPage = false, string $prompt = 'Lisbon coffee shop with separate Home, Menu, and About pages'): void
{
    $project->writeJson('meta.json', [
        'prompt'     => $prompt,
        'multi_page' => $multiPage,
    ]);
}

test('refine-prompt without multi_page turns named pages into on-page sections', function () {
    with_project('builder_refine_', function (Project $project) {
        seed_refine_meta($project, multiPage: false);
        $llm = new FakeLlm();
        $llm->queueText('A single coffee shop page with Menu and About sections.');
        $renderer = new PromptRenderer(repo_path('prompts'));
        (new RefinePromptStep($llm, $renderer))->run($project);

        assert_eq(1, count($llm->calls));
        assert_contains('single landing page', $llm->calls[0]['prompt']);
        assert_contains('turn the others into clearly named on-page sections', $llm->calls[0]['prompt']);
        assert_contains('while applying the page-scope rule above', $llm->calls[0]['prompt']);
        assert_true(
            !str_contains($llm->calls[0]['prompt'], 'can produce multiple pages'),
            'multi-page scope must not appear when multi_page is off'
        );
        assert_true(
            !str_contains($llm->calls[0]['prompt'], 'keep those as separate destinations'),
            'multi-page destination rule must not appear when multi_page is off'
        );
        assert_eq('A single coffee shop page with Menu and About sections.', $project->readJson('meta.json')['prompt']);
        assert_eq(
            'Lisbon coffee shop with separate Home, Menu, and About pages',
            $project->readJson('meta.json')['original_prompt']
        );
    });
});

test('refine-prompt with multi_page preserves multi-page intent in the brief rule', function () {
    with_project('builder_refine_', function (Project $project) {
        seed_refine_meta($project, multiPage: true);
        $llm = new FakeLlm();
        $llm->queueText('A refined multi-page coffee shop brief with Home, Menu, and About.');
        $renderer = new PromptRenderer(repo_path('prompts'));
        (new RefinePromptStep($llm, $renderer))->run($project);

        assert_eq(1, count($llm->calls));
        assert_contains('can produce multiple pages', $llm->calls[0]['prompt']);
        assert_contains('keep those as separate destinations', $llm->calls[0]['prompt']);
        assert_contains('do not collapse them into one scrollable landing page', $llm->calls[0]['prompt']);
        assert_true(
            !str_contains($llm->calls[0]['prompt'], 'not a multi-page site'),
            'single-page force must not appear when multi_page is on'
        );
        assert_true(
            !str_contains($llm->calls[0]['prompt'], 'turn the others into clearly named on-page sections'),
            'single-page section rule must not appear when multi_page is on'
        );
    });
});

test('refine-prompt keeps the original when the model returns empty', function () {
    with_project('builder_refine_', function (Project $project) {
        seed_refine_meta($project, multiPage: true, prompt: 'A bakery');
        $llm = new FakeLlm();
        $llm->queueText('   ');
        $renderer = new PromptRenderer(repo_path('prompts'));
        (new RefinePromptStep($llm, $renderer))->run($project);

        $meta = $project->readJson('meta.json');
        assert_eq('A bakery', $meta['prompt']);
        assert_true(!isset($meta['original_prompt']), 'empty refinement must not rewrite meta');
    });
});
