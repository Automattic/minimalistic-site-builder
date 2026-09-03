<?php
declare(strict_types=1);

use Automattic\SiteBuild\JsonBatchRecovery;
use Automattic\SiteBuild\CtaStyle;
use Automattic\SiteBuild\GroundKey;
use Automattic\SiteBuild\GroundTint;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Measure;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\TypeTreatment;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TypeScale;

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

/** @return array{seed:string,ground:string,register:string,accent:string} */
function designdir_seed_obj(string $text, string $ground, string $register, string $accent): array
{
    return ['seed' => $text, 'ground' => $ground, 'register' => $register, 'accent' => $accent];
}

/** @return array<string,array<string,mixed>> */
function designdir_type(): array
{
    return [
        'heading' => [
            'family' => 'Spectral',
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
        'image_treatment'  => 'tinted-overlay',
        'text_placement'   => 'asymmetric-thirds',
        'image_crop'       => 'portrait',
        'card_style'       => 'framed',
        'depth'            => 'soft',
        'hero_blueprint'   => HeroBlueprint::defaultFor('foreground-split'),
    ];
}

/** @param list<string> $rows @return list<string> */
function designdir_card_rows(array $rows): array
{
    return array_values(array_filter(
        $rows,
        static fn (string $row): bool => str_contains($row, 'card_style'),
    ));
}

test('design-direction persists and narrates an unexecutable ornament promise', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $authored = designdir_direction();
    $authored['description'] = 'Delicate filigree runs along every band edge.';
    $authored['device'] = 'none';
    $llm->queueJson(['direction' => $authored]);

    $sink = fopen('php://temp', 'w+');
    Narrator::setStream($sink);
    try {
        (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    } finally {
        Narrator::setStream(null);
    }

    $warnings = $project->readJson('warnings.json')['design-direction'] ?? [];
    assert_eq(1, count($warnings), 'one defective sentence writes one durable row');
    foreach ([
        "file='designDirection.json'",
        'path="description"',
        'filigree',
        'delivered=not executed',
        'committed no device',
    ] as $context) {
        assert_contains($context, $warnings[0]);
    }

    rewind($sink);
    assert_contains(
        '[design-direction] warning: delivered through 1 generated-content degradation(s)',
        (string) stream_get_contents($sink),
        'the durable warning is also narrated live',
    );
    fclose($sink);
    exec('rm -rf ' . escapeshellarg($tmp));
});

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
    assert_eq('Spectral', $written['type']['heading']['family']);
    assert_eq([700, 900], $written['type']['heading']['weights']);
    assert_eq('warm kodachrome color, soft golden light', $written['image_grade']);
    assert_eq('tinted-overlay', $written['image_treatment']);
    assert_eq('asymmetric-thirds', $written['text_placement']);
    assert_eq('portrait', $written['image_crop']);
    assert_eq('framed', $written['card_style']);
    assert_eq('soft', $written['depth']);
    assert_eq(Measure::DEFAULT, $written['measure']);
    assert_eq(TypeScale::DEFAULT, $written['type_scale']);
    assert_eq(TypeTreatment::DEFAULT, $written['type_treatment']);
    assert_eq(CtaStyle::DEFAULT, $written['cta_style']);
    assert_true(!array_key_exists('signature_device', $written), 'signature_device field is gone');
    assert_true(in_array($written['hero_blueprint']['recipe'], HeroComposition::RECIPES, true));
    assert_contains('Seed ', $written['concept_seed']);
    assert_true(!array_key_exists('hero_composition', $written), 'old prose field is gone');

    // The seed prompt carries the user's words and the factual spec.
    assert_eq(2, count($llm->calls), 'exactly two calls: seeds + expansion');
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);
    assert_true(
        !str_contains($llm->calls[0]['prompt'], '`"luxury"`'),
        'a brief that did not name luxury does not offer it as a label',
    );
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);
    assert_contains('title', $llm->calls[0]['prompt']);

    // The expansion prompt carries the brief, the spec, ONE of the seeds
    // (random pick), and asks for every structured field.
    assert_contains('cozy neighborhood bakery', $llm->calls[1]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[1]['prompt']);
    assert_contains('Seed ', $llm->calls[1]['prompt'], 'a seed reached the expansion prompt');
    foreach (['palette', 'type', 'type_scale', 'type_treatment', 'image_grade', 'image_treatment', 'image_crop', 'canvas', 'measure', 'card_style', 'depth', 'cta_style', 'hero_blueprint'] as $field) {
        assert_contains($field, $llm->calls[1]['prompt']);
    }
    assert_contains(
        '"motion_note": ["Zero or more motion-kit class names the profile ships, chosen per the motion_note field above."],',
        $llm->calls[1]['prompt'],
    );
    $assigned = $written['hero_blueprint']['recipe'];
    assert_contains($assigned, $llm->calls[1]['prompt']);
    foreach (HeroComposition::RECIPES as $other) {
        if ($other !== $assigned) {
            assert_true(!str_contains($llm->calls[1]['prompt'], $other), "{$other} recipe does not leak");
        }
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction persists an unmappable motion-note warning and reaches a fixed point', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $authored = designdir_direction();
    $authored['motion'] = 'calm';
    $authored['motion_note'] = 'a cinematic wipe nobody ships';
    $llm->queueJson(['direction' => $authored]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $written = $project->readJson('designDirection.json');
    assert_eq([], $written['motion_note']);
    foreach (['title', 'image_grade', 'image_treatment', 'image_crop', 'card_style', 'depth'] as $sibling) {
        assert_eq($authored[$sibling], $written[$sibling], "{$sibling} survives motion-note removal");
    }
    foreach ($authored['palette'] as $slug => $hex) {
        assert_eq($hex, $written['palette'][$slug], "palette.{$slug} survives motion-note removal");
    }
    assert_true(\Automattic\SiteBuild\BandColor::valid(
        $written['palette']['base'],
        $written['palette']['band'],
    ), 'the missing committed band is derived independently of motion-note removal');
    foreach (['heading', 'body'] as $face) {
        assert_eq($authored['type'][$face], $written['type'][$face], "type.{$face} survives motion-note removal");
    }
    assert_eq('', $written['type']['accent']['family'], 'no accent face is invented');

    $motionWarning = '';
    foreach ($project->readJson('warnings.json')['design-direction'] ?? [] as $warning) {
        if (str_contains($warning, 'field motion_note')) {
            $motionWarning = $warning;
            break;
        }
    }
    foreach ([
        'designDirection.json',
        'field motion_note',
        'a cinematic wipe nobody ships',
        'delivered=[]',
        'named no motion-kit class the calm profile ships',
    ] as $context) {
        assert_contains($context, $motionWarning);
    }

    $repairs = [];
    $warnings = [];
    $normalizedAgain = DesignDirectionStep::normalize(
        $written,
        $written['hero_blueprint']['recipe'],
        $written['concept_seed'],
        $repairs,
        $warnings,
    );
    assert_eq(
        json_encode($written, JSON_THROW_ON_ERROR),
        json_encode($normalizedAgain, JSON_THROW_ON_ERROR),
        'serialized delivered direction is a fixed point',
    );
    assert_eq([], $repairs);
    assert_eq([], $warnings);

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

test('design-direction offers a locked extra label only when the brief named that look', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $project->writeJson('meta.json', ['prompt' => 'A luxury bakery in Lisbon']);
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_contains('`"luxury"`', $llm->calls[0]['prompt']);
    assert_contains('This brief already named a look', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction drops a repeated world from the pick and records one warning', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => [
        designdir_seed_obj('Hearth Light — a warm heritage bakery.', 'light', 'heritage', 'warm'),
        designdir_seed_obj('Copper Morning — a warm heritage bakery.', 'light', 'heritage', 'warm'),
        designdir_seed_obj('Night Kitchen — a dark modernist counter.', 'dark', 'modernist', 'cool'),
    ]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    $warnings = $project->readJson('warnings.json')['design-direction'] ?? [];
    $seedWarnings = array_values(array_filter(
        $warnings,
        static fn (string $row): bool => str_contains($row, 'concept seed')
            || str_contains($row, 'concept seeds'),
    ));
    assert_eq(1, count($seedWarnings), 'one drop, not a drop plus a shared-ground echo');
    assert_contains('Copper Morning', $seedWarnings[0]);
    assert_contains('disposition dropped', $seedWarnings[0]);
    assert_true(
        !str_contains(implode("\n", $warnings), 'open brief'),
        'the warning does not claim the brief was open',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction records a collapsed round once, without a shared-ground echo', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => [
        designdir_seed_obj('One', 'dark', 'editorial', 'jewel'),
        designdir_seed_obj('Two', 'dark', 'editorial', 'jewel'),
        designdir_seed_obj('Three', 'dark', 'editorial', 'jewel'),
    ]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    $warnings = $project->readJson('warnings.json')['design-direction'] ?? [];
    $seedWarnings = array_values(array_filter(
        $warnings,
        static fn (string $row): bool => str_contains($row, 'concept seed')
            || str_contains($row, 'concept seeds')
            || str_contains($row, '-grounded'),
    ));
    assert_eq(1, count($seedWarnings), 'collapse is one durable row');
    assert_contains('describe one world', $seedWarnings[0]);
    assert_true(
        !str_contains(implode("\n", $warnings), 'every concept seed is'),
        'shared ground is already named inside the collapse row',
    );
    assert_true(
        !str_contains(implode("\n", $warnings), 'open brief'),
        'a collapsed round is not reported as an open brief',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction records a round of distinct worlds that all lean on one tint', function () {
    // BIGR-922: tint is not in the dedup key, so three distinct worlds can
    // still share a family — the audited cohort's cream skew. The lean is
    // recorded, never blocking.
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => [
        designdir_seed_obj('One', 'light', 'heritage', 'warm') + ['tint' => 'warm'],
        designdir_seed_obj('Two', 'light', 'modernist', 'cool') + ['tint' => 'warm'],
        designdir_seed_obj('Three', 'dark', 'noir', 'jewel') + ['tint' => 'warm'],
    ]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $joined = implode("\n", $project->readJson('warnings.json')['design-direction'] ?? []);
    assert_contains('every concept seed is warm-tinted', $joined);
    assert_contains('disposition tolerated', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction stays quiet about tint when the round spreads its families', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => [
        designdir_seed_obj('One', 'light', 'heritage', 'warm') + ['tint' => 'warm'],
        designdir_seed_obj('Two', 'light', 'modernist', 'cool') + ['tint' => 'cool'],
        designdir_seed_obj('Three', 'dark', 'noir', 'jewel') + ['tint' => 'violet'],
    ]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $joined = $project->exists('warnings.json')
        ? implode("\n", $project->readJson('warnings.json')['design-direction'] ?? [])
        : '';
    assert_true(!str_contains($joined, '-tinted'), 'a spread round earns no tint row');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction does not warn that every seed is grounded when two left ground unstated', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => [
        designdir_seed_obj('One', 'light', 'heritage', 'warm'),
        ['seed' => 'Two — a sentence.', 'register' => 'modernist', 'accent' => 'cool'],
        ['seed' => 'Three — a sentence.'],
    ]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    $joined = $project->exists('warnings.json')
        ? implode("\n", $project->readJson('warnings.json')['design-direction'] ?? [])
        : '';
    assert_true(
        !str_contains($joined, 'every concept seed is'),
        'one named ground is not a round-wide claim',
    );

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
                'family' => 'Spectral',
                'weights' => [900],
                'italic' => false,
                'axes' => [],
                'character' => '',
            ],
        ],
    ], 'cinematic-safe-zone');
    assert_eq('Forge & Flame', $direction['title']);
    assert_eq('#FDF6EC', $direction['palette']['base']);
    assert_eq('#8A5A2B', $direction['palette']['primary']);
    assert_true(\Automattic\SiteBuild\BandColor::valid(
        $direction['palette']['base'],
        $direction['palette']['band'],
    ));
    assert_eq(['base', 'primary', 'band'], array_keys($direction['palette']));
    assert_eq('Spectral', $direction['type']['heading']['family']);
    assert_eq([900], $direction['type']['heading']['weights']);
    assert_eq('', $direction['type']['body']['family']);
    assert_eq('', $direction['image_grade']);

    assert_eq(null, DesignDirectionStep::normalize(['title' => 'Empty', 'description' => '   '], 'cinematic-safe-zone'));
    assert_eq(null, DesignDirectionStep::normalize('not an array', 'cinematic-safe-zone'));
});

test('format renders the ground key and tint as facts, so downstream prompts can cite them', function () {
    // theme-json.md tells the model a **Ground tint** fact may arrive. If
    // format() never emits one, that instruction describes something that
    // does not exist.
    $rendered = DesignDirectionStep::format([
        'description' => 'x',
        'palette'     => ['base' => '#1B2233'],
        'ground_key'  => 'dark',
        'ground_tint' => 'cool',
    ]);
    assert_contains('**Ground key**', $rendered);
    assert_contains('dark', $rendered);
    assert_contains('**Ground tint**', $rendered);
    assert_contains('cool', $rendered);

    assert_true(
        !str_contains(DesignDirectionStep::format(['description' => 'x']), 'Ground tint'),
        'an uncommitted ground states nothing',
    );
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x']), 'Ground key'));
});

test('the seed and expansion prompts ask for both ground coordinates, and ban treatments not hues', function () {
    $renderer = new PromptRenderer(repo_path('prompts'));

    $seeds = $renderer->render('design-direction-seeds.md', [
        'user_prompt' => 'a bakery', 'site_spec' => '{}', 'locked_labels' => '',
    ]);
    assert_contains('`tint`', $seeds, 'seeds declare which way their ground leans');
    foreach (['warm', 'cool', 'violet', 'green', 'blush', 'neutral'] as $family) {
        assert_contains('"' . $family . '"', $seeds, "{$family} is offered as a ground");
    }

    $direction = $renderer->render('design-direction.md', [
        'user_prompt' => 'a bakery', 'site_spec' => '{}', 'seed' => 'Seed',
        'hero_composition' => '', 'ground_key' => 'dark', 'ground_tint' => 'violet',
        'register' => 'editorial', 'type_register' => 'didone', 'type_candidates' => '',
        'color_economy' => 'monochrome',
    ]);
    assert_contains('ground_key', $direction, 'the expansion commits the light/dark field the build enforces');
    assert_contains('dark', $direction, 'and is told which side the seed chose');
    assert_contains('ground_tint', $direction, 'the expansion commits the field the build enforces');
    assert_contains('violet', $direction, 'and is told which family the seed chose');
    assert_contains('color_economy', $direction, 'the expansion commits a bounded hue budget');
    assert_contains('monochrome', $direction, 'and sees the budget selected by the seed');

    // No hue may be forbidden outright — only the cliche treatment.
    foreach ([$direction, $renderer->render('theme-json.md', [
        'user_prompt' => 'a bakery', 'site_spec' => '{}', 'design_direction' => 'x',
        'hero_sizing_context' => '',
    ])] as $prompt) {
        foreach (['Avoid purple-on-white', 'blue-and-gray corporate', 'generic blue-gray'] as $hueBan) {
            assert_true(!str_contains($prompt, $hueBan), "hue ban removed: {$hueBan}");
        }
    }
});

test('design-direction carries the chosen seed tint into the direction it writes', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    // One seed, so the uniform pick is deterministic. It commits a cool
    // ground; the expansion hands back the usual cream anyway.
    $llm->queueJson(['seeds' => [
        ['seed' => 'Ink & Brass — a deep blue reading room.', 'ground' => 'light',
            'register' => 'editorial', 'accent' => 'cool', 'tint' => 'cool',
            'color_economy' => 'monochrome'],
    ]]);
    $llm->queueJson(['direction' => designdir_direction()]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $written = $project->readJson('designDirection.json');
    assert_eq('light', $written['ground_key'], 'the seed light/dark coordinate reaches the written direction');
    assert_eq('cool', $written['ground_tint'], 'the seed coordinate reaches the written direction');
    assert_eq('monochrome', $written['color_economy'], 'the seed hue budget outranks expansion drift');
    assert_eq('cool', GroundTint::classify($written['palette']['base']), 'and the cream ground was moved onto it');
    assert_eq('#26221E', $written['palette']['contrast'], 'siblings are untouched');
    assert_contains(
        'seed already committed this: **light**',
        $llm->calls[1]['prompt'],
        'the expansion sees the selected seed ground instead of deciding it again',
    );
    assert_contains(
        '**monochrome**',
        $llm->calls[1]['prompt'],
        'the expansion sees the selected seed economy instead of inventing a color count later',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('normalize moves a base that contradicts the light or dark key its seed committed', function () {
    foreach (
        [
            ['key' => 'dark', 'base' => '#F4EBDA', 'tint' => 'warm'],
            ['key' => 'light', 'base' => '#1B2233', 'tint' => 'cool'],
        ] as $case
    ) {
        $repairs = [];
        $warnings = [];
        $direction = DesignDirectionStep::normalize(
            ['description' => 'x', 'palette' => ['base' => $case['base']]],
            'cinematic-safe-zone',
            'committed seed',
            $repairs,
            $warnings,
            $case['tint'],
            $case['key'],
        );

        assert_eq($case['key'], $direction['ground_key']);
        assert_eq($case['key'], GroundKey::classify($direction['palette']['base']));
        assert_eq($case['tint'], GroundTint::classify($direction['palette']['base']));
        assert_contains('palette.base', $repairs[0]);
        assert_contains('committed "' . $case['key'] . '" ground', $repairs[0]);

        $fixedPointRepairs = [];
        $fixedPointWarnings = [];
        $again = DesignDirectionStep::normalize(
            $direction,
            'cinematic-safe-zone',
            'committed seed',
            $fixedPointRepairs,
            $fixedPointWarnings,
            $case['tint'],
            $case['key'],
        );
        assert_eq($direction['palette']['base'], $again['palette']['base']);
        assert_eq([], $fixedPointRepairs);
        assert_eq([], $fixedPointWarnings);
    }
});

test('normalize falls back to the direction own ground_key when the seed committed none', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'ground_key' => 'dark', 'palette' => ['base' => '#F4EBDA']],
        'cinematic-safe-zone',
        'seed without coordinates',
        $repairs,
        $warnings,
    );
    assert_eq('dark', $direction['ground_key']);
    assert_eq('dark', GroundKey::classify($direction['palette']['base']));
});

test('normalize ignores a ground_key outside the vocabulary', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'ground_key' => 'dim', 'palette' => ['base' => '#F4EBDA']],
        'cinematic-safe-zone',
        '',
        $repairs,
        $warnings,
    );
    assert_eq('', $direction['ground_key']);
    assert_eq('#F4EBDA', $direction['palette']['base']);
    assert_eq(1, count($repairs), 'the ignored ground_key causes no repair; only the missing band is derived');
    assert_contains('palette.band', $repairs[0]);
});

test('normalize moves a base that drifted off the tint its seed committed', function () {
    // The audited failure: a seed commits deep jewel tones and the expansion
    // hands back a cream page anyway. Measured on real builds, nine of the
    // nineteen beige grounds came from seeds that never named a warm neutral.
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        [
            'description' => 'Stark editorial layout, deep jewel tones.',
            'palette'     => [
                'base'     => '#F4EBDA',
                'contrast' => '#1A1A1A',
                'accent'   => '#B4472A',
            ],
        ],
        'cinematic-safe-zone',
        'Threshold — deep jewel tones.',
        $repairs,
        $warnings,
        'cool',
    );

    assert_eq('cool', GroundTint::classify($direction['palette']['base']), 'the ground joins its committed family');
    assert_eq('cool', $direction['ground_tint'], 'and the direction records what it committed to');
    assert_eq('#1A1A1A', $direction['palette']['contrast'], 'only the ground moves');
    assert_eq('#B4472A', $direction['palette']['accent']);
    assert_contains('palette.base', $repairs[0]);
    assert_contains('cool', $repairs[0]);
});

test('normalize leaves an earned warm ground exactly as authored', function () {
    // A bakery whose seed committed to cream keeps its cream.
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'Flour-dusted warmth.', 'palette' => ['base' => '#F4EBDA']],
        'cinematic-safe-zone',
        'Hearth & Crumb — warm cream and amber.',
        $repairs,
        $warnings,
        'warm',
    );
    assert_eq('#F4EBDA', $direction['palette']['base']);
    assert_true(\Automattic\SiteBuild\BandColor::valid(
        $direction['palette']['base'],
        $direction['palette']['band'],
    ));
    assert_eq(1, count($repairs), 'the honored ground stays untouched; only its missing band is derived');
    assert_contains('palette.band', $repairs[0]);
});

test('normalize enforces nothing when no tint was committed', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'palette' => ['base' => '#F4EBDA']],
        'cinematic-safe-zone',
        'A seed from a round that declared no coordinates.',
        $repairs,
        $warnings,
    );
    assert_eq('#F4EBDA', $direction['palette']['base'], 'nothing was committed, so nothing was violated');
    assert_eq('', $direction['ground_tint']);
    assert_true(\Automattic\SiteBuild\BandColor::valid(
        $direction['palette']['base'],
        $direction['palette']['band'],
    ));
    assert_eq(1, count($repairs), 'the independent band contract still derives its missing role');
    assert_contains('palette.band', $repairs[0]);
});

test('normalize falls back to the direction own ground_tint when the seed committed none', function () {
    // Still a real check: the model declared a family, then painted another.
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        [
            'description' => 'x',
            'ground_tint' => 'violet',
            'palette'     => ['base' => '#F4EBDA'],
        ],
        'cinematic-safe-zone',
        'seed without coordinates',
        $repairs,
        $warnings,
    );
    assert_eq('violet', GroundTint::classify($direction['palette']['base']));
    assert_eq('violet', $direction['ground_tint']);
});

test('normalize warns actionably when an impossible tint must remain unresolved', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'palette' => ['base' => '#000000']],
        'cinematic-safe-zone',
        'an absolute-black warm ground',
        $repairs,
        $warnings,
        'warm',
        'dark',
    );

    assert_eq('#000000', $direction['palette']['base']);
    assert_eq(1, count($repairs), 'the unresolved tint causes no repair; only the missing band is derived');
    assert_contains('palette.band', $repairs[0]);
    assert_contains("file='designDirection.json'", $warnings[0]);
    assert_contains('path="palette.base"', $warnings[0]);
    assert_contains('authored="#000000"', $warnings[0]);
    assert_contains('delivered="#000000"', $warnings[0]);
    assert_contains('ground_tint "warm" cannot be rendered', $warnings[0]);
    assert_contains('disposition=', $warnings[0]);
});

test('normalize ignores a ground_tint outside the vocabulary', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'ground_tint' => 'chartreuse', 'palette' => ['base' => '#F4EBDA']],
        'cinematic-safe-zone',
        '',
        $repairs,
        $warnings,
    );
    assert_eq('#F4EBDA', $direction['palette']['base']);
    assert_eq('', $direction['ground_tint']);
});

test('design-direction persists structured typography and warns when an axis is removed', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $direction = designdir_direction();
    $direction['type'] = [
        'heading' => [
            'family' => 'Spectral',
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
    assert_eq('Spectral', $written['type']['heading']['family']);
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
                'family' => 'Spectral',
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
        'concept_seed' => 'must stay hidden',
        'hero_blueprint' => HeroBlueprint::defaultFor('foreground-split'),
    ]);
    assert_contains('# Archivo Silencioso', $text);
    assert_contains('base #F4F1EA', $text);
    assert_contains('heading — Spectral; weights 900; body — Source Sans 3; weights 400', $text);
    assert_contains('monochrome documentary', $text);
    assert_true(!str_contains($text, 'foreground-split'), 'general format excludes hero recipe');
    assert_true(!str_contains($text, 'must stay hidden'), 'general format excludes concept seed');

    // Empty fields are omitted — a bare direction is just the narrative.
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.']));
});

test('format renders structured typography without losing its design character', function () {
    $text = DesignDirectionStep::format([
        'description' => 'Print-led warmth.',
        'type' => [
            'heading' => [
                'family' => 'Spectral',
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

    assert_contains('heading — Spectral; weights 700/900; opsz 9..144; swaggering display serif', $text);
    assert_contains('body — Source Serif 4; weights 400/600; true italics; warm editorial text', $text);
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

test('normalize commits and renders one bounded measure with framed-canvas meaning', function () {
    foreach (Measure::ALL as $measure) {
        $warnings = [];
        $direction = DesignDirectionStep::normalize([
            'description' => 'x',
            'canvas' => $measure === 'narrow' ? 'framed' : 'full-bleed',
            'measure' => strtoupper($measure),
            'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
        ], 'cinematic-safe-zone', '', warnings: $warnings);

        assert_eq($measure, $direction['measure']);
        assert_eq([], $warnings);
        assert_contains('**Measure**: ' . $measure, DesignDirectionStep::format($direction));
    }

    $framed = DesignDirectionStep::format([
        'description' => 'x',
        'canvas' => 'framed',
        'measure' => 'narrow',
    ]);
    assert_contains('640px reading column inside a 1000px wide stage', $framed);
    assert_contains('visible frame edge below the full-bleed hero', $framed);
});

test('invalid measure degrades to standard with actionable evidence', function () {
    foreach (['panoramic', ['wide'], true] as $authored) {
        $warnings = [];
        $direction = DesignDirectionStep::normalize([
            'description' => 'x',
            'measure' => $authored,
            'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
        ], 'cinematic-safe-zone', '', warnings: $warnings);

        assert_eq(Measure::DEFAULT, $direction['measure']);
        assert_eq(1, count($warnings));
        assert_contains('field measure', $warnings[0]);
        assert_contains('delivered "standard"', $warnings[0]);
        assert_contains('disposition', $warnings[0]);
    }
});

test('normalize commits and renders one bounded type treatment', function () {
    foreach (TypeTreatment::ALL as $treatment) {
        $warnings = [];
        $direction = DesignDirectionStep::normalize([
            'description' => 'x',
            'type_treatment' => strtoupper($treatment),
            'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
        ], 'cinematic-safe-zone', '', warnings: $warnings);

        assert_eq($treatment, $direction['type_treatment']);
        assert_eq([], $warnings);
        $rendered = DesignDirectionStep::format($direction);
        assert_contains('**Type treatment**: ' . $treatment, $rendered);
        assert_contains(TypeTreatment::typography($treatment)['letterSpacing'], $rendered);
        assert_contains('preserve its lineHeight', $rendered);
    }
});

test('invalid type treatment degrades to sentence with actionable evidence', function () {
    foreach (['small-caps', ['title'], true] as $authored) {
        $warnings = [];
        $direction = DesignDirectionStep::normalize([
            'description' => 'x',
            'type_treatment' => $authored,
            'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
        ], 'cinematic-safe-zone', '', warnings: $warnings);

        assert_eq(TypeTreatment::DEFAULT, $direction['type_treatment']);
        assert_eq(1, count($warnings));
        assert_contains('field type_treatment', $warnings[0]);
        assert_contains('delivered "sentence"', $warnings[0]);
        assert_contains('disposition', $warnings[0]);
    }
});

test('normalize commits a card style: bounded values pass through and missing defaults without warning', function () {
    assert_eq('framed', DesignDirectionStep::normalize(['description' => 'x', 'card_style' => ' Framed '], 'cinematic-safe-zone')['card_style']);
    assert_eq('overlap', DesignDirectionStep::normalize(['description' => 'x', 'card_style' => 'overlap'], 'cinematic-safe-zone')['card_style']);
    assert_eq('borderless', DesignDirectionStep::normalize(['description' => 'x', 'card_style' => 'borderless'], 'cinematic-safe-zone')['card_style']);

    $missingRepairs = [];
    $missingWarnings = [];
    $missing = DesignDirectionStep::normalize(
        ['description' => 'x'],
        'cinematic-safe-zone',
        '',
        $missingRepairs,
        $missingWarnings,
    );
    assert_eq('flush', $missing['card_style']);
    assert_eq([], designdir_card_rows($missingRepairs));
    assert_eq([], designdir_card_rows($missingWarnings));

    $emptyWarnings = [];
    DesignDirectionStep::normalize(
        ['description' => 'x', 'card_style' => '   '],
        'cinematic-safe-zone',
        '',
        $missingRepairs,
        $emptyWarnings,
    );
    assert_eq(
        [],
        designdir_card_rows($emptyWarnings),
        'an empty optional commitment behaves like a missing one',
    );
});

test('normalizeCardStyle is the canonical downstream card-style normalizer', function () {
    $warnings = [];
    assert_eq('framed', DesignDirectionStep::normalizeCardStyle(' Framed ', $warnings));
    assert_eq([], $warnings);

    assert_eq('flush', DesignDirectionStep::normalizeCardStyle(null, $warnings));
    assert_eq('flush', DesignDirectionStep::normalizeCardStyle('   ', $warnings));
    assert_eq([], $warnings, 'missing and blank commitments use the documented default silently');

    assert_eq('flush', DesignDirectionStep::normalizeCardStyle(['framed'], $warnings));
    $cardWarnings = designdir_card_rows($warnings);
    assert_eq(1, count($cardWarnings));
    assert_contains('authored ["framed"]', $cardWarnings[0]);
    assert_contains('delivered "flush"', $cardWarnings[0]);
    assert_contains('disposition', $cardWarnings[0]);
});

test('normalize warns actionably when an invalid card style loses authored intent', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'card_style' => 'polaroid'],
        'cinematic-safe-zone',
        '',
        $repairs,
        $warnings,
    );
    assert_eq('flush', $direction['card_style']);
    assert_eq(
        [],
        designdir_card_rows($repairs),
        'a value-losing fallback is not reported as a successful repair',
    );
    $cardWarnings = designdir_card_rows($warnings);
    assert_eq(1, count($cardWarnings));
    foreach ([
        'designDirection.json',
        'field card_style',
        'authored "polaroid"',
        'delivered "flush"',
        'disposition',
    ] as $context) {
        assert_contains($context, $cardWarnings[0]);
    }

    $fixedPointRepairs = [];
    $fixedPointWarnings = [];
    $fixedPoint = DesignDirectionStep::normalize(
        ['description' => 'x', 'card_style' => $direction['card_style']],
        'cinematic-safe-zone',
        '',
        $fixedPointRepairs,
        $fixedPointWarnings,
    );
    assert_eq('flush', $fixedPoint['card_style']);
    assert_eq([], designdir_card_rows($fixedPointRepairs));
    assert_eq(
        [],
        designdir_card_rows($fixedPointWarnings),
        'the delivered value is a warning-free fixed point',
    );
});

test('normalize degrades object and list card styles without emitting PHP diagnostics', function () {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        foreach ([
            ['flush'],
            ['style' => 'framed'],
            (object) ['style' => 'overlap'],
        ] as $authored) {
            $repairs = [];
            $warnings = [];
            $direction = DesignDirectionStep::normalize(
                ['description' => 'x', 'card_style' => $authored],
                'cinematic-safe-zone',
                '',
                $repairs,
                $warnings,
            );

            assert_eq('flush', $direction['card_style']);
            assert_eq([], designdir_card_rows($repairs));
            $cardWarnings = designdir_card_rows($warnings);
            assert_eq(1, count($cardWarnings));
            assert_contains('field card_style authored', $cardWarnings[0]);
            assert_contains('delivered "flush"', $cardWarnings[0]);
            assert_contains('disposition', $cardWarnings[0]);
        }
    } finally {
        restore_error_handler();
    }
});

test('design-direction persists an invalid card style as a durable warning', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $direction = designdir_direction();
    $direction['card_style'] = 'polaroid';
    $llm->queueJson(['direction' => $direction]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq('flush', $project->readJson('designDirection.json')['card_style']);
    $warnings = implode(' ', $project->readJson('warnings.json')['design-direction'] ?? []);
    assert_contains('designDirection.json', $warnings);
    assert_contains('field card_style', $warnings);
    assert_contains('authored "polaroid"', $warnings);
    assert_contains('delivered "flush"', $warnings);
    assert_contains('disposition', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('format renders the card treatment with its executable meaning', function () {
    $flush = DesignDirectionStep::format(['description' => 'x', 'card_style' => 'flush']);
    assert_contains('**Card treatment**: flush', $flush);
    assert_contains('bleeds to the card edges', $flush);

    $framed = DesignDirectionStep::format(['description' => 'x', 'card_style' => 'framed']);
    assert_contains('**Card treatment**: framed', $framed);
    assert_contains('concentric corner radii', $framed);

    // Directions persisted before the field existed carry no card fact —
    // the section prompt's own default (flush) then applies.
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.']));
});

test('normalize and format commit one bounded render-time image treatment', function () {
    $base = [
        'description' => 'x',
        'image_grade' => 'monochrome documentary, lifted highlights, fine grain',
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ];
    foreach (['natural', 'duotone', 'tinted-overlay', 'high-key-bw'] as $treatment) {
        $warnings = [];
        $direction = DesignDirectionStep::normalize(
            $base + ['image_treatment' => strtoupper($treatment)],
            'cinematic-safe-zone',
            warnings: $warnings,
        );
        assert_eq($treatment, $direction['image_treatment']);
        assert_eq([], $warnings);
        $formatted = DesignDirectionStep::format($direction);
        assert_contains("**Image treatment**: {$treatment}", $formatted);
        assert_contains('Do not author a local duotone', $formatted);
    }

    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        $base + ['image_treatment' => 'sepia-ish'],
        'cinematic-safe-zone',
        warnings: $warnings,
    );
    assert_eq('natural', $direction['image_treatment']);
    assert_contains('field image_treatment', $warnings[0]);
    assert_contains('authored "sepia-ish"', $warnings[0]);
    assert_contains('delivered "natural"', $warnings[0]);
});

test('normalize commits every bounded image crop and warns on an unsupported system', function () {
    $base = [
        'description' => 'x',
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ];
    foreach (['landscape', 'portrait', 'square', 'panoramic', 'mixed'] as $crop) {
        $warnings = [];
        $direction = DesignDirectionStep::normalize(
            $base + ['image_crop' => strtoupper($crop)],
            'cinematic-safe-zone',
            '',
            warnings: $warnings,
        );
        assert_eq($crop, $direction['image_crop']);
        assert_eq([], $warnings, $crop);
    }

    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        $base + ['image_crop' => 'cinemascope-ish'],
        'cinematic-safe-zone',
        '',
        warnings: $warnings,
    );
    assert_eq('mixed', $direction['image_crop']);
    assert_contains('field image_crop', $warnings[0]);
    assert_contains('authored "cinemascope-ish"', $warnings[0]);
    assert_contains('delivered "mixed"', $warnings[0]);
    assert_contains('disposition ', $warnings[0]);
});

test('format renders image crop as an executable ratio contract', function () {
    foreach ([
        'landscape' => 'ordinary cards 3:2',
        'portrait' => 'ordinary cards and feature media 4:5',
        'square' => 'feature-media crop 1:1',
        'panoramic' => 'feature media 21:9',
        'mixed' => 'established per-role system',
    ] as $crop => $meaning) {
        $rendered = DesignDirectionStep::format(['description' => 'x', 'image_crop' => $crop]);
        assert_contains("**Image crop**: {$crop}", $rendered);
        assert_contains($meaning, $rendered);
        assert_contains('do not author an aspect ratio', $rendered);
    }
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.']));
});

test('normalize commits every bounded depth and warns on an unsupported treatment', function () {
    $base = [
        'description' => 'x',
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ];
    foreach (['flat', 'soft', 'hard-offset', 'inset', 'glow'] as $depth) {
        $warnings = [];
        $direction = DesignDirectionStep::normalize(
            $base + ['depth' => strtoupper($depth)],
            'cinematic-safe-zone',
            '',
            warnings: $warnings,
        );
        assert_eq($depth, $direction['depth']);
        assert_eq([], $warnings, $depth);
    }

    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        $base + ['depth' => 'floaty'],
        'cinematic-safe-zone',
        '',
        warnings: $warnings,
    );
    assert_eq('flat', $direction['depth']);
    assert_contains('field depth', $warnings[0]);
    assert_contains('authored "floaty"', $warnings[0]);
    assert_contains('delivered "flat"', $warnings[0]);
    assert_contains('disposition ', $warnings[0]);
});

test('format renders depth as an executable build-owned fact', function () {
    foreach ([
        'flat' => 'deliberately shadowless',
        'soft' => 'restrained, diffuse lift',
        'hard-offset' => 'poster-like offset plate',
        'inset' => 'presses cards and contained media',
        'glow' => 'primary-colored luminous halo',
    ] as $depth => $meaning) {
        $rendered = DesignDirectionStep::format(['description' => 'x', 'depth' => $depth]);
        assert_contains("**Depth**: {$depth}", $rendered);
        assert_contains($meaning, $rendered);
        assert_contains('do not add another shadow', $rendered);
    }
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.']));
});

test('normalize commits a shape while valid normalization and a missing field stay silent', function () {
    $base = [
        'description' => 'x',
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ];
    foreach ([
        ['raw' => $base + ['shape' => ' Soft '], 'delivered' => 'soft'],
        ['raw' => $base + ['shape' => 'ROUND'], 'delivered' => 'round'],
        ['raw' => $base, 'delivered' => 'sharp'],
    ] as $case) {
        $repairs = [];
        $warnings = [];
        $direction = DesignDirectionStep::normalize(
            $case['raw'],
            'cinematic-safe-zone',
            '',
            $repairs,
            $warnings,
        );

        assert_eq($case['delivered'], $direction['shape']);
        assert_eq([], $repairs, 'valid shape normalization needs no repair evidence');
        assert_eq([], $warnings, 'valid or absent shape needs no durable warning');
    }
});

test('normalize warns actionably when invalid string and non-string shapes fall back to sharp', function () {
    $base = [
        'description' => 'x',
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ];
    foreach (['wavy', '   ', ['round'], true, 7, null] as $authored) {
        $repairs = [];
        $warnings = [];
        $direction = DesignDirectionStep::normalize(
            $base + ['shape' => $authored],
            'cinematic-safe-zone',
            '',
            $repairs,
            $warnings,
        );

        assert_eq('sharp', $direction['shape']);
        assert_eq([], $repairs, 'a fallback that loses an authored value is not a successful repair');
        assert_eq(1, count($warnings));
        foreach ([
            "file='designDirection.json'",
            'path="shape"',
            'authored=',
            'delivered="sharp"',
            'disposition=',
        ] as $context) {
            assert_contains($context, $warnings[0]);
        }
    }
});

test('design-direction persists invalid shape fallback evidence in warnings.json', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $direction = designdir_direction();
    $direction['shape'] = ['round'];
    $llm->queueJson(['direction' => $direction]);

    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq('sharp', $project->readJson('designDirection.json')['shape']);
    $shapeWarning = '';
    foreach ($project->readJson('warnings.json')['design-direction'] ?? [] as $warning) {
        if (str_contains($warning, 'path="shape"')) {
            $shapeWarning = $warning;
            break;
        }
    }
    foreach ([
        "file='designDirection.json'",
        'path="shape"',
        'authored=["round"]',
        'delivered="sharp"',
        'disposition=invalid corner language replaced by deterministic sharp fallback',
    ] as $context) {
        assert_contains($context, $shapeWarning);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('format renders the shape commitment with its executable meaning', function () {
    $sharp = DesignDirectionStep::format(['description' => 'x', 'shape' => 'sharp']);
    assert_contains('**Shape**: sharp', $sharp);
    assert_contains('contained media (`core/image`, `core/cover`, the media half of `core/media-text`) and buttons square', $sharp);
    assert_contains('Full-bleed media stays square', $sharp);
    assert_true(!str_contains($sharp, 'cards'), 'shape does not promise generic card geometry');

    $soft = DesignDirectionStep::format(['description' => 'x', 'shape' => 'soft']);
    assert_contains('**Shape**: soft', $soft);
    assert_contains('subtle corner radius', $soft);
    assert_contains('modest radius onto buttons', $soft);
    assert_contains('Full-bleed media stays square', $soft);

    $round = DesignDirectionStep::format(['description' => 'x', 'shape' => 'round']);
    assert_contains('**Shape**: round', $round);
    assert_contains('decisive corner radius', $round);
    assert_contains('pill-shaped buttons', $round);
    assert_contains('Full-bleed media stays square', $round);

    // Directions persisted before the field existed carry no shape fact,
    // and an unrecognized or wrong-typed value renders none rather than
    // guessing (or emitting an array-to-string warning).
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.']));
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.', 'shape' => 'wavy']));
    assert_eq('Just prose.', DesignDirectionStep::format(['description' => 'Just prose.', 'shape' => ['round']]));
});

test('normalize commits and renders one bounded modular type scale', function () {
    foreach (TypeScale::ALL as $scale) {
        $warnings = [];
        $direction = DesignDirectionStep::normalize([
            'description' => 'x',
            'type_scale' => strtoupper($scale),
            'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
        ], 'cinematic-safe-zone', '', warnings: $warnings);

        assert_eq($scale, $direction['type_scale']);
        assert_eq([], $warnings);
        $formatted = DesignDirectionStep::format($direction);
        assert_contains('**Type scale**: ' . $scale, $formatted);
        assert_contains('The build owns the six preset values', $formatted);
    }
});

test('invalid type scale degrades to classic with actionable evidence', function () {
    foreach (['heroic', ['editorial'], true] as $authored) {
        $warnings = [];
        $direction = DesignDirectionStep::normalize([
            'description' => 'x',
            'type_scale' => $authored,
            'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
        ], 'cinematic-safe-zone', '', warnings: $warnings);

        assert_eq(TypeScale::DEFAULT, $direction['type_scale']);
        assert_eq(1, count($warnings));
        assert_contains('field type_scale', $warnings[0]);
        assert_contains('delivered "classic"', $warnings[0]);
        assert_contains('disposition', $warnings[0]);
    }
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
    assert_eq('flush', $direction['card_style']);
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
    assert_eq(Measure::DEFAULT, $generic['measure']);
    assert_eq(TypeTreatment::DEFAULT, $generic['type_treatment']);
    assert_eq('calm', $generic['motion']);
    assert_eq('natural', $generic['image_treatment']);
    assert_eq('mixed', $generic['image_crop']);
    assert_eq('flat', $generic['depth']);
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

test('cardStyleFor adapts persisted direction data through the canonical normalizer', function () {
    [$project, , $tmp] = make_designdir_fixture();
    $warnings = [];
    assert_eq('flush', DesignDirectionStep::cardStyleFor($project, $warnings));
    assert_eq([], $warnings);

    $project->writeJson('designDirection.json', ['card_style' => ' Overlap ']);
    assert_eq('overlap', DesignDirectionStep::cardStyleFor($project, $warnings));
    assert_eq([], $warnings);

    $project->writeJson('designDirection.json', ['card_style' => 'polaroid']);
    assert_eq('flush', DesignDirectionStep::cardStyleFor($project, $warnings));
    $cardWarnings = designdir_card_rows($warnings);
    assert_eq(1, count($cardWarnings));
    assert_contains('authored "polaroid"', $cardWarnings[0]);
    assert_contains('delivered "flush"', $cardWarnings[0]);
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
                'family' => 'Spectral',
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
        'hero_blueprint' => \Automattic\SiteBuild\HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ]);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer))->run($project);

    assert_contains('Editorial-magazine', $llm->calls[0]['prompt']);
    assert_contains('base #FDF6EC', $llm->calls[0]['prompt'], 'structured palette reaches the theme prompt');
    assert_contains(
        'heading — Spectral; weights 900',
        $llm->calls[0]['prompt'],
        'structured type reaches the theme prompt',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('normalize commits a motion profile: valid values pass, anything else defaults', function () {
    assert_eq('dramatic', DesignDirectionStep::normalize(['description' => 'x', 'motion' => ' Dramatic '], 'cinematic-safe-zone')['motion']);
    assert_eq('none', DesignDirectionStep::normalize(['description' => 'x', 'motion' => 'none'], 'cinematic-safe-zone')['motion']);
    assert_eq('calm', DesignDirectionStep::normalize(['description' => 'x'], 'cinematic-safe-zone')['motion'], 'missing → default');
    assert_eq('calm', DesignDirectionStep::normalize(['description' => 'x', 'motion' => 'bouncy'], 'cinematic-safe-zone')['motion'], 'unknown → default');
    assert_eq('calm', DesignDirectionStep::normalize(['description' => 'x', 'motion' => ['calm']], 'cinematic-safe-zone')['motion'], 'non-string → default');
    $mapped = DesignDirectionStep::normalize([
        'description' => 'x',
        'motion' => 'calm',
        'motion_note' => ['hover-lift'],
    ], 'cinematic-safe-zone');
    assert_eq(['hover-lift'], $mapped['motion_note'], 'a named kit class survives as the persisted list');
    $roundTripRepairs = [];
    $roundTripWarnings = [];
    $roundTrip = DesignDirectionStep::normalize(
        $mapped,
        'cinematic-safe-zone',
        $mapped['concept_seed'],
        $roundTripRepairs,
        $roundTripWarnings,
    );
    assert_eq(
        json_encode($mapped, JSON_THROW_ON_ERROR),
        json_encode($roundTrip, JSON_THROW_ON_ERROR),
        'a mapped note is a warning-free fixed point',
    );
    assert_eq([], $roundTripRepairs);
    assert_eq([], $roundTripWarnings);
    assert_eq(
        [],
        DesignDirectionStep::normalize(['description' => 'x', 'motion_note' => ' a note '], 'cinematic-safe-zone')['motion_note'],
        'prose names no class',
    );

    $repairs = [];
    $warnings = [];
    $unusable = DesignDirectionStep::normalize([
        'description' => 'x',
        'motion' => 'calm',
        'motion_note' => 42,
    ], 'cinematic-safe-zone', '', $repairs, $warnings);
    assert_eq([], $unusable['motion_note']);
    assert_eq([], $repairs, 'type loss is a degradation, not a successful repair');
    $motionWarnings = array_values(array_filter(
        $warnings,
        static fn (string $warning): bool => str_contains($warning, 'path="motion_note"'),
    ));
    assert_eq(1, count($motionWarnings));
    foreach ([
        "file='designDirection.json'",
        'path="motion_note"',
        'authored=42',
        'delivered=[]',
        'disposition=motion note was neither a class list nor a string and was removed',
    ] as $context) {
        assert_contains($context, $motionWarnings[0]);
    }

    // A list carrying a class the profile cannot ship keeps the rest and says
    // what it dropped, rather than discarding the whole commitment.
    $partialWarnings = [];
    $partialRepairs = [];
    $partial = DesignDirectionStep::normalize([
        'description' => 'x',
        'motion' => 'minimal',
        'motion_note' => ['hover-lift', 'ken-burns'],
    ], 'cinematic-safe-zone', '', $partialRepairs, $partialWarnings);
    assert_eq(['hover-lift'], $partial['motion_note']);
    assert_contains('ken-burns', implode(' ', $partialWarnings));
});

test('format renders the motion commitment with its executable meaning', function () {
    $calm = DesignDirectionStep::format(['description' => 'x', 'motion' => 'calm', 'motion_note' => ['ken-burns']]);
    assert_contains('**Motion**: calm', $calm);
    assert_contains('Use kit classes: ken-burns.', $calm);

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

    putenv('HERO_RECIPE=foreground-split');
    try {
        (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    } finally {
        putenv('HERO_RECIPE');
    }

    $direction = $project->readJson('designDirection.json');
    assert_eq('foreground-split', $direction['hero_blueprint']['recipe']);
    assert_eq('foreground-image', $direction['hero_blueprint']['media_mode']);
    assert_contains('foreground-split', $llm->calls[1]['prompt']);
    foreach (HeroComposition::RECIPES as $recipe) {
        if ($recipe !== 'foreground-split') {
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
        'design_constraints' => ['allowed_hero_media_modes' => ['cover-image']],
    ]);
    putenv('HERO_RECIPE=foreground-split');
    try {
        $e = assert_throws(fn () => (new DesignDirectionStep(
            $llm,
            new PromptRenderer(repo_path('prompts')),
        ))->run($project));
        assert_contains('incompatible with caller-owned design_constraints', $e->getMessage());
    } finally {
        putenv('HERO_RECIPE');
    }
    assert_eq(0, count($llm->calls));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('caller-incompatible HEADER_ARCHETYPE fails before the design-direction seed call', function () {
    $cases = [
        ['override' => 'not-a-header', 'constraints' => [],
            'message' => "unknown HEADER_ARCHETYPE 'not-a-header'"],
        ['override' => 'minimal-overlay', 'constraints' => ['hero_canvas' => 'framed'],
            'message' => "hero_canvas='framed'"],
        ['override' => 'minimal-overlay', 'constraints' => ['allowed_hero_media_modes' => ['foreground-image']],
            'message' => 'no compatible cover/overlay hero recipe remains'],
        ['override' => 'split-nav', 'constraints' => [],
            'message' => 'one-page scope'],
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
            $e = assert_throws(fn () => (new DesignDirectionStep(
                $llm,
                new PromptRenderer(repo_path('prompts')),
            ))->run($project), (string) $case['override']);
            assert_contains($case['message'], $e->getMessage(), (string) $case['override']);
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
        'design_constraints' => ['allowed_hero_media_modes' => ['cover-image']],
        'hero_assignment' => ['source' => 'batch', 'requested_recipe' => 'foreground-split'],
    ]);
    $llm->queueJson(['seeds' => designdir_seeds()]);
    $llm->queueJson(['direction' => designdir_direction()]);
    (new DesignDirectionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $direction = $project->readJson('designDirection.json');
    $delivered = $direction['hero_blueprint']['recipe'];
    assert_true(
        in_array($delivered, ['cinematic-safe-zone', 'layered-poster'], true),
        'foreground request remapped inside the cover-image pool',
    );
    $joined = implode(' ', $project->readJson('warnings.json')['design-direction'] ?? []);
    assert_contains('foreground-split', $joined);
    assert_contains($delivered, $joined);
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

    $blueprint = HeroBlueprint::defaultFor('foreground-split');
    $project->writeJson('designDirection.json', [
        'description' => 'A quiet gallery language with warm mineral color.',
        'hero_blueprint' => $blueprint,
        'concept_seed' => 'Hidden seed bytes',
    ]);
    assert_eq($blueprint, DesignDirectionStep::heroBlueprintFor($project));
    $focused = DesignDirectionStep::formatHeroBlueprint($blueprint);
    assert_contains('Front-page hero blueprint (front page only)', $focused);
    assert_contains('foreground-split', $focused);
    $general = DesignDirectionStep::readFor($project);
    assert_true(!str_contains($general, 'foreground-split'));
    assert_true(!str_contains($general, 'Hidden seed bytes'));

    $project->writeJson('designDirection.json', [
        'description' => 'Corrupt persisted blueprint.',
        'hero_blueprint' => ['recipe' => 'not-in-the-catalog'],
    ]);
    assert_throws(fn () => DesignDirectionStep::heroBlueprintFor($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('legacy signature-device fields are dropped from the normalized direction', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize([
        'description' => 'A complete visual direction.',
        'signature_device' => 'One notched color block.',
        'signature_device_slots' => ['hero'],
        'hero_blueprint' => array_merge(HeroBlueprint::defaultFor('foreground-split'), [
            'signature_device_use' => 'Place the notch beside the headline.',
        ]),
    ], 'foreground-split', 'Seed bytes', $repairs, $warnings);
    assert_true(!array_key_exists('signature_device', $direction));
    assert_true(!array_key_exists('signature_device_slots', $direction));
    assert_true(!array_key_exists('signature_device_use', $direction['hero_blueprint']));
});

test('automatic hero selection keeps the image gate aligned with each catalog media budget (BIGR-885)', function () {
    // The catalog holds no imageless recipe since the type-manifesto removal.
    // The invariant that survives it: the image gate always agrees with the
    // selected recipe's own budget, so an unconstrained build never generates
    // an orphan image and never leaves an image-bearing recipe without one.
    $imageless = array_values(array_filter(
        Automattic\SiteBuild\HeroComposition::RECIPES,
        fn (string $recipe): bool
            => (int) Automattic\SiteBuild\HeroComposition::metadata($recipe)['max_images'] === 0,
    ));
    assert_eq([], $imageless);

    $selected = [];
    foreach (range(1, 16) as $i) {
        $w = [];
        $recipe = DesignDirectionStep::selectHeroRecipe([], "gate-site-{$i}", 'Committed seed', $w);
        $meta = Automattic\SiteBuild\HeroComposition::metadata($recipe);
        assert_eq(
            (int) $meta['min_images'] >= 1,
            Automattic\SiteBuild\HeroComposition::usesGeneratedImages($recipe),
            $recipe,
        );
        assert_eq(
            (int) $meta['min_images'] >= 1,
            Automattic\SiteBuild\HeroComposition::usesGeneratedImages(
                HeroBlueprint::defaultFor($recipe),
            ),
            $recipe,
        );
        assert_eq([], $w);
        $selected[$recipe] = true;
    }
    assert_true(count($selected) > 1, 'unconstrained selection spreads across the catalog');
});

test('shapeFor returns only an explicit valid commitment', function () {
    $tmp = sys_get_temp_dir() . '/builder_ddshape_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq(null, DesignDirectionStep::shapeFor($project), 'no direction file');

    $project->writeJson('designDirection.json', ['description' => 'x']);
    assert_eq(null, DesignDirectionStep::shapeFor($project), 'direction predates the field');

    $project->writeJson('designDirection.json', ['description' => 'x', 'shape' => ['round']]);
    assert_eq(null, DesignDirectionStep::shapeFor($project), 'garbled persisted shape is not guessed');

    $project->writeJson('designDirection.json', ['description' => 'x', 'shape' => ' Round ']);
    assert_eq('round', DesignDirectionStep::shapeFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('depthFor returns only an explicit valid commitment', function () {
    $tmp = sys_get_temp_dir() . '/builder_dddepth_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq(null, DesignDirectionStep::depthFor($project));
    $project->writeJson('designDirection.json', ['description' => 'x']);
    assert_eq(null, DesignDirectionStep::depthFor($project), 'pre-field direction stays uncommitted');
    $project->writeJson('designDirection.json', ['description' => 'x', 'depth' => ['soft']]);
    assert_eq(null, DesignDirectionStep::depthFor($project), 'garbled value is not guessed');
    $project->writeJson('designDirection.json', ['description' => 'x', 'depth' => ' Hard-Offset ']);
    assert_eq('hard-offset', DesignDirectionStep::depthFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('measureFor returns only an explicit valid persisted commitment', function () {
    $tmp = sys_get_temp_dir() . '/builder_ddmeasure_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq(null, DesignDirectionStep::measureFor($project));
    $project->writeJson('designDirection.json', ['measure' => ['wide']]);
    assert_eq(null, DesignDirectionStep::measureFor($project));
    $project->writeJson('designDirection.json', ['measure' => ' Full ']);
    assert_eq('full', DesignDirectionStep::measureFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('imageTreatmentFor returns only an explicit valid commitment', function () {
    $tmp = sys_get_temp_dir() . '/builder_ddtreatment_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq(null, DesignDirectionStep::imageTreatmentFor($project));
    $project->writeJson('designDirection.json', ['description' => 'x']);
    assert_eq(null, DesignDirectionStep::imageTreatmentFor($project), 'pre-field direction stays uncommitted');
    $project->writeJson('designDirection.json', ['description' => 'x', 'image_treatment' => ['duotone']]);
    assert_eq(null, DesignDirectionStep::imageTreatmentFor($project), 'garbled value is not guessed');
    $project->writeJson('designDirection.json', ['description' => 'x', 'image_treatment' => ' Duotone ']);
    assert_eq('duotone', DesignDirectionStep::imageTreatmentFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('typeTreatmentFor returns only an explicit valid persisted commitment', function () {
    $tmp = sys_get_temp_dir() . '/builder_ddtypetreatment_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq(null, DesignDirectionStep::typeTreatmentFor($project));
    $project->writeJson('designDirection.json', ['type_treatment' => ['title']]);
    assert_eq(null, DesignDirectionStep::typeTreatmentFor($project));
    $project->writeJson('designDirection.json', ['type_treatment' => ' Caps-Tracked ']);
    assert_eq('caps-tracked', DesignDirectionStep::typeTreatmentFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('imageCropFor returns only an explicit valid commitment', function () {
    $tmp = sys_get_temp_dir() . '/builder_ddcrop_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq(null, DesignDirectionStep::imageCropFor($project));
    $project->writeJson('designDirection.json', ['description' => 'x']);
    assert_eq(null, DesignDirectionStep::imageCropFor($project), 'pre-field direction stays uncommitted');
    $project->writeJson('designDirection.json', ['description' => 'x', 'image_crop' => ['portrait']]);
    assert_eq(null, DesignDirectionStep::imageCropFor($project), 'garbled value is not guessed');
    $project->writeJson('designDirection.json', ['description' => 'x', 'image_crop' => ' Panoramic ']);
    assert_eq('panoramic', DesignDirectionStep::imageCropFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('typeScaleFor returns only an explicit valid commitment', function () {
    $tmp = sys_get_temp_dir() . '/builder_ddtypescale_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    assert_eq(null, DesignDirectionStep::typeScaleFor($project));
    $project->writeJson('designDirection.json', ['type_scale' => ['editorial']]);
    assert_eq(null, DesignDirectionStep::typeScaleFor($project));
    $project->writeJson('designDirection.json', ['type_scale' => ' Dramatic ']);
    assert_eq('dramatic', DesignDirectionStep::typeScaleFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('CTA style normalizes actionably, renders executable meaning, and accessor fails closed', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'cta_style' => 'pill',
            'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone')],
        'cinematic-safe-zone',
        '',
        $repairs,
        $warnings,
    );
    assert_eq('solid', $direction['cta_style']);
    assert_eq([], $repairs);
    assert_eq(1, count($warnings));
    foreach (['field cta_style', 'pill', 'delivered "solid"', 'invalid CTA construction'] as $part) {
        assert_contains($part, $warnings[0]);
    }

    $formatted = DesignDirectionStep::format(['description' => 'x', 'cta_style' => 'ghost-arrow']);
    assert_contains('**CTA style**: ghost-arrow', $formatted);
    assert_contains('arrow glyph', $formatted);
    assert_contains('do not restyle those per button', $formatted);

    $tmp = sys_get_temp_dir() . '/builder_cta_accessor_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    assert_eq(null, DesignDirectionStep::ctaStyleFor($project));
    $project->writeJson('designDirection.json', ['cta_style' => ['block']]);
    assert_eq(null, DesignDirectionStep::ctaStyleFor($project));
    $project->writeJson('designDirection.json', ['cta_style' => ' Block ']);
    assert_eq('block', DesignDirectionStep::ctaStyleFor($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('normalize commits a catalog surface and falls unknown textures back to none', function () {
    $direction = DesignDirectionStep::normalize([
        'description' => 'Paper ground.',
        'surface' => 'Paper',
    ], 'cinematic-safe-zone');
    assert_eq('paper', $direction['surface']);
    assert_contains('**Surface**: paper', DesignDirectionStep::format($direction));

    $warnings = [];
    $none = DesignDirectionStep::normalizeSurface('kraft', $warnings);
    assert_eq('none', $none);
    assert_contains('unsupported texture', implode(' ', $warnings));
    assert_eq('none', DesignDirectionStep::normalizeSurface(null));
});

test('normalize commits a catalog device', function () {
    $direction = DesignDirectionStep::normalize([
        'description' => 'A stamp on the menu.',
        'device' => 'stamp',
    ], 'cinematic-safe-zone');
    assert_eq('stamp', $direction['device']);
    assert_contains('device--stamp', DesignDirectionStep::format($direction));

    $warnings = [];
    assert_eq('none', DesignDirectionStep::normalizeDevice('twine', $warnings));
    assert_contains('unbuildable motif', implode(' ', $warnings));
});

test('the direction description is never edited to remove motif words', function () {
    // Deleting motif words mid-sentence left broken English in the text every
    // downstream prompt reads through format(): "Kraft labels with twine and
    // tape corners on the loaf" came out as "Kraft labels with and on the
    // loaf". prompts/design-direction.md already says twine and tape are not
    // devices, so it owns the rule and the description ships as authored.
    $authored = 'Kraft labels with twine and tape corners on the loaf.';
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize([
        'description' => $authored,
        'device' => 'none',
    ], 'cinematic-safe-zone', '', $repairs, $warnings);

    assert_eq($authored, $direction['description'], 'the sentence stays readable');
    assert_true(
        !str_contains(implode(' ', $warnings), 'unbuildable motif phrases removed'),
        'no removal is claimed',
    );
});

test('a description naming the accent font is left alone', function () {
    // The removal list carried "rotated caveat", and Caveat is a real font a
    // direction can commit to (#290). A direction that used it in the
    // narrative had the phrase deleted and was warned it was unbuildable.
    $authored = 'Flavor labels in a rotated Caveat, hand-written on kraft.';
    $direction = DesignDirectionStep::normalize([
        'description' => $authored,
        'device' => 'none',
    ], 'cinematic-safe-zone');
    assert_eq($authored, $direction['description']);
});

test('directionFor returns nothing when no direction was committed', function () {
    $project = new Project(sys_get_temp_dir() . '/design-direction-absent-' . uniqid());
    try {
        assert_eq([], DesignDirectionStep::dataFor($project));
    } finally {
        exec('rm -rf ' . escapeshellarg($project->root));
    }
});

test('normalize commits optional accent type and leaves an empty accent valid', function () {
    $prompt = file_get_contents(repo_path('prompts/design-direction.md')) ?: '';
    assert_eq(1, preg_match(
        '/"accent":\s*\{\s*"family":\s*"",\s*"weights":\s*\[\],\s*'
            . '"italic":\s*false,\s*"axes":\s*\{\},\s*"character":\s*""\s*\}/',
        $prompt,
    ), 'the empty-accent prompt example is warning-free');

    $withAccent = DesignDirectionStep::normalize([
        'description' => 'Caveat on flavor labels.',
        'type' => [
            'heading' => ['family' => 'Oswald', 'weights' => [700], 'italic' => false, 'axes' => [], 'character' => ''],
            'body' => ['family' => 'Source Sans 3', 'weights' => [400], 'italic' => false, 'axes' => [], 'character' => ''],
            'accent' => ['family' => 'Caveat', 'weights' => [400, 700], 'italic' => false, 'axes' => [], 'character' => 'hand labels'],
        ],
    ], 'cinematic-safe-zone');

    assert_eq('Caveat', $withAccent['type']['accent']['family']);
    assert_eq([400, 700], $withAccent['type']['accent']['weights']);
    assert_contains('accent — Caveat', DesignDirectionStep::format($withAccent));

    $repairs = [];
    $warnings = [];
    $empty = DesignDirectionStep::normalize([
        'description' => 'Two faces only.',
        'type' => [
            'heading' => ['family' => 'Oswald', 'weights' => [700], 'italic' => false, 'axes' => [], 'character' => ''],
            'body' => ['family' => 'Source Sans 3', 'weights' => [400], 'italic' => false, 'axes' => [], 'character' => ''],
            'accent' => ['family' => '', 'weights' => [], 'italic' => false, 'axes' => [], 'character' => ''],
        ],
        'hero_blueprint' => HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ], 'cinematic-safe-zone', '', $repairs, $warnings);
    assert_eq('', $empty['type']['accent']['family']);
    assert_true(!str_contains(DesignDirectionStep::format($empty), 'accent —'));
    assert_eq([], $warnings, 'valid empty accent emits no durable warning');
});

test('color economy is normalized, formatted, and read with a restrained default', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize([
        'description' => 'A deliberately one-family palette.',
        'color_economy' => 'multicolor',
    ], 'cinematic-safe-zone', '', $repairs, $warnings, '', '', 'monochrome');
    assert_eq('monochrome', $direction['color_economy'], 'the chosen seed outranks expansion drift');
    assert_contains('**Color economy**: monochrome', DesignDirectionStep::format($direction));

    $default = DesignDirectionStep::normalize(['description' => 'An old artifact shape.']);
    assert_eq('single-accent', $default['color_economy'], 'missing fields receive the restrained default');

    $invalidWarnings = [];
    $invalidRepairs = [];
    $invalid = DesignDirectionStep::normalize(
        ['description' => 'x', 'color_economy' => 'rainbow'],
        'cinematic-safe-zone',
        '',
        $invalidRepairs,
        $invalidWarnings,
    );
    assert_eq('single-accent', $invalid['color_economy']);
    assert_contains('color_economy', implode(' ', $invalidWarnings));

    with_project('builder_color_economy_', function ($project): void {
        assert_eq('single-accent', DesignDirectionStep::colorEconomyFor($project));
        $project->writeJson('designDirection.json', ['color_economy' => 'multicolor']);
        assert_eq('multicolor', DesignDirectionStep::colorEconomyFor($project));
    });
});
