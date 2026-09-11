<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\SectionUnit;

test('section specifics omit unrelated grid instructions', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    foreach (['cta-panel', 'full-bleed-cover', 'zigzag-steps'] as $archetype) {
        $input = section_unit_input();
        $input['section']['layout_archetype'] = $archetype;
        $prompt = section_unit_request_text($unit->request($input));
        assert_true(!str_contains($prompt, '1. `equal-grid`'), $archetype);
        assert_true(!str_contains($prompt, '2. `staggered-grid`'), $archetype);
        assert_eq($archetype === 'zigzag-steps', str_contains($prompt, '### zigzag-steps'));
        assert_true(!str_contains($prompt, '### equal-card-grid'));
        assert_true(!str_contains($prompt, '### offset-grid'));
        assert_contains('AI_IMAGE: subject | page-context | style | aspect-ratio', $prompt);
        assert_contains('HARD FACTS:', $prompt);
        assert_contains('UNIT-THEME', strtoupper($prompt));
    }
});

test('section specifics send only the assigned card anatomy with complete overlap construction', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    foreach (['flush', 'framed', 'overlap', 'borderless'] as $style) {
        $input = section_unit_input();
        $input['section']['layout_archetype'] = 'equal-card-grid';
        $input['card_style'] = $style;
        $prompt = section_unit_request_text($unit->request($input));
        assert_contains('card-style--' . $style, $prompt);
        foreach (array_diff(['flush', 'framed', 'overlap', 'borderless'], [$style]) as $other) {
            assert_true(!str_contains($prompt, 'card-style--' . $other), "$style excludes $other");
        }
        if ($style === 'overlap') {
            assert_contains('FIRST direct child', $prompt);
            assert_contains('card-body overlap-up', $prompt);
        }
    }
});

test('section specifics gate motion vocabulary and keep shared cache prefixes stable across compositions', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    foreach (['none', 'minimal', 'calm', 'energetic', 'dramatic'] as $profile) {
        $input = section_unit_input();
        $input['motion_profile'] = $profile;
        $request = $unit->request($input);
        $prompt = section_unit_request_text($request);
        assert_eq($profile !== 'none', str_contains($prompt, '- `hover-lift`'));
        assert_eq(!in_array($profile, ['none', 'minimal'], true), str_contains($prompt, '- `reveal-up`'));
        foreach (['sticky-stack', 'count-up', 'marquee'] as $effect) {
            assert_eq(!in_array($profile, ['none', 'minimal'], true), str_contains($prompt, '- `' . $effect . '`'), "$profile/$effect");
        }
        foreach (array_diff(['calm', 'energetic', 'dramatic'], [$profile]) as $other) {
            assert_true(!str_contains($prompt, '- `' . $other . '`:'), "$profile excludes $other choreography");
        }
        $input['section']['layout_archetype'] = 'equal-card-grid';
        assert_eq($request['cached_prefixes'], $unit->request($input)['cached_prefixes']);
        assert_contains('custom-motion', $prompt);
    }
});

test('section specifics keep complete requests within a bounded instruction budget', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    foreach (['cta-panel', 'full-bleed-cover', 'equal-card-grid', 'asymmetric-split', 'offset-grid', 'zigzag-steps'] as $archetype) {
        $input = section_unit_input();
        $input['section']['layout_archetype'] = $archetype;
        foreach (['none', 'minimal', 'calm', 'energetic', 'dramatic'] as $profile) {
            $input['motion_profile'] = $profile;
            // Includes section-label guidance alongside the expanded motion recipes.
            // Fixture maxima: 49,668 bytes for minimal motion, 55,187 for animated
            // (BIGR-1015 added the masthead-preset rule to the type-scale block).
            $budget = in_array($profile, ['none', 'minimal'], true) ? 50000 : 55500;
            $bytes = strlen(section_unit_request_text($unit->request($input)));
            assert_true($bytes < $budget, "$archetype/$profile request is $bytes bytes; budget is $budget including fixture context");
        }
    }
});

test('section specifics keep item cards available inside a cardless-looking composition', function () {
    $input = section_unit_input();
    $input['section']['layout_archetype'] = 'faq-split';
    $input['section']['item_pattern'] = 'card';
    $input['card_style'] = 'framed';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $prompt = section_unit_request_text($unit->request($input));
    assert_contains('card-style--framed', $prompt);
    assert_contains('"className":"card-media"', $prompt);
    assert_contains('NEVER write cropping as an inline style or `aspectRatio`', $prompt);
    foreach (['rule-row', 'spec-table', 'tag-cluster'] as $pattern) {
        $input['section']['item_pattern'] = $pattern;
        $request = $unit->request($input);
        assert_contains('card-style--framed', $request['cached_prefixes'][1]);
        assert_true(!str_contains($request['prompt'], 'Card anatomy'));
        assert_true(!str_contains($request['prompt'], 'card-style--framed'));
    }
});

test('section specifics receive the persisted motion commitment through SectionsStep', function () {
    with_project('section_prompt_specifics_', function ($project) {
        seed_section_cache_project($project);
        $direction = $project->readJson('designDirection.json');
        $step = new \Automattic\SiteBuild\Steps\SectionsStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
        foreach (['none', 'minimal', 'calm', 'energetic', 'dramatic'] as $profile) {
            $direction['motion'] = $profile;
            $project->writeJson('designDirection.json', $direction);
            $request = $step->requests($project)['page-home--about'];
            $prompt = section_unit_request_text($request);
            assert_eq($profile !== 'none', str_contains($prompt, '- `hover-lift`'));
            assert_eq(!in_array($profile, ['none', 'minimal'], true), str_contains($prompt, '- `reveal-up`'));
        }
    });
});

test('section specifics fail closed for missing and invalid motion commitments', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    foreach ([null, '', 'invalid', [], 42] as $profile) {
        $input = section_unit_input();
        $input['motion_profile'] = $profile;
        assert_contains('Motion: none', section_unit_request_text($unit->request($input)));
    }
});
