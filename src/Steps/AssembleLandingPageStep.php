<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (deterministic): compose the landing page from the parts the sections
 * step generated. No LLM call — just stitch the template parts in plan order.
 *
 * Input:  sections.json (order) + theme/parts/{header,footer,section-*}.html
 *         (written by the sections step) + theme/theme.json.
 * Output: theme/templates/front-page.html (header + each section part in order +
 *         footer), theme/templates/index.html (fallback), and theme.json updated
 *         with templateParts registrations for the editor.
 *
 * Because this is pure composition it is fast, reproducible, and easy to reason
 * about — the model never sees the whole page at once, only individual sections.
 */
final class AssembleLandingPageStep implements Step
{
    public function id(): string
    {
        return 'assemble-landing-page';
    }

    public function label(): string
    {
        return 'Assemble landing page';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['sections.json', 'theme/parts/*', 'theme/theme.json'],
            writes: ['theme/templates/*', 'theme/theme.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $plan = $project->readJson('sections.json');
        $sections = $plan['sections'] ?? [];
        if (!is_array($sections) || $sections === []) {
            throw new \RuntimeException('assemble-landing-page: sections.json has no sections');
        }

        // Header and footer must exist before we reference them.
        foreach (['parts/header.html', 'parts/footer.html'] as $rel) {
            if (!$project->exists('theme/' . $rel)) {
                throw new \RuntimeException("assemble-landing-page: missing {$rel}");
            }
        }

        $slugs = [];
        foreach ($sections as $section) {
            $slug = SectionsStep::SECTION_PREFIX . (string) ($section['slug'] ?? '');
            $rel = "parts/{$slug}.html";
            if (!$project->exists('theme/' . $rel)) {
                throw new \RuntimeException("assemble-landing-page: missing section part {$rel}");
            }
            $slugs[] = $slug;
        }

        $project->writeText('theme/templates/front-page.html', self::frontPage($slugs));
        $project->writeText('theme/templates/index.html', self::index());

        $this->registerTemplateParts($project, $slugs);
    }

    /**
     * The landing page: header part, every section part in order, footer part.
     * Pure — unit-testable.
     *
     * @param string[] $sectionSlugs template-part slugs (already prefixed)
     */
    public static function frontPage(array $sectionSlugs): string
    {
        $lines = [self::part('header', 'header')];
        foreach ($sectionSlugs as $slug) {
            $lines[] = self::part($slug, 'section');
        }
        $lines[] = self::part('footer', 'footer');
        return implode("\n", $lines) . "\n";
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
     * Register header, footer, and every section part under theme.json's
     * templateParts so the Site Editor shows them with titles and areas. Render
     * by slug works without this, but the editor experience is nicer with it.
     *
     * @param string[] $sectionSlugs
     */
    private function registerTemplateParts(Project $project, array $sectionSlugs): void
    {
        $theme = $project->readJson('theme/theme.json');

        $parts = [
            ['name' => 'header', 'title' => 'Header', 'area' => 'header'],
            ['name' => 'footer', 'title' => 'Footer', 'area' => 'footer'],
        ];
        foreach ($sectionSlugs as $slug) {
            $parts[] = [
                'name'  => $slug,
                'title' => ucwords(str_replace('-', ' ', $slug)),
                'area'  => 'uncategorized',
            ];
        }

        $theme['templateParts'] = $parts;
        $project->writeJson('theme/theme.json', $theme);
    }
}
