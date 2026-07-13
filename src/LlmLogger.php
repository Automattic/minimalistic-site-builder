<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Per-call transcript logger for every LLM request the builder makes.
 *
 * Each Claude call (single or batched) is written to its own file under the
 * active project's logs/llms/ directory, named after the call's label — the
 * step/variable that asked for it, e.g. `section-hero.log`, `header.log`,
 * `theme-json.log`. A second call with the same label gets a numeric suffix
 * (`section-hero-02.log`) so nothing is ever overwritten across a run. A call
 * that fails for good is logged too, as `<label>-failed.log`, so an aborted
 * build is still inspectable.
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
     * A failed call (one that exhausted retries or hit a permanent error) is
     * logged too: pass its message as $error and the file is named
     * `<label>-failed.log` with the error in place of the response, so an
     * aborted build is still inspectable.
     *
     * @param string $label             call identity (step + variable), e.g. "section-hero"
     * @param array<string,mixed> $request  the Messages API request body that was sent
     * @param array{text:string,input:int,output:int,stop_reason?:?string} $response
     * @param float $seconds            wall-clock time the call took
     * @param ?string $error            failure message, or null for a successful call
     */
    public static function log(string $label, array $request, array $response, float $seconds, ?string $error = null): ?string
    {
        if (self::$disabled) {
            return null;
        }
        $dir = self::$dir;
        if ($dir === null) {
            // No active project context — nowhere to log (and never the repo root).
            return null;
        }
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return null;
            }
            // Prefix the filename with the call's position this run (01, 02, …)
            // so the directory listing reflects the order calls were made, and
            // tag failures so they stand out in the listing.
            $prefix = sprintf('%02d', ++self::$seq);
            $name = $prefix . '-' . $label . ($error !== null ? '-failed' : '');
            $path = self::uniquePath($dir, $name);
            $written = @file_put_contents($path, self::format($label, $request, $response, $seconds, $error));
            return $written === false ? null : $path;
        } catch (\Throwable $e) {
            // Best-effort: a logging failure must never break a build.
            return null;
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
     * Render the full log file: summary header, then request, then the response
     * (or, for a failed call, the error). Pure — unit-testable.
     *
     * @param array<string,mixed> $request
     * @param array{text:string,input:int,output:int,stop_reason?:?string} $response
     * @param ?string $error  failure message, or null for a successful call
     */
    public static function format(string $label, array $request, array $response, float $seconds, ?string $error = null): string
    {
        $rule = str_repeat('=', 80);
        $sub = str_repeat('-', 80);

        $input = (int) ($response['input'] ?? 0);
        $output = (int) ($response['output'] ?? 0);
        $model = (string) ($request['model'] ?? 'unknown');

        $headerLines = [
            $rule,
            'LLM REQUEST LOG',
            $rule,
            'Step / label : ' . $label,
            'Model        : ' . $model,
            'Status       : ' . ($error !== null ? 'FAILED' : 'OK'),
            'Logged at    : ' . date('Y-m-d H:i:s'),
            'Time         : ' . sprintf('%.2fs', $seconds),
            'Tokens       : ' . sprintf('%d in + %d out = %d total', $input, $output, $input + $output),
        ];
        $stopReason = trim((string) ($response['stop_reason'] ?? ''));
        if ($stopReason !== '') {
            $headerLines[] = 'Stop reason  : ' . $stopReason;
        }
        $header = implode("\n", $headerLines);

        $body = self::renderRequest($request);

        return implode("\n", [
            $header,
            $sub,
            'REQUEST',
            $sub,
            $body,
            $sub,
            $error !== null ? 'ERROR' : 'RESPONSE',
            $sub,
            $error !== null ? $error : (string) ($response['text'] ?? ''),
            $rule,
            '',
        ]);
    }

    /**
     * Render the request in a human-readable way: the small scalar params
     * (model, max_tokens, …) as compact JSON, then the `system` prompt and each
     * `message` as actual multi-line text with real line breaks — so the prompt
     * reads like prose instead of one long JSON string full of escaped "\n".
     * Pure — unit-testable.
     *
     * @param array<string,mixed> $request
     */
    public static function renderRequest(array $request): string
    {
        // Pull out the bulky, prose-like fields so we can render them as text;
        // everything else (model, max_tokens, stream, temperature, …) stays as
        // a compact JSON block at the top.
        $system   = $request['system']   ?? null;
        $messages = $request['messages'] ?? null;
        $tools    = $request['tools']    ?? null;
        unset($request['system'], $request['messages'], $request['tools']);

        $parts = [];

        if ($request !== []) {
            $params = json_encode(
                $request,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $parts[] = $params === false ? '(unencodable params)' : $params;
        }

        if ($system !== null && $system !== '') {
            $parts[] = "### SYSTEM\n" . self::renderContent($system);
        }

        if (is_array($messages)) {
            foreach ($messages as $i => $message) {
                $role = is_array($message) ? (string) ($message['role'] ?? 'unknown') : 'unknown';
                $content = is_array($message) ? ($message['content'] ?? '') : $message;
                $parts[] = sprintf("### MESSAGE %d [%s]\n%s", $i + 1, strtoupper($role), self::renderContent($content));
            }
        }

        if ($tools !== null && $tools !== []) {
            $toolsJson = json_encode($tools, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $parts[] = "### TOOLS\n" . ($toolsJson === false ? '(unencodable tools)' : $toolsJson);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Render a message/system `content` value as readable text. A plain string
     * comes through verbatim (real newlines intact); the content-block array
     * form (text / tool_use / tool_result / image) is flattened block by block.
     * Pure.
     *
     * @param mixed $content
     */
    public static function renderContent($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return (string) json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $blocks = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $blocks[] = $block;
                continue;
            }
            if (!is_array($block)) {
                $blocks[] = (string) json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            switch ($type) {
                case 'text':
                    $blocks[] = (string) ($block['text'] ?? '');
                    break;
                case 'image':
                    $src = $block['source'] ?? [];
                    $kind = is_array($src) ? (string) ($src['media_type'] ?? $src['type'] ?? 'image') : 'image';
                    $blocks[] = "[image: {$kind}]";
                    break;
                case 'tool_use':
                    $name = (string) ($block['name'] ?? '');
                    $input = json_encode($block['input'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $blocks[] = "[tool_use: {$name}]\n" . ($input === false ? '' : $input);
                    break;
                case 'tool_result':
                    $blocks[] = "[tool_result]\n" . self::renderContent($block['content'] ?? '');
                    break;
                default:
                    $blocks[] = (string) json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        return implode("\n", $blocks);
    }
}
