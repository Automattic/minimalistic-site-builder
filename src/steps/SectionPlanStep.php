<?php
declare(strict_types=1);

/**
 * Step (LLM, concurrent): plan the landing page as an ordered list of sections.
 *
 * Input:  meta.json (user prompt) + siteSpec.json. Runs alongside theme-json
 *         (both depend only on the brief + spec) in a ConcurrentGroup.
 * Output: sections.json — { "sections": [ { slug, title, type, purpose,
 *         content_notes, layout_archetype, background, handoff } ] }, in
 *         display order.
 *
 * This enriches siteSpec's flat "sections" string list into a concrete brief per
 * section, which the sections step then generates independently and in parallel,
 * and the assemble step composes in this order. Because the sections are built
 * blind to each other, this step is also the page's art director: it assigns
 * each section a layout archetype and background treatment (validated below,
 * with an adjacency rule) so the page has a deliberate visual rhythm.
 */
final class SectionPlanStep implements ConcurrentStep
{
    use LlmOptions;

    private const REQ = 'section-plan';

    /** Composition menu — must match the archetypes offered in section-plan.md. */
    public const ARCHETYPES = [
        'full-bleed-cover',
        'asymmetric-split',
        'centered-stack',
        'offset-grid',
        'mixed-width-editorial',
        'equal-card-grid',
        'list-with-thumbnails',
    ];

    /** Background treatments — must match section-plan.md. */
    public const BACKGROUNDS = ['base', 'tinted', 'contrast', 'image'];

    /** The most default-looking archetype is capped so it can't dominate the page. */
    private const MAX_EQUAL_CARD_GRIDS = 2;

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

        return [self::REQ => $this->withOptions(['prompt' => $rendered])];
    }

    public function consume(Project $project, array $results): void
    {
        $plan = $results[self::REQ] ?? null;
        if (!is_array($plan)) {
            throw new RuntimeException('section-plan: missing model output');
        }

        try {
            $sections = self::normalize($plan['sections'] ?? null);
            if ($sections === []) {
                throw new RuntimeException(
                    'section-plan: the plan has no sections — return the full JSON object with a non-empty "sections" array'
                );
            }
        } catch (RuntimeException $e) {
            // The art-direction rules (adjacency, enums, card-grid cap) are
            // creative constraints the model occasionally violates. Re-ask ONCE
            // with the specific rejections; a still-invalid repair aborts the build.
            $repaired = $this->repair($project, $plan, $e->getMessage());
            $sections = self::normalize($repaired['sections'] ?? null);
            if ($sections === []) {
                throw new RuntimeException('section-plan produced no sections');
            }
        }

        $project->writeJson('sections.json', ['sections' => $sections]);
    }

    /**
     * One-shot repair call: original prompt + the rejected plan + every
     * validation error, asking for a corrected full plan.
     *
     * @param array<mixed> $plan
     * @return array<mixed>
     */
    private function repair(Project $project, array $plan, string $errors): array
    {
        $prompt = $this->requests($project)[self::REQ]['prompt']
            . "\n\nYOUR PREVIOUS PLAN (JSON):\n"
            . json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nIT WAS REJECTED FOR THESE REASONS:\n{$errors}\n"
            . "\nReturn the corrected full JSON object. Fix EVERY rejection above. "
            . 'If you change a section\'s layout_archetype, background, or position, also update its content_notes, '
            . 'handoff, and any affected neighbor handoffs so the prose matches the corrected assignment. '
            . 'Keep only fields that are still semantically consistent exactly as planned.';

        return $this->llm->completeJson($prompt, $this->withOptions(['log_label' => self::REQ . '-repair']));
    }

    public function run(Project $project): void
    {
        $this->consume($project, $this->llm->completeJsonBatch($this->requests($project)));
    }

    /**
     * Validate the section list and force unique, file-safe slugs. Each section
     * keeps its model-provided fields; missing optional fields default benignly
     * so the sections + assemble steps can rely on the keys. The art-direction
     * fields (layout_archetype, background, handoff) are strict: unknown values,
     * a missing handoff, adjacent duplicate archetypes, or too many card grids
     * are collected and thrown together in ONE message, so the single repair
     * call sees every violation at once. Pure — unit-testable.
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
        $errors = [];
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

            $archetype = trim((string) ($section['layout_archetype'] ?? ''));
            if (!in_array($archetype, self::ARCHETYPES, true)) {
                $errors[] = "section-plan: section '{$slug}' has invalid layout_archetype '{$archetype}' — use one of: "
                    . implode(', ', self::ARCHETYPES);
            }
            $background = trim((string) ($section['background'] ?? ''));
            if (!in_array($background, self::BACKGROUNDS, true)) {
                $errors[] = "section-plan: section '{$slug}' has invalid background '{$background}' — use one of: "
                    . implode(', ', self::BACKGROUNDS);
            }
            $handoff = trim((string) ($section['handoff'] ?? ''));
            if ($handoff === '') {
                $errors[] = "section-plan: section '{$slug}' is missing 'handoff' — describe what sits immediately above and below it";
            }

            $out[] = [
                'slug'             => $slug,
                'title'            => $title !== '' ? $title : ucwords(str_replace('-', ' ', $slug)),
                'type'             => trim((string) ($section['type'] ?? 'content')),
                'purpose'          => trim((string) ($section['purpose'] ?? '')),
                'content_notes'    => trim((string) ($section['content_notes'] ?? '')),
                'layout_archetype' => $archetype,
                'background'       => $background,
                'handoff'          => $handoff,
            ];
        }

        // Report every violation at once so the single repair call can fix them all.
        $errors = array_merge($errors, self::varietyErrors($out));
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }
        return $out;
    }

    /**
     * The page-level variety violations of the rules the prompt states: no
     * archetype on two adjacent sections, and the equal card grid at most twice
     * per page. Adjacency is only judged between VALID archetypes so an enum
     * error above doesn't cascade into a misleading adjacency error too.
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
                $errors[] = "section-plan: adjacent sections '{$prev['slug']}' and '{$section['slug']}' both use "
                    . "layout_archetype '{$section['layout_archetype']}' — adjacent sections must use different archetypes";
            }
        }

        $grids = count(array_filter($sections, fn (array $s) => $s['layout_archetype'] === 'equal-card-grid'));
        if ($grids > self::MAX_EQUAL_CARD_GRIDS) {
            $errors[] = "section-plan: 'equal-card-grid' is used {$grids} times — use it at most "
                . self::MAX_EQUAL_CARD_GRIDS . ' times per page and vary the other sections';
        }
        return $errors;
    }
}
