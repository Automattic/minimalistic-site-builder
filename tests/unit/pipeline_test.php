<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConcurrentGroup;
use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\BuildPipeline;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Tests\FakeLlm;

test('Pipeline implements the frozen build runner contract', function () {
    $pipeline = new Pipeline([new RecorderStep('contract')]);

    assert_true($pipeline instanceof BuildPipeline);
});

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
    public function declaration(): StepDeclaration
    {
        return new StepDeclaration($this->id, $this->id, [], [], false);
    }
    public function run(Project $project): void { self::$ran[] = $this->id; }
}

test('Pipeline rejects a step list with unmet reads', function () {
    assert_throws(function () {
        new Pipeline([
            new class implements Step {
                public function id(): string { return 'sections'; }
                public function label(): string { return 'sections'; }
                public function declaration(): StepDeclaration
                {
                    return new StepDeclaration('sections', 'sections', ['sections.json'], ['theme/parts/*'], true);
                }
                public function run(Project $project): void {}
            },
        ]);
    });
});

test('stopIds expands a concurrent group into its member ids', function () {
    $llm = new FakeLlm();
    $group = new ConcurrentGroup($llm, [
        new RecordingConcurrentStep('theme-json', ['out' => ['prompt' => 'P']]),
        new RecordingConcurrentStep('page-plan', ['out' => ['prompt' => 'P']]),
    ]);
    $pipeline = new Pipeline([new RecorderStep('site-spec'), $group, new RecorderStep('sections')]);

    assert_eq('theme-json+page-plan', $group->id());
    assert_eq(['site-spec', 'theme-json', 'page-plan', 'sections'], $pipeline->stopIds());
});

test('--until stops after a concurrent group when given a member id', function () {
    RecorderStep::$ran = [];
    $tmp = sys_get_temp_dir() . '/builder_pl_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    $llm = new FakeLlm();
    $llm->queueJson(['v' => 1]); // theme-json member
    $llm->queueJson(['v' => 2]); // page-plan member
    $group = new ConcurrentGroup($llm, [
        new RecordingConcurrentStep('theme-json', ['out' => ['prompt' => 'P']]),
        new RecordingConcurrentStep('page-plan', ['out' => ['prompt' => 'P']]),
    ]);
    $pipeline = new Pipeline([new RecorderStep('site-spec'), $group, new RecorderStep('sections')]);

    // Member id of the group — must stop after the whole group, not run 'sections'.
    $pipeline->runThrough($project, 'theme-json');

    assert_eq(['site-spec'], RecorderStep::$ran, 'stopped after the group; later step did not run');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('--from skips every step that order-precedes the resume id', function () {
    RecorderStep::$ran = [];
    $tmp = sys_get_temp_dir() . '/builder_pl_from_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    $pipeline = new Pipeline([
        new RecorderStep('scaffold'),
        new RecorderStep('transform-site'),
        new RecorderStep('section-layout'),
        new RecorderStep('page-styles'),
    ]);

    // Resume at transform-site through page-styles: the two upstream steps are
    // assumed already materialized on disk and must not run.
    $pipeline->runThrough($project, 'page-styles', fromId: 'transform-site');

    assert_eq(
        ['transform-site', 'section-layout', 'page-styles'],
        RecorderStep::$ran,
        'ran the requested window only; upstream steps skipped',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('--from resumes at the whole group when given a member id', function () {
    RecorderStep::$ran = [];
    $tmp = sys_get_temp_dir() . '/builder_pl_from_group_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    $llm = new FakeLlm();
    $llm->queueJson(['v' => 1]); // theme-json member
    $llm->queueJson(['v' => 2]); // page-plan member
    $group = new ConcurrentGroup($llm, [
        new RecordingConcurrentStep('theme-json', ['out' => ['prompt' => 'P']]),
        new RecordingConcurrentStep('page-plan', ['out' => ['prompt' => 'P']]),
    ]);
    $pipeline = new Pipeline([new RecorderStep('site-spec'), $group, new RecorderStep('sections')]);

    // Member id of the group — starts at the whole group (site-spec is skipped),
    // mirroring how --until stops at the whole group for a member id.
    $pipeline->runThrough($project, fromId: 'page-plan');

    assert_eq(['sections'], RecorderStep::$ran, 'skipped site-spec, ran the group then sections');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('--from null preserves running from the first step', function () {
    RecorderStep::$ran = [];
    $tmp = sys_get_temp_dir() . '/builder_pl_from_null_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    $pipeline = new Pipeline([
        new RecorderStep('a'),
        new RecorderStep('b'),
        new RecorderStep('c'),
    ]);
    $pipeline->runThrough($project);

    assert_eq(['a', 'b', 'c'], RecorderStep::$ran, 'default runs the full graph');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('O-G8d top-level orchestration runs a concurrent group once while stopIds runs it twice', function () {
    $run = static function (bool $topLevel): int {
        $tmp = sys_get_temp_dir() . '/builder_pl_orchestration_' . uniqid();
        $project = (new ProjectStore($tmp))->create('demo');
        $llm = new FakeLlm();
        for ($i = 0; $i < ($topLevel ? 2 : 4); $i++) {
            $llm->queueJson(['v' => $i]);
        }
        $group = new ConcurrentGroup($llm, [
            new RecordingConcurrentStep('theme-json', ['out' => ['prompt' => 'P']]),
            new RecordingConcurrentStep('page-plan', ['out' => ['prompt' => 'P']]),
        ]);
        $pipeline = new Pipeline([$group]);
        $ids = $topLevel ? $pipeline->stepIds() : $pipeline->stopIds();

        try {
            foreach ($ids as $id) {
                $target = explode(ConcurrentGroup::ID_SEPARATOR, $id)[0];
                $pipeline->runThrough($project, $target, fromId: $target);
            }
            return $llm->completeJsonBatchCalls;
        } finally {
            remove_tree($tmp);
        }
    };

    assert_eq(1, $run(true), 'the emitted top-level list pays for the group once');
    assert_eq(2, $run(false), 'the expanded stopIds list pays for the whole group per member');
});
