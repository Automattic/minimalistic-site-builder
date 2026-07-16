<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\PresetReferences;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\ThemeValidator;

/**
 * Final deterministic build gate for contracts that downstream serializers
 * can invalidate: block structure, layout normalization, preset references,
 * and page-owned vertical rhythm.
 */
final class ValidateThemeStep implements Step
{
    private const LOG_FILE = 'validate-theme.log';

    public function id(): string
    {
        return 'validate-theme';
    }

    public function label(): string
    {
        return 'Validate final theme';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['theme/*', 'sections.json'],
            writes: [],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $problems = array_merge(
            ThemeValidator::validate($project),
            ThemeValidator::layoutWarnings($project),
            ThemeValidator::spacingWarnings($project),
            PresetReferences::problems($project),
        );
        $problems = array_values(array_unique($problems));

        if ($problems !== []) {
            $report = "Final theme validation failed:\n- " . implode("\n- ", $problems) . "\n";
            $project->writeText('logs/' . self::LOG_FILE, $report);
            throw new \RuntimeException(
                'validate-theme: ' . count($problems) . ' problem(s); see logs/' . self::LOG_FILE
            );
        }

        $project->writeText('logs/' . self::LOG_FILE, "Final theme validation passed.\n");
        echo "  final theme validation passed\n";
    }
}
