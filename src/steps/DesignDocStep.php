<?php
declare(strict_types=1);

/**
 * Step 5 (LLM): consolidate spec + direction into a human-readable design doc.
 *
 * Input:  siteSpec.json + designDirection.json
 * Output: design.md — the single narrative the remaining build steps reference.
 */
final class DesignDocStep implements Step
{
    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
    ) {}

    public function id(): string
    {
        return 'design-doc';
    }

    public function label(): string
    {
        return 'Write design document';
    }

    public function run(Project $project): void
    {
        $spec = $project->readJson('siteSpec.json');
        $direction = $project->readJson('designDirection.json');

        $rendered = $this->renderer->render('design-doc.txt', [
            'site_spec'        => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'design_direction' => json_encode($direction, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $md = self::stripFences($this->llm->complete($rendered));

        if (strlen(trim($md)) < 200) {
            throw new RuntimeException('design.md is suspiciously short: ' . $md);
        }

        $project->writeText('design.md', rtrim($md) . "\n");
    }

    private static function stripFences(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = preg_replace('/\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }
}
