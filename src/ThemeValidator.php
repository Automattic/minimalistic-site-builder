<?php
declare(strict_types=1);

/**
 * Structural validation of a generated theme. Catches the failure modes that
 * matter for a WordPress block theme: missing required files, invalid
 * theme.json, and unbalanced block-comment grammar in templates/parts.
 *
 * Returns a list of human-readable problems (empty = valid).
 */
final class ThemeValidator
{
    /** @return string[] list of problems (empty means valid) */
    public static function validate(Project $project): array
    {
        $problems = [];

        // style.css with a theme header.
        if (!$project->exists('theme/style.css')) {
            $problems[] = 'missing theme/style.css';
        } elseif (!str_contains($project->readText('theme/style.css'), 'Theme Name:')) {
            $problems[] = 'style.css missing "Theme Name:" header';
        }

        // theme.json valid + version 3.
        if (!$project->exists('theme/theme.json')) {
            $problems[] = 'missing theme/theme.json';
        } else {
            $theme = json_decode($project->readText('theme/theme.json'), true);
            if (!is_array($theme)) {
                $problems[] = 'theme.json is not valid JSON';
            } elseif (($theme['version'] ?? null) !== 3) {
                $problems[] = 'theme.json version is not 3';
            }
        }

        // Required block files exist and have balanced block grammar.
        $required = [
            'theme/templates/index.html',
            'theme/templates/front-page.html',
            'theme/parts/header.html',
            'theme/parts/footer.html',
        ];
        foreach ($required as $rel) {
            if (!$project->exists($rel)) {
                $problems[] = "missing {$rel}";
                continue;
            }
            $balanceProblem = self::blockBalance($project->readText($rel));
            if ($balanceProblem !== null) {
                $problems[] = "{$rel}: {$balanceProblem}";
            }
        }

        // Leftover unfilled placeholders anywhere in the theme.
        foreach ($required as $rel) {
            if ($project->exists($rel) && preg_match('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', $project->readText($rel))) {
                $problems[] = "{$rel}: contains unfilled {{placeholder}}";
            }
        }

        return $problems;
    }

    /** @return string|null problem description, or null if balanced */
    private static function blockBalance(string $markup): ?string
    {
        $allOpen = preg_match_all('/<!--\s*wp:/', $markup);
        $selfClose = preg_match_all('/<!--\s*wp:[^>]*?\/-->/', $markup);
        $close = preg_match_all('/<!--\s*\/wp:/', $markup);

        $openBlocks = $allOpen - $selfClose;
        if ($openBlocks !== $close) {
            return "unbalanced block comments (open={$openBlocks}, close={$close})";
        }
        if ($allOpen === 0) {
            return 'no block markup';
        }
        return null;
    }
}
