<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Boots a generated project in WordPress Playground (ephemeral, not persisted).
 */
final class PlaygroundRunner implements SiteRunner
{
    public function __construct(
        private readonly int $port = 9400,
        private readonly string $workers = '2',
    ) {}

    public function name(): string
    {
        return 'playground';
    }

    /**
     * Boot Playground and return the running site.
     *
     * $timeoutSeconds is the wait-for-ready budget (default 240, matching the
     * old bin/screenshot.php wrapper). A CLI that stays alive but never prints
     * Ready! throws RuntimeException("Playground did not become ready within Ns").
     */
    public function start(Project $project, int $timeoutSeconds = 240): RunningSite
    {
        if (!command_exists('node')) {
            throw new \RuntimeException('Node.js is required to run WordPress Playground.');
        }
        // repo_path() lives in bootstrap.php, which the WPCom host never loads.
        // This file is src/, so dirname(__DIR__) is the same repo root.
        $playgroundCli = dirname(__DIR__) . '/node_modules/.bin/wp-playground-cli';
        if (!is_file($playgroundCli)) {
            throw new \RuntimeException('WordPress Playground CLI is not installed. Run `npm ci` at the repository root.');
        }

        $requestedPort = $this->port;
        $port = self::freePort($this->port);
        if ($port !== $requestedPort) {
            Narrator::write("Port {$requestedPort} is in use — using {$port} instead.\n");
        }

        $slug = $project->slug();
        $steps = [...SitePreset::sharedSteps($project), ['step' => 'activateTheme', 'themeFolderName' => $slug]];
        $hasPlugin = is_file($project->pluginPath('site-content.php'));
        if ($hasPlugin) {
            // AFTER the theme: the seeder resolves asset URLs against the active
            // stylesheet when it creates the pages.
            $steps[] = ['step' => 'activatePlugin', 'pluginPath' => "{$slug}-content/site-content.php"];
        }
        $blueprint = SitePreset::wrapBlueprint($steps);

        // Pid-stamped, instance-unique path — the why lives on the helper.
        $blueprintPath = playground_blueprint_path($slug, getmypid());
        $blueprintJson = json_encode(
            $blueprint,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        if (file_put_contents($blueprintPath, $blueprintJson) === false) {
            throw new \RuntimeException("Failed to write blueprint to {$blueprintPath}");
        }

        $mount = $project->themePath() . ':/wordpress/wp-content/themes/' . $slug;
        $pluginMount = $hasPlugin ? $project->pluginPath() . ':/wordpress/wp-content/plugins/' . $slug . '-content' : null;

        // `server` (not `start`) because it accepts --workers, which caps the
        // request-handling worker threads — each one holds a full PHP wasm runtime, so
        // the CLI default of min(6, cpus-1) costs several hundred MB we don't need for
        // a single-user preview. The CLI warns that fewer than 6 workers raises the
        // odds of deadlock on file locks; with our sequential preview/screenshot
        // traffic that hasn't been an issue, and 2 keeps a spare worker for loopback
        // subrequests. Override with --workers=auto to get the old behaviour.
        //
        // `server` also boots an ephemeral site (nothing persisted under
        // ~/.wordpress-playground/sites), which sidesteps the persisted-mount-point
        // problem that used to require `start --reset`: a persisted empty
        // wp-content/themes/<slug> folder would shadow the theme --mount on the next
        // boot and activation failed with "Stylesheet is missing". No persisted site,
        // no shadowing — and no auto-mount either (for `server` it is opt-in), so the
        // only mount is the one we pass. Booting from scratch costs no extra time
        // versus the old `start --reset`, which wiped the site every run anyway.
        $cmd = sprintf(
            '%s server --login --workers=%s --port=%d --mount=%s%s --blueprint=%s 2>&1',
            escapeshellarg($playgroundCli),
            escapeshellarg($this->workers),
            $port,
            escapeshellarg($mount),
            $pluginMount !== null ? ' --mount=' . escapeshellarg($pluginMount) : '',
            escapeshellarg($blueprintPath)
        );

        // Stream CLI output to STDOUT so a human watching the boot still sees
        // the Ready! line. Do not passthru — we have to return RunningSite.
        // Merge stderr with 2>&1 onto ONE file descriptor: two fds on the same
        // path (w + a) overwrite each other and the poll loop can see Ready!
        // in the full log while the echo offset has already walked past the
        // clobbered bytes.
        $logPath = sys_get_temp_dir() . "/playground-{$slug}." . getmypid() . ".log";
        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logPath, 'w']],
            $pipes,
            dirname(__DIR__)
        );
        if (!is_resource($proc)) {
            @unlink($blueprintPath);
            throw new \RuntimeException('Could not start Playground.');
        }

        $childPid = (int) (proc_get_status($proc)['pid'] ?? 0);
        try {
            $url = self::waitUntilReady($proc, $logPath, $timeoutSeconds);
        } catch (\Throwable $e) {
            self::teardown($proc, $childPid, $blueprintPath);
            @unlink($logPath);
            throw $e;
        }

        $stopped = false;
        return new RunningSite(
            url: $url,
            adminUrl: $url . 'wp-admin/',
            persistent: false,
            stop: static function () use (&$stopped, $proc, $childPid, $blueprintPath, $logPath): void {
                if ($stopped) {
                    return;
                }
                $stopped = true;
                self::teardown($proc, $childPid, $blueprintPath);
                @unlink($logPath);
            },
        );
    }

    /**
     * The site URL from Playground's readiness line, or null until it appears.
     *
     * Playground prints this exact line once it is actually serving, and it carries
     * the real port (playground.php auto-bumps a busy one). Spawners rely on it
     * because the site's `/` answers 302, not 200 — polling for a 200 would never
     * succeed. Colour escapes are stripped first: the CLI wraps "Ready!" and the
     * URL in them when stdout is a TTY (and some envs force colour even when it
     * isn't, e.g. FORCE_COLOR), and they sit between the two, breaking the \s+
     * match. $log may be the whole log or a single streamed line.
     */
    public static function readyUrl(string $log): ?string
    {
        $plain = preg_replace('~\x1b\[[0-9;]*m~', '', $log) ?? $log;
        if (!preg_match('~Ready!\s+WordPress is running on (http://127\.0\.0\.1:\d+)~', $plain, $m)) {
            return null;
        }
        return $m[1] . '/';
    }

    /**
     * Stop one Playground boot: the php wrapper, its Playground/node subtree, and
     * the reparented node server (once the launcher exits it reparents to init and
     * escapes the tree walk — but it keeps the blueprint path in its argv).
     */
    public static function teardown($proc, int $pid, string $blueprintPath): void
    {
        if ($pid > 0) {
            kill_tree($pid);
        }
        @exec('pkill -f ' . escapeshellarg(preg_quote($blueprintPath, '~')) . ' 2>/dev/null');
        @unlink($blueprintPath);
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /** Return the first free TCP port at or after $start (fails after 50 tries). */
    public static function freePort(int $start): int
    {
        for ($port = $start; $port < $start + 50; $port++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn === false) {
                return $port; // nothing listening — free
            }
            fclose($conn);
        }
        throw new \RuntimeException(sprintf('No free TCP port in %d..%d.', $start, $start + 49));
    }

    /**
     * Block until Playground prints its readiness line, the process dies, or
     * $timeoutSeconds elapses. Public so tests can fire the deadline against a
     * process that stays alive without ever becoming ready.
     *
     * @param resource $proc
     */
    public static function waitUntilReady($proc, string $logPath, int $timeoutSeconds = 240): string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $offset = 0;
        while (true) {
            $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
            if (strlen($log) < $offset) {
                $offset = 0;
            }
            $new = substr($log, $offset);
            if ($new !== '') {
                echo $new;
                fflush(STDOUT);
                $offset = strlen($log);
            }
            $url = self::readyUrl($log);
            if ($url !== null) {
                if (!str_contains($new, 'Ready!')) {
                    echo "Ready! WordPress is running on " . rtrim($url, '/') . "\n";
                    fflush(STDOUT);
                }
                return $url;
            }
            if (!proc_get_status($proc)['running']) {
                throw new \RuntimeException("Playground exited before it was ready.\n" . $log);
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException("Playground did not become ready within {$timeoutSeconds}s");
            }
            usleep(300_000);
        }
    }
}
