<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\IslandPartFacts;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TransformArtifacts;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/**
 * Delivery + final above-fold contract for the html-islands graph.
 *
 * Mirrors SectionsStep::deliveryContract(): resolve() and finalizeDelivery()
 * verbatim, with IslandPartFacts supplying overlay/surface/hero facts the
 * group-root inspector cannot see in a wp:html island.
 */
final class IslandAboveFoldStep implements Step
{
    public function id(): string
    {
        return 'island-above-fold';
    }

    public function label(): string
    {
        return 'Derive the above-fold contract from island markup';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'siteSpec.json',
                'designDirection.json',
                'theme/theme.json',
                TransformArtifacts::SITE_CSS,
                'pages.json',
                'theme/parts/header.html',
                'theme/parts/*',
                'island-report.json',
            ],
            writes: [
                'aboveFold.json',
                'island-report.json',
                'warnings.json',
                'theme/parts/header.html',
                // extract-patterns is dropped on this graph (every island fails
                // eligibility). validate-theme still reads this glob; claiming
                // the empty slot keeps StepGraph::validate() honest.
                'theme/patterns/*',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $pages = array_values(array_filter(
            (array) ($project->readJson('pages.json')['pages'] ?? []),
            'is_array',
        ));
        $pages = self::withContractSectionFields($pages);
        $contract = self::deliveryContract($project, $pages);
        $contract = AboveFoldContract::finalizeMarkup($contract, $pages, self::facts($project, $pages, $contract));
        $project->writeJson('aboveFold.json', $contract);

        $report = $project->exists('island-report.json')
            ? $project->readJson('island-report.json')
            : [];
        if (!is_array($report)) {
            $report = [];
        }
        $report['above_fold'] = [
            'degradations' => (array) ($contract['degradations'] ?? []),
            'header_mode' => $contract['header']['mode'] ?? null,
        ];
        $project->writeJson('island-report.json', $report);

        $warnings = AboveFoldContract::warningRows($contract);
        if ($warnings !== []) {
            $project->addWarnings($this->id(), $warnings);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,mixed>
     */
    public static function deliveryContract(Project $project, array $pages): array
    {
        $pages = self::withContractSectionFields($pages);
        $siteSpecData = $project->readJson('siteSpec.json');
        $blueprint = DesignDirectionStep::heroBlueprintFor($project);
        $footerArchetype = FooterComposition::archetypeForProject($project);
        $footerSurface = FooterComposition::resolveSurface($footerArchetype, SectionsStep::closingBackgrounds($pages));
        $css = $project->exists(TransformArtifacts::SITE_CSS)
            ? $project->readText(TransformArtifacts::SITE_CSS)
            : '';
        $contract = AboveFoldContract::resolve(
            pages: $pages,
            blueprint: $blueprint,
            canvas: DesignDirectionStep::canvasFor($project),
            themeContext: $project->readJson('theme/theme.json'),
            siteContext: [
                'stable_id' => (string) ($siteSpecData['slug'] ?? $project->slug()),
                'writing_direction' => (string) ($siteSpecData['writing_direction'] ?? 'ltr'),
                'page_count' => count($pages),
            ],
            footerContext: [
                'archetype' => $footerArchetype,
                'surface' => $footerSurface,
            ],
            forcedHeaderArchetype: Env::get(AboveFoldContract::HEADER_ARCHETYPE_ENV),
            designCss: $css !== '' ? $css : null,
        );
        self::stampHeaderArchetype($project, (string) ($contract['header']['archetype'] ?? ''));
        $facts = self::facts($project, $pages, $contract);
        return AboveFoldContract::finalizeDelivery($contract, $pages, $facts);
    }

    private static function stampHeaderArchetype(Project $project, string $archetype): void
    {
        if ($archetype === '' || !$project->exists('theme/parts/header.html')) {
            return;
        }
        $repairs = [];
        $markup = GeneratedMarkup::withRootClassMarker(
            $project->readText('theme/parts/header.html'),
            'header-archetype--',
            'header-archetype--' . $archetype,
            'header',
            $repairs,
        );
        $project->writeText('theme/parts/header.html', $markup);
    }

    /**
     * island-pages writes {slug,title} only. assertContract requires a
     * non-empty following_section.layout_archetype. Fill a local copy; do not
     * rewrite pages.json.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private static function withContractSectionFields(array $pages): array
    {
        foreach ($pages as &$page) {
            if (!is_array($page)) {
                continue;
            }
            $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
            foreach ($sections as &$section) {
                if (!is_array($section)) {
                    continue;
                }
                if (!is_string($section['layout_archetype'] ?? null) || trim((string) $section['layout_archetype']) === '') {
                    $section['layout_archetype'] = 'html-island';
                }
            }
            unset($section);
            $page['sections'] = $sections;
        }
        unset($page);
        return $pages;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,mixed> $contract
     * @return array<string,mixed>
     */
    private static function facts(Project $project, array $pages, array $contract): array
    {
        $partBytes = [];
        foreach (glob($project->themePath('parts/*.html')) ?: [] as $abs) {
            $partBytes[substr(basename($abs), 0, -strlen('.html'))] = (string) file_get_contents($abs);
        }
        $css = $project->exists(TransformArtifacts::SITE_CSS)
            ? $project->readText(TransformArtifacts::SITE_CSS)
            : '';
        return IslandPartFacts::inspect($pages, $partBytes, $contract, $css);
    }
}
