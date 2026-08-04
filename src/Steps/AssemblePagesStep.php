<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (deterministic): compose the site's pages from the (already fixed)
 * section parts. No LLM call — pure file composition, run AFTER fix-blocks so
 * the markup that lands in the content plugin is the final, re-serialized
 * form.
 *
 * Input:  pages.json (the plan) + headerBehavior.json (the resolved header
 *         treatment) + theme/parts/{header,footer,page-*}.html +
 *         theme/theme.json.
 * Output:
 *   - plugin/pages/<slug>.html — each page's section markup inlined in plan
 *     order (page content in WordPress can't reference template parts, so the
 *     markup is embedded, not linked)
 *   - plugin/pages.json — the seeder manifest (slug/title/front/menu_order/
 *     parent), parents before children, in display order
 *   - plugin/images.json — every asset the page content references, with a
 *     media title; generate-images ships the files, the seeder imports them
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

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'pages.json',
                'headerBehavior.json',
                'images.json',
                'theme/parts/header.html',
                'theme/parts/footer.html',
                'theme/parts/*',
                'theme/theme.json',
            ],
            writes: [
                'plugin/pages/*',
                'plugin/pages.json',
                'plugin/images.json',
                'theme/templates/page.html',
                'theme/templates/index.html',
                'theme/theme.json',
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $plan = $project->readJson('pages.json');
        $pages = $plan['pages'] ?? [];
        if (!is_array($pages) || $pages === []) {
            throw new \RuntimeException('assemble-pages: pages.json has no pages');
        }

        $warnings = [];
        $headerBehavior = $this->readHeaderBehavior($project, $warnings);

        // The templates reference the chrome parts; a missing one renders as
        // an empty template-part in WordPress — degraded but usable, so warn
        // and continue rather than discard the whole build.
        foreach (['parts/header.html', 'parts/footer.html'] as $rel) {
            if (!$project->exists('theme/' . $rel)) {
                $warnings[] = "missing {$rel}; the templates reference an absent part (renders empty)";
            }
        }

        // Gather every page's section markup BEFORE writing or deleting
        // anything. A missing part loses one section, not the page; a page
        // with NO surviving section markup is skipped whole (an empty page in
        // the nav is worse than an absent one) — except the front page, which
        // the templates and the seeder rely on.
        $contents = [];
        $manifest = [];
        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            $markups = [];
            foreach ((array) ($page['sections'] ?? []) as $section) {
                $part = SectionsStep::partSlug($slug, (string) ($section['slug'] ?? ''));
                $rel = "theme/parts/{$part}.html";
                if (!$project->exists($rel)) {
                    $warnings[] = "page '{$slug}': missing generated part parts/{$part}.html; section skipped";
                    continue;
                }
                $markups[] = rtrim($project->readText($rel));
            }
            if ($markups === [] && empty($page['front'])) {
                $warnings[] = "page '{$slug}': no section markup survived; page skipped";
                continue;
            }
            if ($markups === []) {
                $warnings[] = "front page '{$slug}': no section markup survived; empty front page delivered";
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
        // A skipped page may still be named as another page's parent; the
        // seeder can't attach a child to a page that was never created.
        $kept = array_fill_keys(array_column($manifest, 'slug'), true);
        foreach ($manifest as &$entry) {
            if ($entry['parent'] !== null && !isset($kept[$entry['parent']])) {
                $warnings[] = "page '{$entry['slug']}': parent '{$entry['parent']}' was skipped; promoted to top level";
                $entry['parent'] = null;
            }
        }
        unset($entry);
        $project->addWarnings($this->id(), $warnings);

        foreach ($contents as $slug => $content) {
            $project->writeText("plugin/pages/{$slug}.html", $content);
        }
        $project->writeJson('plugin/pages.json', ['pages' => $manifest]);

        // Content images are content: list every asset the page markup
        // references so generate-images ships the files with the plugin and
        // the seeder imports them into the media library. Subjects from
        // images.json become the attachments' media titles.
        $specs = $project->exists('images.json') ? $project->readJson('images.json') : [];
        $project->writeJson('plugin/images.json', ['images' => self::contentImages($contents, $specs)]);

        $project->writeText('theme/templates/page.html', self::pageTemplate($headerBehavior));
        $project->writeText('theme/templates/index.html', self::index($headerBehavior));
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
     * The plugin's image manifest: one entry per unique asset referenced by
     * any page's content ("theme:./assets/<file>"), in first-appearance order.
     * The title comes from the collected spec's subject (it becomes the media
     * library title at import), falling back to a name derived from the
     * filename. Pure — unit-testable.
     *
     * @param array<string,string>          $pageContents page slug => markup
     * @param array<int,array<string,mixed>> $specs       images.json entries
     * @return array<int,array{filename:string,title:string}>
     */
    public static function contentImages(array $pageContents, array $specs): array
    {
        $subjects = [];
        foreach ($specs as $spec) {
            if (is_array($spec) && isset($spec['filename'])) {
                $subjects[(string) $spec['filename']] = trim((string) ($spec['subject'] ?? ''));
            }
        }

        $images = [];
        foreach ($pageContents as $content) {
            if (!preg_match_all('/theme:\.\/assets\/([a-z0-9-]+\.(?:jpe?g|png))/i', $content, $m)) {
                continue;
            }
            foreach ($m[1] as $filename) {
                if (isset($images[$filename])) {
                    continue;
                }
                $title = $subjects[$filename] ?? '';
                if ($title === '') {
                    $title = ucwords(str_replace('-', ' ', (string) preg_replace('/\.[a-z]+$/i', '', $filename)));
                }
                $images[$filename] = ['filename' => $filename, 'title' => $title];
            }
        }
        return array_values($images);
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
    public static function pageTemplate(string $headerBehavior = 'static'): string
    {
        return self::part('header', 'header', self::pageHeaderClassName($headerBehavior)) . "\n"
            . '<!-- wp:post-content /-->' . "\n"
            . self::part('footer', 'footer') . "\n";
    }

    /** The blog fallback template: header, post content, footer. Pure. */
    public static function index(string $headerBehavior = 'static'): string
    {
        return self::part('header', 'header', self::indexHeaderClassName($headerBehavior)) . "\n"
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' . "\n"
            . '<main class="wp-block-group"><!-- wp:post-content {"layout":{"type":"constrained"}} /--></main>' . "\n"
            . '<!-- /wp:group -->' . "\n"
            . self::part('footer', 'footer') . "\n";
    }

    /**
     * Classes on the header template-part in generated page templates. Static
     * preserves the pre-behavior markup exactly; non-static treatments put
     * positioning on the outer template-part wrapper rather than constraining
     * it to the height of the generated inner header group. Pure — testable.
     */
    public static function pageHeaderClassName(string $headerBehavior): ?string
    {
        return match ($headerBehavior) {
            'sticky-soft' => 'site-header-shell site-header-shell--sticky-soft',
            'overlay-to-solid' => 'site-header-shell site-header-shell--overlay-to-solid',
            default => null,
        };
    }

    /**
     * Classes on the blog fallback's header reference. An overlay has no
     * reviewed image-led opening on index.html, so it deterministically uses
     * the opaque sticky treatment from the first frame. Pure — testable.
     */
    public static function indexHeaderClassName(string $headerBehavior): ?string
    {
        return match ($headerBehavior) {
            'sticky-soft' => 'site-header-shell site-header-shell--sticky-soft',
            'overlay-to-solid' => 'site-header-shell site-header-shell--sticky-soft site-header-shell--force-solid',
            default => null,
        };
    }

    /** One template-part reference. */
    private static function part(string $slug, string $tagName, ?string $className = null): string
    {
        $attributes = ['slug' => $slug, 'tagName' => $tagName];
        if ($className !== null) {
            $attributes['className'] = $className;
        }
        return '<!-- wp:template-part '
            . json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ' /-->';
    }

    /**
     * Read the deterministic header-behavior artifact without allowing an
     * imperfect generated choice to abort assembly. File I/O errors remain
     * fatal, but a missing, malformed, or unsupported authored value degrades
     * to the unchanged static template and leaves an actionable warning.
     *
     * @param list<string> $warnings
     */
    private function readHeaderBehavior(Project $project, array &$warnings): string
    {
        if (!$project->exists('headerBehavior.json')) {
            $warnings[] = 'file=headerBehavior.json; authored=<missing>; delivered=static; '
                . 'disposition=missing generated header behavior, degraded to static header';
            return 'static';
        }

        $raw = $project->readText('headerBehavior.json');
        try {
            $artifact = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            $warnings[] = 'file=headerBehavior.json; authored=<invalid JSON: ' . $error->getMessage() . '>; '
                . 'delivered=static; disposition=malformed generated header behavior, degraded to static header';
            return 'static';
        }

        try {
            if (!is_array($artifact)) {
                throw new \InvalidArgumentException('header behavior artifact must be a JSON object');
            }
            $artifact = HeaderBehavior::validateArtifact($artifact);
            return $artifact['behavior'];
        } catch (\InvalidArgumentException $error) {
            $authoredSummary = json_encode(
                $artifact,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $warnings[] = 'file=headerBehavior.json; authored=' . ($authoredSummary === false ? '<unrepresentable>' : $authoredSummary)
                . '; delivered=static; disposition=invalid generated header behavior (' . $error->getMessage()
                . '), degraded to static header';
            return 'static';
        }
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
