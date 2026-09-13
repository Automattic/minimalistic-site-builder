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

        $categories = self::categories($inputs['inventory'] ?? []);
        if ($categories === []) {
            throw new \RuntimeException(
                'No inventory pattern declares a category, so there is nothing to plan sections from.'
            );
        }

        $requested = $inputs['pages'] ?? [];

        $prompt = $this->renderer->render('pattern-site-plan.md', [
            'facts' => (string) json_encode($inputs['facts'] ?? [], JSON_PRETTY_PRINT),
            'categories' => implode("\n", array_map(static fn (string $c): string => '- ' . $c, $categories)),
            'requested_pages' => $requested === []
                ? 'None. Choose the pages yourself.'
                : (string) json_encode($requested, JSON_PRETTY_PRINT),
            'notes' => trim((string) ($inputs['facts']['notes'] ?? '')) ?: 'None.',
        ]);

        $plan = $this->llm->completeJson($prompt, array_filter([
            'model' => $this->model,
            'json_schema' => ['name' => 'site_plan', 'schema' => self::schema($categories)],
            'max_tokens' => 4000,
        ]));

        $pages = self::pages($plan, $categories);

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

        $project->writeJson(PatternArtifacts::PLAN, ['pages' => $pages]);
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
    private static function pages(array $plan, array $categories): array
    {
        $allowed = array_fill_keys($categories, true);
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
                if (isset($allowed[$category])) {
                    $sections[] = [
                        'category' => $category,
                        'intent' => (string) ($section['intent'] ?? $category),
                        'reason' => (string) ($section['reason'] ?? ''),
                    ];
                }
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
     * @param list<string> $categories
     * @return array<string, mixed>
     */
    private static function schema(array $categories): array
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
                                'maxItems' => 8,
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['category', 'intent', 'reason'],
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'category' => ['type' => 'string', 'enum' => $categories],
                                        'intent' => ['type' => 'string'],
                                        'reason' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
