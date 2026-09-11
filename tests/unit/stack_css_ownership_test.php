<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PageStylesStep;

test('shared design CSS records ownership losses and keeps usable layout on resume', function () {
    [$project, $tmp] = ps_project('stack_css_ownership_');
    try {
        $project->writeText('theme/parts/section-story.html', '<!-- wp:group {"className":"design-story"} --><div class="wp-block-group design-story"><!-- wp:heading --><h2>Read <span class="emph">the story</span></h2><!-- /wp:heading --></div><!-- /wp:group -->');
        $llm = new Automattic\SiteBuild\Tests\FakeLlm();
        $css = '.design-story { --wp--preset--color--base: var(--wp--preset--color--accent); gap: 2rem; } .design-story .emph { white-space: nowrap; }';
        $llm->queueText($css);
        $step = new PageStylesStep($llm, new Automattic\SiteBuild\PromptRenderer(repo_path('prompts')));
        $step->run($project);
        $delivered = $project->readText('theme/style.css');
        assert_contains('gap: 2rem;', $delivered);
        assert_true(!str_contains($delivered, '--wp--preset--color--base: var('));
        assert_true(!str_contains($delivered, 'white-space: nowrap'));
        $warnings = json_encode($project->readJson('warnings.json'), JSON_UNESCAPED_SLASHES);
        foreach (['theme/style.css', 'authored declaration', 'delivered removed', 'heading emphasis', 'WordPress preset'] as $context) {
            assert_contains($context, $warnings);
        }
        $llm->queueText($css);
        $step->run($project);
        assert_eq($delivered, $project->readText('theme/style.css'));
    } finally {
        remove_tree($tmp);
    }
});

test('shared design CSS cannot override presets or the heading emphasis kit', function () {
    $css = '.design-story { --wp--preset--color--base: var(--wp--preset--color--accent); gap: 2rem; }'
        . '@media (min-width: 40rem) { .design-story .emph { white-space: nowrap; } }';
    assert_true(PageStylesStep::validate($css) !== []);
    [$clean, $drops] = PageStylesStep::dropOffendingDeclarations($css);
    assert_true(!str_contains($clean, '--wp--preset--color--base:'));
    assert_true(!str_contains($clean, 'white-space: nowrap'));
    assert_contains('gap: 2rem;', $clean);
    assert_eq(2, count($drops));
    assert_eq([], PageStylesStep::validate($clean));
    assert_eq([$clean, []], PageStylesStep::dropOffendingDeclarations($clean));
});
