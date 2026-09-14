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

test('theme compiler leaves rhythm, heading weight, and button case to the model', function () {
    $tmp = sys_get_temp_dir() . '/theme_compiler_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A lamp studio']);
    $project->writeJson('siteSpec.json', ['name' => 'Lamp Studio']);
    seed_test_design_direction($project, overrides: ['type' => [
        'heading' => ['family' => 'Spectral', 'weights' => [700, 900]],
        'body' => ['family' => 'Inter', 'weights' => [400]],
    ]]);
    $llm = new FakeLlm();
    $step = new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts')));
    $request = $step->requests($project)['theme-json'];
    $styles = $request['json_schema']['schema']['properties']['styles']['properties'];
    assert_eq(
        ['var:preset|spacing|xs', 'var:preset|spacing|sm', 'var:preset|spacing|md',
            'var:preset|spacing|lg', 'var:preset|spacing|xl', 'var:preset|spacing|xxl'],
        $styles['spacing']['properties']['blockGap']['enum'],
    );
    assert_eq(['700', '900'], $styles['elements']['properties']['heading']['properties']['typography']['properties']['fontWeight']['enum']);
    $button = $styles['elements']['properties']['button']['properties']['typography']['properties'];
    assert_eq(['none', 'uppercase', 'lowercase'], $button['textTransform']['enum']);
    assert_eq(['0', '0.02em', '0.05em', '0.1em'], $button['letterSpacing']['enum']);
    assert_contains('committed heading weights: `700`, `900`', $request['prompt']);
    assert_contains('`packed` and `dense` use `xs` or `sm`', $request['prompt']);

    $choices = ['styles' => [
        'typography' => ['lineHeight' => '1.6'],
        'spacing' => ['blockGap' => 'var:preset|spacing|lg'],
        'elements' => [
            'heading' => ['typography' => ['lineHeight' => '1.1', 'fontWeight' => '900']],
            'button' => ['typography' => ['fontWeight' => '600', 'textTransform' => 'uppercase', 'letterSpacing' => '0.05em']],
        ],
        'blocks' => ['core/navigation' => ['typography' => ['fontWeight' => '500']]],
    ]];
    $step->consume($project, ['theme-json' => $choices]);
    $theme = $project->readJson('theme/theme.json');
    assert_eq('var:preset|spacing|lg', $theme['styles']['spacing']['blockGap']);
    assert_eq('900', $theme['styles']['elements']['heading']['typography']['fontWeight']);
    assert_eq('uppercase', $theme['styles']['elements']['button']['typography']['textTransform']);
    assert_eq('0.05em', $theme['styles']['elements']['button']['typography']['letterSpacing']);
    assert_eq('600', $theme['styles']['elements']['button']['typography']['fontWeight']);
    assert_eq('500', $theme['styles']['blocks']['core/navigation']['typography']['fontWeight']);
    assert_eq([], $llm->calls);

    // A direction without committed weights leaves the generic weight choices.
    $bare = (new ProjectStore($tmp))->create('bare');
    $bare->writeJson('meta.json', ['prompt' => 'A lamp studio']);
    $bare->writeJson('siteSpec.json', ['name' => 'Lamp Studio']);
    seed_test_design_direction($bare, overrides: ['type' => [
        'heading' => ['family' => 'Spectral'],
        'body' => ['family' => 'Inter'],
    ]]);
    $schema = $step->requests($bare)['theme-json']['json_schema']['schema'];
    assert_eq(['400', '500', '600', '700'], $schema['properties']['styles']['properties']['elements']['properties']['heading']['properties']['typography']['properties']['fontWeight']['enum']);
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
