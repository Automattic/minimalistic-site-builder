<?php
declare(strict_types=1);

/**
 * Step (LLM): commit to ONE distinctive creative concept for the site BEFORE any
 * theme, palette, or layout is chosen.
 *
 * Input:  meta.json (the user prompt) + siteSpec.json (factual info).
 * Output: designDirection.md — a short freeform design brief (markdown) — and
 *         imageGrade.txt — the direction's one-sentence photographic grade for
 *         ALL site imagery (read by GenerateImagesStep via imageGradeFor()).
 *
 * The model is asked for FOUR distinct visual directions in one call; this step
 * then picks ONE of them at random. Generating a spread and sampling from it —
 * rather than asking for a single "best" concept — is the deliberate injection of
 * variety: it widens the creative range and stops repeated builds of the same
 * brief from converging on the model's one safe favourite.
 *
 * The model responds in a sentinel-delimited plain-text format (=== DIRECTION ===
 * blocks with TITLE:/IMAGE_GRADE:/DESCRIPTION: fields), NOT JSON: the
 * descriptions are long free prose, exactly where models drop an unescaped
 * quote and break json_decode. With sentinels there is nothing to escape, so a
 * quote in the prose can't fail the step.
 *
 * This is the single source of design intent. The theme-json, section-plan and
 * section steps all read it (via DesignDirectionStep::readFor) and let it drive
 * their choices, so two sites diverge in concept — not just in hex values.
 *
 * Only the chosen direction is persisted, as freeform prose: nothing downstream
 * parses it — it's produced by a model and consumed by a model (spliced into the
 * design prompts), so structure would only add escaping friction downstream.
 */
final class DesignDirectionStep implements Step
{
    use ModelOption;

    /**
     * Injected into downstream prompts when no designDirection.md exists yet
     * (a step run in isolation). Keeps the creative intent alive instead of
     * silently reverting to defaults.
     */
    private const FALLBACK = '(No explicit design direction was provided. Make bold, '
        . 'specific, non-generic design choices that fit the brand, and consciously avoid '
        . 'default treatments like a centered hero, all-sans-serif typography, and a '
        . 'blue/teal palette.)';

    /** Where the brief is written, and read back from by readFor(). */
    private const FILE = 'designDirection.md';

    /**
     * Where the chosen direction's image grade is written, and read back from by
     * imageGradeFor(). A separate machine-readable artifact (not parsed out of
     * the freeform brief) so GenerateImagesStep can inject it verbatim into
     * every composed image prompt.
     */
    private const GRADE_FILE = 'imageGrade.txt';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
    ) {}

    public function id(): string
    {
        return 'design-direction';
    }

    public function label(): string
    {
        return 'Choose design direction';
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $prompt = (string) ($meta['prompt'] ?? '');
        if (trim($prompt) === '') {
            throw new RuntimeException('meta.json has no "prompt"');
        }

        $rendered = $this->renderer->render('design-direction.md', [
            'user_prompt' => $prompt,
            'site_spec'   => $project->readText('siteSpec.json'),
        ]);
        $text = $this->llm->complete($rendered, $this->withModel(['log_label' => $this->id()]));

        $directions = self::parseDirections($text);
        if ($directions === []) {
            throw new RuntimeException('design-direction: model returned no usable directions');
        }

        // Sample ONE direction at random — the deliberate variety injection.
        $chosen = $directions[random_int(0, count($directions) - 1)];

        $project->writeText(self::FILE, self::format($chosen) . "\n");

        // Persist the direction's photographic grade for the image pipeline.
        // Always written — even empty (a partial model response without one just
        // means image prompts get no grade clause; imageGradeFor() returns '')
        // — so a re-run never leaves a stale grade from a previous direction.
        $project->writeText(self::GRADE_FILE, trim((string) ($chosen['image_grade'] ?? '')) . "\n");
    }

    /**
     * Parse the sentinel-delimited response into direction arrays. Each
     * "=== DIRECTION ===" block yields {title, description, image_grade};
     * TITLE:/IMAGE_GRADE: are single lines, DESCRIPTION: is everything after its
     * marker to the end of the block. Anything before the first marker (stray
     * preamble) is ignored, and a block without a description is dropped — a
     * description is what downstream needs; the title is a nice-to-have label.
     * Pure — unit-testable.
     *
     * @return array<int,array{title:string,description:string,image_grade:string}>
     */
    public static function parseDirections(string $text): array
    {
        $blocks = preg_split('/^[ \t]*={2,}[ \t]*DIRECTION[ \t]*={2,}[ \t]*$/mi', $text) ?: [];

        $out = [];
        foreach (array_slice($blocks, 1) as $block) {
            $description = '';
            if (preg_match('/^[ \t]*DESCRIPTION:[ \t]*/mi', $block, $m, PREG_OFFSET_CAPTURE)) {
                $description = trim(substr($block, $m[0][1] + strlen($m[0][0])));
            }
            if ($description === '') {
                continue;
            }
            $out[] = [
                'title'       => self::line($block, 'TITLE'),
                'description' => $description,
                'image_grade' => self::line($block, 'IMAGE_GRADE'),
            ];
        }
        return $out;
    }

    /** The first "NAME: value" single-line field in a direction block, or ''. */
    private static function line(string $block, string $name): string
    {
        return preg_match('/^[ \t]*' . $name . ':[ \t]*(.*)$/mi', $block, $m) ? trim($m[1]) : '';
    }

    /**
     * Render one chosen direction as the freeform markdown brief downstream reads.
     *
     * @param array<string,mixed> $direction
     */
    private static function format(array $direction): string
    {
        $title = trim((string) ($direction['title'] ?? ''));
        $description = trim((string) $direction['description']);
        return $title === '' ? $description : "# {$title}\n\n{$description}";
    }

    /**
     * The committed design direction as a text block to inject into a downstream
     * prompt. Returns the brief verbatim when present, else a fallback that keeps
     * the "be bold, avoid defaults" intent so steps run in isolation still work.
     */
    public static function readFor(Project $project): string
    {
        return $project->exists(self::FILE)
            ? trim($project->readText(self::FILE))
            : self::FALLBACK;
    }

    /**
     * The committed direction's image grade — the one-sentence photographic
     * treatment shared by ALL of the site's imagery. Returns '' when no grade
     * was persisted (no fallback: an absent grade just means the image prompts
     * carry no grade clause).
     */
    public static function imageGradeFor(Project $project): string
    {
        return $project->exists(self::GRADE_FILE)
            ? trim($project->readText(self::GRADE_FILE))
            : '';
    }
}
