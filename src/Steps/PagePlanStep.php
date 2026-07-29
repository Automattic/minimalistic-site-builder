<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\ConcurrentStep;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
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
 *         handoff } ] } ] }, a FLAT list in display order, parents before
 *         children.
 *
 * Each page's plan enriches the spec's purpose into concrete section briefs,
 * which the sections step then generates independently and in parallel and
 * the assemble step composes per page. Because the sections are built blind
 * to each other, this step is also each page's art director: it assigns every
 * section a layout archetype and background treatment (validated below, with
 * an adjacency rule) so every page has a deliberate visual rhythm.
 */
final class PagePlanStep implements ConcurrentStep
{
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
        . " sections so the page is richer and flows well. Let the design direction's signature device and mood"
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
        . " design direction's signature device and mood inform the section choices here too, and remember the"
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
            writes: ['pages.json'],
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
        $meta = $project->readJson('meta.json');
        $pages = self::flattenPages($project->readJson('siteSpec.json'));

        $shared = [
            'user_prompt'      => (string) ($meta['prompt'] ?? ''),
            'site_spec'        => $project->readText('siteSpec.json'),
            'language'         => SiteSpecStep::languageOf($project),
            'design_direction' => DesignDirectionStep::readFor($project),
            'site_pages'       => self::sitePagesList($pages),
        ];

        $requests = [];
        $jsonSchema = ['name' => 'page_plan', 'schema' => self::jsonSchema()];
        foreach ($pages as $page) {
            $requests[$page['slug']] = $this->withOptions([
                'prompt' => $this->renderer->render('page-plan.md', $shared + [
                    'page_title'    => (string) $page['title'],
                    'page_slug'     => (string) $page['slug'],
                    'page_purpose'  => (string) $page['purpose'],
                    'page_emphasis' => $page['front'] ? self::FRONT_EMPHASIS : self::INTERIOR_EMPHASIS,
                ]),
                'json_schema' => $jsonSchema,
            ]);
        }
        return $requests;
    }

    public function consume(Project $project, array $results): void
    {
        $pages = self::flattenPages($project->readJson('siteSpec.json'));

        // First pass: normalize what the model returned, collecting every page
        // that broke a rule so they can all be re-asked together below.
        $sectionsBySlug = [];
        $rejected = [];
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            $plan = $results[$slug] ?? null;
            if (!is_array($plan)) {
                throw new \RuntimeException("page-plan: missing model output for page '{$slug}'");
            }

            try {
                $sections = self::normalize($plan['sections'] ?? null, (bool) $page['front']);
                if ($sections === []) {
                    throw new \RuntimeException(
                        "page-plan: page '{$slug}' has no sections — return the full JSON object with a non-empty \"sections\" array"
                    );
                }
                $sectionsBySlug[$slug] = $sections;
            } catch (\RuntimeException $e) {
                $rejected[$slug] = ['plan' => $plan, 'errors' => $e->getMessage()];
            }
        }

        // The art-direction rules are creative constraints the model
        // occasionally violates. Re-ask ONCE per rejected page, all of them in
        // ONE batch; if a repair still breaks a rule, fix it mechanically
        // instead of aborting the build. recoverSections() is the backstop:
        // field + variety coercion for every known normalize rejection, and a
        // single fallback section if a future rule slips past both passes.
        // Only an empty section list still ends the page (nothing to coerce).
        $warnings = [];
        $frontBySlug = array_column($pages, 'front', 'slug');
        foreach ($this->repairAll($project, $rejected) as $slug => $repaired) {
            $slug = (string) $slug;
            $front = (bool) ($frontBySlug[$slug] ?? false);
            try {
                $sections = self::normalize($repaired['sections'] ?? null, $front);
            } catch (\RuntimeException $stillInvalid) {
                $sections = self::recoverSections(
                    $repaired['sections'] ?? null,
                    $front,
                    $warnings,
                    $slug
                );
            }
            if ($sections === []) {
                throw new \RuntimeException("page-plan: page '{$slug}' produced no sections");
            }
            $sectionsBySlug[$slug] = $sections;
        }

        // Every coercion changed delivered output, so it belongs in the durable
        // record rather than only in the narration, which is best-effort.
        $project->addWarnings($this->id(), $warnings);

        $out = [];
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            // A repair batch that answers fewer pages than it was asked about
            // must fail loud: assembling the page without sections would write
            // a null into pages.json and break far downstream instead.
            if (!isset($sectionsBySlug[$slug])) {
                throw new \RuntimeException("page-plan: page '{$slug}' produced no sections");
            }
            $page['sections'] = $sectionsBySlug[$slug];
            $out[] = $page;
        }

        $project->writeJson('pages.json', ['pages' => $out]);
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
            . 'violation is rejected too. '
            . 'If you change a section\'s layout_archetype, background, vertical_density, or position, also update its content_notes, '
            . 'handoff, and any affected neighbor handoffs so the prose matches the corrected assignment. '
            . 'Keep only fields that are still semantically consistent exactly as planned.';
    }

    public function run(Project $project): void
    {
        $this->consume($project, $this->llm->completeJsonBatch($this->requests($project)));
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
     * @return array<int,array<string,mixed>>
     */
    public static function normalize($raw, bool $front = true): array
    {
        if (!is_array($raw)) {
            return [];
        }

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
            ];
        }

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
     * Mechanical backstop after the LLM repair round still fails normalize().
     *
     * Runs repairFields() then repairVariety(), then normalize(). Those two
     * passes cover every rejection normalize() can raise today bar an empty
     * section list. If a future normalize rule slips past both, the residual
     * failure is recorded as a warning and the page gets a single known-good
     * section instead of aborting the build — one thin page is better than no
     * site after the rest of the pipeline has already been paid for.
     *
     * Empty input still returns [] so the caller can fail that page loud;
     * inventing sections from nothing is not this method's job.
     *
     * Pure — unit-testable.
     *
     * @param mixed $raw
     * @param list<string> $warnings appended to in place
     * @return array<int,array<string,mixed>>
     */
    public static function recoverSections($raw, bool $front, array &$warnings = [], string $pageSlug = ''): array
    {
        $mechanically = self::repairVariety(self::repairFields($raw, $warnings), $front);
        if ($mechanically === []) {
            return [];
        }
        return self::acceptRepairedSections($mechanically, $front, $warnings, $pageSlug);
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
     * @return array<int,array<string,mixed>>
     */
    public static function acceptRepairedSections(
        array $sections,
        bool $front,
        array &$warnings = [],
        string $pageSlug = ''
    ): array {
        try {
            return self::normalize($sections, $front);
        } catch (\RuntimeException $residual) {
            $where = $pageSlug !== '' ? "page '{$pageSlug}'" : 'page';
            $warnings[] = "page-plan: {$where}: mechanical repair left residual validation errors; "
                . 'delivered a single fallback section — ' . $residual->getMessage();
            return self::fallbackSections($front);
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
    public static function fallbackSections(bool $front = true): array
    {
        return self::normalize([
            [
                'slug'             => 'content',
                'title'            => 'Content',
                'type'             => 'content',
                'purpose'          => '',
                'content_notes'    => '',
                'layout_archetype' => $front ? 'full-bleed-cover' : 'centered-stack',
                'background'       => 'base',
                'vertical_density' => 'standard',
                'handoff'          => 'Sits below the site header and above the site footer.',
            ],
        ], $front);
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
     * @return array<int,array<string,mixed>>
     */
    public static function repairFields($raw, array &$warnings = []): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $sections = array_values(array_filter($raw, 'is_array'));

        // Neither of these is a safe landing spot for a value we are guessing:
        // a cover has its own interior-page rule and a grid has a cap.
        $candidates = array_values(array_diff(self::ARCHETYPES, ['full-bleed-cover', 'equal-card-grid']));

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
                $warnings[] = "page-plan: section '{$slug}': unknown layout_archetype '{$was}' "
                    . "replaced with '{$replacement}'";
            }

            $background = trim((string) ($section['background'] ?? ''));
            if (!in_array($background, self::BACKGROUNDS, true)) {
                $sections[$i]['background'] = 'base';
                $warnings[] = "page-plan: section '{$slug}': unknown background '{$background}' "
                    . "replaced with 'base'";
            }

            $density = trim((string) ($section['vertical_density'] ?? ''));
            if (!in_array($density, self::VERTICAL_DENSITIES, true)) {
                $sections[$i]['vertical_density'] = 'standard';
                $warnings[] = "page-plan: section '{$slug}': unknown vertical_density '{$density}' "
                    . "replaced with 'standard'";
            }

            if (trim((string) ($section['type'] ?? '')) === '') {
                $sections[$i]['type'] = 'content';
                $warnings[] = "page-plan: section '{$slug}': missing type; defaulted to 'content'";
            }

            if (trim((string) ($section['handoff'] ?? '')) === '') {
                $above = $i === 0
                    ? 'the site header'
                    : '"' . trim((string) ($sections[$i - 1]['title'] ?? 'the previous section')) . '"';
                $below = $i === count($sections) - 1
                    ? 'the site footer'
                    : '"' . trim((string) ($sections[$i + 1]['title'] ?? 'the next section')) . '"';
                $sections[$i]['handoff'] = "Sits below {$above} and above {$below}.";
                $warnings[] = "page-plan: section '{$slug}': missing handoff; "
                    . 'described from its neighbours in the plan';
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
     * @return array<int,array<string,mixed>>
     */
    public static function repairVariety($raw, bool $front = true): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $sections = array_values(array_filter($raw, 'is_array'));
        $archetypes = array_map(
            fn (array $s) => trim((string) ($s['layout_archetype'] ?? '')),
            $sections
        );

        $pick = function (int $i, string ...$exclude) use (&$archetypes): string {
            foreach (self::ARCHETYPES as $candidate) {
                if ($candidate !== 'equal-card-grid'
                    && !in_array($candidate, $exclude, true)
                    && $candidate !== ($archetypes[$i - 1] ?? null)
                    && $candidate !== ($archetypes[$i + 1] ?? null)
                ) {
                    return $candidate;
                }
            }
            return $archetypes[$i]; // unreachable: 6 non-grid archetypes vs 2 neighbors + 1 exclusion
        };

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
        }

        // Density passes, mirroring the varietyErrors rules: demote a
        // spacious pause on a content-dense section, the later of two
        // adjacent pauses, and any pause beyond the page cap to 'standard'.
        // Only valid 'spacious' values are touched; enum errors still reject.
        $densities = array_map(
            fn (array $s) => trim((string) ($s['vertical_density'] ?? '')),
            $sections
        );
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
        }
        return $sections;
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
