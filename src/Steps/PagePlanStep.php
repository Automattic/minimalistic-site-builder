<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\FooterSectionIdentity;
use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\GeneratedJsonFallbackStep;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\PhotographySite;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionRole;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (LLM, concurrent): plan every page of the site as an ordered list of
 * sections — one independent request per page in the spec's page tree, all
 * fired in the same batch (and merged with theme-json's request by the
 * ConcurrentGroup).
 *
 * Input:  meta.json (user prompt) + siteSpec.json (with its `pages` tree).
 * Output: pages.json — { "pages": [ { slug, title, path, front, parent,
 *         menu_order, purpose, sections: [ { slug, title, role, type, purpose,
 *         content_notes, layout_archetype, background, vertical_density,
 *         handoff, primary_action } ] } ] }, a FLAT list in display order, parents before
 *         children; warnings.json records every generated value removed or
 *         replaced by a deterministic fallback.
 *
 * Each page's plan enriches the spec's purpose into concrete section briefs,
 * which the sections step then generates independently and in parallel and
 * the assemble step composes per page. Because the sections are built blind
 * to each other, this step is also each page's art director: it assigns every
 * section a layout archetype and background treatment (validated below, with
 * an adjacency rule) so every page has a deliberate visual rhythm.
 */
final class PagePlanStep implements GeneratedJsonFallbackStep
{
    private const REPORT_FILE = 'page-plan.txt';

    use LlmOptions;

    /** Composition menu — must match the archetypes offered in page-plan.md. */
    public const ARCHETYPES = [
        'full-bleed-cover',
        'asymmetric-split',
        'centered-stack',
        'offset-grid',
        'mixed-width-editorial',
        'equal-card-grid',
        'list-with-thumbnails',
    ];

    /** Background treatments — must match page-plan.md. */
    public const BACKGROUNDS = ['base', 'tinted', 'contrast', 'image'];

    /** Page-owned outer spacing roles — must match page-plan.md. */
    public const VERTICAL_DENSITIES = ['compact', 'standard', 'spacious'];

    /** The most default-looking archetype is capped so it can't dominate a page. */
    private const MAX_EQUAL_CARD_GRIDS = 2;

    /** Whitespace-led pauses are accents, not a page's default cadence. */
    private const MAX_SPACIOUS_SECTIONS = 2;

    /**
     * Content-dense section roles must not compound their height with the
     * largest edge. "type" is free-form model output, so these are matched as
     * lowercase word tokens ("Gallery" and "image-gallery" both count), not
     * as exact strings.
     */
    private const DENSE_SECTION_TYPES = ['features', 'services', 'gallery', 'pricing', 'team', 'faq'];

    /** Per-page creative emphasis injected as {{page_emphasis}}. */
    private const FRONT_EMPHASIS = "This page is the site's front page and centerpiece — give it the most creative"
        . ' energy: a strong hero, at least 3 unique, image-rich content sections, and a compelling closing CTA.'
        . " Use the spec's \"sections\" list as a starting point, but improve it: add, reorder, split, or rename"
        . " sections so the page is richer and flows well. Let the design direction's mood"
        . " inform which sections you choose and how they're framed. Aim for 5 to 8 sections.";

    private const INTERIOR_EMPHASIS = 'This is one interior page of a multi-page site. Aim for 3 to 6 sections.'
        . ' Open with a COMPACT page hero that orients the visitor on this page (not a second homepage hero —'
        . ' never "full-bleed-cover" as the FIRST section; an image-led opening uses background "image" on a'
        . ' compact archetype instead),'
        . " cover only THIS page's purpose (don't rebuild content that lives on other pages — see SITE PAGES),"
        . " and close with a next step that points onward. The homepage already teases the site's topics (the"
        . " spec's \"sections\" list): where this page's subject matter overlaps one of those teasers, plan the"
        . ' DEEPER destination the teaser links to — different framing, more specific copy points, and imagery'
        . ' subjects the homepage band would not have used — never a re-plan of the teaser itself. Let the'
        . " design direction's mood inform the section choices here too, and remember the"
        . " site header renders above — sometimes floating over — this page's FIRST section: open with a"
        . ' background the site chrome can sit on.';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'page-plan';
    }

    public function label(): string
    {
        return "Plan every page's sections";
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json', 'siteSpec.json', 'designDirection.json'],
            writes: ['pages.json', 'warnings.json'],
            concurrent: true,
        );
    }

    /**
     * Provider-neutral output contract for one page plan. Cross-section rules
     * (non-empty plan, adjacency and grid caps) remain in normalize(), where
     * they can report useful page-specific validation errors.
     *
     * @return array<string,mixed>
     */
    public static function jsonSchema(): array
    {
        $fields = [
            'slug'             => ['type' => 'string'],
            'title'            => ['type' => 'string'],
            'type'             => ['type' => 'string'],
            'purpose'          => ['type' => 'string'],
            'content_notes'    => ['type' => 'string'],
            'layout_archetype' => ['type' => 'string', 'enum' => self::ARCHETYPES],
            'background'       => ['type' => 'string', 'enum' => self::BACKGROUNDS],
            'vertical_density' => ['type' => 'string', 'enum' => self::VERTICAL_DENSITIES],
            'handoff'          => ['type' => 'string'],
            'primary_action'   => [
                'anyOf' => [
                    ['type' => 'null'],
                    [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['label', 'intent', 'destination'],
                        'properties'           => [
                            'label'       => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                            'intent'      => ['type' => 'string', 'minLength' => 1],
                            'destination' => ['type' => 'string', 'minLength' => 1],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'type'       => 'object',
            'properties' => [
                'sections' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'properties'           => $fields,
                        'required'             => array_keys($fields),
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['sections'],
            'additionalProperties' => false,
        ];
    }

    public function requests(Project $project): array
    {
        return $this->requestsForPages(
            $project,
            self::flattenPages($project->readJson('siteSpec.json')),
        );
    }

    /**
     * Render legacy page-plan requests for only the named pages. Used by the
     * HTML-first mixed fallback after an inner-page design writes a .failed
     * marker. Request keys remain page slugs, matching the ordinary batch.
     *
     * @param list<string> $slugs
     * @return array<string,array<string,mixed>>
     */
    public function requestsForSlugs(Project $project, array $slugs): array
    {
        $wanted = array_fill_keys($slugs, true);
        $sitePages = self::flattenPages($project->readJson('siteSpec.json'));
        $pages = array_values(array_filter(
            $sitePages,
            static fn (array $page): bool => isset($wanted[(string) $page['slug']]),
        ));
        return $this->requestsForPages($project, $pages, $sitePages);
    }

    /**
     * Run the legacy planner for only the named pages and return their planned
     * entries without replacing pages.json. Generated-output failures degrade
     * to the deterministic one-section plan; missing build inputs still fail
     * while requests are rendered before the guarded LLM call.
     *
     * @param list<string> $slugs
     * @return array<int,array<string,mixed>>
     */
    public function runForSlugs(Project $project, array $slugs): array
    {
        $wanted = array_fill_keys($slugs, true);
        $sitePages = self::flattenPages($project->readJson('siteSpec.json'));
        $pages = array_values(array_filter(
            $sitePages,
            static fn (array $page): bool => isset($wanted[(string) $page['slug']]),
        ));
        if ($pages === []) {
            return [];
        }

        $requests = $this->requestsForPages($project, $pages, $sitePages);
        try {
            $results = $this->llm->completeJsonBatch($requests);
        } catch (\RuntimeException $error) {
            $warnings = [];
            foreach ($pages as &$page) {
                $slug = (string) $page['slug'];
                $page['sections'] = self::fallbackSections((bool) $page['front']);
                $reason = trim((string) preg_replace('/\s+/', ' ', $error->getMessage()));
                $warnings[] = "file pages.json block_path pages[slug={$slug}].sections "
                    . "authored_value scoped legacy plan unavailable: {$reason} "
                    . 'delivered_value deterministic fallback section disposition degraded';
            }
            unset($page);
            $project->addWarnings($this->id(), $warnings);
            return $pages;
        }
        return $this->plannedPages($project, $pages, $results);
    }

    /**
     * Render the page-plan requests for an explicit page list, resolving the
     * shared SITE PAGES context from $sitePages (the full delivered site) so a
     * scoped subset request still sees every sibling page.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>>|null $sitePages
     * @return array<string,array<string,mixed>>
     */
    private function requestsForPages(Project $project, array $pages, ?array $sitePages = null): array
    {
        $meta = $project->readJson('meta.json');
        $blueprint = DesignDirectionStep::heroBlueprintFor($project);
        $projection = HeroComposition::planProjection($blueprint);

        $siteSpec = $project->readText('siteSpec.json');
        $designDirection = DesignDirectionStep::readFor($project);
        $shared = [
            'user_prompt'      => (string) ($meta['prompt'] ?? ''),
            'site_spec'        => $siteSpec,
            'language'         => SiteSpecStep::languageOf($project),
            'design_direction' => $designDirection,
            'site_pages'       => self::sitePagesList($sitePages ?? $pages),
            // One footer part renders below every page here, and these requests
            // fan out concurrently blind to each other — so this is the only
            // point where the MODEL can be steered off it. The deterministic
            // floor in consumeResults() is what guarantees the result.
            'footer_surface_rule' => FooterComposition::closingSectionRule(
                FooterComposition::surface(FooterComposition::archetypeFor($siteSpec, $designDirection)),
            ),
        ];

        $requests = [];
        $jsonSchema = ['name' => 'page_plan', 'schema' => self::jsonSchema()];
        foreach ($pages as $page) {
            $front = (bool) $page['front'];
            $requests[$page['slug']] = $this->withOptions([
                'prompt' => $this->renderer->render('page-plan.md', $shared + [
                    'page_title'             => (string) $page['title'],
                    'page_slug'              => (string) $page['slug'],
                    'page_purpose'           => (string) $page['purpose'],
                    'page_emphasis'          => $front ? self::FRONT_EMPHASIS : self::INTERIOR_EMPHASIS,
                    'front_hero_context'     => $front
                        ? self::frontHeroPromptContext($blueprint, $projection)
                        : '',
                    'primary_action_rule'    => $front
                        ? 'Only this front page\'s FIRST section may provide `primary_action`. Use a real, useful '
                            . 'destination from SITE PAGES, a real section anchor planned on this site, or a contact '
                            . 'destination stated in SITE SPEC; otherwise return null. Every later section returns null.'
                        : 'This is not the front-page hero. Return `primary_action`: null on EVERY section.',
                ]),
                'json_schema' => $jsonSchema,
            ]);
        }
        return $requests;
    }

    /**
     * Render the one assigned blueprint plus its code-owned generic-plan
     * projection for the front-page planning request only. Interior requests
     * receive an empty replacement and therefore cannot learn hero topology.
     *
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $projection
     */
    private static function frontHeroPromptContext(array $blueprint, array $projection): string
    {
        [$archetype, $backgrounds, $default] = self::projectionContract($projection);

        return DesignDirectionStep::formatHeroBlueprint($blueprint)
            . "\n\nCode-owned page-plan projection (authoritative):\n"
            . "- The FIRST section's layout_archetype is exactly `{$archetype}`.\n"
            . '- Its background is one of: ' . implode(', ', array_map(
                static fn (string $value): string => "`{$value}`",
                $backgrounds
            )) . ".\n"
            . "- Prefer `{$default}` when no other allowed surface better serves the real following section.\n"
            . '- Do not reinterpret or replace this topology. Design the following section around the locked opening.';
    }

    public function consume(Project $project, array $results): void
    {
        $this->consumeResults($project, $results);
    }

    /**
     * @param array<string,array<mixed>> $results
     * @param list<string> $initialWarnings
     */
    private function consumeResults(Project $project, array $results, array $initialWarnings = []): void
    {
        $siteSpec = $project->readJson('siteSpec.json');
        $pages = self::flattenPages($siteSpec);
        $blueprint = DesignDirectionStep::heroBlueprintFor($project);
        $frontProjection = HeroComposition::planProjection($blueprint);
        $allowOffsetGrid = self::allowOffsetGridFor($project, $siteSpec);
        $actionContext = self::withPlannedSectionAnchors(
            self::primaryActionContext($siteSpec, $pages),
            $pages,
            $results,
        );

        // First pass: normalize what the model returned, collecting every page
        // that broke a rule so they can all be re-asked together below.
        $sectionsBySlug = [];
        $rejected = [];
        $warnings = $initialWarnings;
        $successfulRepairs = [];
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            $front = (bool) $page['front'];
            $projection = $front ? $frontProjection : null;
            $plan = $results[$slug] ?? null;
            if (!is_array($plan)) {
                $sectionsBySlug[$slug] = self::fallbackAfterGeneratedPlanLoss(
                    $front,
                    $warnings,
                    $slug,
                    'missing generated page-plan result',
                    'deterministic fallback substituted for the missing page result',
                    $projection,
                    $actionContext,
                );
                continue;
            }

            $rawSections = $plan['sections'] ?? null;
            $sectionCount = self::sectionArrayCount($rawSections);
            $rawSections = self::removeTemplateFooterSections($rawSections, $warnings, $slug);
            $removedFooter = self::sectionArrayCount($rawSections) < $sectionCount;
            if ($removedFooter && self::sectionArrayCount($rawSections) === 0) {
                $sectionsBySlug[$slug] = self::fallbackAfterFooterRemoval(
                    $front,
                    $warnings,
                    $slug,
                    $projection,
                    $actionContext,
                );
                continue;
            }

            try {
                $normalizationWarnings = [];
                $normalizationRepairs = [];
                $sections = self::normalize(
                    $rawSections,
                    $front,
                    $projection,
                    $actionContext,
                    $normalizationWarnings,
                    $slug,
                    $normalizationRepairs,
                    $allowOffsetGrid,
                );
                if ($sections === []) {
                    throw new \RuntimeException(
                        "page-plan: page '{$slug}' has no sections — return the full JSON object with a non-empty \"sections\" array"
                    );
                }
                $sectionsBySlug[$slug] = $sections;
                $warnings = array_merge($warnings, $normalizationWarnings);
                $successfulRepairs = array_merge($successfulRepairs, $normalizationRepairs);
            } catch (\RuntimeException $e) {
                // The repair prompt must not reintroduce a section the
                // deterministic template-ownership guard already removed.
                $filteredPlan = $plan;
                $discardedRepairs = [];
                $filteredPlan['sections'] = is_array($rawSections)
                    ? self::reconcileFrontHeroProjection(
                        $rawSections,
                        $front,
                        $projection,
                        $discardedRepairs,
                        $slug,
                    )
                    : $rawSections;
                $rejected[$slug] = ['plan' => $filteredPlan, 'errors' => $e->getMessage()];
            }
        }

        // The art-direction rules are creative constraints the model
        // occasionally violates. Re-ask ONCE per rejected page, all of them in
        // ONE batch; if a repair still breaks a rule, fix it mechanically
        // instead of aborting the build. recoverSections() is the backstop:
        // field + variety coercion for every known normalize rejection, and a
        // single fallback section if a future rule slips past both passes.
        // Empty, missing, and terminally malformed repairs degrade per page;
        // valid siblings and every already-paid-for plan still ship.
        $frontBySlug = array_column($pages, 'front', 'slug');
        $repairs = [];
        $repairFailures = [];
        try {
            $repairs = $this->repairAll($project, $rejected);
        } catch (GeneratedJsonException $e) {
            $repairs = $e->partialResults;
            $repairFailures = $e->failures;
        }
        if (array_intersect_key($repairs, $repairFailures) !== []) {
            throw new \RuntimeException('page-plan: repair failure routing overlaps successful results');
        }
        foreach (array_keys($repairs + $repairFailures) as $slug) {
            if (!array_key_exists($slug, $rejected)) {
                throw new \RuntimeException("page-plan: repair returned unknown page '{$slug}'");
            }
        }
        $repairActionContext = self::withPlannedSectionAnchors(
            $actionContext,
            $pages,
            array_replace($results, $repairs),
        );
        foreach ($rejected as $slug => $_rejection) {
            $slug = (string) $slug;
            $front = (bool) ($frontBySlug[$slug] ?? false);
            if (isset($repairFailures[$slug])) {
                $sectionsBySlug[$slug] = self::fallbackAfterGeneratedPlanLoss(
                    $front,
                    $warnings,
                    $slug,
                    'unusable generated repair JSON (' . $repairFailures[$slug] . ')',
                    'deterministic fallback substituted after the model repair remained unusable',
                    $front ? $frontProjection : null,
                    $repairActionContext,
                );
                continue;
            }
            $repaired = $repairs[$slug] ?? null;
            if (!is_array($repaired)) {
                $sectionsBySlug[$slug] = self::fallbackAfterGeneratedPlanLoss(
                    $front,
                    $warnings,
                    $slug,
                    'missing generated repair result',
                    'deterministic fallback substituted for the missing repair result',
                    $front ? $frontProjection : null,
                    $repairActionContext,
                );
                continue;
            }
            $rawSections = $repaired['sections'] ?? null;
            $sectionCount = self::sectionArrayCount($rawSections);
            $rawSections = self::removeTemplateFooterSections($rawSections, $warnings, $slug);
            $removedFooter = self::sectionArrayCount($rawSections) < $sectionCount;
            if ($removedFooter && self::sectionArrayCount($rawSections) === 0) {
                $sectionsBySlug[$slug] = self::fallbackAfterFooterRemoval(
                    $front,
                    $warnings,
                    $slug,
                    $front ? $frontProjection : null,
                    $repairActionContext,
                );
                continue;
            }
            try {
                $normalizationWarnings = [];
                $normalizationRepairs = [];
                $sections = self::normalize(
                    $rawSections,
                    $front,
                    $front ? $frontProjection : null,
                    $repairActionContext,
                    $normalizationWarnings,
                    $slug,
                    $normalizationRepairs,
                    $allowOffsetGrid,
                );
                $warnings = array_merge($warnings, $normalizationWarnings);
                $successfulRepairs = array_merge($successfulRepairs, $normalizationRepairs);
            } catch (\RuntimeException $stillInvalid) {
                $sections = self::recoverSections(
                    $rawSections,
                    $front,
                    $warnings,
                    $slug,
                    $front ? $frontProjection : null,
                    $repairActionContext,
                    $successfulRepairs,
                    $allowOffsetGrid,
                );
            }
            if ($sections === []) {
                $sections = self::fallbackAfterGeneratedPlanLoss(
                    $front,
                    $warnings,
                    $slug,
                    'empty repaired sections array',
                    'deterministic fallback substituted after the repair preserved no page-owned section',
                    $front ? $frontProjection : null,
                    $repairActionContext,
                );
            }
            $sectionsBySlug[$slug] = $sections;
        }

        $out = [];
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            if (!isset($sectionsBySlug[$slug])) {
                // Defensive backstop for a future branch added above: never
                // write null sections or abort over generated page content.
                $sectionsBySlug[$slug] = self::fallbackAfterGeneratedPlanLoss(
                    (bool) $page['front'],
                    $warnings,
                    $slug,
                    'no generated sections reached final assembly',
                    'deterministic fallback substituted at the page-plan boundary',
                    !empty($page['front']) ? $frontProjection : null,
                    $actionContext,
                );
            }
            $page['sections'] = $sectionsBySlug[$slug];
            $out[] = $page;
        }

        // The plan prompt demands a hero, at least 3 content sections, and a
        // closing — a 1-2 section front page is a degenerate plan (observed:
        // a SaaS brief delivered hero-only, shipping a 1.5-screen site).
        // Pad below the delivered sections with reviewed generic briefs so
        // the sections step still writes a whole page, and record the loss.
        $out = self::padThinFrontPlan($out, $frontProjection, $actionContext, $warnings, $allowOffsetGrid);

        // Anchors cannot be judged until every normal, repair, and fallback
        // path has produced its final page/section set. Recheck the sole
        // eligible action now and null it atomically when its target vanished.
        $out = self::validatePrimaryActionAnchors($out, $warnings);

        $project->writeText('logs/' . self::REPORT_FILE, $successfulRepairs === []
            ? "No semantics-preserving page-plan repairs were needed.\n"
            : implode("\n", $successfulRepairs) . "\n");
        if ($successfulRepairs !== []) {
            Narrator::write('  [page-plan] repaired ' . count($successfulRepairs)
                . " generated plan field(s) (reported separately from durable warnings).\n");
        }

        // Only removals, fallback substitutions, and other delivered-value
        // losses reach the durable queue. Exact recipe projection repairs are
        // recorded in the report above instead.
        // Last: one footer part renders below every page here, so no page may
        // close on its surface. The plan prompt says so, but the page requests
        // fan out concurrently and the model still lands on it often enough
        // that the seam merges — this is the deterministic floor.
        $out = self::withClosingBandOffFooterSurface(
            $out,
            FooterComposition::surface(FooterComposition::archetypeForProject($project)),
            $warnings,
        );

        $project->addWarnings($this->id(), $warnings);
        $project->writeJson('pages.json', ['pages' => $out]);
    }

    /**
     * Move any page's LAST section off the footer's surface, so the footer
     * always reads as its own band. Bands above the closing one are deliberate
     * page rhythm and are never touched; `tinted` and `image` already differ
     * from an exact solid surface and are left alone. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param list<string> $warnings
     * @return array<int,array<string,mixed>>
     */
    public static function withClosingBandOffFooterSurface(
        array $pages,
        string $footerSurface,
        array &$warnings = [],
    ): array {
        // A light footer takes a soft tint above it; forcing contrast would end
        // every non-compliant page on a dark band.
        $replacement = $footerSurface === 'base' ? 'tinted' : 'base';

        foreach ($pages as $index => $page) {
            $sections = $page['sections'] ?? null;
            if (!is_array($sections) || $sections === []) {
                continue;
            }
            $keys = array_keys($sections);
            $lastKey = end($keys);
            $last = $sections[$lastKey];
            if (!is_array($last) || ($last['background'] ?? null) !== $footerSurface) {
                continue;
            }
            $slug = (string) ($page['slug'] ?? '');
            $sections[$lastKey]['background'] = $replacement;
            // The plan's own handoff prose names the background it chose, and
            // both the section author and the footer author read that line —
            // correct it in place or they get two contradictory briefs.
            $handoff = trim((string) ($last['handoff'] ?? ''));
            $sections[$lastKey]['handoff'] = trim($handoff . ' Build correction: this section\'s background is now "'
                . $replacement . '" so the site footer\'s "' . $footerSurface . '" band below reads as its own '
                . 'surface; this supersedes any background named earlier in this line.');
            $pages[$index]['sections'] = $sections;
            $warnings[] = self::valueLossWarning(
                self::sectionPath($slug, (int) $lastKey) . '.background',
                $footerSurface,
                $replacement,
                "the footer renders on {$footerSurface} directly below it, so the planned band would have left "
                . 'that page with no visible footer boundary',
            );
        }
        return $pages;
    }

    /**
     * Normalize model results for a page SUBSET (HTML-first mixed fallback) and
     * return complete entries in the given order, without writing pages.json or
     * running the whole-site front/contract finalization. Subset pages are
     * interior, so front-hero projection never applies; a broken plan degrades
     * mechanically via recoverSections rather than aborting.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<array-key,array<mixed>> $results
     * @return array<int,array<string,mixed>>
     */
    private function plannedPages(Project $project, array $pages, array $results): array
    {
        $siteSpec = $project->readJson('siteSpec.json');
        $actionContext = self::primaryActionContext($siteSpec, $pages);
        $allowOffsetGrid = self::allowOffsetGridFor($project, $siteSpec);
        $warnings = [];
        $repairs = [];
        $out = [];
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            $front = (bool) $page['front'];
            $projection = $front
                ? HeroComposition::planProjection(DesignDirectionStep::heroBlueprintFor($project))
                : null;
            $plan = $results[$slug] ?? null;
            if (!is_array($plan)) {
                $page['sections'] = self::fallbackAfterGeneratedPlanLoss(
                    $front,
                    $warnings,
                    $slug,
                    'missing generated page-plan result',
                    'deterministic fallback substituted for the missing page result',
                    $projection,
                    $actionContext,
                );
                $out[] = $page;
                continue;
            }
            $rawSections = self::removeTemplateFooterSections($plan['sections'] ?? null, $warnings, $slug);
            try {
                $sections = self::normalize($rawSections, $front, $projection, $actionContext, $warnings, $slug, $repairs, $allowOffsetGrid);
                if ($sections === []) {
                    throw new \RuntimeException("page-plan: page '{$slug}' has no sections");
                }
            } catch (\RuntimeException $e) {
                $sections = self::recoverSections($rawSections, $front, $warnings, $slug, $projection, $actionContext, $repairs, $allowOffsetGrid);
                if ($sections === []) {
                    $sections = self::fallbackSections($front, $projection, $actionContext);
                }
            }
            $page['sections'] = $sections;
            $out[] = $page;
        }
        $project->addWarnings($this->id(), $warnings);
        return $out;
    }

    public function consumeGeneratedJsonFailure(
        Project $project,
        array $results,
        array $failures,
    ): void {
        $siteSpec = $project->readJson('siteSpec.json');
        $pages = self::flattenPages($siteSpec);
        $frontBySlug = array_column($pages, 'front', 'slug');
        $frontProjection = HeroComposition::planProjection(
            DesignDirectionStep::heroBlueprintFor($project)
        );
        $actionContext = self::primaryActionContext($siteSpec, $pages);
        $warnings = [];
        foreach ($failures as $slug => $diagnostic) {
            $slug = (string) $slug;
            if (!array_key_exists($slug, $frontBySlug) || isset($results[$slug])) {
                throw new \RuntimeException(
                    "page-plan: inconsistent generated JSON failure routing for page '{$slug}'"
                );
            }
            $results[$slug] = [
                'sections' => self::fallbackAfterGeneratedPlanLoss(
                    (bool) $frontBySlug[$slug],
                    $warnings,
                    $slug,
                    'unusable generated JSON (' . $diagnostic . ')',
                    'deterministic fallback substituted after generated JSON repair failed',
                    !empty($frontBySlug[$slug]) ? $frontProjection : null,
                    $actionContext,
                ),
            ];
        }
        $this->consumeResults($project, $results, $warnings);
    }

    /**
     * Re-ask every rejected page in ONE batch: each page's original prompt plus
     * its own rejected plan and validation errors, keyed back by page slug. No
     * rejections means no LLM call, and the page prompts are rendered once for
     * the whole batch rather than once per repair.
     *
     * @param array<string,array{plan:array<mixed>,errors:string}> $rejected
     * @return array<array-key,array<mixed>>
     */
    private function repairAll(Project $project, array $rejected): array
    {
        if ($rejected === []) {
            return [];
        }

        $prompts = $this->requests($project);
        $requests = [];
        foreach ($rejected as $slug => $rejection) {
            // Same flattenPages tree feeds both, so a miss means the page tree
            // shifted underneath us. Name the page instead of repairing against
            // a suffix with no page context.
            if (!isset($prompts[$slug]['prompt'])) {
                throw new \RuntimeException("page-plan: no prompt to repair page '{$slug}' with");
            }
            $requests[$slug] = $this->withOptions([
                'prompt'      => (string) $prompts[$slug]['prompt']
                    . self::repairSuffix($rejection['plan'], $rejection['errors']),
                'log_label'   => $this->id() . "-{$slug}-repair",
                'json_schema' => ['name' => 'page_plan', 'schema' => self::jsonSchema()],
            ]);
        }

        return $this->llm->completeJsonBatch($requests);
    }

    /**
     * The correction appended to a page's original prompt: the rejected plan
     * and every validation error, plus the rules a repair itself must respect.
     * Pure — unit-testable.
     *
     * @param array<mixed> $plan
     */
    private static function repairSuffix(array $plan, string $errors): string
    {
        return "\n\nYOUR PREVIOUS PLAN (JSON):\n"
            . json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nIT WAS REJECTED FOR THESE REASONS:\n{$errors}\n"
            . "\nReturn the corrected full JSON object. Fix EVERY rejection above. "
            . 'For an adjacent-duplicate rejection, change only ONE of the two sections, and re-check the whole '
            . 'corrected list top-to-bottom against every rule before returning — a repair that introduces a NEW '
            . 'violation is rejected too. If the front-page context locks the FIRST section, preserve it exactly '
            . 'and change the conflicting following section. '
            . 'If you change a section\'s layout_archetype, background, vertical_density, or position, also update its content_notes, '
            . 'handoff, and any affected neighbor handoffs so the prose matches the corrected assignment. '
            . 'Keep only fields that are still semantically consistent exactly as planned.';
    }

    public function run(Project $project): void
    {
        try {
            $this->consume($project, $this->llm->completeJsonBatch($this->requests($project)));
        } catch (GeneratedJsonException $e) {
            $this->consumeGeneratedJsonFailure($project, $e->partialResults, $e->failures);
        }
    }

    /**
     * Flatten the spec's page tree into display order: depth-first, parents
     * before children, the FIRST page marked as the front page. Paths follow
     * WordPress page permalinks ("/", "/menu/", "/menu/breads/") — including
     * for children of the front page, whose hierarchical URIs WordPress still
     * builds from the front page's post_name ("/home/visit/", never
     * "/visit/"). menu_order steps by 10 so seeded pages sort — and
     * wp:page-list renders — in plan order. A spec without pages degrades to
     * a single homepage. Pure — unit-testable.
     *
     * @param array<mixed> $spec
     * @return array<int,array<string,mixed>>
     */
    public static function flattenPages(array $spec): array
    {
        $tree = is_array($spec['pages'] ?? null) ? $spec['pages'] : [];

        $flat = [];
        $order = 0;
        $walk = function (array $pages, ?string $parent, string $basePath) use (&$walk, &$flat, &$order): void {
            foreach ($pages as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $rawSlug = trim((string) ($page['slug'] ?? ''));
                $rawTitle = trim((string) ($page['title'] ?? ''));
                if ($rawSlug === '' && $rawTitle === '') {
                    continue;
                }
                $slug = ProjectStore::slugify($rawSlug !== '' ? $rawSlug : $rawTitle);

                $front = $parent === null && $flat === [];
                $path = $front ? '/' : $basePath . $slug . '/';
                $flat[] = [
                    'slug'       => $slug,
                    'title'      => $rawTitle !== '' ? $rawTitle : ucwords(str_replace('-', ' ', $slug)),
                    'path'       => $path,
                    'front'      => $front,
                    'parent'     => $parent,
                    'menu_order' => $order * 10,
                    'purpose'    => trim((string) ($page['purpose'] ?? '')),
                ];
                $order++;

                if (is_array($page['children'] ?? null) && $page['children'] !== []) {
                    // The front page's path is "/" but its children still
                    // resolve under its real slug: the seeder parents them to
                    // the Home page, and get_page_uri() prepends every
                    // ancestor's post_name, front-page status notwithstanding.
                    $walk($page['children'], $slug, $front ? "/{$slug}/" : $path);
                }
            }
        };
        $walk($tree, null, '/');

        if ($flat === []) {
            return [[
                'slug'       => 'home',
                'title'      => 'Home',
                'path'       => '/',
                'front'      => true,
                'parent'     => null,
                'menu_order' => 0,
                'purpose'    => trim((string) ($spec['description'] ?? '')),
            ]];
        }
        return $flat;
    }

    /**
     * One line per page — title, path, front marker, purpose — shared context
     * for every prompt that should know the whole site's shape (page plans,
     * sections, header, footer). Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $pages flattened entries
     */
    public static function sitePagesList(array $pages): string
    {
        $lines = [];
        foreach ($pages as $page) {
            $lines[] = sprintf(
                '- "%s" — %s%s: %s',
                (string) ($page['title'] ?? ''),
                (string) ($page['path'] ?? ''),
                !empty($page['front']) ? ' (front page)' : '',
                (string) ($page['purpose'] ?? '')
            );
        }
        return implode("\n", $lines);
    }

    /**
     * Remove page-planned site chrome before section generation.
     *
     * Every assembled page receives theme/parts/footer.html from its template,
     * so a model-planned footer would render a second ending. Semantic labels
     * are open-ended, therefore inspect all three identity fields rather than
     * relying on one exact type: "footer", "footer-info", "site-footer", and
     * equivalent word-separated labels all identify template-owned chrome.
     *
     * Non-footer siblings are returned unchanged and in their original order.
     * Re-running the filter is a fixed point: once a footer is absent it
     * changes neither the list nor the warning accumulator.
     *
     * @param mixed $raw
     * @param list<string> $warnings appended to in place, one per removal
     * @return array<mixed>
     */
    public static function removeTemplateFooterSections(
        $raw,
        array &$warnings = [],
        string $pageSlug = ''
    ): array {
        if (!is_array($raw)) {
            return [];
        }

        $sections = [];
        foreach ($raw as $index => $section) {
            if (!is_array($section) || !FooterSectionIdentity::matches($section)) {
                $sections[] = $section;
                continue;
            }

            $pagePath = $pageSlug === ''
                ? 'pages[].sections[' . $index . ']'
                : "pages[slug='{$pageSlug}'].sections[{$index}]";
            $authored = json_encode(
                [
                    'slug'  => $section['slug'] ?? null,
                    'title' => $section['title'] ?? null,
                    'type'  => $section['type'] ?? null,
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if (!is_string($authored)) {
                $authored = get_debug_type($section);
            }

            $warnings[] = "page-plan: file='pages.json'; path=\"{$pagePath}\"; authored={$authored}; "
                . "delivered=removed; disposition=template-owned site-footer section removed because "
                . 'theme/parts/footer.html is appended by the page template';
        }

        return $sections;
    }

    /**
     * Validate one page's section list and force unique, file-safe slugs.
     * The structural role is stamped deterministically from each section's
     * position rather than trusted to model output. Art-direction fields
     * (layout_archetype, background, vertical_density, handoff) are strict:
     * unknown values, a missing handoff, adjacent duplicate archetypes, too
     * many card grids, or an interior page opening at homepage-cover scale
     * are collected and thrown together in ONE message, so the single repair
     * call sees every violation at once. The semantic type is intentionally
     * open-ended. Pure — unit-testable.
     *
     * @param mixed $raw
     * @param bool $front whether the page is the front page (interior pages
     *                    must not OPEN with a full-bleed cover)
     * @param array<string,mixed>|null $frontProjection code-owned homepage
     *        recipe projection; null on interior pages and focused unit tests
     * @param array<string,mixed> $actionContext known page/contact targets
     * @param list<string> $warnings appended only for delivered value loss
     * @param list<string> $repairs appended for semantics-preserving fixes
     * @param bool $allowOffsetGrid staggered rows are photography- and gallery-only
     * @return array<int,array<string,mixed>>
     */
    public static function normalize(
        $raw,
        bool $front = true,
        ?array $frontProjection = null,
        array $actionContext = [],
        array &$warnings = [],
        string $pageSlug = '',
        array &$repairs = [],
        bool $allowOffsetGrid = true,
    ): array {
        if (!is_array($raw)) {
            return [];
        }

        $raw = self::reconcileFrontHeroProjection(
            $raw,
            $front,
            $frontProjection,
            $repairs,
            $pageSlug,
        );

        $out = [];
        $seen = [];
        $errors = [];
        $sectionCount = count(array_filter($raw, 'is_array'));
        foreach ($raw as $i => $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = trim((string) ($section['title'] ?? ''));
            $slug = ProjectStore::slugify((string) ($section['slug'] ?? $title ?: "section-{$i}"));
            if ($slug === '') {
                $slug = 'section-' . count($out);
            }
            // Keep slugs unique so part filenames collide-free within the page.
            $base = $slug;
            $n = 2;
            while (isset($seen[$slug])) {
                $slug = "{$base}-{$n}";
                $n++;
            }
            $seen[$slug] = true;

            $type = trim((string) ($section['type'] ?? ''));
            if ($type === '') {
                $errors[] = "page-plan: section '{$slug}' is missing 'type' — provide a short semantic label";
            }
            $archetype = trim((string) ($section['layout_archetype'] ?? ''));
            if (!in_array($archetype, self::ARCHETYPES, true)) {
                $errors[] = "page-plan: section '{$slug}' has invalid layout_archetype '{$archetype}' — use one of: "
                    . implode(', ', self::ARCHETYPES);
            }
            $background = trim((string) ($section['background'] ?? ''));
            if (!in_array($background, self::BACKGROUNDS, true)) {
                $errors[] = "page-plan: section '{$slug}' has invalid background '{$background}' — use one of: "
                    . implode(', ', self::BACKGROUNDS);
            }
            $verticalDensity = trim((string) ($section['vertical_density'] ?? ''));
            if (!in_array($verticalDensity, self::VERTICAL_DENSITIES, true)) {
                $errors[] = "page-plan: section '{$slug}' has invalid vertical_density '{$verticalDensity}' — use one of: "
                    . implode(', ', self::VERTICAL_DENSITIES);
            }
            $type = trim((string) ($section['type'] ?? 'content'));
            if ($verticalDensity === 'spacious' && self::isDenseType($type)) {
                $errors[] = "page-plan: section '{$slug}' is content-dense ({$type}, {$archetype}) — "
                    . "use vertical_density 'compact' or 'standard', not 'spacious'";
            }
            $handoff = trim((string) ($section['handoff'] ?? ''));
            if ($handoff === '') {
                $errors[] = "page-plan: section '{$slug}' is missing 'handoff' — describe what sits immediately above and below it";
            }

            $sectionPath = self::sectionPath($pageSlug, count($out)) . '.primary_action';
            $primaryAction = self::normalizePrimaryAction(
                $section['primary_action'] ?? null,
                $front && $out === [],
                $actionContext,
                $warnings,
                $sectionPath,
            );

            $out[] = [
                'slug'             => $slug,
                'title'            => $title !== '' ? $title : ucwords(str_replace('-', ' ', $slug)),
                'role'             => SectionRole::forPosition(count($out), $sectionCount),
                'type'             => $type,
                'purpose'          => trim((string) ($section['purpose'] ?? '')),
                'content_notes'    => trim((string) ($section['content_notes'] ?? '')),
                'layout_archetype' => $archetype,
                'background'       => $background,
                'vertical_density' => $verticalDensity,
                'handoff'          => $handoff,
                'primary_action'   => $primaryAction,
            ];
        }

        // A fragment destination must name a section the generated plans
        // actually contain. Authoring '#menu-signature' while never planning
        // a menu section shipped a hero button whose label promised content
        // the page cannot deliver (BIGR-800): the deterministic
        // closing-section retarget keeps the button working but cannot repair
        // the label's promise, so the plan gets its one repair round to
        // reconcile sections, destination, and label together. The bare '#'
        // placeholder intentionally stays valid — the retarget backstop owns
        // it. Primary actions belong only to the front page, whose current
        // path is '/', while planned_anchors supplies every cross-page target.
        foreach ($out as $section) {
            $action = $section['primary_action'] ?? null;
            if (!is_array($action)) {
                continue;
            }
            $destination = trim((string) ($action['destination'] ?? ''));
            $target = self::anchorTarget($destination, '/');
            if ($target === null || $destination === '#') {
                continue;
            }
            [$targetPath, $fragment] = $target;
            $plannedAnchors = is_array($actionContext['planned_anchors'] ?? null)
                ? $actionContext['planned_anchors']
                : [];
            if ($targetPath === '/') {
                $targetAnchors = $seen;
                $targetDescription = 'this plan';
            } elseif (array_key_exists($targetPath, $plannedAnchors)) {
                $targetAnchors = is_array($plannedAnchors[$targetPath])
                    ? $plannedAnchors[$targetPath]
                    : [];
                $targetDescription = "the planned page '{$targetPath}'";
            } else {
                // A missing/unusable sibling plan has its own fallback path.
                // The final all-page validator will judge its delivered anchors.
                continue;
            }
            if (isset($targetAnchors[$fragment])) {
                continue;
            }
            $errors[] = "page-plan: section '{$section['slug']}' primary_action.destination "
                . "'{$destination}' names no section in {$targetDescription} — plan that section, or point the "
                . 'action (label AND destination together, so the label still describes where the '
                . 'button goes) at a section the target page really contains';
        }

        $out = self::restrictOffsetGrid($out, $allowOffsetGrid, $front, $warnings, $pageSlug);

        // An interior page that opens with a full-viewport cover is a second
        // homepage, not an inner page (the prompt demands a COMPACT opening).
        // The escape hatch for a deliberately image-led opening is explicit:
        // background "image" on any compact archetype renders a full-bleed
        // image band without homepage-hero scale.
        if (!$front && ($out[0]['layout_archetype'] ?? '') === 'full-bleed-cover') {
            $errors[] = "page-plan: the FIRST section '{$out[0]['slug']}' of this INTERIOR page uses "
                . "layout_archetype 'full-bleed-cover' — interior pages open with a COMPACT hero, not a second "
                . 'homepage hero; pick a compact archetype (use background "image" if the opening should be image-led)';
        }

        // Report every violation at once so the single repair call can fix them all.
        $errors = array_merge($errors, self::varietyErrors($out));
        if ($errors !== []) {
            throw new \RuntimeException(implode("\n", $errors));
        }
        return $out;
    }

    /**
     * Reconcile the delivered homepage opening with the code-owned recipe
     * projection before variety validation. The recipe topology is locked;
     * the plan still chooses among its reviewed compatible surfaces.
     *
     * @param array<mixed> $raw
     * @param array<string,mixed>|null $projection
     * @param list<string> $repairs
     * @return array<mixed>
     */
    private static function reconcileFrontHeroProjection(
        array $raw,
        bool $front,
        ?array $projection,
        array &$repairs,
        string $pageSlug,
    ): array {
        if (!$front || $projection === null) {
            return $raw;
        }

        [$archetype, $allowedBackgrounds, $defaultBackground] = self::projectionContract($projection);
        foreach ($raw as $rawIndex => $section) {
            if (!is_array($section)) {
                continue;
            }

            $path = self::sectionPath($pageSlug, 0);
            $authoredArchetype = trim((string) ($section['layout_archetype'] ?? ''));
            if ($authoredArchetype !== $archetype) {
                $raw[$rawIndex]['layout_archetype'] = $archetype;
                $repairs[] = self::successfulRepair(
                    "{$path}.layout_archetype",
                    $authoredArchetype,
                    $archetype,
                    'restored the code-assigned hero recipe projection',
                );
            }

            $authoredBackground = trim((string) ($section['background'] ?? ''));
            if (!in_array($authoredBackground, $allowedBackgrounds, true)) {
                $raw[$rawIndex]['background'] = $defaultBackground;
                $repairs[] = self::successfulRepair(
                    "{$path}.background",
                    $authoredBackground,
                    $defaultBackground,
                    'replaced a surface incompatible with the assigned hero recipe',
                );
            }
            break;
        }
        return $raw;
    }

    /**
     * @param array<string,mixed> $projection
     * @return array{0:string,1:list<string>,2:string}
     */
    private static function projectionContract(array $projection): array
    {
        $archetype = trim((string) ($projection['layout_archetype'] ?? ''));
        $allowed = array_values(array_unique(array_filter(
            is_array($projection['allowed_backgrounds'] ?? null) ? $projection['allowed_backgrounds'] : [],
            static fn (mixed $value): bool => is_string($value) && in_array($value, self::BACKGROUNDS, true),
        )));
        $default = trim((string) ($projection['default_background'] ?? ''));
        if (!in_array($archetype, self::ARCHETYPES, true)
            || $allowed === []
            || !in_array($default, $allowed, true)
        ) {
            throw new \LogicException('page-plan: invalid code-owned hero plan projection');
        }
        return [$archetype, $allowed, $default];
    }

    /**
     * Validate the one optional plan-owned front-hero action. Invalid or
     * misplaced generated content degrades as one unit: label, intent, and
     * destination can only be delivered together.
     *
     * @param array<string,mixed> $context
     * @param list<string> $warnings
     * @return array{label:string,intent:string,destination:string}|null
     */
    public static function normalizePrimaryAction(
        mixed $raw,
        bool $eligible,
        array $context,
        array &$warnings = [],
        string $path = 'pages[].sections[].primary_action',
    ): ?array {
        if ($raw === null) {
            return null;
        }

        $reason = '';
        if (!$eligible) {
            $reason = 'primary actions are owned only by the front page first section';
        } elseif (!is_array($raw) || array_is_list($raw)) {
            $reason = 'action must be an object or null';
        } elseif (array_diff(array_keys($raw), ['label', 'intent', 'destination']) !== []
            || array_diff(['label', 'intent', 'destination'], array_keys($raw)) !== []
        ) {
            $reason = 'action must contain exactly label, intent, and destination';
        }

        $label = is_array($raw) && is_string($raw['label'] ?? null) ? trim($raw['label']) : '';
        $intent = is_array($raw) && is_string($raw['intent'] ?? null) ? trim($raw['intent']) : '';
        $destination = is_array($raw) && is_string($raw['destination'] ?? null)
            ? trim($raw['destination'])
            : '';
        if ($reason === '' && (!self::isPlainText($label) || self::graphemeLength($label) > 80)) {
            $reason = 'label must be 1..80 Unicode grapheme clusters of plain text';
        }
        if ($reason === '' && !self::isPlainText($intent)) {
            $reason = 'intent must be non-empty plain text';
        }
        if ($reason === '' && !self::isPlainText($destination)) {
            $reason = 'destination must be non-empty plain text';
        }
        if ($reason === '' && !self::initialDestinationIsKnown($destination, $context)) {
            // An invented absolute URL (observed: https://atlasfield.io/trial
            // on a fabricated domain) must never ship — but deleting the
            // conversion CTA over it is rung 3 when rung 1 exists. Rewrite it
            // to an anchor named after the URL's last path segment; the later
            // anchor validation resolves it to a real planned section or
            // retargets it to the page's closing anchor.
            if (preg_match('#^https?://#i', $destination) === 1) {
                $segment = strtolower(trim((string) parse_url($destination, PHP_URL_PATH), '/'));
                $segment = (string) preg_replace('/[^a-z0-9-]+/', '-', basename($segment));
                $anchor = '#' . (trim($segment, '-') !== '' ? trim($segment, '-') : 'cta');
                $warnings[] = self::valueLossWarning(
                    $path . '.destination',
                    "'{$destination}'",
                    "'{$anchor}'",
                    'invented external URL rewritten to a local anchor for the closing-section retarget '
                        . 'instead of removing the action',
                    valuesAlreadyRendered: true,
                );
                $destination = $anchor;
            } else {
                $reason = 'destination is not a known page, planned anchor, or spec-backed contact target';
            }
        }

        if ($reason !== '') {
            $warnings[] = self::valueLossWarning(
                $path,
                self::warningValue($raw),
                'null',
                "removed invalid primary action: {$reason}",
                valuesAlreadyRendered: true,
            );
            return null;
        }

        return [
            'label' => $label,
            'intent' => $intent,
            'destination' => $destination,
        ];
    }

    /**
     * Recheck same-page and cross-page fragments only after all page recovery
     * paths have settled. A dead fragment on a known page is retargeted to
     * that page's closing (else last) section anchor — the planner's action
     * label and intent survive with a resolvable scroll target instead of the
     * whole conversion control being deleted (rung 1 before rung 3). Only when
     * no distinct retarget section exists is the action removed. Every sibling
     * and all non-action section fields remain byte-for-byte unchanged.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param list<string> $warnings
     * @return array<int,array<string,mixed>>
     */
    public static function validatePrimaryActionAnchors(array $pages, array &$warnings = []): array
    {
        $anchorsByPath = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $path = self::normalizePagePath((string) ($page['path'] ?? '/'));
            $anchorsByPath[$path] = [];
            foreach ((array) ($page['sections'] ?? []) as $section) {
                if (is_array($section) && trim((string) ($section['slug'] ?? '')) !== '') {
                    $anchorsByPath[$path][(string) $section['slug']] = true;
                }
            }
        }

        foreach ($pages as $pageIndex => $page) {
            if (!is_array($page)) {
                continue;
            }
            $currentPath = self::normalizePagePath((string) ($page['path'] ?? '/'));
            foreach ((array) ($page['sections'] ?? []) as $sectionIndex => $section) {
                if (!is_array($section) || !is_array($section['primary_action'] ?? null)) {
                    continue;
                }
                $destination = (string) ($section['primary_action']['destination'] ?? '');
                $target = self::anchorTarget($destination, $currentPath);
                if ($target === null) {
                    continue;
                }
                [$targetPath, $fragment] = $target;
                if (isset($anchorsByPath[$targetPath][$fragment])) {
                    continue;
                }

                $slug = (string) ($page['slug'] ?? '');
                $path = self::sectionPath($slug, (int) $sectionIndex) . '.primary_action';

                $owningSlug = $targetPath === $currentPath
                    ? trim((string) ($section['slug'] ?? ''))
                    : '';
                $retargetSlug = self::retargetAnchorSlug($pages, $targetPath, $owningSlug);
                if ($retargetSlug !== null) {
                    $delivered = ($targetPath === $currentPath ? '' : rtrim($targetPath, '/') . '/')
                        . '#' . $retargetSlug;
                    $warnings[] = self::valueLossWarning(
                        $path . '.destination',
                        "'{$destination}'",
                        "'{$delivered}'",
                        "retargeted primary action: authored target '{$destination}' has no matching "
                            . 'section anchor; delivered the closing-section anchor instead of removing the action',
                        valuesAlreadyRendered: true,
                    );
                    $pages[$pageIndex]['sections'][$sectionIndex]['primary_action']['destination'] = $delivered;
                    continue;
                }

                $warnings[] = self::valueLossWarning(
                    $path,
                    self::warningValue($section['primary_action']),
                    'null',
                    "removed primary action whose delivered target '{$destination}' has no matching section anchor",
                    valuesAlreadyRendered: true,
                );
                $pages[$pageIndex]['sections'][$sectionIndex]['primary_action'] = null;
            }
        }

        return $pages;
    }

    /**
     * Build the structured first-pass destination context. Page paths come
     * from the normalized spec tree; non-page destinations must occur in the
     * factual spec itself, so the planner cannot invent an external/contact
     * route merely because its syntax looks plausible.
     *
     * @param array<mixed> $siteSpec
     * @param array<int,array<string,mixed>> $pages
     * @return array{page_paths:array<string,true>,contact_destinations:array<string,true>,email_domains:array<string,true>,planned_anchors:array<string,array<string,true>>}
     */
    public static function primaryActionContext(array $siteSpec, array $pages): array
    {
        $paths = [];
        foreach ($pages as $page) {
            if (is_array($page)) {
                $paths[self::normalizePagePath((string) ($page['path'] ?? '/'))] = true;
            }
        }

        $contacts = [];
        $walk = function (mixed $value, string $key = '') use (&$walk, &$contacts): void {
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $walk($child, strtolower((string) $childKey));
                }
                return;
            }
            if (!is_string($value)) {
                return;
            }

            $value = trim($value);
            if ($value === '') {
                return;
            }
            if (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL)) {
                $contacts[$value] = true;
                return;
            }
            if (str_starts_with(strtolower($value), 'mailto:')
                && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL)
            ) {
                $contacts[$value] = true;
                return;
            }
            if (str_starts_with(strtolower($value), 'tel:')
                && preg_match('/^tel:\+?[0-9][0-9(). -]*$/i', $value)
            ) {
                $contacts[$value] = true;
                return;
            }
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $contacts['mailto:' . $value] = true;
                return;
            }
            if (preg_match('/(?:phone|telephone|mobile|tel|whatsapp)/', $key)
                && preg_match('/^\+?[0-9][0-9(). -]*$/', $value)
            ) {
                $number = preg_replace('/[(). -]+/', '', $value) ?? '';
                if ($number !== '') {
                    $contacts['tel:' . $number] = true;
                }
            }
        };
        $walk($siteSpec);

        $emailDomains = [];
        $domain = strtolower(trim((string) ($siteSpec['email_domain'] ?? '')));
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
            $emailDomains[$domain] = true;
        }

        return [
            'page_paths' => $paths,
            'contact_destinations' => $contacts,
            'email_domains' => $emailDomains,
            'planned_anchors' => [],
        ];
    }

    /**
     * Add the section anchors visible across the generated page-plan batch.
     * This is derived before per-page normalization so a front-page action can
     * be semantically rejected in the same batched repair round when it names
     * a missing section on another page. A repaired batch is projected again
     * before repaired pages are normalized, so newly added target sections are
     * immediately visible to the owning action.
     *
     * @param array<string,mixed> $context
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,array<mixed>> $plans
     * @return array<string,mixed>
     */
    private static function withPlannedSectionAnchors(
        array $context,
        array $pages,
        array $plans,
    ): array {
        $anchors = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $slug = (string) ($page['slug'] ?? '');
            $plan = $plans[$slug] ?? null;
            if (!is_array($plan) || !is_array($plan['sections'] ?? null)) {
                continue;
            }
            $path = self::normalizePagePath((string) ($page['path'] ?? '/'));
            $anchors[$path] = self::normalizedSectionSlugSet($plan['sections']);
        }
        $context['planned_anchors'] = $anchors;
        return $context;
    }

    /**
     * Derive the same unique, file-safe slug set normalize() will deliver,
     * excluding template-owned footer sections before they can masquerade as
     * page anchors.
     *
     * @param mixed $raw
     * @return array<string,true>
     */
    private static function normalizedSectionSlugSet(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $pageOwned = [];
        foreach ($raw as $section) {
            if (is_array($section) && FooterSectionIdentity::matches($section)) {
                continue;
            }
            $pageOwned[] = $section;
        }
        $seen = [];
        foreach ($pageOwned as $i => $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = trim((string) ($section['title'] ?? ''));
            $slug = ProjectStore::slugify((string) ($section['slug'] ?? $title ?: "section-{$i}"));
            if ($slug === '') {
                $slug = 'section-' . count($seen);
            }
            $base = $slug;
            $n = 2;
            while (isset($seen[$slug])) {
                $slug = "{$base}-{$n}";
                $n++;
            }
            $seen[$slug] = true;
        }
        return $seen;
    }

    /** @param array<string,mixed> $context */
    private static function initialDestinationIsKnown(string $destination, array $context): bool
    {
        $contacts = is_array($context['contact_destinations'] ?? null)
            ? $context['contact_destinations']
            : [];
        if (isset($contacts[$destination]) || in_array($destination, $contacts, true)) {
            return true;
        }

        if (str_starts_with(strtolower($destination), 'mailto:')) {
            $address = substr($destination, 7);
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            $at = strrpos($address, '@');
            $domain = $at === false ? '' : strtolower(substr($address, $at + 1));
            $domains = is_array($context['email_domains'] ?? null) ? $context['email_domains'] : [];
            return isset($domains[$domain]) || in_array($domain, $domains, true);
        }

        if (str_starts_with($destination, '#')) {
            // Any anchor shape survives the first pass — including the
            // placeholder "#" the prompt forbids but models still emit.
            // validatePrimaryActionAnchors() always runs after every
            // recovery path and retargets an unknown fragment to the
            // page's closing section (or removes the action when no
            // distinct target exists), which preserves the conversion CTA
            // instead of deleting it over a placeholder.
            return true;
        }
        if (!str_starts_with($destination, '/')) {
            return false;
        }

        $parts = parse_url($destination);
        if ($parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['query'])
        ) {
            return false;
        }
        $fragment = (string) ($parts['fragment'] ?? '');
        if (str_contains($destination, '#') && $fragment === '') {
            return false;
        }
        if ($fragment !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $fragment) !== 1) {
            return false;
        }
        $path = self::normalizePagePath((string) ($parts['path'] ?? '/'));
        $paths = is_array($context['page_paths'] ?? null) ? $context['page_paths'] : [];
        return isset($paths[$path]) || in_array($path, $paths, true);
    }

    /** @return array{0:string,1:string}|null */
    /**
     * Append reviewed generic section briefs below a front page whose
     * delivered plan has fewer than three sections. The appended briefs are
     * re-normalized together with the delivered ones so slug uniqueness,
     * enum, and variety rules hold; positional roles then make the final
     * appended section the page's closing anchor (which the primary-action
     * retarget can use). Interior pages are never padded.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param list<string> $warnings
     * @param bool $allowOffsetGrid
     * @return array<int,array<string,mixed>>
     */
    public static function padThinFrontPlan(
        array $pages,
        ?array $frontProjection,
        array $actionContext,
        array &$warnings = [],
        bool $allowOffsetGrid = true,
    ): array {
        foreach ($pages as $index => $page) {
            if (!is_array($page) || empty($page['front'])) {
                continue;
            }
            $sections = array_values(array_filter((array) ($page['sections'] ?? []), 'is_array'));
            $missing = 3 - count($sections);
            if ($missing <= 0) {
                return $pages;
            }

            // A delivered plan of 2+ sections keeps its own final section in
            // the closing position; the generic briefs slot in above it. A
            // hero-only plan appends, so the appended tail takes the role.
            // Each inserted archetype avoids both neighbors so the adjacency
            // variety rule holds by construction.
            $safeArchetypes = $allowOffsetGrid
                ? ['centered-stack', 'asymmetric-split', 'offset-grid']
                : ['centered-stack', 'asymmetric-split', 'mixed-width-editorial'];
            $briefs = [
                [
                    'slug'          => 'overview',
                    'title'         => 'Overview',
                    'type'          => 'content',
                    'purpose'       => 'Substantiate the hero promise with the core offering, grounded in the site spec.',
                    'content_notes' => 'Two or three short paragraphs or a compact list; every claim comes from the site spec, nothing invented.',
                    'background'    => 'contrast',
                    'handoff'       => 'Sits between the hero above and the closing section below.',
                ],
                [
                    'slug'          => 'closing',
                    'title'         => 'Next Step',
                    'type'          => 'cta',
                    'purpose'       => 'Close the page with the one next step a visitor should take.',
                    'content_notes' => 'One heading, one short supporting line, and the conversion or contact path stated in the site spec.',
                    'background'    => 'base',
                    'handoff'       => 'Sits between the previous section and the site footer.',
                ],
            ];
            $insertAt = count($sections) >= 2 ? count($sections) - 1 : count($sections);
            $above = $insertAt > 0
                ? (string) ($sections[$insertAt - 1]['layout_archetype'] ?? '')
                : '';
            $below = isset($sections[$insertAt])
                ? (string) ($sections[$insertAt]['layout_archetype'] ?? '')
                : '';
            $inserted = [];
            foreach (array_slice($briefs, 0, $missing) as $brief) {
                $archetype = $safeArchetypes[0];
                foreach ($safeArchetypes as $candidate) {
                    if ($candidate !== $above && $candidate !== $below) {
                        $archetype = $candidate;
                        break;
                    }
                }
                $above = $archetype;
                $inserted[] = $brief + [
                    'layout_archetype' => $archetype,
                    'vertical_density' => 'standard',
                    'primary_action'   => null,
                ];
            }
            $padded = array_merge(
                array_slice($sections, 0, $insertAt),
                $inserted,
                array_slice($sections, $insertAt),
            );
            $repairs = [];
            try {
                $pages[$index]['sections'] = self::normalize(
                    $padded,
                    true,
                    $frontProjection,
                    $actionContext,
                    $warnings,
                    (string) ($page['slug'] ?? ''),
                    $repairs,
                    $allowOffsetGrid,
                );
            } catch (\Throwable) {
                // Padding must never make a deliverable plan worse: keep the
                // thin-but-valid delivered sections when the padded list
                // cannot be normalized.
                return $pages;
            }
            $warnings[] = self::valueLossWarning(
                "pages[slug='" . (string) ($page['slug'] ?? '') . "'].sections",
                count($sections) . ' delivered section(s)',
                count($pages[$index]['sections']) . ' section(s) after padding',
                'front-page plan was thinner than the contract minimum of a hero plus supporting and closing '
                    . 'sections; reviewed generic briefs were appended below the delivered ones',
                valuesAlreadyRendered: true,
            );
            return $pages;
        }
        return $pages;
    }

    /**
     * The anchor a dead primary-action fragment is deterministically
     * retargeted to on the target page: the last section with the `closing`
     * role, else the page's last section — where a landing page's terminal
     * conversion moment lives. The section that owns the action is never a
     * valid target (a CTA must not scroll to itself), so a page whose only
     * candidate is the owner yields null and the caller falls back to removal.
     *
     * @param array<int,array<string,mixed>> $pages
     */
    private static function retargetAnchorSlug(array $pages, string $targetPath, string $owningSlug): ?string
    {
        foreach ($pages as $page) {
            if (!is_array($page) || self::normalizePagePath((string) ($page['path'] ?? '/')) !== $targetPath) {
                continue;
            }
            $closing = null;
            $last = null;
            foreach ((array) ($page['sections'] ?? []) as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $slug = trim((string) ($section['slug'] ?? ''));
                if ($slug === '' || $slug === $owningSlug) {
                    continue;
                }
                $last = $slug;
                if ((string) ($section['role'] ?? '') === 'closing') {
                    $closing = $slug;
                }
            }
            return $closing ?? $last;
        }
        return null;
    }

    private static function anchorTarget(string $destination, string $currentPath): ?array
    {
        if (str_starts_with($destination, '#')) {
            return [self::normalizePagePath($currentPath), substr($destination, 1)];
        }
        if (!str_starts_with($destination, '/')) {
            return null;
        }
        $parts = parse_url($destination);
        if ($parts === false || (string) ($parts['fragment'] ?? '') === '') {
            return null;
        }
        return [
            self::normalizePagePath((string) ($parts['path'] ?? '/')),
            (string) $parts['fragment'],
        ];
    }

    private static function normalizePagePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : $path . '/';
    }

    private static function isPlainText(string $value): bool
    {
        return $value !== ''
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1
            && strip_tags($value) === $value;
    }

    private static function graphemeLength(string $value): int
    {
        if (function_exists('grapheme_strlen')) {
            $length = grapheme_strlen($value);
            return is_int($length) ? $length : PHP_INT_MAX;
        }
        $matched = preg_match_all('/\X/u', $value, $graphemes);
        return is_int($matched) ? $matched : PHP_INT_MAX;
    }

    private static function sectionPath(string $pageSlug, int $sectionIndex): string
    {
        return $pageSlug === ''
            ? "pages[].sections[{$sectionIndex}]"
            : "pages[slug='{$pageSlug}'].sections[{$sectionIndex}]";
    }

    private static function warningValue(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : get_debug_type($value);
    }

    private static function successfulRepair(
        string $path,
        mixed $authored,
        mixed $delivered,
        string $disposition,
    ): string {
        return "page-plan repair: file='pages.json'; path=\"{$path}\"; authored="
            . self::warningValue($authored) . '; delivered=' . self::warningValue($delivered)
            . "; disposition=repaired — {$disposition}";
    }

    private static function valueLossWarning(
        string $path,
        mixed $authored,
        mixed $delivered,
        string $disposition,
        bool $valuesAlreadyRendered = false,
    ): string {
        $authoredValue = $valuesAlreadyRendered ? (string) $authored : self::warningValue($authored);
        $deliveredValue = $valuesAlreadyRendered ? (string) $delivered : self::warningValue($delivered);
        return "page-plan: file='pages.json'; path=\"{$path}\"; authored={$authoredValue}; "
            . "delivered={$deliveredValue}; disposition={$disposition}";
    }

    /**
     * Count actual section objects while ignoring malformed scalar entries in
     * the same way normalize() does.
     */
    private static function sectionArrayCount(mixed $raw): int
    {
        return is_array($raw) ? count(array_filter($raw, 'is_array')) : 0;
    }

    /**
     * Keep a page buildable when its only authored section was a duplicate
     * template footer. The loss and delivered fallback both remain explicit
     * in warnings.json; this generated-content defect must not abort the site.
     *
     * @param list<string> $warnings appended to in place
     * @return array<int,array<string,mixed>>
     */
    private static function fallbackAfterFooterRemoval(
        bool $front,
        array &$warnings,
        string $pageSlug,
        ?array $frontProjection = null,
        array $actionContext = [],
    ): array {
        $pagePath = $pageSlug === '' ? 'pages[].sections' : "pages[slug='{$pageSlug}'].sections";
        $warnings[] = "page-plan: file='pages.json'; path=\"{$pagePath}\"; "
            . 'authored=no page-owned section survived footer removal; delivered=one synthesized content section; '
            . 'disposition=minimal valid page plan substituted so duplicate site chrome cannot abort the build';
        return self::fallbackSections($front, $frontProjection, $actionContext);
    }

    /**
     * @param list<string> $warnings appended to in place
     * @return array<int,array<string,mixed>>
     */
    private static function fallbackAfterGeneratedPlanLoss(
        bool $front,
        array &$warnings,
        string $pageSlug,
        string $authored,
        string $disposition,
        ?array $frontProjection = null,
        array $actionContext = [],
    ): array {
        $pagePath = $pageSlug === '' ? 'pages[].sections' : "pages[slug='{$pageSlug}'].sections";
        $warnings[] = "page-plan: file='pages.json'; path=\"{$pagePath}\"; authored={$authored}; "
            . "delivered=one synthesized content section; disposition={$disposition}";
        return self::fallbackSections($front, $frontProjection, $actionContext);
    }

    /**
     * Mechanical backstop after the LLM repair round still fails normalize().
     *
     * Runs repairFields(), repairVariety(), and the smallest-unit action-anchor
     * backstop before normalize(). Those passes cover every rejection
     * normalize() can raise today bar an empty section list. If a future rule
     * slips past them, the residual failure is recorded as a warning and the
     * page gets a single known-good section instead of aborting the build.
     *
     * Empty input still returns [] so the caller can record the loss and
     * synthesize a page-boundary fallback; inventing sections from nothing is
     * not this field-repair helper's job.
     *
     * Pure — unit-testable.
     *
     * @param mixed $raw
     * @param list<string> $warnings appended to in place
     * @param bool $allowOffsetGrid
     * @return array<int,array<string,mixed>>
     */
    public static function recoverSections(
        $raw,
        bool $front,
        array &$warnings = [],
        string $pageSlug = '',
        ?array $frontProjection = null,
        array $actionContext = [],
        array &$repairs = [],
        bool $allowOffsetGrid = true,
    ): array {
        if (!is_array($raw)) {
            return [];
        }
        $prepared = self::reconcileFrontHeroProjection(
            $raw,
            $front,
            $frontProjection,
            $repairs,
            $pageSlug,
        );
        $mechanically = self::repairVariety(
            self::repairFields($prepared, $warnings, $pageSlug, $allowOffsetGrid),
            $front,
            $frontProjection,
            $warnings,
            $pageSlug,
            $repairs,
            $allowOffsetGrid,
        );
        $mechanically = self::repairUnresolvedPrimaryActionAnchor(
            $mechanically,
            $front,
            $actionContext,
            $warnings,
            $pageSlug,
        );
        if ($mechanically === []) {
            return [];
        }
        return self::acceptRepairedSections(
            $mechanically,
            $front,
            $warnings,
            $pageSlug,
            $frontProjection,
            $actionContext,
            $repairs,
            $allowOffsetGrid,
        );
    }

    /**
     * Smallest-unit backstop when the generated repair repeats a dead action
     * anchor. Retarget to the target page's final delivered/planned section
     * when possible; otherwise remove only the action. The authored sections
     * must not be replaced merely because language-level repair failed twice.
     *
     * @param array<int,array<string,mixed>> $sections
     * @param array<string,mixed> $actionContext
     * @param list<string> $warnings
     * @return array<int,array<string,mixed>>
     */
    private static function repairUnresolvedPrimaryActionAnchor(
        array $sections,
        bool $front,
        array $actionContext,
        array &$warnings,
        string $pageSlug,
    ): array {
        if (!$front || !isset($sections[0]) || !is_array($sections[0]['primary_action'] ?? null)) {
            return $sections;
        }
        $action = $sections[0]['primary_action'];
        $path = self::sectionPath($pageSlug, 0) . '.primary_action';
        $actionWarnings = [];
        $normalizedAction = self::normalizePrimaryAction(
            $action,
            true,
            $actionContext,
            $actionWarnings,
            $path,
        );
        if ($normalizedAction === null) {
            // normalize() owns this invalid-action removal and its warning;
            // do not narrate an intermediate anchor change that will not ship.
            return $sections;
        }
        $authoredDestination = trim((string) ($action['destination'] ?? ''));
        $destination = $normalizedAction['destination'];
        $sections[0]['primary_action'] = $normalizedAction;
        $target = self::anchorTarget($destination, '/');
        if ($target === null || $destination === '#') {
            $warnings = array_merge($warnings, $actionWarnings);
            return $sections;
        }
        [$targetPath, $fragment] = $target;
        $ownAnchors = self::normalizedSectionSlugSet($sections);
        if ($targetPath === '/') {
            $targetAnchors = $ownAnchors;
        } else {
            $plannedAnchors = is_array($actionContext['planned_anchors'] ?? null)
                ? $actionContext['planned_anchors']
                : [];
            if (!array_key_exists($targetPath, $plannedAnchors)) {
                return $sections;
            }
            $targetAnchors = is_array($plannedAnchors[$targetPath])
                ? $plannedAnchors[$targetPath]
                : [];
        }
        if (isset($targetAnchors[$fragment])) {
            $warnings = array_merge($warnings, $actionWarnings);
            return $sections;
        }

        $ownerSlug = (string) array_key_first($ownAnchors);
        $candidates = array_keys($targetAnchors);
        if ($targetPath === '/') {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (string $slug): bool => $slug !== $ownerSlug,
            ));
        }
        $retargetSlug = $candidates === [] ? null : $candidates[count($candidates) - 1];
        if ($retargetSlug === null) {
            $sections[0]['primary_action'] = null;
            $warnings[] = self::valueLossWarning(
                $path,
                self::warningValue($action),
                'null',
                "removed only the primary action after generated repair repeated unresolved target '{$destination}'",
                valuesAlreadyRendered: true,
            );
            return $sections;
        }

        $prefix = $targetPath === '/'
            ? (str_starts_with($destination, '/') ? '/' : '')
            : rtrim($targetPath, '/') . '/';
        $delivered = $prefix . '#' . $retargetSlug;
        $sections[0]['primary_action']['destination'] = $delivered;
        $warnings[] = self::valueLossWarning(
            $path . '.destination',
            "'{$authoredDestination}'",
            "'{$delivered}'",
            'retargeted only the unresolved primary action after generated repair repeated the dead anchor; '
                . 'preserved every authored page section',
            valuesAlreadyRendered: true,
        );
        return $sections;
    }

    /**
     * Accept a field+variety-repaired section list, or degrade to one section.
     *
     * Separated from recoverSections() so the residual path is unit-testable
     * without inventing a normalize rule the two passes cannot answer: pass an
     * unrepaired invalid list and this method warns + falls back instead of
     * throwing.
     *
     * @param array<int,array<string,mixed>> $sections
     * @param list<string> $warnings appended to in place
     * @param bool $allowOffsetGrid
     * @return array<int,array<string,mixed>>
     */
    public static function acceptRepairedSections(
        array $sections,
        bool $front,
        array &$warnings = [],
        string $pageSlug = '',
        ?array $frontProjection = null,
        array $actionContext = [],
        array &$repairs = [],
        bool $allowOffsetGrid = true,
    ): array {
        try {
            return self::normalize(
                $sections,
                $front,
                $frontProjection,
                $actionContext,
                $warnings,
                $pageSlug,
                $repairs,
                $allowOffsetGrid,
            );
        } catch (\RuntimeException $residual) {
            $path = $pageSlug === '' ? 'pages[].sections' : "pages[slug='{$pageSlug}'].sections";
            $warnings[] = self::valueLossWarning(
                $path,
                'mechanically repaired plan with residual error: ' . $residual->getMessage(),
                'one synthesized content section',
                'replaced the smallest page-plan unit after bounded repair left residual validation errors',
            );
            return self::fallbackSections($front, $frontProjection, $actionContext);
        }
    }

    /**
     * Smallest valid page plan: one section that always survives normalize().
     *
     * Used only when recoverSections() cannot coerce the model's list into a
     * legal plan. Front pages keep a full-bleed cover so the homepage still
     * looks like a homepage; interior pages open compact.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fallbackSections(
        bool $front = true,
        ?array $frontProjection = null,
        array $actionContext = [],
    ): array {
        $archetype = $front ? 'full-bleed-cover' : 'centered-stack';
        $background = 'base';
        if ($front && $frontProjection !== null) {
            [$archetype, $_allowedBackgrounds, $background] = self::projectionContract($frontProjection);
        }
        $warnings = [];
        return self::normalize([
            [
                'slug'             => 'content',
                'title'            => 'Content',
                'type'             => 'content',
                'purpose'          => '',
                'content_notes'    => '',
                'layout_archetype' => $archetype,
                'background'       => $background,
                'vertical_density' => 'standard',
                'handoff'          => 'Sits below the site header and above the site footer.',
                'primary_action'   => null,
            ],
        ], $front, $frontProjection, $actionContext, $warnings);
    }

    /**
     * Mechanical fix for the field-level rules on a raw section list: unknown
     * enum values and missing required fields.
     *
     * These are not creative-constraint violations like the variety rules — they
     * are the model mis-filling a slot, and the commonest shape is cross-wiring
     * two adjacent enum fields ('contrast' is a valid `background`, so it turns
     * up as a `layout_archetype`). One such slip on one section otherwise ends a
     * multi-minute build, because the repair round is the only thing that can
     * fix it and a repair that reproduces the mistake has nowhere left to go.
     *
     * Coerced archetypes avoid both neighbours, and never become a full-bleed
     * cover or a card grid — the two that carry their own rules — so this pass
     * hands repairVariety() a list it has no new work to do on. Prose
     * (content_notes, handoff) may then lag the coerced assignment, the same
     * accepted trade repairVariety() makes.
     *
     * Together with repairVariety(), covers every rejection normalize() can
     * raise except an empty section list, which no amount of mechanical repair
     * can invent. Interior-page rules (leading full-bleed cover) belong to
     * repairVariety(), which still receives the page's $front flag. Residual
     * failures after both passes are handled by recoverSections(). Pure —
     * unit-testable.
     *
     * @param mixed $raw
     * @param list<string> $warnings appended to in place, one per coercion
     * @param bool $allowOffsetGrid
     * @return array<int,array<string,mixed>>
     */
    public static function repairFields($raw, array &$warnings = [], string $pageSlug = '', bool $allowOffsetGrid = true): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $sections = array_values(array_filter($raw, 'is_array'));

        // Neither of these is a safe landing spot for a value we are guessing:
        // a cover has its own interior-page rule and a grid has a cap.
        $excluded = ['full-bleed-cover', 'equal-card-grid'];
        if (!$allowOffsetGrid) {
            $excluded[] = 'offset-grid';
        }
        $candidates = array_values(array_diff(self::ARCHETYPES, $excluded));

        $archetypes = array_map(
            fn (array $s) => trim((string) ($s['layout_archetype'] ?? '')),
            $sections
        );

        foreach ($sections as $i => $section) {
            $slug = trim((string) ($section['slug'] ?? '')) ?: "section-{$i}";

            if (!in_array($archetypes[$i], self::ARCHETYPES, true)) {
                $was = $archetypes[$i];
                $replacement = $candidates[0];
                foreach ($candidates as $candidate) {
                    if ($candidate !== ($archetypes[$i - 1] ?? null)
                        && $candidate !== ($archetypes[$i + 1] ?? null)
                    ) {
                        $replacement = $candidate;
                        break;
                    }
                }
                $archetypes[$i] = $replacement;
                $sections[$i]['layout_archetype'] = $replacement;
                $warnings[] = self::valueLossWarning(
                    self::sectionPath($pageSlug, (int) $i) . '.layout_archetype',
                    $was,
                    $replacement,
                    "replaced unknown layout archetype for section '{$slug}' with a reviewed valid assignment",
                );
            }

            $background = trim((string) ($section['background'] ?? ''));
            if (!in_array($background, self::BACKGROUNDS, true)) {
                $sections[$i]['background'] = 'base';
                $warnings[] = self::valueLossWarning(
                    self::sectionPath($pageSlug, (int) $i) . '.background',
                    $background,
                    'base',
                    "replaced unknown background for section '{$slug}' with the safe base surface",
                );
            }

            $density = trim((string) ($section['vertical_density'] ?? ''));
            if (!in_array($density, self::VERTICAL_DENSITIES, true)) {
                $sections[$i]['vertical_density'] = 'standard';
                $warnings[] = self::valueLossWarning(
                    self::sectionPath($pageSlug, (int) $i) . '.vertical_density',
                    $density,
                    'standard',
                    "replaced unknown vertical density for section '{$slug}' with standard",
                );
            }

            if (trim((string) ($section['type'] ?? '')) === '') {
                $sections[$i]['type'] = 'content';
                $warnings[] = self::valueLossWarning(
                    self::sectionPath($pageSlug, (int) $i) . '.type',
                    $section['type'] ?? null,
                    'content',
                    "replaced missing semantic type for section '{$slug}'",
                );
            }

            if (trim((string) ($section['handoff'] ?? '')) === '') {
                $above = $i === 0
                    ? 'the site header'
                    : '"' . trim((string) ($sections[$i - 1]['title'] ?? 'the previous section')) . '"';
                $below = $i === count($sections) - 1
                    ? 'the site footer'
                    : '"' . trim((string) ($sections[$i + 1]['title'] ?? 'the next section')) . '"';
                $sections[$i]['handoff'] = "Sits below {$above} and above {$below}.";
                $warnings[] = self::valueLossWarning(
                    self::sectionPath($pageSlug, (int) $i) . '.handoff',
                    $section['handoff'] ?? null,
                    $sections[$i]['handoff'],
                    "replaced missing handoff for section '{$slug}' with neutral delivered-neighbor facts",
                );
            }
        }

        return $sections;
    }

    /**
     * Last-resort mechanical fix for the variety rules on a raw section list:
     * an interior page's leading full-bleed cover, excess equal-card-grids,
     * and the later section of each adjacent duplicate pair are reassigned to
     * the first archetype that differs from both neighbors (never a card
     * grid, so the cap holds and no new grids appear); spacious pauses on
     * content-dense sections, adjacent to another pause, or beyond the cap
     * are demoted to 'standard'. The reassigned section's prose
     * (content_notes, handoff) may then lag its assignment slightly — an
     * accepted trade against aborting the whole build. Only touches VALID
     * values: run repairFields() first, which is the companion pass that makes
     * them valid, or enum and missing-field errors still reject in normalize().
     * Pure — unit-testable.
     *
     * @param mixed $raw
     * @param bool $front whether the page is the front page
     * @param array<string,mixed>|null $frontProjection
     * @param list<string> $warnings
     * @param list<string> $repairs
     * @param bool $allowOffsetGrid
     * @return array<int,array<string,mixed>>
     */
    public static function repairVariety(
        $raw,
        bool $front = true,
        ?array $frontProjection = null,
        array &$warnings = [],
        string $pageSlug = '',
        array &$repairs = [],
        bool $allowOffsetGrid = true,
    ): array {
        if (!is_array($raw)) {
            return [];
        }
        $raw = self::reconcileFrontHeroProjection(
            $raw,
            $front,
            $frontProjection,
            $repairs,
            $pageSlug,
        );
        $sections = array_values(array_filter($raw, 'is_array'));
        $archetypes = array_map(
            fn (array $s) => trim((string) ($s['layout_archetype'] ?? '')),
            $sections
        );
        $authoredArchetypes = $archetypes;

        $pick = function (int $i, string ...$exclude) use (&$archetypes, $allowOffsetGrid): string {
            return self::pickArchetype($archetypes, $i, $allowOffsetGrid, ...$exclude);
        };

        if (!$allowOffsetGrid) {
            foreach ($archetypes as $i => $archetype) {
                if ($archetype === 'offset-grid') {
                    $archetypes[$i] = $pick($i, 'offset-grid');
                }
            }
        }

        // Interior-opening pass: normalize() rejects an interior page whose
        // first section is a full-bleed cover, so demote it to a compact
        // archetype before the variety passes run.
        if (!$front && ($archetypes[0] ?? '') === 'full-bleed-cover') {
            $archetypes[0] = $pick(0, 'full-bleed-cover');
        }

        // Cap pass: keep the first MAX card grids, reassign the rest.
        $grids = 0;
        foreach ($archetypes as $i => $archetype) {
            if ($archetype === 'equal-card-grid' && ++$grids > self::MAX_EQUAL_CARD_GRIDS) {
                $archetypes[$i] = $pick($i);
            }
        }

        // Adjacency pass, left to right, judging only valid archetypes (like
        // varietyErrors). $pick avoids both neighbors, so a fix never breaks
        // the pair behind it or pre-duplicates the one ahead.
        foreach ($archetypes as $i => $archetype) {
            if ($i > 0 && $archetype === $archetypes[$i - 1]
                && in_array($archetype, self::ARCHETYPES, true)
            ) {
                $archetypes[$i] = $pick($i);
            }
        }

        foreach ($archetypes as $i => $archetype) {
            $sections[$i]['layout_archetype'] = $archetype;
            if ($archetype !== ($authoredArchetypes[$i] ?? $archetype)) {
                $path = self::sectionPath($pageSlug, (int) $i) . '.layout_archetype';
                $disposition = $front && $frontProjection !== null && $i === 1
                    ? 'changed the following section to preserve the locked hero projection and adjacency variety'
                    : 'mechanically repaired page-level composition variety';
                if ($front && $frontProjection !== null && $i === 1) {
                    $repairs[] = self::successfulRepair(
                        $path,
                        $authoredArchetypes[$i] ?? '',
                        $archetype,
                        $disposition,
                    );
                } else {
                    $warnings[] = self::valueLossWarning(
                        $path,
                        $authoredArchetypes[$i] ?? '',
                        $archetype,
                        $disposition,
                    );
                }
            }
        }

        // When the recipe-locked hero and its following section collided,
        // the following section—not the hero—moved. Replace only the now-stale
        // seam prose with neutral facts derived from the delivered assignment.
        if ($front && $frontProjection !== null
            && isset($sections[1])
            && ($authoredArchetypes[1] ?? null) !== ($archetypes[1] ?? null)
        ) {
            $heroTitle = trim((string) ($sections[0]['title'] ?? 'Hero')) ?: 'Hero';
            $nextTitle = trim((string) ($sections[1]['title'] ?? 'the following section'))
                ?: 'the following section';
            $heroHandoff = "Sits below the site header and above the {$sections[1]['background']} "
                . "{$sections[1]['layout_archetype']} section \"{$nextTitle}\".";
            $followingHandoff = "Sits below the {$sections[0]['background']} "
                . "{$sections[0]['layout_archetype']} front-page hero \"{$heroTitle}\""
                . (isset($sections[2])
                    ? " and above the {$sections[2]['background']} {$sections[2]['layout_archetype']} section."
                    : ' and above the site footer.');
            foreach ([0 => $heroHandoff, 1 => $followingHandoff] as $i => $deliveredHandoff) {
                $authoredHandoff = (string) ($sections[$i]['handoff'] ?? '');
                if ($authoredHandoff === $deliveredHandoff) {
                    continue;
                }
                $sections[$i]['handoff'] = $deliveredHandoff;
                $repairs[] = self::successfulRepair(
                    self::sectionPath($pageSlug, $i) . '.handoff',
                    $authoredHandoff,
                    $deliveredHandoff,
                    'replaced stale seam prose after following-section variety repair',
                );
            }
        }

        // Density passes, mirroring the varietyErrors rules: demote a
        // spacious pause on a content-dense section, the later of two
        // adjacent pauses, and any pause beyond the page cap to 'standard'.
        // Only valid 'spacious' values are touched; enum errors still reject.
        $densities = array_map(
            fn (array $s) => trim((string) ($s['vertical_density'] ?? '')),
            $sections
        );
        $authoredDensities = $densities;
        $spacious = 0;
        foreach ($densities as $i => $density) {
            if ($density !== 'spacious') {
                continue;
            }
            if (self::isDenseType(trim((string) ($sections[$i]['type'] ?? 'content')))
                || ($i > 0 && $densities[$i - 1] === 'spacious')
                || ++$spacious > self::MAX_SPACIOUS_SECTIONS
            ) {
                $densities[$i] = 'standard';
            }
        }
        foreach ($densities as $i => $density) {
            $sections[$i]['vertical_density'] = $density;
            if ($density !== ($authoredDensities[$i] ?? $density)) {
                $warnings[] = self::valueLossWarning(
                    self::sectionPath($pageSlug, (int) $i) . '.vertical_density',
                    $authoredDensities[$i] ?? '',
                    $density,
                    'mechanically repaired page-level density limits',
                );
            }
        }
        return $sections;
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @param list<string> $warnings
     * @return array<int,array<string,mixed>>
     */
    private static function restrictOffsetGrid(
        array $sections,
        bool $allowOffsetGrid,
        bool $front,
        array &$warnings,
        string $pageSlug,
    ): array {
        if ($allowOffsetGrid) {
            return $sections;
        }
        $archetypes = array_map(
            fn (array $s) => trim((string) ($s['layout_archetype'] ?? '')),
            $sections
        );
        foreach ($sections as $i => $section) {
            if ($archetypes[$i] !== 'offset-grid') {
                continue;
            }
            $exclude = ['offset-grid'];
            if (!$front && $i === 0) {
                $exclude[] = 'full-bleed-cover';
            }
            $replacement = self::pickArchetype($archetypes, (int) $i, false, ...$exclude);
            $archetypes[$i] = $replacement;
            $sections[$i]['layout_archetype'] = $replacement;
            $slug = trim((string) ($section['slug'] ?? '')) ?: "section-{$i}";
            $warnings[] = self::valueLossWarning(
                self::sectionPath($pageSlug, (int) $i) . '.layout_archetype',
                'offset-grid',
                $replacement,
                "replaced offset-grid for section '{$slug}' because staggered rows are reserved for photography and gallery sites",
            );
        }
        return $sections;
    }

    /**
     * @param list<string> $archetypes
     */
    private static function pickArchetype(
        array $archetypes,
        int $i,
        bool $allowOffsetGrid,
        string ...$exclude,
    ): string {
        foreach (self::ARCHETYPES as $candidate) {
            if ($candidate === 'equal-card-grid') {
                continue;
            }
            if (!$allowOffsetGrid && $candidate === 'offset-grid') {
                continue;
            }
            if (in_array($candidate, $exclude, true)) {
                continue;
            }
            if ($candidate === ($archetypes[$i - 1] ?? null)) {
                continue;
            }
            if ($candidate === ($archetypes[$i + 1] ?? null)) {
                continue;
            }
            return $candidate;
        }
        return $archetypes[$i];
    }

    /**
     * @param array<mixed> $siteSpec
     */
    private static function allowOffsetGridFor(Project $project, array $siteSpec): bool
    {
        $meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
        return PhotographySite::matches(
            $siteSpec,
            (string) ($meta['prompt'] ?? ''),
        );
    }

    /**
     * The page-level variety violations of the rules the prompt states: no
     * archetype on two adjacent sections, the equal card grid at most twice
     * per page, and no adjacent / excessive spacious-density pauses. Adjacency
     * is only judged between VALID values so an enum error above doesn't
     * cascade into a misleading adjacency error too.
     *
     * @param array<int,array<string,mixed>> $sections
     * @return string[]
     */
    private static function varietyErrors(array $sections): array
    {
        $errors = [];
        foreach ($sections as $i => $section) {
            if ($i === 0) {
                continue;
            }
            $prev = $sections[$i - 1];
            if ($section['layout_archetype'] === $prev['layout_archetype']
                && in_array($section['layout_archetype'], self::ARCHETYPES, true)
            ) {
                $errors[] = "page-plan: adjacent sections '{$prev['slug']}' and '{$section['slug']}' both use "
                    . "layout_archetype '{$section['layout_archetype']}' — adjacent sections must use different archetypes";
            }
            if ($section['vertical_density'] === 'spacious' && $prev['vertical_density'] === 'spacious') {
                $errors[] = "page-plan: adjacent sections '{$prev['slug']}' and '{$section['slug']}' both use "
                    . "vertical_density 'spacious' — spacious pauses must be isolated";
            }
        }

        $grids = count(array_filter($sections, fn (array $s) => $s['layout_archetype'] === 'equal-card-grid'));
        if ($grids > self::MAX_EQUAL_CARD_GRIDS) {
            $errors[] = "page-plan: 'equal-card-grid' is used {$grids} times — use it at most "
                . self::MAX_EQUAL_CARD_GRIDS . ' times per page and vary the other sections';
        }

        $spacious = count(array_filter($sections, fn (array $s) => $s['vertical_density'] === 'spacious'));
        if ($spacious > self::MAX_SPACIOUS_SECTIONS) {
            $errors[] = "page-plan: vertical_density 'spacious' is used {$spacious} times — use it at most "
                . self::MAX_SPACIOUS_SECTIONS . ' times per page and use standard/compact elsewhere';
        }
        return $errors;
    }

    /** Word-token match against DENSE_SECTION_TYPES for free-form model types. */
    private static function isDenseType(string $type): bool
    {
        $tokens = preg_split('/[^a-z]+/', strtolower($type), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_intersect($tokens, self::DENSE_SECTION_TYPES) !== [];
    }
}
