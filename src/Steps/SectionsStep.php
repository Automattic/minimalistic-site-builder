<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\AboveFoldPartFacts;
use Automattic\SiteBuild\BilledInput;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\HeaderFallback;
use Automattic\SiteBuild\HeroFallback;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\PageOpeningFallback;
use Automattic\SiteBuild\PlaygroundArtifact;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionRole;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TextBatchResult;
use Automattic\SiteBuild\TransformArtifacts;
use Automattic\SiteBuild\UsageReporting;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\GeneratedMarkup;
use Automattic\SiteBuild\Units\HeaderUnit;
use Automattic\SiteBuild\Units\HeroUnit;
use Automattic\SiteBuild\Units\MarkupUnit;
use Automattic\SiteBuild\Units\SectionUnit;
use Automattic\SiteBuild\Warnings;

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
    private const CACHE_WARM_PROMPT = 'Warm the cached markup context.';

    /**
     * Below this many estimated prefix tokens the warm-up probe cannot tell a
     * discarded layer from ordinary system-prompt overhead, so it stays quiet.
     * Real section layers run to thousands of tokens.
     */
    private const CONTEXT_PROBE_MIN_TOKENS = 500;

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

    /** {{nav_rule}} text for footer generation given how many pages the plan has. */
    public static function footerNavRuleFor(int $pageCount): string
    {
        return FooterComposition::navigationRule($pageCount);
    }

    private SectionUnit $sectionUnit;
    private HeroUnit $heroUnit;
    private HeaderUnit $headerUnit;
    private FooterUnit $footerUnit;

    /** Footer composition menu shared with the stateless FooterUnit. */
    public const FOOTER_ARCHETYPES = FooterComposition::ARCHETYPES;

    public function __construct(
        private Llm $llm,
        PromptRenderer $renderer,
        ?string $model = null,
        ?float $temperature = null,
    ) {
        $this->sectionUnit = new SectionUnit($llm, $renderer, $model, $temperature);
        $this->heroUnit = new HeroUnit($llm, $renderer, $model, $temperature);
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
                'meta.json',
                'siteSpec.json',
                'theme/theme.json',
                'pages.json',
                'designDirection.json',
            ],
            // pages.json: a section whose markup is unusable is dropped and
            // pruned from the plan so downstream steps see a consistent site.
            writes: ['theme/parts/*', 'pages.json', 'aboveFold.json', 'warnings.json'],
            concurrent: true,
        );
    }

    public function requests(Project $project): array
    {
        return self::requestsFor($this->jobPlan($project)['jobs']);
    }

    public function run(Project $project): void
    {
        $warnings = [];
        $repairs = [];
        $plan = $project->readJson('pages.json');
        $pages = self::repairedPages(self::pages($project), $repairs);
        $jobPlan = $this->jobPlan($project, $repairs, $pages, $warnings);
        $jobs = $jobPlan['jobs'];
        $initialContract = $jobPlan['contract'];
        $requests = self::requestsFor($jobs);
        array_push($warnings, ...$this->warmMarkupCache($requests));
        $batchFailure = null;
        try {
            $batch = $this->llm->completeBatch($requests);
        } catch (\RuntimeException $error) {
            // The raw-text transport is all-or-nothing, but every member has
            // a reviewed unit fallback below. Treat a permanent batch failure
            // as one missing result per key so already-paid planning work can
            // still deliver the smallest meaningful site.
            $batch = new TextBatchResult([]);
            $batchFailure = str_replace(["\r", "\n"], ' ', $error->getMessage());
            Narrator::write(
                '    (sections batch failed; applying per-unit fallbacks: '
                . $batchFailure . ")\n"
            );
        }
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
            try {
                if (!array_key_exists($key, $parts) || !is_string($parts[$key])) {
                    throw new \RuntimeException($batchFailure === null
                        ? 'the batch returned no result'
                        : "the generation batch failed: {$batchFailure}");
                }
                $result = $job['unit']->finish($parts[$key], $job['input']);
                if (($job['opening'] ?? false) === true) {
                    self::assertOpeningRoot($result->markup, $key);
                }
                $files[$job['file']] = $result->markup;
                array_push($repairs, ...$result->repairs);
                array_push(
                    $warnings,
                    ...$result->warnings,
                    ...self::batchWarnings($job['file'], $batch->notesFor($key)),
                );
            } catch (\RuntimeException $e) {
                $authoredFailure = Warnings::value($e->getMessage());
                $warningContext = "file='theme/{$job['file']}'; block='part root'; "
                    . "authored={$authoredFailure}; ";
                if ($key === 'header') {
                    $fallback = HeaderFallback::render($job['input'], $initialContract, $e->getMessage());
                    $files[$job['file']] = $fallback->markup;
                    array_push($repairs, ...$fallback->repairs);
                    array_push($warnings, ...$fallback->warnings);
                } elseif ($key === 'footer') {
                    // This is an error handler: an unknown archetype falls
                    // back to the default surface instead of throwing again.
                    // The resolved surface on the job wins when present — the
                    // fallback part must land on the same band the closing
                    // sections were planned against.
                    $assignedArchetype = (string) ($job['input']['composition_archetype'] ?? '');
                    $resolvedSurface = (string) ($job['input']['surface'] ?? '');
                    $footerSurface = match (true) {
                        $resolvedSurface !== '' => $resolvedSurface,
                        in_array($assignedArchetype, FooterComposition::ARCHETYPES, true)
                            => FooterComposition::surface($assignedArchetype),
                        default => null,
                    };
                    $footerPageCount = $key === 'footer' && is_int($job['input']['page_count'] ?? null)
                        ? $job['input']['page_count']
                        : null;
                    $files[$job['file']] = self::fallbackChrome($key, $footerSurface, $footerPageCount);
                    $warnings[] = "part '{$key}': unusable generated markup; {$warningContext}"
                        . "delivered=deterministic minimal {$key}; disposition=unusable template-part markup "
                        . 'replaced while preserving the rest of the generated site';
                } elseif (($job['front_hero'] ?? false) === true) {
                    $fallback = HeroFallback::render($job['input'], $initialContract, $e->getMessage());
                    $files[$job['file']] = $fallback->markup;
                    array_push($repairs, ...$fallback->repairs);
                    array_push($warnings, ...$fallback->warnings);
                } elseif (($job['opening'] ?? false) === true) {
                    $fallback = PageOpeningFallback::render($job['input'], $initialContract, $e->getMessage());
                    $files[$job['file']] = $fallback->markup;
                    array_push($repairs, ...$fallback->repairs);
                    array_push($warnings, ...$fallback->warnings);
                } else {
                    $dropped[$key] = true;
                    $warnings[] = "part '{$key}': unusable generated markup; {$warningContext}"
                        . 'delivered=removed; disposition=only the unusable section part was removed and pruned '
                        . 'from pages.json';
                }
                Narrator::write("    (part '{$key}': unusable generated markup — {$e->getMessage()})\n");
            }
        }

        // Commit the repaired/pruned plan only after every generated response
        // has been normalized. An operational batch failure above therefore
        // leaves pages.json byte-for-byte unchanged. Pruning can change each
        // survivor's positional role, so recompute roles after the cut.
        $pages = self::pruneDroppedSections($pages, $dropped, $warnings);
        $pages = self::repairedPages($pages, $repairs);

        $partBytes = self::partBytes($files);
        $facts = AboveFoldPartFacts::inspect($pages, $partBytes, $initialContract);
        $delivery = AboveFoldContract::finalizeDelivery($initialContract, $pages, $facts);

        // A failed/protection-less opening can invalidate an initially safe
        // overlay relation. Commit matching header bytes with the narrowed
        // delivery contract; never leave the next step to discover a contract
        // describing a different page top.
        if (($delivery['header']['mode'] ?? null) !== ($initialContract['header']['mode'] ?? null)
            || ($delivery['header']['archetype'] ?? null) !== ($initialContract['header']['archetype'] ?? null)
        ) {
            $fallback = HeaderFallback::render(
                $jobs['header']['input'],
                $delivery,
                'initial header assignment became incompatible with delivered opening markup',
            );
            $files['parts/header.html'] = $fallback->markup;
            array_push($repairs, ...$fallback->repairs);
            array_push($warnings, ...$fallback->warnings);
            $partBytes = self::partBytes($files);
            $facts = AboveFoldPartFacts::inspect($pages, $partBytes, $delivery);
            $delivery = AboveFoldContract::finalizeDelivery($delivery, $pages, $facts);
        }

        if (is_array($initialContract['primary_action'] ?? null)
            && !is_array($delivery['primary_action'] ?? null)
        ) {
            $heroPart = (string) ($initialContract['hero_part'] ?? '');
            $heroRel = 'parts/' . $heroPart . '.html';
            if ($heroPart !== '' && isset($files[$heroRel])) {
                $removedAction = GeneratedMarkup::withoutPrimaryAction(
                    $files[$heroRel],
                    $initialContract['primary_action'],
                    $heroPart,
                );
                $files[$heroRel] = $removedAction['markup'];
                array_push($repairs, ...$removedAction['repairs']);
                array_push($warnings, ...$removedAction['warnings']);
            }
        }
        $pages = self::synchronizePrimaryAction($pages, $initialContract, $delivery, $warnings);
        array_push($warnings, ...AboveFoldContract::warningRows($delivery));
        $plan['pages'] = $pages;
        foreach ($files as $rel => $markup) {
            $project->writeText('theme/' . $rel, $markup . "\n");
        }
        $project->writeJson('pages.json', $plan);
        $project->writeJson('aboveFold.json', $delivery);
        $project->addWarnings($this->id(), $warnings);
        $project->writeText('logs/sections.txt', $repairs === []
            ? "No semantics-preserving unit repairs were needed.\n"
            : implode("\n", array_map(
                static fn (mixed $repair): string => is_string($repair)
                    ? $repair
                    : (string) json_encode(
                        $repair,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                    ),
                $repairs,
            )) . "\n");
        if ($repairs !== []) {
            Narrator::write('    sections: ' . count($repairs) . " successful repair(s); details in logs/sections.txt\n");
        }
    }

    /**
     * Generate legacy section parts for only the supplied pages (HTML-first
     * mixed fallback after an inner page's design failed). Header, footer,
     * pages.json, aboveFold.json and sibling parts are left untouched. The
     * whole-site above-fold contract is resolved from $sitePages (the full
     * delivered site) so opening sections still get the correct header
     * relation, but it is not re-finalized here. A permanent batch failure
     * degrades to dropping the scoped sections. Returns the surviving page
     * plans (dropped sections pruned) in the given order.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>>|null $sitePages full delivered-site context for prompts/header seam
     * @return array<int,array<string,mixed>>
     */
    public function runForPages(Project $project, array $pages, ?array $sitePages = null): array
    {
        if ($pages === []) {
            return [];
        }
        $context = $sitePages ?? $pages;
        $wanted = array_fill_keys(
            array_map(static fn (array $p): string => (string) ($p['slug'] ?? ''), $pages),
            true,
        );
        $warnings = [];
        $repairs = [];
        $pages = self::repairedPages($pages, $repairs);
        $jobPlan = $this->jobPlan($project, $repairs, $context);
        $initialContract = $jobPlan['contract'];
        // Only the requested pages' section jobs — never chrome or siblings.
        $jobs = array_filter(
            $jobPlan['jobs'],
            static fn (array $job): bool => isset($job['input']['page']['slug'])
                && isset($wanted[(string) $job['input']['page']['slug']]),
        );
        if ($jobs === []) {
            return $pages;
        }
        $requests = self::requestsFor($jobs);
        array_push($warnings, ...$this->warmMarkupCache($requests));
        try {
            $batch = $this->llm->completeBatch($requests);
        } catch (\RuntimeException $error) {
            $reason = str_replace(["\r", "\n"], ' ', $error->getMessage());
            foreach ($pages as $page) {
                $slug = (string) ($page['slug'] ?? '');
                foreach ((array) ($page['sections'] ?? []) as $section) {
                    if (!is_array($section)) {
                        continue;
                    }
                    $sectionSlug = (string) ($section['slug'] ?? '');
                    $path = 'theme/parts/' . self::partSlug($slug, $sectionSlug) . '.html';
                    $warnings[] = "file {$path} "
                        . "block_path pages[slug={$slug}].sections[slug={$sectionSlug}] "
                        . "authored_value scoped legacy section batch unavailable: {$reason} "
                        . 'delivered_value removed disposition dropped';
                }
            }
            $project->addWarnings($this->id(), $warnings);
            return [];
        }
        $parts = $batch->texts;
        $files = [];
        $dropped = [];
        foreach ($jobs as $key => $job) {
            try {
                if (!array_key_exists($key, $parts) || !is_string($parts[$key])) {
                    throw new \RuntimeException('the batch returned no result');
                }
                $result = $job['unit']->finish($parts[$key], $job['input']);
                if (($job['opening'] ?? false) === true) {
                    self::assertOpeningRoot($result->markup, $key);
                }
                $files[$job['file']] = $result->markup;
                array_push($repairs, ...$result->repairs);
                array_push(
                    $warnings,
                    ...$result->warnings,
                    ...self::batchWarnings($job['file'], $batch->notesFor($key)),
                );
            } catch (\RuntimeException $e) {
                $authoredFailure = Warnings::value($e->getMessage());
                $warningContext = "file='theme/{$job['file']}'; block='part root'; authored={$authoredFailure}; ";
                if (($job['opening'] ?? false) === true) {
                    $fallback = PageOpeningFallback::render($job['input'], $initialContract, $e->getMessage());
                    $files[$job['file']] = $fallback->markup;
                    array_push($repairs, ...$fallback->repairs);
                    array_push($warnings, ...$fallback->warnings);
                } else {
                    $dropped[$key] = true;
                    $warnings[] = "part '{$key}': unusable generated markup; {$warningContext}"
                        . 'delivered=removed; disposition=only the unusable section part was removed and pruned '
                        . 'from pages.json';
                }
                Narrator::write("    (part '{$key}': unusable generated markup — {$e->getMessage()})\n");
            }
        }
        $pages = self::pruneDroppedSections($pages, $dropped, $warnings);
        $pages = self::repairedPages($pages, $repairs);
        foreach ($files as $rel => $markup) {
            $project->writeText('theme/' . $rel, $markup . "\n");
        }
        $project->addWarnings($this->id(), $warnings);
        return $pages;
    }

    /**
     * Resolve the delivery-phase above-fold contract for an already-delivered
     * page set + theme/parts. The HTML-first path generates sections through
     * the transformer instead of this step's run(), so it has no in-flight
     * contract; this rebuilds the same delivery-phase contract from the
     * delivered pages and part bytes so HeaderHeroStep consumes an identical
     * artifact in both paths. Legacy run() writes the phase inline.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,mixed>
     */
    public static function deliveryContract(Project $project, array $pages): array
    {
        $siteSpecData = $project->readJson('siteSpec.json');
        $blueprint = DesignDirectionStep::heroBlueprintFor($project);
        $footerArchetype = FooterComposition::archetypeForProject($project);
        $footerSurface = FooterComposition::resolveSurface($footerArchetype, self::closingBackgrounds($pages));
        $contract = AboveFoldContract::resolve(
            pages: $pages,
            blueprint: $blueprint,
            canvas: DesignDirectionStep::canvasFor($project),
            themeContext: $project->readJson('theme/theme.json'),
            siteContext: [
                'stable_id' => (string) ($siteSpecData['slug'] ?? $project->slug()),
                'writing_direction' => (string) ($siteSpecData['writing_direction'] ?? 'ltr'),
                'page_count' => count($pages),
            ],
            footerContext: [
                'archetype' => $footerArchetype,
                'surface' => $footerSurface,
            ],
            forcedHeaderArchetype: Env::get(AboveFoldContract::HEADER_ARCHETYPE_ENV),
            designCss: self::designCss($project),
        );
        $partBytes = [];
        foreach (glob($project->themePath('parts/*.html')) ?: [] as $abs) {
            $partBytes[substr(basename($abs), 0, -strlen('.html'))] = (string) file_get_contents($abs);
        }
        $facts = AboveFoldPartFacts::inspect($pages, $partBytes, $contract);
        return AboveFoldContract::finalizeDelivery($contract, $pages, $facts);
    }

    private static function assertOpeningRoot(string $markup, string $part): void
    {
        $document = BlockMarkup::parse($markup);
        $roots = array_values(array_filter(
            $document->indices(),
            static fn (int $index): bool => $document->parent($index) === null,
        ));
        if (count($roots) !== 1
            || $document->name($roots[0]) !== 'group'
            || $document->endOffset($roots[0]) === null
        ) {
            throw new \RuntimeException(
                "contract-critical opening '{$part}' must deliver one complete top-level wp:group"
            );
        }
    }

    /**
     * A deterministic minimal chrome part delivered when the generated
     * header/footer markup is unusable: a constrained group carrying the site
     * title, so templates referencing the part render something coherent.
     */
    public static function fallbackChrome(
        string $key,
        ?string $footerSurface = null,
        ?int $footerPageCount = null
    ): string
    {
        if (!in_array($key, ['header', 'footer'], true)) {
            throw new \InvalidArgumentException("unknown chrome part '{$key}'");
        }

        $attrs = [
            'layout' => ['type' => 'constrained'],
            'style' => [
                'spacing' => [
                    'padding' => [
                        'top' => 'var:preset|spacing|md',
                        'bottom' => 'var:preset|spacing|md',
                    ],
                ],
            ],
        ];
        $classes = ['wp-block-group'];
        $siteTitleAttrs = [];
        if ($key === 'footer') {
            $surface = $footerSurface ?? 'base';
            if (!in_array($surface, ['base', 'contrast'], true)) {
                throw new \InvalidArgumentException("unknown footer surface '{$surface}'");
            }
            $pageCount = $footerPageCount ?? 1;
            if ($pageCount < 1) {
                throw new \InvalidArgumentException('footer page count must be at least 1');
            }
            $foreground = $surface === 'contrast' ? 'base' : 'contrast';
            $attrs['backgroundColor'] = $surface;
            $attrs['textColor'] = $foreground;
            $classes[] = "has-{$surface}-background-color";
            $classes[] = 'has-background';
            $classes[] = "has-{$foreground}-color";
            $classes[] = 'has-text-color';
            $siteTitleAttrs['textColor'] = $foreground;
            if ($pageCount === 1) {
                $siteTitleAttrs['isLink'] = false;
            }
        }
        $json = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException("could not encode deterministic {$key} fallback");
        }

        $siteTitleJson = $siteTitleAttrs === []
            ? ''
            : ' ' . json_encode($siteTitleAttrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '<!-- wp:group ' . $json . ' -->' . "\n"
            . '<div class="' . implode(' ', $classes) . '" style="padding-top:var(--wp--preset--spacing--md);'
            . 'padding-bottom:var(--wp--preset--spacing--md)"><!-- wp:site-title'
            . $siteTitleJson . ' /--></div>' . "\n"
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

    /** @param array<string,string> $files @return array<string,string> */
    private static function partBytes(array $files): array
    {
        $parts = [];
        foreach ($files as $rel => $markup) {
            if (!str_starts_with($rel, 'parts/') || !str_ends_with($rel, '.html')) {
                continue;
            }
            $parts[substr($rel, strlen('parts/'), -strlen('.html'))] = $markup;
        }
        return $parts;
    }

    /**
     * Keep pages.json honest when a generated/fallback hero did not deliver
     * its validated primary action. Only the front opening is touched.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param list<string> $warnings
     * @return array<int,array<string,mixed>>
     */
    private static function synchronizePrimaryAction(
        array $pages,
        array $initial,
        array $delivery,
        array &$warnings,
    ): array {
        if (!is_array($initial['primary_action'] ?? null) || is_array($delivery['primary_action'] ?? null)) {
            return $pages;
        }
        foreach ($pages as $pageIndex => $page) {
            if (($page['front'] ?? false) !== true || !is_array($page['sections'][0] ?? null)) {
                continue;
            }
            $authored = $pages[$pageIndex]['sections'][0]['primary_action'] ?? null;
            $pages[$pageIndex]['sections'][0]['primary_action'] = null;
            $warnings[] = "file='pages.json'; path=\"pages[{$pageIndex}].sections[0].primary_action\"; authored="
                . (string) json_encode($authored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . '; delivered=null; disposition=the delivered hero did not preserve the authoritative visitor-facing '
                . 'action, so plan and contract presence were atomically removed instead of claiming dead UI';
            break;
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

    /** @param list<string> $notes @return list<string> */
    private static function batchWarnings(string $file, array $notes): array
    {
        return array_map(static function (string $note) use ($file): string {
            if (str_contains($note, "file='")) {
                return $note;
            }
            return "file='theme/{$file}'; block='generated response'; authored="
                . (string) json_encode($note, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . '; delivered=\"best normalized partial response\"; disposition=abnormally terminated '
                . 'generation was retained only after bounded regeneration and per-unit normalization';
        }, $notes);
    }

    /**
     * Warm the cached context the batch is about to reuse, and use that same
     * probe to verify the batch path actually SENT it. A failed probe only
     * forfeits first-window cache hits; it must not abort the build or change
     * the subsequent concurrent fan-out.
     *
     * The probe uses the most deeply layered request in the batch — a section
     * whenever the batch has one. Its leading layer is byte-identical to the
     * one the header, footer and hero open with, so priming it covers every
     * markup call rather than only the sections. Warming a chrome request
     * instead would prime that shared layer alone and leave the section
     * build/page layers cold — the batch fans out concurrently, so nothing
     * else can prime them in time. A hero-only front page has no section, so
     * there the probe is a chrome request and the shared layer is all there is
     * to prime.
     *
     * The sections themselves are generated through completeBatch(), so the
     * one-member probe deliberately travels that same seam. Hosts may implement
     * complete() and completeBatch() separately; measuring complete() would
     * permit either path to lie about the one that actually authors sections.
     *
     * @param array<string,array{prompt:string,model?:string,temperature?:float,cached_prefixes?:list<string>}> $requests
     * @return list<string> context-loss and shared-layer-divergence warnings, empty when the host is conformant and the batch agrees, or when the probe failed
     */
    private function warmMarkupCache(array $requests): array
    {
        $request = self::deepestLayeredRequest($requests);
        if ($request === null) {
            return [];
        }

        $diverged = self::requestsOutsideSharedLayer($requests, $request['cached_prefixes'][0] ?? '');

        $opts = $request;
        unset($opts['prompt']);
        $opts['max_tokens'] = 1;
        $opts['tolerate_empty'] = true;
        $opts['log_label'] = 'markup-cache-warm';

        $before = $this->usageSnapshot();

        try {
            $this->llm->completeBatch([
                'markup-cache-warm' => ['prompt' => self::CACHE_WARM_PROMPT] + $opts,
            ]);
        } catch (\Throwable $e) {
            Narrator::write("    markup cache warm-up failed ({$e->getMessage()}); continuing uncached\n");
            return [];
        }

        foreach ($diverged as $warning) {
            Narrator::write("    WARNING: {$warning}\n");
        }

        $after = $this->usageSnapshot();
        if ($before === null || $after === null) {
            return $diverged;
        }
        $observed = self::billedInputDelta($before, $after);
        $warning = self::contextLossWarning($request['cached_prefixes'], $observed);
        if ($warning !== null) {
            Narrator::write("    WARNING: {$warning}\n");
            $diverged[] = $warning;
        }
        return $diverged;
    }

    /**
     * Warn for every request whose leading layer is not the one the probe primed.
     *
     * Nothing notices otherwise: the request still generates correct markup,
     * pays full price, and writes a cache entry no one reads. The four units
     * here share one $common and agree by construction, but a host that mirrors
     * jobPlan() by hand passes the same spec as an array in one place and as
     * text in another, and the two render layers that differ by bytes.
     *
     * Pure, like contextLossWarning(), so it is testable without a transport.
     *
     * @param array<string,array{prompt:string,cached_prefixes?:list<string>}> $requests
     * @return list<string> one warning per diverging request, empty when they agree
     */
    public static function requestsOutsideSharedLayer(array $requests, string $primed): array
    {
        if ($primed === '') {
            return [];
        }

        $warnings = [];
        foreach ($requests as $key => $request) {
            $layer = $request['cached_prefixes'][0] ?? null;
            if ($layer === null || $layer === $primed) {
                continue;
            }
            $warnings[] = sprintf(
                'file \'theme/parts/*.html\'; block=\'%s cache layers\'; authored="a site layer of %d bytes"; '
                . 'delivered="the warm-up primed a different %d-byte layer"; disposition=this request cannot read '
                . 'the primed cache and pays full price. The inputs behind it disagree with the rest of the batch, '
                . 'usually because the same site spec or theme.json reached one unit as text and another as an array',
                $key,
                strlen($layer),
                strlen($primed),
            );
        }
        return $warnings;
    }

    /**
     * The request carrying the most cached prefix bytes, so one probe primes
     * the largest reusable context in the batch. Every markup unit's layers
     * open with the same site layer, so priming any request covers that shared
     * layer for all of them; picking the deepest one also primes the section
     * build and page layers sitting behind it. It is not a superset of every
     * request — sections on other pages carry their own page layer, which this
     * probe leaves cold.
     *
     * Returns the request whole: `model` is part of the cache key, so a probe
     * that lost it would write an entry the batch cannot read.
     *
     * @param array<string,array{prompt:string,model?:string,temperature?:float,cached_prefixes?:list<string>}> $requests
     * @return array{prompt:string,model?:string,temperature?:float,cached_prefixes:list<string>}|null null when no request carries layers
     */
    private static function deepestLayeredRequest(array $requests): ?array
    {
        $deepest = null;
        $deepestBytes = 0;
        foreach ($requests as $request) {
            if (!isset($request['cached_prefixes'])) {
                continue;
            }
            $bytes = array_sum(array_map('strlen', $request['cached_prefixes']));
            if ($deepest === null || $bytes > $deepestBytes) {
                $deepest = $request;
                $deepestBytes = $bytes;
            }
        }
        return $deepest;
    }

    /** @return array<string,mixed>|null null when usage measurement is unavailable */
    private function usageSnapshot(): ?array
    {
        if (!$this->llm instanceof UsageReporting) {
            return null;
        }
        try {
            return $this->llm->usageTotals();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Input tokens a host billed for a single call, from cumulative usage
     * snapshots taken either side of it.
     *
     * The reading itself lives on BilledInput, which documents why the raw
     * `input_tokens` field cannot be trusted on its own and which
     * LlmConformance's usage probe shares — the CI gate and this runtime guard
     * must answer the same question the same way.
     *
     * The totals are cumulative and per-client, so this reads as one call's
     * usage only while nothing else is spending on the same Llm. That holds
     * here — SectionsStep is a standalone step in both compositions, never a
     * ConcurrentGroup member, and the probe is sequential. A host that shared
     * one client across parallel work would inflate the delta, which errs
     * toward silence rather than toward a false accusation.
     *
     * @param array<string,mixed> $before cumulative totals before the call
     * @param array<string,mixed> $after  cumulative totals after it
     */
    public static function billedInputDelta(array $before, array $after): int
    {
        return BilledInput::delta($before, $after);
    }

    /**
     * Decide whether a host discarded the cached context layers, from the
     * input-token usage its own accounting reported for the warm-up probe.
     *
     * This exists because the Llm seam has no other way to tell. The layers
     * carry the site spec, theme JSON, design direction and page outline;
     * `prompt` carries only the per-section brief. A host that accepts
     * `cached_prefixes` and drops them still returns perfectly well-formed
     * markup, so the build cannot notice from the response — it can only
     * notice that far too few input tokens were billed. That is exactly how
     * the defect was found in production, after 19 of 21 sections had already
     * been generated against no theme at all.
     *
     * Inconclusive cases return null rather than guessing. A host with small
     * layers, or one whose tokenizer is unusually dense, must never be accused
     * on thin evidence — this only fires when the gap is far too large to be
     * anything else.
     *
     * Pure, so the threshold is unit-testable without a transport.
     *
     * @param list<string> $cachedPrefixes
     * @param int          $observedInputTokens total billed input for the probe,
     *                     cache reads and creations included — take it from
     *                     billedInputDelta() rather than from a raw
     *                     `input_tokens` field, whose meaning varies by host
     */
    public static function contextLossWarning(array $cachedPrefixes, int $observedInputTokens): ?string
    {
        $expected = BilledInput::estimateTokens($cachedPrefixes);
        if ($expected < self::CONTEXT_PROBE_MIN_TOKENS) {
            return null; // Layers too small to distinguish signal from overhead.
        }
        if (!BilledInput::looksDiscarded($expected, $observedInputTokens)) {
            return null; // The host sent them.
        }

        return sprintf(
            'file \'theme/parts/*.html\'; block=\'markup cache layers\'; authored="%d cached_prefixes tokens '
            . '(site spec, theme.json, design direction, and the page outline when the probe is a section)"; '
            . 'delivered="%d input tokens billed by the host"; disposition=the injected Llm appears to discard '
            . 'cached_prefixes, so the markup below was authored without the theme or the design direction. '
            . 'Fix the host adapter so completeBatch() forwards cached_prefixes and reports their billed input usage',
            $expected,
            $observedInputTokens,
        );
    }

    /**
     * Deterministically repair plan drift in every page's section list: a
     * section role is a pure function of its position, and a missing semantic
     * type has a safe generic default. Each semantics-preserving correction is
     * reported through $repairs, never promoted into warnings.json.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param list<string> $repairs appended to in place
     * @return array<int,array<string,mixed>>
     */
    public static function repairedPages(array $pages, array &$repairs = []): array
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
                    $repairs[] = "page '{$page['slug']}' section '{$slug}': "
                        . "role '{$role}' corrected to '{$expectedRole}' (derived from its position in the plan)";
                    $sections[$i]['role'] = $expectedRole;
                }
                $type = trim((string) ($section['type'] ?? ''));
                if ($type === '') {
                    $slug = (string) ($section['slug'] ?? "section-{$i}");
                    $repairs[] = "page '{$page['slug']}' section '{$slug}': "
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
     * @param list<string> $repairs appended to in place
     * @param array<int,array<string,mixed>>|null $sourcePages
     * @param list<string> $warnings appended to in place
     * @return array{
     *   jobs:array<string,array{unit:MarkupUnit,input:array<mixed>,file:string,opening?:bool,front_hero?:bool}>,
     *   contract:array<string,mixed>
     * }
     */
    private function jobPlan(
        Project $project,
        array &$repairs = [],
        ?array $sourcePages = null,
        array &$warnings = [],
    ): array
    {
        $pages = self::repairedPages($sourcePages ?? self::pages($project), $repairs);
        $siteSpec = $project->readText('siteSpec.json');
        $siteSpecData = $project->readJson('siteSpec.json');
        $designDirection = DesignDirectionStep::readFor($project);
        $cardStyle = DesignDirectionStep::cardStyleFor($project, $warnings);
        $blueprint = DesignDirectionStep::heroBlueprintFor($project);

        // One read serves both consumers: the raw text goes verbatim into the
        // prompts and the decoded palette drives the header-behavior preview.
        $themeJsonText = $project->readText('theme/theme.json');
        $themeJson = json_decode($themeJsonText, true);
        if (!is_array($themeJson)) {
            throw new \RuntimeException('sections: theme/theme.json is not valid JSON (run theme-json first)');
        }

        $common = [
            'site_spec'         => $siteSpec,
            'language'          => SiteSpecStep::languageOf($project),
            'theme_json'        => $themeJsonText,
            'design_direction'  => $designDirection,
            // Unlike design_direction's prose, this value is a portable,
            // machine-readable execution contract consumed by SectionUnit's
            // delivery boundary. Old/missing directions retain the documented
            // flush default without making section generation fatal.
            'card_style'        => $cardStyle,
            'site_pages'        => PagePlanStep::sitePagesList($pages),
            // A host capability, not a site fact: it says whether a real form
            // backend exists to replace the placeholders, so it stays in the
            // caller-owned meta rather than in the spec the model authors.
            'form_placeholders' => self::formPlaceholders($project),
        ];

        // Select the footer first: a singleton hero's lower edge must name the
        // actual coda rather than pretending a detail section exists. The one
        // AboveFoldContract then owns the complete top relation; no unit
        // derives mode or header archetype independently.
        $frontSections = (array) (self::frontPage($pages)['sections'] ?? []);
        $footerArchetype = FooterComposition::archetypeForProject($project);
        $footerSurface = FooterComposition::resolveSurface($footerArchetype, self::closingBackgrounds($pages));
        $contract = AboveFoldContract::resolve(
            pages: $pages,
            blueprint: $blueprint,
            canvas: DesignDirectionStep::canvasFor($project),
            themeContext: $project->readJson('theme/theme.json'),
            siteContext: [
                'stable_id' => (string) ($siteSpecData['slug'] ?? $project->slug()),
                'writing_direction' => (string) ($siteSpecData['writing_direction'] ?? 'ltr'),
                'page_count' => count($pages),
                // The one text wp:site-tagline will render at runtime — the
                // contract exposes it so neither above-fold author discovers
                // it by surprise on the live site (BIGR-773).
                'tagline' => PlaygroundArtifact::blogDescription($siteSpecData),
            ],
            footerContext: [
                'archetype' => $footerArchetype,
                'surface' => $footerSurface,
            ],
            forcedHeaderArchetype: Env::get(AboveFoldContract::HEADER_ARCHETYPE_ENV),
            designCss: self::designCss($project),
        );
        $frontContract = AboveFoldContract::frontContract($contract);
        // Preview the runtime header behavior for the contract's relation so
        // the header author designs for its actual shell states (BIGR-762).
        // HeaderHeroStep re-resolves against the delivered markup later; this
        // brief is advisory, never a competing decision.
        $headerBehavior = HeaderBehavior::resolve(
            $pages,
            (string) $contract['header']['mode'],
            ContrastFixStep::paletteMap($project->readJson('theme/theme.json')),
            (string) $contract['header']['archetype'] ?: null,
            HeaderBehavior::transitionFor(DesignDirectionStep::motionProfileFor($project)),
        )['behavior'];
        $jobs = [
            'header' => [
                'unit'  => $this->headerUnit,
                'input' => $common + [
                    'outline'    => self::outline($frontSections),
                    'hero_brief' => self::heroBrief($frontSections),
                    'nav_rule'   => self::navRuleFor(count($pages)),
                    'above_fold_contract' => $contract,
                    'header_behavior' => HeaderBehavior::promptContract($headerBehavior),
                ],
                'file'  => 'parts/header.html',
            ],
            'footer' => [
                'unit'  => $this->footerUnit,
                'input' => $common + [
                    'outline' => self::outline($frontSections),
                    'final_section_brief' => self::finalSectionBrief($frontSections),
                    'composition_archetype' => $footerArchetype,
                    'surface' => $footerSurface,
                    'page_count' => count($pages),
                ],
                'file'  => 'parts/footer.html',
            ],
        ];

        foreach ($pages as $page) {
            $sections = $page['sections'];
            // A compact outline of THIS page, so each section knows its place.
            $outline = self::outline($sections);
            foreach ($sections as $i => $section) {
                $frontHero = self::isFrontHero($page, $i);
                $opening = $i === 0;
                $input = $common + [
                    'outline'   => $outline,
                    'page'      => [
                        'slug'  => (string) ($page['slug'] ?? ''),
                        'title' => (string) ($page['title'] ?? ''),
                        'path'  => (string) ($page['path'] ?? '/'),
                        'front' => (bool) ($page['front'] ?? false),
                    ],
                    'section'   => $section,
                    'neighbors' => self::neighbors($sections, $i, $footerArchetype, $footerSurface),
                    'header_contract' => $opening
                        ? ($frontHero
                            ? $frontContract
                            : AboveFoldContract::openingHeaderContract(
                                $contract,
                                (string) ($page['slug'] ?? ''),
                            ))
                        : '',
                ];
                $unit = $frontHero ? $this->heroUnit : $this->sectionUnit;
                if ($frontHero) {
                    $input['hero_blueprint'] = $blueprint;
                    $input['above_fold_contract'] = $contract;
                }
                $key = $unit->key($input);
                $jobs[$key] = [
                    'unit'  => $unit,
                    'input' => $input,
                    'file'  => 'parts/' . $key . '.html',
                    'opening' => $opening,
                    'front_hero' => $frontHero,
                ];
            }
        }

        return ['jobs' => $jobs, 'contract' => $contract];
    }

    /**
     * Whether this build's host owns a real form backend.
     *
     * Set by the caller at createProject time (CLI: --use-jetpack-placeholders).
     * True picks prompts/jetpack-form.md for every section, false picks
     * prompts/no-forms.md; those two files carry the reasoning.
     */
    public static function formPlaceholders(Project $project): bool
    {
        // The graph always seeds meta.json, but this step is also driven
        // directly — runForPages() from the transform path, and the test
        // fixtures — against projects that never went through
        // createProject. There a missing meta is the default, not an error.
        if (!$project->exists('meta.json')) {
            return false;
        }

        return (bool) ($project->readJson('meta.json')['form_placeholders'] ?? false);
    }

    /** The portable routing rule: position and front flag, never mutable role prose. */
    public static function isFrontHero(array $page, int $sectionIndex): bool
    {
        return ($page['front'] ?? false) === true && $sectionIndex === 0;
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
    public static function neighbors(
        array $sections,
        int $i,
        string $footerArchetype = '',
        string $footerSurface = '',
    ): string {
        $describe = function (?array $s): ?string {
            if (!is_array($s)) {
                return null;
            }
            $title = (string) ($s['title'] ?? '');
            $plan = self::assignment($s);
            return "\"{$title}\"" . ($plan !== '' ? " — {$plan}" : '');
        };

        $above = $describe($sections[$i - 1] ?? null) ?? 'the site header (this is the first section)';
        $below = $describe($sections[$i + 1] ?? null);
        if ($below === null) {
            $own = is_array($sections[$i] ?? null) ? (string) ($sections[$i]['background'] ?? '') : '';
            $below = self::footerNeighborContract($footerArchetype, $footerSurface, $own);
        }
        return "Above: {$above}\nBelow: {$below}";
    }

    /**
     * The footer-side contract injected as every page's final section neighbor.
     * The same archetype and resolved surface are sent to FooterUnit, so the two
     * independently generated parts agree about content ownership and the visual
     * handoff. Passing '' for the archetype preserves the compact legacy
     * description for direct callers whose adapter has not assigned a footer
     * composition; '' for the surface falls back to the archetype's preference.
     * Pure — unit-testable.
     */
    public static function footerNeighborContract(
        string $archetype,
        string $surface = '',
        string $sectionBackground = '',
    ): string {
        if ($archetype === '') {
            return 'the site footer (this is the last section)';
        }
        FooterComposition::assertKnown($archetype);
        $surface = $surface !== '' ? $surface : FooterComposition::surface($archetype);
        // resolveSurface() picks the fewest collisions, not zero, and a host
        // adapter calling this directly never runs the plan-level move. So the
        // section really can be sitting on the footer's surface, and telling
        // its author it was planned off one would brief a cut that has nothing
        // to cut against.
        $seam = $sectionBackground !== '' && $sectionBackground === $surface
            ? 'This section shares that exact surface, so hand off continuously through spacing and rhythm rather '
                . 'than a colour cut, and never restate the footer band.'
            : 'This section was planned NOT to use that surface, so make one decisive color or image cut at the '
                . 'boundary and never restate the footer band.';
        return "the site footer (this is the last section) — assigned {$archetype} composition opening on the "
            . "exact **{$surface}** background surface. "
            . 'This section owns its planned narrative, facts, imagery, and primary CTA; the footer owns persistent '
            . "identity, compact site-wide utility, and credit. {$seam} Do not repeat "
            . 'copy, contact/hours clusters, CTA, or a second signature ornament.';
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
     * A plain-text brief of the front page's positional opening. The same
     * `page.front && index === 0` rule routes HeroUnit, so header context cannot
     * drift back to mutable semantic-role selection. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function heroBrief(array $sections): string
    {
        $hero = $sections[0] ?? null;
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
     * A plain-text brief of the FRONT page's actual final planned section, so
     * the footer can avoid repeating its content and can design the shared
     * seam against its assigned surface/composition. Unlike heroBrief(), this
     * is positional: the section immediately before the template footer is the
     * relevant neighbor even if a stale plan supplied the wrong role.
     * Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function finalSectionBrief(array $sections): string
    {
        $final = null;
        for ($i = count($sections) - 1; $i >= 0; $i--) {
            if (is_array($sections[$i])) {
                $final = $sections[$i];
                break;
            }
        }
        if (!is_array($final)) {
            return '(No final section planned.)';
        }

        $lines = [];
        foreach (
            [
                'title' => 'Title',
                'role' => 'Role',
                'type' => 'Type',
                'purpose' => 'Purpose',
                'content_notes' => 'Notes',
                'layout_archetype' => 'Layout archetype',
                'background' => 'Background',
                'vertical_density' => 'Vertical density',
                'handoff' => 'Planned handoff',
            ] as $key => $label
        ) {
            $value = trim((string) ($final[$key] ?? ''));
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }
        $action = $final['primary_action'] ?? null;
        if (is_array($action)
            && is_string($action['label'] ?? null)
            && is_string($action['destination'] ?? null)
            && trim($action['label']) !== ''
            && trim($action['destination']) !== ''
        ) {
            $lines[] = 'Primary action label (authoritative): ' . trim($action['label']);
            $lines[] = 'Primary action destination: ' . trim($action['destination']);
            $intent = trim((string) ($action['intent'] ?? ''));
            if ($intent !== '') {
                $lines[] = 'Primary action intent (planning context, never button copy): ' . $intent;
            }
        }
        return $lines === [] ? '(No final section planned.)' : implode("\n", $lines);
    }

    /**
     * Select one footer composition deterministically from stable build
     * context. The hash is folded modulo the catalog size byte by byte, so it
     * is portable across integer widths and never depends on process-local
     * randomness. Site identity/direction spread different builds across the
     * catalog; the front outline makes a materially changed plan eligible for
     * a different coda. Pure — unit-testable.
     *
    /**
     * The design stylesheet, or null on the legacy path which never writes
     * one. Its `header` rule is the only authored evidence of the stacked
     * header's surface, so the above-fold contract reads it directly.
     */
    private static function designCss(Project $project): ?string
    {
        return $project->exists(TransformArtifacts::SITE_CSS)
            ? $project->readText(TransformArtifacts::SITE_CSS)
            : null;
    }

    /**
     * The footer composition for this build. Seeded on the site alone so
     * page-plan can learn the footer's surface before it plans the closing
     * sections that have to differ from it.
     */
    public static function footerArchetype(string $siteSpec, string $designDirection): string
    {
        return FooterComposition::archetypeFor($siteSpec, $designDirection);
    }

    /**
     * What each page's last section closes on, in page order. One footer part
     * renders below all of them, so the surface has to clear the whole list —
     * not just the front page's.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return list<string>
     */
    public static function closingBackgrounds(array $pages): array
    {
        $backgrounds = [];
        foreach ($pages as $page) {
            $sections = array_values(array_filter(
                (array) ($page['sections'] ?? []),
                'is_array'
            ));
            $last = end($sections);
            if (is_array($last) && is_string($last['background'] ?? null)) {
                $backgrounds[] = $last['background'];
            }
        }
        return $backgrounds;
    }

    /** The exact, single-archetype directive rendered into footer.md. */
    public static function footerAssignment(string $archetype): string
    {
        return FooterComposition::assignment($archetype);
    }

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
}
