<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;

/**
 * Step (LLM, concurrent): generate every part of every page in ONE batch — the
 * header, the footer, and one part per planned section of every page in
 * pages.json — fired together instead of one giant per-page call.
 *
 * Input:  siteSpec.json + theme/theme.json + pages.json (the plan).
 * Output: theme/parts/header.html, theme/parts/footer.html, and
 *         theme/parts/page-<pageSlug>--<sectionSlug>.html per planned section.
 *         The page-* parts are transient build artifacts: assemble-pages later
 *         inlines them into the content plugin's page files and removes them
 *         from the theme (header/footer stay — they are the site chrome).
 *
 * Each section is generated independently with ITS page's section list as
 * context (for coherence) plus its own brief, so the model focuses on one
 * section at a time and they all run concurrently. Every part also receives
 * the site's page list so buttons and links can point at real sibling pages.
 * Image placeholders use the same AI_IMAGE convention collect-images parses.
 *
 * Each part's response IS the block markup (raw text, via completeBatch) — not
 * JSON-wrapped — so the model never has to escape its HTML into a JSON string.
 */
final class SectionsStep implements Step
{
    use LlmOptions;

    /** Prefix for a page section part's request key and filename. */
    public const PART_PREFIX = 'page-';

    /** The part slug (request key and file basename) for one page's section. */
    public static function partSlug(string $pageSlug, string $sectionSlug): string
    {
        return self::PART_PREFIX . $pageSlug . '--' . $sectionSlug;
    }

    /** {{nav_rule}} for header.md when the site has inner pages to list. */
    private const NAV_RULE_MULTI = '- Navigation default: the `wp:navigation` should contain `<!-- wp:page-list /-->`'
        . ' so it auto-reflects the site\'s pages — do NOT hand-author `wp:navigation-link` entries unless a curated'
        . ' menu is clearly wanted.';

    /** {{nav_rule}} when the site is the homepage alone — a page-list would render one self-referential "Home" link. */
    private const NAV_RULE_SINGLE = '- Navigation: this site is ONE page, so a page-list would render a single'
        . ' self-referential "Home" link — do NOT use `<!-- wp:page-list /-->`, and do NOT link to the page itself.'
        . ' Either omit navigation entirely (the wordmark carries the header) or hand-author a small `wp:navigation`'
        . ' of `wp:navigation-link` items targeting section anchors from the HOMEPAGE OUTLINE (each outline line ends'
        . ' with its [#anchor]; a link\'s "url" is that anchor, e.g. href="#menu-highlights").';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'sections';
    }

    public function label(): string
    {
        return "Build every page's sections";
    }

    public function requests(Project $project): array
    {
        $siteSpec = $project->readText('siteSpec.json');
        $themeJson = $project->readText('theme/theme.json');
        $language = SiteSpecStep::languageOf($project);
        $pages = self::pages($project);
        $sitePages = PagePlanStep::sitePagesList($pages);

        // The chrome is briefed on the FRONT page: that's what the header sits
        // directly above (or floats on) and what sets the site's opening tone.
        $front = self::frontPage($pages);
        $frontSections = $front['sections'];

        // The AI_IMAGE authoring rules live in their own prompt file so they stay
        // in sync with what CollectImagesStep parses; injected into every section
        // (a section uses them only when its "Use imagery" is yes).
        $imageInstructions = $this->renderer->render('image-generation.md', []);

        // The committed creative concept, shared by every section so the whole
        // site honors one direction (shape language, signature device, mood).
        $designDirection = DesignDirectionStep::readFor($project);

        $requests = [
            'header' => $this->withOptions(['prompt' => $this->renderer->render('header.md', [
                'site_spec'        => $siteSpec,
                'language'         => $language,
                'theme_json'       => $themeJson,
                'design_direction' => $designDirection,
                'hero_brief'       => self::heroBrief($frontSections),
                'outline'          => self::outline($frontSections),
                'site_pages'       => $sitePages,
                'nav_rule'         => count($pages) > 1 ? self::NAV_RULE_MULTI : self::NAV_RULE_SINGLE,
            ])]),
            'footer' => $this->withOptions(['prompt' => $this->renderer->render('footer.md', [
                'site_spec'        => $siteSpec,
                'language'         => $language,
                'theme_json'       => $themeJson,
                'design_direction' => $designDirection,
                'outline'          => self::outline($frontSections),
                'site_pages'       => $sitePages,
            ])]),
        ];

        foreach ($pages as $page) {
            $sections = $page['sections'];
            // A compact outline of THIS page, so each section knows its place.
            $outline = self::outline($sections);
            foreach ($sections as $i => $section) {
                $key = self::partSlug((string) $page['slug'], (string) $section['slug']);
                $requests[$key] = $this->withOptions(['prompt' => $this->renderer->render('section.md', [
                    'site_spec'        => $siteSpec,
                    'language'         => $language,
                    'theme_json'       => $themeJson,
                    'design_direction' => $designDirection,
                    'outline'          => $outline,
                    'page_title'       => (string) ($page['title'] ?? ''),
                    'page_path'        => (string) ($page['path'] ?? '/'),
                    'site_pages'       => $sitePages,
                    'section_title' => (string) ($section['title'] ?? ''),
                    'section_slug'  => (string) ($section['slug'] ?? ''),
                    'section_type'  => (string) ($section['type'] ?? 'content'),
                    'section_purpose' => (string) ($section['purpose'] ?? ''),
                    'content_notes' => (string) ($section['content_notes'] ?? ''),
                    'composition'   => $this->composition($sections, $i, (string) $page['slug']),
                    'image_instructions' => $imageInstructions,
                ])]);
            }
        }

        return $requests;
    }

    public function run(Project $project): void
    {
        $parts = $this->llm->completeBatch($this->requests($project));

        // Validate EVERY part before writing any, so one bad part doesn't leave
        // a half-written set of files on disk (the build aborts either way).
        $files = [];
        foreach ($parts as $key => $text) {
            $rel = match (true) {
                $key === 'header' => 'parts/header.html',
                $key === 'footer' => 'parts/footer.html',
                default           => 'parts/' . $key . '.html', // page-<page>--<section>
            };
            // Every part's top-level group must declare a layout — sections
            // included: a flow-layout section band renders its children
            // edge-to-edge at the viewport with no page gutter.
            $files[$rel] = self::constrainedPart(self::markup($text, $key));
        }

        foreach ($files as $rel => $markup) {
            $project->writeText('theme/' . $rel, $markup . "\n");
        }
    }

    /**
     * Pull and validate the planned page list from pages.json — every page
     * must carry a non-empty section list.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function pages(Project $project): array
    {
        $plan = $project->readJson('pages.json');
        $pages = $plan['pages'] ?? null;
        if (!is_array($pages) || $pages === []) {
            throw new \RuntimeException('sections: pages.json has no pages (run page-plan first)');
        }
        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            if (!is_array($page['sections'] ?? null) || $page['sections'] === []) {
                throw new \RuntimeException("sections: page '{$slug}' has no sections (run page-plan first)");
            }
        }
        return array_values($pages);
    }

    /** The front page entry (flagged, falling back to the first page). */
    private static function frontPage(array $pages): array
    {
        foreach ($pages as $page) {
            if (!empty($page['front'])) {
                return $page;
            }
        }
        return $pages[0];
    }

    /**
     * A one-line-per-section outline string used to give every part the same
     * view of the page, including each section's planned archetype and
     * background so the page rhythm is visible everywhere. Each line ends
     * with the section's [#anchor] (its slug — the section prompt makes the
     * top-level group carry it as an anchor), so navs and links can target
     * sections in-page. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function outline(array $sections): string
    {
        $lines = [];
        foreach ($sections as $n => $s) {
            $title = (string) ($s['title'] ?? '');
            $type = (string) ($s['type'] ?? '');
            $line = ($n + 1) . ". {$title} ({$type})";
            if (($plan = self::assignment($s)) !== '') {
                $line .= " — {$plan}";
            }
            if (($slug = trim((string) ($s['slug'] ?? ''))) !== '') {
                $line .= " [#{$slug}]";
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * The section's COMPOSITION prompt block: the assigned archetype/background/
     * handoff plus its neighbors' assignments (section-composition.md). Missing
     * composition fields mean the plan step contract was broken, so fail before
     * sending a half-empty prompt to the model.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    private function composition(array $sections, int $i, string $pageSlug): string
    {
        $section = $sections[$i];
        $slug = (string) ($section['slug'] ?? "section-{$i}");
        foreach (['layout_archetype', 'background', 'handoff'] as $field) {
            if (trim((string) ($section[$field] ?? '')) === '') {
                throw new \RuntimeException("sections: page '{$pageSlug}' section '{$slug}' is missing {$field} from page-plan");
            }
        }

        return $this->renderer->render('section-composition.md', [
            'layout_archetype' => (string) ($section['layout_archetype'] ?? ''),
            'background'       => (string) ($section['background'] ?? ''),
            'handoff'          => (string) ($section['handoff'] ?? ''),
            'neighbors'        => self::neighbors($sections, $i),
        ]);
    }

    /**
     * The plan's art-direction context for the section at $i: its neighbors'
     * archetype/background assignments, so each seam is designed on both sides.
     * Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function neighbors(array $sections, int $i): string
    {
        $describe = function (?array $s): ?string {
            if (!is_array($s)) {
                return null;
            }
            $title = (string) ($s['title'] ?? '');
            $plan = self::assignment($s);
            return "\"{$title}\"" . ($plan !== '' ? " — {$plan}" : '');
        };

        $above = $describe($sections[$i - 1] ?? null) ?? 'the site header (this is the first section)';
        $below = $describe($sections[$i + 1] ?? null) ?? 'the site footer (this is the last section)';
        return "Above: {$above}\nBelow: {$below}";
    }

    /**
     * "archetype on background" summary of a planned section, or '' when the
     * plan predates the art-direction fields.
     *
     * @param array<string,mixed> $section
     */
    private static function assignment(array $section): string
    {
        $archetype = trim((string) ($section['layout_archetype'] ?? ''));
        $background = trim((string) ($section['background'] ?? ''));
        if ($archetype === '' && $background === '') {
            return '';
        }
        return trim($archetype . ($background !== '' ? " on {$background} background" : ''));
    }

    /**
     * A plain-text brief of the FRONT page's planned hero section, so
     * the header prompt can pick the archetype that fits what it will sit
     * directly above — or float on top of. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function heroBrief(array $sections): string
    {
        $hero = null;
        foreach ($sections as $s) {
            if ((string) ($s['type'] ?? '') === 'hero') {
                $hero = $s;
                break;
            }
        }
        $hero ??= $sections[0] ?? null;
        if (!is_array($hero)) {
            return '(No hero section planned.)';
        }

        $lines = [];
        foreach (['title' => 'Title', 'type' => 'Type', 'purpose' => 'Purpose', 'content_notes' => 'Notes'] as $key => $label) {
            $value = trim((string) ($hero[$key] ?? ''));
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }
        return $lines === [] ? '(No hero section planned.)' : implode("\n", $lines);
    }

    /**
     * Ensure a part's top-level wp:group declares a layout. The header and
     * footer prompts demand `"layout":{"type":"constrained"}` on the top-level
     * group, but the model sometimes drops it — and a group with no layout is
     * flow, not constrained: no centering, no root-padding-aware gutter, so
     * its content renders edge-to-edge at the viewport (a header's align:wide
     * row hugs the screen corners; a footer's text touches the screen edge).
     * Only adds a missing layout; an explicit one (e.g. a deliberate flex row)
     * is left alone. Pure — unit-testable.
     */
    public static function constrainedPart(string $markup): string
    {
        if (preg_match('/^<!--\s*wp:group\s*(\{.*?\})?\s*-->/s', $markup, $m) !== 1) {
            return $markup;
        }
        $attrs = isset($m[1]) && $m[1] !== '' ? json_decode($m[1], true) : [];
        if (!is_array($attrs) || isset($attrs['layout'])) {
            return $markup;
        }
        $attrs['layout'] = ['type' => 'constrained'];
        $json = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return '<!-- wp:group ' . $json . ' -->' . substr($markup, strlen($m[0]));
    }

    /**
     * Validate one part's raw block-markup response. The model returns the
     * markup verbatim; we defensively strip a stray ```…``` code fence if one
     * slipped in, then require it to actually be block markup. The part is
     * untrusted model output headed for templates and stored post content, so
     * script-capable markup is stripped here — the one intake every generated
     * part passes through. Pure — testable.
     */
    public static function markup(string $text, string $key): string
    {
        $markup = self::stripFences(trim($text));
        if ($markup === '' || !str_contains($markup, 'wp:')) {
            throw new \RuntimeException("sections: part '{$key}' is not block markup");
        }
        return MarkupSanitizer::sanitize(self::normalizePresetRefs(rtrim($markup)));
    }

    /**
     * Repair the model's recurring preset-reference typo: `var:preset--type--slug`
     * instead of `var:preset|type|slug` in block-comment attributes. WordPress
     * resolves only the pipe form, so the malformed ref produces NO style — and
     * the block-fixer then deletes the (correct) inline CSS as "not mirrored in
     * attributes", leaving e.g. a section with zero padding beside 8rem siblings.
     * The type names are a fixed vocabulary, so the rewrite is unambiguous.
     * Pure — unit-testable.
     */
    public static function normalizePresetRefs(string $markup): string
    {
        // `--` may also appear as the serializer's `--` escape.
        $dashes = '(?:--|(?:\\\u002d){2})';
        return (string) preg_replace(
            "/var:preset{$dashes}(color|gradient|shadow|spacing|font-size|font-family|aspect-ratio|duotone){$dashes}/",
            'var:preset|$1|',
            $markup
        );
    }

    /** Strip a leading/trailing markdown code fence if the model added one. */
    private static function stripFences(string $text): string
    {
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = preg_replace('/\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }
}
