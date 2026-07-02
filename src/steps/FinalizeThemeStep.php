<?php
declare(strict_types=1);

/**
 * Step 8 (deterministic): make the theme actually render as designed.
 *
 * Input:  theme/theme.json
 * Output: theme/functions.php that
 *         - enqueues style.css: WordPress does NOT load a block theme's
 *           style.css automatically (it only reads the header for metadata),
 *           so the utility CSS shipped there — .equal-cards, .header-overlay,
 *           and the page-styles layout appendix — would silently never apply
 *           without an explicit enqueue. Also registered as an editor style so
 *           the editor previews match the front end.
 *         - enqueues the chosen heading/body fonts from Google Fonts, so the
 *           declared font stacks resolve to real webfonts instead of generic
 *           serif/sans fallbacks.
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
        $slug = ProjectStore::slugify($slug);
        $fonts = '';

        if ($names !== []) {
            $families = implode('&', array_map(
                static fn (string $n) => 'family=' . rawurlencode($n) . ':wght@400;600;700',
                $names
            ));
            // rawurlencode turns spaces into %20; Google Fonts wants '+'.
            $families = str_replace('%20', '+', $families);
            $url = 'https://fonts.googleapis.com/css2?' . $families . '&display=swap';
            $list = implode(', ', $names);

            $fonts = <<<PHP

                // Webfonts ({$list}) from Google Fonts, so the font families
                // declared in theme.json render as designed.
                wp_enqueue_style('preconnect-gfonts', 'https://fonts.gstatic.com', array(), null);
                wp_enqueue_style(
                    '{$slug}-fonts',
                    '{$url}',
                    array(),
                    null
                );
            PHP;
        }

        return <<<PHP
            <?php
            /**
             * Front-end wiring the block theme needs beyond theme.json.
             */
            add_action('wp_enqueue_scripts', function () {
                // Block themes do not load style.css automatically — without this
                // enqueue its utility CSS (card layouts, layout utilities) never applies.
                wp_enqueue_style('{$slug}-style', get_stylesheet_uri(), array(), wp_get_theme()->get('Version'));
            {$fonts}
            });

            // Mirror style.css into the editor so previews match the front end.
            add_action('after_setup_theme', function () {
                add_editor_style('style.css');
            });

            PHP;
    }
}
