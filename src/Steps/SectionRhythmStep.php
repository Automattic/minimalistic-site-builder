<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionRhythm;
use Automattic\SiteBuild\Step;

/**
 * Deterministically apply the page plan's outer vertical rhythm to every
 * generated section part before WordPress re-serializes the block markup.
 *
 * Sections are authored concurrently and therefore cannot safely negotiate a
 * shared seam. This step is the one owner of root top/bottom padding: it maps
 * the plan's semantic density to the canonical theme spacing scale and removes
 * the duplicate edge between consecutive sections on the same background.
 */
final class SectionRhythmStep implements Step
{
    public function id(): string
    {
        return 'section-rhythm';
    }

    public function label(): string
    {
        return 'Normalize section rhythm';
    }

    public function run(Project $project): void
    {
        $plan = $project->readJson('sections.json');
        $sections = $plan['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections) || $sections === []) {
            throw new \RuntimeException('section-rhythm: sections.json has no ordered sections');
        }

        $entries = [];
        $rels = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                throw new \RuntimeException('section-rhythm: sections.json contains a non-object section');
            }
            $slug = trim((string) ($section['slug'] ?? ''));
            $rel = 'parts/' . SectionsStep::SECTION_PREFIX . $slug . '.html';
            if ($slug === '' || !$project->exists('theme/' . $rel)) {
                throw new \RuntimeException("section-rhythm: missing generated section part {$rel}");
            }
            $rels[] = $rel;
            $entries[] = [
                'slug'       => $slug,
                'markup'     => $project->readText('theme/' . $rel),
                'density'    => (string) ($section['vertical_density'] ?? ''),
                'background' => (string) ($section['background'] ?? ''),
            ];
        }

        // Rewrite the complete ordered set before writing any file, so invalid
        // plan data or one malformed root cannot leave a half-normalized page.
        $footerSurface = $project->exists('theme/parts/footer.html')
            ? SectionRhythm::followingSurfaceFromMarkup($project->readText('theme/parts/footer.html'))
            : null;
        $result = SectionRhythm::rewrite($entries, $footerSurface);
        foreach ($rels as $i => $rel) {
            $project->writeText('theme/' . $rel, $result['markups'][$i]);
        }

        echo '  section rhythm: ' . count($result['notes']) . " root spacing adjustment(s)\n";
    }
}
