<?php
declare(strict_types=1);

use Automattic\SiteBuild\BuildPipeline;
use Automattic\SiteBuild\FallbackBuildPipeline;
use Automattic\SiteBuild\MalformedDesignException;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

final class FallbackPipelineProbeStep implements Step
{
    public function __construct(private string $id) {}
    public function id(): string { return $this->id; }
    public function label(): string { return $this->id; }
    public function declaration(): StepDeclaration
    {
        return new StepDeclaration($this->id, $this->id, [], [], false);
    }
    public function run(Project $project): void {}
}

final class FallbackPipelineProbe implements BuildPipeline
{
    public function __construct(
        private Step $step,
        private ?Throwable $failure = null,
        private ?string $marker = null,
    ) {}

    public function stepIds(): array { return [$this->step->id()]; }
    public function stopIds(): array { return [$this->step->id()]; }

    public function runThrough(
        Project $project,
        ?string $untilId = null,
        ?callable $reporter = null,
        ?callable $onStart = null,
    ): void {
        if ($onStart !== null) {
            $onStart($this->step);
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->marker !== null) {
            $project->writeText($this->marker, $untilId ?? 'complete');
        }
        if ($reporter !== null) {
            $reporter($this->step, 0.0);
        }
    }
}

test('FallbackBuildPipeline reroutes only a malformed homepage after recording actionable context', function () {
    $tmp = sys_get_temp_dir() . '/builder_fallback_pipeline_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $primary = new FallbackPipelineProbe(
        new FallbackPipelineProbeStep('homepage-design'),
        new MalformedDesignException('missing style'),
    );
    $legacy = new FallbackPipelineProbe(
        new FallbackPipelineProbeStep('theme-json+page-plan'),
        marker: 'legacy-tail.txt',
    );
    $pipeline = new FallbackBuildPipeline($primary, $legacy);
    $started = [];

    $pipeline->runThrough(
        $project,
        'validate-theme',
        onStart: static function (Step $step) use (&$started): void {
            $started[] = $step->id();
        },
    );

    assert_eq(['homepage-design'], $pipeline->stepIds());
    assert_eq(['homepage-design'], $pipeline->stopIds());
    assert_eq(['homepage-design', 'theme-json+page-plan'], $started);
    assert_eq('validate-theme', $project->readText('legacy-tail.txt'));
    $warning = implode("\n", $project->readJson('warnings.json')['homepage-design'] ?? []);
    foreach (['design/home.html', 'homepage-design', 'legacy_reroute', 'legacy section pipeline'] as $needle) {
        assert_contains($needle, $warning);
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('FallbackBuildPipeline rethrows wrong-step malformed and non-malformed homepage failures', function () {
    $cases = [
        ['transform-site', new MalformedDesignException('wrong step')],
        ['homepage-design', new RuntimeException('operational failure')],
    ];

    foreach ($cases as [$id, $failure]) {
        $tmp = sys_get_temp_dir() . '/builder_fallback_rethrow_' . uniqid();
        $project = (new ProjectStore($tmp))->create('demo');
        $pipeline = new FallbackBuildPipeline(
            new FallbackPipelineProbe(new FallbackPipelineProbeStep($id), $failure),
            new FallbackPipelineProbe(
                new FallbackPipelineProbeStep('theme-json+page-plan'),
                marker: 'legacy-tail.txt',
            ),
        );
        $caught = null;
        try {
            $pipeline->runThrough($project);
        } catch (Throwable $error) {
            $caught = $error;
        }

        assert_true($caught === $failure, "{$id} failure rethrown unchanged");
        assert_true(!$project->exists('legacy-tail.txt'), "{$id} does not enter legacy tail");
        assert_true(!$project->exists('warnings.json'), "{$id} does not record reroute warning");
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
