<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\PaletteReconciliation;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Warnings;

/**
 * Point the planning artifacts at the palette the theme actually shipped,
 * before any markup prompt reads them.
 *
 * theme-json may author a different hex than the design direction proposed —
 * the proposed palette only fills slugs the model left out — and page-plan
 * runs concurrently with theme-json, so it plans against proposed colors it
 * cannot verify. Both artifacts are then replayed into every header, footer,
 * hero and section prompt: designDirection.json as the DESIGN DIRECTION block,
 * pages.json as each section's `content_notes`. Without this pass those
 * prompts carry two disagreeing palettes and no way to rank them.
 *
 * Deterministic and lossless: only hexes whose slug demonstrably drifted are
 * rewritten, and only to that slug's delivered color, so nothing is lost and
 * a second run is a no-op. A hex the delivered palette still contains under a
 * different slug is left alone and recorded — rewriting it would trade one
 * wrong color for another, but a designer reading the prose should know its
 * role moved.
 */
final class ReconcilePaletteStep implements Step
{
    public function id(): string
    {
        return 'reconcile-palette';
    }

    public function label(): string
    {
        return 'Resync planning colors to the theme';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['theme/theme.json', 'designDirection.json', 'pages.json'],
            writes: ['designDirection.json', 'pages.json', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $direction = $project->readJson('designDirection.json');
        $theme = $project->readJson('theme/theme.json');

        $plan = PaletteReconciliation::plan(
            PaletteReconciliation::directionPalette($direction),
            PaletteReconciliation::themePalette($theme),
        );
        $substitutions = $plan['substitutions'];

        if ($substitutions === [] && $plan['ambiguous'] === []) {
            Narrator::write("  palette: planning colors already match the theme\n");
            return;
        }

        // Rewrite both artifacts before writing either: they are replayed into
        // the same prompt, so a half-applied resync would be worse context
        // than the drift it replaces.
        [$reconciledDirection, $directionEdits] = PaletteReconciliation::rewriteData($direction, $substitutions);
        $planEdits = 0;
        $pagesExist = $project->exists('pages.json');
        $reconciledPages = [];
        if ($pagesExist) {
            [$reconciledPages, $planEdits] = PaletteReconciliation::rewriteData(
                $project->readJson('pages.json'),
                $substitutions,
            );
        }

        if ($substitutions !== []) {
            $project->writeJson('designDirection.json', $reconciledDirection);
            if ($pagesExist) {
                $project->writeJson('pages.json', $reconciledPages);
            }
        }

        $project->addWarnings($this->id(), self::ambiguityWarnings($plan['ambiguous'], $direction, $theme));

        $slugs = count($substitutions);
        Narrator::write(
            "  palette: {$slugs} drifted color(s) resynced; "
            . ($directionEdits + $planEdits) . " planning value(s) rewritten\n"
        );
    }

    /**
     * One actionable row per slug whose proposed color survived in the theme
     * under a different name, so the prose describing its role is now wrong in
     * a way this step cannot safely repair.
     *
     * @param list<string>           $ambiguous
     * @param array<array-key,mixed> $direction
     * @param array<array-key,mixed> $theme
     * @return list<string>
     */
    private static function ambiguityWarnings(array $ambiguous, array $direction, array $theme): array
    {
        if ($ambiguous === []) {
            return [];
        }
        $proposed = PaletteReconciliation::directionPalette($direction);
        $delivered = PaletteReconciliation::themePalette($theme);

        $rows = [];
        foreach ($ambiguous as $slug) {
            $authored = Warnings::value($proposed[$slug] ?? null);
            $shipped = Warnings::value($delivered[$slug] ?? null);
            $rows[] = "file='designDirection.json'; block=\"palette.{$slug}\"; "
                . "authored={$authored}; delivered={$shipped}; disposition=proposed color kept "
                . 'verbatim in the direction prose and page plan because the delivered palette '
                . 'still uses it for another slug; every prompt describing that color now names '
                . 'the wrong role';
        }
        return $rows;
    }
}
