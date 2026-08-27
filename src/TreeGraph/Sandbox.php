<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\PlaygroundRunner;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SitePreset;

/**
 * The tree graph's live WordPress: a detached Playground boot with the
 * msb-companion plugin mounted, described by projects/<slug>/sandbox.json.
 *
 * Unlike PlaygroundRunner (which previews a finished theme and dies with the
 * CLI process), this sandbox must outlive individual steps and CLI
 * invocations: the pipeline builds INTO it, --from resumes reconnect to it,
 * and after the build it IS the site the user opens. So the server is spawned
 * through `sh -c '... & echo $!'` — fully detached, no proc handle held —
 * and later invocations find it again through sandbox.json + a fingerprint
 * ping. Teardown matches PlaygroundRunner: kill the recorded pid's tree, then
 * pkill the blueprint path (the reparented node server keeps it in argv).
 */
final class Sandbox
{
    public const DEFAULT_PORT = 9420;

    /** How long a fresh boot may take before we give up (first run downloads WP). */
    private const BOOT_TIMEOUT_SECONDS = 300;

    /**
     * A live sandbox for this project: reconnect to the recorded one when it
     * still answers, boot a fresh one otherwise. Returns the sandbox record
     * (also persisted as sandbox.json).
     *
     * @return array{url: string, port: int, pid: int, blueprint_path: string, log_path: string, started_at: string}
     */
    public static function connect(Project $project, ?int $port = null): array
    {
        if ($project->exists('sandbox.json')) {
            $record = $project->readJson('sandbox.json');
            $url = (string) ($record['url'] ?? '');
            if ($url !== '' && self::alive($url)) {
                return $record;
            }
            Narrator::write("  sandbox.json points at a dead server — booting a fresh sandbox\n");
            $port ??= (int) ($record['port'] ?? self::DEFAULT_PORT);
        }

        return self::boot($project, $port ?? self::DEFAULT_PORT);
    }

    /** Whether the companion answers at this base URL. */
    public static function alive(string $baseUrl): bool
    {
        try {
            (new SandboxClient($baseUrl))->fingerprint();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Boot a detached Playground with the companion plugin and wait until it
     * serves. The blueprint defines MSB_COMPANION_SANDBOX through an
     * mu-plugin, which is what unlocks the companion's routes — the plugin
     * refuses to serve anywhere that constant is absent.
     *
     * @return array{url: string, port: int, pid: int, blueprint_path: string, log_path: string, started_at: string}
     */
    public static function boot(Project $project, int $port = self::DEFAULT_PORT): array
    {
        if (!\command_exists('node')) {
            throw new TreeGraphException('sandbox_boot_failed', 'Node.js is required to run the WordPress Playground sandbox.');
        }
        $playgroundCli = \repo_path('node_modules/.bin/wp-playground-cli');
        if (!is_file($playgroundCli)) {
            throw new TreeGraphException(
                'sandbox_boot_failed',
                'WordPress Playground CLI is not installed.',
                'Run `npm ci` at the repository root.',
            );
        }
        $companionDir = \repo_path('sandbox/companion');
        if (!is_file($companionDir . '/msb-companion.php')) {
            throw new TreeGraphException('sandbox_boot_failed', "The companion plugin is missing: {$companionDir}");
        }

        $requestedPort = $port;
        $port = PlaygroundRunner::freePort($port);
        if ($port !== $requestedPort) {
            Narrator::write("  Port {$requestedPort} is in use — using {$port} instead.\n");
        }

        $blueprintPath = $project->logPath('sandbox-blueprint.json');
        $logPath = $project->logPath('sandbox.log');
        file_put_contents($blueprintPath, json_encode(
            self::blueprint($project),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        // Truncate the log so this boot's readiness line is the one we find.
        file_put_contents($logPath, '');

        $mount = $companionDir . ':/wordpress/wp-content/plugins/msb-companion';
        $server = sprintf(
            '%s server --login --workers=%s --port=%d --mount=%s --blueprint=%s',
            escapeshellarg($playgroundCli),
            escapeshellarg('2'),
            $port,
            escapeshellarg($mount),
            escapeshellarg($blueprintPath),
        );
        // Detached on purpose: `sh` backgrounds nohup+node and echoes the pid,
        // then exits, so no proc resource ties the server to this process.
        $spawn = sprintf(
            'nohup %s >> %s 2>&1 & echo $!',
            $server,
            escapeshellarg($logPath),
        );
        $pid = (int) trim((string) shell_exec('sh -c ' . escapeshellarg($spawn)));
        if ($pid <= 0) {
            throw new TreeGraphException('sandbox_boot_failed', 'Could not spawn the Playground sandbox.');
        }

        $url = self::waitUntilReady($logPath, $pid);
        $record = [
            'url'            => $url,
            'port'           => $port,
            'pid'            => $pid,
            'blueprint_path' => $blueprintPath,
            'log_path'       => $logPath,
            'started_at'     => gmdate('c'),
        ];
        $project->writeJson('sandbox.json', $record);

        // The blueprint has run, but the companion may still be warming its
        // first manifest; one ping proves the routes answer before any step
        // depends on them.
        $deadline = microtime(true) + 60;
        while (!self::alive($url)) {
            if (microtime(true) >= $deadline) {
                throw new TreeGraphException(
                    'sandbox_boot_failed',
                    'The sandbox serves WordPress but the msb-companion routes never answered.',
                    "Check {$logPath} — the plugin may have failed to activate.",
                );
            }
            usleep(500_000);
        }

        return $record;
    }

    /** Stop the recorded sandbox (best effort) and drop sandbox.json. */
    public static function stop(Project $project): void
    {
        if (!$project->exists('sandbox.json')) {
            return;
        }
        $record = $project->readJson('sandbox.json');
        $pid = (int) ($record['pid'] ?? 0);
        if ($pid > 0) {
            \kill_tree($pid);
        }
        $blueprintPath = (string) ($record['blueprint_path'] ?? '');
        if ($blueprintPath !== '') {
            @exec('pkill -f ' . escapeshellarg(preg_quote($blueprintPath, '~')) . ' 2>/dev/null');
        }
        @unlink($project->path('sandbox.json'));
    }

    /**
     * The Playground blueprint: sandbox constant + offline guard mu-plugins,
     * pretty permalinks, companion activation. The default theme (WordPress
     * latest ships Twenty Twenty-Five) stays active — the tree graph builds
     * on the instance's own theme instead of generating one.
     *
     * @return array<string,mixed>
     */
    public static function blueprint(Project $project): array
    {
        $sandboxConstant = "<?php\n"
            . "/**\n"
            . " * Marks this WordPress as a disposable local sandbox. The\n"
            . " * msb-companion plugin serves its routes ONLY where this is\n"
            . " * defined, so the plugin is inert anywhere else it might land.\n"
            . " */\n"
            . "define( 'MSB_COMPANION_SANDBOX', true );\n";

        return [
            '$schema'     => 'https://playground.wordpress.net/blueprint-schema.json',
            'landingPage' => '/',
            'login'       => true,
            'steps'       => [
                [
                    'step' => 'writeFile',
                    'path' => '/wordpress/wp-content/mu-plugins/0-msb-sandbox.php',
                    'data' => $sandboxConstant,
                ],
                [
                    'step' => 'writeFile',
                    'path' => '/wordpress/wp-content/mu-plugins/1-msb-offline.php',
                    'data' => SitePreset::offlineGuardPhp(),
                ],
                [
                    'step'    => 'setSiteOptions',
                    'options' => [
                        'blogname'            => $project->slug(),
                        // Pretty permalinks so published pages resolve at /<slug>/.
                        'permalink_structure' => '/%postname%/',
                    ],
                ],
                [
                    'step'       => 'activatePlugin',
                    'pluginPath' => 'msb-companion/msb-companion.php',
                ],
            ],
        ];
    }

    /** Poll the boot log for Playground's readiness line. */
    private static function waitUntilReady(string $logPath, int $pid): string
    {
        $deadline = microtime(true) + self::BOOT_TIMEOUT_SECONDS;
        while (true) {
            $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
            $url = PlaygroundRunner::readyUrl($log);
            if ($url !== null) {
                return $url;
            }
            if (!self::processAlive($pid)) {
                throw new TreeGraphException(
                    'sandbox_boot_failed',
                    "The Playground sandbox exited before it was ready.\n" . $log,
                );
            }
            if (microtime(true) >= $deadline) {
                \kill_tree($pid);
                throw new TreeGraphException(
                    'sandbox_boot_failed',
                    'The Playground sandbox did not become ready within ' . self::BOOT_TIMEOUT_SECONDS . 's.',
                    "Check {$logPath}.",
                );
            }
            usleep(300_000);
        }
    }

    private static function processAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        @exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $status);
        return $status === 0;
    }
}
