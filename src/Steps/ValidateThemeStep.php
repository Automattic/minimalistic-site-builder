<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\PresetReferences;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\ThemeValidator;

/**
 * Final deterministic validation pass for contracts that downstream
 * serializers can invalidate: block structure, layout normalization, preset
 * references, and page-owned vertical rhythm.
 *
 * Not a gate: by the time this runs the theme is fully built, and rejecting
 * it over a residual defect would leave the user with no site at all. Every
 * problem is recorded in warnings.json (see Project::addWarnings) and the
 * theme is delivered anyway.
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
            reads: [
                'pages.json',
                'theme/style.css',
                'theme/theme.json',
                'theme/templates/index.html',
                'theme/templates/page.html',
                'theme/parts/header.html',
                'theme/parts/footer.html',
                'theme/parts/*',
                'theme/templates/*',
                'plugin/pages/*',
            ],
            writes: [
                'warnings.json',
            ],
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
            $project->addWarnings($this->id(), $problems);
            $report = 'Final theme validation found ' . count($problems)
                . " problem(s); theme delivered anyway, problems recorded in warnings.json:\n- "
                . implode("\n- ", $problems) . "\n";
            $project->writeText('logs/' . self::LOG_FILE, $report);
            echo '  [validate-theme] warning: ' . count($problems)
                . ' problem(s) recorded in warnings.json; see logs/' . self::LOG_FILE . "\n";
            return;
        }

        $project->writeText('logs/' . self::LOG_FILE, "Final theme validation passed.\n");
        echo "  final theme validation passed\n";
    }
}
