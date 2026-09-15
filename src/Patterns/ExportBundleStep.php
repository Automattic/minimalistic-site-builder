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
     *
     * 2: an input hash, a site title, the Brand as a record, per-page hashes
     * and provenance, and the build's warnings carried inside the bundle.
     */
    public const BUNDLE_VERSION = 2;

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
        NormalizeInputsStep::assertVersion($inputs);
        $provenance = $project->readJson(PatternArtifacts::PROVENANCE);
        $media = $project->readJson(PatternArtifacts::MEDIA);
        ResolveMediaStep::assertVersion($media);

        $pages = self::readPages($project, $provenance);

        $bundle = [
            'version' => self::BUNDLE_VERSION,
            'input_hash' => self::inputHash($project),
            'theme' => $inputs['theme'] ?? null,
            'site' => ['title' => (string) ($inputs['site']['title'] ?? '')],
            'brand' => $inputs['brand'] ?? [],
            'pages' => $pages,
            // The destination builds the menu from this. Dropping it leaves a
            // multi-page site whose pages cannot reach each other, and nothing
            // downstream notices that the pages are orphaned. A host that sent
            // no menu gets one of the pages the plan chose, in the plan's order.
            'navigation' => ($inputs['navigation'] ?? []) ?: array_map(
                static fn (array $page): array => ['title' => (string) $page['title'], 'slug' => (string) $page['slug']],
                $pages,
            ),
            'page_template' => (string) ($inputs['capabilities']['page_template'] ?? ''),
            'media' => $media['images'] ?? [],
            'warnings' => $project->exists('warnings.json') ? $project->readJson('warnings.json') : [],
        ];

        // A class an approved pattern shipped with is the customer's, not
        // something generation added: personalization changes text bytes and
        // nothing else, so any class in the bundle that the inventory carries
        // came from the inventory. The same holds for the markup a host
        // supplied for a page: it wrote those classes against its own theme.
        // A theme's own patterns do write classes the theme never styles --
        // Ollie's `feature-boxes` -- and refusing a bundle over those would
        // refuse the customer's own markup.
        $capabilities = $inputs['capabilities'] ?? [];
        $capabilities['classes'] = array_values(array_unique(array_merge(
            $capabilities['classes'] ?? [],
            ContentOnlyGuard::classesIn(array_column($inputs['inventory'] ?? [], 'content')),
            ContentOnlyGuard::classesIn(self::suppliedMarkup($inputs['pages'] ?? [])),
        )));

        $violations = ContentOnlyGuard::check(
            $bundle,
            array_column( $inputs['inventory'] ?? [], 'id' ),
            $capabilities,
        );

        $project->writeJson(PatternArtifacts::REPORT, [
            'bundle_version' => self::BUNDLE_VERSION,
            'theme' => $bundle['theme'],
            'pages' => count($bundle['pages']),
            'composed' => count(array_filter($pages, static fn (array $p): bool => $p['provenance'] === 'composed')),
            'supplied' => count(array_filter($pages, static fn (array $p): bool => $p['provenance'] === 'blueprint')),
            'frozen' => array_values(array_column(array_filter($pages, static fn (array $p): bool => !empty($p['frozen'])), 'slug')),
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
        foreach (PatternArtifacts::pages($project) as $page) {
            $content = (string) ($page['content'] ?? '');
            $pages[] = [
                'slug' => (string) $page['slug'],
                'title' => (string) ($page['title'] ?? ucfirst((string) $page['slug'])),
                'front' => (bool) ($page['front'] ?? false),
                'menu_order' => (int) ($page['menu_order'] ?? 0),
                'provenance' => (string) ($page['provenance'] ?? 'composed'),
                'frozen' => (bool) ($page['frozen'] ?? false),
                'sections' => $sections[(string) $page['slug']] ?? [],
                'source_hash' => (string) ($page['source_hash'] ?? hash('sha256', $content)),
                'content_hash' => hash('sha256', $content),
                'content' => $content,
            ];
        }

        usort($pages, static fn (array $a, array $b) => $a['menu_order'] <=> $b['menu_order']);

        return $pages;
    }

    /**
     * One hash over the three seeds the host wrote, so a bundle names the
     * inputs it came from and a destination can tell two builds apart.
     *
     * The seeds are hashed as the host wrote them. A seed a fixture run did
     * not supply hashes as empty rather than stopping the export: the bundle
     * still says what it was built from, which is nothing for that seed.
     */
    public static function inputHash(Project $project): string
    {
        $parts = [];
        foreach ([PatternArtifacts::REQUEST, PatternArtifacts::INVENTORY, PatternArtifacts::BRAND] as $seed) {
            $parts[] = $project->exists($seed) ? $project->readText($seed) : '';
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<string>
     */
    private static function suppliedMarkup(array $pages): array
    {
        $markup = [];
        foreach ($pages as $page) {
            if (is_array($page) && NormalizeInputsStep::isSupplied($page)) {
                $markup[] = (string) $page['markup'];
            }
        }

        return $markup;
    }
}
