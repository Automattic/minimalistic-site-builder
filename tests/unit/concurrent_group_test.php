<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConcurrentGroup;
use Automattic\SiteBuild\ConcurrentStep;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
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

    /** @param array<string,array<string,mixed>> $requests */
    public function __construct(private string $id, private array $requests) {}

    public function id(): string { return $this->id; }
    public function label(): string { return $this->id; }
    public function requests(Project $project): array { return $this->requests; }
    public function consume(Project $project, array $results): void { $this->consumed = $results; }
    public function run(Project $project): void {}
}

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
