<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;

/**
 * Final step (deterministic): write theme/functions.php.
 *
 * Input:  designDirection.json (the committed motion profile) + the motion kit
 *         scaffold-theme copied into theme/assets/motion/.
 * Output: theme/functions.php — a fixed, deterministic loader this step alone
 *         owns; no model output is ever written into it (telex-style split:
 *         canonical wiring here, generated modules in their own files). It
 *         - enqueues style.css: WordPress does NOT load a block theme's
 *           style.css automatically (it only reads the header for metadata),
 *           so the utility CSS shipped there — .equal-cards, .header-overlay,
 *           and the page-styles layout appendix — would silently never apply
 *           without an explicit enqueue. Also registered as an editor style so
 *           the editor previews match the front end.
 *         - when the motion profile isn't `none`, enqueues the static motion
 *           kit: motion.css, the ONE committed profile stylesheet, and
 *           motion.js (in <head>, so its html.js scope exists before first
 *           paint — no flash of content that then hides to reveal). style.css
 *           depends on the profile so the page-styles :root --motion-* tuning
 *           it may carry wins the cascade over the profile defaults.
 *         - prunes the motion kit to what the theme ships: unused profile
 *           files always; the whole kit when the profile is `none`.
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
        $profile = DesignDirectionStep::motionProfileFor($project);
        $motion = $profile !== 'none' && $project->exists('theme/assets/motion/motion.css')
            ? $profile
            : null;
        self::pruneMotionKit($project, $motion);
        $project->writeText('theme/functions.php', self::functionsPhp($project->slug(), $motion));
        echo $motion === null
            ? "  motion: none (kit not shipped)\n"
            : "  motion: '{$motion}' profile enqueued\n";
    }

    /**
     * Trim the scaffolded kit to what this theme uses: with a live profile,
     * drop the three unused profile stylesheets; with none, drop the whole
     * kit so a motionless theme ships no dead assets.
     */
    private static function pruneMotionKit(Project $project, ?string $motion): void
    {
        $dir = $project->themePath('assets/motion');
        if (!is_dir($dir)) {
            return;
        }
        if ($motion === null) {
            foreach (glob("{$dir}/profiles/*.css") ?: [] as $file) {
                unlink($file);
            }
            foreach (["{$dir}/motion.css", "{$dir}/motion.js"] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir("{$dir}/profiles");
            @rmdir($dir);
            @rmdir($project->themePath('assets'));
            return;
        }
        foreach (glob("{$dir}/profiles/*.css") ?: [] as $file) {
            if (basename($file) !== "{$motion}.css") {
                unlink($file);
            }
        }
    }

    private static function functionsPhp(string $slug, ?string $motion): string
    {
        $slug = ProjectStore::slugify($slug);

        $motionEnqueues = '';
        $styleDeps = 'array()';
        if ($motion !== null) {
            $styleDeps = "array('{$slug}-motion-profile')";
            $motionEnqueues = <<<PHP

                // Static motion kit + the committed '{$motion}' profile. style.css depends
                // on the profile so its :root --motion-* tuning (page-styles) wins the
                // cascade. motion.js goes in <head>: it sets the html.js scope that
                // motion.css hides reveal targets under, and doing that before first
                // paint avoids a visible flash; if it never runs, nothing is hidden.
                wp_enqueue_style('{$slug}-motion', get_theme_file_uri('assets/motion/motion.css'), array(), \$ver);
                wp_enqueue_style('{$slug}-motion-profile', get_theme_file_uri('assets/motion/profiles/{$motion}.css'), array('{$slug}-motion'), \$ver);
                wp_enqueue_script('{$slug}-motion', get_theme_file_uri('assets/motion/motion.js'), array(), \$ver, false);
            PHP;
        }

        return <<<PHP
            <?php
            /**
             * Deterministic theme wiring — written by the build, never by a model.
             * Generated modules (fonts.php) are loaded guardedly at the bottom.
             */
            add_action('wp_enqueue_scripts', function () {
                \$ver = wp_get_theme()->get('Version');{$motionEnqueues}
                // Block themes do not load style.css automatically — without this
                // enqueue its utility CSS (card layouts, layout utilities) never applies.
                wp_enqueue_style('{$slug}-style', get_stylesheet_uri(), {$styleDeps}, \$ver);
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
