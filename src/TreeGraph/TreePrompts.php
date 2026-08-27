<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * Prompt templates for the tree graph, ported from x-pipeline's
 * pipeline/lib/prompts.mjs. Templates declare their required payload fields
 * in frontmatter; the frontmatter is YAML-lite on purpose: `key: value`
 * lines plus one bracketed list. A missing field is a render-time error,
 * not a runtime surprise.
 *
 * These templates JSON-encode non-string payload values, which is why they
 * do not go through PromptRenderer (whose values are strings only).
 */
final class TreePrompts
{
    /**
     * Load prompts/tree/<name>.md and parse its frontmatter.
     *
     * @return array{task_type:string,required:list<string>,body:string}
     */
    public static function loadTemplate(string $promptsDir, string $name): array
    {
        $file = rtrim($promptsDir, '/') . "/{$name}.md";
        if (!is_file($file)) {
            throw new TreeGraphException('preflight_failed', "no prompt template for task \"{$name}\" at {$file}");
        }
        $raw = (string) file_get_contents($file);
        $lines = explode("\n", $raw);
        if (trim($lines[0] ?? '') !== '---') {
            throw new TreeGraphException('preflight_failed', "template {$file} has no frontmatter (must start with ---)");
        }
        $close = false;
        $count = count($lines);
        for ($i = 1; $i < $count; $i++) {
            if ($lines[$i] === '---') {
                $close = $i;
                break;
            }
        }
        if ($close === false) {
            throw new TreeGraphException('preflight_failed', "template {$file} frontmatter never closes");
        }
        $meta = [];
        foreach (array_slice($lines, 1, $close - 1) as $line) {
            if (preg_match('~^([a-z_]+):\s*(.*)$~', $line, $m) === 1) {
                $meta[$m[1]] = trim($m[2]);
            }
        }
        if (($meta['task_type'] ?? null) !== $name) {
            throw new TreeGraphException(
                'preflight_failed',
                "template {$file} declares task_type \"" . ($meta['task_type'] ?? '') . "\", expected \"{$name}\"",
            );
        }
        if (preg_match('~^\[(.*)\]$~', $meta['required'] ?? '', $listMatch) !== 1) {
            throw new TreeGraphException('preflight_failed', "template {$file} must declare required: [field, ...]");
        }
        $required = array_values(array_filter(array_map('trim', explode(',', $listMatch[1])), static fn (string $f): bool => $f !== ''));

        return [
            'task_type' => $name,
            'required'  => $required,
            'body'      => implode("\n", array_slice($lines, $close + 1)),
        ];
    }

    /**
     * Fill a template's {{placeholders}} from the payload. Strings land
     * verbatim; everything else is JSON-encoded.
     *
     * @param array{task_type:string,required:list<string>,body:string} $template
     * @param array<string,mixed>                                       $payload
     */
    public static function render(array $template, array $payload): string
    {
        foreach ($template['required'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new TreeGraphException(
                    'prompt_payload_missing',
                    "template \"{$template['task_type']}\" requires payload field \"{$field}\"",
                );
            }
        }
        return (string) preg_replace_callback(
            '~\{\{([a-z_]+)\}\}~',
            static function (array $match) use ($template, $payload): string {
                $key = $match[1];
                if (!array_key_exists($key, $payload)) {
                    throw new TreeGraphException(
                        'prompt_payload_missing',
                        "template \"{$template['task_type']}\" references {{{$key}}} which is not in the payload",
                    );
                }
                $value = $payload[$key];
                return is_string($value)
                    ? $value
                    : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            },
            $template['body'],
        );
    }
}
