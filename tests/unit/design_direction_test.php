<?php
declare(strict_types=1);

use Automattic\SiteBuild\JsonBatchRecovery;
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

/** @return array<string,array<string,mixed>> */
function designdir_type(): array
{
    return [
        'heading' => [
            'family' => 'Fraunces',
            'weights' => [700, 900],
            'italic' => false,
            'axes' => ['opsz' => ['min' => 9, 'max' => 144]],
            'character' => 'swaggering display serif',
        ],
        'body' => [
            'family' => 'Source Sans 3',
            'weights' => [400, 600],
            'italic' => false,
            'axes' => [],
            'character' => 'clean editorial sans',
        ],
    ];
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
        'type'             => designdir_type(),
        'image_grade'      => 'warm kodachrome color, soft golden light',
        'signature_device' => 'hairline rules with small caps folios',
        'hero_composition' => 'full-bleed bakery photo, headline pinned lower-left',
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
    assert_eq('Fraunces', $written['type']['heading']['family']);
    assert_eq([700, 900], $written['type']['heading']['weights']);
    assert_eq('warm kodachrome color, soft golden light', $written['image_grade']);
    assert_eq('hairline rules with small caps folios', $written['signature_device']);
    assert_eq('full-bleed bakery photo, headline pinned lower-left', $written['hero_composition']);

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
    foreach (['palette', 'type', 'image_grade', 'signature_device', 'hero_composition'] as $field) {
        assert_contains($field, $llm->calls[1]['prompt']);
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
        'type'        => [
            'heading' => [
                'family' => 'Fraunces',
                'weights' => [900],
                'italic' => false,
                'axes' => [],
                'character' => '',
            ],
        ],
    ]);
    assert_eq('Forge & Flame', $direction['title']);
    assert_eq(['base' => '#FDF6EC', 'primary' => '#8A5A2B'], $direction['palette']);
    assert_eq('Fraunces', $direction['type']['heading']['family']);
    assert_eq([900], $direction['type']['heading']['weights']);
    assert_eq('', $direction['type']['body']['family']);
    assert_eq('', $direction['image_grade']);

    assert_eq(null, DesignDirectionStep::normalize(['title' => 'Empty', 'description' => '   ']));
    assert_eq(null, DesignDirectionStep::normalize('not an array'));
});

test('design-direction persists structured typography and warns when an axis is removed', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $direction = designdir_direction();
    $direction['type'] = [
        'heading' => [
            'family' => 'Fraunces',
            'weights' => [700, 900],
            'italic' => false,
            'axes' => ['opsz' => ['min' => 9, 'max' => 144]],
            'character' => 'swaggering display serif',
        ],
        'body' => [
            'family' => 'Source Serif 4',
            'weights' => [400, 600],
            'italic' => true,
            'axes' => ['CASL' => ['min' => 0, 'max' => 1]],
            'character' => 'warm editorial text',
        ],
    ];
    $llm->queueJson(['direction' => $direction]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $written = $project->readJson('designDirection.json');
    assert_eq('Fraunces', $written['type']['heading']['family']);
    assert_eq([700, 900], $written['type']['heading']['weights']);
    assert_eq(['opsz' => ['min' => 9, 'max' => 144]], $written['type']['heading']['axes']);
    assert_eq(true, $written['type']['body']['italic']);
    assert_eq([], $written['type']['body']['axes'], 'unsupported axis removed');

    $warnings = implode(' ', $project->readJson('warnings.json')['design-direction'] ?? []);
    assert_contains('designDirection.json: type.body.axes.CASL', $warnings);
    assert_contains('delivered removed', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction keeps hostile family values inert and warns for every lost commitment', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $direction = designdir_direction();
    $direction['type']['heading'] = [
        'family' => '*/ system($_GET["cmd"]); /*',
        'weights' => [900],
        'italic' => true,
        'axes' => ['opsz' => ['min' => 9, 'max' => 144]],
        'character' => 'dangerous display face',
    ];
    $llm->queueJson(['direction' => $direction]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $heading = $project->readJson('designDirection.json')['type']['heading'];
    assert_eq(
        ['family' => '', 'weights' => [], 'italic' => false, 'axes' => [], 'character' => ''],
        $heading,
    );
    $warnings = implode(' ', $project->readJson('warnings.json')['design-direction'] ?? []);
    assert_contains('type.heading.family authored value', $warnings);
    assert_contains('type.heading.weights authored value', $warnings);
    assert_contains('type.heading.italic authored value', $warnings);
    assert_contains('type.heading.axes.opsz authored value', $warnings);
    assert_true(!str_contains(DesignDirectionStep::format($project->readJson('designDirection.json')), 'system('));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('format renders the narrative plus the structured fact list', function () {
    $text = DesignDirectionStep::format([
        'title'            => 'Archivo Silencioso',
        'description'      => 'Full-bleed black-and-white photography.',
        'palette'          => ['base' => '#F4F1EA', 'accent' => '#C33F2E'],
        'type'             => [
            'heading' => [
                'family' => 'Fraunces',
                'weights' => [900],
                'italic' => false,
                'axes' => [],
                'character' => '',
            ],
            'body' => [
                'family' => 'Source Sans 3',
                'weights' => [400],
                'italic' => false,
                'axes' => [],
                'character' => '',
            ],
        ],
        'image_grade'      => 'monochrome documentary',
        'signature_device' => 'hairline rules with folios',
        'hero_composition' => 'headline pinned lower-left',
    ]);
    assert_contains('# Archivo Silencioso', $text);
    assert_contains('base #F4F1EA', $text);
    assert_contains('heading — Fraunces; weights 900; body — Source Sans 3; weights 400', $text);
    assert_contains('Signature device', $text);
    assert_contains('hero composition', strtolower($text));
    assert_contains('monochrome documentary', $text);

    // Empty fields are omitted — a bare direction is just the narrative.
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.']));
});

test('format renders structured typography without losing its design character', function () {
    $text = DesignDirectionStep::format([
        'description' => 'Print-led warmth.',
        'type' => [
            'heading' => [
                'family' => 'Fraunces',
                'weights' => [700, 900],
                'italic' => false,
                'axes' => ['opsz' => ['min' => 9.0, 'max' => 144.0]],
                'character' => 'swaggering display serif',
            ],
            'body' => [
                'family' => 'Source Serif 4',
                'weights' => [400, 600],
                'italic' => true,
                'axes' => [],
                'character' => 'warm editorial text',
            ],
        ],
    ]);

    assert_contains('heading — Fraunces; weights 700/900; opsz 9..144; swaggering display serif', $text);
    assert_contains('body — Source Serif 4; weights 400/600; true italics; warm editorial text', $text);
});

test('normalize commits a canvas: framed passes through, everything else is full-bleed', function () {
    assert_eq('framed', DesignDirectionStep::normalize(['description' => 'x', 'canvas' => ' Framed '])['canvas']);
    assert_eq('full-bleed', DesignDirectionStep::normalize(['description' => 'x'])['canvas']);
    assert_eq('full-bleed', DesignDirectionStep::normalize(['description' => 'x', 'canvas' => 'poster'])['canvas']);
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
    $seeded = DesignDirectionStep::fallbackDirection('Neon Dusk — electric gradients over charcoal.');
    assert_eq('Neon Dusk — electric gradients over charcoal.', $seeded['description']);

    $generic = DesignDirectionStep::fallbackDirection('');
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
        'type'        => [
            'heading' => [
                'family' => 'Fraunces',
                'weights' => [900],
                'italic' => false,
                'axes' => [],
                'character' => '',
            ],
            'body' => [
                'family' => 'Source Sans 3',
                'weights' => [400],
                'italic' => false,
                'axes' => [],
                'character' => '',
            ],
        ],
    ]);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer))->run($project);

    assert_contains('Editorial-magazine', $llm->calls[0]['prompt']);
    assert_contains('base #FDF6EC', $llm->calls[0]['prompt'], 'structured palette reaches the theme prompt');
    assert_contains(
        'heading — Fraunces; weights 900',
        $llm->calls[0]['prompt'],
        'structured type reaches the theme prompt',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('normalize commits a motion profile: valid values pass, anything else defaults', function () {
    assert_eq('dramatic', DesignDirectionStep::normalize(['description' => 'x', 'motion' => ' Dramatic '])['motion']);
    assert_eq('none', DesignDirectionStep::normalize(['description' => 'x', 'motion' => 'none'])['motion']);
    assert_eq('calm', DesignDirectionStep::normalize(['description' => 'x'])['motion'], 'missing → default');
    assert_eq('calm', DesignDirectionStep::normalize(['description' => 'x', 'motion' => 'bouncy'])['motion'], 'unknown → default');
    assert_eq('calm', DesignDirectionStep::normalize(['description' => 'x', 'motion' => ['calm']])['motion'], 'non-string → default');
    assert_eq('a note', DesignDirectionStep::normalize(['description' => 'x', 'motion_note' => ' a note '])['motion_note']);
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
