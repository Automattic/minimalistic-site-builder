<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\StepNumeral;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;

function step_numeral_item(string $digit, bool $numeral = true, string $title = 'Discovery'): string
{
    return '<!-- wp:group {"className":"item-pattern__item card-style--flush"} --><div class="wp-block-group item-pattern__item card-style--flush">'
        . ($numeral ? '<!-- wp:paragraph {"className":"step-numeral","fontSize":"caption"} --><p class="step-numeral has-caption-font-size">' . $digit . '</p><!-- /wp:paragraph -->' : '')
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $title . '</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>One line.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
}

function step_numeral_section(string $items): string
{
    return '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">How we work</h2><!-- /wp:heading -->'
        . $items . '</div><!-- /wp:group -->';
}

test('the step-numeral kit paints a chip or a ghost figure and a process section is recognised by its words (frm W6c)', function () {
    assert_eq(['none', 'chip', 'ghost'], StepNumeral::ALL);
    assert_eq(null, StepNumeral::kitCss('none'));
    $chip = (string) StepNumeral::kitCss('chip');
    assert_contains('p.step-numeral {', $chip);
    assert_contains('border-radius: 50%', $chip);
    assert_contains('color-mix(in srgb, currentColor 10%, transparent)', $chip, 'the chip is the surface ink, no new colour pair');
    $ghost = (string) StepNumeral::kitCss('ghost');
    assert_contains('opacity: 0.22', $ghost);
    assert_contains('var(--wp--preset--font-size--display', $ghost);
    foreach ([['process', 'process'], ['how-it-works', 'method'], ['Workflow steps', 'x'], ['timeline', ''], ['', 'onboarding-steps']] as [$type, $slug]) {
        assert_true(StepNumeral::isProcessSection($type, $slug), "{$type}/{$slug}");
    }
    foreach ([['testimonials', 'voices'], ['pricing', 'plans'], ['portfolio-preview', 'work']] as [$type, $slug]) {
        assert_true(!StepNumeral::isProcessSection($type, $slug), "{$type}/{$slug}");
    }
});

test('the boundary keeps committed numerals on process step items, renumbers them, and removes every other numeral (frm W6c)', function () {
    $good = step_numeral_section(step_numeral_item('1') . step_numeral_item('2', true, 'Strategy') . step_numeral_item('3', true, 'Launch'));
    $kept = StepNumeral::normalize($good, 'chip', 'page-home--process', true);
    assert_eq($good, $kept['markup']);
    assert_eq([], $kept['warnings']);

    $wrongOrder = step_numeral_section(step_numeral_item('3') . step_numeral_item('1', true, 'Strategy') . step_numeral_item('7', true, 'Launch'));
    $fixed = StepNumeral::normalize($wrongOrder, 'chip', 'page-home--process', true);
    assert_contains('>1</p>', $fixed['markup']);
    assert_true(!str_contains($fixed['markup'], '>7</p>'), 'renumbered in document order');
    assert_eq(3, count($fixed['repairs']));
    preg_match_all('/step-numeral has-caption-font-size">(\d+)</', $fixed['markup'], $m);
    assert_eq(['1', '2', '3'], $m[1]);

    $uncommitted = StepNumeral::normalize($good, 'none', 'page-home--process', true);
    assert_true(!str_contains($uncommitted['markup'], 'step-numeral'), 'no commitment, no numerals');
    assert_eq(3, count($uncommitted['warnings']));

    $notProcess = StepNumeral::normalize($good, 'chip', 'page-home--services', false);
    assert_true(!str_contains($notProcess['markup'], 'step-numeral'), 'a services row is not a process');
    assert_contains('process section only', $notProcess['warnings'][0]);

    $loose = step_numeral_section('<!-- wp:paragraph {"className":"step-numeral"} --><p class="step-numeral">1</p><!-- /wp:paragraph -->' . step_numeral_item('2'));
    $strict = StepNumeral::normalize($loose, 'ghost', 'page-home--process', true);
    assert_eq(1, substr_count($strict['markup'], 'class="step-numeral'), 'a numeral outside a step item goes');
    assert_contains('opens its step item', $strict['warnings'][0]);

    $words = step_numeral_section(step_numeral_item('Step 1') . step_numeral_item('2', true, 'Strategy'));
    $digits = StepNumeral::normalize($words, 'chip', 'page-home--process', true);
    assert_true(!str_contains($digits['markup'], 'Step 1'), 'words are not a numeral');
});

test('the direction persists, formats and reads step_numeral and finalize ships the kit (frm W6c)', function () {
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'hero_blueprint' => \Automattic\SiteBuild\HeroBlueprint::defaultFor('cinematic-safe-zone'), 'step_numeral' => 'Ghost'],
        'cinematic-safe-zone',
        '',
        warnings: $warnings,
    );
    assert_eq('ghost', $direction['step_numeral']);
    assert_contains('**Step numeral**: ghost', DesignDirectionStep::format(['description' => 'x', 'step_numeral' => 'ghost']));
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x', 'step_numeral' => 'none']), 'Step numeral'));

    $tmp = sys_get_temp_dir() . '/builder_numeral_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    assert_eq('none', DesignDirectionStep::stepNumeralFor($project));
    $project->writeJson('designDirection.json', ['description' => 'x', 'step_numeral' => 'chip']);
    assert_eq('chip', DesignDirectionStep::stepNumeralFor($project));
    finalize_static_header($project);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_contains('p.step-numeral {', $project->readText('theme/assets/numeral/numeral.css'));
    assert_contains("wp_enqueue_style('forno-vero-numeral', get_theme_file_uri('assets/numeral/numeral.css'), array('forno-vero-style'), \$ver);", $project->readText('theme/functions.php'));
    $project->writeJson('designDirection.json', ['description' => 'x', 'step_numeral' => 'none']);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_true(!$project->exists('theme/assets/numeral/numeral.css'), 'stale numeral kit pruned');
    exec('rm -rf ' . escapeshellarg($tmp));
});
