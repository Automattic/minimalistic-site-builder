<?php
declare(strict_types=1);

use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\MotionSanityStep;

function motion_group(string $classes, string $inner = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->'): string
{
    return '<!-- wp:group {"className":"' . $classes . '","layout":{"type":"constrained"}} -->'
        . "\n" . '<div class="wp-block-group ' . $classes . '">' . $inner . '</div>'
        . "\n" . '<!-- /wp:group -->';
}

test('motion-sanity keeps a single documented class untouched', function () {
    $budget = MotionSanityStep::newBudget();
    $markup = motion_group('reveal-up');
    $result = MotionSanityStep::sanitize($markup, 'calm', $budget);
    assert_eq($markup, $result['markup']);
    assert_eq([], $result['notes']);
});

test('motion-sanity trims a block to one motion class, syncing the saved HTML', function () {
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize(motion_group('reveal-up reveal-fade'), 'calm', $budget);
    assert_contains('"className":"reveal-up"', $result['markup']);
    assert_true(!str_contains($result['markup'], 'reveal-fade'), 'loser removed from attrs AND html');
    assert_contains('class="wp-block-group reveal-up"', $result['markup']);
    assert_eq(1, count($result['notes']));
});

test('motion-sanity strips unknown motion variants and is-visible but leaves other classes alone', function () {
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize(
        motion_group('equal-cards reveal-left motion-spin is-visible custom-motion'),
        'calm',
        $budget
    );
    assert_contains('"className":"equal-cards custom-motion"', $result['markup']);
    assert_contains('class="wp-block-group equal-cards custom-motion"', $result['markup']);
    assert_eq(3, count($result['notes']));
});

test('motion-sanity gates by profile: minimal keeps hover only, none strips everything', function () {
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize(motion_group('hover-lift reveal-up'), 'minimal', $budget);
    assert_contains('"className":"hover-lift"', $result['markup']);

    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize(motion_group('hover-lift reveal-up'), 'none', $budget);
    assert_true(!str_contains($result['markup'], 'className'), 'empty className removed entirely');
    assert_contains('class="wp-block-group"', $result['markup']);
});

test('motion-sanity enforces the one-ambient-per-page budget across files', function () {
    $budget = MotionSanityStep::newBudget();
    $first = MotionSanityStep::sanitize(motion_group('ken-burns'), 'dramatic', $budget);
    assert_contains('ken-burns', $first['markup'], 'first ambient effect keeps its slot');

    $second = MotionSanityStep::sanitize(motion_group('gradient-shift'), 'dramatic', $budget);
    assert_true(!str_contains($second['markup'], 'gradient-shift'), 'second ambient effect stripped');
    assert_contains('ambient budget', $second['notes'][0]);
});

test('motion-sanity caps hero-entrance at once per page', function () {
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize(
        motion_group('hero-entrance') . "\n" . motion_group('hero-entrance'),
        'calm',
        $budget
    );
    assert_eq(2, substr_count($result['markup'], 'hero-entrance'), 'one survives — once in attrs, once in html');
});

test('motion-sanity drops stagger-children on containers without at least two children', function () {
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize(motion_group('stagger-children'), 'calm', $budget);
    assert_true(!str_contains($result['markup'], 'stagger-children'), 'single-child container stripped');

    $columns = '<!-- wp:columns {"className":"stagger-children"} -->'
        . '<div class="wp-block-columns stagger-children">'
        . '<!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column -->'
        . '<!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->';
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize($columns, 'calm', $budget);
    assert_eq($columns, $result['markup'], 'two-column container keeps its stagger');
});

test('motion-sanity step visits sections in plan order so the hero wins page budgets', function () {
    $tmp = sys_get_temp_dir() . '/builder_motion_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('designDirection.json', ['description' => 'x', 'motion' => 'dramatic']);
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
        ['slug' => 'about', 'title' => 'About', 'type' => 'content'],
    ]]);
    // 'about' globs before 'hero' alphabetically — plan order must win.
    $project->writeText('theme/parts/section-about.html', motion_group('gradient-shift') . "\n");
    $project->writeText('theme/parts/section-hero.html', motion_group('ken-burns') . "\n");

    ob_start();
    (new MotionSanityStep())->run($project);
    ob_end_clean();

    assert_contains('ken-burns', $project->readText('theme/parts/section-hero.html'));
    assert_true(!str_contains($project->readText('theme/parts/section-about.html'), 'gradient-shift'));
    assert_contains('section-about', $project->readText('logs/motion-sanity.txt'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('motion vocabulary lists agree between kit, prompt, and page-styles contracts', function () {
    $sectionPrompt = file_get_contents(repo_path('prompts/section.md'));
    $kitCss = file_get_contents(repo_path('assets/motion/motion.css'));
    foreach (Motion::kitClasses() as $class) {
        assert_contains("`{$class}`", $sectionPrompt, "{$class} documented for sections");
        assert_contains(".{$class}", $kitCss, "{$class} implemented by the kit");
    }
});
