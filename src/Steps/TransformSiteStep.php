<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\SiteBuild\DesignMarkupSanitizer;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionRole;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TransformArtifacts;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\HeaderUnit;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Deterministically compile HTML-first design artifacts into legacy section
 * part paths. Generated-content failures are isolated to one section; every
 * removal is durable in both transform-report.json and warnings.json.
 */
final class TransformSiteStep implements Step
{
    use LlmOptions;

    private const DEFAULT_REPAIR_BUDGET = 12;

    private const SUPPORTED_SLICE = 'Headings, paragraphs, lists, quotes, code, tables, images, links, buttons, '
        . 'and semantic or presentational wrappers. No script, SVG, form controls, custom elements, iframe, '
        . 'canvas, dialog, or behavior-bearing markup.';

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
        return 'transform-site';
    }

    public function label(): string
    {
        return 'Transform the designed site into block parts';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'meta.json',
                'siteSpec.json',
                'designDirection.json',
                'theme/theme.json',
                'design/*',
            ],
            writes: [
                'theme/parts/*',
                'pages.json',
                TransformArtifacts::CARRIED_CSS,
                TransformArtifacts::REPORT,
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $siteSpec = $project->readJson('siteSpec.json');
        $pages = PagePlanStep::flattenPages($siteSpec);
        $project->readJson('theme/theme.json');
        $project->readJson('designDirection.json');
        $siteCss = $project->readText(TransformArtifacts::SITE_CSS);

        [$artifact, $sourceHtml] = $this->artifact($project, $pages, $siteCss);
        $warnings = [];
        $fallbackCodes = [];
        $repairOutcomes = [];
        $droppedFragments = [];
        $assetSets = [];

        try {
            $bundle = (new ArtifactCompiler())->compile($artifact);
        } catch (\InvalidArgumentException|\TypeError $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            $bundle = null;
            $fallbackCodes[] = 'bundle_transform_failed';
            $warnings[] = 'source design/*.html selector bundle diagnostic_code bundle_transform_failed '
                . 'authored_value complete design bundle delivered_value per-fragment transformation '
                . "disposition retained error {$error->getMessage()}";
        }

        $bundleFallbacks = $bundle?->fallbacks ?? [];
        if ($bundle instanceof TransformerResult) {
            $assetSets[] = $bundle->assets;
        }
        foreach ($bundleFallbacks as $fallback) {
            if (is_array($fallback) && ($code = self::fallbackCode($fallback)) !== '') {
                $fallbackCodes[] = $code;
            }
        }

        $fragments = [];
        $pageFragmentKeys = [];
        $chromeKeys = [];
        foreach ($pages as $pageIndex => $page) {
            $slug = (string) $page['slug'];
            $source = !empty($page['front']) ? 'home.html' : "{$slug}.html";
            $html = $sourceHtml[$source] ?? null;
            if (!is_string($html)) {
                throw new \RuntimeException("transform-site: required design artifact design/{$source} is absent");
            }
            $extracted = self::extractPage($html, "design/{$source}");
            if (!empty($page['front'])) {
                foreach (['header', 'footer'] as $area) {
                    if (!is_array($extracted[$area] ?? null)) {
                        continue;
                    }
                    $key = "chrome:{$area}";
                    $fragments[$key] = $extracted[$area] + [
                        'key' => $key,
                        'kind' => 'chrome',
                        'page_slug' => $slug,
                        'section_slug' => $area,
                        'output' => "theme/parts/{$area}.html",
                    ];
                    $chromeKeys[$area] = $key;
                }
            }
            foreach ($extracted['sections'] as $section) {
                $sectionSlug = (string) $section['slug'];
                $key = "page:{$slug}:{$sectionSlug}";
                $fragments[$key] = $section + [
                    'key' => $key,
                    'kind' => 'section',
                    'page_slug' => $slug,
                    'output' => 'theme/parts/' . SectionsStep::partSlug($slug, $sectionSlug) . '.html',
                ];
                $pageFragmentKeys[$pageIndex][] = $key;
            }
        }

        $fallbacksByFragment = self::locateFallbacks($bundleFallbacks, $fragments);
        $repairBudget = max(0, (int) ($meta['repair_budget'] ?? self::DEFAULT_REPAIR_BUDGET));
        $repairRequests = [];
        $repairTargets = [];
        $dropped = [];
        $usedBudget = 0;

        foreach ($fallbacksByFragment as $fragmentKey => $fallbacks) {
            $unsupported = array_values(array_filter(
                $fallbacks,
                static fn (array $fallback): bool => self::fallbackCode($fallback) === 'html_unsupported_element',
            ));
            if ($unsupported === []) {
                continue;
            }
            if ($usedBudget + count($unsupported) > $repairBudget) {
                $fallback = $unsupported[0];
                $drop = self::dropRow(
                    $fragments[$fragmentKey],
                    $fallback,
                    'repair_budget_exhausted',
                );
                $dropped[$fragmentKey] = true;
                $repairOutcomes[] = $drop;
                $droppedFragments[] = $drop;
                $fallbackCodes[] = 'repair_budget_exhausted';
                $warnings[] = self::dropWarning($fragments[$fragmentKey], $drop, 'repair_budget exhausted');
                continue;
            }
            foreach ($unsupported as $fallback) {
                $requestKey = 'repair-' . count($repairRequests);
                $repairRequests[$requestKey] = $this->withOptions([
                    'prompt' => $this->renderer->render(TransformArtifacts::REPAIR_PROMPT, [
                        'fragment' => self::fallbackHtml($fallback),
                        'supported_slice' => self::SUPPORTED_SLICE,
                    ]),
                ]);
                $repairTargets[$requestKey] = [
                    'fragment_key' => $fragmentKey,
                    'fallback' => $fallback,
                ];
                $usedBudget++;
            }
        }

        if ($repairRequests !== []) {
            try {
                $batch = $this->llm->completeBatch($repairRequests);
                foreach ($repairTargets as $requestKey => $target) {
                    $fragmentKey = $target['fragment_key'];
                    if (isset($dropped[$fragmentKey])) {
                        continue;
                    }
                    $raw = $batch->texts[$requestKey] ?? null;
                    if (!is_string($raw) || trim($raw) === '') {
                        $drop = self::dropRow($fragments[$fragmentKey], $target['fallback'], 'repair_response_missing');
                        $dropped[$fragmentKey] = true;
                        $repairOutcomes[] = $drop;
                        $droppedFragments[] = $drop;
                        $fallbackCodes[] = 'repair_response_missing';
                        $warnings[] = self::dropWarning($fragments[$fragmentKey], $drop, 'repair response missing');
                        continue;
                    }
                    $sanitizerWarnings = [];
                    try {
                        $replacement = DesignMarkupSanitizer::sanitize(
                            trim($raw),
                            (string) $fragments[$fragmentKey]['source'],
                            'unsupported fragment repair',
                            $sanitizerWarnings,
                        );
                    } catch (\RuntimeException $error) {
                        $replacement = '';
                        $sanitizerWarnings[] = $error->getMessage();
                    }
                    array_push($warnings, ...$sanitizerWarnings);
                    $authored = self::fallbackHtml($target['fallback']);
                    $replaced = self::replaceFirst((string) $fragments[$fragmentKey]['html'], $authored, $replacement);
                    if ($replacement === '' || $replaced === null) {
                        $drop = self::dropRow($fragments[$fragmentKey], $target['fallback'], 'repair_not_local');
                        $dropped[$fragmentKey] = true;
                        $repairOutcomes[] = $drop;
                        $droppedFragments[] = $drop;
                        $fallbackCodes[] = 'repair_not_local';
                        $warnings[] = self::dropWarning($fragments[$fragmentKey], $drop, 'repair could not be applied locally');
                        continue;
                    }
                    $fragments[$fragmentKey]['html'] = $replaced;
                    $repairOutcomes[] = self::contextRow(
                        source: (string) $fragments[$fragmentKey]['source'],
                        selector: (string) ($target['fallback']['selector'] ?? $fragments[$fragmentKey]['selector']),
                        diagnosticCode: 'html_unsupported_element',
                        authoredValue: $authored,
                        deliveredValue: $replacement,
                        disposition: 'repaired',
                    );
                    array_push($warnings, ...$batch->notesFor($requestKey));
                }
            } catch (\RuntimeException $error) {
                foreach ($repairTargets as $target) {
                    $fragmentKey = $target['fragment_key'];
                    if (isset($dropped[$fragmentKey])) {
                        continue;
                    }
                    $drop = self::dropRow($fragments[$fragmentKey], $target['fallback'], 'repair_batch_failed');
                    $dropped[$fragmentKey] = true;
                    $repairOutcomes[] = $drop;
                    $droppedFragments[] = $drop;
                    $fallbackCodes[] = 'repair_batch_failed';
                    $warnings[] = self::dropWarning(
                        $fragments[$fragmentKey],
                        $drop,
                        "repair batch failed: {$error->getMessage()}",
                    );
                }
            }
        }

        $outputs = [];
        foreach ($fragments as $fragmentKey => $fragment) {
            if (isset($dropped[$fragmentKey])) {
                continue;
            }
            try {
                $result = (new ArtifactCompiler())->compileFragment(
                    (string) $fragment['html'],
                    (string) $fragment['source'],
                );
            } catch (\InvalidArgumentException|\TypeError $error) {
                throw $error;
            } catch (\RuntimeException $error) {
                $drop = self::dropRow($fragment, [], 'fragment_transform_failed');
                $dropped[$fragmentKey] = true;
                $repairOutcomes[] = $drop;
                $droppedFragments[] = $drop;
                $fallbackCodes[] = 'fragment_transform_failed';
                $warnings[] = self::dropWarning($fragment, $drop, "fragment transform failed: {$error->getMessage()}");
                continue;
            }
            $assetSets[] = $result->assets;
            foreach ($result->fallbacks as $fallback) {
                if (is_array($fallback) && ($code = self::fallbackCode($fallback)) !== '') {
                    $fallbackCodes[] = $code;
                }
            }
            $residual = self::firstUnsupported($result->fallbacks);
            if ($residual !== null) {
                $drop = self::dropRow($fragment, $residual, 'unsupported_after_repair');
                $dropped[$fragmentKey] = true;
                $repairOutcomes[] = $drop;
                $droppedFragments[] = $drop;
                $fallbackCodes[] = 'unsupported_after_repair';
                $warnings[] = self::dropWarning($fragment, $drop, 'unsupported element remained after bounded repair');
                continue;
            }
            $markup = trim($result->serializedBlocks);
            if ($markup === '' && trim(strip_tags((string) $fragment['html'])) !== '') {
                $fallback = is_array($result->fallbacks[0] ?? null) ? $result->fallbacks[0] : [];
                $code = self::fallbackCode($fallback);
                if ($code === '') {
                    $code = 'empty_transform_output';
                }
                $drop = self::dropRow($fragment, $fallback, $code);
                $dropped[$fragmentKey] = true;
                $repairOutcomes[] = $drop;
                $droppedFragments[] = $drop;
                $fallbackCodes[] = $code;
                $warnings[] = self::dropWarning($fragment, $drop, 'transform emitted no materializable block markup');
                continue;
            }
            $lostCarrier = self::lostCarrierClass($result->assets, $markup);
            if ($lostCarrier !== null) {
                $fallback = [
                    'diagnostic_code' => 'carrier_class_dropped',
                    'selector' => (string) $fragment['selector'],
                    'html' => (string) $fragment['html'],
                ];
                $drop = self::dropRow($fragment, $fallback, 'carrier_class_dropped');
                $dropped[$fragmentKey] = true;
                $repairOutcomes[] = $drop;
                $droppedFragments[] = $drop;
                $fallbackCodes[] = 'carrier_class_dropped';
                $warnings[] = self::dropWarning(
                    $fragment,
                    $drop,
                    "native geometry carrier {$lostCarrier} was not serialized",
                );
                continue;
            }
            $outputs[(string) $fragment['output']] = rtrim($markup) . "\n";
        }

        $missingLandmarks = array_values(array_diff(['header', 'footer'], array_keys($chromeKeys)));
        $chromeNeedsGeneration = array_values(array_filter(
            ['header', 'footer'],
            static fn (string $area): bool => !isset($outputs["theme/parts/{$area}.html"]),
        ));
        if ($chromeNeedsGeneration !== []) {
            $this->generateMissingChrome(
                $project,
                $pages,
                $siteSpec,
                $chromeNeedsGeneration,
                $missingLandmarks,
                $outputs,
                $fallbackCodes,
                $repairOutcomes,
                $warnings,
            );
        }

        $deliveredPages = [];
        foreach ($pages as $pageIndex => $page) {
            $sections = [];
            $keys = $pageFragmentKeys[$pageIndex] ?? [];
            foreach ($keys as $key) {
                if (isset($dropped[$key]) || !isset($outputs[$fragments[$key]['output']])) {
                    continue;
                }
                $fragment = $fragments[$key];
                $sections[] = [
                    'slug' => (string) $fragment['section_slug'],
                    'title' => (string) $fragment['title'],
                    'type' => 'content',
                    'purpose' => '',
                    'content_notes' => '',
                    'layout_archetype' => 'mixed-width-editorial',
                    'background' => 'base',
                    'vertical_density' => 'standard',
                    'handoff' => '',
                ];
            }
            foreach ($sections as $index => &$section) {
                $section['role'] = SectionRole::forPosition($index, count($sections));
            }
            unset($section);
            $source = 'design/' . (!empty($page['front']) ? 'home.html' : "{$page['slug']}.html");
            if ($sections === [] && !empty($page['front'])) {
                $fallbackSlug = 'content';
                $path = 'theme/parts/' . SectionsStep::partSlug((string) $page['slug'], $fallbackSlug) . '.html';
                $outputs[$path] = '<!-- wp:group {"anchor":"content","tagName":"section"} -->'
                    . '<section id="content" class="wp-block-group"><!-- wp:site-title /--></section>'
                    . "<!-- /wp:group -->\n";
                $sections[] = [
                    'slug' => $fallbackSlug,
                    'title' => 'Content',
                    'type' => 'content',
                    'purpose' => '',
                    'content_notes' => '',
                    'layout_archetype' => 'mixed-width-editorial',
                    'background' => 'base',
                    'vertical_density' => 'standard',
                    'handoff' => '',
                    'role' => SectionRole::HERO,
                ];
                $fallbackCodes[] = 'front_page_content_fallback';
                $repairOutcomes[] = self::contextRow(
                    source: $source,
                    selector: 'page',
                    diagnosticCode: 'front_page_content_fallback',
                    authoredValue: 'no materializable page sections',
                    deliveredValue: $path,
                    disposition: 'retained',
                );
                $warnings[] = "source {$source} selector page block_path {$path} "
                    . 'diagnostic_code front_page_content_fallback authored_value no materializable page sections '
                    . "delivered_value {$path} disposition retained";
            } elseif ($sections === []) {
                $drop = self::contextRow(
                    source: $source,
                    selector: 'page',
                    diagnosticCode: 'page_content_dropped',
                    authoredValue: 'no materializable page sections',
                    deliveredValue: 'removed',
                    disposition: 'dropped',
                );
                $fallbackCodes[] = 'page_content_dropped';
                $repairOutcomes[] = $drop;
                $droppedFragments[] = $drop;
                $warnings[] = "source {$source} selector page block_path pages.json "
                    . 'diagnostic_code page_content_dropped authored_value no materializable page sections '
                    . 'delivered_value removed disposition dropped';
                continue;
            }
            $page['sections'] = $sections;
            $deliveredPages[] = $page;
        }

        foreach ($outputs as $path => $content) {
            $project->writeText($path, $content);
        }
        $project->writeJson('pages.json', ['pages' => $deliveredPages]);

        $allMarkup = implode("\n", $outputs);
        $project->writeText(TransformArtifacts::CARRIED_CSS, self::carriedCss($assetSets, $allMarkup));

        $fallbackCodes = array_values(array_unique(array_filter($fallbackCodes)));
        sort($fallbackCodes);
        $project->writeJson(TransformArtifacts::REPORT, [
            'fallback_codes' => $fallbackCodes,
            'repair_outcomes' => array_values($repairOutcomes),
            'dropped_fragments' => array_values($droppedFragments),
        ]);
        $project->addWarnings($this->id(), $warnings);
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    private function artifact(Project $project, array $pages, string $siteCss): array
    {
        $files = [];
        $sources = [];
        $expected = ['home.html' => true];
        foreach ($pages as $page) {
            if (empty($page['front'])) {
                $expected[(string) $page['slug'] . '.html'] = true;
            }
        }
        foreach (glob($project->path('design/*.html')) ?: [] as $path) {
            $name = basename($path);
            $content = $project->readText("design/{$name}");
            $files[] = [
                'path' => $name,
                'content' => $content,
                'kind' => 'html',
                'entrypoint' => $name === 'home.html',
            ];
            if (isset($expected[$name])) {
                $sources[$name] = $content;
            }
        }
        foreach (array_keys($expected) as $name) {
            if (!isset($sources[$name])) {
                throw new \RuntimeException("transform-site: required design artifact design/{$name} is absent");
            }
        }
        usort($files, static function (array $left, array $right): int {
            if (($left['path'] === 'home.html') !== ($right['path'] === 'home.html')) {
                return $left['path'] === 'home.html' ? -1 : 1;
            }
            return strcmp((string) $left['path'], (string) $right['path']);
        });
        $files[] = ['path' => 'site.css', 'content' => $siteCss, 'kind' => 'css'];
        return [[
            'schema' => ArtifactCompiler::INPUT_SCHEMA,
            'entrypoint' => 'home.html',
            'files' => $files,
        ], $sources];
    }

    /**
     * @return array{header:?array<string,string>,footer:?array<string,string>,sections:list<array<string,string>>}
     */
    private static function extractPage(string $html, string $source): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        try {
            $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new \RuntimeException("transform-site: {$source} is not parseable HTML");
        }
        $xpath = new DOMXPath($dom);
        $header = self::firstElement($xpath, '//header[not(ancestor::main)] | //*[@role="banner"][not(ancestor::main)]');
        $footer = self::firstElement($xpath, '//footer[not(ancestor::main)] | //*[@role="contentinfo"][not(ancestor::main)]');
        $main = self::firstElement($xpath, '//main');
        $body = self::firstElement($xpath, '//body');
        $container = $main ?? $body;
        if (!$container instanceof DOMElement) {
            throw new \RuntimeException("transform-site: {$source} has no materializable body");
        }

        $elements = [];
        foreach ($container->childNodes as $child) {
            if (!$child instanceof DOMElement || in_array(strtolower($child->tagName), ['header', 'footer', 'style', 'script'], true)) {
                continue;
            }
            $elements[] = $child;
        }
        if ($elements === [] && trim($container->textContent) !== '') {
            $elements[] = $container;
        }

        $sections = [];
        $used = [];
        foreach ($elements as $index => $element) {
            $rawSlug = trim($element->getAttribute('id'));
            $base = ProjectStore::slugify($rawSlug !== '' ? $rawSlug : strtolower($element->tagName) . '-' . ($index + 1));
            if ($base === '') {
                $base = 'section-' . ($index + 1);
            }
            $slug = $base;
            for ($suffix = 2; isset($used[$slug]); $suffix++) {
                $slug = $base . '-' . $suffix;
            }
            $used[$slug] = true;
            $serialized = (string) $dom->saveHTML($element);
            if (strtolower($element->tagName) !== 'section') {
                $serialized = '<section id="' . htmlspecialchars($slug, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">'
                    . $serialized . '</section>';
            }
            $heading = self::firstElement(new DOMXPath($dom), './/h1 | .//h2 | .//h3', $element);
            $title = trim($heading?->textContent ?? '');
            $sections[] = [
                'source' => $source,
                'selector' => $rawSlug !== '' ? "#{$rawSlug}" : "{$element->tagName}:nth-child(" . ($index + 1) . ')',
                'slug' => $slug,
                'section_slug' => $slug,
                'title' => $title !== '' ? $title : ucwords(str_replace('-', ' ', $slug)),
                'html' => $serialized,
            ];
        }
        return [
            'header' => self::elementFragment($dom, $header, $source, 'header'),
            'footer' => self::elementFragment($dom, $footer, $source, 'footer'),
            'sections' => $sections,
        ];
    }

    private static function firstElement(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?DOMElement
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes !== false ? $nodes->item(0) : null;
        return $node instanceof DOMElement ? $node : null;
    }

    /** @return array<string,string>|null */
    private static function elementFragment(
        DOMDocument $dom,
        ?DOMElement $element,
        string $source,
        string $area,
    ): ?array {
        if (!$element instanceof DOMElement) {
            return null;
        }
        return [
            'source' => $source,
            'selector' => $area,
            'slug' => $area,
            'section_slug' => $area,
            'title' => ucfirst($area),
            'html' => (string) $dom->saveHTML($element),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $fallbacks
     * @param array<string,array<string,mixed>> $fragments
     * @return array<string,list<array<string,mixed>>>
     */
    private static function locateFallbacks(array $fallbacks, array $fragments): array
    {
        $located = [];
        foreach ($fallbacks as $fallback) {
            if (!is_array($fallback) || self::fallbackCode($fallback) !== 'html_unsupported_element') {
                continue;
            }
            $source = 'design/' . basename((string) ($fallback['source'] ?? ''));
            $html = self::fallbackHtml($fallback);
            foreach ($fragments as $key => $fragment) {
                if ($fragment['source'] !== $source || $html === '' || !str_contains((string) $fragment['html'], $html)) {
                    continue;
                }
                $located[$key][] = $fallback;
                break;
            }
        }
        return $located;
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

    /** @param array<string,mixed> $fallback */
    private static function fallbackHtml(array $fallback): string
    {
        return is_string($fallback['html'] ?? null) ? $fallback['html'] : '';
    }

    private static function replaceFirst(string $haystack, string $needle, string $replacement): ?string
    {
        if ($needle === '' || ($offset = strpos($haystack, $needle)) === false) {
            return null;
        }
        return substr($haystack, 0, $offset) . $replacement . substr($haystack, $offset + strlen($needle));
    }

    /** @param array<string,mixed> $fragment @param array<string,mixed> $fallback */
    private static function dropRow(array $fragment, array $fallback, string $defaultCode): array
    {
        return self::contextRow(
            source: (string) $fragment['source'],
            selector: (string) ($fallback['selector'] ?? $fragment['selector']),
            diagnosticCode: self::fallbackCode($fallback) !== '' ? self::fallbackCode($fallback) : $defaultCode,
            authoredValue: self::fallbackHtml($fallback) !== ''
                ? self::fallbackHtml($fallback)
                : (string) $fragment['html'],
            deliveredValue: 'removed',
            disposition: 'dropped',
        );
    }

    private static function contextRow(
        string $source,
        string $selector,
        string $diagnosticCode,
        string $authoredValue,
        string $deliveredValue,
        string $disposition,
    ): array {
        return [
            'source' => $source,
            'selector' => $selector,
            'diagnostic_code' => $diagnosticCode,
            'authored_value' => $authoredValue,
            'delivered_value' => $deliveredValue,
            'disposition' => $disposition,
        ];
    }

    /** @param array<string,mixed> $fragment @param array<string,mixed> $drop */
    private static function dropWarning(array $fragment, array $drop, string $reason): string
    {
        return 'source ' . $drop['source']
            . ' selector ' . $drop['selector']
            . ' block_path ' . $fragment['output']
            . ' diagnostic_code ' . $drop['diagnostic_code']
            . ' authored_value ' . self::oneLine((string) $drop['authored_value'])
            . ' delivered_value removed disposition dropped'
            . " reason {$reason}";
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /** @param array<int,array<string,mixed>> $assets */
    private static function lostCarrierClass(array $assets, string $markup): ?string
    {
        foreach (self::carrierRules([$assets]) as $class => $_rule) {
            if (!str_contains($markup, $class)) {
                return $class;
            }
        }
        return null;
    }

    /**
     * @param list<array<int,array<string,mixed>>> $assetSets
     * @return array<string,string>
     */
    private static function carrierRules(array $assetSets): array
    {
        $rules = [];
        foreach ($assetSets as $assets) {
            foreach ($assets as $asset) {
                if (!is_array($asset) || !is_string($asset['content'] ?? null)) {
                    continue;
                }
                if (preg_match_all(
                    '/\\.(be-inline-geometry-[a-z0-9-]+)\\{[^{}]*\\}/i',
                    $asset['content'],
                    $matches,
                    PREG_SET_ORDER,
                )) {
                    foreach ($matches as $match) {
                        $rules[$match[1]] = $match[0];
                    }
                }
            }
        }
        ksort($rules);
        return $rules;
    }

    /** @param list<array<int,array<string,mixed>>> $assetSets */
    private static function carriedCss(array $assetSets, string $markup): string
    {
        $kept = [];
        foreach (self::carrierRules($assetSets) as $class => $rule) {
            if (str_contains($markup, $class)) {
                $kept[] = $rule;
            }
        }
        return $kept === [] ? '' : implode("\n", $kept) . "\n";
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param list<string> $missing
     * @param list<string> $missingLandmarks
     * @param array<string,string> $outputs
     * @param list<string> $fallbackCodes
     * @param list<array<string,string>> $repairOutcomes
     * @param list<string> $warnings
     */
    private function generateMissingChrome(
        Project $project,
        array $pages,
        array $siteSpec,
        array $missing,
        array $missingLandmarks,
        array &$outputs,
        array &$fallbackCodes,
        array &$repairOutcomes,
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
        foreach ($missing as $area) {
            $input = $common;
            if ($area === 'header') {
                $input += [
                    'hero_brief' => 'HTML-first homepage',
                    'nav_rule' => SectionsStep::navRuleFor(count($pages)),
                    'archetype_assignment' => 'standard-row',
                ];
                $requests[$area] = $this->headerUnit->request($input);
            } else {
                $requests[$area] = $this->footerUnit->request($input);
            }
        }

        try {
            $batch = $this->llm->completeBatch($requests);
        } catch (\RuntimeException $error) {
            $batch = null;
            $warnings[] = "source design/home.html selector shell diagnostic_code shell_generation_failed "
                . "authored_value missing shell delivered_value deterministic minimal shell disposition retained "
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
                $markup = $area === 'header'
                    ? $this->headerUnit->finish($raw, $common + [
                        'hero_brief' => 'HTML-first homepage',
                        'nav_rule' => SectionsStep::navRuleFor(count($pages)),
                        'archetype_assignment' => 'standard-row',
                    ], $notes)
                    : $this->footerUnit->finish($raw, $common, $notes);
            } catch (\RuntimeException $error) {
                $markup = SectionsStep::fallbackChrome($area);
                $notes[] = "missing {$area}: legacy shell output unusable; deterministic minimal shell delivered";
            }
            $outputs[$path] = rtrim($markup) . "\n";
            $fallbackCodes[] = $diagnosticCode;
            $repairOutcomes[] = self::contextRow(
                source: 'design/home.html',
                selector: $area,
                diagnosticCode: $diagnosticCode,
                authoredValue: $diagnosticCode === 'missing_shell_landmark' ? 'missing' : 'unusable transformed shell',
                deliveredValue: $path,
                disposition: 'repaired',
            );
            $authoredValue = $diagnosticCode === 'missing_shell_landmark' ? 'missing' : 'unusable transformed shell';
            $warnings[] = "source design/home.html selector {$area} diagnostic_code {$diagnosticCode} "
                . "authored_value {$authoredValue} delivered_value {$path} "
                . "disposition repaired via legacy {$area} prompt";
            array_push($warnings, ...$notes);
            if ($batch !== null) {
                array_push($warnings, ...$batch->notesFor($area));
            }
        }
    }
}
