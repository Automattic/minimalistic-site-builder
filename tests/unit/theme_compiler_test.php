<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\ContrastFixStep;
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

test('theme compiler supplies readable CTA links from a typography response without another request', function () {
    $tmp = sys_get_temp_dir() . '/theme_compiler_links_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A Georgian tavern']);
    $project->writeJson('siteSpec.json', ['name' => 'Tbilisi']);
    seed_test_design_direction($project, overrides: ['palette' => [
        'base' => '#1A1710', 'contrast' => '#EDE0CE', 'primary' => '#B4864B',
        'secondary' => '#9A8267', 'accent' => '#9E2B2B', 'band' => '#3A3323',
    ]]);
    $choices = ['styles' => [
        'typography' => ['lineHeight' => '1.6'],
        'elements' => [
            'heading' => ['typography' => ['lineHeight' => '1.15']],
            'button' => ['typography' => ['fontWeight' => '600']],
        ],
        'blocks' => ['core/navigation' => ['typography' => ['fontWeight' => '500']]],
    ]];
    $llm = new FakeLlm();
    $llm->queueJson($choices);
    $step = new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts')));
    $step->run($project);

    assert_eq(1, count($llm->calls));
    $schema = $llm->calls[0]['opts']['json_schema']['schema'];
    $elementsSchema = $schema['properties']['styles']['properties']['elements'];
    assert_eq(['heading', 'button'], $elementsSchema['required']);
    assert_eq(false, $elementsSchema['additionalProperties']);
    assert_true(!isset($elementsSchema['properties']['link']));
    $theme = $project->readJson('theme/theme.json');
    assert_eq('var:preset|color|primary', $theme['styles']['elements']['link']['color']['text']);
    assert_eq('var:preset|color|accent', $theme['styles']['elements']['link'][':hover']['color']['text']);
    assert_true(!isset($theme['styles']['elements']['link'][':focus']));
    assert_eq('1.6', $theme['styles']['typography']['lineHeight']);

    $baseCta = '<!-- wp:paragraph {"className":"text-action"} -->'
        . '<p class="text-action"><a href="/menu/">Read the full menu</a></p><!-- /wp:paragraph -->';
    $readableBand = '<!-- wp:group {"backgroundColor":"band","textColor":"contrast",'
        . '"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"},'
        . '":hover":{"color":{"text":"var:preset|color|contrast"}}}}}} -->'
        . '<div class="wp-block-group has-band-background-color has-contrast-color has-background has-text-color has-link-color">'
        . '<!-- wp:paragraph --><p><a href="/about/">Read our story</a></p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $failingBand = str_replace(
        '":hover":{"color":{"text":"var:preset|color|contrast"}}',
        '":hover":{"color":{"text":"var:preset|color|accent"}}',
        $readableBand,
    );
    $project->writeText('theme/parts/home-cta.html', $baseCta);
    $project->writeText('theme/parts/readable-band.html', $readableBand);
    $project->writeText('theme/parts/failing-band.html', $failingBand);
    $contrast = new ContrastFixStep();
    quietly(fn () => $contrast->run($project));

    $theme = $project->readJson('theme/theme.json');
    $links = $theme['styles']['elements']['link'];
    assert_eq('var:preset|color|primary', $links['color']['text']);
    assert_eq('var(--wp--preset--color--contrast)', $links[':hover']['color']['text']);
    assert_true(!isset($links[':focus']), 'The focus state must retain the normal or local link color.');
    $colors = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    assert_true(ContrastMath::ratio(ContrastMath::hexToRgb($colors['base']), ContrastMath::hexToRgb($colors['primary'])) >= 4.5);
    assert_true(ContrastMath::ratio(ContrastMath::hexToRgb($colors['base']), ContrastMath::hexToRgb($colors['contrast'])) >= 4.5);
    assert_true(ContrastMath::ratio(ContrastMath::hexToRgb($colors['base']), ContrastMath::hexToRgb($colors['accent'])) < 4.5);
    assert_true(ContrastMath::ratio(ContrastMath::hexToRgb($colors['band']), ContrastMath::hexToRgb($colors['contrast'])) >= 4.5);
    assert_eq($baseCta, $project->readText('theme/parts/home-cta.html'));
    assert_eq($readableBand, $project->readText('theme/parts/readable-band.html'));
    assert_eq($readableBand, $project->readText('theme/parts/failing-band.html'));
    assert_contains('global link hover', $project->readText('logs/contrast-report.txt'));
    assert_eq(1, count($llm->calls), 'The contrast repair must use no LLM request.');

    quietly(fn () => $contrast->run($project));
    assert_eq($theme, $project->readJson('theme/theme.json'));
    assert_eq($readableBand, $project->readText('theme/parts/failing-band.html'));
    $step->consume($project, ['theme-json' => $choices]);
    quietly(fn () => $contrast->run($project));
    assert_eq($theme, $project->readJson('theme/theme.json'));
    assert_eq(1, count($llm->calls));
    remove_tree($tmp);
});

test('theme compiler supplies link defaults when the typography response is empty', function () {
    $tmp = sys_get_temp_dir() . '/theme_compiler_empty_links_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A local studio']);
    $project->writeJson('siteSpec.json', ['name' => 'Studio']);
    seed_test_design_direction($project);
    $llm = new FakeLlm();
    $llm->queueJson([]);
    $step = new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts')));
    $step->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq('var:preset|color|primary', $theme['styles']['elements']['link']['color']['text']);
    assert_eq('var:preset|color|accent', $theme['styles']['elements']['link'][':hover']['color']['text']);
    assert_true(!isset($theme['styles']['elements']['link'][':focus']));
    assert_contains('authored empty object', json_encode($project->readJson('warnings.json')));
    assert_eq(1, count($llm->calls));
    $step->consume($project, ['theme-json' => []]);
    assert_eq($theme, $project->readJson('theme/theme.json'));
    assert_eq(1, count($llm->calls));
    remove_tree($tmp);
});
