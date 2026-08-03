<?php
declare(strict_types=1);

use Automattic\SiteBuild\JsonBatchRecovery;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array{0:Project,1:FakeLlm,2:string} */
function make_designdir_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb', 'visual_vibe' => 'warm and rustic']);
    return [$project, new FakeLlm(), $tmp];
}

/** Four concept titles that identify themselves. @return array<int,string> */
function designdir_seeds(): array
{
    return array_map(fn (int $i) => "Seed {$i}", range(1, 4));
}

/** One full direction as the expansion call returns it. @return array<string,mixed> */
function designdir_direction(): array
{
    return [
        'title'            => 'Hearth & Grain',
        'description'      => 'Editorial-magazine warmth: a fully described concept.',
        'palette'          => [
            'base' => '#FDF6EC', 'contrast' => '#26221E', 'primary' => '#8A5A2B',
            'secondary' => '#CC9988', 'accent' => '#E08A3C',
        ],
        'type'             => ['heading' => 'Fraunces 700/900', 'body' => 'Source Sans 3 400/600'],
        'image_grade'      => 'warm kodachrome color, soft golden light',
        'signature_device' => 'hairline rules with small caps folios',
        'signature_device_slots' => ['hero', 'body'],
        'hero_blueprint'   => HeroBlueprint::defaultFor('editorial-split'),
    ];
}

test('design-direction expands a picked seed into structured designDirection.json', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('designDirection.json'), 'designDirection.json written');
    $written = $project->readJson('designDirection.json');
    assert_eq('Hearth & Grain', $written['title']);
    assert_eq('#FDF6EC', $written['palette']['base']);
    assert_eq('Fraunces 700/900', $written['type']['heading']);
    assert_eq('warm kodachrome color, soft golden light', $written['image_grade']);
    assert_eq('hairline rules with small caps folios', $written['signature_device']);
    assert_eq(['hero', 'body'], $written['signature_device_slots']);
    assert_true(in_array($written['hero_blueprint']['recipe'], HeroComposition::RECIPES, true));
    assert_contains('Seed ', $written['concept_seed']);
    assert_true(!array_key_exists('hero_composition', $written), 'old prose field is gone');

    // The seed prompt carries the user's words and the factual spec.
    assert_eq(2, count($llm->calls), 'exactly two calls: seeds + expansion');
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);
    assert_contains('title', $llm->calls[0]['prompt']);

    // The expansion prompt carries the brief, the spec, ONE of the seeds
    // (random pick), and asks for every structured field.
    assert_contains('cozy neighborhood bakery', $llm->calls[1]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[1]['prompt']);
    assert_contains('Seed ', $llm->calls[1]['prompt'], 'a seed reached the expansion prompt');
    foreach (['palette', 'type', 'image_grade', 'signature_device', 'signature_device_slots', 'hero_blueprint'] as $field) {
        assert_contains($field, $llm->calls[1]['prompt']);
    }
    $assigned = $written['hero_blueprint']['recipe'];
    assert_contains($assigned, $llm->calls[1]['prompt']);
    foreach (HeroComposition::RECIPES as $other) {
        if ($other !== $assigned) {
            assert_true(!str_contains($llm->calls[1]['prompt'], $other), "{$other} recipe does not leak");
        }
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction sends the seed call to the seed model and keeps the hot temperature on both calls', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer, 'claude-opus-4-8', 1.0, 'claude-haiku-4-5'))->run($project);

    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null, 'seeds use the seed model');
    assert_eq(1.0, $llm->calls[0]['opts']['temperature'] ?? null, 'seed spread runs hot');
    assert_eq('claude-opus-4-8', $llm->calls[1]['opts']['model'] ?? null, 'expansion uses the step model');
    assert_eq(1.0, $llm->calls[1]['opts']['temperature'] ?? null, 'expansion keeps the step temperature');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction falls back to a built-in seed when the seed call fails', function () {
    [$project, $tmp] = (function () {
        $f = make_designdir_fixture();
        return [$f[0], $f[2]];
    })();

    // First completeJson (seeds) throws; second (expansion) succeeds.
    $llm = new class implements Llm {
        /** @var array<int,array{prompt:string,opts:array<mixed>}> */
        public array $calls = [];
        private bool $first = true;

        public function complete(string $prompt, array $opts = []): string
        {
            throw new RuntimeException('unused');
        }

        public function completeJson(string $prompt, array $opts = []): array
        {
            $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
            if ($this->first) {
                $this->first = false;
                throw new RuntimeException('seed transport error');
            }
            return ['direction' => designdir_direction()];
        }

        public function completeJsonBatch(array $requests): array
        {
            throw new RuntimeException('unused');
        }

        public function completeBatch(array $requests): \Automattic\SiteBuild\TextBatchResult
        {
            throw new RuntimeException('unused');
        }
    };

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('designDirection.json'), 'a direction is still committed');
    assert_contains('(No concept seed was chosen', $llm->calls[1]['prompt'], 'expansion got the fallback seed');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction falls back to a built-in seed when no seed is usable', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => ['   ', 123, ['angle' => 'no title']]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_contains('(No concept seed was chosen', $llm->calls[1]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('DESIGN_DIRECTION_CHOICE forces seed N', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => designdir_direction()]);

    putenv('DESIGN_DIRECTION_CHOICE=2');
    try {
        $renderer = new PromptRenderer(repo_path('prompts'));
        (new DesignDirectionStep($llm, $renderer))->run($project);
    } finally {
        putenv('DESIGN_DIRECTION_CHOICE');
    }

    assert_contains('Seed 2', $llm->calls[1]['prompt'], 'forced seed reaches the expansion prompt');
    assert_true(!str_contains($llm->calls[1]['prompt'], 'Seed 3'), 'other seeds do not leak into the prompt');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('DESIGN_DIRECTION_CHOICE out of range fails loud', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);

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

test('DESIGN_DIRECTION_CHOICE with a failed seed call fails loud (a forced eval must not drift)', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    // Nothing queued: the seed call throws, and with a forced choice that must
    // propagate instead of degrading to the fallback seed.

    putenv('DESIGN_DIRECTION_CHOICE=1');
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
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => designdir_direction()]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_eq(
        '[{"title":"Forbidden Previous Direction"}]',
        (string) file_get_contents($tmp . '/.direction-history.json'),
        'legacy history files are ignored and left untouched'
    );
    assert_true(
        !str_contains($llm->calls[0]['prompt'], 'Forbidden Previous Direction'),
        'seed prompt ignores previous directions'
    );
    assert_true(
        !str_contains($llm->calls[1]['prompt'], 'Forbidden Previous Direction'),
        'expansion prompt ignores previous directions'
    );

    $second = (new ProjectStore($tmp))->create('demo-two');
    $second->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $second->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb']);
    $llm2 = new FakeLlm();
    $llm2->queueJson(['seeds' => designdir_seeds()]);
    $llm2->queueJson(['direction' => designdir_direction()]);
    (new DesignDirectionStep($llm2, $renderer))->run($second);

    assert_true(
        !str_contains($llm2->calls[0]['prompt'], 'Hearth & Grain'),
        'seed prompt does not list the previous build choice'
    );
    assert_true(
        !str_contains($llm2->calls[1]['prompt'], 'Forbidden Previous Direction'),
        'expansion prompt does not list the previous build choice'
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('normalizeSeed accepts titles as strings or title-keyed objects, rejects the rest', function () {
    assert_eq('Forge & Flame', DesignDirectionStep::normalizeSeed(' Forge & Flame '));
    assert_eq('Forge & Flame', DesignDirectionStep::normalizeSeed(['title' => ' Forge & Flame ']));
    assert_eq(null, DesignDirectionStep::normalizeSeed('   '));
    assert_eq(null, DesignDirectionStep::normalizeSeed(['title' => '   ']));
    assert_eq(null, DesignDirectionStep::normalizeSeed(['angle' => 'no title']));
    assert_eq(null, DesignDirectionStep::normalizeSeed(123));
    assert_eq(null, DesignDirectionStep::normalizeSeed(null));
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
    ], 'cinematic-safe-zone');
    assert_eq('Forge & Flame', $direction['title']);
    assert_eq(['base' => '#FDF6EC', 'primary' => '#8A5A2B'], $direction['palette']);
    assert_eq('Fraunces 900', $direction['type']['heading']);
    assert_eq('', $direction['type']['body']);
    assert_eq('', $direction['image_grade']);

    assert_eq(null, DesignDirectionStep::normalize(['title' => 'Empty', 'description' => '   '], 'cinematic-safe-zone'));
    assert_eq(null, DesignDirectionStep::normalize('not an array', 'cinematic-safe-zone'));
});

test('format renders the narrative plus the structured fact list', function () {
    $text = DesignDirectionStep::format([
        'title'            => 'Archivo Silencioso',
        'description'      => 'Full-bleed black-and-white photography.',
        'palette'          => ['base' => '#F4F1EA', 'accent' => '#C33F2E'],
        'type'             => ['heading' => 'Fraunces 900', 'body' => 'Source Sans 3 400'],
        'image_grade'      => 'monochrome documentary',
        'signature_device' => 'hairline rules with folios',
        'signature_device_slots' => ['hero'],
        'concept_seed' => 'must stay hidden',
        'hero_blueprint' => HeroBlueprint::defaultFor('editorial-split'),
    ]);
    assert_contains('# Archivo Silencioso', $text);
    assert_contains('base #F4F1EA', $text);
    assert_contains('heading — Fraunces 900; body — Source Sans 3 400', $text);
    assert_contains('Signature device', $text);
    assert_contains('placement slots', strtolower($text));
    assert_contains('monochrome documentary', $text);
    assert_true(!str_contains($text, 'editorial-split'), 'general format excludes hero recipe');
    assert_true(!str_contains($text, 'must stay hidden'), 'general format excludes concept seed');

    // Empty fields are omitted — a bare direction is just the narrative.
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.']));
});

test('normalize commits a canvas: framed passes through, everything else is full-bleed', function () {
    assert_eq('framed', DesignDirectionStep::normalize(['description' => 'x', 'canvas' => ' Framed '], 'cinematic-safe-zone')['canvas']);
    assert_eq('full-bleed', DesignDirectionStep::normalize(['description' => 'x'], 'cinematic-safe-zone')['canvas']);
    assert_eq('full-bleed', DesignDirectionStep::normalize(['description' => 'x', 'canvas' => 'poster'], 'cinematic-safe-zone')['canvas']);
});

test('format renders the canvas commitment with its executable meaning', function () {
    $framed = DesignDirectionStep::format(['description' => 'x', 'canvas' => 'framed']);
    assert_contains('**Canvas**: framed', $framed);
    assert_contains('"align":"wide"', $framed);

    $full = DesignDirectionStep::format(['description' => 'x', 'canvas' => 'full-bleed']);
    assert_contains('**Canvas**: full-bleed', $full);
    assert_contains('"align":"full"', $full);

    // Directions persisted before the field existed carry no canvas fact.
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.']));
});

test('design-direction delivers the deterministic fallback when the model returns no usable direction', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => ['title' => 'Empty', 'description' => '   ']]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer))->run($project);

    // The build continues on the fallback direction (built on the chosen
    // seed), with the concept-variety loss recorded durably.
    $direction = $project->readJson('designDirection.json');
    assert_true(trim((string) $direction['description']) !== '', 'fallback carries a usable narrative');
    assert_eq('full-bleed', $direction['canvas']);
    $joined = implode(' ', $project->readJson('warnings.json')['design-direction'] ?? []);
    assert_contains('no usable design direction', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction delivers its deterministic fallback when repaired expansion JSON is still malformed', function () {
    [$project, , $tmp] = make_designdir_fixture();
    $llm = new class implements Llm {
        public int $rounds = 0;
        private int $jsonCalls = 0;

        public function complete(string $prompt, array $opts = []): string
        {
            throw new RuntimeException('unused');
        }

        public function completeJson(string $prompt, array $opts = []): array
        {
            $this->jsonCalls++;
            if ($this->jsonCalls === 1) {
                return ['seeds' => designdir_seeds()];
            }
            return JsonBatchRecovery::run(
                ['request' => ['prompt' => $prompt] + $opts],
                function (array $subset): array {
                    $this->rounds++;
                    return ['request' => ['text' => '{"direction":{]']];
                },
            )['request'];
        }

        public function completeJsonBatch(array $requests): array
        {
            throw new RuntimeException('unused');
        }

        public function completeBatch(array $requests): \Automattic\SiteBuild\TextBatchResult
        {
            throw new RuntimeException('unused');
        }
    };

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $direction = $project->readJson('designDirection.json');
    assert_true(trim((string) $direction['description']) !== '');
    assert_eq(2, $llm->rounds, 'one malformed response and one malformed repair response');
    $joined = implode(' ', $project->readJson('warnings.json')['design-direction'] ?? []);
    assert_contains('generated JSON remained unusable', $joined);
    assert_contains('deterministic seed-derived direction delivered', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction keeps an operational expansion failure fatal', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    // No expansion response: FakeLlm throws a plain RuntimeException.

    assert_throws(fn () => (new DesignDirectionStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
    ))->run($project));

    assert_true(!$project->exists('designDirection.json'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('fallbackDirection builds on the chosen seed, generic brief otherwise', function () {
    $seeded = DesignDirectionStep::fallbackDirection(
        'Neon Dusk — electric gradients over charcoal.',
        'cinematic-safe-zone',
    );
    assert_eq('Neon Dusk — electric gradients over charcoal.', $seeded['description']);

    $generic = DesignDirectionStep::fallbackDirection('', 'cinematic-safe-zone');
    assert_contains('bold', $generic['description']);
    assert_eq('calm', $generic['motion']);
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
        'hero_blueprint' => \Automattic\SiteBuild\HeroBlueprint::defaultFor('cinematic-safe-zone'),
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

test('normalize commits a motion profile: valid values pass, anything else defaults', function () {
    assert_eq('dramatic', DesignDirectionStep::normalize(['description' => 'x', 'motion' => ' Dramatic '], 'cinematic-safe-zone')['motion']);
    assert_eq('none', DesignDirectionStep::normalize(['description' => 'x', 'motion' => 'none'], 'cinematic-safe-zone')['motion']);
    assert_eq('calm', DesignDirectionStep::normalize(['description' => 'x'], 'cinematic-safe-zone')['motion'], 'missing → default');
    assert_eq('calm', DesignDirectionStep::normalize(['description' => 'x', 'motion' => 'bouncy'], 'cinematic-safe-zone')['motion'], 'unknown → default');
    assert_eq('calm', DesignDirectionStep::normalize(['description' => 'x', 'motion' => ['calm']], 'cinematic-safe-zone')['motion'], 'non-string → default');
    assert_eq('a note', DesignDirectionStep::normalize(['description' => 'x', 'motion_note' => ' a note '], 'cinematic-safe-zone')['motion_note']);
});

test('format renders the motion commitment with its executable meaning', function () {
    $calm = DesignDirectionStep::format(['description' => 'x', 'motion' => 'calm', 'motion_note' => 'let the hero breathe']);
    assert_contains('**Motion**: calm', $calm);
    assert_contains('let the hero breathe', $calm);

    $minimal = DesignDirectionStep::format(['description' => 'x', 'motion' => 'minimal']);
    assert_contains('hover-lift', $minimal, 'minimal names the only classes allowed');

    $none = DesignDirectionStep::format(['description' => 'x', 'motion' => 'none']);
    assert_contains('NO motion classes', $none);

    // Directions that predate the field render no Motion line.
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x']), '**Motion**'));
});

test('motionProfileFor fails closed to none', function () {
    $tmp = sys_get_temp_dir() . '/builder_ddmotion_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq('none', DesignDirectionStep::motionProfileFor($project), 'no direction file');

    $project->writeJson('designDirection.json', ['description' => 'x']);
    assert_eq('none', DesignDirectionStep::motionProfileFor($project), 'direction predates the field');

    $project->writeJson('designDirection.json', ['description' => 'x', 'motion' => 'Energetic']);
    assert_eq('energetic', DesignDirectionStep::motionProfileFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('HERO_RECIPE is exact, persisted, and isolated to one recipe fragment', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => designdir_direction()]);

    putenv('HERO_RECIPE=typographic-poster');
    try {
        (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    } finally {
        putenv('HERO_RECIPE');
    }

    $direction = $project->readJson('designDirection.json');
    assert_eq('typographic-poster', $direction['hero_blueprint']['recipe']);
    assert_eq('none', $direction['hero_blueprint']['media_mode']);
    assert_contains('typographic-poster', $llm->calls[1]['prompt']);
    foreach (HeroComposition::RECIPES as $recipe) {
        if ($recipe !== 'typographic-poster') {
            assert_true(!str_contains($llm->calls[1]['prompt'], $recipe), "{$recipe} is not exposed");
        }
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('unknown or caller-incompatible HERO_RECIPE fails before any LLM spend', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    putenv('HERO_RECIPE=not-a-recipe');
    try {
        assert_throws(fn () => (new DesignDirectionStep(
            $llm,
            new PromptRenderer(repo_path('prompts')),
        ))->run($project));
    } finally {
        putenv('HERO_RECIPE');
    }
    assert_eq(0, count($llm->calls));

    $project->writeJson('meta.json', [
        'prompt' => 'A cozy neighborhood bakery',
        'design_constraints' => ['allowed_hero_media_modes' => ['none']],
    ]);
    putenv('HERO_RECIPE=editorial-split');
    try {
        assert_throws(fn () => (new DesignDirectionStep(
            $llm,
            new PromptRenderer(repo_path('prompts')),
        ))->run($project));
    } finally {
        putenv('HERO_RECIPE');
    }
    assert_eq(0, count($llm->calls));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('caller-incompatible HEADER_ARCHETYPE fails before the design-direction seed call', function () {
    $cases = [
        ['override' => 'not-a-header', 'constraints' => []],
        ['override' => 'minimal-overlay', 'constraints' => ['hero_canvas' => 'framed']],
        ['override' => 'minimal-overlay', 'constraints' => ['allowed_hero_media_modes' => ['none']]],
        ['override' => 'split-nav', 'constraints' => []],
    ];
    foreach ($cases as $case) {
        [$project, $llm, $tmp] = make_designdir_fixture();
        $project->writeJson('meta.json', [
            'prompt' => 'A cozy neighborhood bakery',
            'multi_page' => false,
            'design_constraints' => $case['constraints'],
        ]);
        putenv('HEADER_ARCHETYPE=' . $case['override']);
        try {
            assert_throws(fn () => (new DesignDirectionStep(
                $llm,
                new PromptRenderer(repo_path('prompts')),
            ))->run($project), (string) $case['override']);
        } finally {
            putenv('HEADER_ARCHETYPE');
            exec('rm -rf ' . escapeshellarg($tmp));
        }
        assert_eq(0, count($llm->calls), 'header preflight runs before any paid model call');
    }
});

test('split-nav preflight counts caller-requested nested pages recursively', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $project->writeJson('meta.json', [
        'prompt' => 'A neighborhood bakery',
        'multi_page' => true,
        'pages' => [[
            'title' => 'Home',
            'children' => [['title' => 'Menu', 'children' => []]],
        ]],
    ]);
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => designdir_direction()]);
    putenv('HEADER_ARCHETYPE=split-nav');
    try {
        (new DesignDirectionStep(
            $llm,
            new PromptRenderer(repo_path('prompts')),
        ))->run($project);
    } finally {
        putenv('HEADER_ARCHETYPE');
        exec('rm -rf ' . escapeshellarg($tmp));
    }
    assert_eq(2, count($llm->calls), 'nested child makes the caller-owned scope definitively multi-page');
});

test('fallible batch hero assignment remaps incompatibility and warns with requested and delivered values', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $project->writeJson('meta.json', [
        'prompt' => 'A cozy neighborhood bakery',
        'design_constraints' => ['allowed_hero_media_modes' => ['none']],
        'hero_assignment' => ['source' => 'batch', 'requested_recipe' => 'diptych-editorial'],
    ]);
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => designdir_direction()]);
    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $direction = $project->readJson('designDirection.json');
    assert_eq('typographic-poster', $direction['hero_blueprint']['recipe']);
    $joined = implode(' ', $project->readJson('warnings.json')['design-direction'] ?? []);
    assert_contains('diptych-editorial', $joined);
    assert_contains('typographic-poster', $joined);
    assert_contains('remapped', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('malformed fallible hero assignment remaps with actionable provenance', function () {
    $warnings = [];
    $recipe = DesignDirectionStep::selectHeroRecipe(
        ['hero_assignment' => 'not-an-assignment-object'],
        'stable-site',
        'Committed seed',
        $warnings,
    );

    assert_true(in_array($recipe, HeroComposition::RECIPES, true));
    assert_eq(1, count($warnings));
    foreach ([
        "file='meta.json'",
        'path="hero_assignment"',
        'authored=',
        'delivered=',
        'disposition=',
    ] as $context) {
        assert_contains($context, $warnings[0]);
    }
});

test('hero blueprint accessors keep front-page topology out of the general brief', function () {
    $tmp = sys_get_temp_dir() . '/builder_ddblueprint_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    assert_throws(fn () => DesignDirectionStep::heroBlueprintFor($project));

    $blueprint = HeroBlueprint::defaultFor('framed-portrait');
    $project->writeJson('designDirection.json', [
        'description' => 'A quiet gallery language with warm mineral color.',
        'hero_blueprint' => $blueprint,
        'concept_seed' => 'Hidden seed bytes',
    ]);
    assert_eq($blueprint, DesignDirectionStep::heroBlueprintFor($project));
    $focused = DesignDirectionStep::formatHeroBlueprint($blueprint);
    assert_contains('Front-page hero blueprint (front page only)', $focused);
    assert_contains('framed-portrait', $focused);
    $general = DesignDirectionStep::readFor($project);
    assert_true(!str_contains($general, 'framed-portrait'));
    assert_true(!str_contains($general, 'Hidden seed bytes'));

    $project->writeJson('designDirection.json', [
        'description' => 'Corrupt persisted blueprint.',
        'hero_blueprint' => ['recipe' => 'not-in-the-catalog'],
    ]);
    assert_throws(fn () => DesignDirectionStep::heroBlueprintFor($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('invalid signature-device slots degrade to empty and clear hero placement with warnings', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize([
        'description' => 'A complete visual direction.',
        'signature_device' => 'One notched color block.',
        'signature_device_slots' => ['hero', 'hero', 'somewhere'],
        'hero_blueprint' => array_merge(HeroBlueprint::defaultFor('editorial-split'), [
            'signature_device_use' => 'Place the notch beside the headline.',
        ]),
    ], 'editorial-split', 'Seed bytes', $repairs, $warnings);
    assert_eq([], $direction['signature_device_slots']);
    assert_eq('', $direction['hero_blueprint']['signature_device_use']);
    assert_true(count($warnings) >= 2);
    assert_contains('signature_device_slots', implode(' ', $warnings));
});

test('subject_is_visual_work gates the image-free recipe out of automatic selection', function () {
    $ids = array_map(fn (int $i) => "gate-site-{$i}", range(1, 64));

    // Ungated, the sweep must reach the image-free recipe at least once —
    // otherwise the gated assertion below would be vacuous.
    $ungated = [];
    foreach ($ids as $id) {
        $w = [];
        $ungated[] = DesignDirectionStep::selectHeroRecipe([], $id, 'Committed seed', $w);
    }
    assert_true(in_array('typographic-poster', $ungated, true));

    foreach ($ids as $id) {
        $w = [];
        $recipe = DesignDirectionStep::selectHeroRecipe(
            [],
            $id,
            'Committed seed',
            $w,
            ['subject_is_visual_work' => true],
        );
        assert_true($recipe !== 'typographic-poster', "gated selection for {$id} must bear an image");
        assert_eq([], $w);
    }
});

test('subject_is_visual_work gate only fires on boolean true', function () {
    // Find an identifier that reaches the image-free recipe ungated, then
    // confirm non-boolean truthy spec values leave that selection unchanged.
    $chosen = null;
    foreach (range(1, 64) as $i) {
        $w = [];
        if (DesignDirectionStep::selectHeroRecipe([], "gate-site-{$i}", 'Committed seed', $w) === 'typographic-poster') {
            $chosen = "gate-site-{$i}";
            break;
        }
    }
    assert_true($chosen !== null);
    foreach (['true', 1, ['yes'], null] as $value) {
        $w = [];
        $recipe = DesignDirectionStep::selectHeroRecipe(
            [],
            $chosen,
            'Committed seed',
            $w,
            ['subject_is_visual_work' => $value],
        );
        assert_eq('typographic-poster', $recipe);
        assert_eq([], $w);
    }
});

test('caller-owned media modes beat the subject_is_visual_work gate without warnings', function () {
    $w = [];
    $recipe = DesignDirectionStep::selectHeroRecipe(
        ['design_constraints' => ['allowed_hero_media_modes' => ['none']]],
        'gate-caller-wins',
        'Committed seed',
        $w,
        ['subject_is_visual_work' => true],
    );
    assert_eq('typographic-poster', $recipe);
    assert_eq([], $w);
});

test('batch request outside the visual-work gate remaps with provenance', function () {
    $w = [];
    $recipe = DesignDirectionStep::selectHeroRecipe(
        ['hero_assignment' => ['source' => 'batch', 'requested_recipe' => 'typographic-poster']],
        'gate-batch-remap',
        'Committed seed',
        $w,
        ['subject_is_visual_work' => true],
    );
    assert_true($recipe !== 'typographic-poster');
    assert_true(in_array($recipe, HeroComposition::RECIPES, true));
    assert_eq(1, count($w));
    assert_contains('subject_is_visual_work', $w[0]);
    assert_contains('typographic-poster', $w[0]);
});
