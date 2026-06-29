<?php
declare(strict_types=1);

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
            throw new RuntimeException("Missing prompt template: {$file}");
        }
        return self::fill((string) file_get_contents($file), $vars);
    }

    /** @param array<string,string> $vars */
    public static function fill(string $text, array $vars): string
    {
        // Conditional sections first: {{#name}}...{{/name}} keeps its body only
        // when `name` resolves to a non-empty value, and drops it (body included)
        // otherwise. This lets a template hold optional clauses — e.g. a style
        // suffix or a context block — instead of pushing that wording into PHP.
        $text = self::applySections($text, $vars);

        // Then plain {{name}} substitution.
        $out = (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($vars) {
            $key = $m[1];
            if (!array_key_exists($key, $vars)) {
                return $m[0]; // leave unresolved so the check below catches it
            }
            return $vars[$key];
        }, $text);

        // Anything left in {{...}} form — an unknown placeholder or an unbalanced
        // section tag — means the template/wiring is wrong, so fail loud.
        if (preg_match('/\{\{\s*[#\/]?\s*([a-zA-Z0-9_]+)\s*\}\}/', $out, $m)) {
            throw new RuntimeException("Unresolved placeholder in prompt: {{{$m[1]}}}");
        }
        return $out;
    }

    /**
     * Expand {{#name}}...{{/name}} sections. A section is kept when its var is
     * present and non-empty (after trimming), removed otherwise. Runs to a fixed
     * point so nested sections resolve: each pass strips the tags of the sections
     * it can see, exposing any inner ones for the next pass.
     *
     * @param array<string,string> $vars
     */
    private static function applySections(string $text, array $vars): string
    {
        // Non-greedy body, with a backreference on the name so the closing tag
        // matches the same section (inner sections of a different name ride along
        // inside the body and are handled on a later pass).
        $pattern = '/\{\{#\s*([a-zA-Z0-9_]+)\s*\}\}(.*?)\{\{\/\s*\1\s*\}\}/s';
        while (preg_match($pattern, $text)) {
            $text = (string) preg_replace_callback($pattern, static function ($m) use ($vars) {
                $key = $m[1];
                $keep = array_key_exists($key, $vars) && trim((string) $vars[$key]) !== '';
                return $keep ? $m[2] : '';
            }, $text);
        }
        return $text;
    }
}
