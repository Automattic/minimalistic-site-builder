<?php
declare(strict_types=1);

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
        'hero_composition' => 'full-bleed bakery photo, headline pinned lower-left',
    ];
}

test('design-direction expands the judged seed into structured designDirection.json', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['choice' => 3, 'rationale' => 'Only seed that escapes the obvious warm-cream register.']);
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
    assert_eq('full-bleed bakery photo, headline pinned lower-left', $written['hero_composition']);

    // The chosen concept and the reason travel with the direction, so a finished
    // build says which candidate it came from and why.
    assert_eq('Seed 3', $written['seed']);
    assert_contains('warm-cream register', $written['seed_rationale']);

    assert_eq(3, count($llm->calls), 'exactly three calls: seeds + judge + expansion');

    // The seed prompt carries the user's words and the factual spec.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);
    assert_contains('title', $llm->calls[0]['prompt']);

    // The judge prompt carries the brief, the spec, and every numbered candidate.
    assert_contains('cozy neighborhood bakery', $llm->calls[1]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[1]['prompt']);
    foreach (designdir_seeds() as $i => $seed) {
        assert_contains(($i + 1) . '. ' . $seed, $llm->calls[1]['prompt']);
    }

    // The expansion prompt carries the brief, the spec, ONLY the winning seed,
    // and asks for every structured field.
    assert_contains('cozy neighborhood bakery', $llm->calls[2]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[2]['prompt']);
    assert_contains('Seed 3', $llm->calls[2]['prompt'], 'the judged seed reached the expansion prompt');
    assert_true(!str_contains($llm->calls[2]['prompt'], 'Seed 1'), 'losing seeds do not leak into the prompt');
    foreach (['palette', 'type', 'image_grade', 'signature_device', 'hero_composition'] as $field) {
        assert_contains($field, $llm->calls[2]['prompt']);
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction routes each call to its own model and keeps the hot temperature off the judge', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['choice' => 1, 'rationale' => 'strongest topic grounding']);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer, 'claude-opus-5', 1.0, 'claude-haiku-4-5', 'claude-opus-5'))
        ->run($project);

    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null, 'seeds use the seed model');
    assert_eq(1.0, $llm->calls[0]['opts']['temperature'] ?? null, 'seed spread runs hot');
    assert_eq('claude-opus-5', $llm->calls[1]['opts']['model'] ?? null, 'the judge uses the judge model');
    assert_eq(null, $llm->calls[1]['opts']['temperature'] ?? null, 'scoring is not a sampling task');
    assert_eq('claude-opus-5', $llm->calls[2]['opts']['model'] ?? null, 'expansion uses the step model');
    assert_eq(1.0, $llm->calls[2]['opts']['temperature'] ?? null, 'expansion keeps the step temperature');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction falls back to a random pick when the judge does not decide', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    // The judge answered, but with no usable choice.
    $llm->queueJson(['rationale' => 'they are all quite nice']);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    $written = $project->readJson('designDirection.json');
    assert_contains('Seed ', $llm->calls[2]['prompt'], 'a seed still reached the expansion prompt');
    assert_contains('Seed ', (string) ($written['seed'] ?? ''), 'the random pick is still recorded');
    assert_true(!isset($written['seed_rationale']), 'no rationale is claimed for an unjudged pick');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction survives a judge that errors', function () {
    [$project, , $tmp] = make_designdir_fixture();

    // Seeds succeed, the judge call throws, the expansion succeeds.
    $llm = new class implements Llm {
        /** @var array<int,array{prompt:string,opts:array<mixed>}> */
        public array $calls = [];

        public function complete(string $prompt, array $opts = []): string
        {
            throw new RuntimeException('unused');
        }

        public function completeJson(string $prompt, array $opts = []): array
        {
            $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
            return match (count($this->calls)) {
                1       => ['seeds' => designdir_seeds()],
                2       => throw new RuntimeException('judge transport error'),
                default => ['direction' => designdir_direction()],
            };
        }

        public function completeJsonBatch(array $requests): array
        {
            throw new RuntimeException('unused');
        }

        public function completeBatch(array $requests): array
        {
            throw new RuntimeException('unused');
        }
    };

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('designDirection.json'), 'a direction is still committed');
    assert_contains('Seed ', $llm->calls[2]['prompt'], 'a seed still reached the expansion prompt');
    assert_true(
        !isset($project->readJson('designDirection.json')['seed_rationale']),
        'no rationale is claimed when the judge never answered'
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction skips the judge when only one seed is usable', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => ['Lone Seed — the only candidate.', '   ', 42]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_eq(2, count($llm->calls), 'nothing to choose between, so no judge call');
    assert_contains('Lone Seed', $llm->calls[1]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('normalizeVerdict accepts an in-range choice and rejects everything else', function () {
    assert_eq([1, 'because'], DesignDirectionStep::normalizeVerdict(['choice' => 2, 'rationale' => ' because '], 4));
    assert_eq([0, ''], DesignDirectionStep::normalizeVerdict(['choice' => '1'], 4), 'a numeric string still decides');
    assert_eq(null, DesignDirectionStep::normalizeVerdict(['choice' => 0], 4), 'the numbering is 1-based');
    assert_eq(null, DesignDirectionStep::normalizeVerdict(['choice' => 5], 4), 'out of range');
    assert_eq(null, DesignDirectionStep::normalizeVerdict(['choice' => 'the third one'], 4));
    assert_eq(null, DesignDirectionStep::normalizeVerdict(['rationale' => 'no choice made'], 4));
    assert_eq(null, DesignDirectionStep::normalizeVerdict([], 4));
});

test('formatSeeds numbers the candidates the way the judge answers', function () {
    assert_eq(
        "1. Salt & Iron — a.\n2. Midnight Provisions — b.",
        DesignDirectionStep::formatSeeds(['Salt & Iron — a.', 'Midnight Provisions — b.'])
    );
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

        public function completeBatch(array $requests): array
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
    $llm->queueJson(['choice' => 1]);
    $llm->queueJson(['direction' => designdir_direction()]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_eq(
        '[{"title":"Forbidden Previous Direction"}]',
        (string) file_get_contents($tmp . '/.direction-history.json'),
        'legacy history files are ignored and left untouched'
    );
    foreach ($llm->calls as $i => $call) {
        assert_true(
            !str_contains($call['prompt'], 'Forbidden Previous Direction'),
            "call {$i} ignores previous directions"
        );
    }

    $second = (new ProjectStore($tmp))->create('demo-two');
    $second->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $second->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb']);
    $llm2 = new FakeLlm();
    $llm2->queueJson(['seeds' => designdir_seeds()]);
    $llm2->queueJson(['choice' => 1]);
    $llm2->queueJson(['direction' => designdir_direction()]);
    (new DesignDirectionStep($llm2, $renderer))->run($second);

    foreach ($llm2->calls as $i => $call) {
        assert_true(
            !str_contains($call['prompt'], 'Hearth & Grain'),
            "call {$i} does not list the previous build choice"
        );
        assert_true(
            !str_contains($call['prompt'], 'Forbidden Previous Direction'),
            "call {$i} does not list the legacy history"
        );
    }

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

test('design-direction throws when the model returns no usable direction', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['choice' => 2]);
    $llm->queueJson(['direction' => ['title' => 'Empty', 'description' => '   ']]);
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
