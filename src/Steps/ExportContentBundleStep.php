<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Patterns\ContentBundle;
use Automattic\SiteBuild\Patterns\PatternInputs;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/** Export portable content and media associations for an existing theme. */
final class ExportContentBundleStep implements Step
{
    public function id(): string { return 'export-content-bundle'; }
    public function label(): string { return 'Export content-only bundle'; }
    public function declaration(): StepDeclaration
    {
        return new StepDeclaration($this->id(), $this->label(),
            ['meta.json', 'pattern-inputs.json', 'pattern-output.json', 'media/*'], ['content-bundle.json'], false);
    }
    public function run(Project $project): void
    {
        $input = PatternInputs::read($project);
        $output = PatternInputs::retained($project, 'pattern-output.json', $input);
        foreach ($input['media'] as $index => $media) {
            if (!$project->exists($media['source']) || !is_file($project->path($media['source']))) {
                throw new \RuntimeException("Missing pattern media source: {$media['source']}");
            }
            $bytes = filesize($project->path($media['source']));
            $sha256 = hash_file('sha256', $project->path($media['source']));
            if ($bytes === false || $sha256 === false) {
                throw new \RuntimeException("Could not inspect pattern media source: {$media['source']}");
            }
            $input['media'][$index]['bytes'] = $bytes;
            $input['media'][$index]['sha256'] = $sha256;
        }
        $project->writeJsonAtomic('content-bundle.json', ContentBundle::build($input, $output));
    }
}
