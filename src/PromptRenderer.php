<?php
declare(strict_types=1);

/**
 * Loads a prompt template from the prompts/ directory and fills {{placeholders}}
 * from a context map. Every placeholder must resolve — an unresolved one means a
 * step's context wiring is wrong, so we fail loud rather than send a broken
 * prompt to the model.
 *
 * Templates may also pull in shared fragments with an include directive,
 * `{{> partials/name.md}}` (path relative to the prompts/ directory). Includes
 * are expanded recursively BEFORE variable substitution, so the shared
 * design-intelligence blocks (the anti-AI-slop manifesto, the CSS pattern
 * catalog, the color-pairing discipline) live in one place and every design
 * step composes the same battle-tested wording instead of drifting copies.
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
        $text = $this->expandIncludes((string) file_get_contents($file), $file, 0);
        return self::fill($text, $vars);
    }

    /**
     * Recursively splice `{{> path}}` includes (relative to promptDir). The
     * directive's `>` keeps it distinct from a `{{var}}` placeholder, so the
     * variable pass never sees it. Bounded depth guards against an include cycle.
     */
    private function expandIncludes(string $text, string $sourceFile, int $depth): string
    {
        if ($depth > 10) {
            throw new RuntimeException("Prompt include nesting too deep (cycle?) at {$sourceFile}");
        }
        return (string) preg_replace_callback('/\{\{>\s*([^}\s]+)\s*\}\}/', function ($m) use ($depth) {
            $inc = $this->promptDir . '/' . $m[1];
            if (!is_file($inc)) {
                throw new RuntimeException("Missing prompt include: {$inc}");
            }
            return $this->expandIncludes((string) file_get_contents($inc), $inc, $depth + 1);
        }, $text);
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
            throw new RuntimeException("Unresolved placeholder in prompt: {{{$m[1]}}}");
        }
        return $out;
    }
}
