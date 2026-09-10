<?php
declare(strict_types=1);

use Automattic\SiteBuild\TypeTreatment;

test('type treatment maps every bounded commitment to exact case and tracking leaves', function () {
    $expected = [
        'sentence' => ['textTransform' => 'none', 'letterSpacing' => '-0.01em'],
        'tight' => ['textTransform' => 'none', 'letterSpacing' => '-0.04em'],
        'caps-tight' => ['textTransform' => 'uppercase', 'letterSpacing' => '-0.03em'],
        'caps-tracked' => ['textTransform' => 'uppercase', 'letterSpacing' => '0.08em'],
        'lowercase' => ['textTransform' => 'lowercase', 'letterSpacing' => '0.01em'],
    ];

    assert_eq(array_keys($expected), TypeTreatment::ALL);
    foreach ($expected as $treatment => $typography) {
        assert_eq($typography, TypeTreatment::typography($treatment));
        assert_contains($typography['letterSpacing'], TypeTreatment::meaning($treatment));
    }
});

test('the display register tightens the line height of an uppercase site treatment (BIGR-997)', function () {
    // Uppercase display lines set taller and wider than sentence case, so a
    // headline that holds one line in sentence case wraps under caps.
    foreach (['caps-tight', 'caps-tracked'] as $treatment) {
        $css = (string) TypeTreatment::kitCss($treatment);
        assert_contains('.wp-block-heading.has-display-font-size {', $css);
        assert_contains('line-height: 0.92;', $css);
        assert_contains('text-wrap: balance;', $css);
    }

    // Every other treatment ships no kit: lowercase is a deliberate craft
    // voice on long lines, and the rest never set caps.
    foreach (['sentence', 'tight', 'title', 'lowercase', 'small-caps', '', null, 7] as $treatment) {
        assert_eq(null, TypeTreatment::kitCss($treatment));
    }
});

test('type treatment rejects absent and unsupported commitments without guessing', function () {
    foreach ([null, '', 'small-caps', ['title'], 7, 'title'] as $value) {
        assert_eq(null, TypeTreatment::typography($value));
    }
    // Title Case is retired (frm PR-5p): a direction that commits it falls to sentence with a warning.
    assert_eq(['title'], TypeTreatment::RETIRED);
    assert_true(!in_array('title', TypeTreatment::ALL, true));
    assert_eq('caps-tracked', TypeTreatment::explicit(' Caps-Tracked '));
});

test('type treatment prompt contract keeps sentence casing authored and block overrides absent', function () {
    $direction = (string) file_get_contents(repo_path('prompts/design-direction.md'));
    $theme = (string) file_get_contents(repo_path('prompts/theme-json.md'));
    $section = (string) file_get_contents(repo_path('prompts/section.md'));

    assert_contains('`type_treatment`', $direction);
    assert_contains('preserving the theme model\'s heading `lineHeight`', $direction);
    assert_contains('Do not emit either owned leaf', $theme);
    assert_contains('For `sentence` and `tight`, author sentence-case text', $section);
    assert_contains('`"tight"`', $direction);
    assert_contains('when the DESIGN DIRECTION\'s **Type treatment** is `tight`', $theme);
    assert_contains('Do not set either value in a `wp:heading` block', $section);
});

test('the display-lines kit ships for the uppercase treatments only and finalize enqueues it (frm W5c)', function () {
    assert_eq(['caps-tight', 'caps-tracked'], TypeTreatment::STACKED_LINE_TREATMENTS);
    foreach (['sentence', 'tight', 'lowercase'] as $treatment) {
        assert_eq(null, TypeTreatment::kitCss($treatment), $treatment);
    }
    $css = (string) TypeTreatment::kitCss('caps-tight');
    assert_contains('.hero-composition__copy .wp-block-heading:is(h1, .has-display-font-size)', $css);
    assert_contains('line-height: 0.92', $css);
    assert_contains('text-wrap: balance', $css);
    assert_true(!str_contains($css, '!important'));

    $tmp = sys_get_temp_dir() . '/builder_fin_treatment_' . uniqid();
    $project = (new \Automattic\SiteBuild\ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'type_treatment' => 'caps-tracked']);
    finalize_static_header($project);
    quietly(fn () => (new \Automattic\SiteBuild\Steps\FinalizeThemeStep())->run($project));
    assert_contains('line-height: 0.92', $project->readText('theme/assets/type-treatment/type-treatment.css'));
    assert_contains("wp_enqueue_style('forno-vero-type-treatment', get_theme_file_uri('assets/type-treatment/type-treatment.css'), array('forno-vero-style'), \$ver);", $project->readText('theme/functions.php'));
    $project->writeJson('designDirection.json', ['description' => 'x', 'type_treatment' => 'sentence']);
    quietly(fn () => (new \Automattic\SiteBuild\Steps\FinalizeThemeStep())->run($project));
    assert_true(!$project->exists('theme/assets/type-treatment/type-treatment.css'), 'stale treatment kit pruned');
    exec('rm -rf ' . escapeshellarg($tmp));
    $scaffold = (string) file_get_contents(repo_path('src/Steps/ScaffoldThemeStep.php'));
    assert_contains('text-wrap: balance;', $scaffold, 'every hero heading balances its lines');
});


test('the type-treatment kit declares its output path', function () {
    $step = new \Automattic\SiteBuild\Steps\FinalizeThemeStep();
    assert_true(in_array('theme/assets/type-treatment/*', $step->declaration()->writes, true));
});

test('a resumed title treatment uses sentence case and records one warning', function () {
    with_project('retired-type', function ($project) {
        $project->writeJson('designDirection.json', ['type_treatment' => 'title']);
        assert_eq('sentence', \Automattic\SiteBuild\Steps\DesignDirectionStep::typeTreatmentFor($project));
        assert_eq('sentence', \Automattic\SiteBuild\Steps\DesignDirectionStep::typeTreatmentFor($project));
        $warnings = $project->readJson('warnings.json')['design-direction'];
        assert_eq(1, count($warnings));
        assert_contains('; delivered "sentence";', $warnings[0]);
    });
    assert_eq('', \Automattic\SiteBuild\FontShortlist::productWeightSentence('grotesque', 'pop'));
});
