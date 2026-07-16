<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step 3 (deterministic): apply the project identity to the scaffolded theme.
 *
 * Input:  siteSpec.json (name, slug, factual fields) + scaffolded theme files
 * Output: theme/style.css and theme/readme.txt with {{placeholders}} replaced.
 *
 * Identity is purely factual, so it sources from siteSpec.json. The spec no
 * longer carries a fixed "description" field, so we compose one from the
 * factual fields that are present (description/tagline/topic), falling back to
 * the site name.
 */
final class ApplyIdentityStep implements Step
{
    public function id(): string
    {
        return 'apply-identity';
    }

    public function label(): string
    {
        return 'Apply project identity';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['siteSpec.json', 'theme/style.css', 'theme/readme.txt'],
            writes: ['theme/style.css', 'theme/readme.txt'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $spec = $project->readJson('siteSpec.json');

        $vars = [
            'THEME_NAME'  => (string) $spec['name'],
            'THEME_SLUG'  => (string) $spec['slug'],
            'DESCRIPTION' => self::description($spec),
            'AUTHOR'      => (string) ($spec['author'] ?? 'Builder'),
        ];

        foreach (['theme/style.css', 'theme/readme.txt'] as $file) {
            $filled = PromptRenderer::fill($project->readText($file), $vars);
            $project->writeText($file, $filled);
        }
    }

    /**
     * A short theme description from whatever factual fields the spec carries.
     *
     * @param array<mixed> $spec
     */
    private static function description(array $spec): string
    {
        foreach (['description', 'tagline', 'topic'] as $key) {
            $value = trim((string) ($spec[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return (string) $spec['name'];
    }
}
