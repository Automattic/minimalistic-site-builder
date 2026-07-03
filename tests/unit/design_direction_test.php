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

/** Four structured candidates whose titles identify them. @return array<int,array<string,mixed>> */
function designdir_candidates(): array
{
    $palettes = ['#FDF6EC', '#1B1D22', '#F2F7F5', '#221A16'];
    return array_map(fn (int $i) => [
        'title'            => "Direction {$i}",
        'description'      => "Vision {$i}: a fully described concept.",
        'palette'          => [
            'base' => $palettes[$i - 1], 'contrast' => '#26221E', 'primary' => '#8A5A2B',
            'secondary' => '#CC9988', 'accent' => '#E08A3C',
        ],
        'type'             => ['heading' => "Font {$i} 700", 'body' => 'Source Sans 3 400/600'],
        'image_grade'      => "grade {$i}",
        'signature_device' => "device {$i}",
        'hero_composition' => "hero composition {$i}",
    ], range(1, 4));
}

test('design-direction persists the judge-picked candidate as structured designDirection.json', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['directions' => designdir_candidates()]);
    $llm->queueJson(['choice' => 3, 'reason' => 'best fit']); // judge verdict

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('designDirection.json'), 'designDirection.json written');
    $written = $project->readJson('designDirection.json');
    assert_eq('Direction 3', $written['title']);
    assert_eq('#F2F7F5', $written['palette']['base']);
    assert_eq('Font 3 700', $written['type']['heading']);
    assert_eq('grade 3', $written['image_grade']);
    assert_eq('device 3', $written['signature_device']);
    assert_eq('hero composition 3', $written['hero_composition']);

    // The generation prompt carries the user's words, the factual spec, and
    // asks for every structured field.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);
    foreach (['palette', 'type', 'image_grade', 'signature_device', 'hero_composition'] as $field) {
        assert_contains($field, $llm->calls[0]['prompt']);
    }

    // The judge saw the brief and all four candidates.
    assert_contains('cozy neighborhood bakery', $llm->calls[1]['prompt']);
    assert_contains('Candidate 4', $llm->calls[1]['prompt']);
    assert_contains('Font 1 700', $llm->calls[1]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction sends the judge call to the configured judge model', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['directions' => designdir_candidates()]);
    $llm->queueJson(['choice' => 1, 'reason' => 'best fit']);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer, 'claude-opus-4-8', 1.0, 'claude-haiku-4-5'))->run($project);

    assert_eq('claude-opus-4-8', $llm->calls[0]['opts']['model'] ?? null, 'generation uses the step model');
    assert_eq('claude-haiku-4-5', $llm->calls[1]['opts']['model'] ?? null, 'judge uses the judge model');
    assert_true(!array_key_exists('temperature', $llm->calls[1]['opts']), 'judge keeps default sampling');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction falls back to a random pick when the judge fails', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    // Only the directions are queued: the judge call throws (FakeLlm queue
    // empty), which must NOT abort the build.
    $llm->queueJson(['directions' => designdir_candidates()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    $written = $project->readJson('designDirection.json');
    assert_contains('Direction ', (string) $written['title']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction falls back to a random pick on an out-of-range judge verdict', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['directions' => designdir_candidates()]);
    $llm->queueJson(['choice' => 9, 'reason' => 'nonsense']);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('designDirection.json'), 'a direction is still committed');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('DESIGN_DIRECTION_CHOICE forces candidate N and skips the judge', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['directions' => designdir_candidates()]);

    putenv('DESIGN_DIRECTION_CHOICE=2');
    try {
        $renderer = new PromptRenderer(repo_path('prompts'));
        (new DesignDirectionStep($llm, $renderer))->run($project);
    } finally {
        putenv('DESIGN_DIRECTION_CHOICE');
    }

    assert_eq('Direction 2', $project->readJson('designDirection.json')['title']);
    assert_eq(1, count($llm->calls), 'no judge call when the choice is forced');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('DESIGN_DIRECTION_CHOICE out of range fails loud', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['directions' => designdir_candidates()]);

    putenv('DESIGN_DIRECTION_CHOICE=7');
    try {
        $renderer = new PromptRenderer(repo_path('prompts'));
        assert_throws(function () use ($llm, $renderer, $project) {
            (new DesignDirectionStep($llm, $renderer))->run($project);
        });
    } finally {
        putenv('DESIGN_DIRECTION_CHOICE');
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction does not read or write cross-build history', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    file_put_contents($tmp . '/.direction-history.json', json_encode([
        ['title' => 'Forbidden Previous Direction'],
    ]));
    $previousCandidates = designdir_candidates();
    $previousCandidates[0]['title'] = 'Previous Build Marker';
    $llm->queueJson(['directions' => $previousCandidates]);
    $llm->queueJson(['choice' => 1, 'reason' => 'fit']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_eq(
        '[{"title":"Forbidden Previous Direction"}]',
        (string) file_get_contents($tmp . '/.direction-history.json'),
        'legacy history files are ignored and left untouched'
    );
    assert_true(
        !str_contains($llm->calls[0]['prompt'], 'Forbidden Previous Direction'),
        'generation prompt ignores previous directions'
    );
    assert_true(
        !str_contains($llm->calls[1]['prompt'], 'Forbidden Previous Direction'),
        'judge prompt ignores previous directions'
    );

    $second = (new ProjectStore($tmp))->create('demo-two');
    $second->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $second->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb']);
    $llm2 = new FakeLlm();
    $llm2->queueJson(['directions' => designdir_candidates()]);
    $llm2->queueJson(['choice' => 2, 'reason' => 'stronger concept']);
    (new DesignDirectionStep($llm2, $renderer))->run($second);

    assert_true(
        !str_contains($llm2->calls[0]['prompt'], 'Previous Build Marker'),
        'generation prompt does not list the previous build choice'
    );
    assert_true(
        !str_contains($llm2->calls[1]['prompt'], 'Previous Build Marker'),
        'judge prompt does not list the previous build choice'
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('normalize keeps valid palette hexes, drops invalid ones, and requires a description', function () {
    $direction = DesignDirectionStep::normalize([
        'title'       => ' Forge & Flame ',
        'description' => 'A vivid vision.',
        'palette'     => [
            'base'      => '#fdf6ec',   // lowercase → normalized
            'contrast'  => 'ink black', // not a hex → dropped
            'primary'   => '#8A5A2B',
            'weird'     => '#FFFFFF',   // unknown role → dropped
        ],
        'type'        => ['heading' => 'Fraunces 900'],
    ]);
    assert_eq('Forge & Flame', $direction['title']);
    assert_eq(['base' => '#FDF6EC', 'primary' => '#8A5A2B'], $direction['palette']);
    assert_eq('Fraunces 900', $direction['type']['heading']);
    assert_eq('', $direction['type']['body']);
    assert_eq('', $direction['image_grade']);

    assert_eq(null, DesignDirectionStep::normalize(['title' => 'Empty', 'description' => '   ']));
    assert_eq(null, DesignDirectionStep::normalize('not an array'));
});

test('format renders the narrative plus the structured fact list', function () {
    $text = DesignDirectionStep::format([
        'title'            => 'Archivo Silencioso',
        'description'      => 'Full-bleed black-and-white photography.',
        'palette'          => ['base' => '#F4F1EA', 'accent' => '#C33F2E'],
        'type'             => ['heading' => 'Fraunces 900', 'body' => 'Source Sans 3 400'],
        'image_grade'      => 'monochrome documentary',
        'signature_device' => 'hairline rules with folios',
        'hero_composition' => 'headline pinned lower-left',
    ]);
    assert_contains('# Archivo Silencioso', $text);
    assert_contains('base #F4F1EA', $text);
    assert_contains('heading — Fraunces 900; body — Source Sans 3 400', $text);
    assert_contains('Signature device', $text);
    assert_contains('hero composition', strtolower($text));
    assert_contains('monochrome documentary', $text);

    // Empty fields are omitted — a bare direction is just the narrative.
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.']));
});

test('design-direction throws when the model returns no usable directions', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['directions' => [['title' => 'Empty', 'description' => '   ']]]);
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

test('readFor returns the rendered direction when present, fallback when absent', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    // Absent → a non-empty fallback that still carries the "avoid defaults" intent.
    $fallback = DesignDirectionStep::readFor($project);
    assert_true(trim($fallback) !== '', 'fallback is non-empty');
    assert_contains('avoid', strtolower($fallback));

    // Present → the rendered brief, structured fields included.
    $project->writeJson('designDirection.json', [
        'title'       => 'Midnight Levain',
        'description' => 'Dark-luxe direction with gold accents.',
        'palette'     => ['base' => '#14100C'],
    ]);
    $brief = DesignDirectionStep::readFor($project);
    assert_contains('Dark-luxe', $brief);
    assert_contains('base #14100C', $brief);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('imageGradeFor reads the grade from designDirection.json, empty when absent', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq('', DesignDirectionStep::imageGradeFor($project));

    $project->writeJson('designDirection.json', [
        'description' => 'A direction.',
        'image_grade' => 'warm kodachrome color, soft golden light',
    ]);
    assert_eq('warm kodachrome color, soft golden light', DesignDirectionStep::imageGradeFor($project));

    // A direction without a grade reads as '' (image prompts get no grade clause).
    $project->writeJson('designDirection.json', ['description' => 'No grade.']);
    assert_eq('', DesignDirectionStep::imageGradeFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json injects the design direction into its prompt', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('designDirection.json', [
        'title'       => 'Hearth & Grain',
        'description' => 'Editorial-magazine direction.',
        'palette'     => ['base' => '#FDF6EC'],
        'type'        => ['heading' => 'Fraunces 900', 'body' => 'Source Sans 3 400'],
    ]);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer))->run($project);

    assert_contains('Editorial-magazine', $llm->calls[0]['prompt']);
    assert_contains('base #FDF6EC', $llm->calls[0]['prompt'], 'structured palette reaches the theme prompt');
    assert_contains('Fraunces 900', $llm->calls[0]['prompt'], 'structured type reaches the theme prompt');
    exec('rm -rf ' . escapeshellarg($tmp));
});
