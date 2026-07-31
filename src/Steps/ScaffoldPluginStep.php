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
 * on activation it imports the bundled content images (plugin/images/, listed
 * in images.json) into the media library, creates one WordPress page per
 * entry in the bundled pages.json manifest from the markup in
 * pages/<slug>.html (written later by the assemble-pages step) with its image
 * references resolved to the imported attachments — the attachment ids exist
 * only now, never at build time — points the site's front page at the seeded
 * homepage, unpublishes the stock "Sample Page" so it leaves the nav, and
 * records everything in one option; on deactivation it deletes exactly what
 * it created (pages and attachments) and restores the front-page options.
 * No LLM ever touches this code.
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
         * Requires at least: 6.7
         * Requires PHP: 7.4
         * License: GNU General Public License v2 or later
         * License URI: https://www.gnu.org/licenses/gpl-2.0.html
         * Text Domain: {{THEME_SLUG}}-content
         */

        if (!defined('ABSPATH')) {
            exit;
        }

        define('{{CONST_PREFIX}}_CONTENT_STATE_OPTION', '{{FN_PREFIX}}_content_state');

        register_activation_hook(__FILE__, '{{FN_PREFIX}}_content_activate');
        register_deactivation_hook(__FILE__, '{{FN_PREFIX}}_content_deactivate');

        /**
         * Create every page listed in pages.json from its pages/<slug>.html
         * markup, point the site's front page at the seeded homepage, and
         * remember everything changed so deactivation can undo it exactly.
         * Re-activating while the state option exists is a no-op, so a double
         * activation never duplicates pages.
         */
        function {{FN_PREFIX}}_content_activate() {
            if (get_option({{CONST_PREFIX}}_CONTENT_STATE_OPTION)) {
                return;
            }

            $manifest = json_decode((string) file_get_contents(__DIR__ . '/pages.json'), true);
            $pages = is_array($manifest) && isset($manifest['pages']) && is_array($manifest['pages'])
                ? $manifest['pages']
                : array();

            $state = array(
                'page_ids'       => array(),
                'attachment_ids' => array(),
                'unpublished'    => array(),
                'show_on_front'  => get_option('show_on_front'),
                'page_on_front'  => get_option('page_on_front'),
                'changed_front'  => false,
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

            // Content images are content: import the bundled files into the
            // media library FIRST — the attachment ids the markup needs exist
            // only after this import, never at build time.
            $image_map = {{FN_PREFIX}}_content_import_images($state['attachment_ids']);

            // The page markup is generated content, not user input from this
            // site — but kses would mangle its block comments when activation
            // runs without an unfiltered_html user (WP-CLI, a Playground
            // blueprint step). So ONLY the post-content kses filter is
            // suspended around the inserts (titles, excerpts, and everything
            // else stay filtered), and every page passes through
            // {{FN_PREFIX}}_content_sanitize() below — the same script-stripping
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
                // Point the markup at the imported media (attachment ids +
                // upload URLs), then resolve anything left — an image the
                // build never generated — against the ACTIVE theme's assets.
                $content = {{FN_PREFIX}}_content_resolve_images($content, $image_map);
                $content = str_replace(
                    'theme:./assets/',
                    trailingslashit(get_stylesheet_directory_uri()) . 'assets/',
                    $content
                );
                $content = {{FN_PREFIX}}_content_sanitize($content, "page '{$slug}'");

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

            update_option({{CONST_PREFIX}}_CONTENT_STATE_OPTION, $state);
            flush_rewrite_rules();
        }

        /**
         * Import every bundled content image (plugin/images/, listed in
         * images.json) into the media library and return a map from the
         * build-time placeholder ("theme:./assets/<file>") to the imported
         * attachment's id and URL. Ids are appended to $attachment_ids so the
         * state option can undo the import on deactivation. A file the build
         * never shipped is skipped — its markup falls back to the theme.
         */
        function {{FN_PREFIX}}_content_import_images(&$attachment_ids) {
            if (!is_file(__DIR__ . '/images.json')) {
                return array();
            }
            $manifest = json_decode((string) file_get_contents(__DIR__ . '/images.json'), true);
            $images = is_array($manifest) && isset($manifest['images']) && is_array($manifest['images'])
                ? $manifest['images']
                : array();

            $map = array();
            $images_dir = realpath(__DIR__ . '/images');
            foreach ($images as $image) {
                if (!is_array($image)) {
                    continue;
                }
                $filename = isset($image['filename']) ? (string) $image['filename'] : '';
                // Basename only, same charset CollectImagesStep accepts — reject
                // path segments so a crafted images.json cannot escape plugin/images/.
                if ($filename === '' || $filename !== basename($filename)
                    || !preg_match('/^[a-z0-9-]+\.(?:jpe?g|png)$/i', $filename)) {
                    continue;
                }
                if ($images_dir === false) {
                    continue;
                }
                $path = $images_dir . DIRECTORY_SEPARATOR . $filename;
                if (!is_file($path)) {
                    continue;
                }
                $real = realpath($path);
                if ($real === false || strpos($real, $images_dir . DIRECTORY_SEPARATOR) !== 0) {
                    continue;
                }

                $upload = wp_upload_bits($filename, null, (string) file_get_contents($real));
                if (!empty($upload['error'])) {
                    continue;
                }

                $type = wp_check_filetype($upload['file']);
                $attachment_id = wp_insert_attachment(array(
                    'post_mime_type' => !empty($type['type']) ? (string) $type['type'] : 'image/jpeg',
                    'post_title'     => isset($image['title']) && $image['title'] !== '' ? (string) $image['title'] : $filename,
                    'post_status'    => 'inherit',
                ), $upload['file']);
                if (is_wp_error($attachment_id) || !$attachment_id) {
                    continue;
                }

                // Sizes/srcset metadata; the generator lives in an admin include.
                if (!function_exists('wp_generate_attachment_metadata')) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                $meta = wp_generate_attachment_metadata((int) $attachment_id, $upload['file']);
                if (is_array($meta)) {
                    wp_update_attachment_metadata((int) $attachment_id, $meta);
                }

                $attachment_ids[] = (int) $attachment_id;
                $map['theme:./assets/' . $filename] = array(
                    'id'  => (int) $attachment_id,
                    'url' => (string) $upload['url'],
                );
            }
            return $map;
        }

        /**
         * Point page markup at the imported media. wp:image blocks get the
         * real attachment id injected into their block attributes and the
         * paired wp-image-<id> class on the <img> (core keys srcset and
         * lightbox off that pair); every other reference — cover url
         * attributes, inline background styles — gets a plain URL swap, which
         * keeps the block attrs and HTML in agreement. Placeholders not in
         * the map are left for the caller's theme fallback.
         */
        function {{FN_PREFIX}}_content_resolve_images($content, $map) {
            if ($map === array()) {
                return $content;
            }

            $content = (string) preg_replace_callback(
                '/<!--\s*wp:image(\s+\{.*?\})?\s*-->(.*?)<!--\s*\/wp:image\s*-->/s',
                function ($m) use ($map) {
                    $attrs = isset($m[1]) && trim($m[1]) !== '' ? json_decode(trim($m[1]), true) : array();
                    $html = $m[2];
                    if (!is_array($attrs)) {
                        return $m[0];
                    }
                    if (!preg_match('/src=(["\'])(theme:\.\/assets\/[^"\']+)\1/', $html, $src) || !isset($map[$src[2]])) {
                        return $m[0];
                    }

                    $id = (int) $map[$src[2]]['id'];
                    $attrs['id'] = $id;
                    $html = str_replace($src[2], (string) $map[$src[2]]['url'], $html);
                    if (preg_match('/<img\b[^>]*\bclass=/', $html)) {
                        $html = (string) preg_replace('/(<img\b[^>]*\bclass=(["\']))/', '$1wp-image-' . $id . ' ', $html, 1);
                    } else {
                        $html = (string) preg_replace('/<img\b/', '<img class="wp-image-' . $id . '"', $html, 1);
                    }

                    $json = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return '<!-- wp:image ' . $json . ' -->' . $html . '<!-- /wp:image -->';
                },
                $content
            );

            foreach ($map as $placeholder => $image) {
                $content = str_replace($placeholder, (string) $image['url'], $content);
            }
            return $content;
        }

        /**
         * Deterministic strip of script-capable markup from generated content.
         *
         * The build applies the same rules to every part at intake
         * (MarkupSanitizer); repeating them here keeps seeding safe if a page
         * file was edited between build and activation. wp_kses() is not
         * usable for this — it mangles the block comments the content is made
         * of — but WordPress's HTML API is, and it is the same tokenizer the
         * block editor trusts. The build cannot reach for it (that pipeline
         * runs standalone, with no WordPress loaded); here it is already in
         * memory, so the two are deliberately different implementations of one
         * contract: nothing executable survives.
         *
         * Nothing below deletes a tag. Deleting one joins whatever sits on
         * either side of it, and that seam can spell a tag the browser never
         * parsed from the input; editing attributes in place cannot.
         */
        function {{FN_PREFIX}}_content_sanitize($content, $context = 'page') {
            if (!class_exists('WP_HTML_Tag_Processor')) {
                {{FN_PREFIX}}_content_log(
                    "{$context}: WordPress has no HTML API (requires 6.7); stored empty"
                    . ' rather than markup nothing reviewed'
                );
                return '';
            }

            // Each round drops at most one unfinished trailing token, so this
            // terminates well inside the bound.
            for ($round = 0; $round < 8; $round++) {
                $incomplete = false;
                $sanitized = {{FN_PREFIX}}_content_sanitize_document($content, $incomplete, $context);
                if ($sanitized !== null) {
                    return $sanitized;
                }
                if (!$incomplete) {
                    break;
                }

                // The document ends inside an unfinished tag or raw-text body.
                // Everything from there on was never inspected, and a browser
                // still runs an unterminated <script>. Cut the fragment and
                // rescan: truncation rejoins nothing, so unlike deletion it
                // cannot produce a tag at the seam.
                $cut = strrpos($content, '<');
                if ($cut === false) {
                    break;
                }
                $content = substr($content, 0, $cut);
            }

            // Publishing nothing is the safe answer, but an empty page that
            // nobody was told about is its own failure — say so loudly enough
            // that it is findable after the fact.
            {{FN_PREFIX}}_content_log(
                "{$context}: no HTML pass could read this markup through to the end;"
                . ' stored empty rather than unreviewed markup'
            );
            return '';
        }

        /** Report a seeding problem where a site owner can still find it. */
        function {{FN_PREFIX}}_content_log($message) {
            error_log('Generated Site Content: ' . $message);
        }

        /** Sanitize with the tree processor, falling back to the tag processor. */
        function {{FN_PREFIX}}_content_sanitize_document($content, &$incomplete, $context = 'page') {
            // Preferred: the tree processor tracks SVG/MathML namespaces, so
            // HTML raw-text rules are not applied inside foreign content and
            // `<svg><title><img onerror=...>` is still reached. It also knows
            // each token's ancestors, which is how inert fallback content is
            // found.
            if (class_exists('WP_HTML_Processor')) {
                $sanitized = {{FN_PREFIX}}_content_sanitize_pass(
                    WP_HTML_Processor::create_fragment($content),
                    $incomplete
                );
                if ($sanitized !== null || $incomplete) {
                    return $sanitized;
                }
            }

            // It gives up on markup it does not model (<plaintext>) and throws
            // past roughly 98 nested elements. The tag processor has neither
            // limit, so it is the fallback — but it does NOT track SVG/MathML
            // namespaces, so it applies HTML raw-text rules inside foreign
            // content and would miss `<svg><title><img onerror=...>`. That is
            // a weaker guarantee, and degrading to it quietly is how a gap
            // goes unnoticed.
            {{FN_PREFIX}}_content_log(
                "{$context}: " . (class_exists('WP_HTML_Processor')
                    ? 'HTML tree processor could not finish'
                    : 'WordPress has no HTML tree processor')
                . '; fell back to the tag processor, which does not track'
                . ' SVG/MathML namespaces'
            );
            return {{FN_PREFIX}}_content_sanitize_pass(
                new WP_HTML_Tag_Processor($content),
                $incomplete
            );
        }

        /** One sanitizing walk; null when the processor could not finish. */
        function {{FN_PREFIX}}_content_sanitize_pass($processor, &$incomplete) {
            if (!$processor instanceof WP_HTML_Tag_Processor) {
                return null;
            }

            // Elements that load or run code.
            $inert = array(
                'SCRIPT' => true, 'IFRAME' => true, 'OBJECT' => true,
                'APPLET' => true, 'EMBED' => true, 'NOEMBED' => true,
                'NOFRAMES' => true, 'NOSCRIPT' => true,
            );
            $loaders = array(
                'src', 'data', 'srcdoc', 'code', 'codebase', 'archive',
                'classid', 'href',
            );
            // SVG SMIL animation elements: neutralized by stripping their
            // targeting attributes below (they animate a sibling's attribute
            // to a javascript: value that no URL sink inspects).
            $animation = array(
                'ANIMATE' => true, 'ANIMATETRANSFORM' => true,
                'ANIMATEMOTION' => true, 'SET' => true,
            );
            // Core's canonical URI-attribute list (18 entries, including
            // poster/cite/background/longdesc) plus the SVG spelling it omits.
            $urls = array_merge(wp_kses_uri_attributes(), array('xlink:href'));
            $allowed = wp_allowed_protocols();
            $tree = $processor instanceof WP_HTML_Processor;

            try {
                while ($processor->next_token()) {
                    $type = $processor->get_token_type();

                    if ($type === '#text') {
                        // <object>/<applet> fallback content and, to a parser
                        // with scripting disabled, <noscript> children are real
                        // nodes rather than raw text — so emptying the
                        // container's own text leaves this behind as page copy.
                        if ($tree && {{FN_PREFIX}}_content_inside_inert($processor, $inert)) {
                            $processor->set_modifiable_text('');
                        }
                        continue;
                    }
                    if ($type !== '#tag' || $processor->is_tag_closer()) {
                        continue;
                    }
                    $tag = $processor->get_tag();

                    $handlers = $processor->get_attribute_names_with_prefix('on');
                    if (is_array($handlers)) {
                        foreach ($handlers as $name) {
                            $processor->remove_attribute($name);
                        }
                    }

                    // No branch below returns early: every tag still falls
                    // through to the URL sweep, so a stray href on a <meta>
                    // cannot slip past on its way out.
                    if (isset($inert[$tag])) {
                        foreach ($loaders as $name) {
                            $processor->remove_attribute($name);
                        }
                        if ($tag === 'SCRIPT') {
                            $processor->set_attribute('type', 'text/plain');
                        }
                        // Raw-text bodies (script, iframe, noembed, noframes)
                        // are this tag's own modifiable text and go with it.
                        $processor->set_modifiable_text('');
                    }
                    if ($tag === 'BASE') {
                        $processor->remove_attribute('href');
                        $processor->remove_attribute('target');
                    }
                    if ($tag === 'META') {
                        // http-equiv="refresh" redirects every visitor to a
                        // URL the model chose.
                        $processor->remove_attribute('http-equiv');
                        $processor->remove_attribute('content');
                    }
                    if (isset($animation[$tag])) {
                        // SVG SMIL animation sets the live value of a sibling's
                        // attribute (e.g. a link's href) to whatever rides
                        // `values`/`to`/`from`/`by` — a javascript: URL that no
                        // URL sink below inspects. The Tag Processor cannot drop
                        // a node, so strip the targeting attributes and leave an
                        // inert element that animates nothing.
                        foreach (array('attributeName', 'values', 'to', 'from', 'by') as $name) {
                            $processor->remove_attribute($name);
                        }
                    }

                    foreach ($urls as $name) {
                        // get_attribute() returns the value already decoded, so
                        // `&#106;avascript:` is compared in its resolved form
                        // and needs no entity handling here.
                        $value = $processor->get_attribute($name);
                        if (is_string($value)
                            && wp_kses_bad_protocol($value, $allowed) !== $value
                        ) {
                            $processor->set_attribute($name, '#');
                        }
                    }
                }
            } catch (Throwable $e) {
                return null;
            }

            if ($processor->paused_at_incomplete_token()) {
                $incomplete = true;
                return null;
            }
            if ($tree && $processor->get_last_error() !== null) {
                return null;
            }
            return $processor->get_updated_html();
        }

        /** Whether the current token sits inside a code-bearing element. */
        function {{FN_PREFIX}}_content_inside_inert($processor, $inert) {
            foreach ($processor->get_breadcrumbs() as $crumb) {
                if (isset($inert[strtoupper($crumb)])) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Delete every page and attachment this plugin created and restore
         * the front-page options it changed; leaves anything the user created
         * alone.
         */
        function {{FN_PREFIX}}_content_deactivate() {
            $state = get_option({{CONST_PREFIX}}_CONTENT_STATE_OPTION);
            if (!is_array($state)) {
                return;
            }

            $ids = isset($state['page_ids']) && is_array($state['page_ids']) ? $state['page_ids'] : array();
            foreach ($ids as $id) {
                wp_delete_post((int) $id, true);
            }

            $attachments = isset($state['attachment_ids']) && is_array($state['attachment_ids']) ? $state['attachment_ids'] : array();
            foreach ($attachments as $id) {
                wp_delete_attachment((int) $id, true);
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

            delete_option({{CONST_PREFIX}}_CONTENT_STATE_OPTION);
            flush_rewrite_rules();
        }

        PHP;
}
