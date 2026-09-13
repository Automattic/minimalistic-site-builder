<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step: choose a pattern from the supplied inventory for every planned section.
 *
 * This is where a plan written in intents — "Hero", "Why attend", "CTA" —
 * becomes markup written in the destination theme's own vocabulary. The theme
 * decides that vocabulary; nothing here may invent a word outside it, which is
 * what the inventory is and why a section with no match stops the build rather
 * than quietly shipping a page with a hole in it.
 *
 * Choices are recorded as they are made. A replay that picked differently would
 * compose a different site before reaching the model, and the two runs it was
 * meant to compare would no longer be comparable.
 */
final class ComposeLayoutsStep implements Step
{
    public function id(): string
    {
        return 'compose-layouts';
    }

    public function label(): string
    {
        return 'Select approved patterns for every page and shared part';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [PatternArtifacts::NORMALIZED, PatternArtifacts::PLAN],
            writes: [PatternArtifacts::LAYOUTS, PatternArtifacts::PROVENANCE],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
        NormalizeInputsStep::assertVersion($inputs);
        $plan = $project->readJson(PatternArtifacts::PLAN);

        $inventory = self::byId($inputs['inventory'] ?? []);
        if ($inventory === []) {
            throw new \RuntimeException('No inventory was supplied, so there is nothing to compose from.');
        }

        $layouts = [];
        $provenance = [];
        $unmatched = [];

        foreach ($plan['pages'] ?? [] as $page) {
            $slug = (string) ($page['slug'] ?? '');
            $sections = [];

            foreach ($page['sections'] ?? [] as $index => $section) {
                $choice = self::choose($section, $inventory);

                if ($choice === null) {
                    $unmatched[] = sprintf(
                        '%s section %d (%s)',
                        $slug,
                        $index,
                        (string) ($section['intent'] ?? $section['category'] ?? 'unnamed'),
                    );
                    continue;
                }

                $sections[] = [
                    'pattern' => $choice,
                    'content' => (string) $inventory[$choice]['content'],
                ];
                $provenance[] = [
                    'page' => $slug,
                    'index' => $index,
                    'pattern' => $choice,
                    'intent' => $section['intent'] ?? null,
                ];
            }

            $layouts[] = ['slug' => $slug, 'sections' => $sections];
        }

        // Fail closed, and name every gap rather than the first. A planner
        // fixing one section at a time against a build that stops at the
        // earliest failure learns the inventory one round trip per hole.
        if ($unmatched !== []) {
            throw new \RuntimeException(
                "No approved pattern matches these planned sections:\n  - "
                . implode("\n  - ", $unmatched)
            );
        }

        $project->writeJson(PatternArtifacts::LAYOUTS, ['pages' => $layouts]);
        $project->writeJson(PatternArtifacts::PROVENANCE, ['sections' => $provenance]);
    }

    /**
     * The pattern for one planned section, or null when the inventory has none.
     *
     * A plan may name the pattern outright, which a Blueprint written by hand
     * does; it still has to be in the inventory, because "the plan said so" is
     * not the same as "the customer approved it".
     *
     * Otherwise the section's category is matched against what each pattern
     * declares. Among equals the lowest id wins — an arbitrary rule, chosen
     * because it is stable: the alternative is a different site on every run.
     *
     * Ranking the equals by how well they fit is the part still to come out of
     * Big Sky's selection scoring. Until it does, this picks the same one every
     * time rather than picking cleverly.
     *
     * @param array<string,mixed>              $section
     * @param array<string,array<string,mixed>> $inventory Keyed by pattern id.
     */
    private static function choose(array $section, array $inventory): ?string
    {
        $named = $section['pattern'] ?? null;
        if (is_string($named) && $named !== '') {
            return isset($inventory[$named]) ? $named : null;
        }

        $category = strtolower(trim((string) ($section['category'] ?? $section['intent'] ?? '')));
        if ($category === '') {
            return null;
        }

        $matches = [];
        foreach ($inventory as $id => $pattern) {
            foreach ($pattern['categories'] ?? [] as $candidate) {
                if (strtolower(trim((string) $candidate)) === $category) {
                    $matches[] = (string) $id;
                    break;
                }
            }
        }

        if ($matches === []) {
            return null;
        }

        sort($matches);

        return $matches[0];
    }

    /**
     * Index the inventory by id, dropping entries that carry no markup.
     *
     * An entry with no content would be chosen, recorded as the section's
     * provenance, and contribute an empty section — a page that is missing
     * something while every check says it was composed from approved patterns.
     *
     * @param array<int,array<string,mixed>> $inventory
     * @return array<string,array<string,mixed>>
     */
    private static function byId(array $inventory): array
    {
        $byId = [];
        foreach ($inventory as $pattern) {
            $id = $pattern['id'] ?? null;
            $content = $pattern['content'] ?? null;

            if (is_string($id) && $id !== '' && is_string($content) && trim($content) !== '') {
                $byId[$id] = $pattern;
            }
        }

        return $byId;
    }
}
