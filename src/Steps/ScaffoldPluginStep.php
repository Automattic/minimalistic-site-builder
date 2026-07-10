<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;

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

            // The markup is trusted build output; bypass kses so block comments
            // survive even when activation runs without a privileged user
            // (WP-CLI, a Playground blueprint step).
            kses_remove_filters();

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

            kses_init_filters();

            if ($front_id) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $front_id);
                $state['changed_front'] = true;
            }

            update_option(BUILDER_CONTENT_STATE_OPTION, $state);
            flush_rewrite_rules();
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
