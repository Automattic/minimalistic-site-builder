<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PageStylesStep;

test('generated CSS drops size containment without changing adjacent layout', function () {
    $css = file_get_contents(__DIR__ . '/../fixtures/page-styles-containment.css');
    assert_true(PageStylesStep::validate($css) !== [], 'size containment must trigger salvage');
    [$clean, $drops] = PageStylesStep::dropOffendingDeclarations($css);
    assert_eq(str_replace('container-type: inline-size;', '', $css), $clean);
    assert_eq(1, count($drops));
    assert_contains('.design-hero-headline', $drops[0], 'warning locates the affected selector');
    assert_contains('size containment', $drops[0]);
    assert_eq([], PageStylesStep::validate($clean));
    assert_eq([$clean, []], PageStylesStep::dropOffendingDeclarations($clean));
});

test('generated CSS catches size containment longhands and shorthand at every breakpoint', function () {
    foreach (['container-type: size', 'container: headline / inline-size', 'contain: size layout paint',
        'contain: inline-size', 'contain: strict', 'CONTAINER-TYPE: /* note */ INLINE-SIZE !important',
        'container-type: var(--containment)', 'container: var(--container)', 'contain: var(--containment)',
        'container-type: inherit'] as $declaration) {
        $css = '@media (min-width: 60rem) { .design-copy { ' . $declaration . '; gap: 2rem; } }';
        assert_true(PageStylesStep::validate($css) !== [], $declaration);
        [$clean, $drops] = PageStylesStep::dropOffendingDeclarations($css);
        assert_eq('@media (min-width: 60rem) { .design-copy {  gap: 2rem; } }', $clean, $declaration);
        assert_eq(1, count($drops));
        assert_eq([], PageStylesStep::validate($clean));
    }
});

test('generated CSS retains containment that does not remove intrinsic size', function () {
    foreach (['container-type: normal', 'container-type: scroll-state', 'container: headline',
        'container: size', 'container: headline / normal', 'contain: layout paint style',
        'contain: content', 'contain: none', 'container-type: initial',
        '--example: "container-type: inline-size"'] as $declaration) {
        $css = '.design-copy { ' . $declaration . '; gap: 2rem; }';
        assert_eq([], PageStylesStep::validate($css), $declaration);
        assert_eq([$css, []], PageStylesStep::dropOffendingDeclarations($css), $declaration);
    }
});

test('containment salvage warns and continues without touching scaffold CSS or page content', function () {
    [$project, $tmp] = ps_project('ps_containment_');
    try {
        $scaffold = '.section-composition--stat-ledger .wp-block-column { container-type: inline-size; min-width:0; }';
        $project->writeText('theme/style.css', $scaffold);
        $markup = '<!-- wp:group {"className":"design-hero-copy"} --><div class="wp-block-group design-hero-copy"><!-- wp:group {"className":"design-hero-headline"} --><div class="wp-block-group design-hero-headline"><!-- wp:heading --><h2>When your best just is not good enough</h2><!-- /wp:heading --></div><!-- /wp:group --></div><!-- /wp:group -->';
        $project->writeText('theme/parts/section-hero.html', $markup);
        $css = file_get_contents(__DIR__ . '/../fixtures/page-styles-containment.css');
        $llm = new Automattic\SiteBuild\Tests\FakeLlm();
        $llm->queueText($css);
        $step = new PageStylesStep($llm, new Automattic\SiteBuild\PromptRenderer(repo_path('prompts')));
        $step->run($project);
        $delivered = $project->readText('theme/style.css');
        assert_contains($scaffold, $delivered);
        assert_eq(1, substr_count($delivered, 'container-type: inline-size;'), 'only scaffold containment remains');
        assert_contains('align-items: flex-start;', $delivered);
        assert_eq($markup, $project->readText('theme/parts/section-hero.html'));
        $warnings = json_encode($project->readJson('warnings.json'), JSON_UNESCAPED_SLASHES);
        foreach (['theme/style.css', '.design-hero-headline', 'container-type: inline-size', 'delivered removed', 'disposition', 'size containment'] as $context) {
            assert_contains($context, $warnings);
        }
        assert_eq(1, count($llm->calls), 'repair adds no AI round-trip');
        $llm->queueText($css);
        $step->run($project);
        assert_eq($delivered, $project->readText('theme/style.css'));
    } finally {
        remove_tree($tmp);
    }
});
