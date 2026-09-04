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
        $skippedOpenings = [];
        $usable = [];
        foreach ($pages as $page) {
            $sections = array_values(array_filter((array) ($page['sections'] ?? []), 'is_array'));
            if ($sections === []) {
                $skippedOpenings[] = (string) ($page['slug'] ?? '');
                continue;
            }
            $page['sections'] = $sections;
            $usable[] = $page;
        }
        $stepWarnings = [];
        foreach ($skippedOpenings as $slug) {
            $stepWarnings[] = "island-above-fold: page '{$slug}' has no opening section; skipped for above-fold; "
                . 'disposition=page omitted from the contract';
        }
        if ($usable === []) {
            $front = $pages[0] ?? ['slug' => 'home', 'title' => 'Home'];
            $usable = [[
                'slug' => (string) ($front['slug'] ?? 'home'),
                'title' => (string) ($front['title'] ?? 'Home'),
                'path' => (string) ($front['path'] ?? '/'),
                'front' => true,
                'parent' => null,
                'menu_order' => 0,
                'purpose' => '',
                'sections' => [[
                    'slug' => 'opening',
                    'title' => 'Opening',
                    'layout_archetype' => 'html-island',
                ]],
            ]];
            $stepWarnings[] = 'island-above-fold: no delivered page has an opening section; '
                . 'authored empty pages.json sections; delivered a stacked fallback contract; '
                . 'disposition=degraded and continued';
        }
        $pages = self::withContractSectionFields($usable);
        $contract = null;
        try {
            $contract = self::deliveryContract($project, $pages);
            $contract = AboveFoldContract::finalizeMarkup($contract, $pages, self::facts($project, $pages, $contract));
            $contract = self::withValidFollowingSection($contract);
            AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_FINAL);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $contract = null;
            $stepWarnings[] = 'island-above-fold: ' . $e->getMessage()
                . '; delivered a stacked fallback contract; disposition=degraded and continued';
        }
        if ($contract === null) {
            $contract = self::stackedFallbackContract($project, $pages);
        }
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
            'skipped_openings' => $skippedOpenings,
        ];
        $project->writeJson('island-report.json', $report);

        $warnings = array_merge($stepWarnings, AboveFoldContract::warningRows($contract));
        if ($warnings !== []) {
            $project->addWarnings($this->id(), $warnings);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,mixed>
     */
    public static function stackedFallbackContract(Project $project, array $pages): array
    {
        $dummy = self::withContractSectionFields($pages !== [] ? $pages : [[
            'slug' => 'home',
            'title' => 'Home',
            'path' => '/',
            'front' => true,
            'parent' => null,
            'menu_order' => 0,
            'purpose' => '',
            'sections' => [[
                'slug' => 'opening',
                'title' => 'Opening',
                'layout_archetype' => 'html-island',
            ]],
        ]]);
        $contract = self::deliveryContract($project, $dummy);
        $contract = AboveFoldContract::finalizeMarkup($contract, $dummy, self::facts($project, $dummy, $contract));
        $contract = self::withValidFollowingSection($contract);
        AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_FINAL);
        return $contract;
    }

    /**
     * assertContract requires a non-empty layout_archetype (and surface/slug/part)
     * when following_section is present. Island pages.json has {slug,title} only;
     * refreshDeliveredFacts can still emit "". Fill rather than ship a contract
     * validate-theme will abort on.
     *
     * @param array<string,mixed> $contract
     * @return array<string,mixed>
     */
    public static function withValidFollowingSection(array $contract): array
    {
        $following = $contract['following_section'] ?? null;
        if (!is_array($following)) {
            return $contract;
        }
        $front = (string) ($contract['front_page'] ?? 'home');
        $slug = is_string($following['slug'] ?? null) ? trim((string) $following['slug']) : '';
        if ($slug === '') {
            $slug = 'following';
        }
        $part = is_string($following['part'] ?? null) ? trim((string) $following['part']) : '';
        if ($part === '') {
            $part = 'page-' . $front . '--' . $slug;
        }
        $layout = is_string($following['layout_archetype'] ?? null) ? trim((string) $following['layout_archetype']) : '';
        if ($layout === '') {
            $layout = 'html-island';
        }
        $surface = is_string($following['surface'] ?? null) ? trim((string) $following['surface']) : '';
        if ($surface === '') {
            $surface = 'base';
        }
        $contract['following_section'] = [
            'slug' => $slug,
            'part' => $part,
            'layout_archetype' => $layout,
            'surface' => $surface,
        ];
        return $contract;
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
