<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConceptSeeds;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

test('organic style lost by site-spec survives through design selection after recovery', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    try {
        $project->writeJson('meta.json', [
            'original_prompt' => 'A coaching website. I want an organically styled site.',
            'prompt' => 'Create a professional coaching website.',
        ]);
        $llm->queueJson(['name' => 'Super Coaching', 'visual_vibe' => '']);
        $renderer = new PromptRenderer(repo_path('prompts'));
        (new Automattic\SiteBuild\Steps\SiteSpecStep($llm, $renderer))->run($project);
        $llm->queueJson(['seeds' => [
            designdir_seed_obj('Garden Path', 'light', 'organic', 'earth'),
            designdir_seed_obj('Concrete Grid', 'dark', 'brutalist', 'neutral'),
        ]]);
        $llm->queueJson(['direction' => designdir_direction()]);
        (new DesignDirectionStep($llm, $renderer))->run($project);

        $direction = $project->readJson('designDirection.json');
        assert_eq('organically styled', $direction['requested_style']);
        assert_eq('Garden Path', $direction['concept_seed']);
        assert_contains('USER-REQUESTED STYLE: "organically styled"', DesignDirectionStep::readFor($project));
        assert_contains('**Design tradition**: organic', $llm->calls[2]['prompt']);
        assert_eq(3, count($llm->calls), 'normal spec + seeds + expansion calls only');
    } finally {
        remove_tree($tmp);
    }
});

test('explicit style filters conflicting seeds without prescribing fonts or colors', function () {
    $seeds = array_map(static fn (array $raw): array => ConceptSeeds::normalize($raw), [
        ['seed' => 'Concrete Clarity', 'register' => 'brutalist', 'ground' => 'light'],
        ['seed' => 'Stripped Steel', 'register' => 'technical', 'ground' => 'dark'],
        ['seed' => 'Burnt Foundation', 'register' => 'archival', 'ground' => 'dark'],
    ]);
    $warnings = [];
    $kept = ConceptSeeds::respectStyle($seeds, 'Brutalist', $warnings);
    assert_eq([$seeds[0]], $kept);
    assert_eq(2, count($warnings));
    assert_contains('Burnt Foundation', implode("\n", $warnings));
    assert_contains('brutalist', implode("\n", $warnings));
    assert_contains('removed', implode("\n", $warnings));
    $again = [];
    assert_eq($kept, ConceptSeeds::respectStyle($kept, 'Brutalist', $again));
    assert_eq([], $again);
    $open = [];
    assert_eq($seeds, ConceptSeeds::respectStyle($seeds, '', $open));
    assert_eq([], $open);
});

test('style constraint supports freeform styles and does not infer one from the business topic', function () {
    assert_eq('swiss punk collage', ConceptSeeds::requestedStyle('{"visual_vibe":"Swiss punk collage"}'));
    assert_eq('', ConceptSeeds::requestedStyle('{"topic":"organic bakery"}'));
    assert_eq('', ConceptSeeds::requestedStyle('{"visual_vibe":[]}'));
    $vars = ConceptSeeds::seedPromptVars('A Swiss punk collage site', '{"visual_vibe":"Swiss punk collage"}');
    assert_contains('swiss punk collage', $vars['locked_labels']);
    assert_contains('every candidate', $vars['locked_labels']);
    $freeform = ConceptSeeds::normalize(['seed' => 'Punk index', 'register' => 'Swiss punk collage'], [
        'registers' => ['swiss punk collage'],
    ]);
    $warnings = [];
    assert_eq([$freeform], ConceptSeeds::respectStyle([$freeform], 'swiss punk collage', $warnings));
    assert_eq([], $warnings);
});

test('style eligibility treats grammatical variants as the same requested aesthetic', function () {
    foreach (['brutalist styled' => 'brutalist', 'bold brutalist' => 'brutalist', 'brutalist style' => 'brutalist', 'Brutalism' => 'brutalist',
        'organically styled' => 'organic', 'professionally, organically styled' => 'organic',
        'art deco' => 'art-deco', 'Swiss punk collage' => 'swiss punk collage',
        'not brutalist' => 'not brutalist', 'organic and brutalist' => 'organic and brutalist'] as $raw => $canonical) {
        assert_eq($canonical, ConceptSeeds::requestedStyle(json_encode(['visual_vibe' => $raw])));
        $seed = ConceptSeeds::normalize(['seed' => 'One defensible interpretation', 'register' => $canonical], ['registers' => [$canonical]]);
        $warnings = [];
        assert_eq([$seed], ConceptSeeds::respectStyle([$seed], $raw, $warnings));
        assert_eq([], $warnings);
    }
});

test('fleet willow seed regression expands brutalism instead of archival even with a conflicting forced seed', function () {
    [$project, $llm] = make_designdir_fixture();
    $project->writeJson('meta.json', ['prompt' => 'A brutalist Super Coaching site.']);
    $project->writeJson('siteSpec.json', ['name' => 'Super Coaching', 'visual_vibe' => 'brutalist']);
    $llm->queueJson(['seeds' => [
        designdir_seed_obj('Concrete Clarity', 'light', 'brutalist', 'neutral'),
        designdir_seed_obj('Stripped Steel', 'dark', 'technical', 'cool'),
        designdir_seed_obj('Burnt Foundation', 'dark', 'archival', 'earth'),
    ]]);
    $llm->queueJson(['direction' => designdir_direction()]);
    putenv('DESIGN_DIRECTION_CHOICE=3');
    try {
        (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    } finally {
        putenv('DESIGN_DIRECTION_CHOICE');
    }
    assert_eq('Concrete Clarity', $project->readJson('designDirection.json')['concept_seed']);
    assert_contains('USER-REQUESTED STYLE: "brutalist"', DesignDirectionStep::readFor($project));
    assert_eq(3, count($project->readJson('logs/design-direction-seeds.json')['candidates']));
    assert_contains('**Design tradition**: brutalist', $llm->calls[1]['prompt']);
    assert_true(!str_contains($llm->calls[1]['prompt'], 'Burnt Foundation'));
    assert_contains('requested style', implode("\n", $project->readJson('warnings.json')['design-direction']));
});

test('all conflicting seeds fall back to the requested style without another model call', function () {
    [$project, $llm] = make_designdir_fixture();
    $project->writeJson('meta.json', ['prompt' => 'A Swiss punk collage coaching site.']);
    $project->writeJson('siteSpec.json', ['name' => 'Coaching', 'visual_vibe' => 'Swiss punk collage']);
    $llm->queueJson(['seeds' => [designdir_seed_obj('Warm Archive', 'dark', 'archival', 'earth')]]);
    $llm->queueJson(['direction' => designdir_direction()]);
    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    assert_eq(2, count($llm->calls));
    assert_contains('swiss punk collage', $llm->calls[1]['prompt']);
    assert_true(!str_contains($llm->calls[1]['prompt'], 'Warm Archive'));
    assert_contains('requested style', implode("\n", $project->readJson('warnings.json')['design-direction']));
});

test('design model chooses a compatible hero and its media proportions', function () {
    [$project, $llm] = make_designdir_fixture();
    $project->writeJson('siteSpec.json', ['name' => 'Coaching', 'visual_vibe' => '']);
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(designdir_judge());
    $direction = designdir_direction();
    $direction['hero_blueprint'] = array_replace(HeroBlueprint::defaultFor('foreground-split'), [
        'media_aspect' => 'square', 'media_weight' => 'dominant',
    ]);
    $llm->queueJson(['direction' => $direction]);
    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    assert_eq($direction['hero_blueprint'], $project->readJson('designDirection.json')['hero_blueprint']);
    assert_contains('Choose the hero composition', $llm->calls[2]['prompt']);
    assert_contains('layered-poster', $llm->calls[2]['prompt']);
    assert_contains('cinematic-safe-zone', $llm->calls[2]['prompt']);
});

test('seed judge only sees compatible styles and keeps distinct interpretations of one style', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    try {
        $project->writeJson('siteSpec.json', ['name' => 'Coaching', 'visual_vibe' => 'organic']);
        $llm->queueJson(['seeds' => [
            designdir_seed_obj('Concrete Grid', 'dark', 'brutalist', 'neutral'),
            designdir_seed_obj('Garden Path', 'light', 'organic', 'earth'),
            designdir_seed_obj('Leaf Canopy', 'light', 'organic', 'earth'),
        ]]);
        $llm->queueJson(designdir_judge(1, 'The canopy gives this subject a stronger spatial idea.'));
        $llm->queueJson(['direction' => designdir_direction()]);
        (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

        assert_eq(3, count($llm->calls));
        $ballot = $llm->calls[1]['prompt'];
        assert_contains('[0] Garden Path', $ballot);
        assert_contains('[1] Leaf Canopy', $ballot);
        assert_true(!str_contains($ballot, 'Concrete Grid'));
        assert_eq('Leaf Canopy', $project->readJson('designDirection.json')['concept_seed']);
        assert_eq('organic', $project->readJson('designDirection.json')['requested_style']);
        assert_contains('Seed choice: judge picked [1] Leaf Canopy', $project->readText('logs/design-direction.txt'));
        assert_eq(3, count($project->readJson('logs/design-direction-seeds.json')['candidates']));
    } finally {
        remove_tree($tmp);
    }
});

test('homepage creative emphasis has no section or image quota', function () {
    $reflection = new ReflectionClass(Automattic\SiteBuild\Steps\PagePlanStep::class);
    $emphasis = $reflection->getConstant('FRONT_EMPHASIS');
    assert_true(!str_contains($emphasis, 'at least 3'));
    assert_true(!str_contains($emphasis, '5 to 8'));
    assert_true(!str_contains($emphasis, 'image-rich'));
    assert_contains('content', $emphasis);
});
