<?php
declare(strict_types=1);

/**
 * Step (LLM): make the design decisions and record them as design.md.
 *
 * Input:  meta.json (user prompt) + siteSpec.json (factual info only)
 * Output: design.md — the single source of design truth, written to the
 *         DESIGN.md standard (https://github.com/google-labs-code/design.md):
 *         YAML front matter with design tokens (colors, typography, spacing,
 *         rounded) followed by a Markdown body of rationale.
 *
 * This is where palette/typography/spacing/components are decided — the site
 * spec (#1) intentionally carries no design, and the old design-direction step
 * (#2) is gone, so design.md is the only design artifact downstream steps read.
 */
final class DesignDocStep implements Step
{
    /** Markdown sections the DESIGN.md standard expects (canonical order). */
    private const REQUIRED_SECTIONS = ['Overview', 'Colors', 'Typography'];

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
        $meta = $project->readJson('meta.json');
        $prompt = (string) ($meta['prompt'] ?? '');
        if (trim($prompt) === '') {
            throw new RuntimeException('meta.json has no "prompt"');
        }
        $spec = $project->readJson('siteSpec.json');

        $rendered = $this->renderer->render('design-doc.md', [
            'user_prompt' => $prompt,
            'site_spec'   => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $md = self::stripFences($this->llm->complete($rendered, ['max_tokens' => 8000]));

        self::assertConformant($md);

        $project->writeText('design.md', rtrim($md) . "\n");
    }

    /**
     * Sanity-check that the output is a substantive DESIGN.md: YAML front matter
     * with the color/typography tokens downstream steps rely on, and the
     * standard's core Markdown sections.
     */
    private static function assertConformant(string $md): void
    {
        if (strlen(trim($md)) < 200) {
            throw new RuntimeException('design.md is suspiciously short: ' . $md);
        }

        $frontMatter = self::frontMatter($md);
        if ($frontMatter === null) {
            throw new RuntimeException('design.md is missing its YAML front matter (--- fences)');
        }
        foreach (['base', 'contrast', 'primary', 'secondary', 'accent'] as $token) {
            if (!preg_match('/^\s*' . $token . '\s*:/mi', $frontMatter)) {
                throw new RuntimeException("design.md front matter missing color token: {$token}");
            }
        }
        foreach (['heading', 'body'] as $token) {
            if (!preg_match('/^\s*' . $token . '\s*:/mi', $frontMatter)) {
                throw new RuntimeException("design.md front matter missing typography token: {$token}");
            }
        }

        foreach (self::REQUIRED_SECTIONS as $section) {
            if (!preg_match('/^##\s+' . preg_quote($section, '/') . '\b/mi', $md)) {
                throw new RuntimeException("design.md missing required section: ## {$section}");
            }
        }
    }

    /** The YAML front-matter block (between the leading --- fences), or null. */
    private static function frontMatter(string $md): ?string
    {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*(\n|$)/s', $md, $m)) {
            return null;
        }
        return $m[1];
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
