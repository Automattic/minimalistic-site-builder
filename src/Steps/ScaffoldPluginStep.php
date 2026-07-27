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
         * Requires at least: 6.5
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
                $content = {{FN_PREFIX}}_content_sanitize($content);

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

        /** Return the byte after a tag's attribute-state-aware closing `>`. */
        function {{FN_PREFIX}}_content_tag_end($html, $start, &$self_closing = null) {
            $self_closing = false;
            $length = strlen($html);
            if (preg_match(
                '/\A<[\x09\x0A\x0C\x0D\x20]*\/?[\x09\x0A\x0C\x0D\x20]*[a-zA-Z][^\x09\x0A\x0C\x0D\x20\/>]*(?=[\x09\x0A\x0C\x0D\x20\/>])/',
                substr($html, $start),
                $opening
            ) !== 1) {
                // Declarations use a different tokenizer; consuming quoted
                // `>` conservatively is sufficient for sanitizer scanning.
                $quote = null;
                for ($i = $start + 2; $i < $length; $i++) {
                    $char = $html[$i];
                    if ($quote !== null) {
                        if ($char === $quote) {
                            $quote = null;
                        }
                    } elseif ($char === '"' || $char === "'") {
                        $quote = $char;
                    } elseif ($char === '>') {
                        return $i + 1;
                    }
                }
                return $length;
            }

            $offset = $start + strlen($opening[0]);
            $state = 'before_attribute';
            $quote = '';
            while ($offset < $length) {
                $char = $html[$offset];
                $space = $char === ' ' || $char === "\t" || $char === "\n"
                    || $char === "\f" || $char === "\r";

                if ($state === 'quoted_value') {
                    if ($char === $quote) {
                        $state = 'after_quoted_value';
                    }
                    $offset++;
                    continue;
                }

                if ($state === 'unquoted_value') {
                    if ($space) {
                        $state = 'before_attribute';
                    } elseif ($char === '>') {
                        $self_closing = ($state === 'self_closing');
                        return $offset + 1;
                    }
                    $offset++;
                    continue;
                }

                if ($state === 'before_value') {
                    if ($space) {
                        $offset++;
                        continue;
                    }
                    if ($char === '"' || $char === "'") {
                        $quote = $char;
                        $state = 'quoted_value';
                        $offset++;
                        continue;
                    }
                    if ($char === '>') {
                        $self_closing = ($state === 'self_closing');
                        return $offset + 1;
                    }
                    $state = 'unquoted_value';
                    continue;
                }

                if ($state === 'attribute_name') {
                    if ($space) {
                        $state = 'after_attribute_name';
                    } elseif ($char === '=') {
                        $state = 'before_value';
                    } elseif ($char === '>') {
                        $self_closing = ($state === 'self_closing');
                        return $offset + 1;
                    } elseif ($char === '/') {
                        $state = 'self_closing';
                    }
                    $offset++;
                    continue;
                }

                if ($state === 'after_attribute_name') {
                    if ($space) {
                        $offset++;
                        continue;
                    }
                    if ($char === '=') {
                        $state = 'before_value';
                        $offset++;
                        continue;
                    }
                    if ($char === '>') {
                        $self_closing = ($state === 'self_closing');
                        return $offset + 1;
                    }
                    if ($char === '/') {
                        $state = 'self_closing';
                        $offset++;
                        continue;
                    }
                    $state = 'attribute_name';
                    continue;
                }

                if ($state === 'after_quoted_value') {
                    if ($space) {
                        $state = 'before_attribute';
                        $offset++;
                        continue;
                    }
                    if ($char === '/') {
                        $state = 'self_closing';
                        $offset++;
                        continue;
                    }
                    if ($char === '>') {
                        $self_closing = ($state === 'self_closing');
                        return $offset + 1;
                    }
                    $state = 'before_attribute';
                    continue;
                }

                if ($state === 'self_closing') {
                    if ($char === '>') {
                        $self_closing = ($state === 'self_closing');
                        return $offset + 1;
                    }
                    $state = 'before_attribute';
                    continue;
                }

                if ($space) {
                    $offset++;
                    continue;
                }
                if ($char === '>') {
                        $self_closing = ($state === 'self_closing');
                        return $offset + 1;
                }
                if ($char === '/') {
                    $state = 'self_closing';
                    $offset++;
                    continue;
                }
                $state = 'attribute_name';
                if ($char === '=') {
                    $offset++;
                }
            }
            return $length;
        }

        /** Parse one tag at an exact less-than offset, or return null. */
        function {{FN_PREFIX}}_content_tag_at($html, $start) {
            if (preg_match(
                '/\A<(\/?)([a-zA-Z][^\x09\x0A\x0C\x0D\x20\/>]*)(?=[\x09\x0A\x0C\x0D\x20\/>])/',
                substr($html, $start),
                $tag
            ) !== 1) {
                return null;
            }
            $self_closing = false;
            $end = {{FN_PREFIX}}_content_tag_end($html, $start, $self_closing);
            return array(
                'name' => strtolower($tag[2]),
                'closer' => $tag[1] === '/',
                'end' => $end,
                'self_closing' => $self_closing,
            );
        }

        /**
         * End of a bogus comment. It stops at the first `>`; quotes do not
         * protect it, so a quote-aware scan here stretches the inert region
         * over live markup that then never reaches the sanitizer. The end is
         * clamped to the next block delimiter so an unterminated `<!` cannot
         * hide the rest of the document.
         */
        function {{FN_PREFIX}}_content_bogus_comment_end($html, $start) {
            $length = strlen($html);
            $close = strpos($html, '>', $start + 2);
            $end = $close === false ? $length : $close + 1;
            if (preg_match('/<!--\s*\/?wp:/', $html, $marker, PREG_OFFSET_CAPTURE, $start + 2) === 1
                && $marker[0][1] < $end
            ) {
                return $marker[0][1];
            }
            return $end;
        }

        /**
         * End of a comment/declaration at an exact offset, or null.
         *
         * `<![CDATA[` is only CDATA inside SVG/MathML. In HTML content a
         * browser reads it as a bogus comment, so honoring `]]>` there skips
         * live markup — everything that follows when no `]]>` exists.
         */
        function {{FN_PREFIX}}_content_special_end($html, $start, $in_foreign = false) {
            $length = strlen($html);
            if (substr($html, $start, 4) === '<!--') {
                $close = strpos($html, '-->', $start + 4);
                return $close === false ? $length : $close + 3;
            }
            if ($in_foreign && substr($html, $start, 9) === '<![CDATA[') {
                $close = strpos($html, ']]>', $start + 9);
                return $close === false ? $length : $close + 3;
            }
            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                return {{FN_PREFIX}}_content_bogus_comment_end($html, $start);
            }
            // `</` with no name is a bogus comment too, not an end tag.
            if (substr($html, $start, 2) === '</'
                && preg_match('/\A<\/[a-zA-Z]/', substr($html, $start, 3)) !== 1
            ) {
                return {{FN_PREFIX}}_content_bogus_comment_end($html, $start);
            }
            return null;
        }

        /**
         * Track the SVG/MathML subtrees a scan is inside. Inside foreign
         * content `<title>` and friends hold real elements rather than text,
         * and a self-closing slash is honored.
         */
        function {{FN_PREFIX}}_content_track_foreign(&$foreign, $tag) {
            if ($tag['name'] !== 'svg' && $tag['name'] !== 'math') {
                return;
            }
            if (!$tag['closer']) {
                if (empty($tag['self_closing'])) {
                    $foreign[] = $tag['name'];
                }
                return;
            }
            for ($i = count($foreign) - 1; $i >= 0; $i--) {
                if ($foreign[$i] === $tag['name']) {
                    array_splice($foreign, $i);
                    return;
                }
            }
        }

        /** Whether an offset begins a state-changing script keyword. */
        function {{FN_PREFIX}}_content_script_keyword_at($html, $offset, $closer) {
            $keyword = $closer ? '</script' : '<script';
            $keyword_length = strlen($keyword);
            if (strtolower(substr($html, $offset, $keyword_length)) !== $keyword) {
                return false;
            }
            $delimiter = isset($html[$offset + $keyword_length])
                ? $html[$offset + $keyword_length]
                : '';
            return {{FN_PREFIX}}_content_is_space_byte($delimiter)
                || $delimiter === '/'
                || $delimiter === '>';
        }

        /** End of the script element under HTML escaped/double-escaped states. */
        function {{FN_PREFIX}}_content_script_end($html, $content_start) {
            $length = strlen($html);
            $offset = $content_start;
            $state = 'data';
            while ($offset < $length) {
                if ($state !== 'data' && substr($html, $offset, 3) === '-->') {
                    $state = 'data';
                    $offset += 3;
                    continue;
                }
                if ($html[$offset] !== '<') {
                    $offset++;
                    continue;
                }

                if ($state === 'data') {
                    if (substr($html, $offset, 4) === '<!--') {
                        $state = 'escaped';
                        $offset += 4;
                        continue;
                    }
                    if ({{FN_PREFIX}}_content_script_keyword_at($html, $offset, true)) {
                        return {{FN_PREFIX}}_content_tag_end($html, $offset);
                    }
                } elseif ($state === 'escaped') {
                    if ({{FN_PREFIX}}_content_script_keyword_at($html, $offset, true)) {
                        return {{FN_PREFIX}}_content_tag_end($html, $offset);
                    }
                    if ({{FN_PREFIX}}_content_script_keyword_at($html, $offset, false)) {
                        $state = 'double_escaped';
                        $offset += 8;
                        continue;
                    }
                } elseif ({{FN_PREFIX}}_content_script_keyword_at($html, $offset, true)) {
                    $state = 'escaped';
                    $offset += 9;
                    continue;
                }
                $offset++;
            }
            return $length;
        }

        /**
         * Find an opaque element's true end. Normal/inert elements are
         * nesting-aware; raw-text elements end at their first real closer.
         */
        function {{FN_PREFIX}}_content_opaque_end($html, $name, $content_start) {
            $length = strlen($html);
            if ($name === 'plaintext') {
                return $length;
            }
            if ($name === 'script') {
                return {{FN_PREFIX}}_content_script_end($html, $content_start);
            }
            $nestable = array('object', 'applet', 'template', 'code', 'pre');
            if (!in_array($name, $nestable, true)) {
                if (preg_match(
                    '#</[\x09\x0A\x0C\x0D\x20]*' . preg_quote($name, '#') . '(?=[\x09\x0A\x0C\x0D\x20/>])#i',
                    $html,
                    $close,
                    PREG_OFFSET_CAPTURE,
                    $content_start
                ) !== 1) {
                    return $length;
                }
                return {{FN_PREFIX}}_content_tag_end($html, $close[0][1]);
            }

            $opaque = array(
                'script', 'style', 'textarea', 'title', 'xmp', 'iframe',
                'object', 'applet', 'noembed', 'noframes', 'noscript',
                'template', 'code', 'pre', 'plaintext',
            );
            $offset = $content_start;
            $depth = 1;
            $foreign = array();
            while ($offset < $length) {
                $start = strpos($html, '<', $offset);
                if ($start === false) {
                    return $length;
                }
                $special_end = {{FN_PREFIX}}_content_special_end($html, $start, $foreign !== array());
                if ($special_end !== null) {
                    $offset = max($special_end, $start + 1);
                    continue;
                }
                $tag = {{FN_PREFIX}}_content_tag_at($html, $start);
                if ($tag === null) {
                    $offset = $start + 1;
                    continue;
                }
                if ($tag['name'] === $name) {
                    if ($tag['closer']) {
                        $depth--;
                        if ($depth === 0) {
                            return $tag['end'];
                        }
                    } elseif (empty($tag['self_closing']) || $foreign === array()) {
                        $depth++;
                    }
                    {{FN_PREFIX}}_content_track_foreign($foreign, $tag);
                    $offset = $tag['end'];
                    continue;
                }
                {{FN_PREFIX}}_content_track_foreign($foreign, $tag);
                if (!$tag['closer']
                    && $foreign === array()
                    && in_array($tag['name'], $opaque, true)
                ) {
                    $offset = {{FN_PREFIX}}_content_opaque_end($html, $tag['name'], $tag['end']);
                    continue;
                }
                $offset = $tag['end'];
            }
            return $length;
        }

        /** Remove listed container elements with all of their content. */
        function {{FN_PREFIX}}_content_remove_elements($html, $names) {
            $targets = array_fill_keys($names, true);
            $raw = array(
                'script', 'style', 'textarea', 'title', 'xmp',
                'iframe', 'noembed', 'noframes', 'noscript',
            );
            $length = strlen($html);
            $offset = 0;
            $kept_from = 0;
            $out = '';
            $foreign = array();
            while ($offset < $length) {
                $start = strpos($html, '<', $offset);
                if ($start === false) {
                    break;
                }
                $special_end = {{FN_PREFIX}}_content_special_end($html, $start, $foreign !== array());
                if ($special_end !== null) {
                    $offset = max($special_end, $start + 1);
                    continue;
                }
                $tag = {{FN_PREFIX}}_content_tag_at($html, $start);
                if ($tag === null) {
                    $offset = $start + 1;
                    continue;
                }
                if (!$tag['closer'] && isset($targets[$tag['name']])) {
                    // A slash does not self-close these non-void HTML elements,
                    // but in foreign content it does and there is no body.
                    $end = (!empty($tag['self_closing']) && $foreign !== array())
                        ? $tag['end']
                        : {{FN_PREFIX}}_content_opaque_end($html, $tag['name'], $tag['end']);
                    $out .= substr($html, $kept_from, $start - $kept_from);
                    $kept_from = $end;
                    $offset = $end;
                    continue;
                }
                {{FN_PREFIX}}_content_track_foreign($foreign, $tag);
                if (!$tag['closer']
                    && $foreign === array()
                    && (in_array($tag['name'], $raw, true) || $tag['name'] === 'plaintext')
                ) {
                    $offset = {{FN_PREFIX}}_content_opaque_end($html, $tag['name'], $tag['end']);
                    continue;
                }
                $offset = $tag['end'];
            }
            return $out . substr($html, $kept_from);
        }

        /** Remove listed tags without touching surrounding text. */
        function {{FN_PREFIX}}_content_remove_tags($html, $names) {
            $targets = array_fill_keys($names, true);
            $length = strlen($html);
            $offset = 0;
            $kept_from = 0;
            $out = '';
            $foreign = array();
            while ($offset < $length) {
                $start = strpos($html, '<', $offset);
                if ($start === false) {
                    break;
                }
                $special_end = {{FN_PREFIX}}_content_special_end($html, $start, $foreign !== array());
                if ($special_end !== null) {
                    $offset = max($special_end, $start + 1);
                    continue;
                }
                $tag = {{FN_PREFIX}}_content_tag_at($html, $start);
                if ($tag === null) {
                    $offset = $start + 1;
                    continue;
                }
                {{FN_PREFIX}}_content_track_foreign($foreign, $tag);
                if (isset($targets[$tag['name']])) {
                    $out .= substr($html, $kept_from, $start - $kept_from);
                    $kept_from = $tag['end'];
                }
                $offset = $tag['end'];
            }
            return $out . substr($html, $kept_from);
        }

        /** Rewrite each real opening tag using stateful HTML boundaries. */
        function {{FN_PREFIX}}_content_rewrite_opening_tags($html, $rewrite) {
            $raw = array(
                'script', 'style', 'textarea', 'title', 'xmp',
                'iframe', 'noembed', 'noframes', 'noscript',
            );
            $length = strlen($html);
            $offset = 0;
            $kept_from = 0;
            $out = '';
            $foreign = array();
            while ($offset < $length) {
                $start = strpos($html, '<', $offset);
                if ($start === false) {
                    break;
                }
                $special_end = {{FN_PREFIX}}_content_special_end($html, $start, $foreign !== array());
                if ($special_end !== null) {
                    $offset = max($special_end, $start + 1);
                    continue;
                }
                $tag = {{FN_PREFIX}}_content_tag_at($html, $start);
                if ($tag === null) {
                    $offset = $start + 1;
                    continue;
                }
                if (!$tag['closer']) {
                    $out .= substr($html, $kept_from, $start - $kept_from);
                    $out .= $rewrite(substr($html, $start, $tag['end'] - $start));
                    $kept_from = $tag['end'];
                }
                {{FN_PREFIX}}_content_track_foreign($foreign, $tag);
                // Inside foreign content there is no raw text, so those
                // bodies are scanned and their event handlers still stripped.
                if (!$tag['closer']
                    && $foreign === array()
                    && (in_array($tag['name'], $raw, true) || $tag['name'] === 'plaintext')
                ) {
                    $offset = {{FN_PREFIX}}_content_opaque_end($html, $tag['name'], $tag['end']);
                } else {
                    $offset = $tag['end'];
                }
            }
            return $out . substr($html, $kept_from);
        }

        function {{FN_PREFIX}}_content_is_space_byte($char) {
            return $char === ' '
                || $char === "\t"
                || $char === "\n"
                || $char === "\f"
                || $char === "\r";
        }

        /**
         * Tokenize attribute byte spans from one already-bounded opening tag.
         * Quote and slash transitions mirror the browser-facing tag scanner.
         */
        function {{FN_PREFIX}}_content_attributes($tag) {
            if (preg_match(
                '/\A<[a-zA-Z][^\x09\x0A\x0C\x0D\x20\/>]*(?=[\x09\x0A\x0C\x0D\x20\/>])/',
                $tag,
                $opening
            ) !== 1) {
                return array();
            }

            $length = strlen($tag);
            $offset = strlen($opening[0]);
            $state = 'before_attribute';
            $quote = '';
            $pending_start = null;
            $after_name_whitespace = null;
            $attribute = null;
            $attributes = array();

            $begin = function ($at) use (&$attribute, &$pending_start) {
                $attribute = array(
                    'start' => $pending_start !== null ? $pending_start : $at,
                    'name_start' => $at,
                    'name_end' => null,
                    'value_start' => null,
                );
                $pending_start = null;
            };
            $commit = function ($end, $value_end = null) use (
                &$attribute,
                &$attributes,
                $tag
            ) {
                if ($attribute === null) {
                    return;
                }
                $name_end = $attribute['name_end'] !== null
                    ? $attribute['name_end']
                    : $end;
                $attributes[] = array(
                    'name' => strtolower(substr(
                        $tag,
                        $attribute['name_start'],
                        $name_end - $attribute['name_start']
                    )),
                    'start' => $attribute['start'],
                    'end' => $end,
                    'value_start' => $attribute['value_start'],
                    'value_end' => $value_end,
                );
                $attribute = null;
            };

            while ($offset < $length) {
                $char = $tag[$offset];

                if ($state === 'quoted_value') {
                    if ($char === $quote) {
                        $commit($offset + 1, $offset);
                        $state = 'after_quoted_value';
                    }
                    $offset++;
                    continue;
                }

                if ($state === 'unquoted_value') {
                    if ({{FN_PREFIX}}_content_is_space_byte($char)) {
                        $commit($offset, $offset);
                        $pending_start = $offset;
                        $state = 'before_attribute';
                        $offset++;
                    } elseif ($char === '>') {
                        $commit($offset, $offset);
                        break;
                    } else {
                        $offset++;
                    }
                    continue;
                }

                if ($state === 'before_value') {
                    if ({{FN_PREFIX}}_content_is_space_byte($char)) {
                        $offset++;
                        continue;
                    }
                    if ($char === '"' || $char === "'") {
                        $quote = $char;
                        $attribute['value_start'] = $offset + 1;
                        $state = 'quoted_value';
                        $offset++;
                        continue;
                    }
                    if ($char === '>') {
                        $attribute['value_start'] = $offset;
                        $commit($offset, $offset);
                        break;
                    }
                    $attribute['value_start'] = $offset;
                    $state = 'unquoted_value';
                    continue;
                }

                if ($state === 'attribute_name') {
                    if ($char === '=') {
                        $attribute['name_end'] = $offset;
                        $state = 'before_value';
                        $offset++;
                        continue;
                    }
                    if ({{FN_PREFIX}}_content_is_space_byte($char)) {
                        $attribute['name_end'] = $offset;
                        $after_name_whitespace = $offset;
                        $state = 'after_attribute_name';
                        $offset++;
                        continue;
                    }
                    if ($char === '/' || $char === '>') {
                        $attribute['name_end'] = $offset;
                        $state = 'after_attribute_name';
                        continue;
                    }
                    $offset++;
                    continue;
                }

                if ($state === 'after_attribute_name') {
                    if ({{FN_PREFIX}}_content_is_space_byte($char)) {
                        if ($after_name_whitespace === null) {
                            $after_name_whitespace = $offset;
                        }
                        $offset++;
                        continue;
                    }
                    if ($char === '=') {
                        $after_name_whitespace = null;
                        $state = 'before_value';
                        $offset++;
                        continue;
                    }

                    $commit((int) $attribute['name_end']);
                    if ($char === '>') {
                        break;
                    }
                    $pending_start = $after_name_whitespace;
                    $after_name_whitespace = null;
                    if ($char === '/') {
                        if ($pending_start === null) {
                            $pending_start = $offset;
                        }
                        $state = 'self_closing';
                        $offset++;
                    } else {
                        $state = 'before_attribute';
                    }
                    continue;
                }

                if ($state === 'after_quoted_value') {
                    if ({{FN_PREFIX}}_content_is_space_byte($char)) {
                        $pending_start = $offset;
                        $state = 'before_attribute';
                        $offset++;
                        continue;
                    }
                    if ($char === '/') {
                        $pending_start = $offset;
                        $state = 'self_closing';
                        $offset++;
                        continue;
                    }
                    if ($char === '>') {
                        break;
                    }
                    $state = 'before_attribute';
                    continue;
                }

                if ($state === 'self_closing') {
                    if ($char === '>') {
                        break;
                    }
                    $state = 'before_attribute';
                    continue;
                }

                // before_attribute
                if ({{FN_PREFIX}}_content_is_space_byte($char)) {
                    if ($pending_start === null) {
                        $pending_start = $offset;
                    }
                    $offset++;
                    continue;
                }
                if ($char === '>') {
                    break;
                }
                if ($char === '/') {
                    if ($pending_start === null) {
                        $pending_start = $offset;
                    }
                    $state = 'self_closing';
                    $offset++;
                    continue;
                }
                $begin($offset);
                $after_name_whitespace = null;
                $state = 'attribute_name';
                if ($char === '=') {
                    // A leading equals sign belongs to a malformed name.
                    $offset++;
                }
            }

            if ($attribute !== null) {
                if ($state === 'attribute_name') {
                    $attribute['name_end'] = $length;
                    $commit($length);
                } elseif ($state === 'after_attribute_name') {
                    $commit((int) $attribute['name_end']);
                } elseif ($state === 'before_value') {
                    $attribute['value_start'] = $length;
                    $commit($length, $length);
                } elseif ($state === 'quoted_value' || $state === 'unquoted_value') {
                    $commit($length, $length);
                }
            }

            return $attributes;
        }

        /** Whether an attribute value resolves to an executable URL scheme. */
        // Fails closed at every step: casting a PCRE error to '' would report
        // an empty scheme and keep a javascript: URL.
        function {{FN_PREFIX}}_content_has_executable_scheme($value) {
            $decoded = preg_replace_callback(
                '/&#(?:(?:x|X)([0-9a-fA-F]+)|([0-9]+));?/',
                function ($match) {
                    $hex = isset($match[1]) && $match[1] !== '';
                    $digits = $hex ? $match[1] : $match[2];
                    $significant = ltrim($digits, '0');
                    if ($significant === '') {
                        return "\xEF\xBF\xBD";
                    }
                    if (strlen($significant) > ($hex ? 2 : 3)) {
                        return $match[0];
                    }
                    $codepoint = $hex
                        ? hexdec($significant)
                        : (int) $significant;
                    return $codepoint > 0 && $codepoint <= 0x7f
                        ? chr($codepoint)
                        : $match[0];
                },
                $value
            );
            if ($decoded === null) {
                return true;
            }
            $decoded = html_entity_decode(
                $decoded,
                ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $stripped = preg_replace('/[\x00-\x20\x7F]+/', '', $decoded);
            if ($stripped === null) {
                return true;
            }
            return preg_match('/\A(?:javascript|vbscript|data):/i', $stripped) !== 0;
        }

        /** Strip active attributes from one opening tag without reserializing it. */
        function {{FN_PREFIX}}_content_sanitize_opening_tag($tag) {
            $url_attributes = array(
                'href', 'src', 'xlink:href', 'formaction', 'action',
            );
            $attributes = {{FN_PREFIX}}_content_attributes($tag);
            $event_starts = array();
            foreach ($attributes as $attribute) {
                if (strlen($attribute['name']) > 2
                    && substr($attribute['name'], 0, 2) === 'on'
                ) {
                    $event_starts[$attribute['start']] = true;
                }
            }

            $edits = array();
            foreach ($attributes as $attribute) {
                if (strlen($attribute['name']) > 2
                    && substr($attribute['name'], 0, 2) === 'on'
                ) {
                    $next = isset($tag[$attribute['end']])
                        ? $tag[$attribute['end']]
                        : '>';
                    $needs_separator = !{{FN_PREFIX}}_content_is_space_byte($next)
                        && $next !== '/'
                        && $next !== '>'
                        && !isset($event_starts[$attribute['end']]);
                    $edits[] = array(
                        'start' => $attribute['start'],
                        'end' => $attribute['end'],
                        'replacement' => $needs_separator ? ' ' : '',
                    );
                    continue;
                }

                if ($attribute['value_start'] !== null
                    && in_array($attribute['name'], $url_attributes, true)
                    && {{FN_PREFIX}}_content_has_executable_scheme(substr(
                        $tag,
                        $attribute['value_start'],
                        $attribute['value_end'] - $attribute['value_start']
                    ))
                ) {
                    $edits[] = array(
                        'start' => $attribute['value_start'],
                        'end' => $attribute['value_end'],
                        'replacement' => '#',
                    );
                }
            }

            usort($edits, function ($a, $b) {
                return $b['start'] <=> $a['start'];
            });
            foreach ($edits as $edit) {
                $tag = substr_replace(
                    $tag,
                    $edit['replacement'],
                    $edit['start'],
                    $edit['end'] - $edit['start']
                );
            }
            return $tag;
        }

        /**
         * Deterministic strip of script-capable markup: script/embed elements,
         * inline event handlers, and executable URL schemes. The build applies
         * the same rules (MarkupSanitizer) to every generated part; repeating
         * them here keeps seeding safe if a page file was edited between build
         * and activation. wp_kses() is not usable for this — it mangles the
         * block comments the content is made of.
         */
        function {{FN_PREFIX}}_content_sanitize($content) {
            $containers = array(
                'script', 'iframe', 'object', 'applet',
                'noembed', 'noframes', 'noscript',
            );
            $content = {{FN_PREFIX}}_content_remove_elements($content, $containers);
            $content = {{FN_PREFIX}}_content_remove_tags(
                $content,
                array_merge($containers, array('embed', 'base'))
            );
            return {{FN_PREFIX}}_content_rewrite_opening_tags($content, function ($tag) {
                return {{FN_PREFIX}}_content_sanitize_opening_tag($tag);
            });
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
