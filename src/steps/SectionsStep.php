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
    use LlmOptions;

    /** Prefix for a section part's request key, filename, and template-part slug. */
    public const SECTION_PREFIX = 'section-';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
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
        $language = SiteSpecStep::languageOf($project);
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
            'header' => $this->withOptions(['prompt' => $this->renderer->render('header.md', [
                'site_spec'        => $siteSpec,
                'language'         => $language,
                'theme_json'       => $themeJson,
                'design_direction' => $designDirection,
                'hero_brief'       => self::heroBrief($sections),
                'outline'          => $outline,
            ])]),
            'footer' => $this->withOptions(['prompt' => $this->renderer->render('footer.md', [
                'site_spec'        => $siteSpec,
                'language'         => $language,
                'theme_json'       => $themeJson,
                'design_direction' => $designDirection,
                'outline'          => $outline,
            ])]),
        ];

        foreach ($sections as $i => $section) {
            $key = self::SECTION_PREFIX . $section['slug'];
            $requests[$key] = $this->withOptions(['prompt' => $this->renderer->render('section.md', [
                'site_spec'        => $siteSpec,
                'language'         => $language,
                'theme_json'       => $themeJson,
                'design_direction' => $designDirection,
                'outline'          => $outline,
                'section_title' => (string) ($section['title'] ?? ''),
                'section_type'  => (string) ($section['type'] ?? 'content'),
                'section_purpose' => (string) ($section['purpose'] ?? ''),
                'content_notes' => (string) ($section['content_notes'] ?? ''),
                'composition'   => $this->composition($sections, $i),
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
            $markup = self::markup($text, $key);
            if ($key === 'header' || $key === 'footer') {
                $markup = self::constrainedPart($markup);
            }
            $files[$rel] = $markup;
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
     * view of the page, including each section's planned archetype and
     * background so the page rhythm is visible everywhere. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function outline(array $sections): string
    {
        $lines = [];
        foreach ($sections as $n => $s) {
            $title = (string) ($s['title'] ?? '');
            $type = (string) ($s['type'] ?? '');
            $line = ($n + 1) . ". {$title} ({$type})";
            if (($plan = self::assignment($s)) !== '') {
                $line .= " — {$plan}";
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * The section's COMPOSITION prompt block: the assigned archetype/background/
     * handoff plus its neighbors' assignments (section-composition.md). Missing
     * composition fields mean the plan step contract was broken, so fail before
     * sending a half-empty prompt to the model.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    private function composition(array $sections, int $i): string
    {
        $section = $sections[$i];
        $slug = (string) ($section['slug'] ?? "section-{$i}");
        foreach (['layout_archetype', 'background', 'handoff'] as $field) {
            if (trim((string) ($section[$field] ?? '')) === '') {
                throw new RuntimeException("sections: section '{$slug}' is missing {$field} from section-plan");
            }
        }

        return $this->renderer->render('section-composition.md', [
            'layout_archetype' => (string) ($section['layout_archetype'] ?? ''),
            'background'       => (string) ($section['background'] ?? ''),
            'handoff'          => (string) ($section['handoff'] ?? ''),
            'neighbors'        => self::neighbors($sections, $i),
        ]);
    }

    /**
     * The plan's art-direction context for the section at $i: its neighbors'
     * archetype/background assignments, so each seam is designed on both sides.
     * Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function neighbors(array $sections, int $i): string
    {
        $describe = function (?array $s): ?string {
            if (!is_array($s)) {
                return null;
            }
            $title = (string) ($s['title'] ?? '');
            $plan = self::assignment($s);
            return "\"{$title}\"" . ($plan !== '' ? " — {$plan}" : '');
        };

        $above = $describe($sections[$i - 1] ?? null) ?? 'the site header (this is the first section)';
        $below = $describe($sections[$i + 1] ?? null) ?? 'the site footer (this is the last section)';
        return "Above: {$above}\nBelow: {$below}";
    }

    /**
     * "archetype on background" summary of a planned section, or '' when the
     * plan predates the art-direction fields.
     *
     * @param array<string,mixed> $section
     */
    private static function assignment(array $section): string
    {
        $archetype = trim((string) ($section['layout_archetype'] ?? ''));
        $background = trim((string) ($section['background'] ?? ''));
        if ($archetype === '' && $background === '') {
            return '';
        }
        return trim($archetype . ($background !== '' ? " on {$background} background" : ''));
    }

    /**
     * A plain-text brief of the planned hero section (from sections.json), so
     * the header prompt can pick the archetype that fits what it will sit
     * directly above — or float on top of. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $sections
     */
    public static function heroBrief(array $sections): string
    {
        $hero = null;
        foreach ($sections as $s) {
            if ((string) ($s['type'] ?? '') === 'hero') {
                $hero = $s;
                break;
            }
        }
        $hero ??= $sections[0] ?? null;
        if (!is_array($hero)) {
            return '(No hero section planned.)';
        }

        $lines = [];
        foreach (['title' => 'Title', 'type' => 'Type', 'purpose' => 'Purpose', 'content_notes' => 'Notes'] as $key => $label) {
            $value = trim((string) ($hero[$key] ?? ''));
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }
        return $lines === [] ? '(No hero section planned.)' : implode("\n", $lines);
    }

    /**
     * Ensure a part's top-level wp:group declares a layout. The header and
     * footer prompts demand `"layout":{"type":"constrained"}` on the top-level
     * group, but the model sometimes drops it — and a group with no layout is
     * flow, not constrained: no centering, no root-padding-aware gutter, so
     * its content renders edge-to-edge at the viewport (a header's align:wide
     * row hugs the screen corners; a footer's text touches the screen edge).
     * Only adds a missing layout; an explicit one (e.g. a deliberate flex row)
     * is left alone. Pure — unit-testable.
     */
    public static function constrainedPart(string $markup): string
    {
        if (preg_match('/^<!--\s*wp:group\s*(\{.*?\})?\s*-->/s', $markup, $m) !== 1) {
            return $markup;
        }
        $attrs = isset($m[1]) && $m[1] !== '' ? json_decode($m[1], true) : [];
        if (!is_array($attrs) || isset($attrs['layout'])) {
            return $markup;
        }
        $attrs['layout'] = ['type' => 'constrained'];
        $json = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return '<!-- wp:group ' . $json . ' -->' . substr($markup, strlen($m[0]));
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
        return self::normalizePresetRefs(rtrim($markup));
    }

    /**
     * Repair the model's recurring preset-reference typo: `var:preset--type--slug`
     * instead of `var:preset|type|slug` in block-comment attributes. WordPress
     * resolves only the pipe form, so the malformed ref produces NO style — and
     * the block-fixer then deletes the (correct) inline CSS as "not mirrored in
     * attributes", leaving e.g. a section with zero padding beside 8rem siblings.
     * The type names are a fixed vocabulary, so the rewrite is unambiguous.
     * Pure — unit-testable.
     */
    public static function normalizePresetRefs(string $markup): string
    {
        // `--` may also appear as the serializer's `--` escape.
        $dashes = '(?:--|(?:\\\\u002d){2})';
        return (string) preg_replace(
            "/var:preset{$dashes}(color|gradient|shadow|spacing|font-size|font-family|aspect-ratio|duotone){$dashes}/",
            'var:preset|$1|',
            $markup
        );
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
