<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step: assemble the content-only bundle, but only if it passes its checks.
 *
 * This is the last thing that runs and the only thing a host consumes, which
 * makes it the right place to enforce what "content only" means. The checks
 * run before the bundle is written, so a build that violates them fails with
 * the reasons rather than emitting something a host would apply and regret.
 *
 * The report is written either way. A failed build is the one you most want to
 * inspect, and a report that only exists on success is no use for that.
 */
final class ExportBundleStep implements Step
{
    /**
     * Bumped when the bundle's shape changes in a way a host must notice.
     * A host reads this before applying and refuses a version it predates.
     */
    public const BUNDLE_VERSION = 1;

    public function id(): string
    {
        return 'export-bundle';
    }

    public function label(): string
    {
        return 'Verify inventory and Brand constraints, then export content only';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                PatternArtifacts::NORMALIZED,
                PatternArtifacts::PAGES,
                PatternArtifacts::PROVENANCE,
                PatternArtifacts::MEDIA,
            ],
            writes: [PatternArtifacts::BUNDLE, PatternArtifacts::REPORT],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
        $provenance = $project->readJson(PatternArtifacts::PROVENANCE);
        $media = $project->readJson(PatternArtifacts::MEDIA);

        $bundle = [
            'version' => self::BUNDLE_VERSION,
            'theme' => $inputs['theme'] ?? null,
            'brand' => $inputs['brand'] ?? [],
            'pages' => self::readPages($project, $provenance),
            'parts' => self::readParts($project),
            'media' => $media['images'] ?? [],
        ];

        $violations = ContentOnlyGuard::check(
            $bundle,
            self::stringList($inputs['inventory_ids'] ?? []),
            self::stringList($inputs['capabilities']['classes'] ?? []),
        );

        $project->writeJson(PatternArtifacts::REPORT, [
            'bundle_version' => self::BUNDLE_VERSION,
            'theme' => $bundle['theme'],
            'pages' => count($bundle['pages']),
            'parts' => count($bundle['parts']),
            'media' => count($bundle['media']),
            'violations' => $violations,
            'passed' => $violations === [],
        ]);

        if ($violations !== []) {
            throw new \RuntimeException(
                "Bundle is not content-only and was not written:\n  - "
                . implode("\n  - ", $violations)
            );
        }

        $project->writeJson(PatternArtifacts::BUNDLE, $bundle);
    }

    /**
     * Pages in the order the plan put them, each carrying the patterns its
     * sections came from so a host — and the checks above — can trace them.
     *
     * @param array<string, mixed> $provenance
     * @return list<array<string, mixed>>
     */
    private static function readPages(Project $project, array $provenance): array
    {
        $sections = [];
        foreach ($provenance['sections'] ?? [] as $record) {
            if (is_array($record) && isset($record['page'])) {
                $sections[(string) $record['page']][] = $record;
            }
        }

        $pages = [];
        foreach (self::jsonFiles($project, 'patterns/pages') as $file) {
            $page = json_decode((string) file_get_contents($file), true);
            if (!is_array($page) || !isset($page['slug'])) {
                continue;
            }

            $slug = (string) $page['slug'];
            $page['sections'] = $sections[$slug] ?? [];
            $pages[] = $page;
        }

        usort($pages, static fn (array $a, array $b) => ($a['menu_order'] ?? 0) <=> ($b['menu_order'] ?? 0));

        return $pages;
    }

    /**
     * Shared parts — header, footer — which every page renders inside and
     * which therefore have to clear the same checks the pages do.
     *
     * @return list<array<string, mixed>>
     */
    private static function readParts(Project $project): array
    {
        $parts = [];
        foreach (self::jsonFiles($project, 'patterns/parts') as $file) {
            $part = json_decode((string) file_get_contents($file), true);
            if (is_array($part) && isset($part['slug'])) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /** @return list<string> */
    private static function jsonFiles(Project $project, string $rel): array
    {
        $dir = $project->path($rel);
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
