<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Loads a prompt template from the prompts/ directory and fills {{placeholders}}
 * from a context map. Every placeholder must resolve — an unresolved one means a
 * step's context wiring is wrong, so we fail loud rather than send a broken
 * prompt to the model.
 */
final class PromptRenderer
{
    public function __construct(private string $promptDir) {}

    /** @param array<string,string> $vars */
    public function render(string $template, array $vars): string
    {
        $file = $this->promptDir . '/' . $template;
        if (!is_file($file)) {
            throw new \RuntimeException("Missing prompt template: {$file}");
        }
        return self::fill((string) file_get_contents($file), $vars);
    }

    /** @param array<string,string> $vars */
    public static function fill(string $text, array $vars): string
    {
        $out = (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($vars) {
            $key = $m[1];
            if (!array_key_exists($key, $vars)) {
                return $m[0]; // leave unresolved so the check below catches it
            }
            return $vars[$key];
        }, $text);

        // Any leftover {{...}} means an unknown placeholder — the template/wiring
        // is wrong, so fail loud rather than send a broken prompt to the model.
        if (preg_match('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $out, $m)) {
            throw new \RuntimeException("Unresolved placeholder in prompt: {{{$m[1]}}}");
        }
        return $out;
    }
}
