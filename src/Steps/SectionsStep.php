<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionRole;
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
    private const CACHE_WARM_PROMPT = 'Warm the cached section context.';

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
        . ' menu is clearly wanted (split-nav is the exception: it requires hand-authored links).';

    /** {{nav_rule}} when the site is the homepage alone — a page-list would render one self-referential "Home" link. */
    private const NAV_RULE_SINGLE = '- Navigation: this site is ONE page, so a page-list would render a single'
        . ' self-referential "Home" link — do NOT use `<!-- wp:page-list /-->`, and do NOT link to the page itself.'
        . ' Either omit navigation entirely (the wordmark carries the header) or hand-author a small `wp:navigation`'
        . ' of `wp:navigation-link` items targeting section anchors from the HOMEPAGE OUTLINE (each outline line ends'
        . ' with its [#anchor]; a link\'s "url" is that anchor, e.g. href="#menu-highlights").';

    /**
     * {{nav_rule}} text for header generation given how many pages the plan has.
     *
     * Public so host adapters (e.g. wpcom queue phase) share the same source of
     * truth as jobs() — do not re-mirror the private constants.
     */
    public static function navRuleFor(int $pageCount): string
    {
        return $pageCount > 1 ? self::NAV_RULE_MULTI : self::NAV_RULE_SINGLE;
    }

    private SectionUnit $sectionUnit;
    private HeaderUnit $headerUnit;
    private FooterUnit $footerUnit;

    /** Env var: force a header archetype (e.g. HEADER_ARCHETYPE=branded-lockup). */
    public const ARCHETYPE_ENV = 'HEADER_ARCHETYPE';

    /** Header archetype menu — must match the catalog in header.md. */
    public const HEADER_ARCHETYPES = [
        'standard-row',
        'centered-masthead',
        'minimal-overlay',
        'oversized-wordmark',
        'branded-lockup',
        'double-decker',
        'split-nav',
    ];

    /** Floats transparently over the hero, so it needs an image-led hero under it. */
    private const OVERLAY_ARCHETYPE = 'minimal-overlay';

    /** Splits the site's pages across two navs, so it needs pages to split. */
    private const SPLIT_NAV_ARCHETYPE = 'split-nav';

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
            // pages.json: a section whose markup is unusable is dropped and
            // pruned from the plan so downstream steps see a consistent site.
            writes: ['theme/parts/*', 'pages.json', 'warnings.json'],
            concurrent: true,
        );
    }

    public function requests(Project $project): array
    {
        return self::requestsFor($this->jobs($project));
    }

    public function run(Project $project): void
    {
        $warnings = [];
        $jobs = $this->jobs($project, $warnings);
        $requests = self::requestsFor($jobs);
        $this->warmSectionCache($requests);
        $parts = $this->llm->completeBatch($requests);

        // Normalize EVERY part before writing any, so one bad part doesn't
        // leave a half-written set of files on disk. A part whose response is
        // unusable degrades instead of aborting: chrome falls back to a
        // deterministic minimal part, and a page section is dropped (and
        // pruned from pages.json below) so the rest of the paid-for build
        // still ships. Only a build with NO usable page section left is fatal
        // — there is no site to deliver.
        $files = [];
        $dropped = [];
        foreach ($jobs as $key => $job) {
            $isChrome = in_array($key, ['header', 'footer'], true);
            try {
                if (!array_key_exists($key, $parts) || !is_string($parts[$key])) {
                    throw new \RuntimeException('the batch returned no result');
                }
                $files[$job['file']] = $job['unit']->finish($parts[$key], $job['input'], $warnings);
            } catch (\RuntimeException $e) {
                if ($isChrome) {
                    $files[$job['file']] = self::fallbackChrome($key);
                    $warnings[] = "part '{$key}': unusable generated markup ({$e->getMessage()}); "
                        . "deterministic minimal {$key} delivered";
                } else {
                    $dropped[$key] = true;
                    $warnings[] = "part '{$key}': unusable generated markup ({$e->getMessage()}); "
                        . 'section dropped from the page plan';
                }
                fwrite(STDERR, "    (part '{$key}': unusable generated markup — {$e->getMessage()})\n");
            }
        }

        if ($dropped !== [] && count($files) <= 2) {
            // Only the chrome (or nothing) survived: a site with zero content
            // sections is not a usable partial result.
            throw new \RuntimeException(
                'sections: no page section produced usable markup: ' . implode('; ', $warnings)
            );
        }

        foreach ($files as $rel => $markup) {
            $project->writeText('theme/' . $rel, $markup . "\n");
        }
        if ($dropped !== []) {
            self::pruneDroppedSections($project, $dropped, $warnings);
        }
        $project->addWarnings($this->id(), $warnings);
    }

    /**
     * A deterministic minimal chrome part delivered when the generated
     * header/footer markup is unusable: a constrained group carrying the site
     * title, so templates referencing the part render something coherent.
     */
    public static function fallbackChrome(string $key): string
    {
        $tag = $key === 'header' ? 'header' : 'footer';
        return '<!-- wp:group {"tagName":"' . $tag . '","layout":{"type":"constrained"},'
            . '"style":{"spacing":{"padding":{"top":"var:preset|spacing|md","bottom":"var:preset|spacing|md"}}}} -->' . "\n"
            . '<' . $tag . ' class="wp-block-group" style="padding-top:var(--wp--preset--spacing--md);'
            . 'padding-bottom:var(--wp--preset--spacing--md)"><!-- wp:site-title /--></' . $tag . '>' . "\n"
            . '<!-- /wp:group -->';
    }

    /**
     * Remove dropped sections from pages.json so every downstream consumer
     * (section-rhythm, assemble-pages, motion-sanity) sees the same plan the
     * parts on disk satisfy. A page whose every section was dropped is removed
     * whole — an empty page in the nav is worse than an absent one — except
     * the front page, which templates and the seeder rely on.
     *
     * @param array<string,true> $dropped part keys (partSlug) of dropped sections
     * @param list<string>       $warnings appended to in place
     */
    private static function pruneDroppedSections(Project $project, array $dropped, array &$warnings): void
    {
        $plan = $project->readJson('pages.json');
        $pages = [];
        foreach ((array) ($plan['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $pageSlug = (string) ($page['slug'] ?? '');
            $kept = array_values(array_filter(
                (array) ($page['sections'] ?? []),
                static fn ($section): bool => !is_array($section)
                    || !isset($dropped[self::partSlug($pageSlug, (string) ($section['slug'] ?? ''))]),
            ));
            if ($kept === [] && empty($page['front'])) {
                $warnings[] = "page '{$pageSlug}': every section was dropped; page removed from the plan";
                continue;
            }
            $page['sections'] = $kept;
            $pages[] = $page;
        }
        $plan['pages'] = $pages;
        $project->writeJson('pages.json', $plan);
    }

    /**
     * Ask each job's unit to render its self-contained LLM request.
     *
     * @param array<string,array{unit:MarkupUnit,input:array<mixed>,file:string}> $jobs
     * @return array<string,array{prompt:string,model?:string,temperature?:float,cached_prefixes?:list<string>}>
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
     * Warm the exact cached context used by the deterministic first section.
     * A failed probe only forfeits first-window cache hits; it must not abort
     * the build or change the subsequent concurrent fan-out.
     *
     * @param array<string,array{prompt:string,model?:string,temperature?:float,cached_prefixes?:list<string>}> $requests
     */
    private function warmSectionCache(array $requests): void
    {
        foreach ($requests as $request) {
            if (!isset($request['cached_prefixes'])) {
                continue;
            }

            $opts = $request;
            unset($opts['prompt']);
            $opts['max_tokens'] = 1;
            $opts['tolerate_empty'] = true;
            $opts['log_label'] = 'section-cache-warm';

            try {
                $this->llm->complete(self::CACHE_WARM_PROMPT, $opts);
            } catch (\Throwable $e) {
                fwrite(STDERR, "    section cache warm-up failed ({$e->getMessage()}); continuing uncached\n");
            }
            return;
        }
    }

    /**
     * Read Project state once and adapt it into self-contained unit inputs.
     *
     * Plan drift that is deterministically repairable is repaired here, not
     * rejected: a section role is a pure function of its position, and a
     * missing semantic type has a safe generic default. Each repair is noted
     * in $warnings for warnings.json.
     *
     * @param list<string> $warnings appended to in place
     * @return array<string,array{unit:MarkupUnit,input:array<mixed>,file:string}>
     */
    private function jobs(Project $project, array &$warnings = []): array
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
                    'nav_rule'   => self::navRuleFor(count($pages)),
                    'archetype_assignment' => self::headerAssignment($frontSections, DesignDirectionStep::canvasFor($project), count($pages)),
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
                $role = trim((string) ($section['role'] ?? ''));
                $expectedRole = SectionRole::forPosition($i, count($sections));
                if ($role !== $expectedRole) {
                    $slug = (string) ($section['slug'] ?? "section-{$i}");
                    $warnings[] = "page '{$page['slug']}' section '{$slug}': "
                        . "role '{$role}' corrected to '{$expectedRole}' (derived from its position in the plan)";
                    $section['role'] = $expectedRole;
                }
                $type = trim((string) ($section['type'] ?? ''));
                if ($type === '') {
                    $slug = (string) ($section['slug'] ?? "section-{$i}");
                    $warnings[] = "page '{$page['slug']}' section '{$slug}': "
                        . "missing semantic type; defaulted to 'content'";
                    $section['type'] = 'content';
                }
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
                ];            }
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
        $hero = self::heroSection($sections);
        if (!is_array($hero)) {
            return '(No hero section planned.)';
        }

        $lines = [];
        foreach (
            ['title' => 'Title', 'role' => 'Role', 'type' => 'Type', 'purpose' => 'Purpose', 'content_notes' => 'Notes']
            as $key => $label
        ) {
            $value = trim((string) ($hero[$key] ?? ''));
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }
        return $lines === [] ? '(No hero section planned.)' : implode("\n", $lines);
    }

    /**
     * The planned section with the structural hero ROLE — the semantic type is
     * free-form and a `type: hero` elsewhere on the page must not win. No
     * fallback: a plan without a hero role has no hero.
     *
     * @param array<int,array<string,mixed>> $sections
     * @return array<string,mixed>|null
     */
    private static function heroSection(array $sections): ?array
    {
        foreach ($sections as $s) {
            if ((string) ($s['role'] ?? '') === SectionRole::HERO) {
                return $s;
            }
        }
        return null;
    }

    /**
     * The header archetypes compatible with the planned hero, the direction's
     * canvas, and the site's shape: minimal-overlay floats transparently over
     * the first section, so it is only offered when the hero is an image-led
     * cover it can read against — and never on a "framed" canvas, whose mat of
     * page background would sit under the overlay instead of the image.
     * split-nav splits the site's pages across two navs, so a one-page site
     * (where the nav rule prescribes section anchors instead) drops it.
     * Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     * @return string[]
     */
    public static function headerArchetypePool(array $sections, string $canvas = '', int $pageCount = 2): array
    {
        $hero = self::heroSection($sections);
        $imageLed = is_array($hero) && (
            (string) ($hero['layout_archetype'] ?? '') === 'full-bleed-cover'
            || (string) ($hero['background'] ?? '') === 'image'
        );
        $excluded = [];
        if (!$imageLed || $canvas === 'framed') {
            $excluded[] = self::OVERLAY_ARCHETYPE;
        }
        if ($pageCount <= 1) {
            $excluded[] = self::SPLIT_NAV_ARCHETYPE;
        }
        return array_values(array_diff(self::HEADER_ARCHETYPES, $excluded));
    }

    /**
     * The archetype assignment injected into header.md: the forced archetype
     * (HEADER_ARCHETYPE env var) or two random picks from the compatible pool
     * for the model to choose between. Randomizing the shortlist in code is
     * what actually spreads header variety across builds — offered the full
     * menu, the model gravitates to the same one or two archetypes every time.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function headerAssignment(array $sections, string $canvas = '', int $pageCount = 2): string
    {
        $forced = Env::get(self::ARCHETYPE_ENV);
        if ($forced !== null && $forced !== '') {
            if (!in_array($forced, self::HEADER_ARCHETYPES, true)) {
                throw new \RuntimeException(sprintf(
                    'sections: %s=%s is not a header archetype (use one of: %s)',
                    self::ARCHETYPE_ENV,
                    $forced,
                    implode(', ', self::HEADER_ARCHETYPES),
                ));
            }
            return "ASSIGNED HEADER ARCHETYPE for this build: **{$forced}**. Build exactly this one.";
        }

        $pool = self::headerArchetypePool($sections, $canvas, $pageCount);
        $first = array_splice($pool, random_int(0, count($pool) - 1), 1)[0];
        $second = $pool[random_int(0, count($pool) - 1)];
        return "ASSIGNED HEADER ARCHETYPES for this build: **{$first}** or **{$second}**. "
            . 'Build EXACTLY ONE of these two — whichever serves the DESIGN DIRECTION and the planned hero better. '
            . 'Every other catalog entry below is reference only and is OFF the table for this build.';
    }
}
