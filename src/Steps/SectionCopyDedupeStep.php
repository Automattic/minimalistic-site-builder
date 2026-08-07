<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionCopyDedupe;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Remove copy lines that repeat across a page's sections (BIGR-783).
 *
 * Runs while the generated sections are still separate ordered part files —
 * after section rhythm (parts final in plan order), before image collection
 * and the fix-blocks re-serialization that syncs any edit downstream. The
 * hero part is excluded: the above-fold twin-lines contract in HeaderHeroStep
 * (BIGR-773) already owns header/hero copy, and this pass must not compete
 * with it. Per-page isolation mirrors section-rhythm: a page whose plan or
 * parts are malformed keeps its authored copy under a durable warning while
 * every other page is still deduped.
 */
final class SectionCopyDedupeStep implements Step
{
    public function id(): string
    {
        return 'copy-dedupe';
    }

    public function label(): string
    {
        return 'Remove repeated copy lines';
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
        $footerMarkup = $project->exists('theme/parts/footer.html')
            ? $project->readText('theme/parts/footer.html')
            : '';

        $removals = 0;
        $warnings = [];
        if ($footerMarkup !== '') {
            try {
                self::assertSafeMarkup($footerMarkup, 'theme/parts/footer.html');
            } catch (\RuntimeException $e) {
                $warnings[] = $e->getMessage()
                    . '; footer-seam comparison skipped and authored seam values delivered unchanged';
                $footerMarkup = '';
            }
        }
        foreach (SectionRhythmStep::pages($project) as $page) {
            $pageSlug = trim((string) ($page['slug'] ?? ''));
            try {
                [$sections, $rels] = self::contentSections($project, $page);
                if ($sections === []) {
                    continue;
                }
                $result = SectionCopyDedupe::dedupe($sections, $footerMarkup);
            } catch (\RuntimeException $e) {
                $warnings[] = "page '{$pageSlug}': copy dedupe skipped ({$e->getMessage()}); "
                    . 'all authored page copy delivered byte-for-byte';
                continue;
            }
            foreach ($result['markups'] as $i => $markup) {
                if ($markup !== $sections[$i]['markup']) {
                    $project->writeText('theme/' . $rels[$i], $markup);
                }
            }
            foreach ($result['notes'] as $note) {
                Narrator::write("  [copy-dedupe] page '{$pageSlug}', {$note}\n");
            }
            foreach ($result['residuals'] as $residual) {
                $path = 'theme/' . $rels[$residual['section']];
                $warnings[] = "page '{$pageSlug}', file '{$path}', block byte {$residual['start']}: "
                    . "authored value \"{$residual['excerpt']}\" delivered unchanged; disposition=duplicate "
                    . 'retained because the four-removal page safety cap was exceeded; '
                    . 'the page was preserved transactionally';
            }
            $removals += $result['removed'];
        }
        $project->addWarnings($this->id(), $warnings);

        Narrator::write("  copy dedupe: {$removals} repeated line(s) removed\n");
        if ($warnings !== []) {
            Narrator::write('  [copy-dedupe] warning: ' . count($warnings)
                . " degradation(s) recorded in warnings.json\n");
        }
    }

    /**
     * One page's ordered non-hero section parts and their theme-relative
     * paths, while the transient parts still exist.
     *
     * @param array<string,mixed> $page
     * @return array{list<array{slug:string,markup:string}>,list<string>}
     */
    private static function contentSections(Project $project, array $page): array
    {
        $pageSlug = trim((string) ($page['slug'] ?? ''));
        $plan = $page['sections'] ?? null;
        if (!is_array($plan) || !array_is_list($plan) || $plan === []) {
            throw new \RuntimeException("page '{$pageSlug}' has no ordered sections");
        }
        $sections = [];
        $rels = [];
        foreach ($plan as $section) {
            if (!is_array($section) || (string) ($section['role'] ?? '') === 'hero') {
                continue;
            }
            $slug = trim((string) ($section['slug'] ?? ''));
            $rel = 'parts/' . SectionsStep::partSlug($pageSlug, $slug) . '.html';
            if ($slug === '' || !$project->exists('theme/' . $rel)) {
                throw new \RuntimeException("missing generated section part {$rel}");
            }
            $markup = $project->readText('theme/' . $rel);
            self::assertSafeMarkup($markup, 'theme/' . $rel);
            $sections[] = ['slug' => $slug, 'markup' => $markup];
            $rels[] = $rel;
        }
        return [$sections, $rels];
    }

    /** Reject a generated part whose block boundaries cannot be edited safely. */
    private static function assertSafeMarkup(string $markup, string $path): void
    {
        $doc = BlockMarkup::parse($markup);
        if (
            $doc->unclosedIndices() !== []
            || $doc->hasMismatchedDelimiters()
            || $doc->hasMalformedDelimiters()
        ) {
            throw new \RuntimeException("file '{$path}' has malformed block structure");
        }
    }
}
