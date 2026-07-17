<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (deterministic): scaffold the companion content-seeder plugin.
 *
 * Input:  none
 * Output: plugin/site-content.php — the complete, static plugin code with
 *         {{placeholders}} that ApplyIdentityStep fills once the site
 *         name/slug are known.
 *
 * The plugin is identical for every site (only its header identity varies):
 * on activation it creates one WordPress page per entry in the bundled
 * pages.json manifest from the markup in pages/<slug>.html (written later by
 * the assemble-pages step), points the site's front page at the seeded
 * homepage, unpublishes the stock "Sample Page" so it leaves the nav, and
 * records everything in one option; on deactivation it deletes
 * exactly what it created and restores the front-page options. No LLM ever
 * touches this code.
 */
final class ScaffoldPluginStep implements Step
{
    /** Plugin main file, relative to the project root. */
    public const MAIN_FILE = 'plugin/site-content.php';

    public function id(): string
    {
        return 'scaffold-plugin';
    }

    public function label(): string
    {
        return 'Scaffold content plugin';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [],
            writes: [self::MAIN_FILE],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $project->writeText(self::MAIN_FILE, self::PLUGIN_PHP);
    }

    private const PLUGIN_PHP = <<<'PHP'
        <?php
        /**
         * Plugin Name: {{THEME_NAME}} Content
         * Description: Seeds the generated content for {{THEME_NAME}}: creates the site pages on activation and removes them on deactivation.
         * Version: 0.1.0
         * Requires at least: 6.5
         * Requires PHP: 7.4
         * License: GNU General Public License v2 or later
         * License URI: https://www.gnu.org/licenses/gpl-2.0.html
         * Text Domain: {{THEME_SLUG}}-content
         */

        if (!defined('ABSPATH')) {
            exit;
        }

        define('BUILDER_CONTENT_STATE_OPTION', 'builder_content_state');

        register_activation_hook(__FILE__, 'builder_content_activate');
        register_deactivation_hook(__FILE__, 'builder_content_deactivate');

        /**
         * Create every page listed in pages.json from its pages/<slug>.html
         * markup, point the site's front page at the seeded homepage, and
         * remember everything changed so deactivation can undo it exactly.
         * Re-activating while the state option exists is a no-op, so a double
         * activation never duplicates pages.
         */
        function builder_content_activate() {
            if (get_option(BUILDER_CONTENT_STATE_OPTION)) {
                return;
            }

            $manifest = json_decode((string) file_get_contents(__DIR__ . '/pages.json'), true);
            $pages = is_array($manifest) && isset($manifest['pages']) && is_array($manifest['pages'])
                ? $manifest['pages']
                : array();

            $state = array(
                'page_ids'      => array(),
                'unpublished'   => array(),
                'show_on_front' => get_option('show_on_front'),
                'page_on_front' => get_option('page_on_front'),
                'changed_front' => false,
            );

            // A fresh WordPress ships a published "Sample Page"; the header's
            // wp:page-list would render it in the nav next to the seeded
            // pages. Unpublish it (draft, not delete — it isn't ours) and
            // remember it so deactivation can restore it.
            $sample = get_page_by_path('sample-page');
            if ($sample && $sample->post_status === 'publish') {
                wp_update_post(array('ID' => (int) $sample->ID, 'post_status' => 'draft'));
                $state['unpublished'][] = (int) $sample->ID;
            }

            // The page markup is generated content, not user input from this
            // site — but kses would mangle its block comments when activation
            // runs without an unfiltered_html user (WP-CLI, a Playground
            // blueprint step). So ONLY the post-content kses filter is
            // suspended around the inserts (titles, excerpts, and everything
            // else stay filtered), and every page passes through
            // builder_content_sanitize() below — the same script-stripping
            // rules the build applies — before it is stored.
            $kses_filtered = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;
            if ($kses_filtered) {
                remove_filter('content_save_pre', 'wp_filter_post_kses');
            }

            $ids = array();
            $front_id = 0;
            foreach ($pages as $page) {
                $slug = isset($page['slug']) ? (string) $page['slug'] : '';
                if ($slug === '') {
                    continue;
                }

                $file = __DIR__ . '/pages/' . $slug . '.html';
                $content = is_file($file) ? (string) file_get_contents($file) : '';
                // Asset references are theme-relative at build time; resolve them
                // against the ACTIVE theme, where the generated images live.
                $content = str_replace(
                    'theme:./assets/',
                    trailingslashit(get_stylesheet_directory_uri()) . 'assets/',
                    $content
                );
                $content = builder_content_sanitize($content);

                $parent_slug = isset($page['parent']) ? (string) $page['parent'] : '';
                $id = wp_insert_post(array(
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_title'   => isset($page['title']) && $page['title'] !== '' ? (string) $page['title'] : $slug,
                    'post_name'    => $slug,
                    'post_content' => $content,
                    'menu_order'   => isset($page['menu_order']) ? (int) $page['menu_order'] : 0,
                    // Parents precede children in the manifest, so the id map
                    // already holds the parent when a child is inserted.
                    'post_parent'  => isset($ids[$parent_slug]) ? $ids[$parent_slug] : 0,
                ), true);
                if (is_wp_error($id) || !$id) {
                    continue;
                }

                $ids[$slug] = (int) $id;
                $state['page_ids'][] = (int) $id;
                if (!empty($page['front'])) {
                    $front_id = (int) $id;
                }
            }

            if ($kses_filtered) {
                add_filter('content_save_pre', 'wp_filter_post_kses');
            }

            if ($front_id) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $front_id);
                $state['changed_front'] = true;
            }

            update_option(BUILDER_CONTENT_STATE_OPTION, $state);
            flush_rewrite_rules();
        }

        /**
         * Deterministic strip of script-capable markup: script/embed elements,
         * inline event handlers, and executable URL schemes. The build applies
         * the same rules (MarkupSanitizer) to every generated part; repeating
         * them here keeps seeding safe if a page file was edited between build
         * and activation. wp_kses() is not usable for this — it mangles the
         * block comments the content is made of.
         */
        function builder_content_sanitize($content) {
            $content = (string) preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $content);
            $content = (string) preg_replace('#</?(script|iframe|object|embed|applet|base)\b[^>]*>#i', '', $content);
            // Event handlers are matched only inside tags so prose is never touched.
            $content = (string) preg_replace_callback('#<[a-z][^>]*>#i', function ($m) {
                return (string) preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $m[0]);
            }, $content);
            return (string) preg_replace(
                '#\b(href|src|xlink:href|formaction|action)\s*=\s*(["\'])\s*(?:javascript|vbscript|data)\s*:[^"\']*\2#i',
                '$1=$2#$2',
                $content
            );
        }

        /**
         * Delete every page this plugin created and restore the front-page
         * options it changed; leaves anything the user created alone.
         */
        function builder_content_deactivate() {
            $state = get_option(BUILDER_CONTENT_STATE_OPTION);
            if (!is_array($state)) {
                return;
            }

            $ids = isset($state['page_ids']) && is_array($state['page_ids']) ? $state['page_ids'] : array();
            foreach ($ids as $id) {
                wp_delete_post((int) $id, true);
            }

            // Republish whatever activation unpublished (the stock sample page).
            $unpublished = isset($state['unpublished']) && is_array($state['unpublished']) ? $state['unpublished'] : array();
            foreach ($unpublished as $id) {
                wp_update_post(array('ID' => (int) $id, 'post_status' => 'publish'));
            }

            if (!empty($state['changed_front'])) {
                update_option('show_on_front', (string) $state['show_on_front']);
                update_option('page_on_front', (int) $state['page_on_front']);
            }

            delete_option(BUILDER_CONTENT_STATE_OPTION);
            flush_rewrite_rules();
        }

        PHP;
}
