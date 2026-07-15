<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionRhythm;
use Automattic\SiteBuild\Step;

/**
 * Deterministically apply the page plan's outer vertical rhythm to every
 * generated section part before WordPress re-serializes the block markup.
 *
 * Sections are authored concurrently and therefore cannot safely negotiate a
 * shared seam. This step is the one owner of root top/bottom padding: it maps
 * the plan's semantic density to the canonical theme spacing scale and removes
 * the duplicate edge between consecutive sections on the same background.
 * Every page in pages.json is rewritten independently — adjacency only exists
 * within one rendered page, and the footer (which renders on every page)
 * supplies each page's following surface.
 */
final class SectionRhythmStep implements Step
{
    public function id(): string
    {
        return 'section-rhythm';
    }

    public function label(): string
    {
        return 'Normalize section rhythm';
    }

    public function run(Project $project): void
    {
        // Rewrite the complete ordered set of EVERY page before writing any
        // file, so invalid plan data or one malformed root cannot leave a
        // half-normalized site.
        $footerSurface = self::footerSurface($project);
        $writes = [];
        $adjustments = 0;
        foreach (self::pages($project) as $page) {
            [$entries, $rels] = self::planEntries($project, $page);
            $result = SectionRhythm::rewrite($entries, $footerSurface);
            foreach ($rels as $i => $rel) {
                $writes['theme/' . $rel] = $result['markups'][$i];
            }
            $adjustments += count($result['notes']);
        }
        foreach ($writes as $path => $markup) {
            $project->writeText($path, $markup);
        }

        echo "  section rhythm: {$adjustments} root spacing adjustment(s)\n";
    }

    /**
     * The ordered page list from pages.json, validated to be non-empty.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pages(Project $project): array
    {
        $pages = $project->readJson('pages.json')['pages'] ?? null;
        if (!is_array($pages) || !array_is_list($pages) || $pages === []) {
            throw new \RuntimeException('section-rhythm: pages.json has no ordered pages');
        }
        foreach ($pages as $page) {
            if (!is_array($page)) {
                throw new \RuntimeException('section-rhythm: pages.json contains a non-object page');
            }
        }
        return $pages;
    }

    /**
     * Build one page's ordered SectionRhythm entries and their theme-relative
     * part paths from its pages.json entry, while the transient parts still
     * exist (before assemble-pages inlines and drops them).
     *
     * @param array<string,mixed> $page
     * @return array{list<array{slug:string,markup:string,density:string,background:string}>,list<string>}
     */
    public static function planEntries(Project $project, array $page): array
    {
        $pageSlug = trim((string) ($page['slug'] ?? ''));
        $entries = [];
        $rels = [];
        foreach (self::planSections($page) as $section) {
            $slug = trim((string) ($section['slug'] ?? ''));
            $rel = 'parts/' . SectionsStep::partSlug($pageSlug, $slug) . '.html';
            if ($slug === '' || !$project->exists('theme/' . $rel)) {
                throw new \RuntimeException("section-rhythm: missing generated section part {$rel}");
            }
            $rels[] = $rel;
            $entries[] = self::entry($section, $slug, $project->readText('theme/' . $rel));
        }
        return [$entries, $rels];
    }

    /**
     * The same plan demands over the ASSEMBLED page: entries built by
     * splitting plugin/pages/<slug>.html (where assemble-pages inlined the
     * parts in plan order) back into top-level section markups. Shared with
     * ThemeValidator's final drift gate so the build pass and the gate can
     * never disagree about what the plan demands of each section root.
     *
     * @param array<string,mixed> $page
     * @return list<array{slug:string,markup:string,density:string,background:string}>
     */
    public static function assembledEntries(Project $project, array $page): array
    {
        $pageSlug = trim((string) ($page['slug'] ?? ''));
        $sections = self::planSections($page);

        $rel = "plugin/pages/{$pageSlug}.html";
        if (!$project->exists($rel)) {
            throw new \RuntimeException("section-rhythm: missing assembled page {$rel}");
        }
        $markups = self::splitTopLevel($project->readText($rel));
        if (count($markups) !== count($sections)) {
            throw new \RuntimeException(
                "section-rhythm: page '{$pageSlug}' has " . count($markups)
                . ' top-level blocks for ' . count($sections) . ' planned sections'
            );
        }

        $entries = [];
        foreach ($sections as $i => $section) {
            $entries[] = self::entry($section, trim((string) ($section['slug'] ?? '')), $markups[$i]);
        }
        return $entries;
    }

    /** The footer's seam-owning surface, when a footer part exists and supplies one. */
    public static function footerSurface(Project $project): ?string
    {
        return $project->exists('theme/parts/footer.html')
            ? SectionRhythm::followingSurfaceFromMarkup($project->readText('theme/parts/footer.html'))
            : null;
    }

    /**
     * Split one assembled page document into its top-level block markups —
     * the inverse of AssemblePagesStep::pageContent. Pure — unit-testable.
     *
     * @return list<string>
     */
    public static function splitTopLevel(string $content): array
    {
        $doc = BlockMarkup::parse($content);
        $starts = [];
        foreach ($doc->indices() as $i) {
            if ($doc->parent($i) === null) {
                $starts[] = $doc->openingOffset($i);
            }
        }

        $chunks = [];
        foreach ($starts as $n => $start) {
            $end = $starts[$n + 1] ?? strlen($content);
            $chunks[] = rtrim(substr($content, $start, $end - $start));
        }
        return $chunks;
    }

    /**
     * One page's validated, ordered section list.
     *
     * @param array<string,mixed> $page
     * @return array<int,array<string,mixed>>
     */
    private static function planSections(array $page): array
    {
        $pageSlug = trim((string) ($page['slug'] ?? ''));
        $sections = $page['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections) || $sections === []) {
            throw new \RuntimeException("section-rhythm: page '{$pageSlug}' has no ordered sections");
        }
        foreach ($sections as $section) {
            if (!is_array($section)) {
                throw new \RuntimeException("section-rhythm: page '{$pageSlug}' contains a non-object section");
            }
        }
        return $sections;
    }

    /** @param array<string,mixed> $section */
    private static function entry(array $section, string $slug, string $markup): array
    {
        return [
            'slug'       => $slug,
            'markup'     => $markup,
            'density'    => (string) ($section['vertical_density'] ?? ''),
            'background' => (string) ($section['background'] ?? ''),
        ];
    }
}
