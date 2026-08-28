<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\DesignDocument;
use Automattic\SiteBuild\IslandEditableLeaves;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use DOMElement;

/**
 * Step (deterministic): the --html-islands page half. Each direct element
 * child of the design <main> ships as one core/html island in a transient
 * theme/parts/ file; assemble-pages concatenates those parts into
 * plugin/pages/<slug>.html.
 *
 * Split copies TransformSiteStep::extractPage's child walk (slug naming,
 * numeric-suffix dedupe, header/footer/style/script exclusion) but does
 * NOT wrap non-sections in a synthetic <section> and does NOT descend
 * through wrappers — both are contract amendments against the plan.
 *
 * A missing or unparseable FRONT page is the one deliberate fatal.
 * Every other generated-content defect degrades: skip the page or drop
 * the island, warn, continue.
 */
final class IslandPagesStep implements Step
{
    private const SKIP_TAGS = ['header', 'footer', 'style', 'script'];

    /** Direct <main> children that are page content, not chrome — exclude from islands but warn (G7). */
    private const LANDMARK_SKIP_TAGS = ['header', 'footer'];

    public function id(): string
    {
        return 'island-pages';
    }

    public function label(): string
    {
        return 'Ship design sections as HTML islands';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'siteSpec.json',
                'design/*',
                'theme/style.css',
            ],
            writes: [
                'theme/parts/*',
                'theme/style.css',
                'pages.json',
                'island-report.json',
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $allPages = PagePlanStep::flattenPages($project->readJson('siteSpec.json'));
        $warnings = [];
        $delivered = [];
        $reportPages = [];
        $skipped = [];
        $degrades = [];

        foreach ($allPages as $page) {
            $result = $this->processPage($project, $page, $warnings, $degrades);
            if ($result['skip']) {
                $skipped[] = [
                    'slug'   => (string) $page['slug'],
                    'reason' => $result['reason'],
                ];
                continue;
            }
            $delivered[] = $result['page'];
            $reportPages[] = $result['report'];
        }

        $kept = array_fill_keys(array_column($delivered, 'slug'), true);
        foreach ($delivered as &$page) {
            $parent = $page['parent'];
            if ($parent !== null && !isset($kept[$parent])) {
                $slug = (string) $page['slug'];
                $message = "malformed_design: pages.json context page {$slug}; "
                    . "authored parent {$parent}; delivered null; disposition skipped-parent-cleared";
                $warnings[] = $message;
                $degrades[] = [
                    'slug'   => $slug,
                    'reason' => "parent '{$parent}' was skipped; promoted to top level",
                ];
                $page['parent'] = null;
            }
        }
        unset($page);

        $project->writeJson('pages.json', ['pages' => $delivered]);
        $project->writeJson('island-report.json', [
            'pages'    => $reportPages,
            'skipped'  => $skipped,
            'degrades' => $degrades,
            'warnings' => $warnings,
        ]);
        $project->addWarnings($this->id(), $warnings);
        self::ensureBareWrapperCss($project);
    }

    /**
     * Direct element children of <main> that become islands.
     * Skips header/footer/style/script. No wrapper descent.
     * header/footer skips are warned in processPage (G7); style/script stay silent.
     *
     * @return list<DOMElement>
     */
    public static function islandElements(DOMElement $main): array
    {
        $elements = [];
        foreach ($main->childNodes as $child) {
            if (
                !$child instanceof DOMElement
                || in_array(strtolower($child->tagName), self::SKIP_TAGS, true)
            ) {
                continue;
            }
            $elements[] = $child;
        }
        return $elements;
    }

    /**
     * Direct header/footer children of <main>. Page content per DesignDocument
     * landmark rules, not site chrome — excluded from islands but not silent.
     *
     * @return list<string>
     */
    public static function excludedLandmarks(DOMElement $main): array
    {
        $tags = [];
        foreach ($main->childNodes as $child) {
            if (
                !$child instanceof DOMElement
                || !in_array(strtolower($child->tagName), self::LANDMARK_SKIP_TAGS, true)
            ) {
                continue;
            }
            $tags[] = strtolower($child->tagName);
        }
        return $tags;
    }

    public static function isTextOnlyFallback(DOMElement $main, array $elements): bool
    {
        if ($elements !== []) {
            return false;
        }
        $text = '';
        foreach ($main->childNodes as $child) {
            if (
                $child instanceof DOMElement
                && in_array(strtolower($child->tagName), self::SKIP_TAGS, true)
            ) {
                continue;
            }
            $text .= $child->textContent;
        }
        return trim($text) !== '';
    }

    /**
     * @param array<string,mixed> $page
     * @param list<string> $warnings
     * @param list<array<string,string>> $degrades
     * @return array{
     *   skip:bool,
     *   reason:string,
     *   page:?array<string,mixed>,
     *   report:?array<string,mixed>
     * }
     */
    private function processPage(
        Project $project,
        array $page,
        array &$warnings,
        array &$degrades,
    ): array {
        $slug = (string) $page['slug'];
        $front = !empty($page['front']);
        $rel = "design/{$slug}.html";
        $failedPath = "design/{$slug}.failed";

        if (!$front && $project->exists($failedPath)) {
            $reason = "design/{$slug}.failed marker";
            $warnings[] = "malformed_design: {$failedPath} context page {$slug}; "
                . 'authored failed marker; delivered removed; disposition skipped';
            return self::skip($reason);
        }

        if (!$project->exists($rel)) {
            $reason = "missing design artifact {$rel}";
            if ($front) {
                throw new \RuntimeException(
                    "island-pages: missing front-page design artifact {$rel} for page '{$slug}'"
                );
            }
            $warnings[] = "malformed_design: {$rel} context page {$slug}; "
                . 'authored missing; delivered removed; disposition skipped';
            return self::skip($reason);
        }

        $html = $project->readText($rel);
        $structuralErrors = [];
        $doc = DesignDocument::parse($html, $structuralErrors);
        if ($doc === null || $structuralErrors !== []) {
            $detail = $structuralErrors !== []
                ? implode('; ', $structuralErrors)
                : 'document failed to load';
            if ($front) {
                throw new \RuntimeException(
                    "island-pages: front page '{$slug}' is structurally unparseable: {$detail}"
                );
            }
            $reason = "structurally unparseable: {$detail}";
            $warnings[] = "malformed_design: {$rel} context page {$slug}; "
                . "authored {$detail}; delivered removed; disposition skipped";
            return self::skip($reason);
        }

        $main = $doc->main();
        if (!$main instanceof DOMElement) {
            $reason = 'no page-level main';
            if ($front) {
                throw new \RuntimeException(
                    "island-pages: front page '{$slug}' has no page-level main"
                );
            }
            $warnings[] = "malformed_design: {$rel} context page {$slug}; "
                . 'authored no page-level main; delivered removed; disposition skipped';
            return self::skip($reason);
        }

        $excluded = self::excludedLandmarks($main);
        foreach ($excluded as $tag) {
            $warnings[] = "malformed_design: {$rel} context page {$slug}; "
                . "authored <{$tag}> inside <main>; delivered removed; disposition excluded-landmark";
            $degrades[] = [
                'slug'   => $slug,
                'reason' => "<{$tag}> inside <main> excluded from islands",
            ];
        }

        $css = '';
        if ($project->exists('design/site.css')) {
            $css .= $project->readText('design/site.css') . "\n";
        }
        $css .= $doc->styles();

        $elements = self::islandElements($main);
        $textOnly = self::isTextOnlyFallback($main, $elements);
        if ($textOnly) {
            $stripped = $main->cloneNode(true);
            if ($stripped instanceof DOMElement) {
                foreach (iterator_to_array($stripped->childNodes) as $child) {
                    if (
                        $child instanceof DOMElement
                        && in_array(strtolower($child->tagName), self::SKIP_TAGS, true)
                    ) {
                        $stripped->removeChild($child);
                    }
                }
                $elements = [$stripped];
            } else {
                $elements = [$main];
            }
        }

        $sections = [];
        $nonSection = 0;
        $named = self::nameIslands($elements);
        foreach ($named as $island) {
            $sectionSlug = $island['slug'];
            $element = $island['element'];
            $unwrapMain = $textOnly;
            $context = "page {$slug} island {$sectionSlug}";
            try {
                $markup = $doc->sanitizedHtml($element, $rel, $context, $warnings);
            } catch (\RuntimeException $error) {
                $message = "malformed_design: {$rel} context {$context}; "
                    . "authored sanitize failed ({$error->getMessage()}); "
                    . 'delivered removed; disposition dropped';
                $warnings[] = $message;
                $degrades[] = [
                    'slug'   => $slug,
                    'reason' => "island '{$sectionSlug}' sanitize failed; dropped",
                ];
                continue;
            }
            if ($unwrapMain) {
                $markup = self::unwrapMain($markup);
            }
            $markup = trim($markup);
            if ($markup === '') {
                $message = "malformed_design: {$rel} context {$context}; "
                    . 'authored empty after sanitize; delivered removed; disposition dropped';
                $warnings[] = $message;
                $degrades[] = [
                    'slug'   => $slug,
                    'reason' => "island '{$sectionSlug}' empty after sanitize; dropped",
                ];
                continue;
            }
            if (strtolower($element->tagName) !== 'section') {
                $nonSection++;
            }
            $partRel = 'theme/parts/' . SectionsStep::partSlug($slug, $sectionSlug) . '.html';
            $project->writeText($partRel, self::htmlBlock($markup, $css, $rel, $context, $warnings));
            $sections[] = [
                'slug'  => $sectionSlug,
                'title' => self::headingTitle($element, $sectionSlug),
            ];
        }

        return [
            'skip'   => false,
            'reason' => '',
            'page'   => [
                'slug'       => $slug,
                'title'      => (string) ($page['title'] ?? ''),
                'path'       => (string) ($page['path'] ?? ''),
                'front'      => $front,
                'parent'     => isset($page['parent']) && $page['parent'] !== null
                    ? (string) $page['parent']
                    : null,
                'menu_order' => (int) ($page['menu_order'] ?? 0),
                'purpose'    => (string) ($page['purpose'] ?? ''),
                'sections'   => $sections,
            ],
            'report' => [
                'slug'                 => $slug,
                'islands'              => count($sections),
                'non_section_islands'  => $nonSection,
                'text_only'            => $textOnly,
                'excluded_landmarks'   => count($excluded),
            ],
        ];
    }

    /**
     * @param list<DOMElement> $elements
     * @return list<array{element:DOMElement,slug:string}>
     */
    private static function nameIslands(array $elements): array
    {
        $used = [];
        $named = [];
        foreach ($elements as $index => $element) {
            $rawSlug = trim($element->getAttribute('id'));
            $base = ProjectStore::slugify(
                $rawSlug !== '' ? $rawSlug : strtolower($element->tagName) . '-' . ($index + 1)
            );
            if ($base === '') {
                $base = 'section-' . ($index + 1);
            }
            $slug = $base;
            for ($suffix = 2; isset($used[$slug]); $suffix++) {
                $slug = $base . '-' . $suffix;
            }
            $used[$slug] = true;
            $named[] = [
                'element' => $element,
                'slug'    => $slug,
            ];
        }
        return $named;
    }

    private static function headingTitle(DOMElement $element, string $slug): string
    {
        $dom = $element->ownerDocument;
        if ($dom === null) {
            return ucwords(str_replace('-', ' ', $slug));
        }
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('.//h1 | .//h2 | .//h3', $element);
        $heading = $nodes !== false ? $nodes->item(0) : null;
        $title = trim($heading instanceof DOMElement ? $heading->textContent : '');
        return $title !== '' ? $title : ucwords(str_replace('-', ' ', $slug));
    }

    /**
     * @param list<string> $warnings
     */
    private static function htmlBlock(
        string $inner,
        string $css,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        $inner = IslandEditableLeaves::wrap($inner, $css, $path, $context, $warnings);
        return "<!-- wp:html -->\n{$inner}\n<!-- /wp:html -->\n";
    }

    private static function ensureBareWrapperCss(Project $project): void
    {
        if (!$project->exists('theme/style.css')) {
            return;
        }
        $style = $project->readText('theme/style.css');
        if (str_contains($style, '.' . IslandEditableLeaves::BARE_WRAPPER_CLASS)) {
            return;
        }
        $rule = IslandEditableLeaves::BARE_WRAPPER_CSS;
        $marker = '/* Wrap at spaces only — never split a word mid-token. */';
        $offset = strpos($style, $marker);
        if ($offset === false) {
            $project->writeText('theme/style.css', rtrim($style) . "\n\n" . $rule);
            return;
        }
        $project->writeText(
            'theme/style.css',
            rtrim(substr($style, 0, $offset)) . "\n\n" . $rule . "\n" . substr($style, $offset),
        );
    }

    private static function unwrapMain(string $html): string
    {
        if (preg_match('~^<main\b[^>]*>(.*)</main>\s*$~is', $html, $match) === 1) {
            return $match[1];
        }
        return $html;
    }

    /**
     * @return array{skip:true,reason:string,page:null,report:null}
     */
    private static function skip(string $reason): array
    {
        return [
            'skip'   => true,
            'reason' => $reason,
            'page'   => null,
            'report' => null,
        ];
    }
}
