<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Tests\FakeLlm;

test('theme compiler keeps the fixed design values outside the bounded model response', function () {
    $tmp = sys_get_temp_dir() . '/theme_compiler_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A lamp studio']);
    $project->writeJson('siteSpec.json', ['name' => 'Lamp Studio']);
    seed_test_design_direction($project);
    $step = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $request = $step->requests($project)['theme-json'];
    assert_true(strlen($request['prompt']) < 4000);
    $schema = $request['json_schema']['schema'];
    assert_eq(['styles'], $schema['required']);
    assert_eq(false, $schema['additionalProperties']);
    assert_true(!str_contains(json_encode($schema), 'palette'));
    assert_true(!str_contains($request['prompt'], 'exactly five'));
    assert_contains('all six palette colors', $request['prompt']);
    $htmlPrompt = file_get_contents(repo_path('prompts/theme-json.md'));
    foreach (['base', 'contrast', 'primary', 'secondary', 'accent', 'band'] as $slug) {
        assert_contains('"' . $slug . '"', $htmlPrompt);
    }
    assert_eq(true, ThemeJsonStep::compileDefaults([])['settings']['typography']['fluid']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme compiler repairs the Lumen and Atlas secondary contrast without another color request', function () {
    $cases = [
        'lumen' => [
            'base' => '#F4EBDC', 'contrast' => '#221A12', 'primary' => '#8C5A20',
            'secondary' => '#7A6A55', 'accent' => '#D40E0D', 'band' => '#E7D9C3',
        ],
        'atlas' => [
            'base' => '#EDEEEC', 'contrast' => '#1D2124', 'primary' => '#3F4B52',
            'secondary' => '#6B7378', 'accent' => '#E4761B', 'band' => '#D4D6D1',
        ],
    ];
    foreach ($cases as $name => $palette) {
        $tmp = sys_get_temp_dir() . '/theme_compiler_' . uniqid();
        $project = (new ProjectStore($tmp))->create($name);
        $project->writeJson('meta.json', ['prompt' => 'A design test']);
        $project->writeJson('siteSpec.json', ['name' => $name]);
        seed_test_design_direction($project, overrides: ['palette' => $palette]);
        $llm = new FakeLlm();
        $step = new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts')));
        $choices = ['styles' => [
            'typography' => ['lineHeight' => '1.7'],
            'elements' => ['heading' => ['typography' => ['lineHeight' => '1.15']]],
        ]];
        $step->consume($project, ['theme-json' => $choices]);
        $theme = $project->readJson('theme/theme.json');
        $colors = array_column($theme['settings']['color']['palette'], 'color', 'slug');
        assert_eq(6, count($colors));
        assert_true(ContrastMath::ratio(ContrastMath::hexToRgb($colors['base']), ContrastMath::hexToRgb($colors['secondary'])) >= 4.5);
        assert_true($palette['secondary'] !== $colors['secondary']);
        assert_eq('1.15', $theme['styles']['elements']['heading']['typography']['lineHeight']);
        assert_eq('1.7', $theme['styles']['typography']['lineHeight']);
        assert_eq([], $llm->calls);
        $warnings = json_encode($project->readJson('warnings.json'));
        assert_contains('secondary', $warnings);
        assert_contains($palette['secondary'], $warnings);
        $step->consume($project, ['theme-json' => $choices]);
        assert_eq($theme, $project->readJson('theme/theme.json'));
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
