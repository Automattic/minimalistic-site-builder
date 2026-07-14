<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\HeaderUnit;
use Automattic\SiteBuild\Units\MarkupUnit;
use Automattic\SiteBuild\Units\SectionUnit;

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
    /** Prefix for a section part's request key, filename, and template-part slug. */
    public const SECTION_PREFIX = SectionUnit::KEY_PREFIX;

    private SectionUnit $sectionUnit;
    private HeaderUnit $headerUnit;
    private FooterUnit $footerUnit;

    public function __construct(
        private Llm $llm,
        PromptRenderer $renderer,
        ?string $model = null,
        ?float $temperature = null,
    ) {
        $this->sectionUnit = new SectionUnit($llm, $renderer, $model, $temperature);
        $this->headerUnit = new HeaderUnit($llm, $renderer, $model, $temperature);
        $this->footerUnit = new FooterUnit($llm, $renderer, $model, $temperature);
    }

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
        return self::requestsFor($this->jobs($project));
    }

    public function run(Project $project): void
    {
        $jobs = $this->jobs($project);
        $parts = $this->llm->completeBatch(self::requestsFor($jobs));

        // Validate EVERY part before writing any, so one bad part doesn't leave
        // a half-written set of files on disk (the build aborts either way).
        $files = [];
        foreach ($jobs as $key => $job) {
            if (!array_key_exists($key, $parts) || !is_string($parts[$key])) {
                throw new \RuntimeException("sections: missing result for part '{$key}'");
            }
            $files[$job['file']] = $job['unit']->finish($parts[$key], $job['input']);
        }

        foreach ($files as $rel => $markup) {
            $project->writeText('theme/' . $rel, $markup . "\n");
        }
    }

    /**
     * Ask each job's unit to render its self-contained LLM request.
     *
     * @param array<string,array{unit:MarkupUnit,input:array<mixed>,file:string}> $jobs
     * @return array<string,array{prompt:string,model?:string,temperature?:float}>
     */
    private static function requestsFor(array $jobs): array
    {
        $requests = [];
        foreach ($jobs as $key => $job) {
            $requests[$key] = $job['unit']->request($job['input']);
        }
        return $requests;
    }

    /**
     * Read Project state once and adapt it into self-contained unit inputs.
     *
     * @return array<string,array{unit:MarkupUnit,input:array<mixed>,file:string}>
     */
    private function jobs(Project $project): array
    {
        $sections = self::sections($project);
        $common = [
            'site_spec'        => $project->readText('siteSpec.json'),
            'language'         => SiteSpecStep::languageOf($project),
            'theme_json'       => $project->readText('theme/theme.json'),
            'design_direction' => DesignDirectionStep::readFor($project),
            'outline'          => self::outline($sections),
        ];

        $jobs = [
            'header' => [
                'unit'  => $this->headerUnit,
                'input' => $common + ['hero_brief' => self::heroBrief($sections)],
                'file'  => 'parts/header.html',
            ],
            'footer' => [
                'unit'  => $this->footerUnit,
                'input' => $common,
                'file'  => 'parts/footer.html',
            ],
        ];

        foreach ($sections as $i => $section) {
            $input = $common + [
                'section'   => $section,
                'neighbors' => self::neighbors($sections, $i),
            ];
            $key = $this->sectionUnit->key($input);
            $jobs[$key] = [
                'unit'  => $this->sectionUnit,
                'input' => $input,
                'file'  => 'parts/' . $key . '.html',
            ];
        }

        return $jobs;
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
            throw new \RuntimeException('sections: sections.json has no sections (run section-plan first)');
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
        $density = trim((string) ($section['vertical_density'] ?? ''));
        if ($archetype === '' && $background === '' && $density === '') {
            return '';
        }
        $assignment = trim($archetype . ($background !== '' ? " on {$background} background" : ''));
        return trim($assignment . ($density !== '' ? ", {$density} vertical density" : ''));
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

}
