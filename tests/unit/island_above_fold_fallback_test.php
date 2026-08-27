<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\Steps\IslandAboveFoldStep;

test('island-above-fold stacked fallback contract passes assertContract', function () {
    with_project('island-above-fold-fallback', function ($project) {
        $project->writeJson('meta.json', ['graph' => 'html-islands']);
        $project->writeJson('siteSpec.json', [
            'slug' => 'fallback',
            'name' => 'Fallback',
            'language' => 'English',
            'writing_direction' => 'ltr',
            'pages' => [['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome']],
        ]);
        seed_test_design_direction($project, 'cinematic-safe-zone');
        $project->writeJson('theme/theme.json', [
            'version' => 3,
            'settings' => ['color' => ['palette' => [
                ['slug' => 'base', 'color' => '#080809', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#FFFFFF', 'name' => 'Contrast'],
            ]]],
        ]);
        $project->writeText('design/site.css', ':root{--base:#080809}');
        $project->writeText(
            'theme/parts/header.html',
            '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
            . '<!-- wp:site-title /--></header><!-- /wp:group -->' . "\n"
        );
        $project->writeText(
            'theme/parts/page-home--hero.html',
            "<!-- wp:html -->\n<section id=\"hero\"><h1>Hi</h1></section>\n<!-- /wp:html -->\n"
        );
        $project->writeText(
            'theme/parts/page-home--cta.html',
            "<!-- wp:html -->\n<section id=\"cta\"><h2>Next</h2></section>\n<!-- /wp:html -->\n"
        );

        // Two sections, {slug,title} only — the island-pages shape. A following
        // section with an empty layout_archetype is what validate-theme aborted on.
        $pages = [[
            'slug' => 'home',
            'title' => 'Home',
            'path' => '/',
            'front' => true,
            'parent' => null,
            'menu_order' => 0,
            'purpose' => '',
            'sections' => [
                ['slug' => 'hero', 'title' => 'Hi'],
                ['slug' => 'cta', 'title' => 'Next'],
            ],
        ]];

        $contract = IslandAboveFoldStep::stackedFallbackContract($project, $pages);
        AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_FINAL);
        $following = $contract['following_section'] ?? null;
        assert_true(is_array($following) || $following === null, 'following_section must be an object or null');
        if (is_array($following)) {
            assert_true(trim((string) ($following['layout_archetype'] ?? '')) !== '', 'layout_archetype must be non-empty');
            assert_true(trim((string) ($following['surface'] ?? '')) !== '', 'surface must be non-empty');
            assert_true(trim((string) ($following['slug'] ?? '')) !== '', 'slug must be non-empty');
            assert_true(trim((string) ($following['part'] ?? '')) !== '', 'part must be non-empty');
        }
    });
});

test('withValidFollowingSection fills a blank layout_archetype rather than leaving validate-theme to abort', function () {
    $filled = IslandAboveFoldStep::withValidFollowingSection([
        'front_page' => 'home',
        'following_section' => [
            'slug' => 'cta',
            'part' => 'page-home--cta',
            'layout_archetype' => '',
            'surface' => 'base',
        ],
    ]);
    assert_eq('html-island', $filled['following_section']['layout_archetype']);
});
