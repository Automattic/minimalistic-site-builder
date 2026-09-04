<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\ScaffoldPluginStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\AuthoredSelectBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\AuthorLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\DescriptionListBlockGenerator;

require_once __DIR__ . '/../wp-html-api.php';

/**
 * Assert that an HTML string carries nothing a browser would execute.
 *
 * Uses the HTML API as the oracle rather than a regex: `<script` and
 * `href="javascript:..."` both appear harmlessly as prose in this corpus, and
 * a string match calls those a leak.
 */
function assert_inert(string $html, string $context): void
{
    $processor = WP_HTML_Processor::create_fragment($html);
    assert_true($processor !== null, "{$context}: output is parseable");
    if ($processor === null) {
        return;
    }
    $urls = array_merge(wp_kses_uri_attributes(), ['xlink:href']);
    while ($processor->next_tag()) {
        if ($processor->get_tag() === 'SCRIPT') {
            assert_eq('text/plain', $processor->get_attribute('type'), "{$context}: script is inert");
            assert_eq('', $processor->get_modifiable_text(), "{$context}: script body is empty");
        }
        if ($processor->get_tag() === 'STYLE') {
            // A stylesheet in content can restyle the trusted header shell
            // into persistent chrome the ownership contract forbids.
            assert_eq('', trim($processor->get_modifiable_text()), "{$context}: style body is empty");
        }
        foreach ((array) $processor->get_attribute_names_with_prefix('on') as $name) {
            assert_true(false, "{$context}: event handler {$name} survived");
        }
        foreach ($urls as $name) {
            $value = $processor->get_attribute($name);
            assert_true(
                !is_string($value) || !preg_match('/\\A\\s*(?:javascript|vbscript|data)\\s*:/i', $value),
                "{$context}: executable URL survived in {$name}",
            );
        }
        // SVG SMIL animation sets a sibling attribute's live value from
        // `values`/`to`/`from`/`by`, so an animation element that still
        // targets an attribute is live code the URL sweep above never sees.
        if (in_array($processor->get_tag(), ['ANIMATE', 'ANIMATETRANSFORM', 'ANIMATEMOTION', 'SET'], true)) {
            foreach (['attributeName', 'values', 'to', 'from', 'by'] as $name) {
                assert_true(
                    $processor->get_attribute($name) === null,
                    "{$context}: SVG animation attribute {$name} survived",
                );
            }
        }
    }
}

/**
 * Unit tests for the content-seeder plugin: the deterministic scaffold, the
 * identity fill, and — via a tiny in-process WordPress stub — the actual
 * activation/deactivation behavior of the generated plugin code.
 */

// ── Minimal WordPress stubs so the plugin file can be included and driven. ──
// State lives in $GLOBALS so tests can inspect and reset it.

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

function wp_stub_reset(): void
{
    $GLOBALS['wp_options'] = ['show_on_front' => 'posts', 'page_on_front' => 0];
    $GLOBALS['wp_posts'] = [];
    $GLOBALS['wp_attachments'] = [];
    $GLOBALS['wp_next_id'] = 100;
    $GLOBALS['wp_kses_calls'] = [];
    $GLOBALS['wp_post_meta'] = [];
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_registered_block_paths'] = [];
    $GLOBALS['wp_theme_mods'] = [];
    $GLOBALS['wp_stylesheet_directory'] = sys_get_temp_dir() . '/wp-stub-theme';
    if (method_exists(WP_Block_Type_Registry::get_instance(), 'reset')) {
        WP_Block_Type_Registry::get_instance()->reset();
    }
    // Simulate the unprivileged context (WP-CLI, Playground blueprint):
    // the kses post-content filter is active.
    $GLOBALS['wp_filters'] = ['content_save_pre' => ['wp_filter_post_kses' => 10]];
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!class_exists('WP_Block_Type_Registry')) {
    final class WP_Block_Type_Registry
    {
        /** @var array<string, true> */
        private array $registered = [];

        private static ?self $instance = null;

        public static function get_instance(): self
        {
            return self::$instance ??= new self();
        }

        public function is_registered(string $name): bool
        {
            return isset($this->registered[$name]);
        }

        public function register(string $name): void
        {
            $this->registered[$name] = true;
        }

        public function reset(): void
        {
            $this->registered = [];
        }
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['wp_options'][$key] ?? $default;
    }
    function update_option(string $key, $value): bool
    {
        $GLOBALS['wp_options'][$key] = $value;
        return true;
    }
    function delete_option(string $key): bool
    {
        unset($GLOBALS['wp_options'][$key]);
        return true;
    }
    function wp_slash($value)
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }
        return is_string($value) ? addslashes($value) : $value;
    }
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
    // Like core, expects slashed data and unslashes before it stores — this
    // is what eats the backslashes when a caller forgets wp_slash().
    function wp_insert_post(array $post, bool $wp_error = false): int
    {
        $id = $GLOBALS['wp_next_id']++;
        $GLOBALS['wp_posts'][$id] = wp_unslash($post);
        return $id;
    }
    function wp_delete_post(int $id, bool $force = false): bool
    {
        unset($GLOBALS['wp_posts'][$id]);
        return true;
    }
    function wp_update_post(array $post): int
    {
        $post = wp_unslash($post);
        $id = (int) ($post['ID'] ?? 0);
        if (isset($GLOBALS['wp_posts'][$id])) {
            $GLOBALS['wp_posts'][$id] = array_merge($GLOBALS['wp_posts'][$id], $post);
        }
        return $id;
    }
    function get_page_by_path(string $path, $output = OBJECT, string $post_type = 'page')
    {
        foreach ($GLOBALS['wp_posts'] as $id => $post) {
            if (($post['post_name'] ?? '') === $path && ($post['post_type'] ?? '') === $post_type) {
                return (object) [
                    'ID' => $id,
                    'post_status' => (string) ($post['post_status'] ?? ''),
                    'post_date_gmt' => (string) ($post['post_date_gmt'] ?? '2026-01-01 00:00:00'),
                    'post_modified_gmt' => (string) ($post['post_modified_gmt'] ?? $post['post_date_gmt'] ?? '2026-01-01 00:00:00'),
                ];
            }
        }
        return null;
    }
    function is_wp_error($thing): bool
    {
        return false;
    }
    function get_stylesheet_directory_uri(): string
    {
        return 'https://example.test/wp-content/themes/demo';
    }
    function get_stylesheet_directory(): string
    {
        return $GLOBALS['wp_stylesheet_directory'];
    }
    function get_theme_mod(string $key, $default = false)
    {
        return $GLOBALS['wp_theme_mods'][$key] ?? $default;
    }
    function set_theme_mod(string $key, $value): bool
    {
        $GLOBALS['wp_theme_mods'][$key] = $value;
        return true;
    }
    function remove_theme_mod(string $key): void
    {
        unset($GLOBALS['wp_theme_mods'][$key]);
    }
    function trailingslashit(string $s): string
    {
        return rtrim($s, '/') . '/';
    }
    function has_filter(string $hook, $callback = false)
    {
        return $GLOBALS['wp_filters'][$hook][$callback] ?? false;
    }
    function remove_filter(string $hook, $callback, int $priority = 10): bool
    {
        $GLOBALS['wp_kses_calls'][] = "remove:{$hook}:{$callback}";
        unset($GLOBALS['wp_filters'][$hook][$callback]);
        return true;
    }
    function add_filter(string $hook, $callback, int $priority = 10): bool
    {
        $GLOBALS['wp_kses_calls'][] = "add:{$hook}:{$callback}";
        $GLOBALS['wp_filters'][$hook][$callback] = $priority;
        return true;
    }
    function update_post_meta(int $post_id, string $key, $value): bool
    {
        $GLOBALS['wp_post_meta'][$post_id][$key] = $value;
        return true;
    }
    function delete_post_meta(int $post_id, string $key): bool
    {
        unset($GLOBALS['wp_post_meta'][$post_id][$key]);
        return true;
    }
    function flush_rewrite_rules(): void
    {
    }
    function register_activation_hook(string $file, callable $cb): void
    {
    }
    function register_deactivation_hook(string $file, callable $cb): void
    {
    }
    function add_action(string $hook, callable $callback): void
    {
        $GLOBALS['wp_actions'][$hook][] = $callback;
    }
    function register_block_type(string $directory): bool
    {
        $metadata = json_decode((string) file_get_contents($directory . '/block.json'), true);
        if (!is_array($metadata) || !isset($metadata['name'])) {
            return false;
        }
        WP_Block_Type_Registry::get_instance()->register((string) $metadata['name']);
        $GLOBALS['wp_registered_block_paths'][] = $directory;
        return true;
    }
    function wp_upload_bits(string $name, $deprecated, string $bits): array
    {
        $dir = sys_get_temp_dir() . '/wp-stub-uploads';
        @mkdir($dir);
        $file = $dir . '/' . $name;
        file_put_contents($file, $bits);
        return ['file' => $file, 'url' => 'http://example.test/wp-content/uploads/2026/07/' . $name, 'error' => false];
    }
    function wp_check_filetype(string $file): array
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        return ['ext' => $ext, 'type' => $ext === 'png' ? 'image/png' : 'image/jpeg'];
    }
    // Routes through wp_insert_post() in core, so it shares the slash
    // contract: expects slashed data, unslashes before it stores.
    function wp_insert_attachment(array $args, string $file): int
    {
        $id = $GLOBALS['wp_next_id']++;
        $GLOBALS['wp_attachments'][$id] = wp_unslash($args) + ['file' => $file];
        return $id;
    }
    function wp_generate_attachment_metadata(int $id, string $file): array
    {
        return ['file' => basename($file)];
    }
    function wp_update_attachment_metadata(int $id, array $meta): bool
    {
        if (isset($GLOBALS['wp_attachments'][$id])) {
            $GLOBALS['wp_attachments'][$id]['meta'] = $meta;
        }
        return true;
    }
    function wp_delete_attachment(int $id, bool $force = false): bool
    {
        unset($GLOBALS['wp_attachments'][$id]);
        return true;
    }
}

/** The generated content plugin's function name for a slug (namespaced per site). */
function content_fn(string $slug, string $suffix): string
{
    return \Automattic\SiteBuild\Steps\ApplyIdentityStep::identifierPrefix($slug) . '_content_' . $suffix;
}

test('identifierPrefix always yields a valid PHP identifier', function () {
    assert_eq('hearth_crumb', ApplyIdentityStep::identifierPrefix('hearth-crumb'));
    // A slug may start with a digit, which no PHP function/constant name may.
    assert_eq('builder_24_hour_diner', ApplyIdentityStep::identifierPrefix('24-hour-diner'));
});

/** Scaffold + identity-fill a project and return it (plugin ready to include). */
function scaffold_plugin_fixture(string $slug = 'hearth-crumb'): array
{
    $tmp = sys_get_temp_dir() . '/builder_plug_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb', 'slug' => $slug,
        'description' => 'Artisan bread.', 'language' => 'en',
    ]);
    (new ScaffoldThemeStep())->run($project);
    (new ScaffoldPluginStep())->run($project);
    (new ApplyIdentityStep())->run($project);
    return [$project, $tmp];
}

test('scaffold-plugin materializes and registers every fixed transformer companion block', function () {
    $slug = 'companion-blocks';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    $generators = [
        new AuthoredInputBlockGenerator(),
        new AuthoredSelectBlockGenerator(),
        new AuthorLayoutBlockGenerator(),
        new DescriptionListBlockGenerator(),
    ];

    assert_true(
        in_array('plugin/blocks/*', (new ScaffoldPluginStep())->declaration()->writes, true),
        'the step declares its generated block metadata and assets',
    );

    $php = $project->readText(ScaffoldPluginStep::MAIN_FILE);
    $expectedDirectories = [];
    foreach ($generators as $generator) {
        $definition = $generator->definition();
        $directory = 'plugin/blocks/' . $definition['name'];
        $expectedDirectories[] = realpath($project->path($directory));

        assert_eq(
            $definition['block_json'],
            $project->readJson($directory . '/block.json'),
            $definition['name'] . ' metadata comes from the transformer generator',
        );
        foreach ($definition['assets'] as $filename => $contents) {
            assert_eq(
                $contents,
                $project->readText($directory . '/' . $filename),
                $definition['name'] . '/' . $filename . ' comes from the transformer generator',
            );
        }

        assert_contains($definition['block_json']['name'], $php, 'plugin names every fixed companion block');
        assert_contains("__DIR__ . '/blocks/{$definition['name']}'", $php, 'plugin registers the metadata directory');
    }
    assert_contains("function_exists('register_block_type')", $php);
    assert_contains("class_exists('WP_Block_Type_Registry')", $php);
    assert_contains('WP_Block_Type_Registry::get_instance()', $php);
    assert_contains('register_block_type($directory)', $php);

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');
    $register = content_fn($slug, 'register_companion_blocks');
    $register();
    $register();
    assert_eq($expectedDirectories, $GLOBALS['wp_registered_block_paths'], 'second registration is inert');

    exec(PHP_BINARY . ' -l ' . escapeshellarg($project->pluginPath('site-content.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, 'identity-filled php -l: ' . implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-plugin writes the static seeder with identity placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_plugscaf_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldPluginStep())->run($project);

    $php = $project->readText(ScaffoldPluginStep::MAIN_FILE);
    assert_contains('Plugin Name: {{THEME_NAME}} Content', $php);
    assert_contains('Text Domain: {{THEME_SLUG}}-content', $php);
    assert_contains('register_activation_hook', $php);
    assert_contains('register_deactivation_hook', $php);
    assert_contains("if (!defined('ABSPATH'))", $php);
    // Path-traversal guard on images.json filenames (basename + charset + realpath).
    assert_contains('basename($filename)', $php);
    assert_contains('realpath($path)', $php);

    // Every post the seeder creates carries the marker analytics uses to tell
    // seeded publishes from the site owner's; the deactivation republish of
    // stock content carries it only for the duration of the update.
    assert_eq(
        2,
        substr_count($php, "'_wpcom_ai_generated_post' => '1'"),
        'seeded pages and imported attachments are inserted with the marker',
    );
    assert_contains("update_post_meta(\$restore['ID'], '_wpcom_ai_generated_post', '1');", $php);
    assert_contains("delete_post_meta(\$restore['ID'], '_wpcom_ai_generated_post');", $php);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('content plugin skips path-shaped image filenames from images.json', function () {
    $slug = 'skips-images';
    [$project, $tmp] = scaffold_plugin_fixture($slug);

    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0, 'parent' => null],
    ]]);
    $project->writeText('plugin/pages/home.html', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->');
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => '../site-content.php', 'title' => 'traversal'],
        ['filename' => 'not/a/basename.jpg', 'title' => 'slash'],
        ['filename' => '..%2fpasswd.jpg', 'title' => 'encoded'],
        'not-an-array',
        ['filename' => 'ok.jpg', 'title' => 'missing file'],
    ]]);

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();

    assert_eq(0, count($GLOBALS['wp_attachments']), 'path-shaped or invalid filenames never imported');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('apply-identity fills the plugin header and the code lints', function () {
    [$project, $tmp] = scaffold_plugin_fixture();

    $php = $project->readText(ScaffoldPluginStep::MAIN_FILE);
    assert_contains('Plugin Name: Hearth & Crumb Content', $php);
    assert_contains('Text Domain: hearth-crumb-content', $php);
    assert_true(!str_contains($php, '{{'), 'no unfilled placeholders');

    exec(PHP_BINARY . ' -l ' . escapeshellarg($project->pluginPath('site-content.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, 'php -l: ' . implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('two generated sites define disjoint symbols and can coexist on one host', function () {
    // The real failure: two generated content plugins on one WordPress host
    // both defined builder_content_activate() → fatal redeclare, and the
    // second site's pages were seeded from the first site's directory.
    $a = scaffold_plugin_fixture('roastery-niebla');
    $b = scaffold_plugin_fixture('pottery-barro');

    require_once $a[0]->pluginPath('site-content.php');
    require_once $b[0]->pluginPath('site-content.php'); // would fatal on a shared name

    assert_true(function_exists(content_fn('roastery-niebla', 'activate')), 'site A seeder defined');
    assert_true(function_exists(content_fn('pottery-barro', 'activate')), 'site B seeder defined under a different name');
    assert_true(
        content_fn('roastery-niebla', 'activate') !== content_fn('pottery-barro', 'activate'),
        'the two sites name their seeders differently'
    );

    exec('rm -rf ' . escapeshellarg($a[1]));
    exec('rm -rf ' . escapeshellarg($b[1]));
});

test('generated seeder neutralizes the same threats as the intake sanitizer', function () {
    if (!load_wp_html_api()) {
        skip_test('no WordPress copy found for the HTML API; set SITEBUILD_WP_PATH');
    }
    $slug = 'sanitizer-parity';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    require_once $project->pluginPath('site-content.php');
    $sanitize = content_fn($slug, 'sanitize');

    $corpus = [
        '<img src=x onerror="E()">',
        '<svg/onload=E()>',
        '<svg id="x"/onload=\'E()\'>',
        '<div class="x"onclick=E()>',
        '<div id=a onload="x"class=y>t</div>',
        '<div id=a onload="x"onclick="y"class=z>t</div>',
        '<svg =" /onload=E()>',
        '<a href=javascript:alert(1)>x</a>',
        '<a/href=javascript:alert(1)>x</a>',
        '<a href="java&#x73;cript:alert(1)">x</a>',
        '<a href=jav&#97;script:alert(1)>x</a>',
        '<a href=javascript&colon;alert(1)>x</a>',
        "<a href=\"java\tscript:alert(1)\">x</a>",
        '<a href="java&#9;script:alert(1)">x</a>',
        '<img src=data:text/html,x>',
        '<form action=vbscript:x></form>',
        '<svg><a xlink:href=data:text/html,x>x</a></svg>',
        '<a =" /href=javascript:E()>x</a>',
        '<img src=x/onerror=not-an-attr>',
        '<a href="https://example.com">safe</a>',
        '<a href="&amp;#106;avascript:x">one decode only</a>',
        '<!-- href="javascript:x" --> prose href="javascript:x"',
        '<script><!--<script></script><!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph --></script><p>After</p>',
        // Tokenizer boundaries. Each of these is inert in a browser only if
        // both copies agree on where the tag, the bogus comment, the CDATA,
        // and the raw-text body start and end.
        '<p>a < b <script>E()</script></p>',
        '<p>x < y <base href="https://evil.example/">z</p>',
        '<div><![CDATA[><script>E()</script>]]></div>',
        '<div><![CDATA[x> <img src=x onerror=E()></div>',
        '<div><![CDATA[ never closed <img src=x onerror=E()>',
        '<div><! " ><img src=x onerror=E()> " ></div>',
        '<div><!bogus <img src=x onerror=E()>',
        '<svg><title><img src=x onerror=E()></title></svg>',
        '<svg><style><img src=x onerror=E()></style></svg>',
        '<svg><foreignObject><iframe srcdoc="&lt;script&gt;E()&lt;/script&gt;"></iframe></foreignObject></svg>',
        '<svg/><title>a <b> b</title><img src=x onerror=E()>',
        '<svg><![CDATA[<img src=x onerror=E()>]]></svg>',
        '<math><mtext><img src=x onerror=E()></mtext></math>',
        '<title>a <b> b</title><img src=x onerror=E()>',
        '</ b ><script>E()</script>',
        '<p>a</ b <script>E()</script></p>',
        '<div><svg><script>E()</script></svg></div>',
        '<xmp><img src=x onerror=E()></xmp>',
        // A comment ends at -->, at --!>, and immediately for <!--> / <!--->.
        '<div><!--><script>E()</script></div>',
        '<div><!---><script>E()</script></div>',
        '<div><!----><script>E()</script></div>',
        '<div><!-- c --!><script>E()</script></div>',
        '<div><!-- c --><script>E()</script></div>',
        '<div><!-- never closed <script>E()</script>',
        '<p>a<!-->b</p><a href=javascript:E()>x</a>',
        '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --><!--><img src=x onerror=E()>',
        // Foreign-content breakout, and raw-text bodies vs tag removal.
        '<div><svg><p><![CDATA[x><img src=q onerror=E()>]]></svg></div>',
        '<div><svg><![CDATA[x><img src=q onerror=E()>]]></svg></div>',
        '<div><svg><![CDATA[x> <img src=q onerror=E()></div>',
        '<div><svg><div><![CDATA[x><base href="//evil/">]]></svg></div>',
        '<svg><img src=x onerror=E()><title><b>t</b></title></svg>',
        '<div><style>a{c:"<!--"}</style><base href="//evil/"></div>',
        '<div><title><!--</title><embed src=x></div>',
        '<div><textarea><!--</textarea><base href="//evil/"></div>',
        '<style>a{c:"<base>"}</style>',
        // SVG SMIL animation of a sibling attribute to a javascript: value.
        '<svg><a href="#"><animate attributeName="href" values="javascript:E()"/><text>x</text></a></svg>',
        '<svg><a href="#"><set attributeName="href" to="javascript:E()"/><text>x</text></a></svg>',
        '<svg><rect><animatetransform attributeName="href" from="javascript:E()"/></rect></svg>',
    ];
    foreach ($corpus as $html) {
        // The two no longer agree byte-for-byte and are not meant to: the
        // build has no WordPress and scans the markup itself, while the seeder
        // drives WP_HTML_Processor. What has to hold on both is that nothing
        // executable survives, so that is what is asserted.
        foreach ([
            'intake' => MarkupSanitizer::sanitize($html),
            'seeder' => $sanitize($html),
        ] as $which => $out) {
            assert_inert($out, "{$which}: {$html}");
        }
    }

    // Stylesheets are not executable, so assert_inert's oracle cannot see
    // them; a literal match is unambiguous for these fixed inputs. Both
    // sanitizers must drop the CSS whether <style> is raw text (HTML) or an
    // element with text children (SVG foreign content).
    $stylesheets = [
        '<style>.site-header-shell{position:fixed;inset:0}</style><p>After</p>',
        '<svg><style>.site-header-shell{position:fixed}</style></svg>',
    ];
    foreach ($stylesheets as $html) {
        foreach ([
            'intake' => MarkupSanitizer::sanitize($html),
            'seeder' => $sanitize($html),
        ] as $which => $out) {
            assert_true(
                strpos($out, 'position:fixed') === false,
                "{$which}: stylesheet body survived: {$html}",
            );
            assert_inert($out, "{$which}: {$html}");
        }
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the seeder plugin creates, fronts, and removes the site pages', function () {
    if (!load_wp_html_api()) {
        skip_test('no WordPress copy found for the HTML API; set SITEBUILD_WP_PATH');
    }
    $slug = 'seeder-pages';
    [$project, $tmp] = scaffold_plugin_fixture($slug);

    // Hand-written content bundle (assemble-pages writes these in a real build).
    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0, 'parent' => null],
        ['slug' => 'menu', 'title' => 'Menu', 'front' => false, 'menu_order' => 10, 'parent' => null],
        ['slug' => 'breads', 'title' => 'Breads', 'front' => false, 'menu_order' => 20, 'parent' => 'menu'],
    ]]);
    // The H1 attribute carries the \u002d\u002d escapes the serializer
    // writes for `--` inside block-comment JSON; the seeder must store them
    // byte-for-byte or the editor fails block validation (BIGR-960).
    $project->writeText('plugin/pages/home.html', '<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"min(var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dfont-size\u002d\u002ddisplay), 88px)"}}} -->'
        . '<h1 class="wp-block-heading" style="font-size:min(var(--wp--preset--font-size--display), 88px)">Pull Up A Stool</h1><!-- /wp:heading -->' . "\n"
        . '<!-- wp:heading --><h2>Welcome</h2><!-- /wp:heading -->' . "\n"
        . '<!-- wp:image {"sizeSlug":"full"} --><figure class="wp-block-image size-full">'
        . '<img src="theme:./assets/hero.jpg" alt="AI_IMAGE: a bakery | hero | photo | landscape"/></figure><!-- /wp:image -->' . "\n"
        . '<!-- wp:cover {"url":"theme:./assets/hero.jpg"} -->'
        . '<div class="wp-block-cover" style="background-image:url(theme:./assets/hero.jpg)"></div><!-- /wp:cover -->' . "\n"
        . '<img src="theme:./assets/never-generated.jpg" alt="AI_IMAGE: skipped | x | photo | landscape">');
    // The menu page smuggles every script-capable vector a hand-edit (or a
    // build-check gap) could carry; seeding must strip them, not store them.
    $project->writeText('plugin/pages/menu.html', '<!-- wp:heading --><h2 onclick="alert(1)">Menu</h2><!-- /wp:heading -->' . "\n"
        . '<!-- wp:html --><script>alert(2)</script><!-- /wp:html -->' . "\n"
        . '<!-- wp:paragraph --><p><a href="javascript:alert(3)">Specials</a> and <a href="/breads/">breads</a>, come on in=side</p><!-- /wp:paragraph -->'
        . "\n<object><object>inner</object>nested_object_body()</object>"
        . "\n<script data-x=\"</script>\">quoted_script_body()</script>"
        . "\n<noscript>noscript_body()</noscript>"
        . "\n<img src=x\" onerror=malformed_handler()>"
        . "\n<script data-x=foo\"><span x=\">malformed_attribute_body()</script>"
        . "\n<script>unterminated_script_body()");
    $project->writeText('plugin/pages/breads.html', '<!-- wp:heading --><h2>Breads</h2><!-- /wp:heading -->');
    // The content-image bundle: hero.jpg shipped; never-generated.jpg listed
    // but absent (a build without --with-images), so it must fall back to the
    // theme's assets URL.
    $project->writeJson('plugin/images.json', ['images' => [
        // The backslash probes the slash contract: without wp_slash() around
        // wp_insert_attachment(), the unslash inside core eats it (BIGR-960).
        ['filename' => 'hero.jpg', 'title' => 'A bakery at dawn \\ dusk'],
        ['filename' => 'never-generated.jpg', 'title' => 'Skipped'],
    ]]);
    $project->writeText('plugin/images/hero.jpg', 'JPEGDATA');

    wp_stub_reset();
    // A fresh WordPress ships a published "Sample Page" — wp:page-list would
    // show it in the nav next to the seeded pages.
    $GLOBALS['wp_posts'][2] = [
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'Sample Page', 'post_name' => 'sample-page',
    ];
    // Some hosts seed an "About" page and a "Hello world!" post instead of,
    // or alongside, core's Sample Page.
    $GLOBALS['wp_posts'][3] = [
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'About', 'post_name' => 'about',
        'post_date_gmt' => '2026-08-20 20:28:14', 'post_modified_gmt' => '2026-08-20 20:28:14',
    ];
    $GLOBALS['wp_posts'][4] = [
        'post_type' => 'post', 'post_status' => 'publish',
        'post_title' => 'Hello world!', 'post_name' => 'hello-world',
        'post_date_gmt' => '2026-08-20 20:28:14', 'post_modified_gmt' => '2026-08-20 20:28:14',
    ];
    require_once $project->pluginPath('site-content.php');

    // ── Activation seeds every page in manifest order. ──
    (content_fn($slug, 'activate'))();

    $posts = $GLOBALS['wp_posts'];
    assert_eq(6, count($posts)); // 3 stock + 3 seeded

    // Every flavour of stock sample content is unpublished (not deleted) so it
    // leaves the nav, the blog and the feed.
    assert_eq('draft', $posts[2]['post_status'], 'core Sample Page unpublished');
    assert_eq('draft', $posts[3]['post_status'], 'host About page unpublished');
    assert_eq('draft', $posts[4]['post_status'], 'Hello world! post unpublished');

    // Its slug is released, so a seeded page named "about" would keep it.
    assert_eq('about-sample', $posts[3]['post_name'], 'the stock About page releases its slug');

    $stock_names = ['sample-page-sample', 'about-sample', 'hello-world-sample'];
    $seeded = array_filter($posts, fn (array $p) => !in_array($p['post_name'] ?? '', $stock_names, true));
    $ids = array_keys($seeded);
    assert_eq(['home', 'menu', 'breads'], array_column($seeded, 'post_name'));
    assert_eq([0, 10, 20], array_column($seeded, 'menu_order'));

    // The child page hangs off its parent's freshly created id.
    $byName = [];
    foreach ($posts as $id => $post) {
        $byName[$post['post_name']] = ['id' => $id] + $post;
    }
    assert_eq(0, $byName['menu']['post_parent']);
    assert_eq($byName['menu']['id'], $byName['breads']['post_parent']);

    // ── Bundled content images imported into the media library. ──
    assert_eq(1, count($GLOBALS['wp_attachments']), 'one bundled image imported');
    $attId = array_keys($GLOBALS['wp_attachments'])[0];
    $att = $GLOBALS['wp_attachments'][$attId];
    assert_eq('A bakery at dawn \\ dusk', $att['post_title'], 'title backslash survives the insert');
    assert_eq('inherit', $att['post_status']);
    assert_eq(['file' => 'hero.jpg'], $att['meta'], 'attachment metadata generated');

    $home = $byName['home']['post_content'];
    // The block-comment escapes for `--` keep their backslashes. Without
    // wp_slash() around the insert, wp_insert_post() strips them, the stored
    // attribute reads "u002du002d", and the editor fails block validation
    // (BIGR-960).
    assert_contains('min(var(\u002d\u002dwp', $home);
    assert_true(!str_contains($home, 'var(u002d'), 'escape backslashes survive the insert');
    // The wp:image block carries the attachment id (unknown until import) and
    // the paired wp-image class, and loads from the media library.
    assert_contains('"id":' . $attId, $home);
    assert_contains('wp-image-' . $attId, $home);
    assert_contains('http://example.test/wp-content/uploads/2026/07/hero.jpg', $home);
    // The cover's url attr and inline background got the plain URL swap.
    assert_true(!str_contains($home, 'theme:./assets/hero.jpg'), 'no placeholder left for the imported image');
    assert_contains('background-image:url(http://example.test/wp-content/uploads/2026/07/hero.jpg)', $home);
    // An image the build never generated falls back to the theme's assets.
    assert_contains('https://example.test/wp-content/themes/demo/assets/never-generated.jpg', $home);

    // Script-capable markup was stripped before storage; content is intact.
    $menuContent = $byName['menu']['post_content'];
    // Neutralized in place, not deleted: deleting a tag joins its
    // neighbours, and that seam can spell a tag the browser never parsed.
    assert_inert($menuContent, 'seeded menu page');
    assert_true(!str_contains($menuContent, 'alert(2)'), 'script body removed');
    assert_true(!str_contains($menuContent, 'onclick'), 'event handler removed');
    assert_true(!str_contains($menuContent, 'javascript:'), 'executable URL neutralized');
    assert_true(!str_contains($menuContent, 'unterminated_script_body'), 'unclosed script body removed');
    assert_true(!str_contains($menuContent, 'nested_object_body'), 'nested object body removed');
    assert_true(!str_contains($menuContent, 'quoted_script_body'), 'a quoted fake closer cannot expose a script body');
    assert_true(!str_contains($menuContent, 'noscript_body'), 'noscript fallback body removed');
    assert_true(!str_contains($menuContent, 'malformed_handler'), 'malformed unquoted attribute cannot hide an event handler');
    assert_true(!str_contains($menuContent, 'malformed_attribute_body'), 'malformed attribute quote state cannot expose a script body');
    assert_contains('come on in=side', $menuContent, 'prose is untouched');
    assert_contains('href="/breads/"', $menuContent, 'legitimate links survive');
    assert_contains('<!-- wp:html -->', $menuContent, 'block comments survive');
    assert_contains('>Menu</h2>', $menuContent, 'element content survives attribute stripping');

    // The seeded homepage became the front page; previous options snapshotted.
    assert_eq('page', get_option('show_on_front'));
    assert_eq($byName['home']['id'], get_option('page_on_front'));
    $state = get_option(\Automattic\SiteBuild\Steps\ApplyIdentityStep::identifierPrefix($slug) . '_content_state');
    assert_eq('posts', $state['show_on_front']);
    assert_eq($ids, $state['page_ids']);
    assert_eq([$attId], $state['attachment_ids'], 'imported attachments recorded');

    // Only the post-content kses filter was suspended, and only around the
    // seeding — it is back in place afterwards. (The log also carries the
    // plugin's own sync-whitelist registration from load time, so compare
    // just the content_save_pre entries.)
    assert_eq(
        ['remove:content_save_pre:wp_filter_post_kses', 'add:content_save_pre:wp_filter_post_kses'],
        array_values(array_filter(
            $GLOBALS['wp_kses_calls'],
            static fn ($call) => str_contains((string) $call, 'content_save_pre'),
        )),
    );
    assert_eq(10, has_filter('content_save_pre', 'wp_filter_post_kses'));

    // The marker's sync whitelisting is registered at plugin load.
    assert_eq(
        10,
        has_filter(
            'jetpack_sync_post_meta_whitelist',
            \Automattic\SiteBuild\Steps\ApplyIdentityStep::identifierPrefix($slug) . '_content_sync_marker'
        ),
        'the seeder whitelists its marker for Jetpack sync',
    );

    // ── A second activation is a no-op (no duplicate pages or attachments). ──
    (content_fn($slug, 'activate'))();
    assert_eq(6, count($GLOBALS['wp_posts']), 'no duplicates on re-activation');
    assert_eq(1, count($GLOBALS['wp_attachments']), 'no duplicate imports on re-activation');

    // ── Deactivation deletes exactly what was created and restores the rest. ──
    (content_fn($slug, 'deactivate'))();
    assert_eq([2, 3, 4], array_keys($GLOBALS['wp_posts']), 'only the stock content survives');
    // The republish marker was set for the duration of the update only.
    foreach ($GLOBALS['wp_post_meta'] ?? [] as $postId => $meta) {
        assert_true(
            !isset($meta['_wpcom_ai_generated_post']),
            "post {$postId} does not keep the seeder marker after deactivation",
        );
    }
    assert_eq('publish', $GLOBALS['wp_posts'][2]['post_status'], 'sample page republished');
    assert_eq('publish', $GLOBALS['wp_posts'][3]['post_status'], 'About page republished');
    assert_eq('about', $GLOBALS['wp_posts'][3]['post_name'], 'and it gets its slug back');
    assert_eq('publish', $GLOBALS['wp_posts'][4]['post_status'], 'Hello world! republished');
    assert_eq([], $GLOBALS['wp_attachments'], 'imported media removed');
    assert_eq('posts', get_option('show_on_front'));
    assert_eq(0, get_option('page_on_front'));
    assert_eq(false, get_option(\Automattic\SiteBuild\Steps\ApplyIdentityStep::identifierPrefix($slug) . '_content_state'));

    // Deactivating again (state gone) is harmless.
    (content_fn($slug, 'deactivate'))();

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the seeder leaves an About page somebody wrote alone', function () {
    // "about" is a slug a real page can hold, so the seeder only clears it
    // while it still looks stock: untouched since it was created. A page whose
    // post_modified has moved is somebody's writing.
    [$project, $tmp] = scaffold_plugin_fixture('hearth-crumb');
    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0],
    ]]);
    $project->writeText('plugin/pages/home.html', '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->');

    wp_stub_reset();
    $GLOBALS['wp_posts'][2] = [
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'About', 'post_name' => 'about',
        'post_date_gmt' => '2026-08-20 20:28:14', 'post_modified_gmt' => '2026-08-20 22:00:00',
    ];
    require_once $project->pluginPath('site-content.php');

    (content_fn('hearth-crumb', 'activate'))();

    assert_eq('publish', $GLOBALS['wp_posts'][2]['post_status'], 'an edited About page stays published');
    assert_eq('about', $GLOBALS['wp_posts'][2]['post_name'], 'and keeps its slug');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the seeder reports when it degrades or refuses to store markup', function () {
    if (!load_wp_html_api()) {
        skip_test('no WordPress copy found for the HTML API; set SITEBUILD_WP_PATH');
    }
    $slug = 'seeder-logging';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    require_once $project->pluginPath('site-content.php');
    $sanitize = content_fn($slug, 'sanitize');

    $log = $tmp . '/php-error.log';
    $previous = ini_get('error_log');
    ini_set('error_log', $log);
    $read = static function () use ($log): string {
        return is_file($log) ? (string) file_get_contents($log) : '';
    };

    // A clean page says nothing: the log is for problems only.
    $sanitize('<!-- wp:paragraph --><p>Fine.</p><!-- /wp:paragraph -->', "page 'clean'");
    assert_eq('', $read(), 'a clean page logs nothing');

    // Which pathological input trips which internal limit is a WordPress
    // implementation detail — <plaintext> is unsupported, and the tree
    // processor's bookmark ceiling moved between 6.9 and 7.0. So assert the
    // contract rather than the trigger: nothing executable is ever stored,
    // refusing to store says so, and every note names the page.
    $pathological = [
        '<plaintext><img src=x onerror="E()">',
        str_repeat('<div>', 200) . '<img src=x onerror="E()">',
        str_repeat('<a', 12),
        '<svg><title><img src=x onerror="E()"></title></svg>',
    ];
    foreach ($pathological as $i => $html) {
        file_put_contents($log, '');
        $out = $sanitize($html, "page 'p{$i}'");

        assert_true(!str_contains($out, 'onerror='), "case {$i}: no handler stored");
        if ($out === '') {
            assert_contains(
                'stored empty rather than unreviewed markup',
                $read(),
                "case {$i}: refusing to store is reported",
            );
        }
        if ($read() !== '') {
            assert_contains("page 'p{$i}'", $read(), "case {$i}: the note names the page");
        }
    }

    ini_set('error_log', (string) $previous);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('seeder imports from theme assets when plugin/images is empty and sets logo mods', function () {
    $slug = 'logo-import';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'hero.jpg', 'title' => 'Loaves'],
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
        ['filename' => 'site-icon.png', 'title' => 'Site icon', 'role' => 'site-icon'],
    ]]);
    $themeDir = sys_get_temp_dir() . '/wp-stub-theme-' . uniqid();
    @mkdir($themeDir . '/assets', 0777, true);
    file_put_contents($themeDir . '/assets/hero.jpg', 'JPEG');
    file_put_contents($themeDir . '/assets/site-logo.png', 'PNG');
    file_put_contents($themeDir . '/assets/site-icon.png', 'PNGICON');

    wp_stub_reset();
    $GLOBALS['wp_stylesheet_directory'] = $themeDir;
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();

    assert_eq(3, count($GLOBALS['wp_attachments']));
    $logoId = (int) get_theme_mod('custom_logo');
    $iconId = (int) get_option('site_icon');
    assert_true($logoId > 0, 'custom_logo set');
    assert_true($iconId > 0, 'site_icon set');
    assert_true(
        $logoId !== $iconId,
        'the icon is its own opaque attachment, not the transparent header mark',
    );
    $state = get_option(ApplyIdentityStep::identifierPrefix($slug) . '_content_state');
    assert_true($state['changed_logo']);
    assert_eq($logoId, $state['logo_attachment_id']);
    assert_eq($iconId, $state['icon_attachment_id']);

    exec('rm -rf ' . escapeshellarg($tmp));
    exec('rm -rf ' . escapeshellarg($themeDir));
});

test('seeder restore skips logo mods the owner replaced', function () {
    $slug = 'logo-keep';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
        ['filename' => 'site-icon.png', 'title' => 'Site icon', 'role' => 'site-icon'],
    ]]);
    @mkdir($project->pluginPath('images'), 0777, true);
    file_put_contents($project->pluginPath('images/site-logo.png'), 'PNG');
    file_put_contents($project->pluginPath('images/site-icon.png'), 'PNGICON');

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();
    $seeded = (int) get_theme_mod('custom_logo');
    set_theme_mod('custom_logo', 999);
    update_option('site_icon', 999);

    (content_fn($slug, 'deactivate'))();

    assert_eq(999, (int) get_theme_mod('custom_logo'));
    assert_eq(999, (int) get_option('site_icon'));
    assert_true(!isset($GLOBALS['wp_attachments'][$seeded]));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('seeder restore clears custom_logo when the owner changed only site_icon', function () {
    $slug = 'logo-split';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
        ['filename' => 'site-icon.png', 'title' => 'Site icon', 'role' => 'site-icon'],
    ]]);
    @mkdir($project->pluginPath('images'), 0777, true);
    file_put_contents($project->pluginPath('images/site-logo.png'), 'PNG');
    file_put_contents($project->pluginPath('images/site-icon.png'), 'PNGICON');

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();
    $seeded = (int) get_theme_mod('custom_logo');
    update_option('site_icon', 999);

    (content_fn($slug, 'deactivate'))();

    assert_eq(false, get_theme_mod('custom_logo', false), 'still-owned logo is restored');
    assert_eq(999, (int) get_option('site_icon'), 'owner site_icon is left alone');
    assert_true(!isset($GLOBALS['wp_attachments'][$seeded]));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('seeder restore puts back previous logo mods when still owned', function () {
    $slug = 'logo-restore';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
        ['filename' => 'site-icon.png', 'title' => 'Site icon', 'role' => 'site-icon'],
    ]]);
    @mkdir($project->pluginPath('images'), 0777, true);
    file_put_contents($project->pluginPath('images/site-logo.png'), 'PNG');
    file_put_contents($project->pluginPath('images/site-icon.png'), 'PNGICON');

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();
    $seeded = (int) get_theme_mod('custom_logo');
    assert_true($seeded > 0);

    (content_fn($slug, 'deactivate'))();

    assert_eq(false, get_theme_mod('custom_logo', false));
    assert_eq(false, get_option('site_icon', false));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('seeder leaves site_icon alone when no opaque icon shipped', function () {
    $slug = 'logo-only';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
    ]]);
    @mkdir($project->pluginPath('images'), 0777, true);
    file_put_contents($project->pluginPath('images/site-logo.png'), 'PNG');

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();

    assert_true((int) get_theme_mod('custom_logo') > 0, 'the header still gets its mark');
    assert_eq(
        false,
        get_option('site_icon', false),
        'a transparent mark is never borrowed as the favicon',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('content plugin does not import a theme asset that resolves outside the assets root', function () {
    $slug = 'escapes-assets';
    [$project, $tmp] = scaffold_plugin_fixture($slug);

    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0, 'parent' => null],
    ]]);
    $project->writeText('plugin/pages/home.html', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->');
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'escape.png', 'title' => 'Symlinked out of the theme'],
    ]]);

    // A well-named file inside assets/ whose real path is elsewhere. basename
    // and the charset check both pass it; only the realpath containment on the
    // theme-assets root stops it. plugin/images stays empty so the theme
    // fallback is the branch under test.
    $themeDir = sys_get_temp_dir() . '/wp-stub-theme-' . uniqid();
    @mkdir($themeDir . '/assets', 0777, true);
    $outside = sys_get_temp_dir() . '/outside-' . uniqid() . '.png';
    file_put_contents($outside, 'PNG');
    symlink($outside, $themeDir . '/assets/escape.png');

    wp_stub_reset();
    $GLOBALS['wp_stylesheet_directory'] = $themeDir;
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();

    assert_eq(0, count($GLOBALS['wp_attachments']), 'a symlink out of the assets root is not imported');

    exec('rm -rf ' . escapeshellarg($themeDir));
    @unlink($outside);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generated seeder scrubs resource-loading inline styles like the intake sanitizer', function () {
    if (!load_wp_html_api()) {
        skip_test('no WordPress copy found for the HTML API; set SITEBUILD_WP_PATH');
    }
    $slug = 'style-sink-parity';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    require_once $project->pluginPath('site-content.php');
    $sanitize = content_fn($slug, 'sanitize');

    $corpus = [
        '<div style="background:url(https://evil.example/px);color:red">hi</div>',
        '<div style="background:url&#40;https://evil.example/px&#41;">hi</div>',
        '<div style="background:\\75rl(https://evil.example/px)">hi</div>',
        '<div style="background-image: url(&quot;https://evil.example/a.png&quot;)">hi</div>',
        "<div style='background:image-set(\"https://evil.example/a.png\" 1x)'>hi</div>",
        '<div style=background:url(https://evil.example/px) class=x>hi</div>',
        '<div style="content:\'a;b\';background:url(https://evil.example/px)">hi</div>',
    ];
    foreach ($corpus as $html) {
        foreach ([
            'intake' => \Automattic\SiteBuild\MarkupSanitizer::sanitize($html),
            'seeder' => $sanitize($html),
        ] as $which => $out) {
            assert_true(!str_contains($out, 'evil.example'), "{$which}: fetch survived: {$html}");
            assert_true(preg_match('/url|image-set/i', $out) !== 1, "{$which}: loading form survived: {$html}");
            assert_contains('>hi</div>', $out, "{$which}: content survived: {$html}");
        }
    }

    // The surviving declarations stay, on both sides.
    $mixed = '<div style="background:url(https://evil.example/px);color:red">hi</div>';
    assert_contains('color:red', \Automattic\SiteBuild\MarkupSanitizer::sanitize($mixed));
    assert_contains('color:red', $sanitize($mixed));
    // A clean inline style is untouched by the seeder.
    $clean = '<p style="color:red;content:\'a;b\'">t</p>';
    assert_eq($clean, $sanitize($clean));
    // The build's own placeholder is the one url() a cover may carry.
    $cover = '<div class="wp-block-cover" style="background-image:url(theme:./assets/hero.jpg)"></div>';
    assert_eq($cover, $sanitize($cover));
    assert_eq($cover, \Automattic\SiteBuild\MarkupSanitizer::sanitize($cover));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generated seeder removes media sources on a foreign host like the intake sanitizer', function () {
    if (!load_wp_html_api()) {
        skip_test('no WordPress copy found for the HTML API; set SITEBUILD_WP_PATH');
    }
    $slug = 'media-sink-parity';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    require_once $project->pluginPath('site-content.php');
    $sanitize = content_fn($slug, 'sanitize');

    $corpus = [
        '<!-- wp:cover {"dimRatio":50,"url":"https://evil.example/bg.jpg","id":3} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" src="https://evil.example/bg.jpg" alt="Oven"></div><!-- /wp:cover -->',
        '<!-- wp:image {"url":"https:\/\/evil.example\/a.jpg"} --><figure><img src="//evil.example/a.jpg" srcset="/a.jpg 1x, https://evil.example/b.jpg 2x" alt="a"></figure><!-- /wp:image -->',
        '<!-- wp:video {"src":"https://evil.example/v.mp4"} /--><video poster="/p.jpg" src="ftp://evil.example/v.mp4"></video>',
        "<img src=\"ht\ttps://evil.example/a.jpg\">",
        '<input type="image" src="https://evil.example/btn.png">',
        '<img src="\\\\evil.example/a.png" alt="a"><img src="/\\evil.example/b.png">',
        '<svg><image href="https://evil.example/i.png"/><use xlink:href="//evil.example/s.svg#a"/></svg>',
        '<table background="https://evil.example/t.png"><tr><td background="//evil.example/c.png">x</td></tr></table>',
        '<link rel="stylesheet" href="https://evil.example/l.css"><p>after</p>',
        '<!-- wp:group {"style":{"background":{"backgroundImage":{"url":"https://evil.example/g.png","id":5}}}} --><div class="wp-block-group"></div><!-- /wp:group -->',
        '<!-- wp:cover {"url":"\\\\\\\\evil.example\/bg.jpg","id":1} --><div></div><!-- /wp:cover -->',
    ];
    foreach ($corpus as $html) {
        foreach ([
            'intake' => \Automattic\SiteBuild\MarkupSanitizer::sanitize($html),
            'seeder' => $sanitize($html),
        ] as $which => $out) {
            assert_true(!str_contains($out, 'evil.example'), "{$which}: foreign source survived: {$html}");
        }
    }
    // What stays, stays on both sides.
    $kept = '<!-- wp:cover {"url":"theme:./assets/hero.jpg","dimRatio":50} --><div><img src="theme:./assets/hero.jpg" alt="x"><img src="/wp-content/uploads/a.jpg"><a href="https://example.com/">link</a></div><!-- /wp:cover -->'
        . '<!-- wp:navigation-link {"label":"Instagram","url":"https://instagram.com/hearth","kind":"custom"} /-->'
        . '<!-- wp:social-link {"url":"https://x.com/hearth","service":"x"} /-->';
    assert_eq($kept, \Automattic\SiteBuild\MarkupSanitizer::sanitize($kept));
    assert_eq($kept, $sanitize($kept));
    $group = $sanitize($corpus[9]);
    assert_contains('{"style":{"background":{"backgroundImage":{"id":5}}}}', $group, 'seeder drops a block background url and keeps the rest');
    $cover = $sanitize($corpus[0]);
    assert_contains('<!-- wp:cover {"dimRatio":50,"id":3} -->', $cover, 'seeder drops the JSON key with its comma');
    assert_contains('alt="Oven"', $cover, 'seeder keeps the element and its alt');
    exec('rm -rf ' . escapeshellarg($tmp));
});
