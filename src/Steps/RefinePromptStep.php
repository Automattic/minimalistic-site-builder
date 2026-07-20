<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step 1b (LLM, fast/cheap): refine the raw user prompt before anything else.
 *
 * Input:  meta.json (the user prompt, seeded by the runner)
 * Output: meta.json with `prompt` replaced by an improved, build-ready brief,
 *         and the untouched original preserved under `original_prompt`.
 *
 * Users often type something short or vague ("a bakery", "portfolio site").
 * This step runs on a small, fast model to expand such prompts into a clear,
 * self-contained website brief and to normalize already-detailed prompts into
 * the shape the rest of the pipeline builds best from — WITHOUT changing the
 * site's intent, type, topic, language, or any fact the user stated. Every
 * later step (site-spec onward) reads `meta.json`'s `prompt`, so improving it
 * here lifts the quality of the whole build with no downstream changes.
 *
 * Deliberately forgiving: if the model returns nothing usable, the original
 * prompt is kept and the build proceeds. A weak refinement must never be able
 * to block a site from being built.
 */
final class RefinePromptStep implements Step
{
    use LlmOptions;

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'refine-prompt';
    }

    public function label(): string
    {
        return 'Refine user prompt';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json'],
            writes: ['meta.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $prompt = trim((string) ($meta['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \RuntimeException('meta.json has no "prompt"');
        }

        $rendered = $this->renderer->render('refine-prompt.md', ['user_prompt' => $prompt]);

        // The improved brief IS the whole response — a single paragraph of text,
        // so ask for it verbatim (no JSON wrapper to escape, parse, or fail on).
        // Never let a refinement failure block the build: on any error, or an
        // empty/garbage response, fall back to the original prompt untouched.
        try {
            $improved = trim($this->llm->complete($rendered, $this->withOptions(['log_label' => $this->id()])));
        } catch (\Throwable $e) {
            $improved = '';
        }

        if ($improved === '' || $improved === $prompt) {
            return;
        }

        // Preserve the raw user input; hand the improved brief to every later step.
        $meta['original_prompt'] = $prompt;
        $meta['prompt'] = $improved;
        $project->writeJson('meta.json', $meta);
    }
}
