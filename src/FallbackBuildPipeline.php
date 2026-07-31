<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Runs the HTML-first pipeline, then changes course only when homepage design
 * reports that its generated document cannot be used. The shared prefix has
 * already completed, so the fallback pipeline starts immediately after
 * design-direction and leaves those artifacts untouched.
 */
final class FallbackBuildPipeline implements BuildPipeline
{
    public function __construct(
        private BuildPipeline $primary,
        private BuildPipeline $legacyTail,
    ) {}

    public function stepIds(): array
    {
        return $this->primary->stepIds();
    }

    public function stopIds(): array
    {
        return $this->primary->stopIds();
    }

    public function runThrough(
        Project $project,
        ?string $untilId = null,
        ?callable $reporter = null,
        ?callable $onStart = null,
    ): void {
        $currentStep = null;
        $trackingStart = static function (Step $step) use (&$currentStep, $onStart): void {
            // A caller callback is not step execution. Keep it outside the
            // fallback catch even if it throws MalformedDesignException.
            $currentStep = null;
            if ($onStart !== null) {
                $onStart($step);
            }
            $currentStep = $step;
        };
        $trackingReporter = static function (Step $step, float $seconds) use (&$currentStep, $reporter): void {
            // The step completed. A reporter failure must not trigger generated
            // design fallback routing.
            $currentStep = null;
            if ($reporter !== null) {
                $reporter($step, $seconds);
            }
        };

        try {
            $this->primary->runThrough(
                $project,
                $untilId,
                $trackingReporter,
                $trackingStart,
            );
        } catch (MalformedDesignException $error) {
            if (!$currentStep instanceof Step || $currentStep->id() !== 'homepage-design') {
                throw $error;
            }

            $project->addWarnings('homepage-design', [
                'file design/home.html block_path homepage-design '
                    . 'authored_value HTML-first homepage design '
                    . 'delivered_value legacy section pipeline '
                    . 'disposition legacy_reroute; error ' . $error->getMessage(),
            ]);

            $this->legacyTail->runThrough($project, $untilId, $reporter, $onStart);
        }
    }
}
