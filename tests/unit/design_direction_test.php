<?php
declare(strict_types=1);

/** @return array{0:Project,1:FakeLlm,2:string} */
function make_designdir_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb', 'visual_vibe' => 'warm and rustic']);
    return [$project, new FakeLlm(), $tmp];
}

/** One "=== DIRECTION ===" block in the sentinel response format. */
function direction_block(string $title, string $description, string $grade = ''): string
{
    return "=== DIRECTION ===\n"
        . "TITLE: {$title}\n"
        . "IMAGE_GRADE: {$grade}\n"
        . "DESCRIPTION:\n{$description}\n";
}

test('design-direction picks one of the four directions and writes it to designDirection.md', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueText(
        direction_block('Hearth & Grain', 'Editorial-magazine warmth, 1970s print feel.')
        . direction_block('Flour & Steel', 'Industrial-utilitarian bakery, raw concrete tones.')
        . direction_block('Sugar Bloom', 'Playful-pop pastels with oversized display type.')
        . direction_block('Midnight Levain', 'Dark-luxe patisserie, gold on near-black.')
    );

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('designDirection.md'), 'designDirection.md written');
    $written = $project->readText('designDirection.md');
    // Exactly one of the four directions is persisted (title heading + description).
    $titles = ['Hearth & Grain', 'Flour & Steel', 'Sugar Bloom', 'Midnight Levain'];
    $matched = array_values(array_filter($titles, fn ($t) => str_contains($written, $t)));
    assert_true(count($matched) === 1, 'exactly one direction is chosen');
    assert_contains('# ', $written); // the title is rendered as a heading

    // The rendered prompt must carry the user's words AND the factual spec.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction persists the chosen direction\'s image grade', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    // Same grade on all four so the random choice doesn't matter to the assertion.
    $grade = 'warm kodachrome color, soft golden light, shallow depth of field';
    $llm->queueText(implode('', array_map(
        fn (int $i) => direction_block("Direction {$i}", "Vision {$i}.", $grade),
        range(1, 4),
    )));

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('imageGrade.txt'), 'imageGrade.txt written');
    assert_eq($grade, DesignDirectionStep::imageGradeFor($project));

    // The prompt asks the model for the grade field.
    assert_contains('IMAGE_GRADE', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction tolerates a direction without an image grade', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    // A stale grade from a previous run must not survive a re-run whose chosen
    // direction carries no grade.
    $project->writeText('imageGrade.txt', "stale grade from an earlier run\n");
    $llm->queueText("=== DIRECTION ===\nTITLE: No Grade\nDESCRIPTION:\nA direction with no IMAGE_GRADE line.\n");

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_eq('', DesignDirectionStep::imageGradeFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction throws when the model returns no usable directions', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueText(direction_block('Empty', '   '));
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new DesignDirectionStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction throws when meta prompt missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => '']);
    $project->writeJson('siteSpec.json', ['name' => 'X']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($renderer, $project) {
        (new DesignDirectionStep(new FakeLlm(), $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('parseDirections survives quotes and multi-paragraph prose', function () {
    // Exactly the output that used to break json_decode: quotes, apostrophes,
    // em dashes, and multiple paragraphs inside the free prose.
    $prose = "A hushed, gallery-white space with the small \"editorial inquiries\" prompt "
        . "in the corner — the visitor's eye lands on a single monochrome photograph.\n\n"
        . "A second paragraph: GT Sectra for headlines, \"knife-cut\" and journalistic.";
    $directions = DesignDirectionStep::parseDirections(
        direction_block('Archivo Silencioso', $prose, 'monochrome documentary, 35mm grain')
        . direction_block('Tinta y Papel', 'Newsprint warmth.')
    );

    assert_eq(2, count($directions));
    assert_eq('Archivo Silencioso', $directions[0]['title']);
    assert_eq($prose, $directions[0]['description']);
    assert_eq('monochrome documentary, 35mm grain', $directions[0]['image_grade']);
});

test('parseDirections ignores preamble, tolerates loose markers, drops empty blocks', function () {
    $text = "Here are the four directions:\n"          // stray preamble — ignored
        . "===  DIRECTION  ===\n"                       // loose spacing in the marker
        . "TITLE: Kept\n"
        . "IMAGE_GRADE: soft golden light\n"
        . "DESCRIPTION: Same-line description text.\n"  // description on the marker line
        . "=== DIRECTION ===\n"
        . "TITLE: No Description\n"
        . "IMAGE_GRADE: dropped\n";

    $directions = DesignDirectionStep::parseDirections($text);
    assert_eq(1, count($directions));
    assert_eq('Kept', $directions[0]['title']);
    assert_eq('Same-line description text.', $directions[0]['description']);
});

test('parseDirections returns nothing for markerless text', function () {
    assert_eq([], DesignDirectionStep::parseDirections("Just prose, no markers at all.\n"));
});

test('readFor returns the brief when present, fallback when absent', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    // Absent → a non-empty fallback that still carries the "avoid defaults" intent.
    $fallback = DesignDirectionStep::readFor($project);
    assert_true(trim($fallback) !== '', 'fallback is non-empty');
    assert_contains('avoid', strtolower($fallback));

    // Present → the verbatim brief is injected.
    $project->writeText('designDirection.md', "Dark-luxe direction with gold accents.\n");
    assert_contains('Dark-luxe', DesignDirectionStep::readFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json injects the design direction into its prompt', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeText('designDirection.md', "Editorial-magazine direction.\n");

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer))->run($project);

    assert_contains('Editorial-magazine', $llm->calls[0]['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});
