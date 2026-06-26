<?php
declare(strict_types=1);

/**
 * Step 3 (deterministic): apply the project identity to the scaffolded theme.
 *
 * Input:  siteSpec.json (name, slug, description) + scaffolded theme files
 * Output: theme/style.css and theme/readme.txt with {{placeholders}} replaced.
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

    public function run(Project $project): void
    {
        $spec = $project->readJson('siteSpec.json');

        $vars = [
            'THEME_NAME'  => (string) $spec['name'],
            'THEME_SLUG'  => (string) $spec['slug'],
            'DESCRIPTION' => (string) ($spec['description'] ?? $spec['name']),
            'AUTHOR'      => (string) ($spec['author'] ?? 'Builder'),
        ];

        foreach (['theme/style.css', 'theme/readme.txt'] as $file) {
            $filled = PromptRenderer::fill($project->readText($file), $vars);
            $project->writeText($file, $filled);
        }
    }
}
