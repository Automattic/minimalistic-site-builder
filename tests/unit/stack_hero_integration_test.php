<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\HeroUnit;
use Automattic\SiteBuild\Units\SectionUnit;

test('authored home and inner openings reject both committed labels without losing their content', function () {
    foreach (['section-badge' => section_label_part(['Use cases']), 'side-label' => side_label_split()] as $kind => $raw) {
        foreach (['home', 'inner'] as $page) {
            $input = $page === 'home' ? hero_unit_contract_input('authored', null) : section_unit_input();
            $input['section_label'] = $kind;
            $input['section']['primary_action'] = null;
            $input['authored_opening'] = $page === 'inner';
            $unit = $page === 'home'
                ? new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')))
                : new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
            $result = $unit->finish($raw, $input);
            assert_true(!str_contains($result->markup, $kind), "$page/$kind");
            assert_contains('hero-composition--authored', $result->markup);
            assert_contains('<h2', $result->markup, 'the heading survives');
            assert_contains('delivered=removed', implode("\n", $result->warnings));
            assert_eq($result->markup, $unit->finish($result->markup, $input)->markup);
        }
    }
});

test('shared authoring instructions carry both intentional label exceptions', function () {
    foreach (['site-context.md', 'design-direction.md', 'page-plan.md', 'page-styles.md'] as $file) {
        $prompt = file_get_contents(repo_path('prompts/' . $file));
        assert_contains('Eyebrows are banned except for the committed section label', $prompt, $file);
        assert_contains('`section-badge` or `side-label`', $prompt, $file);
    }
    foreach (['hero.md', 'hero-recipe.md'] as $file) {
        $prompt = file_get_contents(repo_path('prompts/' . $file));
        assert_contains('word-reveal', $prompt);
        assert_contains('class="emph"', $prompt);
        assert_true(!str_contains($prompt, 'ORIENTATION LABEL — one optional'), $file);
    }
});
