<?php
declare(strict_types=1);

use Automattic\SiteBuild\MarkupContext;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\HeaderUnit;
use Automattic\SiteBuild\Units\SectionUnit;

function markup_budget_input(): array
{
    return [
        'site_spec' => ['name' => 'Context test'], 'language' => 'English',
        'theme_json' => ['version' => 3], 'design_direction' => 'Keep the full creative intent.',
        'outline' => 'Hero, products, features', 'site_pages' => 'Home: /',
        'page' => ['slug' => 'home', 'title' => 'Home', 'path' => '/'],
        'section' => [
            'slug' => 'products', 'title' => 'Products', 'role' => 'content',
            'purpose' => 'Show products.', 'content_notes' => 'Product details.',
            'layout_archetype' => 'equal-card-grid', 'background' => 'base',
            'vertical_density' => 'standard', 'text_placement' => 'left-column',
            'handoff' => 'Features follow.', 'item_pattern' => null,
        ],
        'neighbors' => 'Hero, features', 'header_contract' => '',
    ];
}

test('markup theme context keeps rendered styles and presets but omits font sources', function () {
    $theme = json_decode(file_get_contents(repo_path('tests/fixtures/theme-json/representative-model-response.json')), true);
    $theme['settings']['typography']['fontFamilies'][0]['fontFace'] = [['src' => str_repeat('font-source', 500)]];
    $compact = MarkupContext::theme($theme);
    $decoded = json_decode($compact, true);
    assert_eq($theme['styles'], $decoded['styles']);
    assert_eq($theme['settings']['color']['palette'], $decoded['settings']['color']['palette']);
    assert_true(!str_contains($compact, 'font-source'));
    assert_true(strlen($compact) < strlen(json_encode($theme, JSON_PRETTY_PRINT)) / 2);
});

test('image sections share compact cached rules while no-media sections share the first two layers', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = markup_budget_input();
    $first = $unit->request($input);
    $input['section']['slug'] = 'more-products';
    $second = $unit->request($input);
    assert_eq($first['cached_prefixes'], $second['cached_prefixes']);
    $input['section']['layout_archetype'] = 'feature-row-hairlines';
    $plain = $unit->request($input);
    assert_eq(array_slice($first['cached_prefixes'], 0, 2), array_slice($plain['cached_prefixes'], 0, 2));
    assert_contains('AI_IMAGE: subject | page-context | style | aspect-ratio', $first['cached_prefixes'][2]);
    assert_true(!str_contains(implode('', $plain['cached_prefixes']) . $plain['prompt'], 'AI_IMAGE: subject |'));
    assert_true(!str_contains($first['prompt'], 'AI_IMAGE: subject |'));
    $rules = file_get_contents(repo_path('prompts/image-markup.md'));
    assert_true(strlen($rules) < 4000);
    assert_true(strlen($rules) < strlen(file_get_contents(repo_path('prompts/image-generation.md'))) / 3);
    foreach (['theme:./assets/', 'url', 'alt', 'ui-screenshot', 'empty', 'grade', 'captions', 'decorative'] as $term) {
        assert_contains($term, $rules);
    }
});

test('header prompt includes only its assigned recipe and shares normalized site JSON', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = markup_budget_input() + [
        'hero_brief' => 'A hero.', 'nav_rule' => 'Use site paths.',
        'above_fold_contract' => test_above_fold_contract(), 'header_behavior' => 'static',
    ];
    $arrayRequest = $unit->request($input);
    $input['theme_json'] = json_encode($input['theme_json'], JSON_PRETTY_PRINT) . "\n";
    $textRequest = $unit->request($input);
    assert_eq($arrayRequest['cached_prefixes'], $textRequest['cached_prefixes']);
    assert_true(!str_contains($arrayRequest['prompt'], 'Header archetype catalog'));
    assert_true(!str_contains($arrayRequest['prompt'], 'A hard-edged mosaic'));
    assert_contains('ASSIGNED HEADER RECIPE:', $arrayRequest['prompt']);
    assert_contains('Keep the full creative intent.', $arrayRequest['cached_prefixes'][0]);
});
