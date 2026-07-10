<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;

/**
 * Step (deterministic): compose the site's pages from the (already fixed)
 * section parts. No LLM call — pure file composition, run AFTER fix-blocks so
 * the markup that lands in the content plugin is the final, re-serialized
 * form.
 *
 * Input:  pages.json (the plan) + theme/parts/{header,footer,page-*}.html +
 *         theme/theme.json.
 * Output:
 *   - plugin/pages/<slug>.html — each page's section markup inlined in plan
 *     order (page content in WordPress can't reference template parts, so the
 *     markup is embedded, not linked)
 *   - plugin/pages.json — the seeder manifest (slug/title/front/menu_order/
 *     parent), parents before children, in display order
 *   - theme/templates/page.html — header + bare post-content + footer (bare:
 *     sections carry their own layout; a constrained wrapper would break
 *     full-bleed bands). The seeded front page renders through this too.
 *   - theme/templates/index.html — the blog fallback
 *   - theme.json templateParts — header/footer registered for the editor
 *   - the transient theme/parts/page-*.html files are DELETED: their markup
 *     now lives in the plugin, and stale copies would ship as dead template
 *     parts and drift from user edits to the seeded content.
 */
final class AssemblePagesStep implements Step
{
    public function id(): string
    {
        return 'assemble-pages';
    }

    public function label(): string
    {
        return 'Assemble pages into the content plugin';
    }

    public function run(Project $project): void
    {
        $plan = $project->readJson('pages.json');
        $pages = $plan['pages'] ?? [];
        if (!is_array($pages) || $pages === []) {
            throw new \RuntimeException('assemble-pages: pages.json has no pages');
        }

        // Chrome must exist before the templates reference it.
        foreach (['parts/header.html', 'parts/footer.html'] as $rel) {
            if (!$project->exists('theme/' . $rel)) {
                throw new \RuntimeException("assemble-pages: missing {$rel}");
            }
        }

        // Gather every page's section markup BEFORE writing or deleting
        // anything, so a missing part aborts with the theme untouched.
        $contents = [];
        $manifest = [];
        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            $markups = [];
            foreach ((array) ($page['sections'] ?? []) as $section) {
                $part = SectionsStep::partSlug($slug, (string) ($section['slug'] ?? ''));
                $rel = "theme/parts/{$part}.html";
                if (!$project->exists($rel)) {
                    throw new \RuntimeException("assemble-pages: missing part parts/{$part}.html");
                }
                $markups[] = rtrim($project->readText($rel));
            }
            $contents[$slug] = self::pageContent($markups);
            $manifest[] = [
                'slug'       => $slug,
                'title'      => (string) ($page['title'] ?? ''),
                'front'      => (bool) ($page['front'] ?? false),
                'menu_order' => (int) ($page['menu_order'] ?? 0),
                'parent'     => isset($page['parent']) && $page['parent'] !== null ? (string) $page['parent'] : null,
            ];
        }

        foreach ($contents as $slug => $content) {
            $project->writeText("plugin/pages/{$slug}.html", $content);
        }
        $project->writeJson('plugin/pages.json', ['pages' => $manifest]);

        $project->writeText('theme/templates/page.html', self::pageTemplate());
        $project->writeText('theme/templates/index.html', self::index());
        $this->registerTemplateParts($project);

        // The inlined markup is the single copy now — drop the transient parts.
        foreach ($pages as $page) {
            foreach ((array) ($page['sections'] ?? []) as $section) {
                $part = SectionsStep::partSlug((string) ($page['slug'] ?? ''), (string) ($section['slug'] ?? ''));
                @unlink($project->themePath("parts/{$part}.html"));
            }
        }
    }

    /**
     * One page's content: its section markup in plan order. Pure — testable.
     *
     * @param string[] $sectionMarkups
     */
    public static function pageContent(array $sectionMarkups): string
    {
        return implode("\n", $sectionMarkups) . "\n";
    }

    /**
     * The universal page template: chrome around bare post content. Every
     * seeded page — the front page included — renders through this. Pure.
     */
    public static function pageTemplate(): string
    {
        return self::part('header', 'header') . "\n"
            . '<!-- wp:post-content /-->' . "\n"
            . self::part('footer', 'footer') . "\n";
    }

    /** The blog fallback template: header, post content, footer. Pure. */
    public static function index(): string
    {
        return self::part('header', 'header') . "\n"
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' . "\n"
            . '<main class="wp-block-group"><!-- wp:post-content {"layout":{"type":"constrained"}} /--></main>' . "\n"
            . '<!-- /wp:group -->' . "\n"
            . self::part('footer', 'footer') . "\n";
    }

    /** One template-part reference. */
    private static function part(string $slug, string $tagName): string
    {
        return '<!-- wp:template-part {"slug":"' . $slug . '","tagName":"' . $tagName . '"} /-->';
    }

    /**
     * Register the chrome parts under theme.json's templateParts so the Site
     * Editor shows them with titles and areas. Only header/footer remain in
     * the theme — page sections live in the content plugin.
     */
    private function registerTemplateParts(Project $project): void
    {
        $theme = $project->readJson('theme/theme.json');
        $theme['templateParts'] = [
            ['name' => 'header', 'title' => 'Header', 'area' => 'header'],
            ['name' => 'footer', 'title' => 'Footer', 'area' => 'footer'],
        ];
        $project->writeJson('theme/theme.json', $theme);
    }
}
