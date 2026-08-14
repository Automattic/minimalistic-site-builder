<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array<string,mixed> */
function slice5_direction_reference(): array
{
    return [
        'url' => 'https://reference.example',
        'page_type' => 'landing',
        'owner_type' => 'other',
        'style' => 'Bold, high-contrast, playful',
        'colors' => [['hex' => '#ff90e8', 'name' => 'Candy pink', 'role' => 'accent']],
        'sections' => [[
            'category' => 'feature',
            'description' => 'Full-bleed color field with oversized headline',
        ]],
    ];
}

/** @return array{0:Project,1:FakeLlm,2:string} */
function slice5_direction_fixture(bool $withReference): array
{
    $tmp = sys_get_temp_dir() . '/builder_slice5_dd_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb',
        'visual_vibe' => 'warm and rustic',
    ]);
    $project->writeJson('inspiration.json', $withReference
        ? [
            'urls' => ['https://reference.example'],
            'references' => [slice5_direction_reference()],
        ]
        : ['urls' => [], 'references' => []]);
    return [$project, new FakeLlm(), $tmp];
}

/** @return array<string,mixed> */
function slice5_direction_response(): array
{
    return [
        'title' => 'Hearth & Grain',
        'description' => 'Editorial-magazine warmth: a fully described concept.',
        'palette' => [
            'base' => '#FDF6EC',
            'contrast' => '#26221E',
            'primary' => '#8A5A2B',
            'secondary' => '#CC9988',
            'accent' => '#E08A3C',
        ],
        'type' => [
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
        ],
        'image_grade' => 'warm kodachrome color, soft golden light',
        'card_style' => 'framed',
        'hero_blueprint' => HeroBlueprint::defaultFor('editorial-split'),
    ];
}

/** @return list<string> */
function slice5_direction_seeds(): array
{
    return ['Seed 1', 'Seed 2', 'Seed 3', 'Seed 4'];
}

function slice5_direction_run(Project $project, FakeLlm $llm): void
{
    (new DesignDirectionStep($llm, new PromptRenderer(Package::promptsDir())))->run($project);
}

test('design-direction declares inspiration.json as a read', function () {
    $step = new DesignDirectionStep(
        new FakeLlm(),
        new PromptRenderer(Package::promptsDir()),
    );
    assert_true(in_array('inspiration.json', $step->declaration()->reads, true));
});

test('disabled design-direction neither declares nor consumes inspiration', function () {
    $previous = getenv(DesignDirectionStep::CHOICE_ENV);
    putenv(DesignDirectionStep::CHOICE_ENV);
    [$project, $llm, $tmp] = slice5_direction_fixture(true);
    try {
        $llm->queueJson(['seeds' => slice5_direction_seeds()]);
        $llm->queueJson(['direction' => slice5_direction_response()]);
        $step = new DesignDirectionStep(
            llm: $llm,
            renderer: new PromptRenderer(Package::promptsDir()),
            useInspiration: false,
        );

        assert_eq(false, in_array('inspiration.json', $step->declaration()->reads, true));
        $step->run($project);
        assert_eq(2, count($llm->calls), 'disabled instance keeps seed + expansion calls');
        assert_eq(false, str_contains($llm->calls[1]['prompt'], 'UNTRUSTED REFERENCE DATA'));
    } finally {
        remove_tree($tmp);
        $previous === false
            ? putenv(DesignDirectionStep::CHOICE_ENV)
            : putenv(DesignDirectionStep::CHOICE_ENV . '=' . $previous);
    }
});

test('reference block follows site spec and skips direction brainstorming', function () {
    $previous = getenv(DesignDirectionStep::CHOICE_ENV);
    putenv(DesignDirectionStep::CHOICE_ENV);
    [$project, $llm, $tmp] = slice5_direction_fixture(true);
    try {
        $llm->queueJson(['direction' => slice5_direction_response()]);
        slice5_direction_run($project, $llm);

        assert_eq(1, count($llm->calls), 'reference grounding must save the seed call');
        $prompt = $llm->calls[0]['prompt'];
        $specOffset = strpos($prompt, 'Hearth & Crumb');
        $referenceOffset = strpos($prompt, 'BEGIN UNTRUSTED REFERENCE DATA');
        assert_true($specOffset !== false && $referenceOffset !== false && $specOffset < $referenceOffset);
        assert_contains('#ff90e8', $prompt);
        assert_contains('Ground this concept in the reference sites', $prompt);
    } finally {
        remove_tree($tmp);
        $previous === false
            ? putenv(DesignDirectionStep::CHOICE_ENV)
            : putenv(DesignDirectionStep::CHOICE_ENV . '=' . $previous);
    }
});

test('forced direction seed still wins when references exist', function () {
    $previous = getenv(DesignDirectionStep::CHOICE_ENV);
    putenv(DesignDirectionStep::CHOICE_ENV . '=2');
    [$project, $llm, $tmp] = slice5_direction_fixture(true);
    try {
        $llm->queueJson(['seeds' => slice5_direction_seeds()]);
        $llm->queueJson(['direction' => slice5_direction_response()]);
        slice5_direction_run($project, $llm);

        assert_eq(2, count($llm->calls), 'forced evaluation keeps seed + expansion calls');
        assert_contains('Seed 2', $llm->calls[1]['prompt']);
        assert_contains('BEGIN UNTRUSTED REFERENCE DATA', $llm->calls[1]['prompt']);
    } finally {
        remove_tree($tmp);
        $previous === false
            ? putenv(DesignDirectionStep::CHOICE_ENV)
            : putenv(DesignDirectionStep::CHOICE_ENV . '=' . $previous);
    }
});

test('no-reference direction prompt remains byte-identical to the pre-slice prompt', function () {
    $previous = getenv(DesignDirectionStep::CHOICE_ENV);
    putenv(DesignDirectionStep::CHOICE_ENV . '=1');
    [$project, $llm, $tmp] = slice5_direction_fixture(false);
    try {
        $llm->queueJson(['seeds' => slice5_direction_seeds()]);
        $llm->queueJson(['direction' => slice5_direction_response()]);
        slice5_direction_run($project, $llm);

        assert_eq(2, count($llm->calls));
        assert_eq(
            'a07309d9d38b4417715b1b371de9784cf74530d74f36ad6243817ee918091af0',
            hash('sha256', $llm->calls[1]['prompt']),
            'empty inspiration must add zero prompt bytes',
        );
    } finally {
        remove_tree($tmp);
        $previous === false
            ? putenv(DesignDirectionStep::CHOICE_ENV)
            : putenv(DesignDirectionStep::CHOICE_ENV . '=' . $previous);
    }
});
