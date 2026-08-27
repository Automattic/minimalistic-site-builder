<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

use Automattic\SiteBuild\Project;

/**
 * Drives bin/sandbox/harness.mjs — the headless-Chrome side of the tree
 * graph. Two commands:
 *
 *  - compile: load the companion's harness page (real wp.blocks, the site's
 *    own block registry) and run window.__compile over each tree's blocks.
 *    This is where serialized markup is BORN — no PHP in this graph ever
 *    hand-writes a block comment.
 *  - verify: navigate the finished site and measure it — box tree, heading
 *    outline, own-text contrast, image load state.
 *
 * One driver invocation per batch: the page loads once and every tree
 * compiles against the same warm registry.
 */
final class Harness
{
    public function __construct(private readonly string $repoRoot) {}

    /**
     * Compile trees through the site's own wp.blocks.
     *
     * @param array<string,array<int,array<string,mixed>>> $treesByKey key => blocks[]
     * @return array{registry: list<string>, results: array<string,array<string,mixed>>}
     */
    public function compile(Project $project, string $harnessUrl, array $treesByKey): array
    {
        // json_decode(assoc) collapsed every empty attributes object to [];
        // restore {} so wp.blocks receives objects where objects were meant.
        $trees = [];
        foreach ($treesByKey as $key => $blocks) {
            $trees[$key] = array_map([self::class, 'blocksAsObjects'], $blocks);
        }
        $result = $this->run($project, [
            'command'     => 'compile',
            'harness_url' => $harnessUrl,
            'trees'       => $trees === [] ? new \stdClass() : $trees,
        ]);
        if (!isset($result['registry']) || !isset($result['results'])) {
            throw new TreeGraphException('harness_error', 'The harness driver returned no registry/results.', '', ['result' => $result]);
        }
        return $result;
    }

    /**
     * Measure a live page.
     *
     * @param list<string> $blockNames manifest block names, for box-tree naming
     * @param array<string,mixed> $opts wait, nav_timeout_ms, viewport
     * @return array<string,mixed> box_tree, a11y_outline, text_contrast, images, measured
     */
    public function verify(Project $project, string $url, array $blockNames, array $opts = []): array
    {
        return $this->run($project, [
            'command'     => 'verify',
            'url'         => $url,
            'block_names' => $blockNames,
        ] + $opts);
    }

    /**
     * @param array<string,mixed> $command
     * @return array<mixed>
     */
    private function run(Project $project, array $command): array
    {
        $driver = $this->repoRoot . '/bin/sandbox/harness.mjs';
        if (!is_file($driver)) {
            throw new TreeGraphException('harness_error', "The harness driver is missing: {$driver}", 'Run `npm ci` at the repository root.');
        }

        $commandPath = $project->logPath('harness-command-' . substr(hash('sha256', uniqid('', true)), 0, 8) . '.json');
        file_put_contents($commandPath, json_encode(
            $command,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        $shell = sprintf('node %s %s', escapeshellarg($driver), escapeshellarg($commandPath));
        $proc = proc_open($shell, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $this->repoRoot);
        if (!is_resource($proc)) {
            @unlink($commandPath);
            throw new TreeGraphException('harness_error', 'Could not start the harness driver.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($commandPath);

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            throw new TreeGraphException(
                'harness_error',
                'The harness driver produced no JSON (exit ' . $exit . '): ' . substr(trim($stderr) !== '' ? $stderr : $stdout, 0, 400),
                'CHROME/CHROME_BIN selects the browser; the driver needs a system Chrome.',
            );
        }
        if (isset($decoded['error'])) {
            throw new TreeGraphException('harness_error', (string) $decoded['error'], '', ['stderr' => $stderr]);
        }
        return $decoded;
    }

    /**
     * Recursively rebuild one BlockNode so empty attribute maps are stdClass
     * (encode as {}) and absent members stay absent.
     *
     * @param array<string,mixed> $node
     */
    public static function blocksAsObjects(array $node): array
    {
        $out = ['name' => $node['name'] ?? ''];
        if (array_key_exists('attributes', $node)) {
            $attributes = $node['attributes'];
            $out['attributes'] = $attributes === [] ? new \stdClass() : self::attributeValues($attributes);
        }
        if (array_key_exists('innerBlocks', $node) && is_array($node['innerBlocks'])) {
            $out['innerBlocks'] = array_map([self::class, 'blocksAsObjects'], $node['innerBlocks']);
        }
        return $out;
    }

    /**
     * Inside attribute values, an empty PHP array is ambiguous; keep it an
     * array (JSON []) except for known object-shaped keys, where {} is the
     * grammar. Non-empty assoc arrays already encode as objects.
     */
    private static function attributeValues(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $child) {
            $out[$key] = self::attributeValues($child);
        }
        return $out;
    }
}
