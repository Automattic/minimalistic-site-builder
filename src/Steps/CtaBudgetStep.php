<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\CtaBudget;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionRole;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (deterministic): keep a page's buttons to the actions its plan placed,
 * and turn every other button into a text link.
 *
 * Runs on the blocks graph while the generated sections are still separate
 * ordered part files — after copy-dedupe, before image collection and the
 * fix-blocks re-serialization that syncs any edit downstream. Per section the
 * budget is: the hero is left to HeaderHeroStep (its one planned button is
 * already the above-fold contract); a section the plan gave a `primary_action`
 * keeps one button; the page's closing section keeps one button as the
 * closing next step; every other section keeps none. What a section may not
 * keep is demoted by CtaBudget, never removed — the link stays, the accent
 * fill goes.
 *
 * Demotions are a page-level policy, like section rhythm, and are reported in
 * logs/cta-budget.log rather than in warnings.json. A part whose block
 * structure cannot be edited safely, or a planned part that is missing, is
 * delivered unchanged under a durable warning while its siblings are still
 * budgeted.
 */
final class CtaBudgetStep implements Step
{
    private const LOG_FILE = 'cta-budget.log';

    public function id(): string
    {
        return 'cta-budget';
    }

    public function label(): string
    {
        return 'Budget calls to action';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['pages.json', 'theme/parts/*'],
            writes: ['theme/parts/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $warnings = [];
        $log = [];
        $demoted = 0;

        foreach (SectionRhythmStep::pages($project) as $page) {
            $pageSlug = trim((string) ($page['slug'] ?? ''));
            $plan = $page['sections'] ?? null;
            if (!is_array($plan) || !array_is_list($plan) || $plan === []) {
                $warnings[] = "page '{$pageSlug}': no ordered sections in pages.json; buttons delivered as authored";
                continue;
            }
            $count = count($plan);
            foreach ($plan as $index => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $role = (string) ($section['role'] ?? SectionRole::forPosition($index, $count));
                if ($role === SectionRole::HERO) {
                    continue;
                }
                $slug = trim((string) ($section['slug'] ?? ''));
                $rel = 'parts/' . SectionsStep::partSlug($pageSlug, $slug) . '.html';
                if ($slug === '' || !$project->exists('theme/' . $rel)) {
                    $warnings[] = "page '{$pageSlug}', section '{$slug}': generated part {$rel} is missing; "
                        . 'no button budget applied';
                    continue;
                }
                $planned = is_array($section['primary_action'] ?? null);
                $closing = $role === SectionRole::CLOSING || $index === $count - 1;
                $keep = $planned || $closing ? 1 : 0;

                $markup = $project->readText('theme/' . $rel);
                try {
                    $result = CtaBudget::apply($markup, $keep);
                } catch (\RuntimeException $e) {
                    $warnings[] = "file='theme/{$rel}'; block='part'; authored buttons delivered unchanged; "
                        . 'disposition=button budget skipped (' . $e->getMessage() . ')';
                    continue;
                }
                if ($result['demoted'] === 0) {
                    continue;
                }
                $project->writeText('theme/' . $rel, $result['markup']);
                $demoted += $result['demoted'];
                $log[] = "page '{$pageSlug}', section '{$slug}': kept {$result['kept']} button(s) ("
                    . ($planned ? 'planned action' : ($closing ? 'closing next step' : 'no planned action'))
                    . "), demoted {$result['demoted']}: " . implode('; ', $result['notes']);
            }
        }

        if ($log !== []) {
            $project->writeText('logs/' . self::LOG_FILE, implode("\n", $log) . "\n");
        }
        $project->addWarnings($this->id(), $warnings);

        Narrator::write("  cta budget: {$demoted} unplanned button(s) demoted to text actions"
            . ($log !== [] ? ' (details: logs/' . self::LOG_FILE . ')' : '') . "\n");
        if ($warnings !== []) {
            Narrator::write('  [cta-budget] warning: ' . count($warnings)
                . " degradation(s) recorded in warnings.json\n");
        }
    }
}
