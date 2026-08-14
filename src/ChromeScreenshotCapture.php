<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Screenshots reference URLs with the local headless browser.
 *
 * Wraps bin/screenshot/screenshot.js, which drives Playwright against system
 * Chrome, scrolls to trip lazy-loaders, and writes viewport-tall slices. One
 * child process per URL, all launched together, because a browser launch plus
 * a page load is seconds of mostly-waiting.
 *
 * Slices rather than one full-page strip: vision models downscale to a fixed
 * long edge, so a 1280x14641 capture arrives ~137px wide and unreadable, while
 * each 1280x900 slice stays under the limit at full detail.
 *
 * Needs Node and a Chrome/Chromium binary, which is why capture is an interface:
 * a host without a browser supplies its own ScreenshotCapture and shares
 * everything else in VisionUrlAnalyzer.
 */
final class ChromeScreenshotCapture implements ScreenshotCapture
{
    /** Wide enough for desktop layout to look like itself, under the long-edge limit. */
    public const WIDTH = 1280;

    /** Hero plus the next two screenfuls — enough to read a page's structure. */
    public const SLICES = 3;

    /** How long to let the network settle before capturing what painted. */
    private const IDLE_TIMEOUT_MS = 4000;

    /**
     * Device scale factor for the capture. Vision cost scales with pixel AREA,
     * so half-scale is a quarter of the tokens. The page still lays out at
     * WIDTH CSS pixels — only the bitmap shrinks — so this reads a desktop
     * design, not a phone one. See the A/B in the design doc for why 0.5.
     */
    private const SCALE = 0.5;

    private string $script;

    /**
     * @param string|null $outputDir where PNGs are written; created on demand.
     *        Null resolves at capture time to the directory InspirationStep
     *        already binds on InspirationLogger, so the slices land beside the
     *        transcript that describes them and a build's inspiration evidence
     *        stays in one place.
     * @param string|null $script    path to screenshot.js, for tests
     * @param int         $timeout   per-URL wall-clock seconds before the child is killed
     */
    public function __construct(
        private ?string $outputDir = null,
        ?string $script = null,
        private int $timeout = 90,
        private int $slices = self::SLICES,
        private float $scale = self::SCALE,
    ) {
        $this->script = $script ?? dirname(__DIR__) . '/bin/screenshot/screenshot.js';
    }

    private function resolveDir(): string
    {
        return $this->outputDir
            ?? InspirationLogger::dir()
            ?? sys_get_temp_dir() . '/site-build-inspiration';
    }

    /**
     * Capture every URL concurrently.
     *
     * Never throws: a URL that cannot be captured comes back as an error string
     * so inspiration degrades to a warning instead of stopping the build.
     *
     * @param  list<string> $urls
     * @return array<string,array{slices:list<string>,error:string|null}> keyed by URL
     */
    public function capture(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        if ($urls === []) {
            return [];
        }
        $dir = $this->resolveDir();
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $error = "could not create screenshot directory {$dir}";
            return array_combine($urls, array_fill(0, count($urls), ['slices' => [], 'error' => $error]));
        }

        $running = [];
        foreach ($urls as $index => $url) {
            $running[$index] = $this->launch($url, $index, $dir);
        }

        $results = [];
        $deadline = microtime(true) + $this->timeout;
        while ($running !== []) {
            $this->pump($running);
            foreach ($running as $index => $child) {
                if ($child['proc'] === null) {
                    $results[$urls[$index]] = ['slices' => [], 'error' => $child['error']];
                    unset($running[$index]);
                    continue;
                }
                $status = proc_get_status($child['proc']);
                if ($status['running'] && microtime(true) < $deadline) {
                    continue;
                }
                if ($status['running']) {
                    proc_terminate($child['proc'], 9);
                    $this->close($child);
                    $results[$urls[$index]] = [
                        'slices' => [],
                        'error' => "screenshot timed out after {$this->timeout}s",
                    ];
                    unset($running[$index]);
                    continue;
                }
                $results[$urls[$index]] = $this->collect($child, $status['exitcode']);
                $this->close($child);
                unset($running[$index]);
            }
            if ($running !== []) {
                usleep(50_000);
            }
        }

        $ordered = [];
        foreach ($urls as $url) {
            $ordered[$url] = $results[$url] ?? ['slices' => [], 'error' => 'capture produced no outcome'];
        }
        return $ordered;
    }

    /**
     * @return array{proc:resource|null,pipes:array<int,resource|null>,out:string,err:string,base:string,error:string|null}
     */
    private function launch(string $url, int $index, string $dir): array
    {
        $base = $dir . '/reference-' . $index . '.png';
        // Delete first: a stale slice from an earlier build would otherwise be
        // collected as if this run had produced it.
        foreach (glob($dir . '/reference-' . $index . '{,-*}.png', GLOB_BRACE) ?: [] as $stale) {
            @unlink($stale);
        }

        $cmd = 'exec ' . escapeshellarg(Env::get('NODE_BIN', 'node') ?? 'node')
            . ' ' . escapeshellarg($this->script)
            . ' ' . escapeshellarg($url)
            . ' ' . escapeshellarg($base)
            . ' --width=' . self::WIDTH
            . ' --slices=' . $this->slices
            // Leave the rest of the process budget for the lazy-load scroll and
            // the captures themselves, so a slow page still yields slices.
            . ' --nav-timeout=' . max(5, intdiv($this->timeout, 2)) * 1000
            // Marketing sites — the ones worth referencing — routinely never go
            // idle, so this budget is paid in full every time. The scroll and
            // image waits that follow are what actually settle the page.
            . ' --idle-timeout=' . self::IDLE_TIMEOUT_MS
            . ' --scale=' . $this->scale;

        $proc = @proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
        );
        if (!is_resource($proc)) {
            return [
                'proc' => null, 'pipes' => [], 'out' => '', 'err' => '', 'base' => $base,
                'error' => 'could not start the screenshot process (is Node installed?)',
            ];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return [
            'proc' => $proc,
            'pipes' => [1 => $pipes[1], 2 => $pipes[2]],
            'out' => '', 'err' => '', 'base' => $base, 'error' => null,
        ];
    }

    /**
     * Drain whatever the children have written. Pipes must not be left to fill:
     * a child blocked on a full stderr buffer never exits.
     *
     * @param array<int,array{proc:resource|null,pipes:array<int,resource|null>,out:string,err:string,base:string,error:string|null}> $running
     */
    private function pump(array &$running): void
    {
        foreach ($running as $index => $child) {
            foreach ([1 => 'out', 2 => 'err'] as $fd => $key) {
                $pipe = $child['pipes'][$fd] ?? null;
                if (!is_resource($pipe)) {
                    continue;
                }
                $chunk = stream_get_contents($pipe);
                if (is_string($chunk) && $chunk !== '') {
                    $running[$index][$key] .= $chunk;
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    $running[$index]['pipes'][$fd] = null;
                }
            }
        }
    }

    /**
     * @param  array{out:string,err:string,base:string} $child
     * @return array{slices:list<string>,error:string|null}
     */
    private function collect(array $child, ?int $exit): array
    {
        // The script echoes each written path on stdout; trust the filesystem
        // over the exit code so a partial capture is still usable.
        $slices = [];
        foreach (preg_split('/\R/', $child['out']) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && is_file($line) && filesize($line) > 0) {
                $slices[] = $line;
            }
        }
        if ($slices !== []) {
            return ['slices' => $slices, 'error' => null];
        }

        $stderr = trim($child['err']);
        $detail = $stderr === '' ? '' : ': ' . self::firstLine($stderr);
        return [
            'slices' => [],
            'error' => 'screenshot failed (exit ' . ($exit ?? -1) . ')' . $detail,
        ];
    }

    /** @param array{proc:resource|null,pipes:array<int,resource|null>} $child */
    private function close(array $child): void
    {
        foreach ($child['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($child['proc'])) {
            proc_close($child['proc']);
        }
    }

    private static function firstLine(string $text): string
    {
        // Playwright colorizes its diagnostics; the escape codes end up verbatim
        // in a build warning otherwise.
        $text = (string) preg_replace('/\e\[[0-9;]*m/', '', $text);
        $lines = preg_split('/\R/', $text) ?: [];
        $last = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $last = trim($line);
            }
        }
        return mb_substr($last, 0, 200);
    }
}
