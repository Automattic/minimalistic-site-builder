<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Strip the markdown code fence a model sometimes wraps around raw output
 * (CSS, markup) despite being asked not to.
 */
final class CodeFences
{
    /** Strip a leading/trailing markdown code fence if the model added one. */
    public static function strip(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = preg_replace('/\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }
}
