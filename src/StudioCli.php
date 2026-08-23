<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The one place that talks to the `studio` binary.
 *
 * Separate from the runner for two reasons: unit tests inject a fake instead
 * of shelling out, and the CLI is an external contract we do not control, so
 * shape assertions live in exactly one place and degrade in exactly one way.
 */
final class StudioCli
{
    private \Closure $exec;

    public function __construct(?\Closure $exec = null, private int $timeoutSeconds = 120)
    {
        $this->exec = $exec ?? self::realExec(...);
    }

    /** @param list<string> $args @return array{exitCode:int,stdout:string,stderr:string} */
    public function run(array $args): array
    {
        $cmd = 'studio ' . implode(' ', array_map(escapeshellarg(...), $args));
        $r = ($this->exec)($cmd, $this->timeoutSeconds);
        $r['stderr'] = self::redact(self::stripAnsi($r['stderr']));
        return $r;
    }

    /**
     * @param list<string> $args
     * @param list<string> $requiredKeys keys whose absence means the CLI contract moved
     * @return array<string,mixed>
     */
    public function json(array $args, array $requiredKeys = []): array
    {
        $r = $this->run($args);
        if ($r['exitCode'] !== 0) {
            throw new \RuntimeException("studio exited {$r['exitCode']}: " . trim($r['stderr']));
        }
        $decoded = json_decode(trim($r['stdout']), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('studio returned output that is not JSON: ' . substr(self::redact($r['stdout']), 0, 200));
        }
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new \RuntimeException("studio JSON is missing '{$key}' — the CLI contract changed");
            }
        }
        if (array_key_exists('adminPassword', $decoded)) {
            $decoded['adminPassword'] = '';
        }
        return $decoded;
    }

    public function available(): bool
    {
        return $this->run(['list', '--format', 'json'])['exitCode'] === 0;
    }

    public static function stripAnsi(string $s): string
    {
        return preg_replace('~\x1b\[[0-9;?]*[a-zA-Z]|\x1b\]8;;|\x07~', '', $s) ?? $s;
    }

    /** Blank any adminPassword value so credentials cannot reach a log. */
    public static function redact(string $s): string
    {
        return preg_replace('~("adminPassword"\s*:\s*")([^"]*)(")~', '$1[redacted]$3', $s) ?? $s;
    }

    /** @return array{exitCode:int,stdout:string,stderr:string} */
    private static function realExec(string $cmd, int $timeout): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['exitCode' => 127, 'stdout' => '', 'stderr' => 'could not start studio'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = time() + $timeout;
        while (true) {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out .= stream_get_contents($pipes[1]);
                $err .= stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['exitCode' => $status['exitcode'], 'stdout' => $out, 'stderr' => $err];
            }
            if (time() > $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['exitCode' => 124, 'stdout' => $out, 'stderr' => $err . "\nstudio timed out after {$timeout}s"];
            }
            usleep(50_000);
        }
    }
}
