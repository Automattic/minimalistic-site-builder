<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TreeGraph\BriefChecks;
use Automattic\SiteBuild\TreeGraph\Budget;
use Automattic\SiteBuild\TreeGraph\Schema;
use Automattic\SiteBuild\TreeGraph\Styles;
use Automattic\SiteBuild\TreeGraph\TreeLlm;

/**
 * Tree graph step 2: the brief — the whole plan of the site in ONE
 * high-effort call (port of x-pipeline S1, brochure mode always on).
 *
 * The brief fixes everything later stages execute: identity, art direction,
 * the style combo (an artistic style x a UI design style from the two
 * rosters, user-named styles pinned in code), the site axis, language,
 * palette, every page's sections with band/layout plans, navigation and
 * footer. It also fixes the bill: computeBudget() derives the call ceiling
 * from the plan and budget.json records it before a second call is made.
 */
final class TreeBriefStep implements Step
{
    /** Brochure mode, verbatim from x-pipeline S1 — this port ships composition only. */
    private const BROCHURE_NOTE = <<<'NOTE'
        BROCHURE MODE — this build ships composition only. custom_blocks and
        schema_packages MUST be empty arrays; there is no argument that wins one here. The R7
        ladder stops at rung 2: express every section through composition of existing blocks,
        block styles and patterns, and design harder instead of reaching for new vocabulary.
        Anything interactive or data-backed (stored forms, tickers, schedules, bookings) is out
        of scope — a contact section carries the venue's details and links, not a stored-submission
        form.

        This constrains only WHAT MAY BE BUILT, never how much. Plan the same pages and the
        same sections you would plan without this note, at the same design ambition — do not
        fold pages away or shrink the plan because the vocabulary is smaller. Content that
        would have been a custom block or a data feature becomes a fully designed static
        section carrying the same information (a beer list is a designed grid with styles and
        prices written in; a schedule is a designed table; a booking is the phone number,
        set beautifully).
        NOTE;

    public function __construct(
        private readonly Llm $llm,
        private readonly ?string $model = null,
        private readonly ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'brief';
    }

    public function label(): string
    {
        return 'Plan the whole site (brief)';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json'],
            writes: ['brief.json', 'budget.json'],
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
        $withImages = (bool) ($meta['tree_images'] ?? false);

        $schema = json_decode(
            (string) file_get_contents(\repo_path('schemas/tree/brief.schema.json')),
            true,
        );
        if (!is_array($schema)) {
            throw new \RuntimeException('schemas/tree/brief.schema.json is missing or invalid');
        }

        $styles = Styles::load();
        $pins = Styles::matchPinnedStyles($prompt, $styles);
        if (($pins['artistic'] ?? null) !== null || ($pins['ui'] ?? null) !== null || ($pins['flexible'] ?? null) !== null) {
            $named = array_filter([
                $pins['artistic'] ?? null,
                $pins['ui'] ?? null,
                $pins['flexible']['artistic'] ?? null,
            ]);
            Narrator::write('  the request names "' . implode('", "', $named) . "\" — pinned; the brief chooses only what is missing\n");
        }

        $lane = TreeLlm::forProject($this->llm, $project, $this->model === null ? [] : ['brief' => $this->model], ['brief' => $this->temperature]);
        $brief = $lane->generate(
            'brief',
            'brief',
            [
                'prompt'          => $prompt,
                'contract'        => $schema,
                'mode_note'       => self::BROCHURE_NOTE,
                'artistic_styles' => implode(', ', Styles::seededShuffle(
                    array_map(static fn (array $e): string => (string) $e['name'], $styles['artistic']),
                    "{$prompt}:artistic",
                )),
                'ui_styles'       => implode(', ', Styles::seededShuffle(
                    array_map(static fn (array $e): string => (string) $e['name'], $styles['ui']),
                    "{$prompt}:ui",
                )),
                'style_pin_note'  => Styles::renderPinNote($pins),
            ],
            static fn (array $v): array => array_merge(
                Schema::validate($schema, $v),
                BriefChecks::crossChecks($v),
                Styles::styleChecks($v, $styles, $pins),
                BriefChecks::brochureChecks($v),
            ),
        );

        $project->writeJson('brief.json', $brief);
        if (isset($brief['style']['artistic'], $brief['style']['ui'])) {
            Narrator::write("  style combo: {$brief['style']['artistic']} × {$brief['style']['ui']}\n");
        }

        $budget = Budget::computeBudget($brief, $withImages);
        $lane->budget()->setCeiling((int) $budget['ceiling']);
        $project->writeJson('budget.json', $budget);
        Narrator::write(sprintf(
            "  this brief costs at most %d calls (S=%d, I=%d)%s\n",
            (int) $budget['ceiling'],
            (int) $budget['S'],
            (int) $budget['I'],
            $withImages ? '' : ' — images skipped, placeholders stay',
        ));
    }
}
