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

test('motion-sanity evaluates a duplicated class once without deleting the survivor', function () {
    $budget = MotionSanityStep::newBudget();
    $markup = motion_group('reveal reveal');
    $result = MotionSanityStep::sanitize($markup, 'calm', $budget);

    assert_eq($markup, $result['markup'], 'a redundant valid token is harmless and not reconstructed away');
    assert_eq([], $result['notes']);
});

test('motion-sanity rejects same-block hover and ambient transform conflicts', function () {
    foreach ([
        ['hover-lift ambient-drift', 'ambient-drift', 'hover-lift'],
        ['ken-burns hover-reveal', 'ken-burns', 'hover-reveal'],
    ] as [$classes, $kept, $dropped]) {
        $budget = MotionSanityStep::newBudget();
        $result = MotionSanityStep::sanitize(motion_group($classes), 'calm', $budget);
        assert_contains('"className":"' . $kept . '"', $result['markup'], "{$kept} keeps the transform owner");
        assert_true(!str_contains($result['markup'], $dropped), "{$dropped} cannot silently lose its transform");
        assert_contains('both animate the same transform', $result['notes'][0]);
    }

    // A disallowed ambient class cannot suppress the hover behavior that the
    // minimal profile does permit.
    $budget = MotionSanityStep::newBudget();
    $minimal = MotionSanityStep::sanitize(motion_group('hover-lift ambient-drift'), 'minimal', $budget);
    assert_contains('"className":"hover-lift"', $minimal['markup']);

    $budget = MotionSanityStep::newBudget();
    $otherOwner = MotionSanityStep::sanitize(
        motion_group('reveal ambient-drift hover-lift'),
        'calm',
        $budget
    );
    assert_contains(
        '"className":"reveal hover-lift"',
        $otherOwner['markup'],
        'hover survives when the conflicting ambient class lost the one-class block budget'
    );

    $budget = MotionSanityStep::newBudget();
    MotionSanityStep::sanitize(motion_group('gradient-shift'), 'calm', $budget);
    $spentAmbient = MotionSanityStep::sanitize(motion_group('ken-burns hover-reveal'), 'calm', $budget);
    assert_contains(
        '"className":"hover-reveal"',
        $spentAmbient['markup'],
        'hover survives when the conflicting ambient class lost the page budget'
    );
});

test('motion-sanity strips unknown and runtime motion states but leaves other classes alone', function () {
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize(
        motion_group('equal-cards reveal-left motion-spin motion-target motion-skip hover-lift-slow is-visible custom-motion'),
        'calm',
        $budget
    );
    assert_contains('"className":"equal-cards custom-motion"', $result['markup']);
    assert_contains('class="wp-block-group equal-cards custom-motion"', $result['markup']);
    assert_eq(6, count($result['notes']));
});

test('motion-sanity evicts preset motion from the custom-motion target block', function () {
    // The registered static selector outranks .custom-motion, so a preset class
    // on the tagged block would override the user's explicit request.
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize(motion_group('custom-motion reveal-up hover-lift'), 'calm', $budget);
    assert_contains('"className":"custom-motion"', $result['markup']);
    assert_true(!str_contains($result['markup'], 'reveal-up'), 'preset entrance evicted');
    assert_true(!str_contains($result['markup'], 'hover-lift'), 'preset hover evicted');
    assert_eq(2, count($result['notes']));

    // The eviction doesn't consume the page budgets: another section's
    // ambient class still gets the one signature slot.
    $result = MotionSanityStep::sanitize(motion_group('custom-motion ken-burns'), 'dramatic', $budget);
    assert_true(!str_contains($result['markup'], 'ken-burns'), 'ambient evicted from the custom target');
    $other = MotionSanityStep::sanitize(motion_group('gradient-shift'), 'dramatic', $budget);
    assert_contains('gradient-shift', $other['markup'], 'ambient budget still free for the page');
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

test('motion-sanity gates classes living only in the saved HTML (fixCustomClassname would rescue them)', function () {
    // No className in the comment JSON at all — the class rides in HTML only.
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . "\n" . '<div class="wp-block-group hover-lift hover-reveal-fast reveal-up"><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div>'
        . "\n" . '<!-- /wp:group -->';
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize($markup, 'none', $budget);
    assert_true(!str_contains($result['markup'], 'hover-lift'), 'HTML-only hover class stripped under none');
    assert_true(!str_contains($result['markup'], 'hover-reveal-fast'), 'invented HTML-only hover variant stripped');
    assert_true(!str_contains($result['markup'], 'reveal-up'), 'HTML-only reveal class stripped under none');
    assert_eq(3, count($result['notes']));

    // Under a profile that allows it, an HTML-only ambient class still
    // consumes the page budget — the fixer will rescue it into className.
    $budget = MotionSanityStep::newBudget();
    $ambient = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . "\n" . '<div class="wp-block-group ken-burns"><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div>'
        . "\n" . '<!-- /wp:group -->';
    $first = MotionSanityStep::sanitize($ambient, 'dramatic', $budget);
    assert_contains('ken-burns', $first['markup'], 'allowed HTML-only class kept');
    $second = MotionSanityStep::sanitize(motion_group('gradient-shift'), 'dramatic', $budget);
    assert_true(!str_contains($second['markup'], 'gradient-shift'), 'HTML-only ambient consumed the budget');
});

test('motion-sanity removal survives tab/newline class separators', function () {
    $markup = '<!-- wp:group {"className":"reveal-up reveal-fade","layout":{"type":"constrained"}} -->'
        . "\n" . "<div class=\"wp-block-group\treveal-up\nreveal-fade\"><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div>"
        . "\n" . '<!-- /wp:group -->';
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize($markup, 'calm', $budget);
    assert_contains('"className":"reveal-up"', $result['markup']);
    assert_true(!str_contains($result['markup'], 'reveal-fade'), 'dropped token removed despite odd separators');
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

test('motion-sanity caps entrances at two per section and resets for the next section', function () {
    $budget = MotionSanityStep::newBudget();
    $result = MotionSanityStep::sanitize(
        motion_group('reveal') . "\n"
            . motion_group('reveal-up') . "\n"
            . motion_group('reveal-fade'),
        'calm',
        $budget
    );

    assert_contains('"className":"reveal"', $result['markup'], 'first entrance kept');
    assert_contains('"className":"reveal-up"', $result['markup'], 'second entrance kept');
    assert_true(!str_contains($result['markup'], 'reveal-fade'), 'third entrance stripped from the section');
    assert_contains('section entrance budget', $result['notes'][0]);

    $nextSection = MotionSanityStep::sanitize(motion_group('reveal-fade'), 'calm', $budget);
    assert_contains('reveal-fade', $nextSection['markup'], 'entrance budget resets for each section part');
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
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
            ['slug' => 'about', 'title' => 'About', 'type' => 'content'],
        ]],
    ]]);
    // 'about' globs before 'hero' alphabetically — plan order must win.
    $project->writeText(
        'theme/parts/page-home--about.html',
        motion_group('gradient-shift') . "\n" . motion_group('hero-entrance') . "\n"
    );
    $project->writeText('theme/parts/page-home--hero.html', motion_group('ken-burns') . "\n");

    quietly(fn () => (new MotionSanityStep())->run($project));

    assert_contains('ken-burns', $project->readText('theme/parts/page-home--hero.html'));
    assert_true(!str_contains($project->readText('theme/parts/page-home--about.html'), 'gradient-shift'));
    assert_true(
        !str_contains($project->readText('theme/parts/page-home--about.html'), 'hero-entrance'),
        'a later section cannot claim hero-entrance when the hero omitted it'
    );
    assert_contains('page-home--about', $project->readText('logs/motion-sanity.txt'));

    // Every strip removed an authored class from delivered markup: durable.
    $warnings = $project->readJson('warnings.json')['motion-sanity'] ?? [];
    assert_true($warnings !== [], 'strips are recorded in warnings.json');
    assert_contains('motion class stripped', $warnings[0]);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('motion vocabulary agrees between the static kit and its isolated authoring prompts', function () {
    $sectionPrompt = file_get_contents(repo_path('prompts/section.md'));
    $heroPrompt = file_get_contents(repo_path('prompts/hero.md'));
    $kitCss = file_get_contents(repo_path('assets/motion/motion.css'));
    foreach (Motion::kitClasses() as $class) {
        $authoringPrompt = in_array($class, \Automattic\SiteBuild\Motion::HERO_CLASSES, true) ? $heroPrompt : $sectionPrompt;
        assert_contains("`{$class}`", $authoringPrompt, "{$class} documented at its authoring boundary");
        assert_contains(".{$class}", $kitCss, "{$class} implemented by the kit");
    }
    foreach (['`calm`:', '`energetic`:', '`dramatic`:'] as $profileGuidance) {
        assert_contains($profileGuidance, $sectionPrompt, "{$profileGuidance} choreography is explicit");
    }
    assert_contains(
        'Do NOT automatically pair `hero-entrance` with `ken-burns`',
        $heroPrompt,
        'dedicated hero prompt rejects the repeated motion pairing seen in live builds'
    );
    assert_contains(
        '`ambient-drift` + `hover-lift`',
        $sectionPrompt,
        'prompt documents the transform conflict enforced by motion-sanity'
    );
    assert_contains('keeps only the first two', $sectionPrompt, 'prompt states the enforced section entrance cap');
});

test('motion-sanity treats word-reveal as a hero-only entrance with its own once-per-page budget (frm W8a)', function () {
    $budget = MotionSanityStep::newBudget();
    $both = MotionSanityStep::sanitize(
        motion_group('hero-entrance', '<!-- wp:heading {"level":1,"className":"word-reveal"} --><h1 class="wp-block-heading word-reveal">Frames that hold</h1><!-- /wp:heading -->'),
        'calm',
        $budget,
    );
    assert_eq(2, substr_count($both['markup'], 'word-reveal'), 'the headline keeps its word-reveal beside the copy group entrance');
    assert_eq(2, substr_count($both['markup'], 'hero-entrance'));

    $twiceBudget = MotionSanityStep::newBudget();
    $twice = MotionSanityStep::sanitize(
        motion_group('word-reveal') . "\n" . motion_group('word-reveal'),
        'calm',
        $twiceBudget,
    );
    assert_eq(2, substr_count($twice['markup'], 'word-reveal'), 'once per page — once in attrs, once in html');

    $laterBudget = MotionSanityStep::newBudget();
    $later = MotionSanityStep::sanitize(motion_group('word-reveal'), 'calm', $laterBudget, false);
    assert_true(!str_contains($later['markup'], 'word-reveal'), 'a later section cannot claim the headline reveal');
    assert_contains('word-reveal is allowed only in the first section', implode(' ', $later['notes'] ?? []) . implode(' ', $later['warnings'] ?? []));
});
