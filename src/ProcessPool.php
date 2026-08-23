<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Run subprocesses through a bounded rolling pool.
 *
 * The orchestration half is RollingPool's, already unit-tested with fakes; this
 * supplies the subprocess $start/$await it drives. Two properties are
 * load-bearing and easy to lose in a rewrite:
 *
 * 1. argv is passed to proc_open as an ARRAY. A shell string would make any
 *    prompt containing quotes, backticks or $ a command-injection vector, and
 *    prompts here are LLM- and user-authored text.
 * 2. stdin is non-blocking and written inside the same select loop that drains
 *    stdout/stderr. A blocking write can deadlock when both pipe directions
 *    fill, and small prompts hide that failure.
 *
 * A job's optional env replaces the complete child environment; callers that
 * need PATH, HOME, or harness configuration must include those entries. A
 * timeoutSeconds value <= 0 means no deadline. Captured stdout and stderr are
 * each capped at 64MB, with the additive result flag truncated set when either
 * stream exceeds that bound.
 *
 * Timeout termination signals only the direct child pid. Descendants may
 * outlive it because this pool deliberately does not create or kill process
 * groups. Likewise, once the direct child exits, buffered output is drained and
 * its pipes are closed without waiting for descendants that inherited them.
 */
final class ProcessPool
{
    /** How long a select() pass waits before re-checking liveness and deadlines. */
    private const SELECT_TIMEOUT_US = 200_000;

    /** Maximum captured bytes for each of stdout and stderr, per job. */
    private const CAPTURE_LIMIT_BYTES = 64 * 1024 * 1024;

    /**
     * @param array<array-key,array{argv:list<string>,stdin?:string,cwd?:string,env?:array<string,string>}> $jobs
     * @return array<array-key,array{exit:int,stdout:string,stderr:string,secs:float,timedOut:bool,truncated:bool}>
     */
    public static function run(array $jobs, int $cap, int $timeoutSeconds): array
    {
        $live = [];

        $start = static function (string|int $key, array $job) use (&$live, $timeoutSeconds): void {
            $started = microtime(true);
            $requestedBinary = (string) ($job['argv'][0] ?? '');
            $resolvedBinary = self::resolveExecutable(
                $requestedBinary,
                $job['env'] ?? null,
                $job['cwd'] ?? null,
            );
            if ($resolvedBinary === null) {
                $name = $requestedBinary === '' ? '(empty argv[0])' : $requestedBinary;
                $live[$key] = [
                    'failed' => "executable not found or not executable: {$name}",
                    'start' => $started,
                ];
                return;
            }
            $job['argv'][0] = $resolvedBinary;
            $hasStdin = array_key_exists('stdin', $job);
            $descriptors = [
                0 => $hasStdin ? ['pipe', 'r'] : ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            error_clear_last();
            $proc = @proc_open(
                $job['argv'],
                $descriptors,
                $pipes,
                $job['cwd'] ?? null,
                $job['env'] ?? null,
            );
            if (!is_resource($proc)) {
                $error = error_get_last();
                $detail = is_array($error) && is_string($error['message'] ?? null)
                    ? ': ' . $error['message']
                    : '';
                $live[$key] = [
                    'failed' => "could not start child process '{$requestedBinary}'{$detail}",
                    'start' => $started,
                ];
                return;
            }
            if (is_resource($pipes[0] ?? null)) {
                stream_set_blocking($pipes[0], false);
            }
            foreach ([1, 2] as $fd) {
                stream_set_blocking($pipes[$fd], false);
            }
            $live[$key] = [
                'proc'     => $proc,
                'pipes'    => $pipes,
                'in'       => $job['stdin'] ?? '',
                'out'      => '',
                'err'      => '',
                'start'    => $started,
                'expires'  => $timeoutSeconds > 0 ? $started + $timeoutSeconds : null,
                'timedOut' => false,
                'truncated' => false,
                'terminalStatus' => null,
            ];
            if ($hasStdin && $live[$key]['in'] === '') {
                fclose($pipes[0]);
                $live[$key]['pipes'][0] = null;
            }
        };

        $await = static function () use (&$live): array {
            $done = [];
            while ($done === [] && $live !== []) {
                $read = $write = [];
                foreach ($live as $key => $slot) {
                    if (isset($slot['failed'])) {
                        continue;
                    }
                    foreach ([1, 2] as $fd) {
                        if (is_resource($slot['pipes'][$fd] ?? null)) {
                            $read[(string) $key . ":{$fd}"] = $slot['pipes'][$fd];
                        }
                    }
                    if ($slot['in'] !== '' && is_resource($slot['pipes'][0] ?? null)) {
                        $write[(string) $key . ':0'] = $slot['pipes'][0];
                    }
                }

                if ($read !== [] || $write !== []) {
                    $r = array_values($read);
                    $w = array_values($write);
                    $x = [];
                    @stream_select($r, $w, $x, 0, self::SELECT_TIMEOUT_US);
                } else {
                    usleep(self::SELECT_TIMEOUT_US);
                }

                foreach ($live as $key => &$slot) {
                    if (isset($slot['failed'])) {
                        $done[$key] = [
                            'exit' => 1,
                            'stdout' => '',
                            'stderr' => $slot['failed'],
                            'secs' => microtime(true) - $slot['start'],
                            'timedOut' => false,
                            'truncated' => false,
                        ];
                        unset($live[$key]);
                        continue;
                    }

                    self::drainOutput($slot);

                    if ($slot['in'] !== '' && is_resource($slot['pipes'][0] ?? null)) {
                        $written = @fwrite($slot['pipes'][0], $slot['in']);
                        if ($written === false) {
                            fclose($slot['pipes'][0]);
                            $slot['pipes'][0] = null;
                        } else {
                            $slot['in'] = substr($slot['in'], $written);
                            if ($slot['in'] === '') {
                                fclose($slot['pipes'][0]);
                                $slot['pipes'][0] = null;
                            }
                        }
                    }

                    if ($slot['terminalStatus'] === null) {
                        $status = proc_get_status($slot['proc']);
                        if (!$status['running']) {
                            $slot['terminalStatus'] = $status;
                            self::drainOutput($slot);
                        }
                    }
                    if (
                        $slot['terminalStatus'] === null
                        && !$slot['timedOut']
                        && $slot['expires'] !== null
                        && microtime(true) > $slot['expires']
                    ) {
                        $slot['timedOut'] = true;
                        @proc_terminate($slot['proc'], 9);
                    }
                    if ($slot['terminalStatus'] === null) {
                        continue;
                    }
                    $status = $slot['terminalStatus'];
                    foreach ([0, 1, 2] as $fd) {
                        if (is_resource($slot['pipes'][$fd] ?? null)) {
                            fclose($slot['pipes'][$fd]);
                        }
                    }
                    $exit = $status['running'] ? -1 : (int) $status['exitcode'];
                    @proc_close($slot['proc']);
                    $done[$key] = [
                        'exit'     => $slot['timedOut'] ? -1 : $exit,
                        'stdout'   => $slot['out'],
                        'stderr'   => $slot['err'],
                        'secs'     => microtime(true) - $slot['start'],
                        'timedOut' => $slot['timedOut'],
                        'truncated' => $slot['truncated'],
                    ];
                    unset($live[$key]);
                }
                unset($slot);
            }
            return $done;
        };

        try {
            return RollingPool::run($jobs, $start, $await, $cap);
        } finally {
            foreach ($live as $slot) {
                if (!is_resource($slot['proc'] ?? null)) {
                    continue;
                }
                @proc_terminate($slot['proc'], 9);
                foreach ([0, 1, 2] as $fd) {
                    if (is_resource($slot['pipes'][$fd] ?? null)) {
                        fclose($slot['pipes'][$fd]);
                    }
                }
                @proc_close($slot['proc']);
            }
        }
    }

    /**
     * Resolve argv[0] to an executable file using the environment the child
     * will receive. Relative PATH entries are interpreted from the child cwd.
     *
     * @param array<string,string>|null $env
     */
    private static function resolveExecutable(string $binary, ?array $env, ?string $cwd): ?string
    {
        if ($binary === '') {
            return null;
        }
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            $candidate = $binary;
            if (!str_starts_with($candidate, DIRECTORY_SEPARATOR)) {
                $base = $cwd ?? getcwd();
                if (!is_string($base) || $base === '') {
                    return null;
                }
                $candidate = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
            }
            return is_file($candidate) && is_executable($candidate) ? $candidate : null;
        }

        $path = $env === null ? getenv('PATH') : ($env['PATH'] ?? '');
        if (!is_string($path) || $path === '') {
            return null;
        }
        $base = $cwd ?? getcwd();
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = $directory === '' ? '.' : $directory;
            if (!str_starts_with($directory, DIRECTORY_SEPARATOR)) {
                if (!is_string($base) || $base === '') {
                    continue;
                }
                $directory = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $directory;
            }
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /** Drain currently buffered stdout/stderr without waiting for pipe EOF. */
    private static function drainOutput(array &$slot): void
    {
        foreach ([1 => 'out', 2 => 'err'] as $fd => $bucket) {
            $pipe = $slot['pipes'][$fd] ?? null;
            if (!is_resource($pipe)) {
                continue;
            }
            while (($chunk = fread($pipe, 65536)) !== false && $chunk !== '') {
                $remaining = self::CAPTURE_LIMIT_BYTES - strlen($slot[$bucket]);
                if ($remaining <= 0) {
                    $slot['truncated'] = true;
                    continue;
                }
                if (strlen($chunk) > $remaining) {
                    $slot[$bucket] .= substr($chunk, 0, $remaining);
                    $slot['truncated'] = true;
                    continue;
                }
                $slot[$bucket] .= $chunk;
            }
            if (feof($pipe)) {
                fclose($pipe);
                $slot['pipes'][$fd] = null;
            }
        }
    }
}
