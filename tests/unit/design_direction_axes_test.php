<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

test('the chosen seed carries its two traditions into the expansion prompt', function () {
    // These labels used to stop at the dedup check: a seed the model labelled
    // `noir`/`didone` reached the expansion as a sentence and nothing more, so
    // the expansion re-decided the world from prose. If this assertion can be
    // deleted without a failure, the vocabularies are bookkeeping again.
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => [
        ['seed' => 'Ink & Brass — a deep blue reading room.', 'ground' => 'dark',
            'register' => 'noir', 'accent' => 'jewel', 'tint' => 'cool', 'type_register' => 'didone'],
    ]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    // calls[0] is the seed round; calls[1] is the expansion.
    $expansion = $llm->calls[1]['prompt'];
    assert_contains('noir', $expansion, 'the design tradition reaches the expansion');
    assert_contains('didone', $expansion, 'so does the letterform tradition');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a seed that named no tradition asks the expansion to choose, not to pretend', function () {
    // The same degradation the ground already uses. A blank must never render
    // as an empty commitment the expansion reads as "none".
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => [
        ['seed' => 'Ink & Brass — a deep blue reading room.', 'ground' => 'dark', 'accent' => 'jewel'],
    ]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $expansion = $llm->calls[1]['prompt'];
    assert_contains('not committed by the seed', $expansion);
    assert_contains('read the letterform tradition off the seed sentence', $expansion);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the expansion prompt names the five reflex fonts without banning them', function () {
    // Naming them is the whole mechanism — the audited collapse was five
    // families setting over half of all sites, and none of them are on the
    // banned list. A ban would be wrong: each is right for SOME concept.
    $prompt = (string) file_get_contents(repo_path('prompts/design-direction.md'));
    foreach (['Archivo', 'Archivo Black', 'Playfair Display', 'Cormorant Garamond', 'Fraunces'] as $reflex) {
        assert_contains($reflex, $prompt, "{$reflex} is named as a reflex");
    }
    assert_contains('Reflex fonts', $prompt);
    assert_true(
        !str_contains($prompt, 'never mention Archivo'),
        'the reflex fonts are discouraged, not forbidden',
    );
});

test('normalize reads the page rhythm and density the direction commits to', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        [
            'description' => 'x',
            'palette'     => ['base' => '#F4EBDA'],
            'rhythm'      => ' Interrupted ',
            'density'     => 'DENSE',
        ],
        'cinematic-safe-zone',
        '',
        $repairs,
        $warnings,
    );
    assert_eq('interrupted', $direction['rhythm'], 'rhythm is case and space insensitive');
    assert_eq('dense', $direction['density']);

    foreach (DesignDirectionStep::RHYTHMS as $rhythm) {
        $read = DesignDirectionStep::normalize(
            ['description' => 'x', 'palette' => ['base' => '#F4EBDA'], 'rhythm' => $rhythm],
            'cinematic-safe-zone',
        );
        assert_eq($rhythm, $read['rhythm'], "{$rhythm} is a first-class band rhythm");
    }
});

test('a direction that forgot the rhythm does not silently re-elect the uniform stack', function () {
    // The default is load-bearing. `stacked` describes what the planner already
    // does unprompted, so defaulting to it would mean an omitted field quietly
    // restores the exact monotony this commitment exists to break.
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'palette' => ['base' => '#F4EBDA']],
        'cinematic-safe-zone',
    );
    assert_eq('alternating', $direction['rhythm']);
    assert_eq('measured', $direction['density']);
    assert_true($direction['rhythm'] !== 'stacked', 'the fallback is never the do-nothing value');
});

test('an unsupported rhythm loses authored intent loudly', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'palette' => ['base' => '#F4EBDA'], 'rhythm' => 'swirly'],
        'cinematic-safe-zone',
        '',
        $repairs,
        $warnings,
    );
    assert_eq('alternating', $direction['rhythm']);
    assert_true($warnings !== [], 'a dropped commitment is durable-warning material');
});

test('format renders rhythm and density as executable facts the page plan can act on', function () {
    // page-plan.md receives the direction through format(). A bare keyword gets
    // re-interpreted; the executable clause is what the planner implements.
    $rendered = DesignDirectionStep::format([
        'description' => 'x',
        'palette'     => ['base' => '#1B2233'],
        'rhythm'      => 'interrupted',
        'density'     => 'dense',
    ]);
    assert_contains('**Rhythm**', $rendered);
    assert_contains('interrupted', $rendered);
    assert_contains('full-bleed', $rendered, 'the rhythm states what to actually do');
    assert_contains('**Density**', $rendered);
    assert_contains('compact', $rendered, 'the density names the section value it maps to');
    assert_contains('lg/xl/xxl section-padding ramp', $rendered, 'the density names its build-owned execution');

    $bare = DesignDirectionStep::format(['description' => 'x']);
    assert_true(!str_contains($bare, 'Rhythm'), 'an uncommitted rhythm states nothing');
    assert_true(!str_contains($bare, 'Density'), 'an uncommitted density states nothing');
});

test('densityFor reads only a valid persisted density and otherwise stays measured', function () {
    $tmp = sys_get_temp_dir() . '/builder_density_for_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq('measured', DesignDirectionStep::densityFor($project));
    $project->writeJson('designDirection.json', ['density' => ['airy']]);
    assert_eq('measured', DesignDirectionStep::densityFor($project));
    $project->writeJson('designDirection.json', ['density' => ' AIRY ']);
    assert_eq('airy', DesignDirectionStep::densityFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the page plan is told to act on the rhythm and density facts', function () {
    // format() emitting a fact nothing reads is a fact that does nothing.
    $plan = (string) file_get_contents(repo_path('prompts/page-plan.md'));
    assert_contains('**Rhythm**', $plan, 'the planner is pointed at the rhythm commitment');
    assert_contains('**Density**', $plan, 'and at the density commitment');
    foreach (DesignDirectionStep::RHYTHMS as $rhythm) {
        assert_contains('`' . $rhythm . '`', $plan, "the planner knows what {$rhythm} means");
    }
});
