<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\HeaderUnit;
use Automattic\SiteBuild\Units\MarkupUnit;
use Automattic\SiteBuild\Units\SectionUnit;

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
 * The prompt rendering and response normalization for each part live in the
 * Units\* markup units; this step only adapts Project state into their
 * self-contained inputs and batches their requests.
 *
 * Each part's response IS the block markup (raw text, via completeBatch) — not
 * JSON-wrapped — so the model never has to escape its HTML into a JSON string.
 */
final class SectionsStep implements Step
{
    /** Prefix for a page section part's request key and filename. */
    public const PART_PREFIX = SectionUnit::KEY_PREFIX;

    /** The part slug (request key and file basename) for one page's section. */
    public static function partSlug(string $pageSlug, string $sectionSlug): string
    {
        return SectionUnit::partKey($pageSlug, $sectionSlug);
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

    private SectionUnit $sectionUnit;
    private HeaderUnit $headerUnit;
    private FooterUnit $footerUnit;

    public function __construct(
        private Llm $llm,
        PromptRenderer $renderer,
        ?string $model = null,
        ?float $temperature = null,
    ) {
        $this->sectionUnit = new SectionUnit($llm, $renderer, $model, $temperature);
        $this->headerUnit = new HeaderUnit($llm, $renderer, $model, $temperature);
        $this->footerUnit = new FooterUnit($llm, $renderer, $model, $temperature);
    }

    public function id(): string
    {
        return 'sections';
    }

    public function label(): string
    {
        return "Build every page's sections";
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'siteSpec.json',
                'theme/theme.json',
                'pages.json',
                'designDirection.json',
            ],
            writes: ['theme/parts/*'],
            concurrent: true,
        );
    }

    public function requests(Project $project): array
    {
        return self::requestsFor($this->jobs($project));
    }

    public function run(Project $project): void
    {
        $jobs = $this->jobs($project);
        $parts = $this->llm->completeBatch(self::requestsFor($jobs));

        // Validate EVERY part before writing any, so one bad part doesn't leave
        // a half-written set of files on disk (the build aborts either way).
        $files = [];
        foreach ($jobs as $key => $job) {
            if (!array_key_exists($key, $parts) || !is_string($parts[$key])) {
                throw new \RuntimeException("sections: missing result for part '{$key}'");
            }
            $files[$job['file']] = $job['unit']->finish($parts[$key], $job['input']);
        }

        foreach ($files as $rel => $markup) {
            $project->writeText('theme/' . $rel, $markup . "\n");
        }
    }

    /**
     * Ask each job's unit to render its self-contained LLM request.
     *
     * @param array<string,array{unit:MarkupUnit,input:array<mixed>,file:string}> $jobs
     * @return array<string,array{prompt:string,model?:string,temperature?:float}>
     */
    private static function requestsFor(array $jobs): array
    {
        $requests = [];
        foreach ($jobs as $key => $job) {
            $requests[$key] = $job['unit']->request($job['input']);
        }
        return $requests;
    }

    /**
     * Read Project state once and adapt it into self-contained unit inputs.
     *
     * @return array<string,array{unit:MarkupUnit,input:array<mixed>,file:string}>
     */
    private function jobs(Project $project): array
    {
        $pages = self::pages($project);

        $common = [
            'site_spec'        => $project->readText('siteSpec.json'),
            'language'         => SiteSpecStep::languageOf($project),
            'theme_json'       => $project->readText('theme/theme.json'),
            'design_direction' => DesignDirectionStep::readFor($project),
            'site_pages'       => PagePlanStep::sitePagesList($pages),
        ];

        // The chrome is briefed on the FRONT page: that's what the header sits
        // directly above (or floats on) and what sets the site's opening tone.
        $frontSections = self::frontPage($pages)['sections'];
        $jobs = [
            'header' => [
                'unit'  => $this->headerUnit,
                'input' => $common + [
                    'outline'    => self::outline($frontSections),
                    'hero_brief' => self::heroBrief($frontSections),
                    'nav_rule'   => count($pages) > 1 ? self::NAV_RULE_MULTI : self::NAV_RULE_SINGLE,
                ],
                'file'  => 'parts/header.html',
            ],
            'footer' => [
                'unit'  => $this->footerUnit,
                'input' => $common + ['outline' => self::outline($frontSections)],
                'file'  => 'parts/footer.html',
            ],
        ];

        foreach ($pages as $page) {
            $sections = $page['sections'];
            // A compact outline of THIS page, so each section knows its place.
            $outline = self::outline($sections);
            foreach ($sections as $i => $section) {
                $input = $common + [
                    'outline'   => $outline,
                    'page'      => [
                        'slug'  => (string) ($page['slug'] ?? ''),
                        'title' => (string) ($page['title'] ?? ''),
                        'path'  => (string) ($page['path'] ?? '/'),
                    ],
                    'section'   => $section,
                    'neighbors' => self::neighbors($sections, $i),
                ];
                $key = $this->sectionUnit->key($input);
                $jobs[$key] = [
                    'unit'  => $this->sectionUnit,
                    'input' => $input,
                    'file'  => 'parts/' . $key . '.html',
                ];
            }
        }

        return $jobs;
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
        $density = trim((string) ($section['vertical_density'] ?? ''));
        if ($archetype === '' && $background === '' && $density === '') {
            return '';
        }
        $assignment = trim($archetype . ($background !== '' ? " on {$background} background" : ''));
        return trim($assignment . ($density !== '' ? ", {$density} vertical density" : ''));
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
}
