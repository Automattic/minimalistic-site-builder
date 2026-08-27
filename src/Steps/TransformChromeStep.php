<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\DesignDocument;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\PlaygroundArtifact;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TransformArtifacts;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\HeaderUnit;

/**
 * Chrome half of transform-site: turn the front page's page-level header and
 * footer into block template parts so the html-islands graph can keep a real
 * block shell while page bodies ship as raw HTML islands.
 *
 * Missing or unusable chrome degrades: HeaderUnit/FooterUnit in one batch,
 * then SectionsStep::fallbackChrome(). The step never aborts the build.
 */
final class TransformChromeStep implements Step
{
    use LlmOptions;

    private const PAGE_ARTIFACT_MAP = 'design/page-artifact-map.json';

    private HeaderUnit $headerUnit;
    private FooterUnit $footerUnit;

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {
        $this->headerUnit = new HeaderUnit($llm, $renderer, $model, $temperature);
        $this->footerUnit = new FooterUnit($llm, $renderer, $model, $temperature);
    }

    public function id(): string
    {
        return 'transform-chrome';
    }

    public function label(): string
    {
        return 'Transform the designed header and footer into block parts';
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
                self::PAGE_ARTIFACT_MAP,
                'design/home.html',
                TransformArtifacts::SITE_CSS,
            ],
            writes: [
                'theme/parts/header.html',
                'theme/parts/footer.html',
                TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR,
                TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR,
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $siteSpec = $project->readJson('siteSpec.json');
        $pages = PagePlanStep::flattenPages($siteSpec);
        $source = self::frontArtifactPath($project, $pages);
        $siteCss = $project->exists(TransformArtifacts::SITE_CSS)
            ? $project->readText(TransformArtifacts::SITE_CSS)
            : '';

        $warnings = [];
        $outputs = [];
        $assetSets = [];
        $located = ['header' => false, 'footer' => false];

        if (!$project->exists($source)) {
            $warnings[] = "source {$source} selector shell diagnostic_code missing_shell_landmark "
                . 'authored_value missing design artifact delivered_value theme/parts/*.html '
                . 'disposition repaired via blocks chrome prompt';
        } else {
            $html = $project->readText($source);
            $structuralErrors = [];
            $document = DesignDocument::parse($html, $structuralErrors);
            if ($structuralErrors !== []) {
                $warnings[] = "source {$source} selector document diagnostic_code shell_transform_degraded "
                    . 'authored_value ' . self::oneLine(implode('; ', $structuralErrors))
                    . ' delivered_value retained disposition degraded';
            }
            if ($document instanceof DesignDocument) {
                $authorCss = self::pageAuthorCss($siteCss, $html);
                foreach (['header', 'footer'] as $area) {
                    $element = $area === 'header' ? $document->header() : $document->footer();
                    if (!$element instanceof \DOMElement) {
                        continue;
                    }
                    $located[$area] = true;
                    $fragmentHtml = $document->html($element);
                    $compiled = self::compileChromeFragment($fragmentHtml, $source, $authorCss, $warnings);
                    if ($compiled === null) {
                        continue;
                    }
                    $assetSets[] = $compiled['assets'];
                    $outputs["theme/parts/{$area}.html"] = $compiled['markup'];
                }
            }
        }

        $missingLandmarks = array_values(array_filter(
            ['header', 'footer'],
            static fn (string $area): bool => $located[$area] === false,
        ));
        $chromeNeedsGeneration = array_values(array_filter(
            ['header', 'footer'],
            static fn (string $area): bool => !isset($outputs["theme/parts/{$area}.html"]),
        ));
        if ($chromeNeedsGeneration !== []) {
            $this->generateMissingChrome(
                $project,
                $pages,
                $siteSpec,
                $source,
                $chromeNeedsGeneration,
                $missingLandmarks,
                $outputs,
                $warnings,
            );
        }

        foreach (['header', 'footer'] as $area) {
            $path = "theme/parts/{$area}.html";
            if (isset($outputs[$path])) {
                continue;
            }
            $outputs[$path] = rtrim(SectionsStep::fallbackChrome($area)) . "\n";
            $warnings[] = "source {$source} selector {$area} diagnostic_code shell_generation_failed "
                . 'authored_value missing shell delivered_value deterministic minimal shell disposition retained';
        }

        foreach ($outputs as $path => $content) {
            $project->writeText($path, $content);
        }

        $carriedCss = self::carriedCss($assetSets, $warnings);
        $project->writeText(TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR, $carriedCss['before-author']);
        $project->writeText(TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR, $carriedCss['after-author']);
        $project->addWarnings($this->id(), $warnings);
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     */
    private static function frontArtifactPath(Project $project, array $pages): string
    {
        $frontSlug = 'home';
        foreach ($pages as $page) {
            if (!empty($page['front'])) {
                $frontSlug = (string) $page['slug'];
                break;
            }
        }
        $artifactSlug = 'home';
        if ($project->exists(self::PAGE_ARTIFACT_MAP)) {
            $map = $project->readJson(self::PAGE_ARTIFACT_MAP);
            $mapped = $map[$frontSlug] ?? null;
            if (is_string($mapped) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $mapped) === 1) {
                $artifactSlug = $mapped;
            }
        }
        return "design/{$artifactSlug}.html";
    }

    /**
     * @param list<string> $warnings
     * @return array{markup:string,assets:array<int,array<string,mixed>>}|null
     */
    private static function compileChromeFragment(
        string $html,
        string $source,
        string $authorCss,
        array &$warnings,
    ): ?array {
        try {
            $result = (new ArtifactCompiler())->compileFragment(
                $html,
                $source,
                'html',
                ['static_css' => $authorCss],
            );
        } catch (\InvalidArgumentException|\TypeError $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            $warnings[] = "source {$source} selector chrome diagnostic_code fragment_transform_failed "
                . 'authored_value unusable transformed shell delivered_value removed disposition dropped '
                . "reason fragment transform failed: {$error->getMessage()}";
            return null;
        }

        $markup = trim($result->serializedBlocks);
        if (!self::usableChromeMarkup($markup, $html)) {
            return null;
        }
        $lostCarrier = self::lostCarrierClass($result->assets, $markup);
        if ($lostCarrier !== null) {
            $warnings[] = "source {$source} selector chrome diagnostic_code carrier_class_dropped "
                . "authored_value {$lostCarrier} delivered_value removed disposition dropped "
                . "reason native geometry carrier {$lostCarrier} was not serialized";
            return null;
        }
        if (self::firstUnsupported($result->fallbacks) !== null) {
            return null;
        }

        return [
            'markup' => rtrim($markup) . "\n",
            'assets' => $result->assets,
        ];
    }

    private static function usableChromeMarkup(string $markup, string $html): bool
    {
        if ($markup === '') {
            return trim(strip_tags($html)) === '';
        }
        if (!str_contains($markup, '<!-- wp:')) {
            return false;
        }
        return !str_contains($markup, '<!-- wp:html');
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param list<string> $missing
     * @param list<string> $missingLandmarks
     * @param array<string,string> $outputs
     * @param list<string> $warnings
     */
    private function generateMissingChrome(
        Project $project,
        array $pages,
        array $siteSpec,
        string $source,
        array $missing,
        array $missingLandmarks,
        array &$outputs,
        array &$warnings,
    ): void {
        $common = [
            'site_spec' => $siteSpec,
            'language' => SiteSpecStep::languageOf($project),
            'theme_json' => $project->readJson('theme/theme.json'),
            'design_direction' => DesignDirectionStep::readFor($project),
            'outline' => 'HTML-first page content',
            'site_pages' => PagePlanStep::sitePagesList($pages),
        ];
        $requests = [];
        $inputs = [];
        foreach ($missing as $area) {
            $input = $common;
            if ($area === 'header') {
                $input += self::headerUnitInput($project, $pages, $siteSpec);
                $inputs[$area] = $input;
                $requests[$area] = $this->headerUnit->request($input);
            } else {
                $frontSections = [];
                foreach ($pages as $candidate) {
                    if (!empty($candidate['front'])) {
                        $frontSections = (array) ($candidate['sections'] ?? []);
                        break;
                    }
                }
                $footerArchetype = FooterComposition::archetypeForProject($project);
                $input += [
                    'final_section_brief' => SectionsStep::finalSectionBrief($frontSections),
                    'composition_archetype' => $footerArchetype,
                    'surface' => FooterComposition::resolveSurface(
                        $footerArchetype,
                        SectionsStep::closingBackgrounds($pages),
                    ),
                    'page_count' => count($pages),
                ];
                $inputs[$area] = $input;
                $requests[$area] = $this->footerUnit->request($input);
            }
        }

        try {
            $batch = $this->llm->completeBatch($requests);
        } catch (\RuntimeException $error) {
            $batch = null;
            $warnings[] = "source {$source} selector shell diagnostic_code shell_generation_failed "
                . 'authored_value missing shell delivered_value deterministic minimal shell disposition retained '
                . "error {$error->getMessage()}";
        }
        foreach ($missing as $area) {
            $path = "theme/parts/{$area}.html";
            $diagnosticCode = in_array($area, $missingLandmarks, true)
                ? 'missing_shell_landmark'
                : 'shell_transform_degraded';
            $notes = [];
            try {
                $raw = $batch?->texts[$area] ?? null;
                if (!is_string($raw)) {
                    throw new \RuntimeException('shell batch returned no result');
                }
                $result = $area === 'header'
                    ? $this->headerUnit->finish($raw, $inputs[$area])
                    : $this->footerUnit->finish($raw, $inputs[$area]);
                $markup = $result->markup;
                array_push($notes, ...$result->warnings);
            } catch (\RuntimeException $error) {
                $markup = SectionsStep::fallbackChrome($area);
                $notes[] = "missing {$area}: blocks shell output unusable; deterministic minimal shell delivered";
            }
            $outputs[$path] = rtrim($markup) . "\n";
            $authoredValue = $diagnosticCode === 'missing_shell_landmark' ? 'missing' : 'unusable transformed shell';
            $warnings[] = "source {$source} selector {$area} diagnostic_code {$diagnosticCode} "
                . "authored_value {$authoredValue} delivered_value {$path} "
                . "disposition repaired via blocks {$area} prompt";
            array_push($warnings, ...$notes);
            if ($batch !== null) {
                array_push($warnings, ...$batch->notesFor($area));
            }
        }
    }

    /**
     * HeaderUnit requires a delivery-phase above-fold contract and a behavior
     * brief. TransformSiteStep::generateMissingChrome omits both (dead for
     * header). Built in-memory; nothing is written to aboveFold.json.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,mixed> $siteSpec
     * @return array<string,mixed>
     */
    private static function headerUnitInput(Project $project, array $pages, array $siteSpec): array
    {
        $pagesForContract = self::pagesWithOpeningSection($project, $pages);
        $blueprint = DesignDirectionStep::heroBlueprintFor($project);
        $footerArchetype = FooterComposition::archetypeForProject($project);
        $footerSurface = FooterComposition::resolveSurface(
            $footerArchetype,
            SectionsStep::closingBackgrounds($pagesForContract),
        );
        $contract = AboveFoldContract::resolve(
            pages: $pagesForContract,
            blueprint: $blueprint,
            canvas: DesignDirectionStep::canvasFor($project),
            themeContext: $project->readJson('theme/theme.json'),
            siteContext: [
                'stable_id' => (string) ($siteSpec['slug'] ?? $project->slug()),
                'writing_direction' => SiteSpecStep::writingDirectionOf($project),
                'page_count' => count($pagesForContract),
                'tagline' => PlaygroundArtifact::blogDescription($siteSpec),
            ],
            footerContext: [
                'archetype' => $footerArchetype,
                'surface' => $footerSurface,
            ],
            forcedHeaderArchetype: Env::get(AboveFoldContract::HEADER_ARCHETYPE_ENV),
            designCss: $project->exists(TransformArtifacts::SITE_CSS)
                ? $project->readText(TransformArtifacts::SITE_CSS)
                : null,
        );
        $headerBehavior = HeaderBehavior::resolve(
            $pagesForContract,
            (string) $contract['header']['mode'],
            ContrastFixStep::paletteMap($project->readJson('theme/theme.json')),
            (string) $contract['header']['archetype'] ?: null,
            HeaderBehavior::transitionFor(DesignDirectionStep::motionProfileFor($project)),
        )['behavior'];

        return [
            'hero_brief' => 'HTML-first homepage',
            'nav_rule' => SectionsStep::navRuleFor(
                count($pages),
                (string) $contract['header']['archetype'],
            ),
            'archetype_assignment' => 'standard-row',
            'above_fold_contract' => $contract,
            'header_behavior' => HeaderBehavior::promptContract($headerBehavior),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private static function pagesWithOpeningSection(Project $project, array $pages): array
    {
        $blueprint = DesignDirectionStep::heroBlueprintFor($project);
        $projection = HeroComposition::planProjection($blueprint);
        $opening = [
            'slug' => 'hero',
            'title' => 'Hero',
            'layout_archetype' => $projection['layout_archetype'],
            'background' => $projection['default_background'],
        ];
        $out = [];
        foreach ($pages as $page) {
            $sections = array_values(array_filter((array) ($page['sections'] ?? []), 'is_array'));
            if ($sections === []) {
                $page['sections'] = [$opening];
            }
            $out[] = $page;
        }
        return $out;
    }

    /**
     * Author stylesheet the transformer resolves fragment images against:
     * the shared site.css plus this page's own inline <style> blocks.
     */
    private static function pageAuthorCss(string $siteCss, string $html): string
    {
        $parts = [];
        if (trim($siteCss) !== '') {
            $parts[] = $siteCss;
        }
        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $matches)) {
            foreach ($matches[1] as $block) {
                if (trim($block) !== '') {
                    $parts[] = $block;
                }
            }
        }
        return implode("\n", $parts);
    }

    /** @param array<int,array<string,mixed>> $fallbacks */
    private static function firstUnsupported(array $fallbacks): ?array
    {
        foreach ($fallbacks as $fallback) {
            if (is_array($fallback) && self::fallbackCode($fallback) === 'html_unsupported_element') {
                return $fallback;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $fallback */
    private static function fallbackCode(array $fallback): string
    {
        return is_string($fallback['diagnostic_code'] ?? null)
            ? $fallback['diagnostic_code']
            : (is_string($fallback['reason_code'] ?? null) ? $fallback['reason_code'] : '');
    }

    /** @param array<int,array<string,mixed>> $assets */
    private static function lostCarrierClass(array $assets, string $markup): ?string
    {
        $classes = [];
        foreach (self::engineSupportContents([$assets]) as $contents) {
            foreach ($contents as $content) {
                if (!preg_match_all(
                    '/\\.(be-inline-geometry-[a-z0-9-]+)\\{[^{}]*\\}/i',
                    $content,
                    $matches,
                    PREG_SET_ORDER,
                )) {
                    continue;
                }
                foreach ($matches as $match) {
                    $classes[$match[1]] = true;
                }
            }
        }
        ksort($classes);
        foreach ($classes as $class => $_present) {
            if (!str_contains($markup, $class)) {
                return $class;
            }
        }
        return null;
    }

    /**
     * @param list<array<int,array<string,mixed>>> $assetSets
     * @param ?list<string> $warnings
     * @return array{'before-author':list<string>,'after-author':list<string>}
     */
    private static function engineSupportContents(array $assetSets, ?array &$warnings = null): array
    {
        $contents = [
            'before-author' => [],
            'after-author' => [],
        ];
        $seen = [
            'before-author' => [],
            'after-author' => [],
        ];
        foreach ($assetSets as $assets) {
            foreach ($assets as $asset) {
                if (!is_array($asset)
                    || ($asset['kind'] ?? null) !== 'css'
                    || !is_string($asset['content'] ?? null)
                    || trim($asset['content']) === ''
                ) {
                    continue;
                }

                $source = $asset['source'] ?? null;
                if ($source === 'wordpress-compat') {
                    $placement = 'after-author';
                } elseif ($source === 'engine-support') {
                    $placement = $asset['stylesheet_placement'] ?? null;
                    if (!is_string($placement) || !array_key_exists($placement, $contents)) {
                        if ($warnings !== null) {
                            $warnings[] = self::unknownEngineSupportPlacementWarning($asset, $placement);
                        }
                        continue;
                    }
                } else {
                    continue;
                }

                $content = $asset['content'];
                if (isset($seen[$placement][$content])) {
                    continue;
                }
                $seen[$placement][$content] = true;
                $contents[$placement][] = $content;
            }
        }
        return $contents;
    }

    /**
     * @param list<array<int,array<string,mixed>>> $assetSets
     * @param list<string> $warnings
     * @return array{'before-author':string,'after-author':string}
     */
    private static function carriedCss(array $assetSets, array &$warnings): array
    {
        $carried = [
            'before-author' => '',
            'after-author' => '',
        ];
        foreach (self::engineSupportContents($assetSets, $warnings) as $placement => $contents) {
            foreach ($contents as $content) {
                $carried[$placement] .= $content;
                if (!str_ends_with($content, "\n")) {
                    $carried[$placement] .= "\n";
                }
            }
        }
        return $carried;
    }

    /** @param array<string,mixed> $asset */
    private static function unknownEngineSupportPlacementWarning(array $asset, mixed $placement): string
    {
        $path = $asset['path'] ?? $asset['target_path'] ?? $asset['source_path'] ?? '(unknown transformer asset)';
        $path = is_string($path) && trim($path) !== ''
            ? self::oneLine($path)
            : '(unknown transformer asset)';
        $jsonFlags = JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $file = json_encode($path, $jsonFlags);
        $authored = json_encode(['stylesheet_placement' => $placement], $jsonFlags);

        return 'file=' . (is_string($file) ? $file : '"(unknown transformer asset)"')
            . ' block_path="transformer asset ' . $path . '"'
            . ' authored_value=' . (is_string($authored) ? $authored : '{"stylesheet_placement":null}')
            . ' delivered_value=removed disposition=dropped'
            . ' reason=unrecognized engine-support stylesheet placement';
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
