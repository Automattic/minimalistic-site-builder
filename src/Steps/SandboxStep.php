<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TreeGraph\Sandbox;

/**
 * Tree graph step 1: a live WordPress to build into.
 *
 * Boots (or reconnects to) the project's detached Playground sandbox with the
 * msb-companion plugin mounted, and records it in sandbox.json. Every later
 * step reads that file to reach the companion routes and the harness page.
 * The sandbox outlives the build — it IS the finished site — and is stopped
 * explicitly via `php bin/sandbox.php <slug> --stop`.
 */
final class SandboxStep implements Step
{
    public function __construct(private readonly ?int $port = null) {}

    public function id(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Boot the sandbox WordPress';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json'],
            writes: ['sandbox.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $record = Sandbox::connect($project, $this->port);
        Narrator::write("  sandbox: {$record['url']} (pid {$record['pid']})\n");
    }
}
