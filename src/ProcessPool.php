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
 */
final class ProcessPool
{
    /** How long a select() pass waits before re-checking liveness and deadlines. */
    private const SELECT_TIMEOUT_US = 200_000;

    /**
     * @param array<array-key,array{argv:list<string>,stdin?:string,cwd?:string,env?:array<string,string>}> $jobs
     * @return array<array-key,array{exit:int,stdout:string,stderr:string,secs:float,timedOut:bool}>
     */
    public static function run(array $jobs, int $cap, int $timeoutSeconds): array
    {
        $live = [];

        $start = static function (string|int $key, array $job) use (&$live, $timeoutSeconds): void {
            $descriptors = [
                0 => array_key_exists('stdin', $job) ? ['pipe', 'r'] : ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = @proc_open(
                $job['argv'],
                $descriptors,
                $pipes,
                $job['cwd'] ?? null,
                $job['env'] ?? null,
            );
            if (!is_resource($proc)) {
                $live[$key] = ['failed' => 'could not start child process', 'start' => microtime(true)];
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
                'start'    => microtime(true),
                'expires'  => microtime(true) + $timeoutSeconds,
                'timedOut' => false,
            ];
            if (array_key_exists('stdin', $job) && $job['stdin'] === '') {
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
                        ];
                        unset($live[$key]);
                        continue;
                    }

                    foreach ([1 => 'out', 2 => 'err'] as $fd => $bucket) {
                        $pipe = $slot['pipes'][$fd] ?? null;
                        if (!is_resource($pipe)) {
                            continue;
                        }
                        while (($chunk = fread($pipe, 65536)) !== false && $chunk !== '') {
                            $slot[$bucket] .= $chunk;
                        }
                        if (feof($pipe)) {
                            fclose($pipe);
                            $slot['pipes'][$fd] = null;
                        }
                    }

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

                    if (!$slot['timedOut'] && microtime(true) > $slot['expires']) {
                        $slot['timedOut'] = true;
                        @proc_terminate($slot['proc'], 9);
                    }

                    $status = proc_get_status($slot['proc']);
                    $drained = !is_resource($slot['pipes'][1] ?? null)
                        && !is_resource($slot['pipes'][2] ?? null);
                    if ($status['running'] || (!$drained && !$slot['timedOut'])) {
                        continue;
                    }
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
}
