<?php
declare(strict_types=1);

use Automattic\SiteBuild\FontCatalog;
use Automattic\SiteBuild\FontShortlist;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

test('combined selection prepares each seed contract and sends each shared recipe once', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $seeds = [
        ['seed' => 'First concept', 'ground' => 'light', 'tint' => 'warm', 'register' => 'editorial', 'type_register' => 'humanist', 'accent' => 'earth', 'color_economy' => 'monochrome'],
        ['seed' => 'Second concept', 'ground' => 'dark', 'tint' => 'cool', 'register' => 'noir', 'type_register' => 'didone', 'accent' => 'jewel', 'color_economy' => 'single-accent'],
    ];
    $llm->queueJson(['seeds' => $seeds]);
    $llm->queueJson(designdir_response(['direction' => designdir_direction()], 1, 'The second concept fits the subject.'));
    putenv('HERO_RECIPE=foreground-split');
    try {
        (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
        assert_eq(2, count($llm->calls));
        $prompt = $llm->calls[1]['prompt'];
        assert_eq(1, substr_count($prompt, '## Recipe: foreground-split'));
        assert_true(!str_contains($prompt, 'wp:columns'), 'direction requests omit block markup instructions');
        assert_contains('Preserve every selected blueprint value', $prompt);
        assert_true(strlen($prompt) < 49000, 'the fixture fits below the previous judge plus expansion byte count');
        foreach ($seeds as $seed) {
            assert_contains($seed['seed'], $prompt);
            foreach (FontShortlist::candidates($seed['type_register'], 'demo', FontCatalog::load(), $seed['register']) as $family) {
                assert_contains($family, $prompt);
            }
            foreach (HeroComposition::selectMediaAxes('demo', $seed['seed'], 'foreground-split') as $axis => $value) {
                assert_contains('"' . $axis . '":"' . $value . '"', $prompt);
            }
        }
        $direction = $project->readJson('designDirection.json');
        assert_eq('Second concept', $direction['concept_seed']);
        assert_eq('dark', $direction['ground_key']);
        assert_eq('cool', $direction['ground_tint']);
        assert_eq('noir', $direction['register']);
        assert_eq('single-accent', $direction['color_economy']);
        assert_contains('The second concept fits the subject.', $project->readText('logs/design-direction.txt'));
    } finally {
        putenv('HERO_RECIPE');
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
