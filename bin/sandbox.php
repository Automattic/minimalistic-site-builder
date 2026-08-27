<?php
declare(strict_types=1);

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\TreeGraph\Sandbox;

/**
 * Inspect or stop a tree-graph project's sandbox WordPress.
 *
 *   php bin/sandbox.php <slug>          status: is the sandbox up, and where
 *   php bin/sandbox.php <slug> --stop   stop it and drop sandbox.json
 *
 * The sandbox is the detached Playground a `--tree` build ran against — it
 * stays up after the build so the finished site can be browsed. The site is
 * ephemeral: stopping it discards the WordPress (the project's artifacts on
 * disk remain, and a new build boots a fresh sandbox).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = parse_cli_args($argv, ['--stop' => 'bool'], maxPositionals: 1);
if ($args['unknown'] !== null || ($args['positionals'][0] ?? '') === '') {
    Narrator::write("Usage: php bin/sandbox.php <slug> [--stop]\n");
    print_built_projects(Narrator::stream() ?? STDERR);
    exit(1);
}
$slug = $args['positionals'][0];
$stop = $args['flags']['--stop'] ?? false;

$builder = make_site_builder(make_llm_optional());
try {
    $project = $builder->store()->open($slug);
} catch (RuntimeException $e) {
    Narrator::write($e->getMessage() . "\n");
    exit(1);
}

if (!$project->exists('sandbox.json')) {
    echo "No sandbox recorded for '{$slug}'.\n";
    exit($stop ? 0 : 1);
}
$record = $project->readJson('sandbox.json');
$url = (string) ($record['url'] ?? '');

if ($stop) {
    Sandbox::stop($project);
    echo "Sandbox for '{$slug}' stopped.\n";
    exit(0);
}

$alive = $url !== '' && Sandbox::alive($url);
echo "Sandbox for '{$slug}': " . ($alive ? 'RUNNING' : 'not answering') . "\n";
echo "  url:   {$url}\n";
echo '  pid:   ' . (int) ($record['pid'] ?? 0) . "\n";
echo '  log:   ' . (string) ($record['log_path'] ?? '') . "\n";
if (!$alive) {
    echo "  (a --tree build or resume will boot a fresh one)\n";
}
exit($alive ? 0 : 1);

/**
 * This CLI never calls the model, but make_site_builder wants an Llm; only
 * construct the real transport when the key happens to be configured, and
 * fall back to a stub otherwise so status/stop work without any API key.
 */
function make_llm_optional(): Automattic\SiteBuild\Llm
{
    try {
        return make_llm();
    } catch (RuntimeException) {
        return new class implements Automattic\SiteBuild\Llm {
            public function complete(string $prompt, array $opts = []): string
            {
                throw new RuntimeException('bin/sandbox.php makes no LLM calls');
            }

            public function completeJson(string $prompt, array $opts = []): array
            {
                throw new RuntimeException('bin/sandbox.php makes no LLM calls');
            }

            public function completeJsonBatch(array $requests): array
            {
                throw new RuntimeException('bin/sandbox.php makes no LLM calls');
            }

            public function completeBatch(array $requests): Automattic\SiteBuild\TextBatchResult
            {
                throw new RuntimeException('bin/sandbox.php makes no LLM calls');
            }
        };
    }
}
