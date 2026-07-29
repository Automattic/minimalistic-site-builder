<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Narrator;
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

    /** Display-scale wordmark — competes head-on with a display-scale hero H1. */
    private const OVERSIZED_ARCHETYPE = 'oversized-wordmark';

    /** Multi-row centered masthead — too tall to stack above a viewport-scale cover. */
    private const MASTHEAD_ARCHETYPE = 'centered-masthead';

    /** Header mode: the header floats transparently over the hero cover. */
    public const MODE_OVERLAY = 'overlay';

    /** Header mode: the header is an opaque bar stacked above the hero. */
    public const MODE_STACKED = 'stacked';

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
        $plan = $project->readJson('pages.json');
        $pages = self::repairedPages(self::pages($project), $warnings);
        $jobs = $this->jobs($project, $warnings, $pages);
        $requests = self::requestsFor($jobs);
        $this->warmSectionCache($requests);
        $batch = $this->llm->completeBatch($requests);
        $parts = $batch->texts;

        // Normalize EVERY part before writing any, so one bad part doesn't
        // leave a half-written set of files on disk. A part whose response is
        // unusable degrades instead of aborting: chrome falls back to a
        // deterministic minimal part, and a page section is dropped (and
        // pruned from pages.json below) so the rest of the paid-for build
        // still ships. If every section is unusable, the deterministic chrome
        // and an empty front page remain a meaningful partial site, so that
        // generated-content failure degrades too.
        $files = [];
        $dropped = [];
        foreach ($jobs as $key => $job) {
            $isChrome = in_array($key, ['header', 'footer'], true);
            try {
                if (!array_key_exists($key, $parts) || !is_string($parts[$key])) {
                    throw new \RuntimeException('the batch returned no result');
                }
                $files[$job['file']] = $job['unit']->finish($parts[$key], $job['input'], $warnings);
                array_push($warnings, ...$batch->notesFor($key));
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
                Narrator::write("    (part '{$key}': unusable generated markup — {$e->getMessage()})\n");
            }
        }

        // Commit the repaired/pruned plan only after every generated response
        // has been normalized. An operational batch failure above therefore
        // leaves pages.json byte-for-byte unchanged. Pruning can change each
        // survivor's positional role, so recompute roles after the cut.
        $pages = self::pruneDroppedSections($pages, $dropped, $warnings);
        $pages = self::repairedPages($pages, $warnings);
        $plan['pages'] = $pages;
        foreach ($files as $rel => $markup) {
            $project->writeText('theme/' . $rel, $markup . "\n");
        }
        $project->writeJson('pages.json', $plan);
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
     * Public for the same reason repairedPages() is: the wpcom fan-out path
     * replaces this step and must apply the identical drop-and-prune
     * degradation when a part is unusable, or one lost section discards a
     * whole build the CLI would have shipped. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sourcePages
     * @param array<string,true> $dropped part keys (partSlug) of dropped sections
     * @param list<string>       $warnings appended to in place
     * @return array<int,array<string,mixed>>
     */
    public static function pruneDroppedSections(array $sourcePages, array $dropped, array &$warnings): array
    {
        $pages = [];
        foreach ($sourcePages as $page) {
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
            if ($kept === []) {
                $warnings[] = "front page '{$pageSlug}': every section was dropped; empty front page delivered";
            }
            $page['sections'] = $kept;
            $pages[] = $page;
        }
        return $pages;
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
                Narrator::write("    section cache warm-up failed ({$e->getMessage()}); continuing uncached\n");
            }
            return;
        }
    }

    /**
     * Deterministically repair plan drift in every page's section list: a
     * section role is a pure function of its position, and a missing semantic
     * type has a safe generic default. Each repair is noted in $warnings for
     * warnings.json. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param list<string> $warnings appended to in place
     * @return array<int,array<string,mixed>>
     */
    public static function repairedPages(array $pages, array &$warnings = []): array
    {
        foreach ($pages as $p => $page) {
            $sections = (array) ($page['sections'] ?? []);
            foreach ($sections as $i => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $role = trim((string) ($section['role'] ?? ''));
                $expectedRole = SectionRole::forPosition($i, count($sections));
                if ($role !== $expectedRole) {
                    $slug = (string) ($section['slug'] ?? "section-{$i}");
                    $warnings[] = "page '{$page['slug']}' section '{$slug}': "
                        . "role '{$role}' corrected to '{$expectedRole}' (derived from its position in the plan)";
                    $sections[$i]['role'] = $expectedRole;
                }
                $type = trim((string) ($section['type'] ?? ''));
                if ($type === '') {
                    $slug = (string) ($section['slug'] ?? "section-{$i}");
                    $warnings[] = "page '{$page['slug']}' section '{$slug}': "
                        . "missing semantic type; defaulted to 'content'";
                    $sections[$i]['type'] = 'content';
                }
            }
            $pages[$p]['sections'] = $sections;
        }
        return $pages;
    }

    /**
     * Read Project state once and adapt it into self-contained unit inputs.
     * Plan drift is repaired via repairedPages() so the prompts always see a
     * consistent plan. run() passes its in-memory repaired pages here and
     * commits them only after every generated response has been normalized.
     *
     * @param list<string> $warnings appended to in place
     * @param array<int,array<string,mixed>>|null $sourcePages
     * @return array<string,array{unit:MarkupUnit,input:array<mixed>,file:string}>
     */
    private function jobs(Project $project, array &$warnings = [], ?array $sourcePages = null): array
    {
        $pages = self::repairedPages($sourcePages ?? self::pages($project), $warnings);

        $common = [
            'site_spec'        => $project->readText('siteSpec.json'),
            'language'         => SiteSpecStep::languageOf($project),
            'theme_json'       => $project->readText('theme/theme.json'),
            'design_direction' => DesignDirectionStep::readFor($project),
            'site_pages'       => PagePlanStep::sitePagesList($pages),
        ];

        // The chrome is briefed on the FRONT page: that's what the header sits
        // directly above (or floats on) and what sets the site's opening tone.
        // The header mode is the shared contract: both sides of the page-top
        // seam derive it from the same pure headerMode(), so the header's
        // archetype assignment and every hero section's brief compose
        // deliberately instead of each guessing. headerAssignment() derives it
        // again from the canvas rather than taking it as an argument, which
        // keeps its signature usable from tests.
        $canvas = DesignDirectionStep::canvasFor($project);
        $headerMode = self::headerMode($pages, $canvas);
        // Fixed for the whole build, so the hero brief is built once, not once
        // per hero section.
        $headerContract = self::headerContract($headerMode);
        $frontSections = self::frontPage($pages)['sections'];
        $jobs = [
            'header' => [
                'unit'  => $this->headerUnit,
                'input' => $common + [
                    'outline'    => self::outline($frontSections),
                    'hero_brief' => self::heroBrief($frontSections),
                    'nav_rule'   => self::navRuleFor(count($pages)),
                    'archetype_assignment' => self::headerAssignment($pages, $canvas),
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
                    // Only page-opening sections share the viewport with the
                    // header; everything below scrolls in under its own rules.
                    'header_contract' => (string) ($section['role'] ?? '') === SectionRole::HERO
                        ? $headerContract
                        : '',
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
     * Whether the planned hero is an image-led cover (the composition an
     * overlay header can float on and read against).
     *
     * @param array<int,array<string,mixed>> $sections
     */
    private static function imageLedHero(array $sections): bool
    {
        $hero = self::heroSection($sections);
        return is_array($hero) && (
            (string) ($hero['layout_archetype'] ?? '') === 'full-bleed-cover'
            || (string) ($hero['background'] ?? '') === 'image'
        );
    }

    /**
     * The deterministic top-of-page contract (BIGR-735): decided once from the
     * plan, then injected into BOTH the header prompt and every hero-section
     * prompt, so the two parts compose instead of colliding blind.
     *
     * `overlay` — the header floats transparently over the hero — requires an
     * image-led, full-bleed front hero (never a "framed" canvas, whose mat
     * would sit under the overlay instead of the image), and because the
     * header renders on EVERY page, every page's opening section must read as
     * a dark band (an `image` background is dimmed to 40%+ by the cover rules;
     * a `contrast` band is dark by definition) so the one light text color the
     * overlay commits to reads everywhere. Anything else is `stacked`.
     * Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $pages
     */
    /**
     * The section a page opens on — the only one that shares the viewport with
     * the header, and so the one both sides of the seam ask about.
     *
     * @param array<string,mixed> $page
     * @return array<string,mixed>|null
     */
    public static function openingSection(array $page): ?array
    {
        $first = ((array) ($page['sections'] ?? []))[0] ?? null;
        return is_array($first) ? $first : null;
    }

    public static function headerMode(array $pages, string $canvas = ''): string
    {
        $front = self::frontPage($pages);
        if (!self::imageLedHero((array) ($front['sections'] ?? [])) || $canvas === 'framed') {
            return self::MODE_STACKED;
        }
        foreach ($pages as $page) {
            $first = self::openingSection($page);
            $background = is_array($first) ? (string) ($first['background'] ?? '') : '';
            if (!in_array($background, ['image', 'contrast'], true)) {
                return self::MODE_STACKED;
            }
        }
        return self::MODE_OVERLAY;
    }

    /**
     * The header archetypes compatible with the header mode and the site's
     * shape. In overlay mode the pool IS minimal-overlay: the mode exists so
     * that an image-led full-bleed hero reliably gets the floating header the
     * theme's `.header-overlay` CSS was written for, instead of losing a
     * random draw to an opaque bar (the audited projects shipped that dead CSS
     * 6 times out of 6). In stacked mode:
     *  - minimal-overlay is out (nothing image-led to float on),
     *  - split-nav needs pages to split, so a one-page site drops it,
     *  - oversized-wordmark is out when the plan has a hero: every planned
     *    hero opens with a display-scale H1, and a display-scale wordmark
     *    ~100px above it is two competing mastheads,
     *  - centered-masthead (2-3 stacked centered rows, the tallest archetype)
     *    is out when the hero is image-led: stacking it above a viewport-scale
     *    cover pushes the hero's content below the fold.
     * Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return string[]
     */
    public static function headerArchetypePool(array $pages, string $canvas = ''): array
    {
        if (self::headerMode($pages, $canvas) === self::MODE_OVERLAY) {
            return [self::OVERLAY_ARCHETYPE];
        }
        $frontSections = (array) (self::frontPage($pages)['sections'] ?? []);
        $excluded = [self::OVERLAY_ARCHETYPE];
        if (count($pages) <= 1) {
            $excluded[] = self::SPLIT_NAV_ARCHETYPE;
        }
        if (self::heroSection($frontSections) !== null) {
            $excluded[] = self::OVERSIZED_ARCHETYPE;
        }
        if (self::imageLedHero($frontSections)) {
            $excluded[] = self::MASTHEAD_ARCHETYPE;
        }
        return array_values(array_diff(self::HEADER_ARCHETYPES, $excluded));
    }

    /**
     * The archetype assignment injected into header.md: the forced archetype
     * (HEADER_ARCHETYPE env var), the contract-mandated minimal-overlay in
     * overlay mode, or two random picks from the compatible pool for the model
     * to choose between. Randomizing the shortlist in code is what actually
     * spreads header variety across builds — offered the full menu, the model
     * gravitates to the same one or two archetypes every time.
     *
     * @param array<int,array<string,mixed>> $pages
     */
    public static function headerAssignment(array $pages, string $canvas = ''): string
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

        $pool = self::headerArchetypePool($pages, $canvas);
        if ($pool === [self::OVERLAY_ARCHETYPE]) {
            return 'ASSIGNED HEADER ARCHETYPE for this build: **minimal-overlay**. '
                . 'The planned hero is an image-led, full-bleed cover and every page opens on a dark band, '
                . 'so the header floats over the imagery instead of stacking above it. Build exactly this one; '
                . 'its top-level wp:group MUST carry "className":"header-overlay" (a deterministic pass verifies it). '
                . 'Every other catalog entry below is reference only and is OFF the table for this build.';
        }
        $first = array_splice($pool, random_int(0, count($pool) - 1), 1)[0];
        $second = $pool[random_int(0, count($pool) - 1)];
        return "ASSIGNED HEADER ARCHETYPES for this build: **{$first}** or **{$second}**. "
            . 'Build EXACTLY ONE of these two — whichever serves the DESIGN DIRECTION and the planned hero better. '
            . 'Every other catalog entry below is reference only and is OFF the table for this build. '
            . 'This header STACKS as an opaque bar directly above the hero, inside the same first viewport '
            . '(the hero is told the same thing and caps its cover height for you) — keep the bar to ONE compact row.';
    }

    /**
     * The header-side contract rendered into every hero-role section brief
     * ({{header_contract}} in section.md), so the section composes with the
     * header that will render above — or float on — it. The header renders on
     * every page, so every page's opening section gets the same contract.
     * Pure — unit-testable.
     */
    public static function headerContract(string $mode): string
    {
        if ($mode === self::MODE_OVERLAY) {
            return "HEADER CONTRACT (this is a page-opening section):\n"
                . "The site header floats TRANSPARENTLY over the very top of this section — a slim overlay bar "
                . "(~60px) with no background of its own. Compose for it:\n"
                . "- The cover's dim/gradient protection MUST reach the very top edge of the image: the header's "
                . "text sits on your top ~80px, not only behind your headline.\n"
                . "- A full-viewport cover (\"minHeight\":90-100 with \"minHeightUnit\":\"vh\") is welcome — nothing "
                . "opaque stacks above it.\n"
                . "- Do NOT reserve blank space for the header and do NOT stack a padded page-background band above "
                . "the cover: the image meets the top of the viewport.";
        }
        return "HEADER CONTRACT (this is a page-opening section):\n"
            . "An OPAQUE site header (one compact bar, roughly 80-100px tall) is stacked directly above this "
            . "section, inside the same first viewport. Compose for the space that remains:\n"
            . "- Cap any cover at \"minHeight\":" . HeaderHeroStep::STACKED_COVER_VH . " with "
            . "\"minHeightUnit\":\"vh\" or less — header + cover must fit ~100vh together (a deterministic "
            . "pass lowers taller covers to " . HeaderHeroStep::STACKED_COVER_VH . "vh).\n"
            . "- The headline and any CTA must land inside the first viewport: on a cover of 70vh or more, avoid a "
            . "bottom-anchored \"contentPosition\" — center or upper placement keeps the masthead above the fold.\n"
            . "- Do not open with a tall band of bare page background above your first visual: the header already "
            . "spent ~100px of the viewport, so keep the section's own top spacing at or below the md step when the "
            . "band above it shares its background.";
    }
}
