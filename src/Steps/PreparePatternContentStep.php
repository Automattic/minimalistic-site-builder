<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Patterns\ApprovedPattern;
use Automattic\SiteBuild\Patterns\ContentPersonalizer;
use Automattic\SiteBuild\Patterns\PatternInputs;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

final class PreparePatternContentStep implements Step
{
    public function id(): string { return 'prepare-pattern-content'; }
    public function label(): string { return 'Prepare approved content slots'; }
    public function declaration(): StepDeclaration
    {
        return new StepDeclaration($this->id(), $this->label(), ['meta.json', 'pattern-inputs.json'], ['pattern-content.json'], false);
    }
    public function run(Project $project): void
    {
        $input = PatternInputs::read($project);
        $layouts = [];
        foreach ($input['layouts'] as $layout) {
            $layouts[$layout['id']] = ContentPersonalizer::prepare(new ApprovedPattern($layout['markup'], $layout['slots']), $input['facts']);
        }
        $project->writeJsonAtomic('pattern-content.json', [
            'version' => PatternInputs::VERSION, 'input_hash' => PatternInputs::fingerprint($input), 'layouts' => $layouts,
        ]);
    }
}
