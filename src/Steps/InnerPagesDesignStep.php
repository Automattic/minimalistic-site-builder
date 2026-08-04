<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\ContinuationRecovery;
use Automattic\SiteBuild\DesignMarkupSanitizer;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TextBatchResult;
use Automattic\SiteBuild\TruncatedGenerationException;

/**
 * Designs the below-fold home body and every inner page from the fold preview.
 *
 * The one batch is the only concurrent operation. Generated fragments and
 * their one semantic-repair attempt are normalized serially per page.
 */
final class InnerPagesDesignStep implements Step
{
    use LlmOptions;

    private const MAX_PAGE_CSS_BYTES = 4096;
    private const RESERVED_INNER_SLUGS = ['preview', 'home', 'site'];
    private const RESERVED_INTERNAL_PHYSICAL_SLUGS = ['home-body'];
    private const VOID_ELEMENTS = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];
    private const RAW_TEXT_ELEMENTS = [
        'script',
        'style',
        'title',
        'textarea',
    ];
    private const DOCUMENT_ELEMENTS = ['html', 'head', 'body'];

    private PagePlanStep $pagePlanStep;

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
        ?PagePlanStep $pagePlanStep = null,
    ) {
        $this->pagePlanStep = $pagePlanStep ?? new PagePlanStep($llm, $renderer);
    }

    public function id(): string
    {
        return 'inner-pages-design';
    }

    public function label(): string
    {
        return 'Design inner pages';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json', 'siteSpec.json', 'designDirection.json', 'design/site.css', 'design/preview.html'],
            writes: ['design/*', 'warnings.json'],
            concurrent: true,
        );
    }

    public function run(Project $project): void
    {
        $generationUnit = Env::get('SITE_BUILD_GEN_UNIT');
        if ($generationUnit === 'section') {
            $this->runSectionMode($project);
            return;
        }

        $warnings = [];
        if ($generationUnit !== null && $generationUnit !== '' && $generationUnit !== 'page') {
            $warnings[] = 'configuration: SITE_BUILD_GEN_UNIT authored '
                . self::warningValue($generationUnit)
                . '; delivered page mode fallback; disposition replaced invalid generation unit';
        }
        $this->runPageMode($project, $warnings);
    }

    /** @param list<string> $warnings */
    private function runPageMode(Project $project, array $warnings = []): void
    {
        $siteSpec = $project->readJson('siteSpec.json');
        $allPages = PagePlanStep::flattenPages($siteSpec);
        $pages = array_values(array_filter(
            $allPages,
            static fn (array $page): bool => !((bool) ($page['front'] ?? false)),
        ));

        $siteCss = $project->readText('design/site.css');
        $previewHtml = $project->readText('design/preview.html');
        $cachedPrefixes = [
            self::cacheLayer($siteCss),
            self::cacheLayer($previewHtml),
        ];
        $siteSpecContext = self::jsonContext($siteSpec);
        $frontPage = $allPages[0];

        $requests = [];
        $units = [];
        $homePrompt = $this->renderer->render('home-body-design.md', [
            'site_spec'      => $siteSpecContext,
            'page_spec'      => self::jsonContext($frontPage),
            'site_css'       => '[cached prefix layer 1 contains the exact design/site.css bytes]',
            'design_preview' => '[cached prefix layer 2 contains the exact design preview bytes]',
        ]);
        $units['home-body'] = [
            'slug'        => 'home-body',
            'path'        => 'design/home-body.html',
            'failed_path' => 'design/home-body.failed',
            'prompt'      => $homePrompt,
            'home'        => true,
        ];
        $requests['home-body'] = $this->withOptions([
            'prompt'          => $homePrompt,
            'cached_prefixes' => $cachedPrefixes,
        ]);

        $artifactMap = [(string) $frontPage['slug'] => 'home'];
        $semanticSlugs = array_fill_keys(array_map(
            static fn (array $page): string => (string) $page['slug'],
            $allPages,
        ), true);
        $usedSlugs = [];
        foreach ($pages as $page) {
            $authoredSlug = (string) $page['slug'];
            $slug = self::innerOutputSlug(
                $authoredSlug,
                $semanticSlugs,
                $usedSlugs,
                $warnings,
            );
            $artifactMap[$authoredSlug] = $slug;
            $requestKey = "page:{$slug}";
            if (array_key_exists($requestKey, $requests)) {
                throw new \RuntimeException("inner-pages-design: duplicate page slug '{$slug}'");
            }
            $prompt = $this->renderer->render('inner-page-design.md', [
                'site_spec'      => $siteSpecContext,
                'page_spec'      => self::jsonContext($page),
                'site_css'       => '[cached prefix layer 1 contains the exact design/site.css bytes]',
                'design_preview' => '[cached prefix layer 2 contains the exact design preview bytes]',
            ]);
            $units[$requestKey] = [
                'slug'        => $slug,
                'path'        => "design/{$slug}.html",
                'failed_path' => "design/{$slug}.failed",
                'prompt'      => $prompt,
                'home'        => false,
            ];
            $requests[$requestKey] = $this->withOptions([
                'prompt'          => $prompt,
                'cached_prefixes' => $cachedPrefixes,
            ]);
        }
        $project->writeJsonAtomic('design/page-artifact-map.json', $artifactMap);

        try {
            $batch = $this->llm->completeBatch($requests);
        } catch (\RuntimeException $error) {
            foreach ($units as $unit) {
                $path = $unit['path'];
                $failedPath = $unit['failed_path'];
                self::writeFailedDesign(
                    $project,
                    $path,
                    $failedPath,
                    ($unit['home'] ? 'Home-body' : 'Inner-page')
                        . " generation unavailable after batch failure.\n",
                );
                $warnings[] = "page_generation: {$path} context page {$unit['slug']}; authored "
                    . 'unit unavailable because completeBatch failed with '
                    . self::warningValue($error->getMessage())
                    . "; delivered {$failedPath} marker; disposition removed unavailable batch unit";
            }
            $project->addWarnings($this->id(), $warnings);
            return;
        }
        foreach ($units as $requestKey => $unit) {
            $slug = $unit['slug'];
            $path = $unit['path'];
            $failedPath = $unit['failed_path'];
            $isHome = $unit['home'];
            if (!array_key_exists($requestKey, $batch->texts)) {
                self::writeFailedDesign(
                    $project,
                    $path,
                    $failedPath,
                    ($isHome ? 'Home-body' : 'Inner-page')
                        . " generation returned no batch result.\n",
                );
                $warnings[] = "page_generation: {$path} context page {$slug}; authored missing "
                    . "batch response; delivered {$failedPath} marker; disposition removed";
                continue;
            }

            foreach ($batch->notesFor($requestKey) as $note) {
                $warnings[] = "page_generation: {$path} context page {$slug}; authored batch "
                    . 'response; delivered best available response; disposition degraded: '
                    . self::warningValue($note);
            }

            $authored = $batch->texts[$requestKey];
            $sanitized = DesignMarkupSanitizer::sanitize(
                $authored,
                $path,
                "page {$slug} batch response",
                $warnings,
            );
            $sanitized = self::removeDocumentDeclarations(
                $sanitized,
                $path,
                "page {$slug} batch response",
                $warnings,
            );
            $sanitized = trim($sanitized);
            $isValid = $isHome
                ? static fn (string $fragment): bool => self::isValidHomeBodyFragment(trim($fragment))
                : static fn (string $fragment): bool => self::isValidFragment(trim($fragment));
            if ($isValid($sanitized)) {
                self::writeSuccessfulDesign($project, $path, $failedPath, $sanitized);
                continue;
            }

            $repairContract = $isHome
                ? 'one closed bare <main> with no attributes for below-fold content, followed by one closed '
                    . '<footer>; no header or hero'
                : 'the full optional <style data-page-css> followed by one closed <main> fragment only';
            $repairPrompt = $unit['prompt']
                . "\n\nThe previous response was empty or malformed. Repair it once. Return {$repairContract}.\n\n"
                . "<authored_fragment>\n{$authored}\n</authored_fragment>";
            try {
                $repair = ContinuationRecovery::completeToClose(
                    $this->llm,
                    $repairPrompt,
                    $this->withOptions(['cached_prefixes' => $cachedPrefixes]),
                    $isValid,
                );
            } catch (TruncatedGenerationException $error) {
                $repair = $error->getPartialText();
                $warnings[] = "page_generation: {$path} context page {$slug} semantic repair; "
                    . 'authored truncated repair; delivered best partial repair for validation; '
                    . 'disposition degraded';
            } catch (\RuntimeException $error) {
                $repair = '';
                $warnings[] = "page_generation: {$path} context page {$slug} semantic repair; "
                    . 'authored unusable batch response; delivered no repair after request failure; '
                    . 'disposition degraded: ' . self::warningValue($error->getMessage());
            }

            $repair = DesignMarkupSanitizer::sanitize(
                $repair,
                $path,
                "page {$slug} semantic repair",
                $warnings,
            );
            $repair = self::removeDocumentDeclarations(
                $repair,
                $path,
                "page {$slug} semantic repair",
                $warnings,
            );
            $repair = trim($repair);
            if ($isValid($repair)) {
                self::writeSuccessfulDesign($project, $path, $failedPath, $repair);
                $warnings[] = "malformed_design: {$path} context page {$slug}; authored "
                    . self::warningValue($authored)
                    . "; delivered {$path} repaired fragment; disposition replaced";
                continue;
            }

            self::writeFailedDesign(
                $project,
                $path,
                $failedPath,
                ($isHome ? 'Home-body' : 'Inner-page')
                    . " generation failed after one semantic repair.\n",
            );
            $warnings[] = "malformed_design: {$path} context page {$slug}; authored "
                . self::warningValue($authored)
                . "; delivered {$failedPath} marker; disposition removed after one semantic repair";
        }

        $project->addWarnings($this->id(), $warnings);
    }

    private function runSectionMode(Project $project): void
    {
        $siteSpec = $project->readJson('siteSpec.json');
        $allPages = PagePlanStep::flattenPages($siteSpec);
        $pages = array_values(array_filter(
            $allPages,
            static fn (array $page): bool => !((bool) ($page['front'] ?? false)),
        ));

        $siteCss = $project->readText('design/site.css');
        $previewHtml = $project->readText('design/preview.html');
        $warnings = [];
        $cachedPrefixes = [
            self::cacheLayer($siteCss),
            self::cacheLayer($previewHtml),
        ];
        $siteSpecContext = self::jsonContext($siteSpec);
        $frontPage = $allPages[0];

        $artifactMap = [(string) $frontPage['slug'] => 'home'];
        $physicalSlugs = [];
        $semanticSlugs = array_fill_keys(array_map(
            static fn (array $page): string => (string) $page['slug'],
            $allPages,
        ), true);
        $usedSlugs = [];
        foreach ($pages as $page) {
            $authoredSlug = (string) $page['slug'];
            $physicalSlugs[$authoredSlug] = self::innerOutputSlug(
                $authoredSlug,
                $semanticSlugs,
                $usedSlugs,
                $warnings,
            );
            $artifactMap[$authoredSlug] = $physicalSlugs[$authoredSlug];
        }
        $project->writeJsonAtomic('design/page-artifact-map.json', $artifactMap);

        $plannedPages = $this->pagePlanStep->runForSlugs(
            $project,
            array_map(static fn (array $page): string => (string) $page['slug'], $pages),
        );
        $plansBySlug = [];
        foreach ($plannedPages as $plannedPage) {
            $plansBySlug[(string) $plannedPage['slug']] = $plannedPage;
        }

        $requests = [];
        $homePrompt = $this->renderer->render('home-body-design.md', [
            'site_spec'      => $siteSpecContext,
            'page_spec'      => self::jsonContext($frontPage),
            'site_css'       => '[cached prefix layer 1 contains the exact design/site.css bytes]',
            'design_preview' => '[cached prefix layer 2 contains the exact design preview bytes]',
        ]);
        $requests['home-body'] = $this->withOptions([
            'prompt'          => $homePrompt,
            'cached_prefixes' => $cachedPrefixes,
        ]);

        /** @var array<string,array<string,mixed>> $sectionUnits */
        $sectionUnits = [];
        /** @var array<string,list<string>> $plannedSectionSlugs */
        $plannedSectionSlugs = [];
        foreach ($pages as $page) {
            $semanticSlug = (string) $page['slug'];
            $physicalSlug = $physicalSlugs[$semanticSlug];
            $plannedPage = $plansBySlug[$semanticSlug] ?? $page + ['sections' => []];
            $sections = array_values(array_filter($plannedPage['sections'] ?? [], 'is_array'));
            $outline = self::compactJson(['sections' => $sections]);
            $plannedSectionSlugs[$physicalSlug] = [];
            foreach ($sections as $section) {
                $sectionSlug = (string) ($section['slug'] ?? '');
                $requestKey = "section:{$physicalSlug}:{$sectionSlug}";
                if ($sectionSlug === '' || array_key_exists($requestKey, $requests)) {
                    throw new \RuntimeException(
                        "inner-pages-design: duplicate or empty section slug '{$sectionSlug}' for page '{$physicalSlug}'",
                    );
                }
                $prompt = $this->renderer->render('inner-section-design.md', [
                    'site_spec'       => $siteSpecContext,
                    'design_direction' => DesignDirectionStep::readFor($project),
                    'page_spec'       => self::jsonContext($page),
                    'page_outline'    => $outline,
                    'section_spec'    => self::jsonContext($section),
                    'section_slug'    => $sectionSlug,
                    'site_css'        => '[cached prefix layer 1 contains the exact design/site.css bytes]',
                    'design_preview'  => '[cached prefix layer 2 contains the exact design preview bytes]',
                ]);
                $sectionUnits[$requestKey] = [
                    'page_slug'    => $physicalSlug,
                    'section_slug' => $sectionSlug,
                    'path'         => "design/{$physicalSlug}.html",
                    'failed_path'  => "design/{$physicalSlug}.failed",
                    'prompt'       => $prompt,
                ];
                $plannedSectionSlugs[$physicalSlug][] = $sectionSlug;
                $requests[$requestKey] = $this->withOptions([
                    'prompt'          => $prompt,
                    'cached_prefixes' => $cachedPrefixes,
                ]);
            }
        }

        try {
            $batch = $this->llm->completeBatch($requests);
        } catch (\RuntimeException $error) {
            self::writeFailedDesign(
                $project,
                'design/home-body.html',
                'design/home-body.failed',
                "Home-body generation unavailable after batch failure.\n",
            );
            $warnings[] = 'page_generation: design/home-body.html context page home-body; authored unit unavailable '
                . 'because completeBatch failed with ' . self::warningValue($error->getMessage())
                . '; delivered design/home-body.failed marker; disposition removed unavailable batch unit';
            foreach ($pages as $page) {
                $physicalSlug = $physicalSlugs[(string) $page['slug']];
                $path = "design/{$physicalSlug}.html";
                $failedPath = "design/{$physicalSlug}.failed";
                self::writeFailedDesign(
                    $project,
                    $path,
                    $failedPath,
                    "Inner-page section generation unavailable after batch failure.\n",
                );
                $warnings[] = "page_generation: {$path} context page {$physicalSlug}; authored all planned sections "
                    . 'unavailable because completeBatch failed with ' . self::warningValue($error->getMessage())
                    . "; delivered {$failedPath} marker; disposition removed unavailable page";
            }
            $project->addWarnings($this->id(), $warnings);
            return;
        }

        $homeUnit = [
            'slug'        => 'home-body',
            'path'        => 'design/home-body.html',
            'failed_path' => 'design/home-body.failed',
            'prompt'      => $homePrompt,
        ];
        $this->consumeSectionModeHome($project, $batch, $homeUnit, $cachedPrefixes, $warnings);

        /** @var array<string,list<string>> $survivors */
        $survivors = [];
        foreach ($sectionUnits as $requestKey => $unit) {
            $pageSlug = (string) $unit['page_slug'];
            $sectionSlug = (string) $unit['section_slug'];
            $path = (string) $unit['path'];
            $survivors[$pageSlug] ??= [];
            if (!array_key_exists($requestKey, $batch->texts)) {
                $warnings[] = "page_generation: {$path} block_path main > section#{$sectionSlug}; authored_value "
                    . "missing batch response; delivered_value removed; disposition removed section";
                continue;
            }

            $authored = $batch->texts[$requestKey];
            $candidate = self::normalizeSectionCandidate(
                $authored,
                $path,
                "page {$pageSlug} section {$sectionSlug} batch response",
                $warnings,
            );
            if (self::isValidSectionFragment($candidate, $sectionSlug)) {
                $survivors[$pageSlug][] = $candidate;
                foreach ($batch->notesFor($requestKey) as $note) {
                    $warnings[] = "page_generation: {$path} block_path main > section#{$sectionSlug}; authored_value "
                        . 'batch response; delivered_value best available section; disposition degraded: '
                        . self::warningValue($note);
                }
                continue;
            }

            $repairPrompt = (string) $unit['prompt']
                . "\n\nThe previous response was empty or malformed. Repair it once. Return exactly one closed "
                . "<section id=\"{$sectionSlug}\">...</section> fragment and nothing else.\n\n"
                . "<authored_fragment>\n{$authored}\n</authored_fragment>";
            $isValid = static fn (string $fragment): bool => self::isValidSectionFragment(
                trim($fragment),
                $sectionSlug,
            );
            try {
                $repair = ContinuationRecovery::completeToClose(
                    $this->llm,
                    $repairPrompt,
                    $this->withOptions(['cached_prefixes' => $cachedPrefixes]),
                    $isValid,
                );
            } catch (TruncatedGenerationException $error) {
                $repair = $error->getPartialText();
                $warnings[] = "page_generation: {$path} block_path main > section#{$sectionSlug}; authored_value "
                    . 'truncated semantic repair; delivered_value best partial repair for validation; '
                    . 'disposition degraded';
            } catch (\RuntimeException $error) {
                $repair = '';
                $warnings[] = "page_generation: {$path} block_path main > section#{$sectionSlug}; authored_value "
                    . 'unusable batch response; delivered_value no repair after request failure; '
                    . 'disposition degraded: ' . self::warningValue($error->getMessage());
            }

            $repair = self::normalizeSectionCandidate(
                $repair,
                $path,
                "page {$pageSlug} section {$sectionSlug} semantic repair",
                $warnings,
            );
            if (self::isValidSectionFragment($repair, $sectionSlug)) {
                $survivors[$pageSlug][] = $repair;
                $warnings[] = "malformed_design: {$path} block_path main > section#{$sectionSlug}; authored_value "
                    . self::warningValue($authored)
                    . "; delivered_value repaired section#{$sectionSlug}; disposition replaced";
                continue;
            }

            $warnings[] = "malformed_design: {$path} block_path main > section#{$sectionSlug}; authored_value "
                . self::warningValue($authored)
                . '; delivered_value removed; disposition removed after one semantic repair';
        }

        foreach ($pages as $page) {
            $physicalSlug = $physicalSlugs[(string) $page['slug']];
            $path = "design/{$physicalSlug}.html";
            $failedPath = "design/{$physicalSlug}.failed";
            $pageSurvivors = $survivors[$physicalSlug] ?? [];
            if ($pageSurvivors !== []) {
                self::writeSuccessfulDesign(
                    $project,
                    $path,
                    $failedPath,
                    "<main>\n" . implode("\n", $pageSurvivors) . "\n</main>",
                );
                continue;
            }

            self::writeFailedDesign(
                $project,
                $path,
                $failedPath,
                "Inner-page generation failed because every planned section was removed.\n",
            );
            $sectionList = implode(', ', $plannedSectionSlugs[$physicalSlug] ?? []);
            $warnings[] = "malformed_design: {$path} context page {$physicalSlug}; authored_value planned sections "
                . self::warningValue($sectionList)
                . "; delivered_value {$failedPath} marker; disposition removed page after all sections failed";
        }

        $project->addWarnings($this->id(), $warnings);
    }

    /**
     * @param array<string,mixed> $unit
     * @param list<string> $cachedPrefixes
     * @param list<string> $warnings
     */
    private function consumeSectionModeHome(
        Project $project,
        TextBatchResult $batch,
        array $unit,
        array $cachedPrefixes,
        array &$warnings,
    ): void {
        $requestKey = 'home-body';
        $slug = (string) $unit['slug'];
        $path = (string) $unit['path'];
        $failedPath = (string) $unit['failed_path'];
        if (!array_key_exists($requestKey, $batch->texts)) {
            self::writeFailedDesign(
                $project,
                $path,
                $failedPath,
                "Home-body generation returned no batch result.\n",
            );
            $warnings[] = "page_generation: {$path} context page {$slug}; authored missing batch response; "
                . "delivered {$failedPath} marker; disposition removed";
            return;
        }

        foreach ($batch->notesFor($requestKey) as $note) {
            $warnings[] = "page_generation: {$path} context page {$slug}; authored batch response; "
                . 'delivered best available response; disposition degraded: ' . self::warningValue($note);
        }
        $authored = $batch->texts[$requestKey];
        $candidate = DesignMarkupSanitizer::sanitize(
            $authored,
            $path,
            "page {$slug} batch response",
            $warnings,
        );
        $candidate = self::removeDocumentDeclarations(
            $candidate,
            $path,
            "page {$slug} batch response",
            $warnings,
        );
        $candidate = trim($candidate);
        $isValid = static fn (string $fragment): bool => self::isValidHomeBodyFragment(trim($fragment));
        if ($isValid($candidate)) {
            self::writeSuccessfulDesign($project, $path, $failedPath, $candidate);
            return;
        }

        $repairPrompt = (string) $unit['prompt']
            . "\n\nThe previous response was empty or malformed. Repair it once. Return one closed bare <main> "
            . "with no attributes for below-fold content, followed by one closed <footer>; no header or hero.\n\n"
            . "<authored_fragment>\n{$authored}\n</authored_fragment>";
        try {
            $repair = ContinuationRecovery::completeToClose(
                $this->llm,
                $repairPrompt,
                $this->withOptions(['cached_prefixes' => $cachedPrefixes]),
                $isValid,
            );
        } catch (TruncatedGenerationException $error) {
            $repair = $error->getPartialText();
            $warnings[] = "page_generation: {$path} context page {$slug} semantic repair; authored truncated repair; "
                . 'delivered best partial repair for validation; disposition degraded';
        } catch (\RuntimeException $error) {
            $repair = '';
            $warnings[] = "page_generation: {$path} context page {$slug} semantic repair; authored unusable batch response; "
                . 'delivered no repair after request failure; disposition degraded: '
                . self::warningValue($error->getMessage());
        }
        $repair = DesignMarkupSanitizer::sanitize(
            $repair,
            $path,
            "page {$slug} semantic repair",
            $warnings,
        );
        $repair = self::removeDocumentDeclarations(
            $repair,
            $path,
            "page {$slug} semantic repair",
            $warnings,
        );
        $repair = trim($repair);
        if ($isValid($repair)) {
            self::writeSuccessfulDesign($project, $path, $failedPath, $repair);
            $warnings[] = "malformed_design: {$path} context page {$slug}; authored "
                . self::warningValue($authored)
                . "; delivered {$path} repaired fragment; disposition replaced";
            return;
        }

        self::writeFailedDesign(
            $project,
            $path,
            $failedPath,
            "Home-body generation failed after one semantic repair.\n",
        );
        $warnings[] = "malformed_design: {$path} context page {$slug}; authored "
            . self::warningValue($authored)
            . "; delivered {$failedPath} marker; disposition removed after one semantic repair";
    }

    /** @param list<string> $warnings */
    private static function normalizeSectionCandidate(
        string $html,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        $html = DesignMarkupSanitizer::sanitize($html, $path, $context, $warnings);
        $html = self::removeDocumentDeclarations($html, $path, $context, $warnings);
        return trim($html);
    }

    /**
     * Commit a usable page before clearing any marker from an earlier failed
     * run. Marker deletion is an I/O operation: failure remains fatal instead
     * of leaving contradictory successful and failed artifacts in place.
     */
    private static function writeSuccessfulDesign(
        Project $project,
        string $path,
        string $failedPath,
        string $content,
    ): void {
        $project->writeText($path, $content);
        if (!$project->exists($failedPath)) {
            return;
        }
        $marker = $project->path($failedPath);
        if (!is_file($marker) || !@unlink($marker)) {
            throw new \RuntimeException("Could not remove stale failed marker: {$marker}");
        }
    }

    /**
     * Clear a stale successful artifact before recording this unit's failure.
     * Deletion and marker writes are I/O boundaries, so either failure stays fatal.
     */
    private static function writeFailedDesign(
        Project $project,
        string $path,
        string $failedPath,
        string $message,
    ): void {
        if ($project->exists($path)) {
            $artifact = $project->path($path);
            if (!is_file($artifact) || !@unlink($artifact)) {
                throw new \RuntimeException("Could not remove stale design artifact: {$artifact}");
            }
        }
        $project->writeText($failedPath, $message);
    }

    /** @param array<mixed> $value */
    private static function jsonContext(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<mixed> $value */
    private static function compactJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array<string,true> $semanticSlugs
     * @param array<string,true> $usedSlugs
     * @param list<string>       $warnings
     */
    private static function innerOutputSlug(
        string $authoredSlug,
        array $semanticSlugs,
        array &$usedSlugs,
        array &$warnings,
    ): string {
        $slug = $authoredSlug;
        $reservedPageSlug = in_array($slug, self::RESERVED_INNER_SLUGS, true);
        $reservedPhysicalSlug = in_array(
            $slug,
            self::RESERVED_INTERNAL_PHYSICAL_SLUGS,
            true,
        );
        if ($reservedPageSlug || $reservedPhysicalSlug) {
            $suffix = 2;
            do {
                $slug = "{$authoredSlug}-{$suffix}";
                $suffix++;
            } while (
                in_array($slug, self::RESERVED_INNER_SLUGS, true)
                || in_array($slug, self::RESERVED_INTERNAL_PHYSICAL_SLUGS, true)
                || isset($semanticSlugs[$slug])
                || isset($usedSlugs[$slug])
            );
            $warnings[] = "page_generation: design/{$authoredSlug}.html context page slug "
                . self::warningValue($authoredSlug)
                . '; authored '
                . ($reservedPhysicalSlug ? 'reserved internal physical slug ' : 'reserved page slug ')
                . "{$authoredSlug}; delivered design/{$slug}.html; "
                . 'disposition renamed to avoid reserved design artifact';
        }
        if (isset($usedSlugs[$slug])) {
            throw new \RuntimeException("inner-pages-design: duplicate page slug '{$slug}'");
        }
        $usedSlugs[$slug] = true;
        return $slug;
    }

    private static function cacheLayer(string $content): string
    {
        return rtrim($content, "\r\n") . "\n\n";
    }

    private static function isValidFragment(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        $tokens = self::sourceTokens($html);
        if ($tokens === null) {
            return false;
        }

        $stack = [];
        $roots = [];
        $mainCount = 0;
        foreach ($tokens as $token) {
            if ($token['type'] === 'declaration') {
                return false;
            }

            $name = $token['name'];
            if (in_array($name, self::DOCUMENT_ELEMENTS, true)) {
                return false;
            }
            if ($token['closing']) {
                if (in_array($name, self::VOID_ELEMENTS, true)) {
                    return false;
                }
                $last = array_key_last($stack);
                if ($last === null || $stack[$last]['name'] !== $name) {
                    return false;
                }
                $opening = array_pop($stack);
                if ($stack === []) {
                    $roots[] = [
                        'name'        => $name,
                        'start'       => $opening['start'],
                        'open_end'    => $opening['end'],
                        'close_start' => $token['start'],
                        'end'         => $token['end'],
                    ];
                }
                continue;
            }

            if ($name === 'main') {
                $mainCount++;
                if ($mainCount > 1) {
                    return false;
                }
            }
            if ($name === 'style' && $stack !== []) {
                return false;
            }
            if ($stack === [] && !in_array($name, ['style', 'main'], true)) {
                return false;
            }
            if (!in_array($name, self::VOID_ELEMENTS, true)) {
                $stack[] = $token;
            }
        }

        if ($stack !== [] || $mainCount !== 1) {
            return false;
        }
        $names = array_column($roots, 'name');
        if ($names !== ['main'] && $names !== ['style', 'main']) {
            return false;
        }
        if ($roots[0]['start'] !== 0 || $roots[count($roots) - 1]['end'] !== strlen($html)) {
            return false;
        }

        if ($names === ['style', 'main']) {
            $style = $roots[0];
            $styleOpen = substr($html, $style['start'], $style['open_end'] - $style['start']);
            if (
                preg_match(
                    '/(?:^|\s)data-page-css(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|\/?>)/i',
                    $styleOpen,
                ) !== 1
                || strlen(substr(
                    $html,
                    $style['open_end'],
                    $style['close_start'] - $style['open_end'],
                )) > self::MAX_PAGE_CSS_BYTES
                || !self::isHtmlWhitespace(substr(
                    $html,
                    $style['end'],
                    $roots[1]['start'] - $style['end'],
                ))
            ) {
                return false;
            }
        }
        return true;
    }

    private static function isValidSectionFragment(string $html, string $expectedId): bool
    {
        if ($html === '' || $expectedId === '') {
            return false;
        }

        $tokens = self::sourceTokens($html);
        if ($tokens === null) {
            return false;
        }

        $stack = [];
        $roots = [];
        foreach ($tokens as $token) {
            if ($token['type'] === 'declaration') {
                return false;
            }
            $name = $token['name'];
            if (
                in_array($name, self::DOCUMENT_ELEMENTS, true)
                || in_array($name, ['main', 'style', 'header', 'footer', 'script'], true)
            ) {
                return false;
            }
            if ($token['closing']) {
                if (in_array($name, self::VOID_ELEMENTS, true)) {
                    return false;
                }
                $last = array_key_last($stack);
                if ($last === null || $stack[$last]['name'] !== $name) {
                    return false;
                }
                $opening = array_pop($stack);
                if ($stack === []) {
                    $roots[] = [
                        'name'    => $name,
                        'start'   => $opening['start'],
                        'end'     => $token['end'],
                        'opening' => $opening,
                    ];
                }
                continue;
            }

            if ($stack === [] && $name !== 'section') {
                return false;
            }
            if (!in_array($name, self::VOID_ELEMENTS, true)) {
                $stack[] = $token;
            }
        }

        if ($stack !== [] || count($roots) !== 1 || $roots[0]['name'] !== 'section') {
            return false;
        }
        if ($roots[0]['start'] !== 0 || $roots[0]['end'] !== strlen($html)) {
            return false;
        }
        return self::openingTagAttribute($html, $roots[0]['opening'], 'id') === $expectedId;
    }

    private static function isValidHomeBodyFragment(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        $tokens = self::sourceTokens($html);
        if ($tokens === null) {
            return false;
        }

        $stack = [];
        $roots = [];
        $mainCount = 0;
        $footerCount = 0;
        foreach ($tokens as $token) {
            if ($token['type'] === 'declaration') {
                return false;
            }
            $name = $token['name'];
            if (in_array($name, self::DOCUMENT_ELEMENTS, true) || $name === 'header') {
                return false;
            }
            if ($token['closing']) {
                if (in_array($name, self::VOID_ELEMENTS, true)) {
                    return false;
                }
                $last = array_key_last($stack);
                if ($last === null || $stack[$last]['name'] !== $name) {
                    return false;
                }
                $opening = array_pop($stack);
                if ($stack === []) {
                    $roots[] = [
                        'name'  => $name,
                        'start' => $opening['start'],
                        'end'   => $token['end'],
                    ];
                }
                continue;
            }

            if ($name === 'main') {
                if (++$mainCount > 1 || !self::openingTagHasNoAttributes($html, $token)) {
                    return false;
                }
            }
            if ($name === 'footer' && ++$footerCount > 1) {
                return false;
            }
            if ($name === 'h1' || self::openingTagAttribute($html, $token, 'id') === 'hero') {
                return false;
            }
            if ($name === 'style') {
                return false;
            }
            if ($stack === [] && !in_array($name, ['main', 'footer'], true)) {
                return false;
            }
            if (!in_array($name, self::VOID_ELEMENTS, true)) {
                $stack[] = $token;
            }
        }

        if ($stack !== [] || $mainCount !== 1 || $footerCount !== 1) {
            return false;
        }
        if (array_column($roots, 'name') !== ['main', 'footer']) {
            return false;
        }
        if ($roots[0]['start'] !== 0 || $roots[1]['end'] !== strlen($html)) {
            return false;
        }
        return self::isHtmlWhitespace(substr(
            $html,
            $roots[0]['end'],
            $roots[1]['start'] - $roots[0]['end'],
        ));
    }

    /** @param array{type:string,start:int,end:int,name:string,closing:bool} $token */
    private static function openingTagHasNoAttributes(string $html, array $token): bool
    {
        $tag = substr($html, $token['start'], $token['end'] - $token['start']);
        return preg_match('/\A<main[\x09\x0A\x0C\x0D\x20]*>\z/Di', $tag) === 1;
    }

    /**
     * Read one real opening-tag attribute without matching text inside another
     * attribute's quoted value.
     *
     * @param array{type:string,start:int,end:int,name:string,closing:bool} $token
     */
    private static function openingTagAttribute(
        string $html,
        array $token,
        string $wanted,
    ): ?string {
        $tag = substr($html, $token['start'], $token['end'] - $token['start']);
        $length = strlen($tag);
        $offset = 1;
        while ($offset < $length && preg_match('/[A-Za-z0-9:-]/', $tag[$offset]) === 1) {
            $offset++;
        }

        while ($offset < $length) {
            while ($offset < $length && self::isHtmlWhitespace($tag[$offset])) {
                $offset++;
            }
            if ($offset >= $length || $tag[$offset] === '>' || $tag[$offset] === '/') {
                return null;
            }

            $nameStart = $offset;
            while (
                $offset < $length
                && !self::isHtmlWhitespace($tag[$offset])
                && !in_array($tag[$offset], ['=', '>', '/'], true)
            ) {
                $offset++;
            }
            $name = strtolower(substr($tag, $nameStart, $offset - $nameStart));
            while ($offset < $length && self::isHtmlWhitespace($tag[$offset])) {
                $offset++;
            }

            $value = '';
            if ($offset < $length && $tag[$offset] === '=') {
                $offset++;
                while ($offset < $length && self::isHtmlWhitespace($tag[$offset])) {
                    $offset++;
                }
                if ($offset < $length && ($tag[$offset] === '"' || $tag[$offset] === "'")) {
                    $quote = $tag[$offset];
                    $offset++;
                    $valueStart = $offset;
                    while ($offset < $length && $tag[$offset] !== $quote) {
                        $offset++;
                    }
                    if ($offset >= $length) {
                        return null;
                    }
                    $value = substr($tag, $valueStart, $offset - $valueStart);
                    $offset++;
                } else {
                    $valueStart = $offset;
                    while (
                        $offset < $length
                        && !self::isHtmlWhitespace($tag[$offset])
                        && $tag[$offset] !== '>'
                    ) {
                        $offset++;
                    }
                    $value = substr($tag, $valueStart, $offset - $valueStart);
                }
            }

            if ($name === strtolower($wanted)) {
                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return null;
    }

    /**
     * Doctypes and processing instructions are never part of a <main> fragment.
     *
     * The shared sanitizer owns hostile-markup handling. This shape normalizer
     * removes only declaration tokens that remain inert after that pass.
     *
     * @param list<string> $warnings
     */
    private static function removeDocumentDeclarations(
        string $html,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        $tokens = self::sourceTokens($html);
        if ($tokens === null) {
            return $html;
        }

        $declarations = array_values(array_filter(
            $tokens,
            static fn (array $token): bool => $token['type'] === 'declaration',
        ));
        foreach (array_reverse($declarations) as $token) {
            $authored = substr($html, $token['start'], $token['end'] - $token['start']);
            $html = substr_replace(
                $html,
                '',
                $token['start'],
                $token['end'] - $token['start'],
            );
            $warnings[] = "malformed_design: {$path} context {$context}; authored "
                . self::warningValue($authored)
                . '; delivered removed; disposition removed document declaration';
        }
        return $html;
    }

    /**
     * @return list<array{type:string,start:int,end:int,name:string,closing:bool}>|null
     */
    private static function sourceTokens(string $html): ?array
    {
        $tokens = [];
        $length = strlen($html);
        $offset = 0;
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            if (substr($html, $start, 4) === '<!--') {
                $end = self::commentEnd($html, $start);
                if ($end === null) {
                    return null;
                }
                $offset = $end;
                continue;
            }
            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                $end = strpos($html, '>', $start + 2);
                if ($end === false) {
                    return null;
                }
                $tokens[] = [
                    'type'    => 'declaration',
                    'start'   => $start,
                    'end'     => $end + 1,
                    'name'    => '',
                    'closing' => false,
                ];
                $offset = $end + 1;
                continue;
            }

            $tag = self::sourceTagAt($html, $start);
            if ($tag === null) {
                if (preg_match('/\G<\/?[A-Za-z]/', $html, $unused, 0, $start) === 1) {
                    return null;
                }
                $offset = $start + 1;
                continue;
            }
            $tokens[] = $tag;
            $offset = $tag['end'];
            if (
                !$tag['closing']
                && in_array($tag['name'], self::RAW_TEXT_ELEMENTS, true)
            ) {
                $close = self::rawTextCloseTag($html, $tag['name'], $offset);
                if ($close === null) {
                    return null;
                }
                $tokens[] = $close;
                $offset = $close['end'];
            }
        }
        return $tokens;
    }

    /**
     * @return array{type:string,start:int,end:int,name:string,closing:bool}|null
     */
    private static function sourceTagAt(string $html, int $start): ?array
    {
        if (
            preg_match(
                '/\G<(\/?)([A-Za-z][A-Za-z0-9:-]*)(?=[\x09\x0A\x0C\x0D\x20\/>])/',
                $html,
                $match,
                0,
                $start,
            ) !== 1
        ) {
            return null;
        }

        $end = self::tagEnd($html, $start + strlen($match[0]));
        if ($end === null) {
            return null;
        }
        if ($match[1] === '/') {
            $tail = substr(
                $html,
                $start + strlen($match[0]),
                $end - $start - strlen($match[0]),
            );
            if (preg_match('/\A[\x09\x0A\x0C\x0D\x20]*>\z/D', $tail) !== 1) {
                return null;
            }
        }
        return [
            'type'    => 'tag',
            'start'   => $start,
            'end'     => $end,
            'name'    => strtolower($match[2]),
            'closing' => $match[1] === '/',
        ];
    }

    private static function tagEnd(string $html, int $offset): ?int
    {
        $quote = null;
        for ($length = strlen($html); $offset < $length; $offset++) {
            $char = $html[$offset];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return $offset + 1;
            }
        }
        return null;
    }

    /**
     * @return array{type:string,start:int,end:int,name:string,closing:bool}|null
     */
    private static function rawTextCloseTag(string $html, string $name, int $offset): ?array
    {
        while (($start = stripos($html, '</' . $name, $offset)) !== false) {
            $tag = self::sourceTagAt($html, $start);
            if ($tag !== null && $tag['closing'] && $tag['name'] === $name) {
                return $tag;
            }
            $offset = $start + 2;
        }
        return null;
    }

    private static function commentEnd(string $html, int $start): ?int
    {
        $offset = $start + 4;
        while (($end = strpos($html, '>', $offset)) !== false) {
            if (
                substr($html, max($start + 4, $end - 2), 2) === '--'
                || substr($html, max($start + 4, $end - 3), 3) === '--!'
            ) {
                return $end + 1;
            }
            $offset = $end + 1;
        }
        return null;
    }

    private static function isHtmlWhitespace(string $text): bool
    {
        return preg_match('/\A[\x09\x0A\x0C\x0D\x20]*\z/D', $text) === 1;
    }

    private static function warningValue(string $value): string
    {
        if (strlen($value) > 320) {
            $value = substr($value, 0, 317) . '...';
        }
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?: '"(unprintable)"';
    }
}
