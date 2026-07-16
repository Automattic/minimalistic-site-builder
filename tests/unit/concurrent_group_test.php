<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConcurrentGroup;
use Automattic\SiteBuild\ConcurrentStep;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * Unit tests for ConcurrentGroup: it merges its members' requests into one
 * batched call and routes each result back to the member that asked for it,
 * even when two members use the same local request key.
 */

/** A minimal ConcurrentStep that records what it consumes, for the test. */
final class RecordingConcurrentStep implements ConcurrentStep
{
    public array $consumed = [];

    /**
     * @param array<string,array<string,mixed>> $requests
     * @param list<string>                      $reads
     * @param list<string>                      $writes
     */
    public function __construct(
        private string $id,
        private array $requests,
        private array $reads = [],
        private array $writes = [],
    ) {}

    public function id(): string { return $this->id; }
    public function label(): string { return $this->id; }
    public function requests(Project $project): array { return $this->requests; }
    public function consume(Project $project, array $results): void { $this->consumed = $results; }
    public function run(Project $project): void {}
    public function declaration(): StepDeclaration
    {
        return new StepDeclaration($this->id, $this->id, $this->reads, $this->writes, false);
    }
}

test('ConcurrentGroup declaration unions member reads/writes and is concurrent', function () {
    $llm = new FakeLlm();
    $a = new RecordingConcurrentStep('theme-json', ['out' => ['prompt' => 'P']], ['meta.json'], ['theme/theme.json']);
    $b = new RecordingConcurrentStep('section-plan', ['out' => ['prompt' => 'P']], ['meta.json'], ['sections.json']);
    $g = new ConcurrentGroup($llm, [$a, $b]);

    $d = $g->declaration();
    assert_eq('theme-json+section-plan', $d->id);
    assert_eq(true, $d->concurrent);
    assert_eq(['meta.json'], $d->reads);
    $writes = $d->writes;
    sort($writes);
    assert_eq(['sections.json', 'theme/theme.json'], $writes);
    assert_eq(['theme-json', 'section-plan'], array_map(fn ($s) => $s->id(), $g->members()));
});

test('ConcurrentGroup merges requests and routes results back per member', function () {
    $tmp = sys_get_temp_dir() . '/builder_cg_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    // Both members use the SAME local key "out" — the group must not let them collide.
    $a = new RecordingConcurrentStep('alpha', ['out' => ['prompt' => 'PA']]);
    $b = new RecordingConcurrentStep('beta', ['out' => ['prompt' => 'PB']]);

    $llm = new FakeLlm();
    $llm->queueJson(['v' => 'A']); // alpha:out
    $llm->queueJson(['v' => 'B']); // beta:out

    (new ConcurrentGroup($llm, [$a, $b]))->run($project);

    assert_eq(['v' => 'A'], $a->consumed['out']);
    assert_eq(['v' => 'B'], $b->consumed['out']);
    assert_eq('alpha+beta', (new ConcurrentGroup($llm, [$a, $b]))->id());

    exec('rm -rf ' . escapeshellarg($tmp));
});
