<?php
declare(strict_types=1);

/**
 * Step 2 (LLM): produce the site spec from the user's creation prompt.
 *
 * Input:  meta.json (the user prompt, seeded by the runner)
 * Output: siteSpec.json — name, slug, and all look-and-feel characteristics.
 *
 * This is the only step that reads the raw user prompt; everything downstream
 * derives from siteSpec.json.
 */
final class SiteSpecStep implements Step
{
    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
    ) {}

    public function id(): string
    {
        return 'site-spec';
    }

    public function label(): string
    {
        return 'Generate site spec';
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $prompt = (string) ($meta['prompt'] ?? '');
        if (trim($prompt) === '') {
            throw new RuntimeException('meta.json has no "prompt"');
        }

        $rendered = $this->renderer->render('site-spec.md', ['user_prompt' => $prompt]);
        $spec = $this->llm->completeJson($rendered);

        $spec = self::normalize($spec);
        $project->writeJson('siteSpec.json', $spec);
    }

    /**
     * Validate required fields and fill safe fallbacks so downstream steps can
     * rely on the shape.
     *
     * @param array<mixed> $spec
     * @return array<mixed>
     */
    private static function normalize(array $spec): array
    {
        $name = trim((string) ($spec['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('site spec has no "name"');
        }

        $slug = ProjectStore::slugify((string) ($spec['slug'] ?? $name));
        $spec['name'] = $name;
        $spec['slug'] = $slug;

        if (trim((string) ($spec['description'] ?? '')) === '') {
            $spec['description'] = $name;
        }

        // Colors must exist with sane defaults so the theme always renders.
        $colors = is_array($spec['colors'] ?? null) ? $spec['colors'] : [];
        $spec['colors'] = $colors + [
            'mood'       => '',
            'primary'    => '#222222',
            'secondary'  => '#666666',
            'background' => '#ffffff',
            'text'       => '#111111',
            'accent'     => '#0a7cff',
        ];

        $typo = is_array($spec['typography'] ?? null) ? $spec['typography'] : [];
        $spec['typography'] = $typo + [
            'mood'    => '',
            'heading' => 'Georgia',
            'body'    => 'Helvetica',
        ];

        foreach (['tone', 'pages', 'key_sections'] as $listKey) {
            if (!isset($spec[$listKey]) || !is_array($spec[$listKey])) {
                $spec[$listKey] = [];
            }
        }

        return $spec;
    }
}
