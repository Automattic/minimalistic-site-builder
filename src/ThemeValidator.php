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

    /**
     * Soft typography checks (warnings, not build failures): font sizes
     * hardcoded in block markup instead of drawn from the theme.json
     * fontSizes scale, and a "display" preset that no markup uses.
     *
     * @return string[] list of warnings (empty means the scale governs the page)
     */
    public static function typographyWarnings(Project $project): array
    {
        $warnings = [];
        $files = array_merge(
            glob($project->themePath('parts') . '/*.html') ?: [],
            glob($project->themePath('templates') . '/*.html') ?: []
        );

        $hardcoded = [];
        $displayUsed = false;
        foreach ($files as $file) {
            $markup = (string) file_get_contents($file);
            // Raw values in the fontSize block attribute (a preset slug or
            // var:preset reference is fine; "1.25rem" / "clamp(...)" is not).
            // Inline font-size styles mirror this attribute, so counting the
            // attribute counts each hardcoded size once.
            $count = preg_match_all('/"fontSize"\s*:\s*"(?:clamp\(|[0-9.]+(?:r?em|px|vw|vh|%))/', $markup);
            if ($count > 0) {
                $hardcoded[] = basename($file) . " ({$count})";
            }
            if (preg_match('/has-display-font-size|"fontSize"\s*:\s*"display"|font-size--display/', $markup)) {
                $displayUsed = true;
            }
        }
        if ($hardcoded !== []) {
            $warnings[] = 'hardcoded font-size values bypass the fontSizes scale: ' . implode(', ', $hardcoded);
        }

        $theme = $project->exists('theme/theme.json')
            ? json_decode($project->readText('theme/theme.json'), true)
            : null;
        $slugs = array_column($theme['settings']['typography']['fontSizes'] ?? [], 'slug');
        if (in_array('display', $slugs, true) && !$displayUsed) {
            $warnings[] = 'theme.json defines a "display" fontSize that no template or part uses';
        }

        return $warnings;
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
