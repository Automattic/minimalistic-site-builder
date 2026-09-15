<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (LLM): decide which pages the site needs and what belongs on each.
 *
 * Extracted from Big Sky's `get-site-structure`, with four things its host
 * supplied replaced by explicit inputs: the available categories come from the
 * supplied inventory rather than a global catalogue, the prompt is rendered
 * from facts rather than expanded from an agent context, the model arrives as
 * an argument, and the chosen page-title pattern is returned here instead of
 * being written to blog metadata for a later request to find.
 *
 * The categories are the important one. The original could offer the model
 * every category the catalogue knew; here it may only offer what the
 * destination theme can actually build, which is the difference between a plan
 * that composes and a plan that fails at the next stage.
 *
 * Big Sky asks for this shape through a forced tool call and this asks through
 * a JSON schema. The instructions carry over; the rendered request does not,
 * and the two have not been compared.
 *
 * A page the host supplied as markup is not the model's to plan. It is carried
 * through in the requested order with no sections, so compose writes it as it
 * is; when every page is supplied the model is never asked.
 */
final class PlanSiteStep implements Step
{
    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
    ) {
    }

    public function id(): string
    {
        return 'plan-site';
    }

    public function label(): string
    {
        return 'Choose pages, sections and the shared-part policy';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [PatternArtifacts::NORMALIZED],
            writes: [PatternArtifacts::PLAN],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
        NormalizeInputsStep::assertVersion($inputs);

        $requested = $inputs['pages'] ?? [];
        $composed = array_values(array_filter($requested, static fn (array $p): bool => !NormalizeInputsStep::isSupplied($p)));

        // Every page arrived as markup: there is nothing to plan and no
        // reason to spend a model call finding that out.
        if ($requested !== [] && $composed === []) {
            $project->writeJson(PatternArtifacts::PLAN, ['pages' => self::order($requested, [])]);
            return;
        }

        $categories = self::categories($inputs['inventory'] ?? []);
        if ($categories === []) {
            throw new \RuntimeException(
                'No inventory pattern declares a category, so there is nothing to plan sections from.'
            );
        }

        $inventory = $inputs['inventory'] ?? [];
        $ids = self::ids($inventory);

        // A page that declares its own sections has already been given its
        // shape; the plan's job there is nothing. Only the pages that left the
        // shape open are put to the model, and a site where every page is
        // guided never makes the call at all.
        $guided = self::guided($composed);
        $open = array_values(array_filter(
            $composed,
            static fn (array $p): bool => !isset($guided[(string) $p['slug']]),
        ));

        // Only when the host asked for pages and declared every one of them.
        // A request that names no pages still needs the model to choose them.
        if ($composed !== [] && $open === []) {
            $project->writeJson(PatternArtifacts::PLAN, [
                'pages' => self::order($requested, array_values($guided)),
            ]);
            return;
        }

        $prompt = $this->renderer->render('pattern-site-plan.md', [
            'facts' => (string) json_encode($inputs['facts'] ?? [], JSON_PRETTY_PRINT),
            'categories' => implode("\n", array_map(static fn (string $c): string => '- ' . $c, $categories)),
            'patterns' => self::catalogue($inventory),
            'requested_pages' => $open === []
                ? 'None. Choose the pages yourself.'
                : (string) json_encode($open, JSON_PRETTY_PRINT),
            'notes' => trim((string) ($inputs['facts']['notes'] ?? '')) ?: 'None.',
        ]);

        $plan = $this->llm->completeJson($prompt, array_filter([
            'model' => $this->model,
            'json_schema' => ['name' => 'site_plan', 'schema' => self::schema($categories, $ids)],
            'max_tokens' => 8000,
        ]));

        $pages = self::pages($plan, $categories, $ids);

        // A reply that renamed its fields normalizes to nothing, and writing
        // that would hand the next stage an empty plan and call it a success.
        // Observed: a provider that ignores the schema this request carries
        // answered with `name` and `why`, and every page fell out here.
        if ($pages === []) {
            throw new \RuntimeException(
                "The plan came back with no usable pages. What arrived:\n"
                . substr((string) json_encode($plan), 0, 600)
            );
        }

        // A guided page keeps the shape the host declared, whatever the model
        // said about it.
        foreach ($pages as $index => $page) {
            $slug = (string) $page['slug'];
            if (isset($guided[$slug])) {
                $pages[$index]['sections'] = $guided[$slug]['sections'];
            }
        }
        foreach ($guided as $slug => $page) {
            if (!in_array($slug, array_column($pages, 'slug'), true)) {
                $pages[] = $page;
            }
        }

        $project->writeJson(PatternArtifacts::PLAN, [
            'pages' => $requested === [] ? $pages : self::order($requested, $pages),
        ]);
    }

    /**
     * The composed pages whose sections the host declared, keyed by slug.
     *
     * A declared section names a category, a pattern, or both. Composition
     * resolves a named pattern directly and falls back to the category, so a
     * Blueprint can pin an exact arrangement or ask for a kind of section and
     * let the theme's catalogue answer.
     *
     * @param list<array<string, mixed>> $composed
     * @return array<string, array<string, mixed>>
     */
    private static function guided(array $composed): array
    {
        $pages = [];

        foreach ($composed as $page) {
            $declared = $page['sections'] ?? null;
            if (!is_array($declared) || $declared === []) {
                continue;
            }

            $sections = [];
            foreach ($declared as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $entry = [
                    'category' => strtolower(trim((string) ($section['category'] ?? ''))),
                    'intent' => trim((string) ($section['intent'] ?? '')),
                    'reason' => 'the Blueprint asked for this section here',
                ];
                $pattern = trim((string) ($section['pattern'] ?? ''));
                if ($pattern !== '') {
                    $entry['pattern'] = $pattern;
                }
                if ($entry['intent'] === '') {
                    $entry['intent'] = $entry['category'] !== '' ? $entry['category'] : $pattern;
                }
                $sections[] = $entry;
            }

            if ($sections === []) {
                continue;
            }

            $slug = (string) $page['slug'];
            $pages[$slug] = [
                'slug' => $slug,
                'title' => (string) ($page['title'] ?? ucfirst($slug)),
                'description' => trim((string) ($page['intent'] ?? '')),
                'sections' => $sections,
            ];
        }

        return $pages;
    }

    /**
     * The plan in the order the host asked for, supplied pages included.
     *
     * The model was shown only the composed pages, so its answer is matched
     * back by slug. A composed page it left out is a hole in the site, and a
     * page it invented is not one the host asked for; the first stops the
     * build, the second is dropped.
     *
     * @param list<array<string, mixed>> $requested Settled pages, in order.
     * @param list<array<string, mixed>> $answered  What the model returned.
     * @return list<array<string, mixed>>
     */
    private static function order(array $requested, array $answered): array
    {
        $bySlug = [];
        foreach ($answered as $page) {
            $bySlug[(string) $page['slug']] = $page;
        }

        $pages = [];
        $missing = [];
        foreach ($requested as $order => $page) {
            $slug = (string) $page['slug'];
            $planned = $bySlug[$slug] ?? null;

            if (!NormalizeInputsStep::isSupplied($page) && $planned === null) {
                $missing[] = $slug;
                continue;
            }

            $pages[] = [
                'slug' => $slug,
                'title' => (string) $page['title'],
                'front' => $order === 0,
                'menu_order' => $order * 10,
                'description' => (string) ($planned['description'] ?? ''),
                'sections' => $planned['sections'] ?? [],
                'supplied' => NormalizeInputsStep::isSupplied($page),
            ];
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'The plan left out pages the host asked for: ' . implode(', ', $missing)
            );
        }

        return $pages;
    }

    /**
     * Pages as the next stage reads them, with anything the model invented
     * outside the inventory's vocabulary removed.
     *
     * A schema enum constrains the model but does not bind it, and a category
     * that slipped through would reach composition, match nothing, and fail
     * there — reported against the plan rather than against the answer that
     * produced it.
     *
     * @param array<string, mixed> $plan
     * @param list<string>         $categories
     * @return list<array<string, mixed>>
     */
    private static function pages(array $plan, array $categories, array $ids = []): array
    {
        $allowed = array_fill_keys($categories, true);
        $known = array_fill_keys($ids, true);
        $pages = [];
        $order = 0;

        foreach ($plan['pages'] ?? [] as $page) {
            $slug = trim((string) ($page['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $sections = [];
            foreach ($page['sections'] ?? [] as $section) {
                $category = strtolower(trim((string) ($section['category'] ?? '')));
                if (!isset($allowed[$category])) {
                    continue;
                }
                $entry = [
                    'category' => $category,
                    'intent' => (string) ($section['intent'] ?? $category),
                    'reason' => (string) ($section['reason'] ?? ''),
                ];
                // The pattern the model picked, when it picked one the host
                // actually supplied. An invented id is dropped rather than
                // carried to composition, which would fail there instead.
                $named = trim((string) ($section['pattern'] ?? ''));
                if ($named !== '' && isset($known[$named])) {
                    $entry['pattern'] = $named;
                }
                $sections[] = $entry;
            }

            $pages[] = [
                'slug' => $slug,
                'title' => (string) ($page['title'] ?? ucfirst($slug)),
                'front' => $order === 0,
                'menu_order' => $order * 10,
                'description' => (string) ($page['description'] ?? ''),
                'sections' => $sections,
            ];
            ++$order;
        }

        return $pages;
    }

    /**
     * Every category the supplied inventory can actually build.
     *
     * @param list<array<string, mixed>> $inventory
     * @return list<string>
     */
    private static function categories(array $inventory): array
    {
        $categories = [];
        foreach ($inventory as $pattern) {
            foreach ($pattern['categories'] ?? [] as $category) {
                $category = strtolower(trim((string) $category));
                if ($category !== '') {
                    $categories[$category] = true;
                }
            }
        }

        $names = array_keys($categories);
        sort($names);

        return $names;
    }

    /**
     * Every pattern id the host supplied, in inventory order.
     *
     * @param list<array<string, mixed>> $inventory
     * @return list<string>
     */
    private static function ids(array $inventory): array
    {
        $ids = [];
        foreach ($inventory as $pattern) {
            $id = trim((string) ($pattern['id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The inventory as the planner reads it: what each pattern is called and
     * what it is for. Planning against category names alone cannot tell a
     * hero from a pricing table, so the plan repeats itself and the site comes
     * out of one pattern per category however much the theme ships.
     *
     * @param list<array<string, mixed>> $inventory
     */
    private static function catalogue(array $inventory): string
    {
        $lines = [];
        foreach ($inventory as $pattern) {
            $id = trim((string) ($pattern['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $categories = array_values(array_filter(array_map(
                static fn ($c): string => strtolower(trim((string) $c)),
                $pattern['categories'] ?? [],
            )));
            $lines[] = sprintf(
                '- %s — %s [%s]',
                $id,
                trim((string) ($pattern['title'] ?? $id)) ?: $id,
                $categories === [] ? 'uncategorised' : implode(', ', $categories),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $categories
     * @param list<string> $ids
     * @return array<string, mixed>
     */
    private static function schema(array $categories, array $ids = []): array
    {
        return [
            'type' => 'object',
            'required' => ['pages'],
            'additionalProperties' => false,
            'properties' => [
                'pages' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['slug', 'title', 'description', 'sections'],
                        'additionalProperties' => false,
                        'properties' => [
                            'slug' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'sections' => [
                                'type' => 'array',
                                'minItems' => 1,
                                // No `maxItems`: the structured-output endpoint
                                // refuses it and fails the whole request, so the
                                // upper bound is asked for in the prompt instead.
                                'items' => [
                                    'type' => 'object',
                                    'required' => $ids === [] ? ['category', 'intent', 'reason'] : ['category', 'pattern', 'intent', 'reason'],
                                    'additionalProperties' => false,
                                    'properties' => array_filter([
                                        'category' => ['type' => 'string', 'enum' => $categories],
                                        'pattern' => $ids === [] ? null : ['type' => 'string', 'enum' => $ids],
                                        'intent' => ['type' => 'string'],
                                        'reason' => ['type' => 'string'],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
