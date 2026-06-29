<?php
declare(strict_types=1);

/**
 * Step (LLM, concurrent): generate every landing-page part in ONE batch — the
 * header, the footer, and one template part per planned section — fired together
 * instead of one giant landing-page call.
 *
 * Input:  siteSpec.json + theme/theme.json + sections.json (the plan).
 * Output: theme/parts/header.html, theme/parts/footer.html, and
 *         theme/parts/section-<slug>.html for each planned section.
 *
 * Each section is generated independently with the full section list as context
 * (for coherence) plus its own brief, so the model focuses on one section at a
 * time and they all run concurrently. The assemble step then composes them.
 * Image placeholders use the same AI_IMAGE convention collect-images parses.
 */
final class SectionsStep implements ConcurrentStep
{
    /** Prefix for a section part's request key, filename, and template-part slug. */
    public const SECTION_PREFIX = 'section-';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
    ) {}

    public function id(): string
    {
        return 'sections';
    }

    public function label(): string
    {
        return 'Build landing-page sections';
    }

    public function requests(Project $project): array
    {
        $siteSpec = $project->readText('siteSpec.json');
        $themeJson = $project->readText('theme/theme.json');
        $sections = self::sections($project);

        // A compact outline of the whole page, so each section knows its place.
        $outline = self::outline($sections);

        $requests = [
            'header' => $this->req($this->renderer->render('header.md', [
                'site_spec'  => $siteSpec,
                'theme_json' => $themeJson,
                'outline'    => $outline,
            ])),
            'footer' => $this->req($this->renderer->render('footer.md', [
                'site_spec'  => $siteSpec,
                'theme_json' => $themeJson,
                'outline'    => $outline,
            ])),
        ];

        foreach ($sections as $section) {
            $key = self::SECTION_PREFIX . $section['slug'];
            $requests[$key] = $this->req($this->renderer->render('section.md', [
                'site_spec'     => $siteSpec,
                'theme_json'    => $themeJson,
                'outline'       => $outline,
                'section_title' => (string) ($section['title'] ?? ''),
                'section_type'  => (string) ($section['type'] ?? 'content'),
                'section_purpose' => (string) ($section['purpose'] ?? ''),
                'content_notes' => (string) ($section['content_notes'] ?? ''),
                'wants_image'   => ($section['wants_image'] ?? false) ? 'yes' : 'no',
            ]));
        }

        return $requests;
    }

    public function consume(Project $project, array $results): void
    {
        foreach ($results as $key => $data) {
            $rel = match (true) {
                $key === 'header' => 'parts/header.html',
                $key === 'footer' => 'parts/footer.html',
                default           => 'parts/' . $key . '.html', // section-<slug>
            };
            $markup = self::markup($data, $key);
            $project->writeText('theme/' . $rel, $markup . "\n");
        }
    }

    public function run(Project $project): void
    {
        $this->consume($project, $this->llm->completeJsonBatch($this->requests($project)));
    }

    /**
     * Pull and validate the planned section list from sections.json.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function sections(Project $project): array
    {
        $plan = $project->readJson('sections.json');
        $sections = $plan['sections'] ?? null;
        if (!is_array($sections) || $sections === []) {
            throw new RuntimeException('sections: sections.json has no sections (run section-plan first)');
        }
        return $sections;
    }

    /**
     * A one-line-per-section outline string used to give every part the same
     * view of the page. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function outline(array $sections): string
    {
        $lines = [];
        foreach ($sections as $n => $s) {
            $title = (string) ($s['title'] ?? '');
            $type = (string) ($s['type'] ?? '');
            $lines[] = ($n + 1) . ". {$title} ({$type})";
        }
        return implode("\n", $lines);
    }

    /**
     * Extract and validate one part's block markup from its model output. The
     * model returns { "markup": "<!-- wp:... -->" }. Pure — unit-testable.
     *
     * @param array<mixed> $data
     */
    public static function markup(array $data, string $key): string
    {
        $markup = $data['markup'] ?? null;
        if (!is_string($markup) || trim($markup) === '') {
            throw new RuntimeException("sections: part '{$key}' has no markup");
        }
        if (!str_contains($markup, 'wp:')) {
            throw new RuntimeException("sections: part '{$key}' is not block markup");
        }
        return rtrim($markup);
    }

    /**
     * Build one batch request, attaching the per-step model override only when
     * configured. Shared shape across every concurrent LLM step.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function req(string $prompt, array $extra = []): array
    {
        $req = ['prompt' => $prompt] + $extra;
        if ($this->model !== null) {
            $req['model'] = $this->model;
        }
        return $req;
    }
}
