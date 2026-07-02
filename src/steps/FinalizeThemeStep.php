<?php
declare(strict_types=1);

/**
 * Final step (deterministic): write theme/functions.php.
 *
 * Input:  none (the project slug names the style handle).
 * Output: theme/functions.php — a fixed, deterministic loader this step alone
 *         owns; no model output is ever written into it (telex-style split:
 *         canonical wiring here, generated modules in their own files). It
 *         - enqueues style.css: WordPress does NOT load a block theme's
 *           style.css automatically (it only reads the header for metadata),
 *           so the utility CSS shipped there — .equal-cards, .header-overlay,
 *           and the page-styles layout appendix — would silently never apply
 *           without an explicit enqueue. Also registered as an editor style so
 *           the editor previews match the front end.
 *         - require_once's the generated fonts.php (written by the fonts-php
 *           step) when present, guarded so a fontless theme stays valid.
 */
final class FinalizeThemeStep implements Step
{
    public function id(): string
    {
        return 'finalize-theme';
    }

    public function label(): string
    {
        return 'Finalize theme (functions.php)';
    }

    public function run(Project $project): void
    {
        $project->writeText('theme/functions.php', self::functionsPhp($project->slug()));
    }

    private static function functionsPhp(string $slug): string
    {
        $slug = ProjectStore::slugify($slug);

        return <<<PHP
            <?php
            /**
             * Deterministic theme wiring — written by the build, never by a model.
             * Generated modules (fonts.php) are loaded guardedly at the bottom.
             */
            add_action('wp_enqueue_scripts', function () {
                // Block themes do not load style.css automatically — without this
                // enqueue its utility CSS (card layouts, layout utilities) never applies.
                wp_enqueue_style('{$slug}-style', get_stylesheet_uri(), array(), wp_get_theme()->get('Version'));
            });

            // Mirror style.css into the editor so previews match the front end.
            add_action('after_setup_theme', function () {
                add_editor_style('style.css');
            });

            // Google Fonts loading lives in its own generated module.
            if (is_readable(__DIR__ . '/fonts.php')) {
                require_once __DIR__ . '/fonts.php';
            }

            PHP;
    }
}
