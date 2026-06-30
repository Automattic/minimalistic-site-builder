<?php
declare(strict_types=1);

/**
 * Step (LLM, concurrent): plan the landing page as an ordered list of sections.
 *
 * Input:  meta.json (user prompt) + siteSpec.json. Runs alongside theme-json
 *         (both depend only on the brief + spec) in a ConcurrentGroup.
 * Output: sections.json — { "sections": [ { slug, title, type, purpose,
 *         content_notes, wants_image } ] }, in display order.
 *
 * This enriches siteSpec's flat "sections" string list into a concrete brief per
 * section, which the sections step then generates independently and in parallel,
 * and the assemble step composes in this order.
 */
final class SectionPlanStep implements ConcurrentStep
{
    use ModelOption;

    private const REQ = 'section-plan';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'section-plan';
    }

    public function label(): string
    {
        return 'Plan landing-page sections';
    }

    public function requests(Project $project): array
    {
        $meta = $project->readJson('meta.json');
        $rendered = $this->renderer->render('section-plan.md', [
            'user_prompt'      => (string) ($meta['prompt'] ?? ''),
            'site_spec'        => $project->readText('siteSpec.json'),
            'design_direction' => DesignDirectionStep::readFor($project),
        ]);

        return [self::REQ => $this->withModel(['prompt' => $rendered])];
    }

    public function consume(Project $project, array $results): void
    {
        $plan = $results[self::REQ] ?? null;
        if (!is_array($plan)) {
            throw new RuntimeException('section-plan: missing model output');
        }

        $sections = self::normalize($plan['sections'] ?? null);
        if ($sections === []) {
            throw new RuntimeException('section-plan produced no sections');
        }

        $project->writeJson('sections.json', ['sections' => $sections]);
    }

    public function run(Project $project): void
    {
        $this->consume($project, $this->llm->completeJsonBatch($this->requests($project)));
    }

    /**
     * Validate the section list and force unique, file-safe slugs. Each section
     * keeps its model-provided fields; missing optional fields default benignly
     * so the sections + assemble steps can rely on the keys. Pure — unit-testable.
     *
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     */
    public static function normalize($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $i => $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = trim((string) ($section['title'] ?? ''));
            $slug = ProjectStore::slugify((string) ($section['slug'] ?? $title ?: "section-{$i}"));
            if ($slug === '') {
                $slug = 'section-' . count($out);
            }
            // Keep slugs unique so part filenames and template-part slugs collide-free.
            $base = $slug;
            $n = 2;
            while (isset($seen[$slug])) {
                $slug = "{$base}-{$n}";
                $n++;
            }
            $seen[$slug] = true;

            $out[] = [
                'slug'          => $slug,
                'title'         => $title !== '' ? $title : ucwords(str_replace('-', ' ', $slug)),
                'type'          => trim((string) ($section['type'] ?? 'content')),
                'purpose'       => trim((string) ($section['purpose'] ?? '')),
                'content_notes' => trim((string) ($section['content_notes'] ?? '')),
                'wants_image'   => (bool) ($section['wants_image'] ?? false),
            ];
        }
        return $out;
    }
}
