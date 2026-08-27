<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TreeGraph\Budget;
use Automattic\SiteBuild\TreeGraph\Gates;
use Automattic\SiteBuild\TreeGraph\Harness;
use Automattic\SiteBuild\TreeGraph\Normalize;
use Automattic\SiteBuild\TreeGraph\SandboxClient;
use Automattic\SiteBuild\TreeGraph\Styles;
use Automattic\SiteBuild\TreeGraph\TokenMath;
use Automattic\SiteBuild\TreeGraph\TreeGate;
use Automattic\SiteBuild\TreeGraph\TreeLlm;

/**
 * Tree graph step 5: write every section as TreeIR (port of x-pipeline S4).
 *
 * One metered call per section, plus one each for the header and footer
 * template parts, all fired concurrently through completeJsonBatch. Each
 * result runs the mechanical screens (shape, band root, literals, measured
 * ink, image geometry) with ONE schema retry carrying the exact failure
 * list, then registry validation on the sandbox and the compile-parity gate
 * through the site's own wp.blocks. Failures are recorded for the repair
 * stage — a failed section never kills its siblings.
 */
final class SectionTreesStep implements Step
{
    private const OPPOSITE = ['left' => 'center', 'center' => 'left'];

    private const PART_NOTES = [
        'header' => 'You are designing the site HEADER template part: one core/group band containing the brand'
            . ' (core/site-title; optionally core/site-tagline or an uppercase letterspaced kicker paragraph) and'
            . ' EXACTLY ONE core/navigation node carrying attributes only — NO innerBlocks and NO ref: the links'
            . ' are injected at publish; your job is the navigation\'s placement and styling. NO heading blocks in'
            . ' the header (the site title is not a heading). One viewport-wide band that belongs to the same'
            . ' design as the hero under it.',
        'footer' => 'You are designing the site FOOTER template part. The brief wrote its design intent below —'
            . ' follow it as a section call follows its section brief. Link to pages ONLY through the footer items'
            . ' listed. Headings inside the footer are level 2, or styled paragraphs; never an h1. This part ends'
            . ' EVERY page: give it the same design attention as a section.',
    ];

    public function __construct(
        private readonly Llm $llm,
        private readonly ?string $model = null,
        private readonly ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'section-trees';
    }

    public function label(): string
    {
        return 'Write section + furniture trees';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'brief.json',
                'budget.json',
                'tokens.json',
                'instance.json',
                'sections/*',
                'sections-index.json',
                'furniture-slice.json',
                'sandbox.json',
            ],
            writes: ['trees/*', 'artifacts.json'],
            concurrent: true,
        );
    }

    public function run(Project $project): void
    {
        $brief = $project->readJson('brief.json');
        $tokens = $project->readJson('tokens.json');
        $instance = $project->readJson('instance.json');
        $sandbox = $project->readJson('sandbox.json');
        $index = $project->readJson('sections-index.json');
        $furnitureSlice = $project->exists('furniture-slice.json') ? $project->readJson('furniture-slice.json') : [];

        $client = new SandboxClient((string) $sandbox['url']);
        $harness = new Harness(\repo_path());
        $epoch = (string) ($instance['fingerprint'] ?? '');
        $palette = (array) ($tokens['palette'] ?? []);

        $tokenSlugs = [
            // Palette entries carry hex + tone: colour choices are checkable,
            // never guessed from a slug's name (the cream-on-cream lesson).
            'palette'       => TokenMath::annotatePalette($palette),
            'spacing'       => array_map(static fn (array $s): string => (string) $s['slug'], (array) ($tokens['spacing']['steps'] ?? [])),
            'font_sizes'    => array_map(static fn (array $s): string => (string) $s['slug'], (array) ($tokens['typography']['sizes'] ?? [])),
            'font_families' => array_map(static fn (array $f): string => (string) $f['slug'], (array) ($tokens['typography']['families'] ?? [])),
        ];

        $axis = (array) ($brief['axis'] ?? ['anchor' => 'left', 'argument' => '']);
        $language = (string) ($brief['language'] ?? "the language the brief's own copy is written in");
        $comboNote = Styles::renderStyleNote($brief['style'] ?? null);
        $styleNote = $comboNote === '' ? '' : $comboNote
            . "\nIn this section the UI style decides how the composition is EXPRESSED (density, corner language,"
            . ' component shapes — through supports and spacing slugs); the artistic style decides its VOICE (which'
            . ' palette slugs, image treatment, editorial detail). Both live inside the page plan and the site axis.';

        $sectionAnchor = static function (array $section) use ($axis): string {
            $anchor = (string) ($axis['anchor'] ?? 'left');
            return (($section['design']['axis_break'] ?? false) === true)
                ? (self::OPPOSITE[$anchor] ?? 'center')
                : $anchor;
        };
        // Pre-axis briefs named the axis inside the layout enum; both legacy
        // values meant the single-column composition.
        $composition = static fn (?string $layout): string => ['centered' => 'stack', 'left-aligned' => 'stack'][$layout] ?? ($layout ?? 'stack');

        $pagePlans = [];
        foreach ((array) ($brief['pages'] ?? []) as $page) {
            $pagePlans[(string) $page['slug']] = array_map(
                static fn (array $section): array => [
                    'id'     => (string) $section['id'],
                    'role'   => (string) $section['role'],
                    'band'   => (string) ($section['design']['band'] ?? 'base'),
                    'layout' => $composition($section['design']['layout'] ?? null),
                    'axis'   => $sectionAnchor($section),
                    'images' => count(Budget::sectionImageIntents($section)),
                ],
                (array) ($page['sections'] ?? []),
            );
        }

        // The band pair plus its measured ink menus: the choice is constrained
        // before it is judged, so the ink screen below almost never fires.
        $bandColors = static function (string $band) use ($brief, $palette): array {
            $pair = TokenMath::resolveBandColors($band, (array) ($brief['palette'] ?? []), $palette);
            return $pair + TokenMath::resolveInkMenus((string) $pair['background'], $palette);
        };

        $treeValidate = static function (array $v) use ($epoch, $palette): array {
            $issues = Gates::localTreeCheck($v, $epoch);
            if ($issues !== []) {
                return $issues;
            }
            $band = Gates::screenBandRoot($v);
            if ($band !== []) {
                return array_map(static fn (array $f): array => ['path' => $f['path'] ?? '', 'message' => $f['message']], $band);
            }
            $literals = Gates::screenTreeLiterals($v);
            if ($literals !== []) {
                return array_map(static fn (array $f): array => ['path' => $f['path'] ?? '', 'message' => $f['message']], $literals);
            }
            $ink = Gates::screenTreeInk($v, $palette)['failures'];
            if ($ink !== []) {
                return array_map(static fn (array $f): array => ['path' => $f['path'] ?? '', 'message' => $f['message']], $ink);
            }
            return array_map(
                static fn (array $f): array => ['path' => $f['path'] ?? '', 'message' => $f['message']],
                Gates::screenImageGeometry($v),
            );
        };

        $items = [];
        $entries = [];
        foreach ((array) $index as $row) {
            $entry = $project->readJson((string) $row['file']);
            $entries[(string) $row['key']] = $entry;
            $section = (array) $entry['section'];
            $items[(string) $row['key']] = [
                'task_type' => 'tree',
                'label'     => "{$row['page']}/{$row['id']}",
                'payload'   => $this->sectionPayload(
                    $entry,
                    $brief,
                    $styleNote,
                    $language,
                    $pagePlans[(string) $row['page']] ?? [],
                    $axis,
                    $sectionAnchor($section),
                    $composition,
                    $bandColors,
                    $tokenSlugs,
                    $epoch,
                ),
                'validate'  => $treeValidate,
            ];
        }

        $headerShape = static function (array $tree): array {
            $navs = [];
            $walk = static function (array $nodes) use (&$walk, &$navs): void {
                foreach ($nodes as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    if (($node['name'] ?? '') === 'core/navigation') {
                        $navs[] = $node;
                    }
                    $walk((array) ($node['innerBlocks'] ?? []));
                }
            };
            $walk((array) ($tree['blocks'] ?? []));
            if (count($navs) !== 1) {
                return [['path' => '/blocks', 'message' => 'the header carries EXACTLY ONE core/navigation node (found ' . count($navs) . ')']];
            }
            if ((array) ($navs[0]['innerBlocks'] ?? []) !== [] || isset($navs[0]['attributes']['ref'])) {
                return [['path' => '/blocks', 'message' => 'the core/navigation node carries attributes only — no innerBlocks and no ref; the links are injected at publish']];
            }
            return [];
        };
        foreach (['header', 'footer'] as $part) {
            $items["furniture--{$part}"] = [
                'task_type' => 'tree',
                'template'  => 'furniture',
                'label'     => "furniture/{$part}",
                'payload'   => [
                    'part'           => $part,
                    'part_note'      => self::PART_NOTES[$part],
                    'identity'       => $brief['identity'] ?? [],
                    'art_direction'  => (string) ($brief['art_direction'] ?? ''),
                    'style_note'     => $styleNote,
                    'voice'          => (string) ($brief['identity']['voice'] ?? $brief['identity']['tagline'] ?? ''),
                    'language'       => $language,
                    'palette'        => $brief['palette'] ?? [],
                    'axis'           => ['anchor' => $axis['anchor'] ?? 'left', 'argument' => $axis['argument'] ?? ''],
                    'nav_items'      => $brief['navigation']['items'] ?? [],
                    'footer_intent'  => (string) ($brief['footer']['intent'] ?? ''),
                    'footer_items'   => $brief['footer']['items'] ?? [],
                    'band_colors'    => $bandColors($part === 'footer' ? 'contrast' : 'base'),
                    'manifest_slice' => $furnitureSlice,
                    'token_slugs'    => $tokenSlugs,
                    'epoch'          => $epoch,
                ],
                'validate'  => static function (array $v) use ($treeValidate, $headerShape, $palette, $epoch, $part): array {
                    $issues = Gates::localTreeCheck($v, $epoch);
                    if ($issues !== []) {
                        return $issues;
                    }
                    $band = Gates::screenBandRoot($v);
                    if ($band !== []) {
                        return array_map(static fn (array $f): array => ['path' => $f['path'] ?? '', 'message' => $f['message']], $band);
                    }
                    $literals = Gates::screenTreeLiterals($v);
                    if ($literals !== []) {
                        return array_map(static fn (array $f): array => ['path' => $f['path'] ?? '', 'message' => $f['message']], $literals);
                    }
                    $ink = Gates::screenTreeInk($v, $palette)['failures'];
                    if ($ink !== []) {
                        return array_map(static fn (array $f): array => ['path' => $f['path'] ?? '', 'message' => $f['message']], $ink);
                    }
                    return $part === 'header' ? $headerShape($v) : [];
                },
            ];
        }

        Narrator::write('  writing ' . count($entries) . " sections + the header and footer parts, concurrently\n");
        $lane = TreeLlm::forProject($this->llm, $project, $this->model === null ? [] : ['tree' => $this->model], ['tree' => $this->temperature]);
        $results = $lane->generateBatch($items);

        // Registry validation, then ONE batched harness compile for every
        // survivor: the page loads once, every tree meets the same registry.
        $artifacts = ['trees' => [], 'furniture' => []];
        $records = [];
        $toCompile = [];
        foreach ($results as $key => $result) {
            $isFurniture = str_starts_with((string) $key, 'furniture--');
            if ($result['value'] === null) {
                $failures = array_map(
                    static fn (array $i): array => ['code' => 'contract_failed', 'path' => $i['path'] ?? '', 'message' => $i['message'] ?? ''],
                    $result['issues'],
                );
                $records[$key] = ['tree' => null, 'gate' => ['status' => 'fail', 'deferred' => [], 'failures' => $failures]];
                Narrator::write("  {$key}: the model's output never satisfied the contract"
                    . ($isFurniture ? " — the deterministic part is the floor\n" : " — the repair stage gets one attempt\n"));
                continue;
            }
            $tree = $result['value'];
            Normalize::normalizeTreeBorders($tree);
            $validation = $client->validate($tree);
            $screen = Gates::screenTreeDiagnostics($validation, []);
            if (!$isFurniture) {
                // A section carries copy and slugs, never a hardcoded design value.
                $screen['failures'] = array_merge($screen['failures'], Gates::screenTreeLiterals($tree));
            }
            $records[$key] = [
                'tree' => $tree,
                'gate' => $screen + ['diagnostics' => $validation['diagnostics'] ?? []],
            ];
            if ($screen['failures'] === [] && $screen['deferred'] === []) {
                $toCompile[$key] = (array) ($tree['blocks'] ?? []);
            }
        }

        if ($toCompile !== []) {
            $compiled = $harness->compile($project, $client->harnessUrl(), $toCompile);
            $registry = array_map('strval', (array) ($compiled['registry'] ?? []));
            foreach ($toCompile as $key => $_blocks) {
                $result = (array) ($compiled['results'][$key] ?? []);
                $failures = TreeGate::compileFailures($records[$key]['tree'], ['result' => $result, 'registry' => $registry]);
                $records[$key]['gate']['failures'] = array_merge($records[$key]['gate']['failures'], $failures);
            }
        }

        foreach ($records as $key => $record) {
            $isFurniture = str_starts_with((string) $key, 'furniture--');
            $gate = $record['gate'];
            $gate['status'] = ($gate['failures'] ?? []) === [] && $record['tree'] !== null ? 'pass' : 'fail';
            if (!$isFurniture && $record['tree'] !== null) {
                // Muddy-but-legal pairs (3–4.5:1) ride into the record as
                // advisory: visible in the report, never fatal.
                $advisories = Gates::screenTreeInk($record['tree'], $palette)['advisories'];
                if ($advisories !== []) {
                    $gate['ink_advisories'] = $advisories;
                }
            }
            $project->writeJson("trees/{$key}.json", ['tree' => $record['tree'], 'gate' => $gate]);
            $summary = [
                'status'   => $gate['status'],
                'deferred' => $gate['deferred'] ?? [],
                'failures' => $gate['failures'] ?? [],
            ];
            if ($isFurniture) {
                $artifacts['furniture'][substr((string) $key, strlen('furniture--'))] = $summary;
            } else {
                $artifacts['trees'][$key] = $summary;
            }
        }
        $project->writeJson('artifacts.json', $artifacts);

        $passed = count(array_filter($artifacts['trees'], static fn (array $a): bool => $a['status'] === 'pass'));
        Narrator::write('  sections written: ' . $passed . ' of ' . count($artifacts['trees']) . " passed validation\n");
    }

    /**
     * The payload of one section's tree call — the shared design language
     * (page plan, axis, band colors, token slugs) around this section's own
     * brief, manifest slice and starting pattern.
     *
     * @param array<string,mixed> $entry sections/<key>.json
     * @return array<string,mixed>
     */
    private function sectionPayload(
        array $entry,
        array $brief,
        string $styleNote,
        string $language,
        array $pagePlan,
        array $axis,
        string $anchor,
        callable $composition,
        callable $bandColors,
        array $tokenSlugs,
        string $epoch,
    ): array {
        $section = (array) $entry['section'];
        $intents = Budget::sectionImageIntents($section);
        if ($intents === []) {
            $imageNote = 'This section carries no generated image; do not add core/image nodes with empty urls.';
        } else {
            $nodes = implode("\n  ", array_map(
                static fn (string $intent): string => '{"url": "", "metadata": {"imageIntent": ' . json_encode($intent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '}}',
                $intents,
            ));
            $imageNote = 'This section carries ' . count($intents) . ' generated image(s) — include EXACTLY one'
                . " core/image node per intent below, each with these attributes (a placeholder pixel is minted at"
                . " publish time; the real image is generated from the intent):\n  {$nodes}\n"
                . ((($section['role'] ?? '') === 'gallery')
                    ? 'Compose them inside a core/gallery (set columns to fit the count).'
                    : 'Place each image where the layout calls for it.')
                . ' EVERY intent node MUST carry its own geometry — width (usually "100%" of its column) AND'
                . ' aspectRatio, with scale "cover" — because the minted placeholder is a 1×1 pixel and a node'
                . ' without geometry renders at one pixel (sizeSlug alone does nothing for it). Geometry is final,'
                . ' pixels are provisional.';
        }
        $isHeroSlot = in_array($section['role'] ?? '', ['hero', 'header'], true);
        $headingRule = $isHeroSlot
            ? 'This section carries the page\'s SINGLE h1: the statement headline MUST be a core/heading with'
                . ' attributes.level set to 1 EXPLICITLY (core/heading defaults to level 2 when level is omitted).'
                . ' Any further headings inside this section are level 2.'
            : 'This section must NOT contain an h1. Its top heading is a core/heading with attributes.level 2;'
                . ' items/cards inside it use level 3. Never skip a heading level.';

        $design = array_merge(['band' => 'base', 'layout' => 'stack'], (array) ($section['design'] ?? []));
        $design['layout'] = $composition($design['layout']);

        return [
            'section'        => $section,
            'page'           => $entry['page'],
            'art_direction'  => (string) ($brief['art_direction'] ?? ''),
            'style_note'     => $styleNote,
            'voice'          => (string) ($brief['identity']['voice'] ?? $brief['identity']['tagline'] ?? ''),
            'language'       => $language,
            'page_plan'      => $pagePlan,
            'design'         => $design,
            'axis'           => [
                'site'     => $axis['anchor'] ?? 'left',
                'section'  => $anchor,
                'is_break' => ($section['design']['axis_break'] ?? false) === true,
                'argument' => $axis['argument'] ?? '',
            ],
            'band_colors'    => $bandColors((string) $design['band']),
            'manifest_slice' => $entry['manifest_slice'] ?? [],
            'pattern_tree'   => $entry['pattern']['parsed_tree'] ?? null,
            'token_slugs'    => $tokenSlugs,
            'epoch'          => $epoch,
            'image_note'     => $imageNote,
            'heading_rule'   => $headingRule,
        ];
    }
}
