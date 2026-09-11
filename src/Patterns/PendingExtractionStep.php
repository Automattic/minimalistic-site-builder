<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * A stage whose contract is settled but whose implementation has not landed.
 *
 * The pattern composition is being assembled from behaviour that currently
 * lives elsewhere, and that behaviour arrives one stage at a time. A graph
 * that simply omitted the missing stages would validate and run, and would
 * quietly produce a bundle with no content in it — the failure mode that is
 * expensive to notice, because the build reports success.
 *
 * So the stage is present from the start, declaring exactly which artifacts it
 * reads and writes, and refuses to run. The graph therefore validates against
 * the real contract, `--until` can stop before it, and a caller who reaches it
 * is told which stage is missing rather than handed an empty result.
 */
final class PendingExtractionStep implements Step
{
    /**
     * @param list<string> $reads  Project-relative paths this stage will consume.
     * @param list<string> $writes Project-relative paths this stage will produce.
     */
    public function __construct(
        private string $id,
        private string $label,
        private array $reads,
        private array $writes,
        private string $source,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label . ' (not yet extracted)';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id,
            label: $this->label(),
            reads: $this->reads,
            writes: $this->writes,
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        throw new \RuntimeException(sprintf(
            'Stage "%s" is not implemented yet. Its behaviour is being extracted from %s. '
            . 'Stop before it with --until, or run a fixture that supplies %s.',
            $this->id,
            $this->source,
            implode(', ', $this->writes),
        ));
    }
}
