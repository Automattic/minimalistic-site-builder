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
            'prompt' => 'A coaching website. I want an organically styled site.',
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

test('requested art directions survive a mood-free spec without an extra AI call', function () {
    foreach (['Art Deco', 'Bauhaus', 'Organic', 'Retro-Futurist', 'Synthwave', 'Swiss punk collage'] as $style) {
        [$project, $llm, $tmp] = make_designdir_fixture();
        try {
            $brief = "Create a professional business coaching website for inspiring confidence. "
                . "The site is called 'Super Coaching' and the tagline is 'When your best just isn't good enough'. "
                . "The business is located in Plymouth, NH. "
                . ($style === 'Bauhaus' ? 'I want the design to be Bauhaus.' : 'Art direction: ' . $style . '.');
            $project->writeJson('meta.json', ['prompt' => $brief, 'multi_page' => true]);
            // A stale host mood cannot compete with the actual user request.
            $project->writeJson('siteSpec.json', ['name' => 'Super Coaching', 'visual_vibe' => 'rustic']);
            $key = ConceptSeeds::styleKey($style);
            $llm->queueJson(['seeds' => [
                designdir_seed_obj('Requested interpretation', 'light', $key, 'neutral'),
                designdir_seed_obj('Conflicting interpretation', 'dark', 'archival', 'earth'),
            ]]);
            $llm->queueJson(['direction' => designdir_direction()]);
            (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
            assert_eq($style, $project->readJson('designDirection.json')['requested_style']);
            assert_eq('Requested interpretation', $project->readJson('designDirection.json')['concept_seed']);
            assert_eq(2, count($llm->calls), 'seed selection plus expansion, no style-extraction call');
            assert_true(!str_contains($llm->calls[0]['prompt'], '"visual_vibe"'));
        } finally {
            remove_tree($tmp);
        }
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
    assert_eq('swiss punk collage', ConceptSeeds::requestedStyle('Art direction: Swiss punk collage.'));
    assert_eq('', ConceptSeeds::requestedStyle('An organic bakery.'));
    assert_eq('', ConceptSeeds::requestedStyle('A portfolio of brutalist architecture.'));
    $vars = ConceptSeeds::seedPromptVars('Art direction: Swiss punk collage.', '{"name":"Coaching"}');
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
        assert_eq($canonical, ConceptSeeds::requestedStyle('Style: ' . $raw . '.'));
        $seed = ConceptSeeds::normalize(['seed' => 'One defensible interpretation', 'register' => $canonical], ['registers' => [$canonical]]);
        $warnings = [];
        assert_eq([$seed], ConceptSeeds::respectStyle([$seed], $raw, $warnings));
        assert_eq([], $warnings);
    }
});

test('fleet willow seed regression expands brutalism instead of archival even with a conflicting forced seed', function () {
    [$project, $llm] = make_designdir_fixture();
    $project->writeJson('meta.json', ['prompt' => 'A Super Coaching site. I want the design to be brutalist.']);
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
    $project->writeJson('meta.json', ['prompt' => 'A coaching site. Art direction: Swiss punk collage.']);
    $project->writeJson('siteSpec.json', ['name' => 'Coaching', 'visual_vibe' => 'Swiss punk collage']);
    $llm->queueJson(['seeds' => [designdir_seed_obj('Warm Archive', 'dark', 'archival', 'earth')]]);
    $llm->queueJson(['direction' => designdir_direction()]);
    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    assert_eq(2, count($llm->calls));
    assert_contains('swiss punk collage', $llm->calls[1]['prompt']);
    assert_true(!str_contains($llm->calls[1]['prompt'], 'Warm Archive'));
    assert_contains('requested style', implode("\n", $project->readJson('warnings.json')['design-direction']));
});

test('seed judge only sees compatible styles and keeps distinct interpretations of one style', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    try {
        $project->writeJson('siteSpec.json', ['name' => 'Coaching']);
        $project->writeJson('meta.json', ['prompt' => 'A coaching site. Style: organic.']);
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

test('an explicit style survives the business that follows it, and a refusal does not', function () {
    // "…-styled website for a bakery" used to reach nothing: the pattern
    // wanted the sentence to end at the noun, and the business followed it.
    assert_eq('bauhaus', ConceptSeeds::requestedStyle('Build me a bauhaus-styled website for a bakery.'));
    assert_eq('bauhaus', ConceptSeeds::requestedStyle('Build me a bauhaus-styled website.'));
    assert_eq('art-deco', ConceptSeeds::requestedStyle('Create an art deco styled landing page for a law firm.'));

    // Only the business may follow. Another clause is another statement, and
    // an exclusion read as a request states the opposite of the brief.
    assert_eq('', ConceptSeeds::requestedStyle('I want an organically styled site, but not brutalist.'));
    assert_eq('', ConceptSeeds::requestedStyle('Build me a bauhaus-styled website, though nothing severe.'));

    // The wrapper is grammar, not the style's name, for a freeform key too.
    assert_eq('bauhaus', ConceptSeeds::styleKey('bauhaus-styled'));
    assert_eq('organic', ConceptSeeds::styleKey('organically styled'));
});

test('an unprompted default face is recorded, never replaced (frm PR-2ad)', function () {
    // The deterministic substitution is gone so the model reaches the whole
    // catalog. The measurement that justified it must not go with it.
    $direction = ['type' => [
        'heading' => ['family' => 'Inter'],
        'body' => ['family' => 'Spectral'],
        'accent' => ['family' => 'Playfair Display'],
    ]];
    $rows = DesignDirectionStep::monocultureFontWarnings($direction, 'A bakery in Lisbon.');
    assert_eq(2, count($rows), 'both unprompted default faces are recorded');
    assert_contains('type.heading.family', implode("\n", $rows));
    assert_contains('type.accent.family', implode("\n", $rows));
    assert_true(!str_contains(implode("\n", $rows), 'Spectral'), 'a face outside the list is not recorded');

    // A face the brief asks for is a request, not a reflex.
    $asked = DesignDirectionStep::monocultureFontWarnings($direction, 'A bakery in Lisbon. Set the headings in Inter.');
    assert_eq(1, count($asked));
    assert_contains('type.accent.family', $asked[0]);

    // Nothing is substituted: the direction is untouched.
    assert_eq('Inter', $direction['type']['heading']['family']);
});

