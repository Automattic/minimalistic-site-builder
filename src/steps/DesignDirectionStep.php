<?php
declare(strict_types=1);

/**
 * Step 4 (LLM): turn the abstract spec into a concrete creative direction.
 *
 * Input:  siteSpec.json
 * Output: designDirection.json — actionable palette/type/spacing/component
 *         decisions that constrain the coding steps that follow.
 */
final class DesignDirectionStep implements Step
{
    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
    ) {}

    public function id(): string
    {
        return 'design-direction';
    }

    public function label(): string
    {
        return 'Create design direction';
    }

    public function run(Project $project): void
    {
        $spec = $project->readJson('siteSpec.json');
        $rendered = $this->renderer->render('design-direction.md', [
            'site_spec' => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $direction = $this->llm->completeJson($rendered);

        if (trim((string) ($direction['concept'] ?? '')) === '') {
            throw new RuntimeException('design direction has no "concept"');
        }

        $project->writeJson('designDirection.json', $direction);
    }
}
