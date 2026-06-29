<?php
declare(strict_types=1);

/**
 * Unit tests for Pipeline stop semantics: `--until` accepts a concurrent
 * group's member ids (not just the composite group id), and a run stops once
 * the matched step has run.
 */

/** A trivial Step that records that it ran, for ordering assertions. */
final class RecorderStep implements Step
{
    /** @var string[] */
    public static array $ran = [];

    public function __construct(private string $id) {}
    public function id(): string { return $this->id; }
    public function label(): string { return $this->id; }
    public function run(Project $project): void { self::$ran[] = $this->id; }
}

test('stopIds expands a concurrent group into its member ids', function () {
    $llm = new FakeLlm();
    $group = new ConcurrentGroup($llm, [
        new RecordingConcurrentStep('theme-json', ['out' => ['prompt' => 'P']]),
        new RecordingConcurrentStep('section-plan', ['out' => ['prompt' => 'P']]),
    ]);
    $pipeline = new Pipeline([new RecorderStep('site-spec'), $group, new RecorderStep('sections')]);

    assert_eq('theme-json+section-plan', $group->id());
    assert_eq(['site-spec', 'theme-json', 'section-plan', 'sections'], $pipeline->stopIds());
});

test('--until stops after a concurrent group when given a member id', function () {
    RecorderStep::$ran = [];
    $tmp = sys_get_temp_dir() . '/builder_pl_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    $llm = new FakeLlm();
    $llm->queueJson(['v' => 1]); // theme-json member
    $llm->queueJson(['v' => 2]); // section-plan member
    $group = new ConcurrentGroup($llm, [
        new RecordingConcurrentStep('theme-json', ['out' => ['prompt' => 'P']]),
        new RecordingConcurrentStep('section-plan', ['out' => ['prompt' => 'P']]),
    ]);
    $pipeline = new Pipeline([new RecorderStep('site-spec'), $group, new RecorderStep('sections')]);

    // Member id of the group — must stop after the whole group, not run 'sections'.
    $pipeline->runThrough($project, 'theme-json');

    assert_eq(['site-spec'], RecorderStep::$ran, 'stopped after the group; later step did not run');
    exec('rm -rf ' . escapeshellarg($tmp));
});
