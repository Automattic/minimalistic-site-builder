<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TreeGraph\Harness;
use Automattic\SiteBuild\TreeGraph\Ledger;
use Automattic\SiteBuild\TreeGraph\SandboxClient;
use Automattic\SiteBuild\TreeGraph\TreeGate;
use Automattic\SiteBuild\TreeGraph\TreeGraphException;

/**
 * Tree graph step 8: the image pass (port of x-pipeline S8's image lane,
 * through this package's own ImageClient instead of the x-agent tools).
 *
 * Off by default: without --with-images the minted placeholder pixels STAY
 * — each 1×1 GIF carries its written imageIntent in the markup, ready for a
 * later fill — and no image call is spent (the brief already priced the run
 * without them). With images on: one generation per intent, uploaded to the
 * sandbox's media library, swapped onto the exact nodes, and the page
 * recompiled through the harness so the shipped markup is still wp.blocks
 * output. A failed image warns and leaves its placeholder — never a dead run.
 */
final class TreeImagesStep implements Step
{
    /** Aspect ratios the image transport accepts, as width/height floats. */
    private const RATIOS = [
        '1:1'  => 1.0,
        '16:9' => 16 / 9,
        '21:9' => 21 / 9,
        '9:16' => 9 / 16,
        '4:3'  => 4 / 3,
        '3:4'  => 3 / 4,
    ];

    public function __construct(private readonly ?ImageClient $images = null) {}

    public function id(): string
    {
        return 'tree-images';
    }

    public function label(): string
    {
        return 'Generate + apply real images';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json', 'brief.json', 'published.json', 'trees/*', 'sandbox.json'],
            writes: ['images/*', 'pages/*', 'trees/*', 'warnings.json'],
            concurrent: true,
        );
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        if (!(bool) ($meta['tree_images'] ?? false)) {
            Narrator::write("  image generation skipped — the placeholder pixels stay in place\n");
            return;
        }
        if ($this->images === null) {
            throw new TreeGraphException(
                'preflight_failed',
                'tree-images was asked to generate but no ImageClient was wired',
                'The CLI builds this step with make_image_client(); set GOOGLE_VERTEX_API_TOKEN.',
            );
        }

        $brief = $project->readJson('brief.json');
        $published = $project->readJson('published.json');
        $sandbox = $project->readJson('sandbox.json');
        $client = new SandboxClient((string) $sandbox['url']);
        $harness = new Harness(\repo_path());
        $ledger = new Ledger($project);
        $artDirection = (string) ($brief['art_direction'] ?? '');
        $manifest = [];

        foreach ((array) ($published['pages'] ?? []) as $page) {
            if (!(bool) ($page['has_images'] ?? false)) {
                continue;
            }
            $slug = (string) $page['slug'];
            $tree = $project->readJson("trees/page--{$slug}.json");

            // Collect every intent node, in document order, by reference path.
            $nodes = [];
            $this->collectIntentNodes($tree['blocks'], $nodes);
            if ($nodes === []) {
                continue;
            }
            Narrator::write('  generating ' . count($nodes) . " real image(s) for /{$slug}/ — one image-model call each\n");

            $specs = [];
            foreach ($nodes as $i => $node) {
                $intent = (string) $node['intent'];
                $specs[$i] = [
                    'prompt'       => $intent
                        . ($artDirection !== '' ? "\nStyle: {$artDirection}" : '')
                        . "\nNo text, no watermarks, no typography, no logos.",
                    'aspect_ratio' => self::nearestRatio($node['aspect']),
                ];
            }
            $started = (int) round(microtime(true) * 1000);
            $results = $this->images->generateBatch($specs);

            $applied = 0;
            foreach ($results as $i => $result) {
                $intent = (string) $nodes[$i]['intent'];
                $ledger->record([
                    'task_type'    => 'image',
                    'label'        => "{$slug}/{$i}",
                    'provider'     => 'image',
                    'model'        => $this->images->model(),
                    'prompt_hash'  => hash('sha256', $specs[$i]['prompt']),
                    'payload_hash' => hash('sha256', $intent),
                    'usage'        => ['input_tokens' => 0, 'output_tokens' => 0],
                    'attempt'      => 1,
                    'outcome'      => ($result['ok'] ?? false) ? 'ok' : 'error',
                    'started_at'   => $started,
                    'ms'           => 0,
                ]);
                if (!($result['ok'] ?? false) || !isset($result['bytes'])) {
                    $project->addWarnings($this->id(), [
                        "/{$slug}/ image {$i} failed to generate (" . (string) ($result['error'] ?? 'unknown') . '); its placeholder pixel stays',
                    ]);
                    $manifest[] = ['page' => $slug, 'index' => $i, 'intent' => $intent, 'ok' => false];
                    continue;
                }
                $filename = "{$slug}-image-" . ($i + 1) . '.jpg';
                $project->writeText("images/{$filename}", $result['bytes']);
                $media = $client->uploadMedia($filename, 'image/jpeg', $result['bytes'], mb_substr($intent, 0, 120));
                $this->assignAttachment($tree['blocks'], (int) $nodes[$i]['ordinal'], (string) $media['url'], (int) $media['id']);
                $manifest[] = ['page' => $slug, 'index' => $i, 'intent' => $intent, 'ok' => true, 'file' => "images/{$filename}", 'attachment' => (int) $media['id']];
                $applied++;
            }

            if ($applied > 0) {
                // Recompile through the site's own save() so the published
                // markup still round-trips, then update the page in place.
                $compiled = $harness->compile($project, $client->harnessUrl(), [$slug => (array) $tree['blocks']]);
                $result = (array) ($compiled['results'][$slug] ?? []);
                $failures = TreeGate::compileFailures($tree, [
                    'result'   => $result,
                    'registry' => array_map('strval', (array) ($compiled['registry'] ?? [])),
                ]);
                if ($failures !== []) {
                    throw new TreeGraphException(
                        'gate_failed',
                        "/{$slug}/ no longer compiles after its images were applied",
                        '',
                        ['failures' => $failures],
                    );
                }
                $markup = (string) $result['markup'];
                $client->updatePageContent((int) $page['id'], $markup);
                $project->writeText("pages/{$slug}.html", $markup);
                $project->writeJson("trees/page--{$slug}.json", $tree);
                Narrator::write("  /{$slug}/: {$applied} image(s) generated and applied\n");
            }
        }

        $project->writeJson('images/images-manifest.json', $manifest);
    }

    /**
     * Every core/image node carrying an imageIntent, in document order.
     * `ordinal` is its position in that order, so a later by-reference walk
     * can address the same node.
     *
     * @param array<int,array<string,mixed>> $blocks
     * @param array<int,array{ordinal: int, intent: string, aspect: ?string}> $nodes
     */
    private function collectIntentNodes(array $blocks, array &$nodes): void
    {
        foreach ($blocks as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['name'] ?? '') === 'core/image' && isset($node['attributes']['metadata']['imageIntent'])) {
                $nodes[] = [
                    'ordinal' => count($nodes),
                    'intent'  => (string) $node['attributes']['metadata']['imageIntent'],
                    'aspect'  => isset($node['attributes']['aspectRatio']) ? (string) $node['attributes']['aspectRatio'] : null,
                ];
            }
            if (isset($node['innerBlocks']) && is_array($node['innerBlocks'])) {
                $this->collectIntentNodes($node['innerBlocks'], $nodes);
            }
        }
    }

    /**
     * Point the Nth intent node at its real attachment, in place.
     *
     * @param array<int,array<string,mixed>> $blocks
     */
    private function assignAttachment(array &$blocks, int $ordinal, string $url, int $id, int &$seen = 0): bool
    {
        foreach ($blocks as &$node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['name'] ?? '') === 'core/image' && isset($node['attributes']['metadata']['imageIntent'])) {
                if ($seen === $ordinal) {
                    $node['attributes']['url'] = $url;
                    $node['attributes']['id'] = $id;
                    return true;
                }
                $seen++;
            }
            if (isset($node['innerBlocks']) && is_array($node['innerBlocks'])
                && $this->assignAttachment($node['innerBlocks'], $ordinal, $url, $id, $seen)
            ) {
                return true;
            }
        }
        unset($node);
        return false;
    }

    /** The transport ratio nearest to the node's declared aspectRatio. */
    public static function nearestRatio(?string $aspect): string
    {
        $value = null;
        if ($aspect !== null && $aspect !== '') {
            if (preg_match('#^\s*(\d+(?:\.\d+)?)\s*[/:]\s*(\d+(?:\.\d+)?)\s*$#', $aspect, $m) === 1 && (float) $m[2] > 0) {
                $value = (float) $m[1] / (float) $m[2];
            } elseif (is_numeric($aspect)) {
                $value = (float) $aspect;
            }
        }
        if ($value === null || $value <= 0) {
            return '4:3';
        }
        $best = '4:3';
        $bestDelta = PHP_FLOAT_MAX;
        foreach (self::RATIOS as $name => $ratio) {
            $delta = abs($ratio - $value);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = $name;
            }
        }
        return $best;
    }
}
