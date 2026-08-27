<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TreeGraph\Gates;
use Automattic\SiteBuild\TreeGraph\Harness;
use Automattic\SiteBuild\TreeGraph\Normalize;
use Automattic\SiteBuild\TreeGraph\SandboxClient;
use Automattic\SiteBuild\TreeGraph\TreeGate;
use Automattic\SiteBuild\TreeGraph\TreeGraphException;
use Automattic\SiteBuild\TreeGraph\TreeLlm;

/**
 * Tree graph step 6: the ONLY repair lane (port of x-pipeline S7, trees
 * only — the brochure build has no block or schema factories).
 *
 * At most one repair call per failed section, judged by the SAME gate the
 * authoring lane used. When the repair fails too, the ladder is
 * deterministic and recorded: swap each failing declared ink for the
 * palette's closest compliant slug and re-gate; else publish the theme's
 * stock pattern in that slot; else the minimal honest slot. The pipeline
 * never improvises. Failed furniture is not repaired — the deterministic
 * header/footer floors at publish are its fallback.
 */
final class TreeRepairStep implements Step
{
    public function __construct(
        private readonly Llm $llm,
        private readonly ?string $model = null,
        private readonly ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'tree-repair';
    }

    public function label(): string
    {
        return 'Repair failed trees';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['artifacts.json', 'trees/*', 'sections/*', 'tokens.json', 'instance.json', 'sandbox.json', 'budget.json'],
            writes: ['trees/*', 'artifacts.json', 'dead.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $artifacts = $project->readJson('artifacts.json');
        $tokens = $project->readJson('tokens.json');
        $instance = $project->readJson('instance.json');
        $sandbox = $project->readJson('sandbox.json');

        $client = new SandboxClient((string) $sandbox['url']);
        $gate = new TreeGate($client, new Harness(\repo_path()), $project);
        $lane = TreeLlm::forProject($this->llm, $project, $this->model === null ? [] : ['repair' => $this->model], ['repair' => $this->temperature]);
        $palette = (array) ($tokens['palette'] ?? []);
        $epoch = (string) ($instance['fingerprint'] ?? '');
        $dead = $project->exists('dead.json') ? $project->readJson('dead.json') : [];

        $treeValidate = static function (array $v) use ($epoch, $palette): array {
            $issues = Gates::localTreeCheck($v, $epoch);
            if ($issues !== []) {
                return $issues;
            }
            foreach ([Gates::screenBandRoot($v), Gates::screenTreeLiterals($v), Gates::screenTreeInk($v, $palette)['failures'], Gates::screenImageGeometry($v)] as $screen) {
                if ($screen !== []) {
                    return array_map(
                        static fn (array $f): array => ['path' => $f['path'] ?? '', 'message' => $f['message']],
                        $screen,
                    );
                }
            }
            return [];
        };

        $repaired = 0;
        foreach ((array) ($artifacts['trees'] ?? []) as $key => $art) {
            if (($art['status'] ?? '') !== 'fail') {
                continue;
            }
            $key = (string) $key;
            Narrator::write("  section {$key}: repairing — one model call, judged by the same validation\n");
            $record = $project->readJson("trees/{$key}.json");

            $value = null;
            try {
                $value = $lane->generate(
                    'repair',
                    "trees/{$key}",
                    [
                        'artifact'              => $record['tree'],
                        'diagnostics'           => $art['failures'] ?? [],
                        'original_payload_note' => 'The artifact is one page section (TreeIR, epoch "' . $epoch . '").'
                            . ' Keep the section\'s content and design; fix only what the diagnostics name.',
                    ],
                    $treeValidate,
                    null,
                    1, // a malformed repair is a dead artifact, not another retry
                );
            } catch (TreeGraphException $e) {
                if ($e->errorCode === 'budget_exceeded') {
                    throw $e;
                }
                // contract_failed: fall through to the deterministic ladder.
            }

            if ($value !== null) {
                Normalize::normalizeTreeBorders($value);
                $verdict = $gate->gateTree($value, $palette);
                if ($verdict['status'] === 'pass') {
                    $project->writeJson("trees/{$key}.json", ['tree' => $value, 'gate' => $verdict + ['repaired' => true]]);
                    $artifacts['trees'][$key] = ['status' => 'repaired', 'deferred' => $verdict['deferred'], 'failures' => []];
                    $repaired++;
                    Narrator::write("  section {$key}: repaired — validation passed this time\n");
                    continue;
                }
                $art['failures'] = array_merge((array) ($art['failures'] ?? []), $verdict['failures']);
            }

            if ($this->rescueInk($project, $gate, $key, $palette)) {
                $artifacts['trees'][$key] = ['status' => 'repaired', 'deferred' => [], 'failures' => []];
                $repaired++;
                Narrator::write("  section {$key}: the repair failed but the defect was ink — swapped deterministically; the designed section survives\n");
                continue;
            }

            $this->substituteBaseline($project, $gate, $key, $palette, $epoch);
            $artifacts['trees'][$key] = ['status' => 'baseline', 'deferred' => [], 'failures' => $art['failures'] ?? []];
            $dead[] = ['kind' => 'trees', 'key' => $key, 'diagnostics' => $art['failures'] ?? []];
            Narrator::write("  section {$key}: the repair failed too — publishing the theme's stock pattern in that slot instead\n");
        }

        $project->writeJson('artifacts.json', $artifacts);
        $project->writeJson('dead.json', $dead);
        if ($dead === [] && $repaired === 0) {
            Narrator::write("  nothing needed repairing — every artifact passed its gate first time\n");
        } elseif ($dead === []) {
            Narrator::write("  {$repaired} artifact(s) repaired; nothing was lost\n");
        } else {
            Narrator::write("  {$repaired} artifact(s) repaired, " . count($dead) . " could not be saved — their slots were substituted (details in the report)\n");
        }
    }

    /**
     * The rescue before the baseline: swap each failing DECLARED ink for the
     * palette's closest compliant slug and re-gate. Deterministic, recorded,
     * never a model call — strictly less destructive than replacing the whole
     * designed section with a stock pattern.
     */
    private function rescueInk(Project $project, TreeGate $gate, string $key, array $palette): bool
    {
        if ($palette === [] || !$project->exists("trees/{$key}.json")) {
            return false;
        }
        $record = $project->readJson("trees/{$key}.json");
        if (!is_array($record['tree'] ?? null)) {
            return false; // no artifact ever satisfied the contract — nothing to rescue
        }
        $tree = $record['tree'];
        $changes = Gates::substituteInk($tree, $palette);
        if ($changes === []) {
            return false; // the failures were never ink-shaped
        }
        $verdict = $gate->gateTree($tree, $palette);
        if ($verdict['status'] !== 'pass') {
            return false;
        }
        $project->writeJson("trees/{$key}.json", ['tree' => $tree, 'gate' => $verdict + ['ink_substituted' => $changes]]);
        return true;
    }

    /**
     * Baselines are gated too: a token-shifted world can invalidate a theme
     * pattern. A pattern baseline that fails degrades to the minimal honest
     * slot (core blocks only) — never bypassed, never improvised.
     */
    private function substituteBaseline(Project $project, TreeGate $gate, string $key, array $palette, string $epoch): void
    {
        $entry = $project->readJson("sections/{$key}.json");
        $blocks = $entry['pattern']['parsed_tree'] ?? null;
        if (!is_array($blocks) || $blocks === []) {
            $blocks = self::minimalSlot($entry);
        }
        $tree = ['version' => 1, 'epoch' => $epoch, 'blocks' => $blocks];
        $verdict = $gate->gateTree($tree, $palette);
        if ($verdict['status'] !== 'pass') {
            $tree = ['version' => 1, 'epoch' => $epoch, 'blocks' => self::minimalSlot($entry)];
        }
        $project->writeJson("trees/{$key}.json", ['tree' => $tree, 'gate' => ['status' => 'baseline']]);
    }

    /**
     * The floor is still a proper band — align full, constrained inner — so
     * a degraded slot never ships clamped to the content column.
     *
     * @param array<string,mixed> $entry sections/<key>.json
     * @return array<int,array<string,mixed>>
     */
    private static function minimalSlot(array $entry): array
    {
        $title = ucwords(str_replace('-', ' ', (string) ($entry['section']['id'] ?? 'section')));
        return [[
            'name'        => 'core/group',
            'attributes'  => ['align' => 'full', 'layout' => ['type' => 'constrained']],
            'innerBlocks' => [
                ['name' => 'core/heading', 'attributes' => ['content' => $title], 'innerBlocks' => []],
                ['name' => 'core/paragraph', 'attributes' => ['content' => (string) ($entry['section']['copy_notes'] ?? '')], 'innerBlocks' => []],
            ],
        ]];
    }
}
