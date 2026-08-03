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
        $pattern = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';
        if (preg_match_all($pattern, $text, $matches) > 0) {
            foreach (array_unique($matches[1]) as $key) {
                if (!array_key_exists($key, $vars)) {
                    throw new \RuntimeException("Unresolved placeholder in prompt: {{{$key}}}");
                }
            }
        }

        // Validate the template before substitution. A value may legitimately
        // contain placeholder-shaped text; it is data, not a second template.
        return (string) preg_replace_callback(
            $pattern,
            static fn (array $match): string => $vars[$match[1]],
            $text,
        );
    }
}
