<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\GroundKey;
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
            $project->writeJsonAtomic('designDirection.json', $reconciledDirection);
            if ($pagesExist) {
                $project->writeJsonAtomic('pages.json', $reconciledPages);
            }
        }

        $project->addWarnings($this->id(), array_merge(
            self::ambiguityWarnings($plan, $direction, $theme),
            self::groundKeyWarnings($direction, $substitutions !== [] ? $reconciledDirection : $direction),
        ));

        $slugs = count($substitutions);
        Narrator::write(
            "  palette: {$slugs} drifted color(s) resynced; "
            . ($directionEdits + $planEdits) . " planning value(s) rewritten\n"
        );
    }

    /**
     * One actionable row when the resynced base contradicts the committed
     * ground key.
     *
     * The direction's `ground_key` was enforced against the proposed base by
     * design-direction, but theme-json may deliver a base on the other side
     * of the luminance split, and this step then adopts that hex verbatim.
     * The theme is already built, so nothing here can move the color; the
     * contradiction is recorded and the build continues.
     *
     * @param array<array-key,mixed> $direction
     * @param array<array-key,mixed> $reconciled
     * @return list<string>
     */
    private static function groundKeyWarnings(array $direction, array $reconciled): array
    {
        $key = $direction['ground_key'] ?? null;
        if (!is_string($key) || !in_array($key, GroundKey::ALL, true)) {
            return [];
        }
        $proposed = PaletteReconciliation::directionPalette($direction)['base'] ?? null;
        $delivered = PaletteReconciliation::directionPalette($reconciled)['base'] ?? null;
        if (!is_string($delivered) || GroundKey::classify($delivered) === $key) {
            return [];
        }
        return [
            "file='designDirection.json'; block=\"palette.base\"; "
            . 'authored=' . Warnings::value($proposed) . '; delivered=' . Warnings::value($delivered)
            . "; disposition=theme-json delivered a base off the committed \"{$key}\" ground and the "
            . 'planning palette now carries it; the theme is already built, so the contradiction is '
            . 'recorded instead of repaired',
        ];
    }

    /**
     * One actionable row per slug whose proposed color cannot be rewritten
     * without trading one wrong color for another, so the prose describing
     * its role is now wrong in a way this step cannot safely repair.
     *
     * @param array{ambiguous?:list<string>,skipReasons?:array<string,string>} $plan
     * @param array<array-key,mixed> $direction
     * @param array<array-key,mixed> $theme
     * @return list<string>
     */
    private static function ambiguityWarnings(array $plan, array $direction, array $theme): array
    {
        $ambiguous = $plan['ambiguous'] ?? [];
        if ($ambiguous === []) {
            return [];
        }
        $proposed = PaletteReconciliation::directionPalette($direction);
        $delivered = PaletteReconciliation::themePalette($theme);
        $skipReasons = $plan['skipReasons'] ?? [];

        $rows = [];
        foreach ($ambiguous as $slug) {
            $authored = Warnings::value($proposed[$slug] ?? null);
            $shipped = Warnings::value($delivered[$slug] ?? null);
            $reason = $skipReasons[$slug] ?? 'still-in-palette';
            $why = $reason === 'collided'
                ? 'two slugs proposed this hex and drifted to different delivered colors, so there is no single rewrite'
                : 'the delivered palette still uses it for another slug';
            $rows[] = "file='designDirection.json'; block=\"palette.{$slug}\"; "
                . "authored={$authored}; delivered={$shipped}; disposition=proposed color kept "
                . "verbatim in the direction prose and page plan because {$why}; every prompt "
                . 'describing that color now names the wrong role';
        }
        return $rows;
    }
}
