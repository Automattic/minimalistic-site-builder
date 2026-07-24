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

        $out = [];
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            $front = (bool) $page['front'];
            $plan = $results[$slug] ?? null;
            if (!is_array($plan)) {
                throw new \RuntimeException("page-plan: missing model output for page '{$slug}'");
            }

            try {
                $sections = self::normalize($plan['sections'] ?? null, $front);
                if ($sections === []) {
                    throw new \RuntimeException(
                        "page-plan: page '{$slug}' has no sections — return the full JSON object with a non-empty \"sections\" array"
                    );
                }
            } catch (\RuntimeException $e) {
                // The art-direction rules (adjacency, enums, card-grid cap,
                // interior opening) are creative constraints the model
                // occasionally violates. Re-ask ONCE per page with the
                // specific rejections; if the repair still breaks the variety
                // rules, reassign the offending archetypes mechanically
                // instead of aborting the build.
                $repaired = $this->repair($project, $slug, $plan, $e->getMessage());
                try {
                    $sections = self::normalize($repaired['sections'] ?? null, $front);
                } catch (\RuntimeException $stillInvalid) {
                    $sections = self::normalize(self::repairVariety($repaired['sections'] ?? null, $front), $front);
                }
                if ($sections === []) {
                    throw new \RuntimeException("page-plan: page '{$slug}' produced no sections");
                }
            }

            $page['sections'] = $sections;
            $out[] = $page;
        }

        $project->writeJson('pages.json', ['pages' => $out]);
    }

    /**
     * One-shot repair call for one page: its original prompt + the rejected
     * plan + every validation error, asking for a corrected full plan.
     *
     * @param array<mixed> $plan
     * @return array<mixed>
     */
    private function repair(Project $project, string $pageSlug, array $plan, string $errors): array
    {
        $prompt = $this->requests($project)[$pageSlug]['prompt']
            . "\n\nYOUR PREVIOUS PLAN (JSON):\n"
            . json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nIT WAS REJECTED FOR THESE REASONS:\n{$errors}\n"
            . "\nReturn the corrected full JSON object. Fix EVERY rejection above. "
            . 'For an adjacent-duplicate rejection, change only ONE of the two sections, and re-check the whole '
            . 'corrected list top-to-bottom against every rule before returning — a repair that introduces a NEW '
            . 'violation is rejected too. '
            . 'If you change a section\'s layout_archetype, background, vertical_density, or position, also update its content_notes, '
            . 'handoff, and any affected neighbor handoffs so the prose matches the corrected assignment. '
            . 'Keep only fields that are still semantically consistent exactly as planned.';

        return $this->llm->completeJson($prompt, $this->withOptions([
            'log_label'   => $this->id() . "-{$pageSlug}-repair",
            'json_schema' => ['name' => 'page_plan', 'schema' => self::jsonSchema()],
        ]));
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
     * Last-resort mechanical fix for the variety rules on a raw section list:
     * an interior page's leading full-bleed cover, excess equal-card-grids,
     * and the later section of each adjacent duplicate pair are reassigned to
     * the first archetype that differs from both neighbors (never a card
     * grid, so the cap holds and no new grids appear); spacious pauses on
     * content-dense sections, adjacent to another pause, or beyond the cap
     * are demoted to 'standard'. The reassigned section's prose
     * (content_notes, handoff) may then lag its assignment slightly — an
     * accepted trade against aborting the whole build. Only touches VALID
     * values; enum and handoff errors still reject the plan in normalize().
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
