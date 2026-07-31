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
    use TranscriptLogger;

    /** Slug used when a label reduces to nothing. */
    private const SLUG_FALLBACK = 'request';

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
     * @param array{text:string,input:int,output:int,cache_read_input_tokens?:int,cache_creation_input_tokens?:int,stop_reason?:?string} $response
     * @param float $seconds            wall-clock time the call took
     * @param ?string $error            failure message, or null for a successful call
     */
    public static function log(string $label, array $request, array $response, float $seconds, ?string $error = null): ?string
    {
        return self::writeTranscript(
            $label,
            $error,
            fn (): string => self::format($label, $request, $response, $seconds, $error)
        );
    }

    /**
     * Render the full log file: summary header, then request, then the response
     * (or, for a failed call, the error). Pure — unit-testable.
     *
     * @param array<string,mixed> $request
     * @param array{text:string,input:int,output:int,cache_read_input_tokens?:int,cache_creation_input_tokens?:int,stop_reason?:?string} $response
     * @param ?string $error  failure message, or null for a successful call
     */
    public static function format(string $label, array $request, array $response, float $seconds, ?string $error = null): string
    {
        $rule = str_repeat('=', 80);
        $sub = str_repeat('-', 80);

        $input = (int) ($response['input'] ?? 0);
        $output = (int) ($response['output'] ?? 0);
        $cacheRead = (int) ($response['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($response['cache_creation_input_tokens'] ?? 0);
        $model = (string) ($request['model'] ?? 'unknown');

        $tokens = sprintf(
            '%s in + %s out = %s total',
            number_format($input),
            number_format($output),
            number_format($input + $output)
        );
        if ($cacheRead !== 0 || $cacheWrite !== 0) {
            $cacheParts = [];
            if ($cacheRead !== 0) {
                $cacheParts[] = number_format($cacheRead) . ' cache-read';
            }
            if ($cacheWrite !== 0) {
                $cacheParts[] = number_format($cacheWrite) . ' cache-write';
            }
            $tokens = sprintf(
                '%s in (%s) + %s out = %s total',
                number_format($input),
                implode(', ', $cacheParts),
                number_format($output),
                number_format($input + $output)
            );
        }

        $headerLines = [
            $rule,
            'LLM REQUEST LOG',
            $rule,
            'Step / label : ' . $label,
            'Model        : ' . $model,
            'Status       : ' . ($error !== null ? 'FAILED' : 'OK'),
            'Logged at    : ' . date('Y-m-d H:i:s'),
            'Time         : ' . sprintf('%.2fs', $seconds),
            'Tokens       : ' . $tokens,
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
     * form (text / tool_use / tool_result / image) has an explicit numbered,
     * typed boundary for every block. Cache-marked blocks are labelled so a
     * cached request remains distinguishable from its marker-stripped retry.
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
        foreach ($content as $index => $block) {
            $type = is_array($block)
                ? (string) ($block['type'] ?? 'unknown')
                : (is_string($block) ? 'text' : get_debug_type($block));
            $rendered = '';
            if (is_string($block)) {
                $rendered = $block;
            } elseif (!is_array($block)) {
                $rendered = (string) json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                switch ($type) {
                    case 'text':
                        $rendered = (string) ($block['text'] ?? '');
                        break;
                    case 'image':
                        $src = $block['source'] ?? [];
                        $kind = is_array($src) ? (string) ($src['media_type'] ?? $src['type'] ?? 'image') : 'image';
                        $rendered = "[image: {$kind}]";
                        break;
                    case 'tool_use':
                        $name = (string) ($block['name'] ?? '');
                        $input = json_encode($block['input'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $rendered = "[tool_use: {$name}]\n" . ($input === false ? '' : $input);
                        break;
                    case 'tool_result':
                        $rendered = "[tool_result]\n" . self::renderContent($block['content'] ?? '');
                        break;
                    default:
                        $rendered = (string) json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            $boundary = sprintf('--- CONTENT BLOCK %d [%s] ---', $index + 1, strtoupper($type));
            $cacheMarker = is_array($block) && array_key_exists('cache_control', $block)
                ? "\n[cached prefix]"
                : '';
            $blocks[] = $boundary . $cacheMarker . "\n" . $rendered;
        }
        return implode("\n", $blocks);
    }
}
