<?php
declare(strict_types=1);

/**
 * Per-call transcript logger for every LLM request the builder makes.
 *
 * Each successful Claude call (single or batched) is written to its own file
 * under the active project's logs/llms/ directory, named after the call's label
 * — the step/variable that asked for it, e.g. `section-hero.log`, `header.log`,
 * `theme-json.log`. A second call with the same label gets a numeric suffix
 * (`section-hero-02.log`) so nothing is ever overwritten across a run.
 *
 * The target directory is set per run by Pipeline::runThrough() from the project
 * being built (projects/<slug>/logs/llms/). Until a directory is set there is no
 * project context, so logging is simply a no-op — nothing is ever written to the
 * repo root.
 *
 * The file has a summary header (label, model, time, tokens) followed by the full
 * request body and the full response text, separated by clear rules.
 *
 * Logging is strictly best-effort: any failure (unwritable dir, etc.) is
 * swallowed so a logging problem can never break a build.
 */
final class LlmLogger
{
    /** Target dir for logs, set per run from the active project; null = no-op. */
    private static ?string $dir = null;
    private static bool $disabled = false;

    /** Count of calls logged this run, used to prefix files in call order. */
    private static int $seq = 0;

    /** Where logs are written, or null when no project context is set. */
    public static function dir(): ?string
    {
        return self::$dir;
    }

    /** Point logging at the active project's logs/llms/ dir (null disables it). */
    public static function setDir(?string $dir): void
    {
        self::$dir = $dir;
        self::$seq = 0; // new run → restart the call-order numbering
    }

    /** Turn logging on/off (off is handy for tests). */
    public static function setEnabled(bool $enabled): void
    {
        self::$disabled = !$enabled;
    }

    /**
     * Write one request/response transcript. Never throws.
     *
     * @param string $label             call identity (step + variable), e.g. "section-hero"
     * @param array<string,mixed> $request  the Messages API request body that was sent
     * @param array{text:string,input:int,output:int} $response
     * @param float $seconds            wall-clock time the call took
     */
    public static function log(string $label, array $request, array $response, float $seconds): void
    {
        if (self::$disabled) {
            return;
        }
        $dir = self::$dir;
        if ($dir === null) {
            // No active project context — nowhere to log (and never the repo root).
            return;
        }
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return;
            }
            // Prefix the filename with the call's position this run (01, 02, …)
            // so the directory listing reflects the order calls were made.
            $prefix = sprintf('%02d', ++self::$seq);
            $path = self::uniquePath($dir, $prefix . '-' . $label);
            @file_put_contents($path, self::format($label, $request, $response, $seconds));
        } catch (\Throwable $e) {
            // Best-effort: a logging failure must never break a build.
        }
    }

    /**
     * Next free path for a label: `<label>.log`, then `<label>-02.log`,
     * `<label>-03.log`, … so concurrent same-named calls never collide. Pure
     * apart from the filesystem existence checks — unit-testable.
     */
    public static function uniquePath(string $dir, string $label): string
    {
        $base = self::slug($label);
        $path = "{$dir}/{$base}.log";
        if (!file_exists($path)) {
            return $path;
        }
        for ($n = 2; ; $n++) {
            $candidate = sprintf('%s/%s-%02d.log', $dir, $base, $n);
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
    }

    /** Make a label safe as a filename: lowercase, only [a-z0-9._-]. Pure. */
    public static function slug(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9._-]+/', '-', $label) ?? '';
        $label = trim($label, '-');
        return $label === '' ? 'request' : $label;
    }

    /**
     * Render the full log file: summary header, then request, then response.
     * Pure — unit-testable.
     *
     * @param array<string,mixed> $request
     * @param array{text:string,input:int,output:int} $response
     */
    public static function format(string $label, array $request, array $response, float $seconds): string
    {
        $rule = str_repeat('=', 80);
        $sub = str_repeat('-', 80);

        $input = (int) ($response['input'] ?? 0);
        $output = (int) ($response['output'] ?? 0);
        $model = (string) ($request['model'] ?? 'unknown');

        $header = implode("\n", [
            $rule,
            'LLM REQUEST LOG',
            $rule,
            'Step / label : ' . $label,
            'Model        : ' . $model,
            'Logged at    : ' . date('Y-m-d H:i:s'),
            'Time         : ' . sprintf('%.2fs', $seconds),
            'Tokens       : ' . sprintf('%d in + %d out = %d total', $input, $output, $input + $output),
        ]);

        $body = json_encode(
            $request,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($body === false) {
            $body = '(unencodable request)';
        }

        return implode("\n", [
            $header,
            $sub,
            'REQUEST',
            $sub,
            $body,
            $sub,
            'RESPONSE',
            $sub,
            (string) ($response['text'] ?? ''),
            $rule,
            '',
        ]);
    }
}
