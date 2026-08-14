<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\DeterministicNavLinkResolver;
use Automattic\SiteBuild\Html;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\NavLinkResolver;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Resolve authored navigation links after transformed pages and chrome exist.
 *
 * Project I/O stays here; NavLinkResolver remains pure. Shared chrome has no
 * current page, while each page part receives its owning public path. A file
 * changes only after its complete resolution result is available.
 */
final class ResolveNavLinksStep implements Step
{
    private const REPORT = 'reports/nav-links.json';

    private NavLinkResolver $resolver;

    public function __construct(?NavLinkResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new DeterministicNavLinkResolver();
    }

    public function id(): string
    {
        return 'resolve-nav-links';
    }

    public function label(): string
    {
        return 'Resolve navigation links';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['pages.json', 'theme/parts/*'],
            writes: ['theme/parts/*', self::REPORT, 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $pageArtifact = $project->readJson('pages.json');
        $rawPages = $pageArtifact['pages'] ?? null;
        if (!is_array($rawPages) || !array_is_list($rawPages)) {
            throw new \RuntimeException('resolve-nav-links: pages.json must contain a pages list');
        }

        [$pages, $targets] = self::pageContext($project, $rawPages);
        foreach (['header', 'footer'] as $area) {
            $file = "theme/parts/{$area}.html";
            if ($project->exists($file)) {
                $targets[$file] = null;
            }
        }
        ksort($targets);

        $repairs = [];
        $warningRows = [];
        foreach ($targets as $file => $currentPagePath) {
            $authored = $project->readText($file);
            $result = $this->resolver->resolve($authored, $pages, $file, $currentPagePath);
            if ($result['markup'] !== $authored) {
                $project->writeText($file, $result['markup']);
            }
            foreach ($result['repairs'] as $repair) {
                $repairs[] = $repair;
            }
            foreach ($result['warnings'] as $warning) {
                $warningRows[] = $warning;
            }
        }

        $project->writeJson(self::REPORT, [
            'repairs' => $repairs,
            'warnings' => $warningRows,
        ]);
        $project->addWarnings($this->id(), array_map(self::warningMessage(...), $warningRows));

        Narrator::write(sprintf(
            "  navigation links: %d repaired, %d warning(s)\n",
            count($repairs),
            count($warningRows),
        ));
    }

    /**
     * @param list<array<string,mixed>> $rawPages
     * @return array{
     *     list<array{label:string,path:string,anchors:list<string>}>,
     *     array<string,string|null>
     * }
     */
    private static function pageContext(Project $project, array $rawPages): array
    {
        $pages = [];
        $targets = [];
        foreach ($rawPages as $pageIndex => $page) {
            if (!is_array($page)) {
                throw new \RuntimeException("resolve-nav-links: pages.json page {$pageIndex} must be an object");
            }
            $label = trim((string) ($page['title'] ?? ''));
            $path = trim((string) ($page['path'] ?? ''));
            $sections = $page['sections'] ?? null;
            if ($label === '' || $path === '' || !is_array($sections) || !array_is_list($sections)) {
                throw new \RuntimeException(
                    "resolve-nav-links: pages.json page {$pageIndex} needs title, path, and sections",
                );
            }

            $anchorSet = [];
            foreach ($sections as $sectionIndex => $section) {
                if (!is_array($section)) {
                    throw new \RuntimeException(
                        "resolve-nav-links: pages.json page {$pageIndex} section {$sectionIndex} must be an object",
                    );
                }
                $sectionSlug = trim((string) ($section['slug'] ?? ''));
                if ($sectionSlug === '') {
                    throw new \RuntimeException(
                        "resolve-nav-links: pages.json page {$pageIndex} section {$sectionIndex} needs a slug",
                    );
                }
                $file = 'theme/parts/' . SectionsStep::partSlug((string) ($page['slug'] ?? ''), $sectionSlug) . '.html';
                $markup = $project->readText($file);
                foreach (self::anchors($markup) as $anchor) {
                    $anchorSet[$anchor] = true;
                }
                $targets[$file] = $path;
            }

            $pages[] = [
                'label' => $label,
                'path' => $path,
                'anchors' => array_keys($anchorSet),
            ];
        }
        return [$pages, $targets];
    }

    /** @return list<string> */
    private static function anchors(string $markup): array
    {
        $document = Html::loadUtf8Html($markup, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if (!$document instanceof \DOMDocument) {
            return [];
        }

        $anchors = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof \DOMElement || !$element->hasAttribute('id')) {
                continue;
            }
            $anchor = trim($element->getAttribute('id'));
            if ($anchor !== '') {
                $anchors[$anchor] = true;
            }
        }
        return array_keys($anchors);
    }

    /**
     * @param array{file:string,block:string,authored:string,delivered:string,disposition:string} $row
     */
    private static function warningMessage(array $row): string
    {
        return 'file ' . self::contextValue($row['file'])
            . ' block_path ' . self::contextValue($row['block'])
            . ' authored_value ' . self::contextValue($row['authored'])
            . ' delivered_value ' . self::contextValue($row['delivered'])
            . ' disposition ' . self::contextValue($row['disposition']);
    }

    private static function contextValue(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
