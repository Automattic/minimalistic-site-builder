<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TreeGraph\Instance;
use Automattic\SiteBuild\TreeGraph\SandboxClient;

/**
 * Tree graph step 3: read the instance (port of x-pipeline S2).
 *
 * Zero LLM calls. Reads the sandbox's block manifest, theme tokens and
 * pattern corpus, then prepares one input file per planned section: the
 * role-sliced manifest (only the block families that section needs, with
 * their REAL attribute schemas) and a deterministically picked starting
 * pattern converted to TreeIR. The site furniture (header/footer) gets its
 * own identity-and-navigation block slice.
 */
final class ReadInstanceStep implements Step
{
    public function id(): string
    {
        return 'read-instance';
    }

    public function label(): string
    {
        return 'Read the sandbox instance';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['sandbox.json', 'brief.json'],
            writes: ['instance.json', 'sections/*', 'sections-index.json', 'furniture-slice.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $sandbox = $project->readJson('sandbox.json');
        $brief = $project->readJson('brief.json');
        $client = new SandboxClient((string) $sandbox['url']);

        $manifest = $client->manifest(true);
        $patterns = $client->patterns();
        $blocks = is_array($manifest['blocks'] ?? null) ? $manifest['blocks'] : [];

        $instance = [
            'site_url'            => (string) ($manifest['site_url'] ?? $sandbox['url']),
            'fingerprint'         => (string) ($manifest['fingerprint'] ?? ''),
            'initial_fingerprint' => (string) ($manifest['fingerprint'] ?? ''),
            'wp_version'          => (string) ($manifest['wp_version'] ?? ''),
            'theme_tokens'        => $manifest['theme_tokens'] ?? [],
        ];
        $project->writeJson('instance.json', $instance);

        $index = [];
        foreach ((array) ($brief['pages'] ?? []) as $page) {
            foreach ((array) ($page['sections'] ?? []) as $section) {
                $key = "{$page['slug']}--{$section['id']}";
                $pattern = Instance::pickPattern($patterns, (string) $section['role']);
                $entry = [
                    'page'           => ['slug' => (string) $page['slug'], 'title' => (string) $page['title']],
                    'section'        => $section,
                    'manifest_slice' => Instance::sliceManifest($blocks, $section),
                    'pattern'        => $pattern === null ? null : [
                        'name'        => (string) $pattern['name'],
                        'title'       => (string) ($pattern['title'] ?? ''),
                        'parsed_tree' => Instance::toTreeIrBlocks($pattern['parsed'] ?? $pattern['parsed_tree'] ?? []),
                    ],
                ];
                $file = "sections/{$key}.json";
                $project->writeJson($file, $entry);
                $index[] = ['key' => $key, 'page' => (string) $page['slug'], 'id' => (string) $section['id'], 'file' => $file];
            }
        }
        $project->writeJson('sections-index.json', $index);
        $project->writeJson('furniture-slice.json', Instance::furnitureSlice($blocks));

        Narrator::write(sprintf(
            "  site read: %s (WordPress %s) — %d sections to write across %d page(s), fingerprint %s…\n",
            $instance['site_url'],
            $instance['wp_version'],
            count($index),
            count((array) ($brief['pages'] ?? [])),
            substr($instance['fingerprint'], 0, 8),
        ));
    }
}
