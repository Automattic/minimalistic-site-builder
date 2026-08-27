<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

use Automattic\SiteBuild\Project;

/**
 * The full gate one tree must pass to ship (shared by the repair and publish
 * steps; the section step runs the same sequence batched): registry
 * validation via the companion, the mechanical screens, then the compile
 * parity check through the site's own wp.blocks — the only layer that can
 * see silent content loss (the quote-value class) and blocks missing from
 * the client-side registry.
 */
final class TreeGate
{
    public function __construct(
        private readonly SandboxClient $client,
        private readonly Harness $harness,
        private readonly Project $project,
    ) {}

    /**
     * @param array<string,mixed> $tree
     * @param array<int,array<string,mixed>> $palette applied palette entries
     * @param list<string> $allowedUnknown block names allowed to defer
     * @return array{status: string, deferred: list<string>, failures: array}
     */
    public function gateTree(array $tree, array $palette, array $allowedUnknown = []): array
    {
        try {
            $validation = $this->client->validate($tree);
        } catch (TreeGraphException $e) {
            return [
                'status'   => 'fail',
                'deferred' => [],
                'failures' => [['code' => $e->errorCode, 'message' => $e->getMessage()]],
            ];
        }
        $screen = Gates::screenTreeDiagnostics($validation, $allowedUnknown);

        // The same ink floor the authoring lane enforced: a repaired tree must
        // not sneak an unreadable pair past the gate it was born under.
        $ink = Gates::screenTreeInk($tree, $palette);
        $screen['failures'] = array_merge($screen['failures'], $ink['failures']);

        if ($screen['failures'] === [] && $screen['deferred'] === []) {
            $compiled = $this->compile($tree);
            $screen['failures'] = array_merge($screen['failures'], self::compileFailures($tree, $compiled));
        }
        $screen['status'] = $screen['failures'] === [] ? 'pass' : 'fail';
        return $screen;
    }

    /**
     * Validate + screen + compile a template-part tree at the current epoch;
     * the compiled markup, or null when any layer refuses (the caller keeps
     * its deterministic floor).
     *
     * @param array<string,mixed> $tree
     */
    public function compileMarkup(array $tree): ?string
    {
        try {
            $validation = $this->client->validate($tree);
        } catch (TreeGraphException) {
            return null;
        }
        $screen = Gates::screenTreeDiagnostics($validation, []);
        if ($screen['status'] !== 'pass') {
            return null;
        }
        $compiled = $this->compile($tree);
        if (self::compileFailures($tree, $compiled) !== []) {
            return null;
        }
        return (string) ($compiled['result']['markup'] ?? '') ?: null;
    }

    /**
     * One tree through the harness. Returns ['result' => compile result,
     * 'registry' => list of registered names].
     *
     * @param array<string,mixed> $tree
     * @return array{result: array<string,mixed>, registry: list<string>}
     */
    public function compile(array $tree): array
    {
        $out = $this->harness->compile(
            $this->project,
            $this->client->harnessUrl(),
            ['tree' => (array) ($tree['blocks'] ?? [])],
        );
        return [
            'result'   => (array) ($out['results']['tree'] ?? []),
            'registry' => array_map('strval', (array) ($out['registry'] ?? [])),
        ];
    }

    /**
     * The compile-side failures for one tree: a harness error, a registry
     * gap, a round-trip invalidity, or silent content loss.
     *
     * @param array<string,mixed> $tree
     * @param array{result: array<string,mixed>, registry: list<string>} $compiled
     */
    public static function compileFailures(array $tree, array $compiled): array
    {
        $result = $compiled['result'];
        if (isset($result['error'])) {
            return [['code' => 'harness_error', 'message' => (string) $result['error']]];
        }
        $failures = [];
        $registry = array_fill_keys($compiled['registry'], true);
        foreach (self::blockNames($tree) as $name) {
            if ($registry !== [] && !isset($registry[$name])) {
                $failures[] = [
                    'code'    => 'harness_gap',
                    'message' => "block \"{$name}\" is registered server-side but missing from the harness registry",
                ];
            }
        }
        return array_merge($failures, Gates::screenContentParity($result));
    }

    /**
     * Every block name used anywhere in the tree.
     *
     * @param array<string,mixed> $tree
     * @return list<string>
     */
    public static function blockNames(array $tree): array
    {
        $names = [];
        $walk = static function (array $nodes) use (&$walk, &$names): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $name = (string) ($node['name'] ?? '');
                if ($name !== '') {
                    $names[$name] = true;
                }
                $walk((array) ($node['innerBlocks'] ?? []));
            }
        };
        $walk((array) ($tree['blocks'] ?? []));
        return array_keys($names);
    }
}
