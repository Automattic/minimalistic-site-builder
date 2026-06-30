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
 *
 * Each part's response IS the block markup (raw text, via completeBatch) — not
 * JSON-wrapped — so the model never has to escape its HTML into a JSON string.
 */
final class SectionsStep implements Step
{
    use ModelOption;

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

        // The AI_IMAGE authoring rules live in their own prompt file so they stay
        // in sync with what CollectImagesStep parses; injected into every section
        // (a section uses them only when its "Use imagery" is yes).
        $imageInstructions = $this->renderer->render('image-generation.md', []);

        // The committed creative concept, shared by every section so the whole
        // page honors one direction (shape language, signature device, mood).
        $designDirection = DesignDirectionStep::readFor($project);

        $requests = [
            'header' => $this->withModel(['prompt' => $this->renderer->render('header.md', [
                'site_spec'  => $siteSpec,
                'theme_json' => $themeJson,
                'outline'    => $outline,
            ])]),
            'footer' => $this->withModel(['prompt' => $this->renderer->render('footer.md', [
                'site_spec'  => $siteSpec,
                'theme_json' => $themeJson,
                'outline'    => $outline,
            ])]),
        ];

        foreach ($sections as $section) {
            $key = self::SECTION_PREFIX . $section['slug'];
            $requests[$key] = $this->withModel(['prompt' => $this->renderer->render('section.md', [
                'site_spec'        => $siteSpec,
                'theme_json'       => $themeJson,
                'design_direction' => $designDirection,
                'outline'          => $outline,
                'section_title' => (string) ($section['title'] ?? ''),
                'section_type'  => (string) ($section['type'] ?? 'content'),
                'section_layout' => SectionPlanStep::layout($section['layout'] ?? null),
                'section_purpose' => (string) ($section['purpose'] ?? ''),
                'content_notes' => (string) ($section['content_notes'] ?? ''),
                'wants_image'   => ($section['wants_image'] ?? false) ? 'yes' : 'no',
                'image_instructions' => $imageInstructions,
            ])]);
        }

        return $requests;
    }

    public function run(Project $project): void
    {
        $parts = $this->llm->completeBatch($this->requests($project));

        // Validate EVERY part before writing any, so one bad part doesn't leave
        // a half-written set of files on disk (the build aborts either way).
        $files = [];
        foreach ($parts as $key => $text) {
            $rel = match (true) {
                $key === 'header' => 'parts/header.html',
                $key === 'footer' => 'parts/footer.html',
                default           => 'parts/' . $key . '.html', // section-<slug>
            };
            $files[$rel] = self::markup($text, $key);
        }

        foreach ($files as $rel => $markup) {
            $project->writeText('theme/' . $rel, $markup . "\n");
        }
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
     * Validate one part's raw block-markup response. The model returns the
     * markup verbatim; we defensively strip a stray ```…``` code fence if one
     * slipped in, then require it to actually be block markup. Pure — testable.
     */
    public static function markup(string $text, string $key): string
    {
        $markup = self::stripFences(trim($text));
        if ($markup === '' || !str_contains($markup, 'wp:')) {
            throw new RuntimeException("sections: part '{$key}' is not block markup");
        }
        return rtrim($markup);
    }

    /** Strip a leading/trailing markdown code fence if the model added one. */
    private static function stripFences(string $text): string
    {
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = preg_replace('/\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }
}
