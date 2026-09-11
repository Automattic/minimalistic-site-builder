<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Patterns\ApprovedPattern;
use Automattic\SiteBuild\Patterns\ContentPersonalizer;
use Automattic\SiteBuild\Patterns\PatternInputs;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Produces a content-only inspection artifact, not a destination blueprint.
 * Revalidates retained generated values per slot before atomic publication.
 */
final class SerializePatternContentStep implements Step
{
    public function id(): string { return 'serialize-pattern-content'; }
    public function label(): string { return 'Serialize approved pattern content'; }
    public function declaration(): StepDeclaration
    {
        return new StepDeclaration($this->id(), $this->label(),
            ['meta.json', 'pattern-inputs.json', 'pattern-values.json'], ['pattern-output.json', 'warnings.json'], false);
    }
    public function run(Project $project): void
    {
        $input = PatternInputs::read($project);
        $retained = PatternInputs::retained($project, 'pattern-values.json', $input);
        $output = [];
        $warnings = [];
        foreach ($input['layouts'] as $layout) {
            $id = $layout['id'];
            $pattern = new ApprovedPattern($layout['markup'], $layout['slots']);
            $values = $retained['layouts'][$id] ?? [];
            // Rebind facts at delivery: retained/model values cannot override them.
            $prepared = ContentPersonalizer::prepare($pattern, $input['facts']);
            $inventory = array_column($pattern->inventory(), null, 'id');
            if (is_array($values)) {
                foreach ($values as $slotId => $value) {
                    if (!isset($inventory[$slotId])
                        || (isset($prepared['values'][$slotId]) && $value !== $prepared['values'][$slotId])) {
                        $warnings[] = json_encode([
                            'file' => 'pattern-output.json', 'layout' => $id,
                            'block_path' => $inventory[$slotId]['block_path'] ?? [], 'slot' => $slotId,
                            'authored' => $value, 'delivered' => $prepared['values'][$slotId] ?? 'removed',
                            'disposition' => isset($inventory[$slotId]) ? 'restored deterministic binding' : 'undeclared retained slot removed',
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                }
            }
            $modelValues = [];
            foreach ($prepared['pending'] as $slot) {
                if (is_array($values) && array_key_exists($slot['id'], $values)) {
                    $modelValues[] = ['id' => $slot['id'], 'e' => $values[$slot['id']]];
                }
            }
            $validated = ContentPersonalizer::consume($prepared['pending'], ['content' => $modelValues]);
            $fallback = ContentPersonalizer::finish($validated['pending']);
            $delivered = $prepared['values'] + $validated['values'] + $fallback['values'];
            foreach ($fallback['warnings'] as $warning) {
                $warnings[] = json_encode(['file' => 'pattern-output.json', 'layout' => $id] + $warning, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $output[] = ['id' => $id, 'role' => $layout['role'], 'markup' => $pattern->serialize($delivered),
                'source_hash' => hash('sha256', $layout['markup'])];
        }
        $project->writeJsonAtomic('pattern-output.json', [
            'version' => PatternInputs::VERSION, 'input_hash' => PatternInputs::fingerprint($input),
            'brand' => $input['brand'], 'layouts' => $output,
        ]);
        $project->replaceWarnings($this->id(), $warnings);
    }
}
