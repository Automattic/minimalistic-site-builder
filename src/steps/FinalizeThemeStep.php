<?php
declare(strict_types=1);

/**
 * Step 8 (deterministic): make the theme actually render as designed.
 *
 * Input:  theme/theme.json
 * Output: theme/functions.php that enqueues the chosen heading/body fonts from
 *         Google Fonts, so the declared font stacks resolve to real webfonts
 *         instead of generic serif/sans fallbacks.
 *
 * Without this, theme.json names fonts (e.g. "Fraunces, serif") that the browser
 * cannot load, so every site falls back to system fonts — the biggest gap the
 * Phase 2 eval surfaced.
 */
final class FinalizeThemeStep implements Step
{
    /** Families that are system/web-safe or CSS generics — never enqueue these. */
    private const GENERIC = [
        'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui',
        'ui-serif', 'ui-sans-serif', 'ui-monospace', '-apple-system', 'blinkmacsystemfont',
        'helvetica', 'helvetica neue', 'arial', 'georgia', 'times', 'times new roman',
        'courier', 'courier new', 'verdana', 'tahoma', 'trebuchet ms', 'palatino',
    ];

    public function id(): string
    {
        return 'finalize-theme';
    }

    public function label(): string
    {
        return 'Finalize theme (font loading)';
    }

    public function run(Project $project): void
    {
        $theme = $project->readJson('theme/theme.json');
        $families = $theme['settings']['typography']['fontFamilies'] ?? [];

        $names = [];
        foreach ($families as $family) {
            $primary = self::primaryFamily((string) ($family['fontFamily'] ?? ''));
            if ($primary !== null && !in_array(strtolower($primary), self::GENERIC, true)) {
                $names[$primary] = true; // dedupe preserving first-seen
            }
        }
        $names = array_keys($names);

        $project->writeText('theme/functions.php', self::functionsPhp($project->slug(), $names));
    }

    /** Extract the first font name from a CSS font-family stack, unquoted. */
    private static function primaryFamily(string $stack): ?string
    {
        $first = trim(explode(',', $stack)[0]);
        $first = trim($first, "\"'");
        return $first === '' ? null : $first;
    }

    /** @param string[] $names */
    private static function functionsPhp(string $slug, array $names): string
    {
        $handle = ProjectStore::slugify($slug) . '-fonts';

        if ($names === []) {
            // No webfonts to load; still emit a valid functions.php.
            return "<?php\n// No external webfonts required for this theme.\n";
        }

        $families = implode('&', array_map(
            static fn (string $n) => 'family=' . rawurlencode($n) . ':wght@400;600;700',
            $names
        ));
        // rawurlencode turns spaces into %20; Google Fonts wants '+'.
        $families = str_replace('%20', '+', $families);
        $url = 'https://fonts.googleapis.com/css2?' . $families . '&display=swap';

        $list = implode(', ', $names);

        return <<<PHP
            <?php
            /**
             * Enqueue the theme's webfonts ({$list}) from Google Fonts so the
             * font families declared in theme.json render as designed.
             */
            add_action('wp_enqueue_scripts', function () {
                wp_enqueue_style('preconnect-gfonts', 'https://fonts.gstatic.com', array(), null);
                wp_enqueue_style(
                    '{$handle}',
                    '{$url}',
                    array(),
                    null
                );
            });

            PHP;
    }
}
